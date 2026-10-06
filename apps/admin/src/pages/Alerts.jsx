import { useCallback, useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../api';
import { useAuth } from '../auth';
import { Empty, fmtDateTime } from '../components/Bits';
import Modal from '../components/Modal';
import { useToast } from '../components/Toast';

const RULE = { PHQ9_ITEM9: 'PHQ-9 item 9 (self-harm thoughts)', PHQ9_GE10: 'PHQ-9 ≥ 10', GAD7_GE10: 'GAD-7 ≥ 10' };

export default function Alerts() {
  const { can } = useAuth();
  const toast = useToast();
  const [filter, setFilter] = useState('active');
  const [res, setRes] = useState(null);
  const [closing, setClosing] = useState(null);
  const [note, setNote] = useState('');
  const [err, setErr] = useState(null);
  const write = can('alerts', 'write');

  const load = useCallback(() => api(`/alerts?status=${filter}`).then(setRes), [filter]);
  useEffect(() => { load(); }, [load]);

  const ack = async (a) => {
    await api(`/alerts/${a.id}/acknowledge`, { method: 'POST' });
    toast('Acknowledged');
    load();
  };
  const close = async () => {
    setErr(null);
    try {
      await api(`/alerts/${closing.id}/close`, { method: 'POST', body: { note } });
      setClosing(null);
      setNote('');
      toast('Alert closed');
      load();
    } catch (ex) {
      setErr(ex.message);
    }
  };

  return (
    <div className="panel-page wide">
      <div className="form-header row">
        <div>
          <div className="form-eyebrow">Safety</div>
          <div className="form-title">Safety alerts</div>
          <div className="form-desc">PHQ-9 item 9 above 0 raises a critical alert at any total; it emails the alert contacts at once and escalates if not acknowledged within 2 hours. It never clears by itself: a clinician closes it with a note. PHQ-9 or GAD-7 ≥ 10 raises a clinical-review warning.</div>
        </div>
        <div className="seg">
          {[['active', 'Not closed'], ['open', 'Unacknowledged'], ['closed', 'Closed'], ['', 'All']].map(([k, l]) => (
            <button key={k} className={filter === k ? 'on' : ''} onClick={() => setFilter(k)}>{l}</button>
          ))}
        </div>
      </div>
      {!res ? <Empty>Loading…</Empty> : res.data.length === 0 ? <Empty>No alerts.</Empty> : res.data.map((a) => (
        <div key={a.id} className={`alert-card ${a.severity} ${a.status}`}>
          <div className="ac-main">
            <div className="ac-top">
              <span className={`sev ${a.severity}`}>{a.severity === 'critical' ? 'CRITICAL' : 'Review'}</span>
              <b>{RULE[a.rule] || a.rule}</b>
              <Link to={`/participants/${a.participant_id}`} className="mono-tag">{a.study_id}</Link>
              <span className="ac-name">{a.full_name}</span>
            </div>
            <div className="ac-summary">{a.summary}</div>
            <div className="ac-times">
              Raised {fmtDateTime(a.raised_at)}
              {a.notified_at && <> · emailed {fmtDateTime(a.notified_at)}</>}
              {a.escalated_at && <> · <b className="es-fail">escalated {fmtDateTime(a.escalated_at)}</b></>}
              {a.acknowledged_at && <> · acknowledged by {a.acknowledged_by} {fmtDateTime(a.acknowledged_at)}</>}
              {a.closed_at && <> · closed by {a.closed_by} {fmtDateTime(a.closed_at)}</>}
            </div>
            {a.close_note && <div className="ac-note">“{a.close_note}”</div>}
          </div>
          {write && a.status !== 'closed' && (
            <div className="ac-actions">
              {a.status === 'open' && <button className="btn-primary" onClick={() => ack(a)}>Acknowledge</button>}
              <button className="btn-secondary" onClick={() => { setClosing(a); setNote(''); setErr(null); }}>Close with note…</button>
            </div>
          )}
        </div>
      ))}
      {closing && (
        <Modal title="Close alert" eyebrow={`${closing.study_id} · ${RULE[closing.rule] || closing.rule}`} onClose={() => setClosing(null)}
          footer={<><button className="btn-secondary" onClick={() => setClosing(null)}>Cancel</button><button className="btn-primary" disabled={note.trim().length < 10} onClick={close}>Close alert</button></>}>
          <label className="field-label" htmlFor="close_note">What was done (contact, outcome, referral)</label>
          <textarea id="close_note" className="field-input" rows={4} value={note} onChange={(e) => setNote(e.target.value)}
            placeholder="e.g. Phoned participant 14:20, no current plan or intent, psychiatry OPD booked 9 Oct, caregiver informed." />
          {err && <div className="field-error">{err}</div>}
        </Modal>
      )}
    </div>
  );
}
