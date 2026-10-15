import { useEffect, useState } from 'react';
import { api } from '../lib/api';
import { supabase } from '../lib/supabase';
import { T } from '../strings';

const CURRENCIES = ['MMK', 'THB', 'SGD', 'USD'];
const BOT = import.meta.env.VITE_BOT_USERNAME;

export default function Settings({ email, profile, setProfile, cats, reloadCats }) {
  const [link, setLink] = useState(null); // { code, expiresAt }
  const [left, setLeft] = useState(0);
  const [err, setErr] = useState('');
  const [name, setName] = useState('');
  const [icon, setIcon] = useState('');

  const linked = profile.telegram_linked;

  // code ပြနေတုန်း ၄ စက္ကန့်တစ်ခါ ချိတ်ပြီးပြီလား စစ် + သက်တမ်း countdown
  useEffect(() => {
    if (!link || linked) return;
    const tick = setInterval(() => {
      const s = Math.max(0, Math.round((link.expiresAt - Date.now()) / 1000));
      setLeft(s);
      if (s === 0) setLink(null);
    }, 1000);
    const poll = setInterval(async () => {
      try { setProfile(await api('/api/profile')); } catch {}
    }, 4000);
    return () => { clearInterval(tick); clearInterval(poll); };
  }, [link, linked, setProfile]);

  useEffect(() => { if (linked) setLink(null); }, [linked]);

  const run = async (fn) => { setErr(''); try { await fn(); } catch (e) { setErr(e.message); } };

  const changeCurrency = (c) => run(async () => setProfile(await api('/api/profile', { method: 'PATCH', body: { default_currency: c } })));

  const makeCode = () => run(async () => {
    const r = await api('/api/telegram/link-code', { method: 'POST' });
    setLink({ code: r.code, expiresAt: Date.now() + r.expires_in * 1000 });
    setLeft(r.expires_in);
  });

  const unlink = () => window.confirm(T.confirmUnlink) && run(async () => {
    await api('/api/telegram/link', { method: 'DELETE' });
    setProfile({ ...profile, telegram_linked: false });
  });

  const addCat = (e) => { e.preventDefault(); run(async () => {
    await api('/api/categories', { method: 'POST', body: { name, icon: icon.trim() || null } });
    setName(''); setIcon(''); await reloadCats();
  }); };

  const delCat = (c) => window.confirm(T.confirmCatDelete) && run(async () => {
    await api(`/api/categories/${c.id}`, { method: 'DELETE' });
    await reloadCats();
  });

  const mm = String(Math.floor(left / 60)).padStart(2, '0');
  const ss = String(left % 60).padStart(2, '0');

  return (
    <section className="settings">
      <h2>{T.tabSettings}</h2>
      <p className="muted small">{email}</p>
      {err && <p className="error" role="alert">{err}</p>}

      <div className="block">
        <h3>{T.currencyTitle}</h3>
        <p className="muted small">{T.currencyHelp}</p>
        <select className="select" value={profile.default_currency} onChange={(e) => changeCurrency(e.target.value)} aria-label={T.currencyTitle}>
          {(CURRENCIES.includes(profile.default_currency) ? CURRENCIES : [profile.default_currency, ...CURRENCIES])
            .map((c) => <option key={c}>{c}</option>)}
        </select>
      </div>

      <div className="block">
        <h3>{T.telegram}</h3>
        <p className={linked ? 'ok' : 'muted'}>{linked ? T.tgLinked : T.tgNotLinked}</p>

        {linked ? (
          <button className="btn ghost" onClick={unlink}>{T.tgUnlink}</button>
        ) : link ? (
          <div className="code-box">
            <p className="muted small">{T.tgHow}</p>
            <code className="code">/link {link.code}</code>
            <p className="muted small">{T.tgExpires} {mm}:{ss}</p>
            {BOT && <a className="btn ghost" href={`https://t.me/${BOT}`} target="_blank" rel="noreferrer">{T.openBot}</a>}
          </div>
        ) : (
          <button className="btn primary" onClick={makeCode}>{T.tgMake}</button>
        )}
      </div>

      <div className="block">
        <h3>{T.categories}</h3>
        <ul className="cat-list">
          {cats.map((c) => (
            <li key={c.id}>
              <span>{c.icon} {c.name}</span>
              <button className="link danger-text" onClick={() => delCat(c)}>{T.remove}</button>
            </li>
          ))}
        </ul>
        <form className="cat-form" onSubmit={addCat}>
          <input className="icon-input" value={icon} onChange={(e) => setIcon(e.target.value)} placeholder="🍜" maxLength={4} aria-label={T.catIcon} />
          <input value={name} onChange={(e) => setName(e.target.value)} placeholder={T.catName} required aria-label={T.catName} />
          <button className="btn ghost">{T.catAdd}</button>
        </form>
      </div>

      <button className="btn ghost wide" onClick={() => supabase.auth.signOut()}>{T.signOut}</button>
    </section>
  );
}
