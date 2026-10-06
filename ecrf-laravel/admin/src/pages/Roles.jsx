import { useCallback, useEffect, useState } from 'react';
import { api } from '../api';
import { useAuth } from '../auth';
import Modal from '../components/Modal';
import { useToast } from '../components/Toast';

export default function Roles() {
  const { can } = useAuth();
  const toast = useToast();
  const write = can('roles', 'write');
  const [meta, setMeta] = useState(null);
  const [sel, setSel] = useState(null);
  const [draft, setDraft] = useState(null);
  const [adding, setAdding] = useState(false);
  const [newName, setNewName] = useState('');
  const [err, setErr] = useState(null);

  const load = useCallback(async (keepId) => {
    const m = await api('/roles');
    setMeta(m);
    const r = m.roles.find((x) => x.id === keepId) || m.roles[0];
    setSel(r?.id);
    setDraft(r ? { name: r.name, description: r.description || '', permissions: structuredClone(r.permissions) } : null);
  }, []);
  useEffect(() => { load(); }, [load]);

  if (!meta) return <div className="panel-page">Loading…</div>;
  const role = meta.roles.find((r) => r.id === sel);

  const pick = (r) => {
    setSel(r.id);
    setDraft({ name: r.name, description: r.description || '', permissions: structuredClone(r.permissions) });
    setErr(null);
  };
  const isProtected = (key) => role?.is_system && meta.protected.includes(key);
  const toggle = (key, level) => {
    setDraft((d) => {
      const p = { ...(d.permissions[key] || { read: false, write: false }) };
      p[level] = !p[level];
      if (level === 'write' && p.write) p.read = true;
      if (level === 'read' && !p.read) p.write = false;
      return { ...d, permissions: { ...d.permissions, [key]: p } };
    });
  };
  const save = async () => {
    setErr(null);
    try {
      await api(`/roles/${sel}`, { method: 'PUT', body: draft });
      toast('Role saved — users get the new access at their next page load');
      load(sel);
    } catch (ex) {
      setErr(ex.message);
    }
  };
  const create = async () => {
    try {
      const r = await api('/roles', { method: 'POST', body: { name: newName } });
      setAdding(false);
      setNewName('');
      load(r.id);
    } catch (ex) {
      setErr(ex.message);
    }
  };
  const remove = async () => {
    if (!confirm(`Delete role "${role.name}"?`)) return;
    try {
      await api(`/roles/${sel}`, { method: 'DELETE' });
      toast('Role deleted');
      load();
    } catch (ex) {
      setErr(ex.message);
    }
  };

  const groups = [...new Set(meta.screens.map((s) => s.group))];

  return (
    <div className="panel-page wide">
      <div className="form-header row">
        <div>
          <div className="form-eyebrow">Administration</div>
          <div className="form-title">Roles &amp; permissions</div>
          <div className="form-desc">Choose what each role can see (read) and change (write) on every screen. Write always includes read. Every change is recorded in the audit trail.</div>
        </div>
        {write && <button className="btn-primary" onClick={() => setAdding(true)}>＋ New role</button>}
      </div>

      <div className="roles-layout">
        <div className="role-list">
          {meta.roles.map((r) => (
            <button key={r.id} className={`role-item ${r.id === sel ? 'on' : ''}`} onClick={() => pick(r)}>
              <b>{r.name}</b>
              <span>{r.users_count} user{r.users_count === 1 ? '' : 's'}{r.is_system ? ' · system' : ''}</span>
            </button>
          ))}
        </div>

        {draft && (
          <div className="form-section" style={{ flex: 1 }}>
            <div className="fs-body">
              <div className="field-grid">
                <div className="field"><label className="field-label">Role name</label>
                  <input className="field-input" value={draft.name} disabled={!write} onChange={(e) => setDraft({ ...draft, name: e.target.value })} /></div>
                <div className="field field-wide"><label className="field-label">Description</label>
                  <input className="field-input" value={draft.description} disabled={!write} onChange={(e) => setDraft({ ...draft, description: e.target.value })} /></div>
              </div>
              <table className="matrix">
                <thead><tr><th>Screen</th><th>Read</th><th>Write</th></tr></thead>
                {groups.map((g) => (
                  <tbody key={g}>
                    <tr className="matrix-group"><td colSpan={3}>{g}</td></tr>
                    {meta.screens.filter((s) => s.group === g).map((s) => {
                      const p = draft.permissions[s.key] || { read: false, write: false };
                      const lock = isProtected(s.key);
                      return (
                        <tr key={s.key}>
                          <td>{s.label}<span className="field-code">{s.key}</span>{lock && <span className="lock-note">always on for system admin</span>}</td>
                          <td><input type="checkbox" aria-label={`${s.label} read`} checked={!!p.read} disabled={!write || lock} onChange={() => toggle(s.key, 'read')} /></td>
                          <td>{s.write_label === null ? <span className="na">—</span> : (
                            <label className="w-label"><input type="checkbox" aria-label={`${s.label} write`} checked={!!p.write} disabled={!write || (lock && s.key !== 'audit')} onChange={() => toggle(s.key, 'write')} /> {s.write_label}</label>
                          )}</td>
                        </tr>
                      );
                    })}
                  </tbody>
                ))}
              </table>
              {err && <div className="field-error">{err}</div>}
              {write && (
                <div className="nav-strip">
                  {!role?.is_system ? <button className="btn-secondary danger" onClick={remove}>Delete role</button> : <span />}
                  <button className="btn-nav btn-next" onClick={save}>Save role</button>
                </div>
              )}
            </div>
          </div>
        )}
      </div>

      {adding && (
        <Modal title="New role" onClose={() => setAdding(false)}
          footer={<><button className="btn-secondary" onClick={() => setAdding(false)}>Cancel</button><button className="btn-primary" disabled={!newName.trim()} onClick={create}>Create</button></>}>
          <label className="field-label">Role name</label>
          <input className="field-input" autoFocus value={newName} onChange={(e) => setNewName(e.target.value)} placeholder="e.g. Study Nurse" />
          <div className="field-note">The new role starts with no access. Tick the screens it needs, then save.</div>
        </Modal>
      )}
    </div>
  );
}
