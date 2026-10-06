import { useCallback, useEffect, useState } from 'react';
import { api } from '../api';
import { useAuth } from '../auth';
import { Alert, Empty, fmtDate, fmtDateTime } from './Bits';
import Modal from './Modal';
import { useToast } from './Toast';

const SLOTS = ['Morning', 'Afternoon', 'Evening', 'Night'];
const FREQ = ['Once daily', 'Twice daily', 'Three times daily', 'Four times daily', 'At night', 'Weekly', 'As needed'];
const STATUS = { invited: ['Not yet signed in', 'sp-screening'], active: ['Active', 'sp-eligible'], revoked: ['Removed', 'sp-muted'] };

const fmtReading = (r) => (r.type === 'bp' ? `${r.values.sbp}/${r.values.dbp} mmHg${r.values.pulse ? ` · pulse ${r.values.pulse}` : ''}`
  : r.type === 'glucose' ? `${r.values.mg_dl} mg/dL (${r.values.context.replace('_', ' ')})` : `${r.values.kg} kg`);
const slug = (s, i) => `${(s || 'med').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')}-${i + 1}`;

/** Medicine list editor: starts from the published list, or from BL-M6 when nothing is published yet. */
function MedEditor({ start, onCancel, onPublish, err }) {
  const [items, setItems] = useState(start);
  const [note, setNote] = useState('');
  const upd = (i, k, v) => setItems(items.map((m, j) => (j === i ? { ...m, [k]: v } : m)));
  return (
    <Modal title="Medicine list for the app" wide onClose={onCancel}
      footer={<><button className="btn-secondary" onClick={onCancel}>Cancel</button>
        <button className="btn-primary" disabled={!items.length} onClick={() => onPublish(items.map((m, i) => ({ ...m, key: m.key || slug(m.drug, i) })), note)}>Publish to the app</button></>}>
      <div className="form-desc" style={{ marginBottom: 10 }}>The participant sees exactly this list, with reminders at the ticked times. “Weekly” and “As needed” medicines are listed without daily reminders.</div>
      <div className="table-field">
        <table>
          <thead><tr><th>Medicine</th><th>Dose</th><th>Frequency</th><th>Times</th><th>Instructions (shown in app)</th><th /></tr></thead>
          <tbody>
            {items.map((m, i) => (
              <tr key={i}>
                <td><input aria-label={`Medicine, row ${i + 1}`} className="field-input" value={m.drug} onChange={(e) => upd(i, 'drug', e.target.value)} /></td>
                <td><input aria-label={`Dose, row ${i + 1}`} className="field-input" value={m.dose} onChange={(e) => upd(i, 'dose', e.target.value)} /></td>
                <td><select aria-label={`Frequency, row ${i + 1}`} className="field-input" value={m.frequency} onChange={(e) => upd(i, 'frequency', e.target.value)}>
                  {FREQ.map((f) => <option key={f}>{f}</option>)}</select></td>
                <td><div className="check-group">{SLOTS.map((s) => (
                  <label key={s} className={`check-pill ${m.times.includes(s) ? 'on' : ''}`}>
                    <input type="checkbox" checked={m.times.includes(s)} aria-label={`${s}, row ${i + 1}`}
                      onChange={() => upd(i, 'times', m.times.includes(s) ? m.times.filter((x) => x !== s) : SLOTS.filter((x) => x === s || m.times.includes(x)))} />{s}
                  </label>))}</div></td>
                <td><input aria-label={`Instructions, row ${i + 1}`} className="field-input" placeholder="e.g. after food" value={m.instructions || ''} onChange={(e) => upd(i, 'instructions', e.target.value)} /></td>
                <td><button type="button" className="btn-link danger" aria-label={`Remove row ${i + 1}`} onClick={() => setItems(items.filter((_, j) => j !== i))}>✕</button></td>
              </tr>
            ))}
          </tbody>
        </table>
        <button type="button" className="btn-secondary small" onClick={() => setItems([...items, { key: '', drug: '', dose: '', class: '', frequency: 'Once daily', times: ['Morning'], instructions: '' }])}>＋ Add medicine</button>
      </div>
      <label className="field-label mt" htmlFor="med_note">Note (recorded in the audit trail)</label>
      <input id="med_note" className="field-input" value={note} onChange={(e) => setNote(e.target.value)} placeholder="e.g. Checked against discharge summary" />
      {err && <div className="field-error">{Array.isArray(err) ? err.join(' ') : err}</div>}
    </Modal>
  );
}

