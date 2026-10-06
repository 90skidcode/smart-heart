import { useEffect, useState } from 'react';
import { api } from '../api';
import { useAuth } from '../auth';
import { Alert, Empty } from '../components/Bits';
import { useToast } from '../components/Toast';

function Editor({ inst, lang, onClose }) {
  const { can } = useAuth();
  const toast = useToast();
  const [d, setD] = useState(null);
  const [texts, setTexts] = useState({});
  const [note, setNote] = useState('');
  const [err, setErr] = useState(null);
  const write = can('instruments', 'write');

  useEffect(() => {
    api(`/instruments/${inst.key}/${lang}`).then((r) => { setD(r); setTexts(Object.fromEntries(r.rows.map((x) => [x.key, x.text ?? '']))); });
  }, [inst.key, lang]);

  const save = async () => {
    setErr(null);
    try {
      const r = await api(`/instruments/${inst.key}/${lang}`, { method: 'PUT', body: { texts, source_note: note } });
      setD(r);
      toast('Saved');
      onClose(true);
    } catch (ex) {
      setErr(ex.message);
    }
  };

  if (!d) return <Empty>Loading…</Empty>;
  return (
    <div className="form-section">
      <div className="fs-head"><div className="fs-num">✎</div><div><div className="fs-title">{d.title} · {lang === 'ta' ? 'Tamil' : 'English'}</div>
        <div className="fs-sub">{d.source}</div></div><button className="btn-link" style={{ marginLeft: 'auto' }} onClick={() => onClose(false)}>Close</button></div>
      <div className="fs-body">
        <Alert kind="warn" title={d.licensed ? 'Licensed instrument' : lang === 'ta' ? 'Validated translation only' : 'Public-domain wording'}>
          {d.licensed ? 'Type or paste the exact licensed wording. Do not paraphrase or translate it yourself.'
            : lang === 'ta' ? 'Use only the validated Tamil version. Never our own translation.'
              : 'The built-in English is the published wording; leave fields blank to keep it.'}
        </Alert>
        <table className="text-table">
          <thead><tr><th>Key</th><th>What it is</th><th>Text</th></tr></thead>
          <tbody>
            {d.rows.map((r) => (
              <tr key={r.key}>
                <td className="mono">{r.key}</td>
                <td>{r.what}</td>
                <td><textarea className="field-input" rows={r.key.includes('.') ? 1 : 2} readOnly={!write} value={texts[r.key] ?? ''}
                  placeholder={r.builtin ?? ''} onChange={(e) => setTexts({ ...texts, [r.key]: e.target.value })} /></td>
              </tr>
            ))}
          </tbody>
        </table>
        {write && (
          <>
            <label className="field-label mt" htmlFor="src_note">Source of this wording (recorded in the audit trail)</label>
            <input id="src_note" className="field-input" value={note} onChange={(e) => setNote(e.target.value)} placeholder="e.g. EuroQol Tamil (India) v2.1, licence ref 12345, received 3 Oct 2026" />
            {err && <div className="field-error">{err}</div>}
            <div className="nav-strip"><span /><button className="btn-nav btn-next" disabled={note.trim().length < 5} onClick={save}>Save texts</button></div>
          </>
        )}
      </div>
    </div>
  );
}

export default function Instruments() {
  const { can } = useAuth();
  const toast = useToast();
  const [list, setList] = useState(null);
  const [edit, setEdit] = useState(null);
  const [vs, setVs] = useState({ csv: '', note: '' });
  const [vsErr, setVsErr] = useState(null);
  const load = () => api('/instruments').then(setList);
  useEffect(() => { load(); }, []);

  const uploadVs = async () => {
    setVsErr(null);
    try {
      const r = await api('/instruments/eq5d-value-set', { method: 'POST', body: { csv: vs.csv, source_note: vs.note } });
      toast(`Value set loaded: ${r.rows} states`);
      setVs({ csv: '', note: '' });
      load();
    } catch (ex) {
      setVsErr([ex.message, ...(ex.body.errors || [])].join(' '));
    }
  };

  if (!list) return <div className="panel-page">Loading…</div>;
  return (
    <div className="panel-page wide">
      <div className="form-header">
        <div className="form-eyebrow">Administration</div>
        <div className="form-title">Questionnaire texts</div>
        <div className="form-desc">A questionnaire can be used in a language only when every question and answer has its approved wording. English PHQ-9, GAD-7 and DASI are built in; EQ-5D-5L, MARS-5 and every Tamil version must be entered here from the licensed or validated source.</div>
      </div>
      {edit ? <Editor inst={edit.inst} lang={edit.lang} onClose={(changed) => { setEdit(null); if (changed) load(); }} /> : (
        <div className="ptable">
          <div className="ptable-head inst"><span>Questionnaire</span><span>Type</span><span>English</span><span>Tamil</span></div>
          {list.instruments.map((i) => (
            <div key={i.key} className="ptable-row inst static">
              <div className="ptable-name">{i.title}<small>{i.form}</small></div>
              <div>{i.licensed ? <span className="status-pill sp-screening">Licensed</span> : <span className="status-pill sp-muted">Public domain</span>}</div>
              {['en', 'ta'].map((l) => (
                <div key={l}>
                  <button className="btn-link" onClick={() => setEdit({ inst: i, lang: l })}>
                    {i.languages[l].ready ? '✓ Ready' : `${i.languages[l].missing} texts missing`} · {can('instruments', 'write') ? 'Edit' : 'View'}
                  </button>
                </div>
              ))}
            </div>
          ))}
        </div>
      )}
      <div className="form-section" style={{ marginTop: 16 }}>
        <div className="fs-head"><div className="fs-num">5L</div><div><div className="fs-title">EQ-5D-5L India value set</div>
          <div className="fs-sub">{list.eq5d_value_set_rows ? `${list.eq5d_value_set_rows} health states loaded — utilities are calculated.` : 'Not loaded — utility stays empty until it is.'}</div></div></div>
        {can('instruments', 'write') && (
          <div className="fs-body">
            <div className="field-note">CSV with two columns: health_state (e.g. 21111) and utility. Exactly 3,125 rows (Jyani et al. 2022, under the EuroQol licence).</div>
            <input type="file" accept=".csv,text/csv" className="mt" onChange={(e) => e.target.files[0]?.text().then((t) => setVs({ ...vs, csv: t }))} />
            <input className="field-input mt" placeholder="Source of the value set (recorded in the audit trail)" value={vs.note} onChange={(e) => setVs({ ...vs, note: e.target.value })} />
            {vsErr && <div className="field-error">{vsErr}</div>}
            <button className="btn-primary mt" disabled={!vs.csv || vs.note.trim().length < 5} onClick={uploadVs}>Load value set</button>
          </div>
        )}
      </div>
    </div>
  );
}
