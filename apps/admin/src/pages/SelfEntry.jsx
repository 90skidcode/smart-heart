import { useEffect, useRef, useState } from 'react';
import { useParams } from 'react-router-dom';
import { api } from '../api';
import { useAuth } from '../auth';

// Interface wording only (questions come from the server).
// TAMIL STRINGS ARE A DRAFT — they must be reviewed by a native speaker before use with participants.
const UI = {
  en: { of: (n, t) => `Question ${n} of ${t}`, submit: 'Submit my answers', saving: 'Saving…', saved: 'Your answers are saved as you go.',
    missing: 'Please answer every question marked with a dot.', thanks: 'Thank you', handBack: 'Please hand the tablet back to the study team.',
    ended: 'This questionnaire is closed.', pick: 'Tap your answer', vasHint: 'Tap a number', optional: '(optional)' },
  ta: { of: (n, t) => `கேள்வி ${n} / ${t}`, submit: 'என் பதில்களைச் சமர்ப்பிக்கவும்', saving: 'சேமிக்கிறது…', saved: 'உங்கள் பதில்கள் தானாகச் சேமிக்கப்படுகின்றன.',
    missing: 'புள்ளி இட்ட எல்லா கேள்விகளுக்கும் பதிலளிக்கவும்.', thanks: 'நன்றி', handBack: 'டேப்லெட்டை ஆய்வுக் குழுவிடம் திருப்பித் தரவும்.',
    ended: 'இந்தக் கேள்வித்தாள் மூடப்பட்டது.', pick: 'உங்கள் பதிலைத் தொடவும்', vasHint: 'ஒரு எண்ணைத் தொடவும்', optional: '(விருப்பம்)' },
};

export default function SelfEntry() {
  const { token } = useParams();
  const [q, setQ] = useState(null);
  const [answers, setAnswers] = useState({});
  const [state, setState] = useState('loading'); // loading | answering | submitting | done | error
  const [msg, setMsg] = useState(null);
  const [missing, setMissing] = useState([]);
  const [support, setSupport] = useState(null);
  const saveTimer = useRef();
  const { forget } = useAuth();

  // The participant must never reach the eCRF: sign any staff user out on this tablet first.
  useEffect(() => { forget(); }, []); // eslint-disable-line react-hooks/exhaustive-deps

  useEffect(() => {
    api(`/self-entry/${token}`)
      .then((r) => { setQ(r); setAnswers(r.answers || {}); setState('answering'); })
      .catch((e) => { setMsg(e.message); setState('error'); });
  }, [token]);

  const t = UI[q?.lang] || UI.en;

  const answer = (code, value) => {
    setAnswers((a) => {
      const next = { ...a, [code]: value };
      clearTimeout(saveTimer.current);
      saveTimer.current = setTimeout(() => api(`/self-entry/${token}`, { method: 'PUT', body: { answers: next } }).catch(() => {}), 500);
      return next;
    });
    setMissing((m) => m.filter((x) => x !== code));
  };

  const submit = async () => {
    const need = q.items.filter((it) => it.required && answers[it.code] == null).map((it) => it.code);
    if (need.length) {
      setMissing(need);
      document.getElementById(`q_${need[0]}`)?.scrollIntoView?.({ behavior: 'smooth', block: 'center' });
      return;
    }
    setState('submitting');
    try {
      const r = await api(`/self-entry/${token}/submit`, { method: 'POST', body: { answers } });
      setSupport(r.support_message);
      setState('done');
    } catch (ex) {
      setMissing(ex.body.missing || []);
      setMsg(ex.message);
      setState('answering');
    }
  };

  if (state === 'loading') return <div className="se-wrap"><div className="se-card">…</div></div>;
  if (state === 'error') return <div className="se-wrap"><div className="se-card se-end"><h1>{UI.en.ended}</h1><p>{msg}</p><p>{UI.en.handBack}<br />{UI.ta.handBack}</p></div></div>;
  if (state === 'done') {
    return (
      <div className="se-wrap">
        <div className="se-card se-end">
          <div className="se-tick">✓</div>
          <h1>{t.thanks}</h1>
          {support && <div className="se-support" role="alert">{support}</div>}
          <p className="se-hand">{t.handBack}</p>
        </div>
      </div>
    );
  }

  const answered = q.items.filter((it) => answers[it.code] != null).length;
  return (
    <div className="se-wrap" lang={q.lang}>
      <header className="se-head">
        <div className="se-title">{q.title}</div>
        <div className="se-progress"><span style={{ width: `${(answered / q.items.length) * 100}%` }} /></div>
        <div className="se-sub">{t.saved}</div>
      </header>
      {q.instructions && <div className="se-instructions">{q.instructions}</div>}
      {q.items.map((it, i) => (
        <section key={it.code} id={`q_${it.code}`} className={`se-card ${missing.includes(it.code) ? 'se-missing' : ''}`}>
          <div className="se-count">{t.of(i + 1, q.items.length)} {!it.required && <span>{t.optional}</span>}{it.required && answers[it.code] == null && <span className="se-dot" aria-hidden="true">●</span>}</div>
          <div className="se-question">{it.text}</div>
          {it.type === 'choice' ? (
            <div className="se-options" role="radiogroup" aria-label={it.text}>
              {it.options.map((o) => (
                <button key={o.value} role="radio" aria-checked={answers[it.code] === o.value}
                  className={`se-option ${answers[it.code] === o.value ? 'on' : ''}`} onClick={() => answer(it.code, o.value)}>
                  {o.label}
                </button>
              ))}
            </div>
          ) : (
            <div className="se-scale">
              <div className="se-scale-ends"><span>{it.min} · {it.low}</span><span>{it.max} · {it.high}</span></div>
              <div className="se-scale-grid" role="radiogroup" aria-label={it.text}>
                {Array.from({ length: (it.max - it.min) / 5 + 1 }, (_, k) => it.min + k * 5).map((v) => (
                  <button key={v} role="radio" aria-checked={answers[it.code] === v} className={`se-num ${answers[it.code] === v ? 'on' : ''}`}
                    onClick={() => answer(it.code, v)}>{v}</button>
                ))}
              </div>
              <label className="se-exact">{t.vasHint}: <input type="number" min={it.min} max={it.max} inputMode="numeric" value={answers[it.code] ?? ''}
                onChange={(e) => { const v = e.target.value === '' ? null : Math.max(it.min, Math.min(it.max, Math.round(Number(e.target.value)))); answer(it.code, v); }} /></label>
            </div>
          )}
        </section>
      ))}
      {missing.length > 0 && <div className="se-error" role="alert">{t.missing}</div>}
      <button className="se-submit" disabled={state === 'submitting'} onClick={submit}>{state === 'submitting' ? t.saving : t.submit}</button>
    </div>
  );
}
