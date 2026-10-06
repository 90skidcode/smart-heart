import { createContext, useCallback, useContext, useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { api, setAuthLostHandler, token } from './api';

const AuthCtx = createContext(null);
export const useAuth = () => useContext(AuthCtx);

export function AuthProvider({ children }) {
  const [state, setState] = useState({ loading: !!token.get(), me: null, notice: null });
  const navigate = useNavigate();

  const loadMe = useCallback(async () => {
    try {
      const me = await api('/auth/me');
      setState({ loading: false, me, notice: null });
    } catch {
      setState((s) => ({ ...s, loading: false, me: null }));
    }
  }, []);

  useEffect(() => {
    setAuthLostHandler((message, where) => {
      if (where === 'change-password') {
        navigate('/change-password');
        return;
      }
      setState({ loading: false, me: null, notice: message });
      navigate('/login');
    });
    if (token.get()) loadMe();
  }, [loadMe, navigate]);

  const login = async (email, password) => {
    const res = await api('/auth/login', { method: 'POST', body: { email, password } });
    token.set(res.token);
    setState({ loading: false, me: res, notice: null });
    return res;
  };

  const logout = async (notice = null) => {
    try {
      if (token.get()) await api('/auth/logout', { method: 'POST' });
    } catch { /* already gone */ }
    token.clear();
    setState({ loading: false, me: null, notice });
    navigate('/login');
  };

  const can = (screen, level = 'read') => !!state.me?.permissions?.[screen]?.[level];

  return (
    <AuthCtx.Provider value={{ ...state, login, logout, can, setMe: (me) => setState((s) => ({ ...s, me })) }}>
      {children}
      {state.me && <IdleGuard minutes={state.me.idle_minutes || 15} onTimeout={() => logout('You were signed out after a period of inactivity.')} />}
    </AuthCtx.Provider>
  );
}

/** Signs the user out after N idle minutes, with a 60-second warning. */
function IdleGuard({ minutes, onTimeout }) {
  const [warn, setWarn] = useState(false);
  const last = useRef(Date.now());

  useEffect(() => {
    const bump = () => {
      last.current = Date.now();
      setWarn(false);
    };
    const events = ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'];
    events.forEach((e) => window.addEventListener(e, bump, { passive: true }));
    const timer = setInterval(() => {
      const idle = (Date.now() - last.current) / 1000;
      if (idle >= minutes * 60) onTimeout();
      else if (idle >= minutes * 60 - 60) setWarn(true);
    }, 5000);
    return () => {
      events.forEach((e) => window.removeEventListener(e, bump));
      clearInterval(timer);
    };
  }, [minutes, onTimeout]);

  if (!warn) return null;
  return (
    <div className="idle-warn" role="alert">
      You will be signed out in about a minute because of inactivity. Move the mouse or press a key to stay signed in.
    </div>
  );
}
