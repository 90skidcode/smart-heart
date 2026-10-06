import { createContext, useCallback, useContext, useRef, useState } from 'react';

const Ctx = createContext(() => {});
export const useToast = () => useContext(Ctx);

export function ToastProvider({ children }) {
  const [t, setT] = useState(null);
  const timer = useRef();
  const show = useCallback((msg, kind = 'ok') => {
    setT({ msg, kind });
    clearTimeout(timer.current);
    timer.current = setTimeout(() => setT(null), 3200);
  }, []);
  return (
    <Ctx.Provider value={show}>
      {children}
      <div className={`toast ${t ? 'show' : ''} ${t?.kind || ''}`} role="status">
        <span className="toast-ico">{t?.kind === 'err' ? '!' : '✓'}</span>
        <span>{t?.msg}</span>
      </div>
    </Ctx.Provider>
  );
}
