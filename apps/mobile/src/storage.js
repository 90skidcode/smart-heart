import AsyncStorage from '@react-native-async-storage/async-storage';
import * as SecureStore from 'expo-secure-store';

// The sign-in token lives in the Android keystore; everything else in AsyncStorage.
const TOKEN = 'shi_app_token';

export const secure = {
  get: () => SecureStore.getItemAsync(TOKEN),
  set: (v) => SecureStore.setItemAsync(TOKEN, v),
  clear: () => SecureStore.deleteItemAsync(TOKEN),
};

export async function getJSON(key, fallback = null) {
  try {
    const v = await AsyncStorage.getItem(key);
    return v == null ? fallback : JSON.parse(v);
  } catch {
    return fallback;
  }
}

export const setJSON = (key, value) => AsyncStorage.setItem(key, JSON.stringify(value));

/** Remove everything this app stored (sign-out / access ended). */
export async function wipe() {
  await secure.clear();
  const keys = (await AsyncStorage.getAllKeys()).filter((k) => k.startsWith('shi_'));
  await AsyncStorage.multiRemove(keys);
}
