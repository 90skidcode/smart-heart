import { useEffect, useState } from 'react';
import { api } from '../api';
import { useAuth } from '../auth';
import { Alert, fmtDate, fmtDateTime } from './Bits';
import Modal from './Modal';
import { useToast } from './Toast';

/** RAND-01: gate check, then a password-confirmed, irreversible allocation done by the server. */
export default function RandomisePanel({ p, onDone }) {
  const { can } = useAuth();
  const toast = useToast();
  const [check, setCheck] = useState(null);
  const [open, setOpen] = useState(false);
  const [pw, setPw] = useState('');
  const [err, setErr] = useState(null);
  const [busy, setBusy] = useState(false);
  const [result, setResult] = useState(null);

  useEffect(() => {
    if (p.status !== 'randomised') api(`/participants/${p.id}/randomisation`).then(setCheck).catch(() => {});
  }, [p.id, p.status]);

  const go = async () => {
    setBusy(true);
    setErr(null);
    try {
      const r = await api(`/participants/${p.id}/randomise`, { method: 'POST', body: { password: pw } });
      setResult(r);
      setOpen(false);
      toast('Randomised');
      onDone();
    } catch (ex) {
      setErr(ex.body.blockers ? ex.body.blockers.join(' ') : ex.message);
    } finally {
      setBusy(false);
    }
  };

  const r = p.randomisation;
  return (
    <div className="form-section">
      <div className="fs-head"><div className="fs-num">🎲</div><div><div className="fs-title">RAND-01 · Randomisation</div>
        <div className="fs-sub">Stratified by age on the randomisation date (&lt;60 / ≥60). Allocation is taken from the statistician's list and cannot be undone.</div></div></div>
      <div className="fs-body">
        {r ? (
          <div className="rand-done">
            <div><span>Date</span>{fmtDate(r.date)}</div>
            <div><span>Age · stratum</span>{r.age} · {r.stratum === 'lt60' ? '<60' : '≥60'}</div>
            <div><span>List row</span>#{r.seq_no}</div>
            <div><span>Arm</span><b>{p.arm === 'blinded' ? 'Blinded for your role' : p.arm === 'intervention' ? 'Intervention (SMART-HEART app)' : 'Control (usual care)'}</b></div>
            <div><span>By</span>{r.by} · {fmtDateTime(r.at)}</div>
            {result && p.arm !== 'blinded' && <Alert kind="ok" title="Next step">{p.arm === 'intervention' ? 'Activate the participant app and issue the watch and BP monitor.' : 'Explain usual care and the follow-up visit schedule.'}</Alert>}
          </div>
        ) : (
          <>
            {check && check.blockers.length > 0 ? (
              <Alert kind="sys" title="Not yet possible">
                <ul className="fail-list">{check.blockers.map((b) => <li key={b}>{b}</li>)}</ul>
              </Alert>
            ) : check ? <Alert kind="ok" title="All gates passed">Eligibility, consent, baseline, PROs and safety clearance are complete.</Alert> : null}
            {can('randomisation', 'write') && (
              <button className="btn-nav btn-confirm" disabled={!check || check.blockers.length > 0} onClick={() => { setPw(''); setErr(null); setOpen(true); }}>🎲 Randomise…</button>
            )}
          </>
        )}
      </div>
      {open && (
        <Modal title="Randomise participant" eyebrow={p.study_id} onClose={() => setOpen(false)}
          footer={<><button className="btn-secondary" onClick={() => setOpen(false)}>Cancel</button><button className="btn-confirm" disabled={!pw || busy} onClick={go}>{busy ? 'Allocating…' : 'Randomise now'}</button></>}>
          <div className="sign-meaning">“I confirm that all randomisation gates were met and that this participant was allocated by the system.”</div>
          <div className="form-desc" style={{ margin: '10px 0' }}>This is final. The allocation cannot be changed or repeated.</div>
          <label className="field-label" htmlFor="rand_pw">Your password</label>
          <input id="rand_pw" className="field-input" type="password" autoFocus value={pw} onChange={(e) => setPw(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && pw && go()} />
          {err && <div className="field-error">{err}</div>}
        </Modal>
      )}
    </div>
  );
}
