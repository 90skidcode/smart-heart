import { useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api } from '../api';
import { Alert, Section } from '../components/Bits';
import FormRenderer from '../components/FormRenderer';
import { useToast } from '../components/Toast';
import { useDefinitions } from '../hooks';

const today = () => new Date().toISOString().slice(0, 10);

export default function Register() {
  const defs = useDefinitions();
  const nav = useNavigate();
  const toast = useToast();
  const [id, setId] = useState({ full_name: '', phone: '', hospital_number: '', address: '' });
  const [data, setData] = useState({ REG_DATE: today() });
  const [errors, setErrors] = useState({});
  const [msg, setMsg] = useState(null);
  const [dup, setDup] = useState(null);
  const [busy, setBusy] = useState(false);

  if (!defs) return <div className="panel-page">Loading…</div>;
  const def = defs.forms['REG-01'];

  const submit = async (confirmDuplicate = false) => {
    setBusy(true);
    setMsg(null);
    setErrors({});
    try {
      const p = await api('/participants', { method: 'POST', body: { ...id, data, confirm_duplicate: confirmDuplicate } });
      toast(`Registered ${p.study_id}`);
      nav(`/participants/${p.id}`);
    } catch (ex) {
      if (ex.status === 409) setDup(ex.body.duplicate);
      else {
        setMsg(ex.message);
        const e = ex.body.errors || {};
        setErrors(Object.fromEntries(Object.entries(e).map(([k, v]) => [k, Array.isArray(v) ? v[0] : v])));
      }
    } finally {
      setBusy(false);
    }
  };

  const setI = (k) => (e) => setId({ ...id, [k]: e.target.value });

  return (
    <div className="panel-page">
      <div className="form-header">
        <div className="form-eyebrow">{def.eyebrow}</div>
        <div className="form-title">{def.title}</div>
        <div className="form-desc">{def.description}</div>
      </div>

      <Section num="1" title="Study Identifiers" badge="System-generated" badgeKind="sys">
        <div className="field-grid">
          <div className="field"><div className="field-label">Study ID</div><input className="field-input computed" readOnly value="Assigned on save (SMART-HEART-####)" /></div>
          <div className="field"><div className="field-label">Screening ID</div><input className="field-input computed" readOnly value="Assigned on save (SCR-####)" /></div>
        </div>
      </Section>

      <Section num="2" title="Participant Identity" sub="Identifiable — never included in de-identified exports" badge="PI Enters">
        <div className="field-grid">
          <div className="field field-wide">
            <label className="field-label" htmlFor="r_name">Full name <span className="field-req">*</span></label>
            <input id="r_name" className="field-input" value={id.full_name} onChange={setI('full_name')} />
            {errors.full_name && <div className="field-error">{errors.full_name}</div>}
          </div>
          <div className="field">
            <label className="field-label" htmlFor="r_phone">Mobile number <span className="field-req">*</span></label>
            <input id="r_phone" className="field-input" inputMode="numeric" maxLength={10} placeholder="10 digits" value={id.phone} onChange={setI('phone')} />
            <div className="field-note">Used later for OTP and follow-up links</div>
            {errors.phone && <div className="field-error">{errors.phone}</div>}
          </div>
          <div className="field">
            <label className="field-label" htmlFor="r_mrn">Hospital number (MRN)</label>
            <input id="r_mrn" className="field-input" value={id.hospital_number} onChange={setI('hospital_number')} />
          </div>
          <div className="field field-wide">
            <label className="field-label" htmlFor="r_addr">Address</label>
            <textarea id="r_addr" className="field-input" rows={2} value={id.address} onChange={setI('address')} />
          </div>
        </div>
      </Section>

      <FormRenderer def={def} data={data} startNum={3}
        onChange={(k, v) => setData((d) => ({ ...d, [k]: v }))} check={{ errors }} />

      {data.REG_POTENTIALLY_ELIGIBLE === 'Yes' && <Alert kind="ok" title="Ready to begin screening">After saving, open SCR-01 Screening &amp; Eligibility.</Alert>}
      {data.REG_POTENTIALLY_ELIGIBLE === 'No' && <Alert kind="sys" title="Will be recorded as not proceeding">The person is logged for the screening log but cannot move to SCR-01.</Alert>}
      {msg && <div className="field-error">{msg}</div>}
      {dup && (
        <Alert kind="warn" title={`This hospital number is already registered as ${dup}`}>
          Check you are not registering the same person twice.{' '}
          <button className="btn-link" onClick={() => { setDup(null); submit(true); }}>Register anyway</button>
        </Alert>
      )}

      <div className="nav-strip">
        <button className="btn-nav btn-back" onClick={() => nav('/')}>← Dashboard</button>
        <button className="btn-nav btn-next" disabled={busy} onClick={() => submit(false)}>{busy ? 'Saving…' : 'Register participant →'}</button>
      </div>
    </div>
  );
}
