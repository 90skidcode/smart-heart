import { useEffect, useState } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { api, qs } from '../api';
import { useAuth } from '../auth';
import { ArmTag, Empty, StatusPill, fmtDate } from '../components/Bits';

const ARMS = [['all', 'All'], ['intervention', 'Intervention'], ['control', 'Control'], ['unassigned', 'Not randomised']];
const DOT_FORMS = [['REG-01', 'REG'], ['SCR-01', 'SCR'], ['CON-01', 'CON'], ['BL', 'BL'], ['PRO', 'PRO'], ['SAF-01', 'SAF'], ['RAND-01', 'RND']];

/** Collapse the eight baseline modules / five PROs into one dot each. */
function groupStatus(forms, prefix) {
  const st = Object.entries(forms).filter(([k]) => k.startsWith(prefix)).map(([, v]) => v);
  if (!st.length) return null;
  if (st.every((v) => v === 'signed')) return 'signed';
  if (st.every((v) => v === 'signed' || v === 'complete')) return 'complete';
  return 'in_progress';
}

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
  // CONSORT-style enrolment flow (screening → consent → baseline → randomisation).
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
      <div className="flow-arrow">→</div>
      <div className="flow-col">
        <Box n={c.randomised} label="Randomised" kind="ok" />
        <Box n={c.in_baseline} label="In baseline" kind="muted" />
        <Box n={c.safety_deferred} label="Safety deferred" kind="muted" />
        <Box n={c.ready_to_randomise} label="Ready to randomise" kind="muted" />
        <Box n={c.withdrawn} label="Withdrawn" kind="muted" />
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
        {stats?.unblinded && <div className="seg" role="tablist" aria-label="Arm filter">
          {ARMS.map(([k, l]) => (
            <button key={k} role="tab" aria-selected={arm === k} className={arm === k ? 'on' : ''} onClick={() => setArm(k)}>{l}</button>
          ))}
        </div>}
      </div>
      {err && <div className="field-error">{err}</div>}

      {stats?.alerts?.critical_open > 0 && can('alerts') && (
        <Link to="/alerts" className="alert alert-fail alert-link">
          <div className="alert-icon">!</div>
          <div className="alert-body">
            <div className="alert-title">{stats.alerts.critical_open} critical safety alert{stats.alerts.critical_open > 1 ? 's' : ''} not yet acknowledged</div>
            <div className="alert-desc">PHQ-9 item 9 positive. Contact the participant and acknowledge the alert. Open safety alerts →</div>
          </div>
        </Link>
      )}

      {c && (
        <>
          <div className="stat-grid">
            <Stat label="In screening" value={c.in_screening} sub="Registered, screening not yet signed" kind="pend" />
            <Stat label="Eligible → consent" value={c.eligible_awaiting_consent} sub="Screening signed, awaiting consent" kind="ok" />
            <Stat label="Screen failures" value={c.screen_failure} sub="Documented with reason" kind="fail" />
            <Stat label="In baseline" value={c.in_baseline} sub="Consented, BL-01 / PRO-01 / SAF-01 in progress" kind="ok" />
            <Stat label="Ready to randomise" value={c.ready_to_randomise} sub={c.safety_deferred ? `${c.safety_deferred} safety deferred` : 'All gates met'} kind="pend" />
            <Stat label="Randomised" value={c.randomised} sub={stats.unblinded ? `Intervention ${c.intervention} · Control ${c.control}` : 'Allocation blinded for your role'} kind="pend" />
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
              {DOT_FORMS.map(([f, label]) => {
                const st = f === 'BL' ? groupStatus(p.forms, 'BL-') : f === 'PRO' ? groupStatus(p.forms, 'PRO-') : p.forms[f];
                return <span key={f} className={`fdot ${st || 'none'}`} title={`${f}: ${(st || 'not started').replace('_', ' ')}`}>{label}</span>;
              })}
            </div>
          </div>
        ))}
      </div>
    </div>
  );
}
