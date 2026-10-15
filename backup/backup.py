#!/usr/bin/env python3
"""
လစဉ် backup: Supabase DB (JSON + CSV) + receipts bucket → zip တစ်ဖိုင် → Google Drive

Env:
  DATABASE_URL          Supabase Session pooler string (GitHub runner က IPv4 မို့ direct connection မရ)
  SUPABASE_URL, SUPABASE_SERVICE_KEY
  GOOGLE_CLIENT_ID, GOOGLE_CLIENT_SECRET, GOOGLE_REFRESH_TOKEN
  BACKUP_KEEP=6                 Drive ပေါ်မှာ ထားမယ့် backup အရေအတွက်
  DRIVE_FOLDER_NAME             (default "Expense Tracker Backups")
  BACKUP_DRY_RUN=1              Drive ကို မတင်ဘဲ zip ပဲ ဆောက် (local စမ်းဖို့)
"""
import csv
import datetime as dt
import io
import json
import os
import re
import sys
import tempfile
import time
import zipfile
from urllib.parse import quote

import psycopg
import requests
from psycopg.rows import dict_row

KEEP = int(os.getenv("BACKUP_KEEP", "6"))
FOLDER_NAME = os.getenv("DRIVE_FOLDER_NAME", "Expense Tracker Backups")
DRY_RUN = os.getenv("BACKUP_DRY_RUN") == "1"
NAME_RE = re.compile(r"^expense-backup-\d{4}-\d{2}-\d{2}\.zip$")

TABLES = {
    "profiles":   "SELECT * FROM public.profiles ORDER BY created_at",
    "categories": "SELECT * FROM public.categories ORDER BY created_at",
    "expenses":   "SELECT * FROM public.expenses ORDER BY spent_at",
    # password hash မပါ၊ မည်သူ့အကောင့်လဲ မှတ်ဖို့သာ
    "auth_users": "SELECT id, email, created_at, last_sign_in_at FROM auth.users ORDER BY created_at",
}


def log(msg):
    print(f"[backup] {msg}", flush=True)


def rows_to_csv(rows):
    if not rows:
        return ""
    buf = io.StringIO()
    w = csv.DictWriter(buf, fieldnames=list(rows[0].keys()))
    w.writeheader()
    for r in rows:
        w.writerow({k: ("" if v is None else v) for k, v in r.items()})
    return buf.getvalue()


def dump_db():
    data = {}
    with psycopg.connect(os.environ["DATABASE_URL"], row_factory=dict_row, connect_timeout=30) as conn:
        for name, sql in TABLES.items():
            data[name] = conn.execute(sql).fetchall()
            log(f"{name}: {len(data[name])} rows")
        data["_receipt_names"] = [
            r["name"] for r in conn.execute(
                "SELECT name FROM storage.objects WHERE bucket_id = 'receipts' ORDER BY name"
            ).fetchall()
        ]
    return data


def download(name):
    url = f"{os.environ['SUPABASE_URL'].rstrip('/')}/storage/v1/object/receipts/{quote(name, safe='/')}"
    headers = {"apikey": os.environ["SUPABASE_SERVICE_KEY"],
               "Authorization": f"Bearer {os.environ['SUPABASE_SERVICE_KEY']}"}
    last = None
    for attempt in range(3):
        try:
            r = requests.get(url, headers=headers, timeout=60)
            if r.status_code == 200:
                return r.content
            last = f"HTTP {r.status_code}"
        except requests.RequestException as e:
            last = str(e)
        time.sleep(2 * (attempt + 1))
    raise RuntimeError(last)


