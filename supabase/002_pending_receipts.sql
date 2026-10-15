-- schema.sql run ပြီးသားဆိုရင် ဒါကို ထပ် run ပါ
create table if not exists public.pending_receipts (
  id uuid primary key default gen_random_uuid(),
  user_id uuid not null references public.profiles(id) on delete cascade,
  draft jsonb not null,
  receipt_path text not null,
  created_at timestamptz not null default now()
);
alter table public.pending_receipts enable row level security;  -- policy မထည့်ဘူး (server ကပဲ သုံးမယ်)
