import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { api, qs } from '../api';
import { useAuth } from '../auth';
import { ArmTag, Empty, StatusPill, fmtDate } from '../components/Bits';

const ARMS = [['all', 'All'], ['intervention', 'Intervention'], ['control', 'Control'], ['unassigned', 'Not randomised']];

function Stat({ label, value, sub, kind }) {
  return (
    <div className={`stat-card ${kind || ''}`}>
      <div className="stat-label">{label}</div>
      <div className="stat-val">{String(value ?? 0).padStart(2, '0')}</div>
      <div className="stat-sub">{sub}</div>
    </div>
  );
}

function Flow({ c }) {
  // CONSORT-style enrolment flow (screening → consent). Randomisation added in Phase 2.
  const Box = ({ n, label, kind }) => (
    <div className={`flow-box ${kind || ''}`}><b>{n}</b><span>{label}</span></div>
  );
  return (
    <div className="flow">
      <Box n={c.registered_total} label="Registered" />
      <div className="flow-arrow">→</div>
      <div className="flow-col">
        <Box n={c.screened} label="Screening signed" />
        <div className="flow-side">
          <Box n={c.in_screening} label="Still in screening" kind="muted" />
          <Box n={c.not_proceeding} label="Not proceeding" kind="muted" />
        </div>
      </div>
      <div className="flow-arrow">→</div>
      <div className="flow-col">
        <Box n={c.screened - c.screen_failure} label="Eligible" kind="ok" />
        <Box n={c.screen_failure} label="Screen failures" kind="fail" />
      </div>
      <div className="flow-arrow">→</div>
      <div className="flow-col">
        <Box n={c.consented + c.randomised} label="Consented" kind="ok" />
        <Box n={c.declined_consent} label="Declined consent" kind="muted" />
        <Box n={c.eligible_awaiting_consent} label="Awaiting consent" kind="muted" />
      </div>
    </div>
  );
}

export default function Dashboard() {
  const { can } = useAuth();
  const nav = useNavigate();
  const [arm, setArm] = useState('all');
  const [status, setStatus] = useState('');
  const [q, setQ] = useState('');
  const [stats, setStats] = useState(null);
  const [rows, setRows] = useState(null);
  const [err, setErr] = useState(null);

  useEffect(() => {
    api(`/dashboard${qs({ arm })}`).then(setStats).catch((e) => setErr(e.message));
  }, [arm]);

  useEffect(() => {
    const t = setTimeout(() => {
      api(`/participants${qs({ arm, status, q })}`).then(setRows).catch((e) => setErr(e.message));
    }, 250);
    return () => clearTimeout(t);
  }, [arm, status, q]);

  const c = stats?.counts;
  return (
    <div className="panel-page wide">
      <div className="dash-header">
        <div>
          <div className="dash-title">SMART-HEART Study Dashboard</div>
          <div className="dash-sub">Participant management · target {stats?.target ?? 240} randomised (120 per arm)</div>
        </div>
        <div className="seg" role="tablist" aria-label="Arm filter">
          {ARMS.map(([k, l]) => (
            <button key={k} role="tab" aria-selected={arm === k} className={arm === k ? 'on' : ''} onClick={() => setArm(k)}>{l}</button>
          ))}
        </div>
      </div>
      {err && <div className="field-error">{err}</div>}

      {c && (
        <>
          <div className="stat-grid">
            <Stat label="In screening" value={c.in_screening} sub="Registered, screening not yet signed" kind="pend" />
            <Stat label="Eligible → consent" value={c.eligible_awaiting_consent} sub="Screening signed, awaiting consent" kind="ok" />
            <Stat label="Screen failures" value={c.screen_failure} sub="Documented with reason" kind="fail" />
            <Stat label="Consented" value={c.consented} sub="Ready for baseline (Phase 2)" kind="ok" />
            <Stat label="Randomised" value={c.randomised} sub={`Intervention ${c.intervention} · Control ${c.control}`} kind="pend" />
            <Stat label="Target enrolment" value={stats.target} sub="120 per arm" />
          </div>

          <div className="two-col">
            <div className="form-section">
              <div className="fs-head"><div className="fs-num">⇢</div><div><div className="fs-title">Enrolment flow</div><div className="fs-sub">Feeds the CONSORT diagram</div></div></div>
              <div className="fs-body"><Flow c={c} /></div>
            </div>
            <div className="form-section">
              <div className="fs-head"><div className="fs-num">✕</div><div><div className="fs-title">Screen failure reasons</div><div className="fs-sub">One participant can have several</div></div></div>
              <div className="fs-body">
                {stats.screen_failure_reasons.length === 0 ? <Empty>No screen failures yet.</Empty> : stats.screen_failure_reasons.map((r) => (
                  <div className="reason-row" key={r.reason}>
                    <span>{r.reason}</span>
                    <span className="reason-bar"><span style={{ width: `${(r.count / Math.max(...stats.screen_failure_reasons.map((x) => x.count))) * 100}%` }} /></span>
                    <b>{r.count}</b>
                  </div>
                ))}
              </div>
            </div>
          </div>
        </>
      )}

      <div className="action-strip">
        {can('participants', 'write') && <Link className="btn-primary" to="/participants/new">＋ Register new participant</Link>}
        <input className="field-input search" placeholder="Search study ID, name, hospital no., phone" value={q} onChange={(e) => setQ(e.target.value)} />
        <select className="field-input" style={{ maxWidth: 200 }} value={status} onChange={(e) => setStatus(e.target.value)} aria-label="Status filter">
          <option value="">All statuses</option>
          {stats && Object.entries(stats.statuses).map(([k, l]) => <option key={k} value={k}>{l}</option>)}
        </select>
      </div>

      <div className="ptable">
        <div className="ptable-head"><span>Study ID</span><span>Participant</span><span>Status</span><span>Arm</span><span>Registered</span><span>Forms</span></div>
        {rows === null ? <Empty>Loading…</Empty> : rows.length === 0 ? <Empty>No participants match.</Empty> : rows.map((p) => (
          <div className="ptable-row" key={p.id} onClick={() => nav(`/participants/${p.id}`)} role="link" tabIndex={0}
            onKeyDown={(e) => e.key === 'Enter' && nav(`/participants/${p.id}`)}>
            <div className="ptable-id">{p.study_id}<small>{p.screening_id}</small></div>
            <div className="ptable-name">{p.full_name}<small>{p.hospital_number || ''}</small></div>
            <div><StatusPill status={p.status} /></div>
            <div><ArmTag arm={p.arm} /></div>
            <div className="ptable-date">{fmtDate(p.registered_on)}</div>
            <div className="form-dots">
              {['REG-01', 'SCR-01', 'CON-01'].map((f) => (
                <span key={f} className={`fdot ${p.forms[f] || 'none'}`} title={`${f}: ${p.forms[f] || 'not started'}`}>{f.slice(0, 3)}</span>
              ))}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
