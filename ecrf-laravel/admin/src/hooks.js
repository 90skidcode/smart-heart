import { useEffect, useState } from 'react';
import { api } from './api';

let defsPromise = null;

/** Form definitions from the server (fetched once per page load). */
export function useDefinitions() {
  const [defs, setDefs] = useState(null);
  useEffect(() => {
    defsPromise ??= api('/forms/definitions').catch((e) => {
      defsPromise = null;
      throw e;
    });
    let alive = true;
    defsPromise.then((d) => alive && setDefs(d)).catch(() => {});
    return () => { alive = false; };
  }, []);
  return defs;
}
