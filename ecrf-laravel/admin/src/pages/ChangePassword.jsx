import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api } from '../api';
import { useAuth } from '../auth';
import { useToast } from '../components/Toast';

export default function ChangePassword({ forced }) {
  const { setMe, logout } = useAuth();
  const toast = useToast();
  const nav = useNavigate();
  const [f, setF] = useState({ current_password: '', new_password: '', new_password_confirmation: '' });
  const [err, setErr] = useState(null);
  const [busy, setBusy] = useState(false);

  const submit = async (e) => {
    e.preventDefault();
    setErr(null);
    if (f.new_password !== f.new_password_confirmation) return setErr('The two new passwords do not match.');
    setBusy(true);
    try {
      const me = await api('/auth/change-password', { method: 'POST', body: f });
      setMe(me);
      toast('Password changed');
      nav('/');
    } catch (ex) {
      setErr(ex.message);
    } finally {
      setBusy(false);
    }
  };
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value });

  const body = (
    <form className="login-card" onSubmit={submit}>
      <div className="form-eyebrow">{forced ? 'First sign-in' : 'Account'}</div>
      <div className="form-title" style={{ marginBottom: 6 }}>Change your password</div>
      {forced && <div className="form-desc" style={{ marginBottom: 14 }}>You are using a temporary password. Choose your own before continuing.</div>}
      <label className="field-label" htmlFor="cp_cur">Current password</label>
      <input id="cp_cur" className="field-input" type="password" autoComplete="current-password" value={f.current_password} onChange={set('current_password')} required />
      <label className="field-label" htmlFor="cp_new" style={{ marginTop: 12 }}>New password</label>
      <input id="cp_new" className="field-input" type="password" autoComplete="new-password" value={f.new_password} onChange={set('new_password')} required />
      <div className="field-note">At least 10 characters, with letters and numbers.</div>
      <label className="field-label" htmlFor="cp_rep" style={{ marginTop: 12 }}>Repeat new password</label>
      <input id="cp_rep" className="field-input" type="password" autoComplete="new-password" value={f.new_password_confirmation} onChange={set('new_password_confirmation')} required />
      {err && <div className="field-error" style={{ marginTop: 10 }}>{err}</div>}
      <button className="btn-primary wide" disabled={busy} style={{ marginTop: 18 }}>{busy ? 'Saving…' : 'Change password'}</button>
      {forced && <button type="button" className="btn-link" onClick={() => logout()}>Sign out</button>}
    </form>
  );
  return forced ? <div className="login-wrap">{body}</div> : <div className="narrow">{body}</div>;
}
