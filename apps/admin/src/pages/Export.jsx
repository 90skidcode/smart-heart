import { useState } from 'react';
import { download, qs } from '../api';
import { useAuth } from '../auth';
import { Alert, Section } from '../components/Bits';
import { useToast } from '../components/Toast';

export default function Export() {
  const { can } = useAuth();
  const toast = useToast();
  const canIdent = can('export_identified');
  const canDe = can('export_deidentified');
  const [type, setType] = useState(canDe ? 'deidentified' : 'identified');
  const [armCoding, setArmCoding] = useState('ab');
  const [arm, setArm] = useState('all');
  const [err, setErr] = useState(null);

  if (!canIdent && !canDe) return <div className="panel-page"><Alert kind="info" title="No export access for your role." /></div>;

  const go = async (what) => {
    setErr(null);
    try {
      const name = await download(`/export/${what}${qs({ type, arm_coding: armCoding, arm })}`);
      toast(`Downloaded ${name}`);
    } catch (ex) {
      setErr(ex.message);
    }
  };

  const Opt = ({ v, cur, set, title, desc, disabled }) => (
    <button type="button" disabled={disabled} className={`radio-row ${cur === v ? 'selected' : ''}`} onClick={() => set(v)}>
      <span className="radio-dot" />
      <span><b>{title}</b><br /><small>{desc}</small></span>
    </button>
  );

  return (
    <div className="panel-page">
      <div className="form-header">
        <div className="form-eyebrow">Data</div>
        <div className="form-title">Export for analysis</div>
        <div className="form-desc">One row per participant, one column per eCRF field, using the same field names as the eCRF. Download the data dictionary with it. Every download is recorded in the audit trail.</div>
      </div>

      <Section num="1" title="Type of export">
        <div className="radio-group">
          <Opt v="deidentified" cur={type} set={setType} disabled={!canDe} title="De-identified (for the statistician)"
            desc="Study ID only. Removes name, mobile, hospital number, address, date of birth and consent-taker / witness names." />
          <Opt v="identified" cur={type} set={setType} disabled={!canIdent} title="Identified (PI only)"
            desc="Includes name, mobile, hospital number and address. Keep it on hospital systems only." />
        </div>
      </Section>

      <Section num="2" title="Arm">
        <div className="field-grid">
          <div className="field">
            <label className="field-label">Arm coding</label>
            <div className="radio-group">
              <Opt v="ab" cur={armCoding} set={setArmCoding} title="Blinded A / B" desc="Keeps the analysis blinded" />
              <Opt v="label" cur={armCoding} set={setArmCoding} title="Intervention / Control" desc="Real arm names" />
            </div>
          </div>
          <div className="field">
            <label className="field-label">Participants</label>
            <select className="field-input" value={arm} onChange={(e) => setArm(e.target.value)}>
              <option value="all">All participants (including screen failures)</option>
              <option value="intervention">Intervention arm only</option>
              <option value="control">Control arm only</option>
            </select>
          </div>
        </div>
      </Section>

      {type === 'identified' && <Alert kind="warn" title="Identified data">This file contains personal details. Do not email it or copy it to personal devices.</Alert>}
      {err && <div className="field-error">{err}</div>}
      <div className="nav-strip">
        <button className="btn-secondary" onClick={() => go('dictionary')}>⇩ Data dictionary (CSV)</button>
        <button className="btn-nav btn-next" onClick={() => go('data')}>⇩ Download data (CSV)</button>
      </div>
    </div>
  );
}
