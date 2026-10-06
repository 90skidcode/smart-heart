import { useEffect, useState } from 'react';
import { api } from '../api';
import { useAuth } from '../auth';
import { Empty, fmtDateTime } from '../components/Bits';
import Modal from '../components/Modal';
import { useToast } from '../components/Toast';

const BLANK = { type: 'education', category: '', sort: 0, title_en: '', body_en: '', title_ta: '', body_ta: '', ta_reviewed: false };
const ST = { draft: ['Draft', 'sp-screening'], published: ['Published', 'sp-eligible'], archived: ['Archived', 'sp-muted'] };

export default function Content() {
  const { can } = useAuth();
  const toast = useToast();
  const write = can('content', 'write');
  const [items, setItems] = useState(null);
  const [edit, setEdit] = useState(null);
  const [err, setErr] = useState(null);
  const [type, setType] = useState('education');

  const load = () => api('/content').then((r) => setItems(r.items));
  useEffect(() => { load(); }, []);

  const save = async () => {
    setErr(null);
    try {
      const body = { ...edit, sort: Number(edit.sort) || 0 };
      if (edit.id) await api(`/content/${edit.id}`, { method: 'PUT', body });
      else await api('/content', { method: 'POST', body });
      setEdit(null);
      toast('Saved');
      load();
    } catch (ex) {
      setErr([ex.message, ...Object.values(ex.body.errors || {}).flat()].join(' '));
    }
  };
  const status = async (c, s, notify = false) => {
    await api(`/content/${c.id}/status`, { method: 'POST', body: { status: s, notify } });
    toast(s === 'published' ? 'Published to the app' : s === 'archived' ? 'Archived' : 'Moved back to draft');
    load();
  };
  // Changing the Tamil text needs a fresh review.
  const setTa = (k, v) => setEdit({ ...edit, [k]: v, ta_reviewed: false });

  const list = items?.filter((c) => c.type === type) || [];
  return (
    <div className="panel-page wide">
      <div className="form-header row">
        <div>
          <div className="form-eyebrow">Participant app</div>
          <div className="form-title">Education & FAQ</div>
          <div className="form-desc">Articles and questions shown in the app, in English and Tamil. Tamil reaches participants only after a native speaker ticks “Tamil reviewed”; until then they see the English text.</div>
        </div>
        <div className="seg" role="tablist" aria-label="Content type">
          {[['education', 'Education'], ['faq', 'FAQ']].map(([k, l]) => <button key={k} role="tab" aria-selected={type === k} className={type === k ? 'on' : ''} onClick={() => setType(k)}>{l}</button>)}
        </div>
      </div>
      {write && <button className="btn-primary" style={{ marginBottom: 12 }} onClick={() => { setErr(null); setEdit({ ...BLANK, type }); }}>＋ New {type === 'faq' ? 'question' : 'article'}</button>}
      <div className="ptable">
        <div className="ptable-head content"><span>Title</span><span>Tamil</span><span>Status</span><span>Updated</span><span /></div>
        {items === null ? <Empty>Loading…</Empty> : list.length === 0 ? <Empty>Nothing yet.</Empty> : list.map((c) => (
          <div key={c.id} className="ptable-row content static">
            <div className="ptable-name">{c.title_en}<small>{c.category || '—'} · order {c.sort}</small></div>
            <div>{c.ta_reviewed ? '✓ Reviewed' : c.title_ta ? 'Draft (not shown)' : 'None'}</div>
            <div><span className={`status-pill ${ST[c.status][1]}`}>{ST[c.status][0]}</span></div>
            <div className="ptable-date">{fmtDateTime(c.updated_at)}</div>
            <div className="row-actions">
              {write && <button className="btn-link" onClick={() => { setErr(null); setEdit({ ...BLANK, ...c, category: c.category || '', title_ta: c.title_ta || '', body_ta: c.body_ta || '' }); }}>Edit</button>}
              {write && c.status !== 'published' && <button className="btn-link" onClick={() => status(c, 'published', true)}>Publish</button>}
              {write && c.status === 'published' && <button className="btn-link" onClick={() => status(c, 'draft')}>Unpublish</button>}
              {write && c.status !== 'archived' && <button className="btn-link danger" onClick={() => status(c, 'archived')}>Archive</button>}
            </div>
          </div>
        ))}
      </div>

      {edit && (
        <Modal title={edit.id ? 'Edit' : `New ${edit.type === 'faq' ? 'question' : 'article'}`} wide onClose={() => setEdit(null)}
          footer={<><button className="btn-secondary" onClick={() => setEdit(null)}>Cancel</button><button className="btn-primary" disabled={!write} onClick={save}>Save</button></>}>
          <div className="content-grid">
            <div>
              <label className="field-label" htmlFor="c_cat">Category</label>
              <input id="c_cat" className="field-input" value={edit.category} onChange={(e) => setEdit({ ...edit, category: e.target.value })} placeholder="e.g. Diet, Medicines, Exercise" />
            </div>
            <div>
              <label className="field-label" htmlFor="c_sort">Order</label>
              <input id="c_sort" className="field-input" type="number" min={0} value={edit.sort} onChange={(e) => setEdit({ ...edit, sort: e.target.value })} />
            </div>
          </div>
          <div className="content-grid mt">
            <div>
              <label className="field-label" htmlFor="c_ten">{edit.type === 'faq' ? 'Question' : 'Title'} (English)</label>
              <input id="c_ten" className="field-input" value={edit.title_en} onChange={(e) => setEdit({ ...edit, title_en: e.target.value })} />
              <label className="field-label mt" htmlFor="c_ben">{edit.type === 'faq' ? 'Answer' : 'Text'} (English)</label>
              <textarea id="c_ben" className="field-input" rows={10} value={edit.body_en} onChange={(e) => setEdit({ ...edit, body_en: e.target.value })} />
            </div>
            <div lang="ta">
              <label className="field-label" htmlFor="c_tta">{edit.type === 'faq' ? 'Question' : 'Title'} (Tamil)</label>
              <input id="c_tta" className="field-input" value={edit.title_ta} onChange={(e) => setTa('title_ta', e.target.value)} />
              <label className="field-label mt" htmlFor="c_bta">{edit.type === 'faq' ? 'Answer' : 'Text'} (Tamil)</label>
              <textarea id="c_bta" className="field-input" rows={10} value={edit.body_ta} onChange={(e) => setTa('body_ta', e.target.value)} />
            </div>
          </div>
          <label className="check-pill mt" style={{ display: 'inline-flex' }}>
            <input type="checkbox" checked={edit.ta_reviewed} disabled={!edit.title_ta || !edit.body_ta}
              onChange={(e) => setEdit({ ...edit, ta_reviewed: e.target.checked })} />
            Tamil reviewed by a native speaker — show Tamil in the app
          </label>
          <div className="field-note">Write in plain language. Medical advice must be approved by the PI before publishing.</div>
          {err && <div className="field-error">{err}</div>}
        </Modal>
      )}
    </div>
  );
}
