import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../auth';

export default function Login() {
  const { login, notice } = useAuth();
  const nav = useNavigate();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [err, setErr] = useState(null);
  const [busy, setBusy] = useState(false);

  const submit = async (e) => {
    e.preventDefault();
    setErr(null);
    setBusy(true);
    try {
      await login(email.trim(), password);
      nav('/');
    } catch (ex) {
      setErr(ex.message);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="login-wrap">
      <div className="login-brand">
        <svg width="72" height="72" viewBox="0 0 160 160" aria-hidden="true">
          <path d="M80,140 C80,140 16,104 12,64 C8,36 26,20 44,20 C56,20 68,28 80,44 C92,28 104,20 116,20 C134,20 152,36 148,64 C144,104 80,140 80,140Z" fill="#C8102E" />
          <polyline className="ecg-run" points="30,78 58,78 66,64 74,92 84,48 94,100 102,78 130,78" fill="none" stroke="#fff" strokeWidth="7" strokeLinecap="round" strokeLinejoin="round" />
        </svg>
        <div className="login-title">SMART-HEART</div>
        <div className="login-sub">Electronic Case Report Form · Sri Ramachandra Medical Centre</div>
        <div className="login-ta">ஸ்மார்ட் ஹார்ட் இதயம்</div>
      </div>
      <form className="login-card" onSubmit={submit}>
        <div className="form-eyebrow">Staff sign-in</div>
        <div className="form-title" style={{ marginBottom: 16 }}>Sign in to the eCRF</div>
        {notice && <div className="alert alert-info"><div className="alert-icon">ℹ</div><div className="alert-body"><div className="alert-desc">{notice}</div></div></div>}
        <label className="field-label" htmlFor="em">Email</label>
        <input id="em" className="field-input" type="email" autoComplete="username" value={email} onChange={(e) => setEmail(e.target.value)} required autoFocus />
        <label className="field-label" htmlFor="pw" style={{ marginTop: 12 }}>Password</label>
        <input id="pw" className="field-input" type="password" autoComplete="current-password" value={password} onChange={(e) => setPassword(e.target.value)} required />
        {err && <div className="field-error" style={{ marginTop: 10 }}>{err}</div>}
        <button className="btn-primary wide" disabled={busy} style={{ marginTop: 18 }}>{busy ? 'Signing in…' : 'Sign in'}</button>
        <div className="login-note">Authorised study staff only. Sign-ins and all data changes are recorded. Five wrong passwords lock the account for 15 minutes.</div>
      </form>
    </div>
  );
}
