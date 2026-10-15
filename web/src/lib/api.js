import { supabase } from './supabase';

const BASE = (import.meta.env.VITE_API_URL || '').replace(/\/$/, '');

export class ApiError extends Error {
  constructor(message, status) {
    super(message);
    this.status = status;
  }
}

/** Render free service တွေ sleep ဝင်နေတတ်လို့ app ဖွင့်တာနဲ့ ကြိုနှိုး */
export function wakeServer() {
  fetch(`${BASE}/health`).catch(() => {});
}

export async function api(path, { method = 'GET', body, query, form } = {}) {
  const url = new URL(BASE + path, window.location.origin);
  if (query) Object.entries(query).forEach(([k, v]) => v != null && url.searchParams.set(k, v));

  const { data } = await supabase.auth.getSession();
  const headers = {};
  if (data.session) headers.Authorization = `Bearer ${data.session.access_token}`;
  if (body) headers['Content-Type'] = 'application/json';

  const ctrl = new AbortController();
  const timer = setTimeout(() => ctrl.abort(), 90000); // cold start ကြာနိုင်လို့
  try {
    const res = await fetch(url, {
      method,
      headers,
      body: form ?? (body ? JSON.stringify(body) : undefined),
      signal: ctrl.signal,
    });
    const json = await res.json().catch(() => null);
    if (res.status === 401) supabase.auth.signOut();
    if (!res.ok) throw new ApiError(json?.error || `Error ${res.status}`, res.status);
    return json;
  } finally {
    clearTimeout(timer);
  }
}

/** [from, to) အတွင်း expense အားလုံး (page ၂၀၀ စီ) */
export async function fetchRange(from, to) {
  const all = [];
  const limit = 200;
  for (let offset = 0; offset < 2000; offset += limit) {
    const page = await api('/api/expenses', {
      query: { from: from.toISOString(), to: to.toISOString(), limit, offset },
    });
    all.push(...page);
    if (page.length < limit) break;
  }
  return all.map((e) => ({ ...e, amount: Number(e.amount) }));
}
