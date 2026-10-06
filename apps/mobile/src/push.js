import { getMessaging, getToken, onTokenRefresh } from '@react-native-firebase/messaging';
import Constants from 'expo-constants';
import { api } from './api';

// Registers this phone for push (FCM). Push carries only a generic line; the app fetches details itself.
export async function registerPush() {
  const m = getMessaging();
  const send = (fcm_token) => api('/app/devices', { method: 'POST', body: { fcm_token, app_version: Constants.expoConfig?.version } }).catch(() => {});
  try {
    const t = await getToken(m);
    if (t) await send(t);
  } catch {
    // Push is optional (e.g. no Google Play services); reminders still work locally.
  }
  return onTokenRefresh(m, send);
}

export async function currentPushToken() {
  try {
    return await getToken(getMessaging());
  } catch {
    return null;
  }
}
