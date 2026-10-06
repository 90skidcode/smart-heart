import { useEffect, useState } from 'react';
import { NavLink, useNavigate } from 'react-router-dom';
import { api } from '../api';
import { useAuth } from '../auth';

function Item({ to, icon, label, end, badge }) {
  return (
    <NavLink to={to} end={end} className={({ isActive }) => `sb-item ${isActive ? 'active' : ''}`}>
      <span className="sb-status">{icon}</span>
      <span className="sb-name">{label}</span>
      {badge > 0 && <span className="sb-badge" aria-label={`${badge} critical alerts open`}>{badge}</span>}
    </NavLink>
  );
}

export default function Layout({ children }) {
  const { me, can, logout } = useAuth();
  const nav = useNavigate();
  const canAlerts = can('alerts');
  const [critical, setCritical] = useState(0);
  // Poll open critical alerts (PHQ-9 item 9) every minute so they are seen on any page.
  useEffect(() => {
    if (!canAlerts) return undefined;
    const load = () => api('/alerts/summary').then((r) => setCritical(r.critical_open)).catch(() => {});
    load();
    const t = setInterval(load, 60000);
    return () => clearInterval(t);
  }, [canAlerts]);
  const initials = me.user.name.split(/\s+/).filter(Boolean).slice(0, 2).map((w) => w[0]).join('').toUpperCase();

  return (
    <div className="app-shell">
      <header className="topbar">
        <div className="tb-left">
          <svg width="26" height="26" viewBox="0 0 160 160" aria-hidden="true">
            <path d="M80,140 C80,140 16,104 12,64 C8,36 26,20 44,20 C56,20 68,28 80,44 C92,28 104,20 116,20 C134,20 152,36 148,64 C144,104 80,140 80,140Z" fill="#C8102E" />
            <polyline points="30,78 58,78 66,64 74,92 84,48 94,100 102,78 130,78" fill="none" stroke="#fff" strokeWidth="8" strokeLinecap="round" strokeLinejoin="round" />
          </svg>
          <div className="tb-brand">{me.study?.name || 'SMART-HEART'}</div>
          <div className="tb-sep" />
          <div className="tb-module">Electronic CRF System</div>
        </div>
        <div className="tb-right">
          <div className="tb-site">{me.study?.site}</div>
          <div className="tb-user">{me.user.name} · {me.user.role}</div>
          <button className="tb-avatar" title="Change password" onClick={() => nav('/change-password')}>{initials}</button>
          <button className="tb-logout" onClick={() => logout()}>Sign out</button>
        </div>
      </header>
      <div className="shell">
        <nav className="sidebar" aria-label="Main">
          <div className="sb-section">
            <div className="sb-label">Study</div>
            {can('dashboard') && <Item to="/" end icon="🏠" label="Dashboard & participants" />}
            {can('participants', 'write') && <Item to="/participants/new" icon="＋" label="REG-01 · Register participant" />}
          </div>
          {(canAlerts || can('randomisation')) && (
            <div className="sb-section">
              <div className="sb-label">Safety & allocation</div>
              {canAlerts && <Item to="/alerts" icon="🚨" label="Safety alerts" badge={critical} />}
              {can('randomisation') && <Item to="/randomisation" icon="🎲" label="Randomisation list" />}
            </div>
          )}
          <div className="sb-section">
            <div className="sb-label">Later phases</div>
            <div className="sb-item locked"><span className="sb-status">📊</span><span className="sb-name">Intervention monitoring</span></div>
            <div className="sb-item locked"><span className="sb-status">🔗</span><span className="sb-name">Control-arm follow-up</span></div>
          </div>
          {(can('export_deidentified') || can('export_identified') || can('audit')) && (
            <div className="sb-section">
              <div className="sb-label">Data</div>
              {(can('export_deidentified') || can('export_identified')) && <Item to="/export" icon="⇩" label="Export for analysis" />}
              {can('audit') && <Item to="/audit" icon="🔍" label="Audit trail" />}
            </div>
          )}
          {(can('users') || can('roles') || can('instruments') || can('scoring') || can('content')) && (
            <div className="sb-section">
              <div className="sb-label">Administration</div>
              {can('users') && <Item to="/users" icon="👤" label="Users" />}
              {can('roles') && <Item to="/roles" icon="🔐" label="Roles & permissions" />}
              {can('instruments') && <Item to="/instruments" icon="✎" label="Questionnaire texts" />}
              {can('scoring') && <Item to="/scoring" icon="∑" label="CCSPS thresholds" />}
              {can('content') && <Item to="/content" icon="📖" label="App education & FAQ" />}
            </div>
          )}
          <div className="sb-foot">All times in IST · Every change is recorded in the audit trail.</div>
        </nav>
        <main className="main">{children}</main>
      </div>
    </div>
  );
}
