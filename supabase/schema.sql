-- Supabase SQL Editor ထဲမှာ run ပါ
create extension if not exists "pgcrypto";

-- Profile (Supabase Auth ရဲ့ auth.users နဲ့ ချိတ်)
create table public.profiles (
  id uuid primary key references auth.users(id) on delete cascade,
  telegram_id bigint unique,
  default_currency text not null default 'MMK',
  created_at timestamptz not null default now()
);

create table public.categories (
  id uuid primary key default gen_random_uuid(),
  user_id uuid not null references public.profiles(id) on delete cascade,
  name text not null,
  icon text,
  created_at timestamptz not null default now(),
  unique (user_id, name)
);

create table public.expenses (
  id uuid primary key default gen_random_uuid(),
  user_id uuid not null references public.profiles(id) on delete cascade,
  amount numeric(14,2) not null check (amount >= 0),
  currency text not null default 'MMK',
  category_id uuid references public.categories(id) on delete set null,
  merchant text,
  note text,
  spent_at timestamptz not null default now(),
  receipt_path text,                       -- storage path: {user_id}/{uuid}.jpg
  source text not null default 'web' check (source in ('web','telegram','ocr')),
  created_at timestamptz not null default now()
);
create index expenses_user_spent_idx on public.expenses (user_id, spent_at desc);
create index expenses_user_cat_idx on public.expenses (user_id, category_id);

-- Telegram account ချိတ်ဖို့ one-time code
create table public.link_codes (
  code text primary key,
  user_id uuid not null references public.profiles(id) on delete cascade,
  expires_at timestamptz not null
);

-- User အသစ်ဝင်ရင် profile + default category အလိုအလျောက်ဆောက်
create or replace function public.handle_new_user() returns trigger
language plpgsql security definer set search_path = public as $$
begin
  insert into public.profiles (id) values (new.id);
  insert into public.categories (user_id, name, icon) values
    (new.id,'Food','🍜'),(new.id,'Transport','🚌'),(new.id,'Shopping','🛍️'),
    (new.id,'Bills','💡'),(new.id,'Health','💊'),(new.id,'Other','📦');
  return new;
end $$;

create trigger on_auth_user_created after insert on auth.users
for each row execute function public.handle_new_user();

-- RLS (PHP က service/postgres role နဲ့ချိတ်လို့ bypass ဖြစ်တယ်၊ ဒါပေမယ့် anon key နဲ့ တိုက်ရိုက်ခေါ်ရင် ကာကွယ်ပေးမယ်)
alter table public.profiles   enable row level security;
alter table public.categories enable row level security;
alter table public.expenses   enable row level security;
alter table public.link_codes enable row level security;

create policy "own profile"    on public.profiles   for all using (id = auth.uid())      with check (id = auth.uid());
create policy "own categories" on public.categories for all using (user_id = auth.uid()) with check (user_id = auth.uid());
create policy "own expenses"   on public.expenses   for all using (user_id = auth.uid()) with check (user_id = auth.uid());
create policy "own link codes" on public.link_codes for all using (user_id = auth.uid()) with check (user_id = auth.uid());

-- Private bucket for receipts
insert into storage.buckets (id, name, public) values ('receipts','receipts',false)
on conflict (id) do nothing;

create policy "receipts own folder" on storage.objects for all
using (bucket_id = 'receipts' and (storage.foldername(name))[1] = auth.uid()::text)
with check (bucket_id = 'receipts' and (storage.foldername(name))[1] = auth.uid()::text);
