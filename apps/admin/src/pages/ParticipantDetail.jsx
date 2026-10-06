import { useCallback, useEffect, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { api } from '../api';
import { useAuth } from '../auth';
import { Alert, ArmTag, FormStatus, StatusPill, fmtDate, fmtDateTime } from '../components/Bits';
import Modal from '../components/Modal';
import { useToast } from '../components/Toast';

function EditIdentity({ p, onClose, onSaved }) {
  const [f, setF] = useState({ full_name: p.full_name, phone: p.phone, hospital_number: p.hospital_number || '', address: p.address || '', reason: '' });
  const [err, setErr] = useState(null);
  const save = async () => {
    try {
      onSaved(await api(`/participants/${p.id}`, { method: 'PUT', body: f }));
    } catch (ex) {
      setErr(ex.message);
    }
  };
  const set = (k) => (e) => setF({ ...f, [k]: e.target.value });
  return (
    <Modal title="Correct identity details" eyebrow={p.study_id} onClose={onClose}
      footer={<><button className="btn-secondary" onClick={onClose}>Cancel</button><button className="btn-primary" onClick={save}>Save with reason</button></>}>
      <label className="field-label">Full name</label><input className="field-input" value={f.full_name} onChange={set('full_name')} />
      <label className="field-label mt">Mobile number</label><input className="field-input" value={f.phone} onChange={set('phone')} maxLength={10} />
      <label className="field-label mt">Hospital number</label><input className="field-input" value={f.hospital_number} onChange={set('hospital_number')} />
      <label className="field-label mt">Address</label><textarea className="field-input" rows={2} value={f.address} onChange={set('address')} />
      <label className="field-label mt">Reason for change <span className="field-req">*</span></label>
      <input className="field-input" value={f.reason} onChange={set('reason')} placeholder="e.g. Spelling corrected from Aadhaar card" />
      {err && <div className="field-error">{err}</div>}
    </Modal>
  );
}

export default function ParticipantDetail() {
  const { id } = useParams();
  const { can } = useAuth();
  const nav = useNavigate();
  const toast = useToast();
  const [p, setP] = useState(null);
  const [err, setErr] = useState(null);
  const [edit, setEdit] = useState(false);

  const load = useCallback(() => api(`/participants/${id}`).then(setP).catch((e) => setErr(e.message)), [id]);
  useEffect(() => { load(); }, [load]);

  if (err) return <div className="panel-page"><div className="field-error">{err}</div></div>;
  if (!p) return <div className="panel-page">Loading…</div>;

  return (
    <div className="panel-page">
      <button className="btn-link" onClick={() => nav('/')}>← Dashboard</button>
      <div className="pt-header">
        <div>
          <div className="form-eyebrow">Participant record</div>
          <div className="pt-name">{p.full_name}</div>
          <div className="pt-tags">
            <span className="mono-tag">{p.study_id}</span>
            <span className="mono-tag">{p.screening_id}</span>
            <StatusPill status={p.status} />
            <ArmTag arm={p.arm} />
            {p.age_stratum && <span className="mono-tag">Age stratum {p.age_stratum}</span>}
          </div>
        </div>
        <div className="pt-meta">
          <div><span>Mobile</span>{p.phone}</div>
          <div><span>Hospital no.</span>{p.hospital_number || '—'}</div>
          <div><span>Registered</span>{fmtDate(p.registered_on)}</div>
          {can('participants', 'write') && <button className="btn-secondary small" onClick={() => setEdit(true)}>Correct identity…</button>}
        </div>
      </div>

      {p.status === 'screen_failure' && (
        <Alert kind="fail" title={`Screen failure${p.screen_failed_on ? ` on ${fmtDate(p.screen_failed_on)}` : ''}`}>
          <ul className="fail-list">{(p.screen_fail_reasons || []).map((r) => <li key={r}>{r}</li>)}</ul>
          Recorded for the CONSORT diagram. No further forms can be opened.
        </Alert>
      )}
      {p.status === 'not_proceeding' && <Alert kind="sys" title="Not proceeding">REG-01 records this person as not potentially eligible.</Alert>}
      {p.status === 'consented' && <Alert kind="ok" title="Consent recorded">Baseline (BL-01), PROs, safety clearance and randomisation will open here in Phase 2.</Alert>}

      <div className="module-grid">
        {p.form_list.map((f, i) => {
          const open = f.status !== 'planned' && !f.locked_reason;
          const viewable = f.status !== 'planned' && (open || f.status !== 'not_started');
          const cls = f.status === 'signed' ? 'complete' : open && f.status !== 'complete' ? 'active-mod' : '';
          return (
            <div key={f.code}
              className={`module-card ${cls} ${!viewable ? 'locked' : ''}`}
              onClick={() => viewable && nav(`/participants/${p.id}/forms/${f.code}`)}
              role={viewable ? 'link' : undefined} tabIndex={viewable ? 0 : -1}
              onKeyDown={(e) => viewable && e.key === 'Enter' && nav(`/participants/${p.id}/forms/${f.code}`)}>
              <div className="mod-num">{f.status === 'signed' ? '✓' : i + 1}</div>
              <div className="mod-info">
                <div className="mod-title">{f.code} · {f.title}</div>
                <div className="mod-desc">
                  <FormStatus status={f.status} />
                  {f.elig_status && f.status !== 'not_started' && <span className={`elig-chip ${f.elig_status}`}>{f.elig_status.replace('_', ' ')}</span>}
                </div>
                {f.signed_at && <div className="mod-status done">Signed {fmtDateTime(f.signed_at)}</div>}
                {f.locked_reason && f.status !== 'signed' && <div className="mod-status pending">🔒 {f.locked_reason}</div>}
              </div>
            </div>
          );
        })}
      </div>
      {can('audit') && <Link className="btn-link" to={`/audit?participant_id=${p.id}`}>View this participant's audit trail →</Link>}

      {edit && <EditIdentity p={p} onClose={() => setEdit(false)} onSaved={(np) => { setP(np); setEdit(false); toast('Identity updated'); }} />}
    </div>
  );
}
