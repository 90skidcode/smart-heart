// The phone's local calendar date (participants are in IST), as YYYY-MM-DD.
export function localDate(d = new Date()) {
  const p = (n) => String(n).padStart(2, '0');
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}`;
}

export function fmtTime(iso) {
  const d = new Date(iso);
  return `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`;
}

export function fmtDay(iso, lang) {
  return new Date(iso).toLocaleDateString(lang === 'ta' ? 'ta-IN' : 'en-IN', { day: 'numeric', month: 'short' });
}
