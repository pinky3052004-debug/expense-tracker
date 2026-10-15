#!/usr/bin/env python3
"""
တစ်ကြိမ်သာ ကိုယ့်စက်ထဲမှာ run → Google Drive refresh token ရယူ
  pip install google-auth-oauthlib
  python backup/get_refresh_token.py
"""
import getpass

from google_auth_oauthlib.flow import InstalledAppFlow

client_id = input("Google OAuth client ID: ").strip()
client_secret = getpass.getpass("Google OAuth client secret: ").strip()

flow = InstalledAppFlow.from_client_config(
    {"installed": {
        "client_id": client_id,
        "client_secret": client_secret,
        "auth_uri": "https://accounts.google.com/o/oauth2/auth",
        "token_uri": "https://oauth2.googleapis.com/token",
        "redirect_uris": ["http://localhost"],
    }},
    scopes=["https://www.googleapis.com/auth/drive.file"],
)
creds = flow.run_local_server(port=0, access_type="offline", prompt="consent")
print("\nGOOGLE_REFRESH_TOKEN =", creds.refresh_token)
