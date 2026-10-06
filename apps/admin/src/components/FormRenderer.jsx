// Renders an eCRF form from its server-side definition (sections + fields).
// Field codes and validation ranges come from the API, so the screen always
// matches what the server enforces. The client never calculates: computed
// fields show what the server returned.

export function isVisible(field, data) {
  if (!field.show_if) return true;
  return data[field.show_if.field] === field.show_if.equals;
}

const pretty = (v) => {
  if (v == null || v === '') return '—';
  if (typeof v === 'boolean') return v ? 'Yes' : 'No';
  if (Array.isArray(v)) return v.join(', ');
  if (typeof v === 'string') return v.replace(/_/g, ' ');
  return String(v);
};

function RadioGroup({ id, options, value, set, readOnly, getValue = (o) => o, getLabel = (o) => o, horizontal }) {
  return (
    <div className={`radio-group ${horizontal ? 'horizontal' : ''}`} role="radiogroup" aria-labelledby={`${id}_l`}>
      {options.map((o) => {
        const v = getValue(o);
        const on = value === v;
        return (
          <button type="button" key={String(v)} role="radio" aria-checked={on} disabled={readOnly}
            className={`radio-row ${on ? 'selected' : ''} ${/Exclusion|prevents/.test(getLabel(o)) ? 'excl' : ''}`}
            onClick={() => set(on ? null : v)}>
            <span className="radio-dot" />
            {getLabel(o)}
          </button>
        );
      })}
    </div>
  );
}

function Checkboxes({ id, options, value, set, readOnly }) {
  const cur = Array.isArray(value) ? value : [];
  return (
    <div className="check-group" role="group" aria-labelledby={`${id}_l`}>
      {options.map((o) => (
        <label key={o} className={`check-pill ${cur.includes(o) ? 'on' : ''}`}>
          <input type="checkbox" disabled={readOnly} checked={cur.includes(o)}
            onChange={() => set(cur.includes(o) ? cur.filter((x) => x !== o) : [...cur, o])} />
          {o}
        </label>
      ))}
    </div>
  );
}

function TableField({ f, value, set, readOnly }) {
  const rows = Array.isArray(value) ? value : [];
  const blank = () => Object.fromEntries(f.columns.map((c) => [c.code, c.type === 'checkboxes' ? [] : null]));
  const update = (i, k, v) => set(rows.map((r, j) => (j === i ? { ...r, [k]: v } : r)));
  return (
    <div className="table-field">
      <table>
        <thead>
          <tr>{f.columns.map((c) => <th key={c.code}>{c.label}{c.required && <span className="field-req">*</span>}</th>)}{!readOnly && <th />}</tr>
        </thead>
        <tbody>
          {rows.length === 0 && <tr><td colSpan={f.columns.length + 1} className="empty">No rows yet.</td></tr>}
          {rows.map((r, i) => (
            <tr key={i}>
              {f.columns.map((c) => {
                const v = r[c.code];
                const label = `${c.label}, row ${i + 1}`;
                if (c.type === 'select') {
                  return <td key={c.code}><select aria-label={label} className="field-input" disabled={readOnly} value={v ?? ''} onChange={(e) => update(i, c.code, e.target.value || null)}>
                    <option value="">—</option>{c.options.map((o) => <option key={o}>{o}</option>)}</select></td>;
                }
                if (c.type === 'checkboxes') {
                  return <td key={c.code}><Checkboxes id={`${f.code}_${i}_${c.code}`} options={c.options} value={v} readOnly={readOnly} set={(nv) => update(i, c.code, nv)} /></td>;
                }
                return <td key={c.code}><input aria-label={label} className="field-input" type={c.type === 'date' ? 'date' : 'text'} readOnly={readOnly}
                  placeholder={c.placeholder || ''} value={v ?? ''} onChange={(e) => update(i, c.code, e.target.value || null)} /></td>;
              })}
              {!readOnly && <td><button type="button" className="btn-link danger" onClick={() => set(rows.filter((_, j) => j !== i))} aria-label={`Remove row ${i + 1}`}>✕</button></td>}
            </tr>
          ))}
        </tbody>
      </table>
      {!readOnly && <button type="button" className="btn-secondary small" onClick={() => set([...rows, blank()])}>＋ Add row</button>}
    </div>
  );
}

function Field({ f, value, onChange, readOnly, computed, error, warning, override, onOverride, needReason }) {
  const id = `f_${f.code}`;
  const set = (v) => onChange(f.code, v);

  let input;
  if (f.type === 'computed') {
    const v = computed?.[f.code];
    input = <input id={id} className="field-input computed" readOnly value={v == null ? '—' : `${pretty(v)}${f.unit ? ` ${f.unit}` : ''}`} />;
  } else if (f.type === 'radio') {
    input = <RadioGroup id={id} options={f.options} value={value} set={set} readOnly={readOnly} />;
  } else if (f.type === 'choice') {
    input = <RadioGroup id={id} options={f.options} value={value} set={set} readOnly={readOnly} getValue={(o) => o.value}
      getLabel={(o) => `${o.label}${o.label !== String(o.value) ? ` (${o.value})` : ''}`} horizontal={f.options.length <= 5} />;
  } else if (f.type === 'checkboxes') {
    input = <Checkboxes id={id} options={f.options} value={value} set={(v) => set(v.length ? v : null)} readOnly={readOnly} />;
  } else if (f.type === 'table') {
    input = <TableField f={f} value={value} set={(v) => set(v.length ? v : null)} readOnly={readOnly} />;
  } else if (f.type === 'scale') {
    input = (
      <div className="scale-field">
        <input id={id} type="number" className="field-input" min={f.min} max={f.max} step={1} readOnly={readOnly} value={value ?? ''}
          onChange={(e) => set(e.target.value === '' ? null : Math.round(Number(e.target.value)))} />
        <span className="field-note">{f.min}{f.low_label ? ` = ${f.low_label}` : ''} · {f.max}{f.high_label ? ` = ${f.high_label}` : ''}. Never pre-filled.</span>
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
        {f.unit && <span className="unit-tag">{f.unit}</span>}
      </div>
    );
  }

  const wide = ['table', 'choice'].includes(f.type) || (f.type === 'radio' && f.options.some((o) => o.length > 18)) || f.type === 'text';
  return (
    <div className={`field ${wide ? 'field-wide' : ''} ${needReason ? 'need-reason' : ''}`}>
      <label className="field-label" id={`${id}_l`} htmlFor={['radio', 'choice', 'checkboxes', 'table'].includes(f.type) ? undefined : id}>
        {f.label}
        {f.required && f.type !== 'computed' && <span className="field-req">*</span>}
        <span className="field-code">{f.code}</span>
      </label>
      {input}
      {f.note && <div className="field-note">{f.note}</div>}
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