def build_zip(path, data):
    failed = []
    receipts = data.pop("_receipt_names")
    with zipfile.ZipFile(path, "w", zipfile.ZIP_DEFLATED) as z:
        for name, rows in data.items():
            z.writestr(f"db/{name}.json", json.dumps(rows, default=str, ensure_ascii=False, indent=1))
            z.writestr(f"db/{name}.csv", rows_to_csv(rows))

        for i, name in enumerate(receipts, 1):
            try:
                # ပုံတွေက JPEG ဖြစ်ပြီးသားမို့ ထပ်မချုံ့ဘူး
                z.writestr(zipfile.ZipInfo(f"receipts/{name}", time.localtime()[:6]),
                           download(name), compress_type=zipfile.ZIP_STORED)
            except Exception as e:  # noqa: BLE001
                failed.append(f"{name}: {e}")
            if i % 50 == 0:
                log(f"receipts {i}/{len(receipts)}")

        manifest = {
            "created_at": dt.datetime.now(dt.timezone.utc).isoformat(),
            "counts": {k: len(v) for k, v in data.items()},
            "receipts_total": len(receipts),
            "receipts_failed": len(failed),
        }
        z.writestr("manifest.json", json.dumps(manifest, indent=1))
        if failed:
            z.writestr("receipts_failed.txt", "\n".join(failed))

    with zipfile.ZipFile(path) as z:
        bad = z.testzip()
        if bad:
            raise RuntimeError(f"zip verification failed at {bad}")
    log(f"zip ready: {os.path.getsize(path) / 1_048_576:.1f} MB, receipts {len(receipts) - len(failed)}/{len(receipts)}")
    return failed


# ---------------- Google Drive ----------------
def drive_client():
    from google.oauth2.credentials import Credentials
    from googleapiclient.discovery import build

    creds = Credentials(
        None,
        refresh_token=os.environ["GOOGLE_REFRESH_TOKEN"],
        token_uri="https://oauth2.googleapis.com/token",
        client_id=os.environ["GOOGLE_CLIENT_ID"],
        client_secret=os.environ["GOOGLE_CLIENT_SECRET"],
        scopes=["https://www.googleapis.com/auth/drive.file"],
    )
    return build("drive", "v3", credentials=creds, cache_discovery=False)


def ensure_folder(drive):
    q = (f"name = '{FOLDER_NAME}' and mimeType = 'application/vnd.google-apps.folder' "
         "and trashed = false")
    files = drive.files().list(q=q, fields="files(id)", pageSize=1).execute()["files"]
    if files:
        return files[0]["id"]
    meta = {"name": FOLDER_NAME, "mimeType": "application/vnd.google-apps.folder"}
    return drive.files().create(body=meta, fields="id").execute()["id"]


def upload(drive, folder_id, path, name):
    from googleapiclient.http import MediaFileUpload

    media = MediaFileUpload(path, mimetype="application/zip", resumable=True, chunksize=8 * 1024 * 1024)
    req = drive.files().create(body={"name": name, "parents": [folder_id]}, media_body=media, fields="id,size")
    resp = None
    while resp is None:
        _, resp = req.next_chunk(num_retries=5)
    if int(resp["size"]) != os.path.getsize(path):
        raise RuntimeError("uploaded size mismatch")
    log(f"uploaded {name} ({int(resp['size']) / 1_048_576:.1f} MB)")


def prune(drive, folder_id):
    q = f"'{folder_id}' in parents and trashed = false and name contains 'expense-backup-'"
    files = drive.files().list(q=q, fields="files(id,name)", orderBy="name desc", pageSize=200).execute()["files"]
    files = [f for f in files if NAME_RE.match(f["name"])]
    for f in files[KEEP:]:
        drive.files().delete(fileId=f["id"]).execute()
        log(f"deleted old backup {f['name']}")


def main():
    name = f"expense-backup-{dt.datetime.now(dt.timezone.utc):%Y-%m-%d}.zip"
    data = dump_db()
    with tempfile.TemporaryDirectory() as tmp:
        path = os.path.join(tmp, name)
        failed = build_zip(path, data)

        if DRY_RUN:
            keep = os.path.join(os.getcwd(), name)
            os.replace(path, keep)
            log(f"dry run — saved {keep}")
        else:
            drive = drive_client()
            folder_id = ensure_folder(drive)
            upload(drive, folder_id, path, name)
            prune(drive, folder_id)   # upload အောင်မြင်မှ အဟောင်းဖျက်

    if failed:
        log(f"WARNING: {len(failed)} receipts could not be downloaded")
        sys.exit(1)   # backup တင်ပြီးသား၊ ဒါပေမယ့် GitHub က failure email ပို့အောင်
    log("done")


if __name__ == "__main__":
    main()
