import NetInfo from '@react-native-community/netinfo';
import { createContext, useCallback, useContext, useEffect, useState } from 'react';
import { api } from './api';
import { strings } from './i18n';
import { getJSON, setJSON } from './storage';

export const AppCtx = createContext(null);
export const useApp = () => useContext(AppCtx);
export const useT = () => strings(useApp().lang);

/**
 * Cached GET: shows the last saved copy at once (works offline), then refreshes from the server.
 * Returns { data, loading, offline, updatedAt, reload }.
 */
export function useCached(path, key) {
  const [state, setState] = useState({ data: null, loading: true, offline: false, updatedAt: null });
  const reload = useCallback(async (silent = false) => {
    if (!silent) setState((x) => ({ ...x, loading: true }));
    try {
      const data = await api(path);
      const updatedAt = new Date().toISOString();
      await setJSON(key, { data, updatedAt });
      setState({ data, loading: false, offline: false, updatedAt });
    } catch (e) {
      setState((x) => ({ ...x, loading: false, offline: !!e.offline }));
    }
  }, [path, key]);
  useEffect(() => {
    let live = true;
    getJSON(key).then((c) => { if (live && c) setState((x) => (x.data ? x : { ...x, data: c.data, updatedAt: c.updatedAt })); });
    reload();
    return () => { live = false; };
  }, [key, reload]);
  return { ...state, reload };
}

export function useOnline() {
  const [online, setOnline] = useState(true);
  useEffect(() => NetInfo.addEventListener((s) => setOnline(s.isConnected !== false)), []);
  return online;
}
