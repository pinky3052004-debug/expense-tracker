const nf = new Intl.NumberFormat('en-US', { maximumFractionDigits: 2 });
export const fmt = (n) => nf.format(Number(n) || 0);

const pad = (n) => String(n).padStart(2, '0');

/** ISO → local 'YYYY-MM-DD' */
export function localDate(iso) {
  const d = new Date(iso);
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
}

/** ISO ရဲ့ ရက်စွဲပိုင်းကိုပဲ ပြောင်း၊ အချိန်ပိုင်း မပြောင်း */
export function setDatePart(iso, ymd) {
  const d = new Date(iso);
  const [y, m, day] = ymd.split('-').map(Number);
  return new Date(y, m - 1, day, d.getHours(), d.getMinutes()).toISOString();
}

export const monthLabel = (y, m) =>
  new Date(y, m, 1).toLocaleDateString('en-US', { month: 'long', year: 'numeric' });

export const dayLabel = (ymd) => {
  const [y, m, d] = ymd.split('-').map(Number);
  return new Date(y, m - 1, d).toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' });
};
