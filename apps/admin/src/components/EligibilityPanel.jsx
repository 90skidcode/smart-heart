const ICON = {
  pass: ['✓', 'es-ok', 'Pass'],
  'n/a': ['–', 'es-na', 'Not applicable'],
  fail: ['✕', 'es-fail', 'Excludes'],
  pending: ['…', 'es-pend', 'Pending'],
  not_evaluated: ['·', 'es-na', 'Not evaluated'],
};
const SCREENS = { 1: 'Age', 2: 'Diagnosis', 3: 'PCI', 4: 'CABG', 5: 'High-risk cardiac', 6: 'Diabetes / renal / BP', 7: 'Sensory & cognitive', 8: 'Digital access' };

/** Live SCR-01 eligibility, computed on the server by ELIG-1.0 (App\Calc\Calc::eligibility). */
export default function EligibilityPanel({ elig, compact }) {
  if (!elig) return null;
  const banner = {
    ELIGIBLE: ['alert-ok', '🟢', 'ELIGIBLE', 'All criteria pass. Mark complete and sign to unlock consent.'],
    NOT_ELIGIBLE: ['alert-fail', '🔴', `NOT ELIGIBLE — stops at screen ${elig.stop_at_screen}`, 'An exclusion was found, so later criteria are not evaluated. You can stop here: mark the form complete and sign it to record the screen failure.'],
    INCOMPLETE: ['alert-warn', '🟡', 'PENDING', 'Some criteria are missing, answered "Unknown", or need PI review (e.g. high BP while untreated). The form cannot be signed until they are resolved.'],
  }[elig.status];
  const fails = elig.criteria.filter((c) => c.status === 'fail');
  const pend = elig.criteria.filter((c) => c.status === 'pending');
  const screens = [...new Set(elig.criteria.map((c) => c.screen))];

  return (
    <div className={`elig-panel ${compact ? 'compact' : ''}`}>
      <div className={`alert ${banner[0]}`}>
        <div className="alert-icon">{banner[1]}</div>
        <div className="alert-body">
          <div className="alert-title" style={{ fontSize: 14 }}>{banner[2]}</div>
          <div className="alert-desc">{banner[3]}</div>
          {(fails.length > 0 || pend.length > 0) && (
            <ul className="fail-list">
              {[...fails, ...pend].map((f) => <li key={f.code}>{f.label}{f.detail ? ` — ${f.detail}` : ''}</li>)}
            </ul>
          )}
        </div>
      </div>
      <div className="elig-table">
        <div className="elig-head"><span>Criterion ({elig.engine})</span><span>Status</span></div>
        {screens.map((s) => (
          <div key={s}>
            <div className="elig-screen">Screen {s} · {SCREENS[s]}</div>
            {elig.criteria.filter((c) => c.screen === s).map((c) => {
              const [ico, cls, word] = ICON[c.status] || ['?', '', c.status];
              return (
                <div className="elig-row" key={c.code}>
                  <div className="elig-criterion">{c.label}<span className="elig-code">{c.code}</span></div>
                  <div className={`elig-status ${cls}`} title={c.detail || ''}>{ico} {c.status === 'pass' || c.status === 'fail' ? (c.detail || word) : word}</div>
                </div>
              );
            })}
          </div>
        ))}
      </div>
    </div>
  );
}
