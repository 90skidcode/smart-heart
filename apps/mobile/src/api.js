import { API_BASE } from './config';
import { secure } from './storage';

export class ApiError extends Error {
  constructor(status, body) {
    super(body?.message || `Request failed (${status})`);
    this.status = status;
    this.body = body || {};
    this.offline = status === 0;
  }
}

let onSignedOut = () => {};
export const setSignedOutHandler = (fn) => { onSignedOut = fn; };

export async function api(path, { method = 'GET', body, token } = {}) {
  const headers = { Accept: 'application/json' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  const t = token ?? (await secure.get());
  if (t) headers.Authorization = `Bearer ${t}`;
  let res;
  try {
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), 20000);
    res = await fetch(API_BASE + path, { method, headers, body: body !== undefined ? JSON.stringify(body) : undefined, signal: ctrl.signal });
    clearTimeout(timer);
  } catch {
    throw new ApiError(0, { message: 'offline' });
  }
  const data = await res.json().catch(() => ({}));
  if (res.status === 401 && !path.startsWith('/app/activate')) onSignedOut(data.code);
  if (!res.ok) throw new ApiError(res.status, data);
  return data;
}
