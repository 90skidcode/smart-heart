import { useCallback, useEffect, useRef, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { api } from '../api';
import { useAuth } from '../auth';
import { Alert, FormStatus, fmtDateTime } from '../components/Bits';
import EligibilityPanel from '../components/EligibilityPanel';
import FormRenderer from '../components/FormRenderer';
import Modal from '../components/Modal';
import { useToast } from '../components/Toast';
import { useDefinitions } from '../hooks';
import SelfEntryPanel from '../components/SelfEntryPanel';

// Mirrors FormRegistry::screenKey on the server.
const screenKey = (code) => (code.startsWith('BL-') ? 'form_baseline' : code.startsWith('PRO-') ? 'form_pro' : `form_${code.replace('-', '').toLowerCase()}`);

function labelOf(def, code) {
  for (const s of def.sections) for (const f of s.fields) if (f.code === code) return f.label;
  return code;
}

/** Asks for a reason for each changed value on a form that was already complete. */
function ReasonModal({ fields, def, reasonsList, onCancel, onSubmit }) {
  const [vals, setVals] = useState(() => Object.fromEntries(fields.map((f) => [f, { pick: '', other: '' }])));
  const final = Object.fromEntries(fields.map((f) => [f, vals[f].pick === 'Other' ? vals[f].other.trim() : vals[f].pick]));
  const ok = fields.every((f) => final[f] && final[f].length >= 3);
  const [all, setAll] = useState('');
  return (
    <Modal title="Reason for change" eyebrow="Required — this form was already complete" onClose={onCancel}
      footer={<><button className="btn-secondary" onClick={onCancel}>Cancel</button><button className="btn-primary" disabled={!ok} onClick={() => onSubmit(final)}>Save changes</button></>}>
      <div className="form-desc" style={{ marginBottom: 12 }}>Every change to a completed form is recorded with the old value, new value, your name, time and this reason.</div>
      {fields.length > 1 && (
        <div className="apply-all">
          <select className="field-input" value={all} onChange={(e) => {
            setAll(e.target.value);
            if (e.target.value) setVals(Object.fromEntries(fields.map((f) => [f, { pick: e.target.value, other: vals[f].other }])));
          }}>
            <option value="">Apply one reason to all…</option>
            {reasonsList.map((r) => <option key={r}>{r}</option>)}
          </select>
        </div>
      )}
      {fields.map((f) => (
        <div key={f} className="reason-field">
          <div className="field-label">{labelOf(def, f)} <span className="field-code">{f}</span></div>
          <select className="field-input" value={vals[f].pick} onChange={(e) => setVals({ ...vals, [f]: { ...vals[f], pick: e.target.value } })}>
            <option value="">— Select reason —</option>
            {reasonsList.map((r) => <option key={r}>{r}</option>)}
          </select>
          {vals[f].pick === 'Other' && (
            <input className="field-input mt" placeholder="Describe the reason" value={vals[f].other}
              onChange={(e) => setVals({ ...vals, [f]: { ...vals[f], other: e.target.value } })} />
          )}
        </div>
      ))}
    </Modal>
  );
}

function SignModal({ meaning, onCancel, onSign, busy, error }) {
  const [pw, setPw] = useState('');
  return (
    <Modal title="Electronic signature" eyebrow="PI declaration" onClose={onCancel}
      footer={<><button className="btn-secondary" onClick={onCancel}>Cancel</button><button className="btn-confirm" disabled={!pw || busy} onClick={() => onSign(pw)}>{busy ? 'Signing…' : 'Sign and lock form'}</button></>}>
      <div className="sign-meaning">“{meaning}”</div>
      <div className="form-desc" style={{ margin: '10px 0' }}>Re-enter your password to sign. Your name, the date and time, and this statement are stored with the form. A signed form is locked; changing it later needs an unlock with a reason and a new signature.</div>
      <label className="field-label" htmlFor="sign_pw">Your password</label>
      <input id="sign_pw" className="field-input" type="password" autoFocus autoComplete="current-password" value={pw} onChange={(e) => setPw(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && pw && onSign(pw)} />
      {error && <div className="field-error">{error}</div>}
    </Modal>
  );
}

function UnlockModal({ onCancel, onUnlock, error }) {
  const [r, setR] = useState('');
  return (
    <Modal title="Unlock signed form" eyebrow="Requires a reason" onClose={onCancel}
      footer={<><button className="btn-secondary" onClick={onCancel}>Cancel</button><button className="btn-primary" disabled={r.trim().length < 5} onClick={() => onUnlock(r.trim())}>Unlock</button></>}>
      <div className="form-desc" style={{ marginBottom: 10 }}>The signature will be removed and the form must be signed again after the correction. This is recorded in the audit trail.</div>
      <label className="field-label" htmlFor="unlock_reason">Reason for unlocking</label>
      <textarea id="unlock_reason" className="field-input" rows={3} value={r} onChange={(e) => setR(e.target.value)} placeholder="e.g. LVEF transcribed wrongly — echo report shows 48%" />
      {error && <div className="field-error">{error}</div>}
    </Modal>
  );
}

export default function FormPage() {
  const { id, code } = useParams();
  const defs = useDefinitions();
  const { can } = useAuth();
  const nav = useNavigate();
  const toast = useToast();

  const [form, setForm] = useState(null);
  const [data, setData] = useState({});
  const [overrides, setOverrides] = useState({});
  const [check, setCheck] = useState(null);
  const [elig, setElig] = useState(null);
  const [dirty, setDirty] = useState(false);
  const [busy, setBusy] = useState(false);
  const [problem, setProblem] = useState(null);
  const [needReasons, setNeedReasons] = useState(null);
  const [signing, setSigning] = useState(false);
  const [signErr, setSignErr] = useState(null);
  const [unlocking, setUnlocking] = useState(false);
  const [unlockErr, setUnlockErr] = useState(null);
  const [loadErr, setLoadErr] = useState(null);
  const previewTimer = useRef();

  const apply = useCallback((f) => {
    setForm(f);
    setData(f.data || {});
    setOverrides(f.overrides || {});
    setCheck(f.check);
    setElig(f.eligibility);
    setDirty(false);
  }, []);

  useEffect(() => {
    api(`/participants/${id}/forms/${code}`).then(apply).catch((e) => setLoadErr(e.message));
  }, [id, code, apply]);

  // Warn before leaving with unsaved changes.
  useEffect(() => {
    const h = (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } };
    window.addEventListener('beforeunload', h);
    return () => window.removeEventListener('beforeunload', h);
  }, [dirty]);

  const onChange = (k, v) => {
    setData((d) => {
      const nd = { ...d, [k]: v };
      clearTimeout(previewTimer.current);
      previewTimer.current = setTimeout(() => {
        api(`/forms/${code}/preview`, { method: 'POST', body: { data: nd, participant_id: Number(id) } })
          .then((r) => { setCheck(r); if (r.eligibility) setElig(r.eligibility); })
          .catch(() => {});
      }, 350);
      return nd;
    });
    setDirty(true);
    setProblem(null);
  };

  const onOverride = (k, v) => {
    setOverrides((o) => ({ ...o, [k]: v }));
    setDirty(true);
  };

  if (loadErr) return <div className="panel-page"><div className="field-error">{loadErr}</div></div>;
  if (!form || !defs) return <div className="panel-page">Loading…</div>;

  const def = defs.forms[code];
  const canWrite = can(screenKey(code), 'write');
  const locked = !!form.locked_reason;
  const signed = form.status === 'signed';
  const readOnly = !canWrite || locked || signed;

  const save = async (reasons = {}) => {
    setBusy(true);
    setProblem(null);
    try {
      const f = await api(`/participants/${id}/forms/${code}`, { method: 'PUT', body: { data, overrides, reasons } });
      apply(f);
      setNeedReasons(null);
      toast('Saved');
      return f;
    } catch (ex) {
      if (ex.body.need_reasons) setNeedReasons(ex.body.need_reasons);
      else setProblem({ message: ex.message, ...ex.body });
      if (ex.body.errors) setCheck((c) => ({ ...c, errors: ex.body.errors }));
      return null;
    } finally {
      setBusy(false);
    }
  };

  const complete = async () => {
    if (dirty) {
      const f = await save();
      if (!f) return;
    }
    setBusy(true);
    try {
      apply(await api(`/participants/${id}/forms/${code}/complete`, { method: 'POST' }));
      toast('Marked complete — ready for PI signature');
    } catch (ex) {
      setProblem({ message: ex.message, ...ex.body });
    } finally {
      setBusy(false);
    }
  };

  const sign = async (password) => {
    setBusy(true);
    setSignErr(null);
    try {
      apply(await api(`/participants/${id}/forms/${code}/sign`, { method: 'POST', body: { password } }));
      setSigning(false);
      toast('Signed and locked');
    } catch (ex) {
      setSignErr(ex.message);
    } finally {
      setBusy(false);
    }
  };

  const unlock = async (reason) => {
    setUnlockErr(null);
    try {
      apply(await api(`/participants/${id}/forms/${code}/unlock`, { method: 'POST', body: { reason } }));
      setUnlocking(false);
      toast('Form unlocked — sign again after correcting');
    } catch (ex) {
      setUnlockErr(ex.message);
    }
  };

  const missingLabels = (problem?.missing || []).map((c) => labelOf(def, c));
  const unconfirmedLabels = (problem?.unconfirmed || []).map((c) => labelOf(def, c));
  const meaning = defs.signature_meanings[code] || defs.signature_meanings.default;
  const isScr = code === 'SCR-01';

  return (
    <div className={`panel-page ${isScr ? 'with-side' : ''}`}>
      <div className="form-main">
        <button className="btn-link" onClick={() => (dirty && !confirm('Leave without saving your changes?')) ? null : nav(`/participants/${id}`)}>← {form.participant.study_id} · {form.participant.full_name}</button>
        <div className="form-header">
          <div className="form-eyebrow">{def.eyebrow}</div>
          <div className="form-title">{def.title}</div>
          <div className="form-desc">{def.description}</div>
          <div className="form-meta">
            <FormStatus status={form.status} />
            {form.updated_at && <span>Last saved {fmtDateTime(form.updated_at)}</span>}
            {form.unlock_count > 0 && <span>Unlocked {form.unlock_count}×</span>}
            {dirty && <span className="unsaved">● Unsaved changes</span>}
          </div>
        </div>

        {locked && <Alert kind="sys" title="This form is locked" icon="🔒">{form.locked_reason}</Alert>}
        {signed && (
          <Alert kind="ok" title={`Signed by ${form.signed_by} · ${fmtDateTime(form.signed_at)}`} icon="✍">
            “{form.signature_meaning}” This form is locked.
          </Alert>
        )}
        {!canWrite && !signed && <Alert kind="info" title="View only">Your role can view this form but not edit it.</Alert>}
        {form.status === 'complete' && !readOnly && (
          <Alert kind="warn" title="Form is complete">Any change from now on asks for a reason for change. Sign the form to lock it.</Alert>
        )}

        {form.self_entry && !signed && form.status !== 'complete' && !locked && (
          <SelfEntryPanel pid={id} code={code} info={form.self_entry} canStart={can('form_pro', 'write')} />
        )}
        {form.self_entry && <div className="field-note" style={{ margin: '-4px 0 10px' }}>Source: {form.self_entry.source}. Staff entry below is for paper-form transcription only.</div>}

        <FormRenderer def={def} data={data} onChange={onChange} readOnly={readOnly} computed={check?.computed || form.computed}
          check={check} overrides={overrides} onOverride={onOverride} highlight={needReasons || []} />

        {isScr && <div className="side-inline"><EligibilityPanel elig={elig} /></div>}

        {problem && (
          <Alert kind="fail" title={problem.message}>
            {missingLabels.length > 0 && <div>Still required: {missingLabels.join(', ')}.</div>}
            {unconfirmedLabels.length > 0 && <div>Out-of-range values need a confirmation reason: {unconfirmedLabels.join(', ')}.</div>}
          </Alert>
        )}

        <div className="nav-strip">
          <button className="btn-nav btn-back" onClick={() => (dirty && !confirm('Leave without saving your changes?')) ? null : nav(`/participants/${id}`)}>← Participant</button>
          <div className="nav-actions">
            {!readOnly && <button className="btn-secondary" disabled={busy || !dirty} onClick={() => save()}>Save draft</button>}
            {!readOnly && form.status !== 'complete' && (
              <button className="btn-nav btn-next" disabled={busy} onClick={complete}>Mark complete</button>
            )}
            {!locked && form.status === 'complete' && !dirty && can('sign_forms', 'write') && (
              <button className="btn-nav btn-confirm" onClick={() => { setSignErr(null); setSigning(true); }}>✍ Sign as PI</button>
            )}
            {!locked && form.status === 'complete' && dirty && !readOnly && (
              <button className="btn-nav btn-next" disabled={busy} onClick={() => save()}>Save changes</button>
            )}
            {signed && can('unlock_forms', 'write') && (
              <button className="btn-secondary" onClick={() => { setUnlockErr(null); setUnlocking(true); }}>Unlock…</button>
            )}
          </div>
        </div>
      </div>

      {isScr && (
        <aside className="form-side">
          <div className="side-title">Eligibility · live</div>
          <EligibilityPanel elig={elig} compact />
        </aside>
      )}

      {needReasons && (
        <ReasonModal fields={needReasons} def={def} reasonsList={defs.change_reasons}
          onCancel={() => setNeedReasons(null)} onSubmit={(r) => save(r)} />
      )}
      {signing && <SignModal meaning={meaning} busy={busy} error={signErr} onCancel={() => setSigning(false)} onSign={sign} />}
      {unlocking && <UnlockModal error={unlockErr} onCancel={() => setUnlocking(false)} onUnlock={unlock} />}
    </div>
  );
}
