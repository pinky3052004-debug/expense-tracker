import { useEffect, useMemo, useState } from 'react';
import { fetchRange } from '../lib/api';
import { dayLabel, fmt, localDate, monthLabel } from '../lib/format';
import { T } from '../strings';
import Icon from '../components/Icon';
import SlowNotice from '../components/SlowNotice';

// Marigold/jade/brick... စာရင်းအမျိုးအစားတွေကို ခွဲပြဖို့
const PALETTE = ['#1F5F4E', '#E8A317', '#B8503A', '#4E7FA3', '#8A6BA8', '#7C8B3A', '#9A9585'];

export default function Home({ currency, version, onOpen, onAdd }) {
  const now = new Date();
  const [cur, setCur] = useState({ y: now.getFullYear(), m: now.getMonth() });
  const [items, setItems] = useState(null);
  const [error, setError] = useState('');
  const [filter, setFilter] = useState(null); // category id | 'none' | null
  const [retry, setRetry] = useState(0);

  useEffect(() => {
    let alive = true;
    setItems(null); setError(''); setFilter(null);
    fetchRange(new Date(cur.y, cur.m, 1), new Date(cur.y, cur.m + 1, 1))
      .then((rows) => alive && setItems(rows))
      .catch((e) => alive && setError(e.message || T.loadError));
    return () => { alive = false; };
  }, [cur, version, retry]);

  const stats = useMemo(() => {
    if (!items) return null;
    const mine = items.filter((e) => e.currency === currency);
    const total = mine.reduce((s, e) => s + e.amount, 0);

    const map = new Map();
    for (const e of mine) {
      const key = e.category_id || 'none';
      const row = map.get(key) || { key, name: e.category_name || T.uncategorized, icon: e.category_icon, total: 0 };
      row.total += e.amount;
      map.set(key, row);
    }
    const byCat = [...map.values()].sort((a, b) => b.total - a.total)
      .map((r, i) => ({ ...r, color: PALETTE[Math.min(i, PALETTE.length - 1)], pct: total ? (r.total / total) * 100 : 0 }));

    const others = {};
    for (const e of items) if (e.currency !== currency) others[e.currency] = (others[e.currency] || 0) + e.amount;

    return { total, byCat, others };
  }, [items, currency]);

  const days = useMemo(() => {
    if (!items) return [];
    const shown = filter ? items.filter((e) => (e.category_id || 'none') === filter) : items;
    const groups = new Map();
    for (const e of shown) {
      const k = localDate(e.spent_at);
      if (!groups.has(k)) groups.set(k, []);
      groups.get(k).push(e);
    }
    return [...groups.entries()];
  }, [items, filter]);

  const shift = (d) => setCur(({ y, m }) => { const n = new Date(y, m + d, 1); return { y: n.getFullYear(), m: n.getMonth() }; });
  const isCurrentMonth = cur.y === now.getFullYear() && cur.m === now.getMonth();

  return (
    <section>
      <div className="month-nav">
        <button className="icon-btn" onClick={() => shift(-1)} aria-label={T.prevMonth}><Icon name="left" /></button>
        <h2>{monthLabel(cur.y, cur.m)}</h2>
        <button className="icon-btn" onClick={() => shift(1)} disabled={isCurrentMonth} aria-label={T.nextMonth}><Icon name="right" /></button>
      </div>

      {error && (
        <div className="state">
          <p className="error" role="alert">{error}</p>
          <button className="btn ghost" onClick={() => setRetry((n) => n + 1)}>{T.retry}</button>
        </div>
      )}

      {!items && !error && (
        <div className="state" aria-busy="true">
          <div className="skeleton big" />
          <div className="skeleton bar" />
          <div className="skeleton line" /><div className="skeleton line" /><div className="skeleton line" />
          <SlowNotice />
        </div>
      )}

      {items && stats && (
        <>
          <div className="total">
            <span className="total-label">{T.total}</span>
            <div className="total-figure">
              <strong>{fmt(stats.total)}</strong><span className="cur">{currency}</span>
            </div>
            {Object.keys(stats.others).length > 0 && (
              <p className="muted small">
                {T.otherCurrencies}: {Object.entries(stats.others).map(([c, v]) => `${fmt(v)} ${c}`).join(', ')}
              </p>
            )}
          </div>

          {items.length === 0 ? (
            <div className="empty">
              <h3>{T.emptyTitle}</h3>
              <p className="muted">{T.emptyHelp}</p>
              <button className="btn primary" onClick={onAdd}>{T.addExpense}</button>
            </div>
          ) : (
            <>
              {stats.byCat.length > 0 && (
                <>
                  <div className="stack" role="img"
                       aria-label={stats.byCat.map((c) => `${c.name} ${Math.round(c.pct)}%`).join(', ')}>
                    {stats.byCat.map((c) => (
                      <span key={c.key} style={{ width: `${c.pct}%`, background: c.color }}
                            className={filter && filter !== c.key ? 'dim' : ''} />
                    ))}
                  </div>
                  <ul className="breakdown">
                    {stats.byCat.map((c) => (
                      <li key={c.key}>
                        <button className={`brk ${filter === c.key ? 'on' : ''}`} aria-pressed={filter === c.key}
                                onClick={() => setFilter(filter === c.key ? null : c.key)}>
                          <i style={{ background: c.color }} />
                          <span className="brk-name">{c.icon} {c.name}</span>
                          <span className="brk-amt">{fmt(c.total)}</span>
                          <span className="brk-pct">{Math.round(c.pct)}%</span>
                        </button>
                      </li>
                    ))}
                  </ul>
                  {filter && <button className="link" onClick={() => setFilter(null)}>{T.clearFilter}</button>}
                </>
              )}

              {days.map(([ymd, rows]) => (
                <div className="day" key={ymd}>
                  <div className="day-head">
                    <h3>{dayLabel(ymd)}</h3>
                    <span>{fmt(rows.filter((r) => r.currency === currency).reduce((s, r) => s + r.amount, 0))}</span>
                  </div>
                  <ul>
                    {rows.map((e) => (
                      <li key={e.id}>
                        <button className="row" onClick={() => onOpen(e)}>
                          <span className="row-icon" aria-hidden="true">{e.category_icon || '•'}</span>
                          <span className="row-main">
                            <span className="row-title">{e.merchant || e.note || e.category_name || '—'}</span>
                            <span className="row-sub">{e.category_name || T.uncategorized}{e.receipt_path ? ' 🧾' : ''}</span>
                          </span>
                          <span className="row-amt">{fmt(e.amount)}<small>{e.currency}</small></span>
                        </button>
                      </li>
                    ))}
                  </ul>
                </div>
              ))}
            </>
          )}
        </>
      )}
    </section>
  );
}
