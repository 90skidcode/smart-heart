// Renders an eCRF form from its server-side definition (sections + fields).
// Field codes and validation ranges come from the API, so the screen always
// matches what the server enforces.

export function isVisible(field, data) {
  if (!field.show_if) return true;
  return data[field.show_if.field] === field.show_if.equals;
}

function Field({ f, value, onChange, readOnly, computed, error, warning, override, onOverride, needReason }) {
  const id = `f_${f.code}`;
  const set = (v) => onChange(f.code, v);

  let input;
  if (f.type === 'computed') {
    const v = computed?.[f.code];
    input = <input id={id} className="field-input computed" readOnly value={v == null ? '—' : `${v}${f.unit ? ` ${f.unit}` : ''}`} />;
  } else if (f.type === 'radio') {
    input = (
      <div className="radio-group" role="radiogroup" aria-labelledby={`${id}_l`}>
        {f.options.map((o) => (
          <button
            type="button"
            key={o}
            role="radio"
            aria-checked={value === o}
            disabled={readOnly}
            className={`radio-row ${value === o ? 'selected' : ''} ${/Exclusion|prevents/.test(o) ? 'excl' : ''}`}
            onClick={() => set(value === o ? null : o)}
          >
            <span className="radio-dot" />
            {o}
          </button>
        ))}
      </div>
    );
  } else if (f.type === 'select') {
    input = (
      <select id={id} className="field-input" disabled={readOnly} value={value ?? ''} onChange={(e) => set(e.target.value || null)}>
        <option value="">— Select —</option>
        {f.options.map((o) => <option key={o}>{o}</option>)}
      </select>
    );
  } else {
    const type = { date: 'date', time: 'time', number: 'number' }[f.type] || 'text';
    input = (
      <div className="input-unit">
        <input
          id={id}
          type={type}
          className={`field-input ${error ? 'has-error' : warning ? 'has-warn' : ''}`}
          readOnly={readOnly}
          value={value ?? ''}
          step={f.type === 'number' ? (f.integer ? 1 : 'any') : undefined}
          min={f.type === 'number' ? f.min : undefined}
          max={f.type === 'date' && f.not_future ? new Date().toISOString().slice(0, 10) : (f.type === 'number' ? f.max : undefined)}
          onChange={(e) => {
            const raw = e.target.value;
            set(raw === '' ? null : f.type === 'number' ? (isNaN(Number(raw)) ? raw : Number(raw)) : raw);
          }}
        />
        {f.unit && f.type !== 'computed' && <span className="unit-tag">{f.unit}</span>}
      </div>
    );
  }

  const wide = f.type === 'radio' && f.options.some((o) => o.length > 18);
  return (
    <div className={`field ${wide || f.type === 'text' ? 'field-wide' : ''} ${needReason ? 'need-reason' : ''}`}>
      <label className="field-label" id={`${id}_l`} htmlFor={id}>
        {f.label}
        {f.required && f.type !== 'computed' && <span className="field-req">*</span>}
        <span className="field-code">{f.code}</span>
      </label>
      {input}
      {(f.min != null || f.max != null) && f.type === 'number' && !error && !warning && (
        <div className="field-note">Allowed {f.min ?? '–'} to {f.max ?? '–'}{f.unit ? ` ${f.unit}` : ''}</div>
      )}
      {error && <div className="field-error">{error}</div>}
      {warning && (
        <div className="field-warn">
          <div>⚠ {warning}</div>
          {!readOnly ? (
            <input
              className="field-input override"
              placeholder="Reason this value is correct (e.g. verified on repeat reading)"
              value={override || ''}
              onChange={(e) => onOverride(f.code, e.target.value)}
            />
          ) : (
            override && <div className="override-ro">Confirmed: {override}</div>
          )}
        </div>
      )}
    </div>
  );
}

export default function FormRenderer({ def, data, onChange, readOnly, computed, check, overrides, onOverride, highlight = [], startNum = 1 }) {
  return def.sections.map((s, i) => {
    const fields = s.fields.filter((f) => isVisible(f, data));
    return (
      <div className="form-section" key={s.title}>
        <div className="fs-head">
          <div className="fs-num">{i + startNum}</div>
          <div>
            <div className="fs-title">{s.title}</div>
            {s.subtitle && <div className="fs-sub">{s.subtitle}</div>}
          </div>
          {s.entered_by && <div className="fs-badge badge-pi">{s.entered_by}</div>}
        </div>
        <div className="fs-body">
          <div className="field-grid">
            {fields.map((f) => (
              <Field
                key={f.code}
                f={f}
                value={data[f.code]}
                onChange={onChange}
                readOnly={readOnly}
                computed={computed}
                error={check?.errors?.[f.code]}
                warning={check?.warnings?.[f.code]}
                override={overrides?.[f.code]}
                onOverride={onOverride}
                needReason={highlight.includes(f.code)}
              />
            ))}
          </div>
        </div>
      </div>
    );
  });
}
