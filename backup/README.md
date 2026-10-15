# လစဉ် Google Drive backup

လတိုင်း ၁ ရက်မှာ GitHub Actions က `expense-backup-YYYY-MM-DD.zip` ဆောက်ပြီး Drive ထဲက
"Expense Tracker Backups" folder ထဲ တင်ပေးတယ်။ နောက်ဆုံး ၆ ခုပဲ ထားပြီး (BACKUP_KEEP) အဟောင်းကို ဖျက်တယ်။

zip ထဲမှာ
- db/*.json, db/*.csv — profiles, categories, expenses, auth_users (email စာရင်းသာ၊ password မပါ)
- receipts/{user_id}/{file}.jpg — ဘောင်ချာပုံအားလုံး
- manifest.json — အရေအတွက်၊ ပုံ download မရတာရှိမရှိ

## Setup (တစ်ကြိမ်)
1. https://console.cloud.google.com → Project အသစ်ဆောက် → **Google Drive API** ကို Enable
2. **OAuth consent screen** → External → App name/email ဖြည့် → Scope `.../auth/drive.file` ထည့်
   → **Publish app (In production)** နှိပ်ပါ။
   ⚠️ "Testing" အတိုင်းထားရင် refresh token က ၇ ရက်နဲ့ သက်တမ်းကုန်ပြီး backup တစ်လကျော်ရင် ရပ်သွားမယ်။
   (drive.file က sensitive scope မဟုတ်လို့ verification မလို)
3. **Credentials → Create credentials → OAuth client ID → Desktop app** → client ID / secret ကူးထား
4. ကိုယ့်စက်မှာ
   pip install google-auth-oauthlib && python backup/get_refresh_token.py
   Browser ပွင့်လာရင် Google account နဲ့ ခွင့်ပြု ("Google hasn't verified this app" ပြရင် Advanced → Continue၊ ကိုယ့် app ပဲ)။
   ထွက်လာတဲ့ GOOGLE_REFRESH_TOKEN ကို ကူးထား
5. GitHub repo → Settings → Secrets and variables → Actions မှာ ထည့်
   DATABASE_URL (Session pooler)၊ SUPABASE_URL၊ SUPABASE_SERVICE_KEY၊
   GOOGLE_CLIENT_ID၊ GOOGLE_CLIENT_SECRET၊ GOOGLE_REFRESH_TOKEN
6. Actions tab → monthly-backup → **Run workflow** နဲ့ ချက်ချင်း စမ်း → Drive ထဲမှာ zip ပေါ်လာမလား ကြည့်

## Local မှာ Drive မတင်ဘဲ စမ်း
pip install -r backup/requirements.txt
DATABASE_URL=... SUPABASE_URL=... SUPABASE_SERVICE_KEY=... BACKUP_DRY_RUN=1 python backup/backup.py

## Restore
- Expenses/categories ကို Supabase Table Editor → Import CSV နဲ့ ပြန်သွင်းလို့ရတယ်။
- profiles.id က auth.users ကို ချိတ်ထားလို့ account တွေ မရှိရင် user တွေ ပြန်စာရင်းသွင်း (sign up) ပြီးမှ id ကိုက်အောင် ပြင်ရမယ်။
  Password တွေ backup မလုပ်ဘူး (လုံခြုံရေးအရ)။
- receipts/ ထဲက ပုံတွေကို Storage → receipts bucket ထဲ ထပ်တင်ပါ (path မပြောင်းဘဲ)။
- အနည်းဆုံး ၆ လတစ်ခါ zip တစ်ခုကို ဖွင့်ပြီး manifest.json နဲ့ ပုံတွေ ပြည့်စုံမှုကို စစ်ကြည့်ပါ။
