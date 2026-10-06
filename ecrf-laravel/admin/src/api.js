// Thin fetch wrapper. The session token lives in sessionStorage, so closing the
// browser ends the session (GCP: no persistent logins on shared hospital PCs).

const BASE = import.meta.env.VITE_API_BASE || '/api';
const KEY = 'shi_token';

export const token = {
  get: () => sessionStorage.getItem(KEY),
  set: (t) => sessionStorage.setItem(KEY, t),
  clear: () => sessionStorage.removeItem(KEY),
};

let onAuthLost = () => {};
export function setAuthLostHandler(fn) {
  onAuthLost = fn;
}

export class ApiError extends Error {
  constructor(status, body) {
    super(body?.message || `Request failed (${status})`);
    this.status = status;
    this.body = body || {};
  }
}

export async function api(path, { method = 'GET', body } = {}) {
  const headers = { Accept: 'application/json' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  const t = token.get();
  if (t) headers.Authorization = `Bearer ${t}`;

  let res;
  try {
    res = await fetch(BASE + path, { method, headers, body: body !== undefined ? JSON.stringify(body) : undefined });
  } catch {
    throw new ApiError(0, { message: 'Cannot reach the server. Check the internet connection.' });
  }
  const data = res.headers.get('content-type')?.includes('json') ? await res.json().catch(() => ({})) : {};

  if (res.status === 401 && !path.startsWith('/auth/login')) {
    token.clear();
    onAuthLost(data.message || 'Please sign in again.');
  }
  if (res.status === 403 && data.code === 'password_change_required') {
    onAuthLost(null, 'change-password');
  }
  if (!res.ok) throw new ApiError(res.status, data);
  return data;
}

/** Download a file from an authenticated endpoint. */
export async function download(path) {
  const res = await fetch(BASE + path, { headers: { Authorization: `Bearer ${token.get()}` } });
  if (!res.ok) {
    const data = await res.json().catch(() => ({}));
    throw new ApiError(res.status, data);
  }
  const blob = await res.blob();
  const cd = res.headers.get('content-disposition') || '';
  const name = (cd.match(/filename="?([^";]+)"?/) || [])[1] || 'download.csv';
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url;
  a.download = name;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(url), 1000);
  return name;
}

export function qs(params) {
  const s = new URLSearchParams(Object.entries(params).filter(([, v]) => v !== '' && v != null)).toString();
  return s ? `?${s}` : '';
}
