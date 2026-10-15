import { useCallback, useEffect, useState } from 'react';
import { supabase } from './lib/supabase';
import { api } from './lib/api';
import { T } from './strings';
import Auth from './screens/Auth';
import Home from './screens/Home';
import Settings from './screens/Settings';
import ExpenseSheet from './components/ExpenseSheet';
import SlowNotice from './components/SlowNotice';
import Icon from './components/Icon';

export default function App() {
  const [session, setSession] = useState(undefined); // undefined = စစ်နေဆဲ

  useEffect(() => {
    supabase.auth.getSession().then(({ data }) => setSession(data.session));
    const { data } = supabase.auth.onAuthStateChange((_e, s) => setSession(s));
    return () => data.subscription.unsubscribe();
  }, []);

  if (session === undefined) return <div className="boot" aria-busy="true" />;
  if (!session) return <Auth />;
  return <Shell key={session.user.id} email={session.user.email} />;
}

function Shell({ email }) {
  const [tab, setTab] = useState('home');
  const [profile, setProfile] = useState(null);
  const [cats, setCats] = useState([]);
  const [sheet, setSheet] = useState(null); // null | {} (အသစ်) | expense (ပြင်)
  const [version, setVersion] = useState(0);
  const [error, setError] = useState('');

  const reloadCats = useCallback(async () => setCats(await api('/api/categories')), []);

  const boot = useCallback(() => {
    setError('');
    Promise.all([api('/api/profile'), api('/api/categories')])
      .then(([p, c]) => { setProfile(p); setCats(c); })
      .catch((e) => setError(e.message || T.loadError));
  }, []);
  useEffect(boot, [boot]);

  if (error) {
    return (
      <div className="boot-msg">
        <p className="error" role="alert">{error}</p>
        <button className="btn ghost" onClick={boot}>{T.retry}</button>
      </div>
    );
  }
  if (!profile) {
    return <div className="boot-msg" aria-busy="true"><p className="muted">…</p><SlowNotice /></div>;
  }

  return (
    <div className="app">
      <main>
        {tab === 'home'
          ? <Home currency={profile.default_currency} version={version} onOpen={setSheet} onAdd={() => setSheet({})} />
          : <Settings email={email} profile={profile} setProfile={setProfile} cats={cats} reloadCats={reloadCats} />}
      </main>

      <nav className="tabbar" aria-label="main">
        <button aria-current={tab === 'home' ? 'page' : undefined} onClick={() => setTab('home')}>
          <Icon name="list" /><span>{T.tabLedger}</span>
        </button>
        <button className="fab" aria-label={T.addExpense} onClick={() => setSheet({})}>
          <Icon name="plus" size={28} />
        </button>
        <button aria-current={tab === 'settings' ? 'page' : undefined} onClick={() => setTab('settings')}>
          <Icon name="gear" /><span>{T.tabSettings}</span>
        </button>
      </nav>

      {sheet && (
        <ExpenseSheet
          expense={sheet}
          cats={cats}
          defaultCurrency={profile.default_currency}
          onClose={() => setSheet(null)}
          onSaved={() => { setSheet(null); setVersion((v) => v + 1); setTab('home'); }}
        />
      )}
    </div>
  );
}
