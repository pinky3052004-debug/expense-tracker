import { useEffect, useState } from 'react';
import { T } from '../strings';

/** Loading ၄ စက္ကန့်ကျော်ရင် Render sleep နေတာကို ရှင်းပြ */
export default function SlowNotice({ delay = 4000 }) {
  const [show, setShow] = useState(false);
  useEffect(() => {
    const t = setTimeout(() => setShow(true), delay);
    return () => clearTimeout(t);
  }, [delay]);
  return show ? <p className="notice" role="status">{T.slow}</p> : null;
}
