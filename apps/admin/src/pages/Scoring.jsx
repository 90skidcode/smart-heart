import { useEffect, useState } from 'react';
import { api } from '../api';
import { useAuth } from '../auth';
import { Alert, fmtDateTime } from '../components/Bits';
import Modal from '../components/Modal';
import { useToast } from '../components/Toast';

const KEY_LABEL = {
  ideal_below: 'Ideal (2) when below', poor_at_or_above: 'Poor (0) at or above', ideal_at_or_above: 'Ideal (2) at or above', poor_below: 'Poor (0) below',
  ideal_sbp_below: 'Ideal: SBP below', ideal_dbp_below: 'Ideal: DBP below', poor_sbp_at_or_above: 'Poor: SBP at or above', poor_dbp_at_or_above: 'Poor: DBP at or above',
  'diabetic.ideal_below': 'Diabetic — ideal below (%)', 'diabetic.poor_at_or_above': 'Diabetic — poor at or above (%)',
  'non_diabetic.ideal_below': 'Non-diabetic — ideal below (%)', 'non_diabetic.poor_at_or_above': 'Non-diabetic — poor at or above (%)',
  never: 'Score for never used tobacco', former: 'Score for former (quit long ago)', former_recent: 'Score for recent quitter', recent_quit_months: 'Recent = quit within (months)', current: 'Score for current use',
  mild_from: 'Mild from total', moderate_from: 'Moderate or worse from total',
};
const ruleText = (r) => Object.entries(r).map(([k, v]) => (typeof v === 'object' ? `${k}: ${ruleText(v)}` : `${k} ${v}`)).join(', ');
const setPath = (o, path, v) => { const [a, b] = path.split('.'); return b ? { ...o, [a]: { ...(o[a] || {}), [b]: v } } : { ...o, [a]: v }; };
const getPath = (o, path) => path.split('.').reduce((x, k) => x?.[k], o);

export default function Scoring() {
  const { can } = useAuth();
  const toast = useToast();
  const [d, setD] = useState(null);
  const [edit, setEdit] = useState(null);
  const [approve, setApprove] = useState(null);
  const [pw, setPw] = useState('');
  const [err, setErr] = useState(null);
  const write = can('scoring', 'write');
  const load = () => api('/scoring-configs').then(setD);
  useEffect(() => { load(); }, []);

  const saveDraft = async () => {
    setErr(null);
    try {
      const rule = {};
      let r = {};
      for (const k of edit.dom.required_keys) { const raw = getPath(edit.rule, k); r = setPath(r, k, raw === '' || raw == null ? null : Number(raw)); }
      Object.assign(rule, r);
      await api('/scoring-configs', { method: 'POST', body: { domain: edit.dom.domain, rule, note: edit.note } });
      setEdit(null);
      toast('Draft saved — approve it to start scoring');
      load();
    } catch (ex) {
      setErr([ex.message, ...(ex.body.errors?.rule || [])].join(' '));
    }
  };
  const doApprove = async () => {
    setErr(null);
    try {
      await api(`/scoring-configs/${approve.id}/approve`, { method: 'POST', body: { password: pw } });
      setApprove(null);
      toast('Approved');
      load();
    } catch (ex) {
      setErr(ex.message);
    }
  };

  if (!d) return <div className="panel-page">Loading…</div>;
  return (
    <div className="panel-page wide">
      <div className="form-header">
        <div className="form-eyebrow">Administration</div>
        <div className="form-title">CCSPS scoring thresholds</div>
        <div className="form-desc">Each domain scores 0, 1 or 2 only under an approved threshold version. Until then it is pending, never 0. Changing a threshold creates a new version; earlier results are not re-scored without an audited re-run. Approval needs the PI's password.</div>
      </div>
      <Alert kind="info" title={`Normalisation (SAP): ${d.normalisation}`}>Set CCSPS_NORMALISATION in the server settings once the statistician decides: complete_case or proportional.</Alert>
      <div className="score-grid">
        {d.domains.map((dom) => (
          <div key={dom.domain} className="score-card">
            <div className="sc-head"><b>{dom.label}</b><span className="mono">{dom.domain}</span></div>
            <div className="field-note">{dom.source}</div>
            {dom.approved ? (
              <div className="sc-approved">✓ v{dom.approved.version}: {ruleText(dom.approved.rule)}<small>Approved by {dom.approved.approved_by} · {fmtDateTime(dom.approved.approved_at)}</small></div>
            ) : <div className="sc-pending">No approved threshold — domain is pending</div>}
            {dom.draft && (
              <div className="sc-draft">Draft v{dom.draft.version}: {ruleText(dom.draft.rule)}<small>{dom.draft.note}</small>
                {write && <button className="btn-confirm small" onClick={() => { setApprove(dom.draft); setPw(''); setErr(null); }}>Approve…</button>}
              </div>
            )}
            {write && <button className="btn-link" onClick={() => { setEdit({ dom, rule: dom.draft?.rule || dom.approved?.rule || {}, note: '' }); setErr(null); }}>New draft…</button>}
          </div>
        ))}
      </div>
      {edit && (
        <Modal title={`${edit.dom.label} thresholds`} eyebrow="New draft version" onClose={() => setEdit(null)}
          footer={<><button className="btn-secondary" onClick={() => setEdit(null)}>Cancel</button><button className="btn-primary" disabled={edit.note.trim().length < 5} onClick={saveDraft}>Save draft</button></>}>
          {edit.dom.required_keys.map((k) => (
            <div key={k} className="mt">
              <label className="field-label" htmlFor={`k_${k}`}>{KEY_LABEL[k] || k}</label>
              <input id={`k_${k}`} className="field-input" type="number" step="any" value={getPath(edit.rule, k) ?? ''} onChange={(e) => setEdit({ ...edit, rule: setPath(edit.rule, k, e.target.value) })} />
            </div>
          ))}
          <label className="field-label mt" htmlFor="sc_note">Source / reason</label>
          <input id="sc_note" className="field-input" value={edit.note} onChange={(e) => setEdit({ ...edit, note: e.target.value })} placeholder="e.g. CCSPS_Specification v2.0 §4, approved by PI and statistician 8 Oct" />
          {err && <div className="field-error">{err}</div>}
        </Modal>
      )}
      {approve && (
        <Modal title="Approve threshold" eyebrow={`${approve.version ? `v${approve.version}` : ''}`} onClose={() => setApprove(null)}
          footer={<><button className="btn-secondary" onClick={() => setApprove(null)}>Cancel</button><button className="btn-confirm" disabled={!pw} onClick={doApprove}>Approve</button></>}>
          <div className="sign-meaning">{ruleText(approve.rule)}</div>
          <label className="field-label mt" htmlFor="ap_pw">Your password</label>
          <input id="ap_pw" className="field-input" type="password" value={pw} onChange={(e) => setPw(e.target.value)} />
          {err && <div className="field-error">{err}</div>}
        </Modal>
      )}
    </div>
  );
}
