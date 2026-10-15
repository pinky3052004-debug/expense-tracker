# Expense Tracker (PHP API + Telegram Bot)

## Setup
1. Supabase → SQL Editor → `supabase/schema.sql` run, ပြီးရင် `supabase/002_pending_receipts.sql` run
2. Supabase → Connect → **Session pooler** connection string ကို `DATABASE_URL` ထဲထည့်
3. GitHub repo သို့ push → Render → New → Blueprint (`render.yaml`) → env vars ဖြည့်
4. Telegram webhook သတ်မှတ်:
   curl "https://api.telegram.org/bot<TOKEN>/setWebhook" \
     -d url=https://expense-bot.onrender.com/telegram/webhook \
     -d secret_token=<TELEGRAM_WEBHOOK_SECRET>

## Local
docker build -t expense . && docker run --env-file .env -e PORT=8080 -p 8080:8080 expense
curl localhost:8080/health

## API
GET/POST /api/expenses · PATCH/DELETE /api/expenses/{id}
GET /api/summary?month=2026-10
GET/POST /api/categories · DELETE /api/categories/{id}
Header: Authorization: Bearer <Supabase access token>

Receipts / Telegram
POST /api/receipts/scan (multipart field "image") -> {receipt_path, draft}
GET  /api/receipts/url?path=...                    -> signed URL
GET  /api/telegram/status · POST /api/telegram/link-code · DELETE /api/telegram/link

Bot commands: /start /link CODE /add 5000 lunch /today /month + receipt photo

## Web (React) — web/
cd web && cp .env.example .env && npm install && npm run dev
Render Static Site အဖြစ် deploy (render.yaml ထဲမှာ ပါပြီးသား)။ Deploy ပြီးရင် API service ရဲ့ CORS_ORIGIN ကို web URL ပေးရမယ်။
API: GET/PATCH /api/profile (default_currency, telegram_linked)
