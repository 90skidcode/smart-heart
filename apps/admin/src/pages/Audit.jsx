import { useEffect, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import { api, download, qs } from '../api';
import { Empty, fmtDateTime } from '../components/Bits';
import { useToast } from '../components/Toast';

const LABEL = {
  field_changed: 'Value changed', form_created: 'Form started', form_completed: 'Form completed', form_signed: 'Form signed',
  form_unlocked: 'Form unlocked', sign_failed: 'Signature failed', range_override: 'Out-of-range confirmed',
  participant_registered: 'Participant registered', participant_status: 'Status changed', identity_changed: 'Identity corrected',
  login: 'Signed in', logout: 'Signed out', login_failed: 'Sign-in failed', login_blocked: 'Sign-in blocked (locked)',
  password_changed: 'Password changed', password_reset: 'Password reset', user_created: 'User created', user_updated: 'User updated',
  role_created: 'Role created', role_deleted: 'Role deleted', permission_changed: 'Permission changed', export: 'Data exported',
  backup_created: 'Backup created', form_status: 'Form status changed',
};

export default function Audit() {
  const [sp] = useSearchParams();
  const toast = useToast();
  const [f, setF] = useState({ study_id: '', action: '', form_code: '', from: '', to: '', participant_id: sp.get('participant_id') || '' });
  const [page, setPage] = useState(1);
  const [res, setRes] = useState(null);
  const [open, setOpen] = useState(null);

  useEffect(() => {
    const t = setTimeout(() => api(`/audit${qs({ ...f, page })}`).then(setRes), 250);
    return () => clearTimeout(t);
  }, [f, page]);

  const set = (k) => (e) => { setF({ ...f, [k]: e.target.value }); setPage(1); };

  return (
    <div className="panel-page wide">
      <div className="form-header row">
        <div>
          <div className="form-eyebrow">Data</div>
          <div className="form-title">Audit trail</div>
          <div className="form-desc">Every sign-in, data entry, change, signature, unlock, export and permission change — who, when, old and new value, and why. Entries cannot be edited or deleted.</div>
        </div>
        <button className="btn-secondary" onClick={async () => { const n = await download(`/audit/download${qs(f)}`); toast(`Downloaded ${n}`); }}>⇩ Download CSV</button>
      </div>

      <div className="filters">
        {f.participant_id && <span className="chip">Participant #{f.participant_id} <button onClick={() => setF({ ...f, participant_id: '' })}>✕</button></span>}
        <input className="field-input" placeholder="Study ID" value={f.study_id} onChange={set('study_id')} />
        <select className="field-input" value={f.action} onChange={set('action')}>
          <option value="">All actions</option>
          {(res?.actions || []).map((a) => <option key={a} value={a}>{LABEL[a] || a}</option>)}
        </select>
        <select className="field-input" value={f.form_code} onChange={set('form_code')}>
          <option value="">All forms</option>
          {['REG-01', 'SCR-01', 'CON-01'].map((c) => <option key={c}>{c}</option>)}
        </select>
        <input className="field-input" type="date" value={f.from} onChange={set('from')} aria-label="From" />
        <input className="field-input" type="date" value={f.to} onChange={set('to')} aria-label="To" />
      </div>

      <div className="audit-table">
        <div className="audit-head"><span>Time (IST)</span><span>User</span><span>Action</span><span>Study ID · Form</span><span>Field</span><span>Old → New</span><span>Reason</span></div>
        {!res ? <Empty>Loading…</Empty> : res.data.length === 0 ? <Empty>No entries.</Empty> : res.data.map((a) => (
          <div className={`audit-row ${open === a.id ? 'open' : ''}`} key={a.id} onClick={() => setOpen(open === a.id ? null : a.id)}>
            <span className="mono">{fmtDateTime(a.created_at)}</span>
            <span>{a.user_name?.replace(/\s*\(.*\)$/, '') || '—'}</span>
            <span className={`act act-${a.action}`}>{LABEL[a.action] || a.action}</span>
            <span className="mono">{a.study_id || ''}{a.form_code ? ` · ${a.form_code}` : ''}</span>
            <span className="mono">{a.field || ''}</span>
            <span className="ov">{a.old_value != null || a.new_value != null ? <><s>{a.old_value ?? '∅'}</s> → <b>{a.new_value ?? '∅'}</b></> : ''}</span>
            <span>{a.reason || ''}</span>
            {open === a.id && (
              <div className="audit-detail">
                <div><b>User:</b> {a.user_name || '—'} · <b>IP:</b> {a.ip_address || '—'} · <b>Entry #</b>{a.id}</div>
                {a.meta && <pre>{JSON.stringify(a.meta, null, 2)}</pre>}
              </div>
            )}
          </div>
        ))}
      </div>
      {res && res.last_page > 1 && (
        <div className="pager">
          <button className="btn-secondary small" disabled={page <= 1} onClick={() => setPage(page - 1)}>← Newer</button>
          <span>Page {res.page} of {res.last_page} · {res.total} entries</span>
          <button className="btn-secondary small" disabled={page >= res.last_page} onClick={() => setPage(page + 1)}>Older →</button>
        </div>
      )}
    </div>
  );
}
