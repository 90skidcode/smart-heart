import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { api } from '../api';
import { useAuth } from '../auth';

const fmtInput = (d) => {
  const v = d.input;
  if (v == null) return '—';
  if (d.domain === 'bp') return `${v.sbp}/${v.dbp} mmHg`;
  if (d.domain === 'hba1c') return `${v.value}% (${v.diabetic ? 'diabetic' : 'non-diabetic'})`;
  if (d.domain === 'smoking') return v.status + (v.months_since_quit != null ? `, quit ${v.months_since_quit} mo ago` : '');
  if (d.domain === 'mental_health') return `PHQ-9 ${v.phq9} · GAD-7 ${v.gad7}`;
  return String(v);
};

/** Baseline CCSPS: each domain scores only under a PI-approved threshold; otherwise pending, never 0. */
export default function CcspsPanel({ pid }) {
  const { can } = useAuth();
  const [c, setC] = useState(null);
  useEffect(() => { api(`/participants/${pid}/ccsps`).then(setC).catch(() => {}); }, [pid]);
  if (!c) return null;
  const t = c.total;
  return (
    <div className="form-section">
      <div className="fs-head"><div className="fs-num">∑</div><div><div className="fs-title">CCSPS composite · baseline</div>
        <div className="fs-sub">Calculated by the server from the baseline forms. A domain without an approved threshold stays pending.</div></div>
        {can('scoring') && <Link className="btn-link" style={{ marginLeft: 'auto' }} to="/scoring">Thresholds →</Link>}</div>
      <div className="fs-body">
        <div className="ccsps-total">
          <div><b>{t.raw}</b><span>raw from {t.domains_scored} of 10 domains</span></div>
          <div><b>{t.normalised_complete_case ?? '—'}</b><span>0–100 complete case</span></div>
          <div><b>{t.normalised_proportional ?? '—'}</b><span>0–100 proportional (≥ 8 domains)</span></div>
          <div><b>{c.normalisation}</b><span>SAP normalisation</span></div>
        </div>
        <table className="ccsps-table">
          <thead><tr><th>Domain</th><th>Input</th><th>Score</th><th>Threshold</th></tr></thead>
          <tbody>
            {c.domains.map((d) => (
              <tr key={d.domain}>
                <td>{d.label}<small>{d.source}</small></td>
                <td>{fmtInput(d)}</td>
                <td>{d.score == null ? <span className="pending">pending</span> : <span className={`pip pip-${d.score}`}>{d.score}/2</span>}</td>
                <td>{d.config_version ? `v${d.config_version}` : <small>{d.pending_reason}</small>}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
