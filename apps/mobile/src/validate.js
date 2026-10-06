// Reading checks. Same limits as the server (AppService::readingCheck) so the person gets the message at once;
// the server checks again. Pure functions — unit-tested in test/validate.test.mjs.

export const fmtReading = (r) => (r.type === 'bp' ? `${r.values.sbp}/${r.values.dbp} mmHg${r.values.pulse ? ` · ${r.values.pulse}/min` : ''}`
  : r.type === 'glucose' ? `${r.values.mg_dl} mg/dL` : `${r.values.kg} kg`);

export const num = (v) => (v === '' || v == null ? NaN : Number(String(v).replace(',', '.')));

export function check(type, f, t) {
  if (type === 'bp') {
    const [a, b, p] = [num(f.sbp), num(f.dbp), f.pulse === '' ? null : num(f.pulse)];
    if (!(a >= 60 && a <= 260 && b >= 30 && b <= 160 && a > b) || (p !== null && !(p >= 30 && p <= 220))) return [null, t.rangeBp];
    return [{ sbp: a, dbp: b, ...(p !== null ? { pulse: p } : {}) }, null];
  }
  if (type === 'glucose') {
    const g = num(f.mg_dl);
    return g >= 20 && g <= 600 ? [{ mg_dl: g, context: f.context }, null] : [null, t.rangeGlucose];
  }
  const k = num(f.kg);
  return k >= 25 && k <= 250 ? [{ kg: k }, null] : [null, t.rangeWeight];
}

export function measuredAt(when, hhmm, now = new Date()) {
  if (when === 'now') return now;
  const m = /^([01]?\d|2[0-3]):([0-5]\d)$/.exec(hhmm.trim());
  if (!m) return null;
  const d = new Date(now);
  d.setHours(Number(m[1]), Number(m[2]), 0, 0);
  return d > now ? null : d;
}

