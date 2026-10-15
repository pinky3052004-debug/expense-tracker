import { useEffect, useState } from 'react';
import { supabase } from '../lib/supabase';
import { wakeServer } from '../lib/api';
import { T } from '../strings';

export default function Auth() {
  const [mode, setMode] = useState('in');
  const [email, setEmail] = useState('');
  const [pw, setPw] = useState('');
  const [busy, setBusy] = useState(false);
  const [err, setErr] = useState('');
  const [info, setInfo] = useState('');

  useEffect(wakeServer, []);

  async function submit(e) {
    e.preventDefault();
    setBusy(true); setErr(''); setInfo('');
    try {
      if (mode === 'in') {
        const { error } = await supabase.auth.signInWithPassword({ email, password: pw });
        if (error) throw error;
      } else {
        const { data, error } = await supabase.auth.signUp({ email, password: pw });
        if (error) throw error;
        if (!data.session) setInfo(T.checkEmail);
      }
    } catch (ex) {
      setErr(ex.message);
    } finally {
      setBusy(false);
    }
  }

  return (
    <main className="auth">
      <h1 className="brand">{T.appName}</h1>
      <p className="muted">{T.tagline}</p>
      <form onSubmit={submit}>
        <label className="field">
          <span>{T.email}</span>
          <input type="email" autoComplete="email" required value={email} onChange={(e) => setEmail(e.target.value)} />
        </label>
        <label className="field">
          <span>{T.password}</span>
          <input type="password" minLength={6} required value={pw}
                 autoComplete={mode === 'in' ? 'current-password' : 'new-password'}
                 onChange={(e) => setPw(e.target.value)} />
        </label>
        {err && <p className="error" role="alert">{err}</p>}
        {info && <p className="notice" role="status">{info}</p>}
        <button className="btn primary wide" disabled={busy}>{mode === 'in' ? T.signIn : T.signUp}</button>
      </form>
      <button className="link" onClick={() => { setMode(mode === 'in' ? 'up' : 'in'); setErr(''); setInfo(''); }}>
        {mode === 'in' ? T.toSignUp : T.toSignIn}
      </button>
    </main>
  );
}
