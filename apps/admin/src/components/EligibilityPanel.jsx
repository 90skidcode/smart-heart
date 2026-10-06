const ICON = {
  met: ['✓', 'es-ok', 'Met'], absent: ['✓', 'es-ok', 'Absent'],
  not_met: ['✕', 'es-fail', 'Not met'], present: ['✕', 'es-fail', 'Present'],
  pending: ['…', 'es-pend', 'Pending'],
};

function Rows({ items, head }) {
  return (
    <div className="elig-table">
      <div className="elig-head"><span>{head}</span><span>Status</span></div>
      {items.map((c) => {
        const [ico, cls, word] = ICON[c.status];
        return (
          <div className="elig-row" key={c.code}>
            <div className="elig-criterion">
              {c.label}
              <span className="elig-code">{c.code}</span>
            </div>
            <div className={`elig-status ${cls}`} title={c.detail || ''}>
              {ico} {c.detail ? c.detail : word}
            </div>
          </div>
        );
      })}
    </div>
  );
}

/** Live eligibility summary for SCR-01, computed by the server's EligibilityEngine. */
export default function EligibilityPanel({ elig, compact }) {
  if (!elig) return null;
  const inc = elig.criteria.filter((c) => c.kind === 'inclusion');
  const exc = elig.criteria.filter((c) => c.kind === 'exclusion');
  const banner = {
    ELIGIBLE: ['alert-ok', '🟢', 'ELIGIBLE', 'All inclusion criteria met and no exclusion criteria found. Mark complete and sign to unlock consent.'],
    NOT_ELIGIBLE: ['alert-fail', '🔴', 'NOT ELIGIBLE — screen failure', 'An exclusion was found. You can stop here: mark the form complete and sign it to record the screen failure. Remaining fields are not required.'],
    INCOMPLETE: ['alert-warn', '🟡', 'INCOMPLETE', 'Some criteria are still pending or answered "Unknown". The form cannot be signed until they are resolved.'],
  }[elig.status];
  const fails = elig.criteria.filter((c) => c.status === 'not_met' || c.status === 'present');

  return (
    <div className={`elig-panel ${compact ? 'compact' : ''}`}>
      <div className={`alert ${banner[0]}`}>
        <div className="alert-icon">{banner[1]}</div>
        <div className="alert-body">
          <div className="alert-title" style={{ fontSize: 14 }}>{banner[2]}</div>
          <div className="alert-desc">{banner[3]}</div>
          {fails.length > 0 && (
            <ul className="fail-list">
              {fails.map((f) => <li key={f.code}>{f.label}{f.detail ? ` — ${f.detail}` : ''}</li>)}
            </ul>
          )}
        </div>
      </div>
      <Rows items={inc} head="Inclusion criterion" />
      <Rows items={exc} head="Exclusion criterion" />
    </div>
  );
}
