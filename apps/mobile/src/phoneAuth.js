import { getAuth, getIdToken, onAuthStateChanged, signInWithPhoneNumber, signOut } from '@react-native-firebase/auth';
import { DEV_LOGIN } from './config';

// Firebase phone OTP. The server checks the resulting ID token and the Participant ID;
// after that the app uses its own server token, and the Firebase session is closed.

/** Starts verification. Returns { confirm(code) → idToken, autoVerified: Promise<idToken> }. */
export async function startPhoneVerification(phone10) {
  if (DEV_LOGIN) {
    const tok = `dev:+91${phone10}`;
    return { confirm: async () => tok, autoVerified: new Promise(() => {}), cancel: () => {} };
  }
  const auth = getAuth();
  const confirmation = await signInWithPhoneNumber(auth, `+91${phone10}`);
  let unsub = () => {};
  // Android can verify the SMS automatically; then no code needs typing.
  const autoVerified = new Promise((resolve) => {
    unsub = onAuthStateChanged(auth, async (user) => {
      if (user?.phoneNumber) resolve(await getIdToken(user, true));
    });
  });
  return {
    confirm: async (code) => {
      const cred = await confirmation.confirm(code);
      return getIdToken(cred.user, true);
    },
    autoVerified,
    cancel: () => unsub(),
  };
}

export async function endFirebaseSession() {
  if (DEV_LOGIN) return;
  try {
    await signOut(getAuth());
  } catch {
    // already signed out
  }
}

/** Maps Firebase error codes to our wording keys. */
export function otpErrorKey(e) {
  const c = e?.code || '';
  if (c.includes('invalid-verification-code') || c.includes('session-expired')) return 'codeWrong';
  if (c.includes('too-many-requests') || c.includes('quota')) return 'tooMany';
  if (c.includes('network')) return 'noNetwork';
  return 'errorGeneric';
}
