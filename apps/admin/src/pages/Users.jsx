import { useCallback, useEffect, useState } from 'react';
import { api } from '../api';
import { useAuth } from '../auth';
import { Empty, fmtDateTime } from '../components/Bits';
import Modal from '../components/Modal';
import { useToast } from '../components/Toast';

function TempPassword({ user, password, onClose }) {
  return (
    <Modal title="Temporary password" eyebrow={user} onClose={onClose}
      footer={<button className="btn-primary" onClick={onClose}>Done</button>}>
      <div className="form-desc">Give this password to the user in person or by phone. It is shown only once. They must change it at first sign-in.</div>
      <div className="temp-pw">{password}</div>
    </Modal>
  );
}

function UserModal({ user, roles, onClose, onSaved }) {
  const isNew = !user.id;
  const [f, setF] = useState({ name: user.name || '', email: user.email || '', designation: user.designation || '', role_id: user.role_id || roles[0]?.id, is_active: user.is_active ?? true });
  const [err, setErr] = useState(null);
  const save = async () => {
    setErr(null);
    try {
      const res = isNew
        ? await api('/users', { method: 'POST', body: f })
        : await api(`/users/${user.id}`, { method: 'PUT', body: f });
      onSaved(res);
    } catch (ex) {
      setErr(ex.message);
    }
  };
  const set = (k) => (e) => setF({ ...f, [k]: e.target.type === 'checkbox' ? e.target.checked : e.target.value });
  return (
    <Modal title={isNew ? 'Add user' : 'Edit user'} onClose={onClose}
      footer={<><button className="btn-secondary" onClick={onClose}>Cancel</button><button className="btn-primary" onClick={save}>{isNew ? 'Create user' : 'Save'}</button></>}>
      <label className="field-label">Full name</label><input className="field-input" value={f.name} onChange={set('name')} />
      <label className="field-label mt">Email (sign-in)</label><input className="field-input" type="email" value={f.email} onChange={set('email')} disabled={!isNew} />
      <label className="field-label mt">Designation</label><input className="field-input" value={f.designation} onChange={set('designation')} placeholder="e.g. Principal Investigator" />
      <label className="field-label mt">Role</label>
      <select className="field-input" value={f.role_id} onChange={set('role_id')}>
        {roles.map((r) => <option key={r.id} value={r.id}>{r.name}</option>)}
      </select>
      {!isNew && (
        <label className="check-line mt"><input type="checkbox" checked={f.is_active} onChange={set('is_active')} /> Active (untick to deactivate — users are never deleted)</label>
      )}
      {err && <div className="field-error">{err}</div>}
    </Modal>
  );
}

export default function Users() {
  const { can, me } = useAuth();
  const toast = useToast();
  const [users, setUsers] = useState(null);
  const [roles, setRoles] = useState([]);
  const [editing, setEditing] = useState(null);
  const [temp, setTemp] = useState(null);
  const write = can('users', 'write');

  const load = useCallback(() => {
    api('/users').then(setUsers);
    if (can('roles')) api('/roles').then((r) => setRoles(r.roles));
  }, [can]);
  useEffect(() => { load(); }, [load]);

  const reset = async (u) => {
    if (!confirm(`Reset the password for ${u.name}? Their current sessions will end.`)) return;
    const r = await api(`/users/${u.id}/reset-password`, { method: 'POST' });
    setTemp({ user: u.email, password: r.temporary_password });
    load();
  };

  return (
    <div className="panel-page wide">
      <div className="form-header row">
        <div>
          <div className="form-eyebrow">Administration</div>
          <div className="form-title">Users</div>
          <div className="form-desc">Staff accounts. Access is set by the user's role (see Roles &amp; permissions).</div>
        </div>
        {write && roles.length > 0 && <button className="btn-primary" onClick={() => setEditing({})}>＋ Add user</button>}
      </div>
      <div className="ptable">
        <div className="ptable-head users"><span>Name</span><span>Email</span><span>Role</span><span>Status</span><span>Last sign-in</span><span /></div>
        {!users ? <Empty>Loading…</Empty> : users.map((u) => (
          <div className="ptable-row users static" key={u.id}>
            <div className="ptable-name">{u.name}<small>{u.designation}</small></div>
            <div className="ptable-date">{u.email}</div>
            <div>{u.role}</div>
            <div>
              {!u.is_active ? <span className="status-pill sp-muted">Inactive</span>
                : u.locked ? <span className="status-pill sp-failure">Locked</span>
                  : u.must_change_password ? <span className="status-pill sp-screening">Temp password</span>
                    : <span className="status-pill sp-eligible">Active</span>}
            </div>
            <div className="ptable-date">{fmtDateTime(u.last_login_at)}</div>
            <div className="row-actions">
              {write && <button className="btn-link" onClick={() => setEditing(u)}>Edit</button>}
              {write && u.id !== me.user.id && <button className="btn-link" onClick={() => reset(u)}>Reset password</button>}
            </div>
          </div>
        ))}
      </div>
      {editing && (
        <UserModal user={editing} roles={roles} onClose={() => setEditing(null)} onSaved={(res) => {
          setEditing(null);
          if (res.temporary_password) setTemp({ user: res.user.email, password: res.temporary_password });
          else toast('User updated');
          load();
        }} />
      )}
      {temp && <TempPassword user={temp.user} password={temp.password} onClose={() => setTemp(null)} />}
    </div>
  );
}
