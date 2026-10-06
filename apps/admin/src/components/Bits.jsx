// Small shared display pieces.

const STATUS_CLASS = {
  registered: 'sp-screening', screening: 'sp-screening', not_proceeding: 'sp-muted',
  screen_failure: 'sp-failure', eligible: 'sp-eligible', declined_consent: 'sp-muted',
  consented: 'sp-baseline', safety_deferred: 'sp-failure', ready_to_randomise: 'sp-eligible',
  randomised: 'sp-rand', withdrawn: 'sp-muted',
};
const STATUS_LABEL = {
  registered: 'Registered', screening: 'Screening', not_proceeding: 'Not proceeding',
  screen_failure: 'Screen failure', eligible: 'Eligible', declined_consent: 'Declined consent',
  consented: 'Consented · baseline', safety_deferred: 'Safety deferred', ready_to_randomise: 'Ready to randomise',
  randomised: 'Randomised', withdrawn: 'Withdrawn',
};

export function StatusPill({ status, label }) {
  return <span className={`status-pill ${STATUS_CLASS[status] || 'sp-muted'}`}>{label || STATUS_LABEL[status] || status}</span>;
}

const FORM_STATUS = {
  not_started: ['Not started', 'fs-none'],
  in_progress: ['In progress', 'fs-prog'],
  complete: ['Complete · awaiting signature', 'fs-done'],
  signed: ['Signed & locked', 'fs-signed'],
  planned: ['Awaiting input', 'fs-none'],
};
export function FormStatus({ status }) {
  const [l, c] = FORM_STATUS[status] || [status, 'fs-none'];
  return <span className={`form-status ${c}`}>{l}</span>;
}

export function ArmTag({ arm }) {
  if (!arm) return <span className="arm-tag none">Not randomised</span>;
  if (arm === 'blinded') return <span className="arm-tag none">Randomised · blinded</span>;
  return <span className={`arm-tag ${arm}`}>{arm === 'intervention' ? 'Intervention' : 'Control'}</span>;
}

export function Alert({ kind = 'info', title, children, icon }) {
  const ico = icon || { ok: '✓', warn: '⚠', fail: '✕', info: 'ℹ', sys: '•' }[kind];
  return (
    <div className={`alert alert-${kind}`}>
      <div className="alert-icon">{ico}</div>
      <div className="alert-body">
        {title && <div className="alert-title">{title}</div>}
        {children && <div className="alert-desc">{children}</div>}
      </div>
    </div>
  );
}

export function fmtDateTime(iso) {
  if (!iso) return '—';
  const d = new Date(iso);
  return d.toLocaleString('en-IN', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: false, timeZone: 'Asia/Kolkata' });
}
export function fmtDate(s) {
  if (!s) return '—';
  const d = new Date(s.length === 10 ? `${s}T00:00:00` : s);
  return d.toLocaleDateString('en-IN', { day: 'numeric', month: 'short', year: 'numeric' });
}

export function Section({ num, title, sub, badge, badgeKind = 'pi', children, right }) {
  return (
    <div className="form-section">
      <div className="fs-head">
        {num != null && <div className="fs-num">{num}</div>}
        <div>
          <div className="fs-title">{title}</div>
          {sub && <div className="fs-sub">{sub}</div>}
        </div>
        {right}
        {badge && <div className={`fs-badge badge-${badgeKind}`}>{badge}</div>}
      </div>
      <div className="fs-body">{children}</div>
    </div>
  );
}

export function Empty({ children }) {
  return <div className="empty">{children}</div>;
}
