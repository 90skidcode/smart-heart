import NetInfo from '@react-native-community/netinfo';
import * as Crypto from 'expo-crypto';
import { api } from './api';
import { getJSON, setJSON } from './storage';

// Offline-first queue. Every dose answer and reading gets a UUID on the phone, is stored here first,
// and is sent in batches when online. The server ignores repeats of the same UUID, so retrying is safe.
const KEY = 'shi_outbox';
const REJECTED = 'shi_rejected';
const NOTICES = 'shi_notices';
const listeners = new Set();
let flushing = null;

export const subscribe = (fn) => { listeners.add(fn); return () => listeners.delete(fn); };
const emit = async () => { const s = await status(); listeners.forEach((fn) => fn(s)); };

export async function status() {
  const q = await getJSON(KEY, []);
  return { pending: q.length, rejected: await getJSON(REJECTED, []), notices: await getJSON(NOTICES, []) };
}

export async function enqueue(kind, item) {
  const entry = { kind, item: { client_uuid: Crypto.randomUUID(), ...item } };
  const q = await getJSON(KEY, []);
  q.push(entry);
  await setJSON(KEY, q);
  await emit();
  flush().catch(() => {});
  return entry.item;
}

export const pending = async (kind) => (await getJSON(KEY, [])).filter((e) => e.kind === kind).map((e) => e.item);

export async function clearNotice(id) {
  await setJSON(NOTICES, (await getJSON(NOTICES, [])).filter((n) => n.id !== id));
  await emit();
}

/** Send what is waiting. Only one flush runs at a time. */
export function flush() {
  if (!flushing) flushing = doFlush().finally(() => { flushing = null; });
  return flushing;
}

async function doFlush() {
  const net = await NetInfo.fetch();
  if (net.isConnected === false) return;
  for (const [kind, path, field] of [['dose', '/app/doses', 'doses'], ['reading', '/app/readings', 'readings']]) {
    const batch = (await getJSON(KEY, [])).filter((e) => e.kind === kind).slice(0, 100);
    if (!batch.length) continue;
    let res;
    try {
      res = await api(path, { method: 'POST', body: { [field]: batch.map((e) => e.item) } });
    } catch (e) {
      if (e.status === 403) {
        // Read-only (caregiver) — these can never be sent.
        await drop(batch.map((e) => e.item.client_uuid));
      }
      return; // offline or server error: keep and retry later
    }
    const done = new Set();
    const rejected = await getJSON(REJECTED, []);
    const notices = await getJSON(NOTICES, []);
    for (const r of res.results) {
      done.add(r.client_uuid);
      if (r.result === 'rejected') {
        rejected.push({ kind, uuid: r.client_uuid, reason: r.reason, item: batch.find((e) => e.item.client_uuid === r.client_uuid)?.item });
      }
      if (r.flag) notices.push({ id: r.client_uuid, flag: r.flag });
    }
    await setJSON(REJECTED, rejected.slice(-20));
    await setJSON(NOTICES, notices);
    await drop([...done]);
  }
}

async function drop(uuids) {
  const set = new Set(uuids);
  await setJSON(KEY, (await getJSON(KEY, [])).filter((e) => !set.has(e.item.client_uuid)));
  await emit();
}

/** Retry when the connection comes back. */
export function startAutoFlush() {
  return NetInfo.addEventListener((s) => { if (s.isConnected) flush().catch(() => {}); });
}