/** Participant app: access, caregivers, medicine list, what came in. Intervention arm only. */
export default function AppPanel({ pid }) {
  const { can } = useAuth();
  const toast = useToast();
  const write = can('app_access', 'write');
  const [d, setD] = useState(null);
  const [err, setErr] = useState(null);
  const [cg, setCg] = useState(null);
  const [revoking, setRevoking] = useState(null);
  const [reason, setReason] = useState('');
  const [meds, setMeds] = useState(null);
  const [medErr, setMedErr] = useState(null);

  const load = useCallback(() => api(`/participants/${pid}/app`).then(setD).catch((e) => setErr(e.message)), [pid]);
  useEffect(() => { load(); }, [load]);

  const call = async (path, body, after) => {
    setErr(null);
    try {
      setD(await api(path, { method: 'POST', body }));
      after?.();
      return true;
    } catch (ex) {
      setErr([ex.message, ...Object.values(ex.body.errors || {}).flat()].join(' '));
      return false;
    }
  };
  const publish = async (items, note) => {
    setMedErr(null);
    try {
      setD(await api(`/participants/${pid}/app/medications`, { method: 'POST', body: { items, note } }));
      setMeds(null);
      toast('Medicine list published');
    } catch (ex) {
      setMedErr(ex.body.errors || ex.message);
    }
  };

  if (!d) return err ? <div className="field-error">{err}</div> : null;
  const participantUser = d.users.find((u) => u.role === 'participant' && u.status !== 'revoked');
  const caregivers = d.users.filter((u) => u.role === 'caregiver');

  return (
    <div className="form-section">
      <div className="fs-head"><div className="fs-num">📱</div><div><div className="fs-title">Participant app</div>
        <div className="fs-sub">Activation: the participant enters their Participant ID and verifies their registered mobile number by OTP.</div></div></div>
      <div className="fs-body">
        {d.blocker && <Alert kind="sys" title="App not available">{d.blocker}</Alert>}
        {!d.firebase_configured && <Alert kind="warn" title="Firebase not configured">Set FIREBASE_PROJECT_ID on the server before participants can sign in.</Alert>}
        {err && <div className="field-error">{err}</div>}

        <div className="app-users">
          {d.users.length === 0 && <Empty>App access not enabled yet.</Empty>}
          {d.users.map((u) => (
            <div key={u.id} className={`app-user ${u.status}`}>
              <div><b>{u.name}</b>{u.role === 'caregiver' && <span className="mono-tag">Caregiver · {u.relation} · view only</span>}
                <small>{u.phone} · {u.lang === 'ta' ? 'Tamil' : 'English'}{u.devices ? ` · ${u.devices} device${u.devices > 1 ? 's' : ''}` : ''}</small></div>
              <div><span className={`status-pill ${STATUS[u.status][1]}`}>{STATUS[u.status][0]}</span>
                <small>{u.status === 'revoked' ? `${fmtDateTime(u.revoked_at)} — ${u.revoke_reason}` : u.last_seen_at ? `Last opened ${fmtDateTime(u.last_seen_at)}` : ''}</small></div>
              {write && u.status !== 'revoked' && <button className="btn-link danger" onClick={() => { setRevoking(u); setReason(''); }}>Remove access…</button>}
            </div>
          ))}
        </div>

        {write && !d.blocker && (
          <div className="selfentry-actions">
            {!participantUser && <button className="btn-primary" onClick={() => call(`/participants/${pid}/app/enable`, {}, () => toast('App access enabled'))}>Enable app for participant</button>}
            {participantUser && d.caregiver_allowed && caregivers.filter((c) => c.status !== 'revoked').length < d.max_caregivers && (
              <button className="btn-secondary" onClick={() => setCg({ name: '', relation: '', phone: '' })}>＋ Add caregiver</button>)}
            {participantUser && !d.caregiver_allowed && <span className="field-note">Consent does not allow caregiver viewing.</span>}
          </div>
        )}

        <div className="group-title">Medicine list in the app</div>
        {d.schedule ? (
          <div className="med-list">
            {d.schedule.items.map((m) => <div key={m.key}><b>{m.drug}</b> {m.dose} · {m.frequency}{m.times.length ? ` · ${m.times.join(', ')}` : ''}{m.instructions && <small>{m.instructions}</small>}</div>)}
            <small className="field-note">Version {d.schedule.version} · published {fmtDateTime(d.schedule.published_at)} by {d.schedule.published_by}{d.schedule.note ? ` — ${d.schedule.note}` : ''}</small>
          </div>
        ) : <div className="field-note">{d.draft.note || 'Not published yet. The participant sees no medicines until you publish the list.'}</div>}
        {write && !d.blocker && (d.schedule || d.draft.items.length > 0) && (
          <button className="btn-secondary small mt" onClick={() => { setMedErr(null); setMeds(d.schedule ? d.schedule.items : d.draft.items); }}>
            {d.schedule ? 'Update medicine list…' : 'Review BL-M6 list and publish…'}</button>
        )}

        {d.schedule && (
          <>
            <div className="group-title">Doses answered · last 7 days</div>
            <table className="ccsps-table">
              <thead><tr><th>Date</th><th>Due</th><th>Taken</th><th>Skipped</th><th>No answer</th></tr></thead>
              <tbody>{d.doses.map((x) => <tr key={x.date}><td>{fmtDate(x.date)}</td><td>{x.due}</td><td>{x.taken}</td><td>{x.skipped}</td><td>{x.unanswered}</td></tr>)}</tbody>
            </table>
          </>
        )}

        <div className="group-title">Home readings (latest 50)</div>
        {d.readings.length === 0 ? <div className="field-note">No readings yet.</div> : (
          <table className="ccsps-table">
            <thead><tr><th>Measured</th><th>Reading</th><th>Source</th><th>Received</th></tr></thead>
            <tbody>{d.readings.map((r) => (
              <tr key={r.id}><td>{fmtDateTime(r.measured_at)}</td><td>{fmtReading(r)}</td>
                <td>{r.source === 'manual' ? 'Typed in' : 'Health Connect'}{r.device && <small>{r.device}</small>}</td><td>{fmtDateTime(r.uploaded_at)}</td></tr>))}</tbody>
          </table>
        )}
        {d.seen.length > 0 && <div className="field-note mt">Caregiver “Seen”: {d.seen.map((s) => fmtDate(s.day)).join(', ')}</div>}
      </div>

      {cg && (
        <Modal title="Add caregiver" onClose={() => setCg(null)}
          footer={<><button className="btn-secondary" onClick={() => setCg(null)}>Cancel</button>
            <button className="btn-primary" onClick={() => call(`/participants/${pid}/app/caregivers`, cg, () => { setCg(null); toast('Caregiver added'); })}>Add caregiver</button></>}>
          <div className="form-desc" style={{ marginBottom: 10 }}>The caregiver installs the app on their own phone, enters the Participant ID and verifies their own number. They can view, not change, information.</div>
          <label className="field-label" htmlFor="cg_name">Name</label>
          <input id="cg_name" className="field-input" value={cg.name} onChange={(e) => setCg({ ...cg, name: e.target.value })} />
          <label className="field-label mt" htmlFor="cg_rel">Relationship</label>
          <input id="cg_rel" className="field-input" value={cg.relation} onChange={(e) => setCg({ ...cg, relation: e.target.value })} placeholder="e.g. Wife, Son" />
          <label className="field-label mt" htmlFor="cg_phone">Caregiver's own mobile number</label>
          <input id="cg_phone" className="field-input" inputMode="numeric" maxLength={10} value={cg.phone} onChange={(e) => setCg({ ...cg, phone: e.target.value.replace(/\D/g, '') })} />
          {err && <div className="field-error">{err}</div>}
        </Modal>
      )}
      {revoking && (
        <Modal title="Remove app access" eyebrow={revoking.name} onClose={() => setRevoking(null)}
          footer={<><button className="btn-secondary" onClick={() => setRevoking(null)}>Cancel</button>
            <button className="btn-primary" disabled={reason.trim().length < 5} onClick={() => call(`/app-users/${revoking.id}/revoke`, { reason }, () => { setRevoking(null); toast('Access removed'); })}>Remove access</button></>}>
          <div className="form-desc" style={{ marginBottom: 10 }}>They are signed out on every phone at once. Data already sent is kept.</div>
          <label className="field-label" htmlFor="rv_reason">Reason</label>
          <input id="rv_reason" className="field-input" value={reason} onChange={(e) => setReason(e.target.value)} placeholder="e.g. Phone lost; caregiver changed" />
          {err && <div className="field-error">{err}</div>}
        </Modal>
      )}
      {meds && <MedEditor start={meds} err={medErr} onCancel={() => setMeds(null)} onPublish={publish} />}
    </div>
  );
}
