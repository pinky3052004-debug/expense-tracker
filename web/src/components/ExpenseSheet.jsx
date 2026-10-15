import { useEffect, useRef, useState } from 'react';
import { api } from '../lib/api';
import { compressImage } from '../lib/image';
import { localDate, setDatePart } from '../lib/format';
import { T } from '../strings';
import Icon from './Icon';

const CURRENCIES = ['MMK', 'THB', 'SGD', 'USD'];

export default function ExpenseSheet({ expense, cats, defaultCurrency, onClose, onSaved }) {
  const editing = Boolean(expense?.id);

  const [amount, setAmount] = useState(editing ? String(Number(expense.amount)) : '');
  const [currency, setCurrency] = useState(expense?.currency || defaultCurrency);
  const [categoryId, setCategoryId] = useState(expense?.category_id || '');
  const [merchant, setMerchant] = useState(expense?.merchant || '');
  const [note, setNote] = useState(expense?.note || '');
  const [spentAt, setSpentAt] = useState(expense?.spent_at || new Date().toISOString());
  const [receiptPath, setReceiptPath] = useState(expense?.receipt_path || null);
  const [source, setSource] = useState(expense?.source || 'web');

  const [preview, setPreview] = useState(null);
  const [receiptUrl, setReceiptUrl] = useState(null);
  const [scan, setScan] = useState('idle'); // idle | busy | ok | fail | error
  const [saving, setSaving] = useState(false);
  const [err, setErr] = useState('');
  const fileRef = useRef(null);

  const currencies = CURRENCIES.includes(currency) ? CURRENCIES : [currency, ...CURRENCIES];

  useEffect(() => {
    const onKey = (e) => e.key === 'Escape' && onClose();
    document.addEventListener('keydown', onKey);
    document.body.style.overflow = 'hidden';
    return () => {
      document.removeEventListener('keydown', onKey);
      document.body.style.overflow = '';
    };
  }, [onClose]);

  useEffect(() => () => preview && URL.revokeObjectURL(preview), [preview]);

  async function onFile(e) {
    const file = e.target.files?.[0];
    e.target.value = '';
    if (!file) return;
    setScan('busy');
    setErr('');
    try {
      const blob = await compressImage(file);
      setPreview(URL.createObjectURL(blob));
      const form = new FormData();
      form.append('image', blob, 'receipt.jpg');
      const r = await api('/api/receipts/scan', { method: 'POST', form });
      setReceiptPath(r.receipt_path);
      const d = r.draft;
      if (d?.amount) {
        setAmount(String(d.amount));
        setMerchant(d.merchant || '');
        if (d.currency) setCurrency(d.currency);
        if (d.category_id) setCategoryId(d.category_id);
        if (d.spent_at) setSpentAt(d.spent_at);
        setSource('ocr');
        setScan('ok');
      } else {
        setScan('fail');
      }
    } catch {
      setScan('error');
    }
  }

  async function showReceipt() {
    try {
      const r = await api('/api/receipts/url', { query: { path: receiptPath } });
      setReceiptUrl(r.url);
    } catch (ex) {
      setErr(ex.message);
    }
  }

  async function save(e) {
    e.preventDefault();
    const n = Number(amount.replace(/,/g, ''));
    if (!(n > 0)) return setErr(T.amountRequired);
    setSaving(true);
    setErr('');
    const payload = {
      amount: n,
      currency,
      category_id: categoryId || null,
      merchant: merchant.trim() || null,
      note: note.trim() || null,
      spent_at: spentAt,
      receipt_path: receiptPath,
    };
    try {
      if (editing) await api(`/api/expenses/${expense.id}`, { method: 'PATCH', body: payload });
      else await api('/api/expenses', { method: 'POST', body: { ...payload, source } });
      onSaved();
    } catch (ex) {
      setErr(ex.message);
      setSaving(false);
    }
  }

  async function remove() {
    if (!window.confirm(T.confirmDelete)) return;
    setSaving(true);
    try {
      await api(`/api/expenses/${expense.id}`, { method: 'DELETE' });
      onSaved();
    } catch (ex) {
      setErr(ex.message);
      setSaving(false);
    }
  }

  const scanMsg = {
    busy: T.scanning, ok: T.scanOk, fail: T.scanFail, error: T.scanError,
  }[scan];

  return (
    <div className="overlay" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <form className="sheet" role="dialog" aria-modal="true" aria-labelledby="sheet-title" onSubmit={save}>
        <header className="sheet-head">
          <h2 id="sheet-title">{editing ? T.editTitle : T.newTitle}</h2>
          <button type="button" className="icon-btn" onClick={onClose} aria-label={T.close}>
            <Icon name="x" />
          </button>
        </header>

        <div className="scan">
          <input ref={fileRef} type="file" accept="image/*" hidden onChange={onFile} />
          <button type="button" className="btn ghost" onClick={() => fileRef.current.click()} disabled={scan === 'busy'}>
            <Icon name="camera" size={18} /> {preview ? T.scanAgain : T.scan}
          </button>
          {scanMsg && <p className={`scan-msg ${scan}`} role="status">{scanMsg}</p>}
          {preview && <img className="thumb" src={preview} alt="" />}
        </div>

        <label className="field amount-field">
          <span>{T.amount}</span>
          <div className="amount-row">
            <input inputMode="decimal" autoFocus={!editing} value={amount} placeholder="0"
                   onChange={(e) => setAmount(e.target.value)} />
            <select value={currency} onChange={(e) => setCurrency(e.target.value)} aria-label={T.currency}>
              {currencies.map((c) => <option key={c}>{c}</option>)}
            </select>
          </div>
        </label>

        <fieldset className="field">
          <legend>{T.category}</legend>
          <div className="chips">
            {cats.map((c) => (
              <label key={c.id} className={`chip ${categoryId === c.id ? 'on' : ''}`}>
                <input type="radio" name="cat" checked={categoryId === c.id} onChange={() => setCategoryId(c.id)} />
                <span aria-hidden="true">{c.icon}</span> {c.name}
              </label>
            ))}
          </div>
        </fieldset>

        <label className="field">
          <span>{T.merchant}</span>
          <input value={merchant} onChange={(e) => setMerchant(e.target.value)} />
        </label>
        <label className="field">
          <span>{T.note}</span>
          <input value={note} onChange={(e) => setNote(e.target.value)} />
        </label>
        <label className="field">
          <span>{T.date}</span>
          <input type="date" value={localDate(spentAt)} onChange={(e) => e.target.value && setSpentAt(setDatePart(spentAt, e.target.value))} />
        </label>

        {editing && receiptPath && !preview && (
          receiptUrl
            ? <img className="receipt-full" src={receiptUrl} alt="" />
            : <button type="button" className="btn ghost" onClick={showReceipt}>{T.viewReceipt}</button>
        )}

        {err && <p className="error" role="alert">{err}</p>}

        <div className="sheet-actions">
          <button className="btn primary" disabled={saving || scan === 'busy'}>{saving ? T.saving : T.save}</button>
          {editing && <button type="button" className="btn danger" onClick={remove} disabled={saving}>{T.remove}</button>}
        </div>
      </form>
    </div>
  );
}
