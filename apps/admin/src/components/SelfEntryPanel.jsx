import { useState } from 'react';
import { api } from '../api';
import { Alert } from './Bits';

/**
 * Starts a tablet self-entry session for one participant and one questionnaire.
 * The session page shows only the questions (no scores, no other data) and locks on submit.
 */
export default function SelfEntryPanel({ pid, code, info, canStart }) {
  const langs = Object.entries(info.languages);
  const [lang, setLang] = useState(langs.find(([, v]) => v.ready)?.[0] || 'en');
  const [err, setErr] = useState(null);
  const [link, setLink] = useState(null);

  const start = async () => {
    setErr(null);
    try {
      const r = await api(`/participants/${pid}/forms/${code}/self-entry`, { method: 'POST', body: { lang } });
      setLink(r.path);
    } catch (ex) {
      setErr(ex.message);
    }
  };

  return (
    <div className="selfentry-panel">
      <div>
        <div className="fs-title">Tablet self-entry</div>
        <div className="fs-sub">The participant answers on this tablet in their language. They never see a score. The session locks when they submit.</div>
      </div>
      {link ? (
        <Alert kind="ok" title="Session ready — hand the tablet to the participant">
          <a className="btn-primary" href={link}>Open questionnaire on this tablet →</a>
          <div className="field-note" style={{ marginTop: 6 }}>Opening it signs you out on this tablet, so the participant cannot reach the eCRF. Valid for 60 minutes; a new session cancels this one.</div>
        </Alert>
      ) : (
        <div className="selfentry-actions">
          <div className="seg" role="radiogroup" aria-label="Language">
            {langs.map(([l, v]) => (
              <button key={l} disabled={!v.ready} className={lang === l ? 'on' : ''} onClick={() => setLang(l)}
                title={v.ready ? '' : 'Validated wording not entered yet'}>{v.label}{!v.ready && ' (not loaded)'}</button>
            ))}
          </div>
          {canStart && <button className="btn-primary" onClick={start} disabled={!info.languages[lang]?.ready}>Start tablet session</button>}
          {info.open_session && <span className="field-note">A session is already open; starting a new one cancels it.</span>}
        </div>
      )}
      {err && <div className="field-error">{err}</div>}
    </div>
  );
}
