import { useEffect, useState } from 'react';
import { api } from '../api';
import { useAuth } from '../auth';
import { Alert, fmtDateTime } from '../components/Bits';
import { useToast } from '../components/Toast';

export default function Randomisation() {
  const { can } = useAuth();
  const toast = useToast();
  const [s, setS] = useState(null);
  const [f, setF] = useState({ name: '', csv: '' });
  const [errs, setErrs] = useState([]);
  const load = () => api('/randomisation').then(setS);
  useEffect(() => { load(); }, []);

  const upload = async () => {
    setErrs([]);
    try {
      const r = await api('/randomisation/list', { method: 'POST', body: f });
      toast(`${r.rows} rows added`);
      setF({ name: '', csv: '' });
      setS(r);
    } catch (ex) {
      setErrs(ex.body.errors || [ex.message]);
    }
  };

  if (!s) return <div className="panel-page">Loading…</div>;
  return (
    <div className="panel-page">
      <div className="form-header">
        <div className="form-eyebrow">Randomisation</div>
        <div className="form-title">Allocation list</div>
        <div className="form-desc">The statistician's sequence, stratified by age (&lt;60 / ≥60). Participants are randomised from their record. Arms are never shown here; only how many rows remain.</div>
      </div>
      {s.list ? (
        <div className="form-section"><div className="fs-body">
          <div className="rand-done">
            <div><span>List</span>{s.list.name}</div>
            <div><span>Rows</span>{s.list.rows}</div>
            <div><span>Uploaded</span>{fmtDateTime(s.list.uploaded_at)}</div>
            <div><span>SHA-256</span><code className="mono">{s.list.sha256.slice(0, 16)}…</code></div>
          </div>
          <div className="strata">
            {Object.entries(s.strata).map(([k, v]) => (
              <div key={k} className={`stat-card ${v.remaining < 10 ? 'fail' : ''}`}>
                <div className="stat-label">Stratum {k === 'lt60' ? '< 60 years' : '≥ 60 years'}</div>
                <div className="stat-val">{v.remaining}</div>
                <div className="stat-sub">remaining · {v.used} used of {v.total}</div>
              </div>
            ))}
          </div>
        </div></div>
      ) : <Alert kind="warn" title="No list uploaded">Randomisation stays locked until the statistician's list is loaded.</Alert>}

      {can('randomisation_list', 'write') && (
        <div className="form-section">
          <div className="fs-head"><div className="fs-num">⇧</div><div><div className="fs-title">{s.list ? 'Extend the list' : 'Upload the list'}</div>
            <div className="fs-sub">CSV columns: stratum (lt60 / ge60), seq_no, block_no, arm (intervention / control). Every block must be balanced 1:1, and sequence numbers must run without gaps{s.list ? ', continuing after the current rows' : ''}.</div></div></div>
          <div className="fs-body">
            <label className="field-label" htmlFor="rl_name">Name / version</label>
            <input id="rl_name" className="field-input" value={f.name} onChange={(e) => setF({ ...f, name: e.target.value })} placeholder="e.g. SMART-HEART allocation v1 (statistician, 6 Oct 2026)" />
            <input type="file" accept=".csv,text/csv" className="mt" onChange={(e) => e.target.files[0]?.text().then((t) => setF({ ...f, csv: t }))} />
            {errs.length > 0 && <Alert kind="fail" title="List not loaded"><ul className="fail-list">{errs.map((e) => <li key={e}>{e}</li>)}</ul></Alert>}
            <button className="btn-primary mt" disabled={!f.name || !f.csv} onClick={upload}>Check and load</button>
          </div>
        </div>
      )}
    </div>
  );
}
