import { useEffect, useRef, useState } from 'react';
import { Text, View } from 'react-native';
import Constants from 'expo-constants';
import { api } from '../api';
import { Banner, Button, Choice, Field, Muted, Screen, Title } from '../components/ui';
import { COLORS } from '../config';
import { strings } from '../i18n';
import { endFirebaseSession, otpErrorKey, startPhoneVerification } from '../phoneAuth';

/**
 * First run: language → Participant ID + mobile → SMS code.
 * The server accepts the participant's registered number, or a caregiver number the study team added.
 */
export default function ActivateScreen({ lang, setLang, onActivated, notice }) {
  const t = strings(lang);
  const [step, setStep] = useState(lang ? 'details' : 'lang');
  const [pid, setPid] = useState('');
  const [phone, setPhone] = useState('');
  const [code, setCode] = useState('');
  const [err, setErr] = useState(null);
  const [busy, setBusy] = useState(false);
  const verification = useRef(null);
  const activating = useRef(false); // auto-verification and a typed code must not both activate

  useEffect(() => () => verification.current?.cancel(), []);

  const activate = async (idToken) => {
    if (activating.current) return;
    activating.current = true;
    setBusy(true);
    setErr(null);
    try {
      const res = await api('/app/activate', { method: 'POST', token: '', body: { participant_id: pid.trim(), id_token: idToken, device_name: Constants.deviceName || 'Android' } });
      await endFirebaseSession();
      await onActivated(res.token, res.me);
    } catch (e) {
      await endFirebaseSession();
      setErr(e.offline ? t.noNetwork : e.body?.code === 'not_registered' ? t.notRegistered : e.body?.message || t.errorGeneric);
      setStep('details');
      setCode('');
      activating.current = false; // allow another try; on success it stays set
    } finally {
      setBusy(false);
    }
  };

  const sendCode = async () => {
    setErr(null);
    if (!pid.trim()) return setErr(t.needId);
    if (!/^[6-9]\d{9}$/.test(phone)) return setErr(t.needNumber);
    setBusy(true);
    try {
      verification.current?.cancel();
      verification.current = await startPhoneVerification(phone);
      verification.current.autoVerified.then(activate);
      setStep('code');
    } catch (e) {
      setErr(t[otpErrorKey(e)]);
    } finally {
      setBusy(false);
    }
  };

  const verify = async () => {
    setErr(null);
    setBusy(true);
    try {
      const idToken = await verification.current.confirm(code);
      await activate(idToken);
    } catch (e) {
      setErr(t[otpErrorKey(e)]);
      setBusy(false);
    }
  };

  return (
    <Screen>
      <View style={{ alignItems: 'center', marginVertical: 24 }}>
        <Text style={{ fontSize: 44 }} accessibilityElementsHidden>❤️</Text>
        <Text style={{ fontSize: 28, fontWeight: '800', color: COLORS.red }}>{t.appName}</Text>
      </View>
      {notice ? <Banner kind="warn">{t[notice] || notice}</Banner> : null}

      {step === 'lang' && (
        <>
          <Title>{strings('en').chooseLanguage} / {strings('ta').chooseLanguage}</Title>
          <View style={{ gap: 12, marginTop: 12 }}>
            <Button title="தமிழ்" onPress={() => { setLang('ta'); setStep('details'); }} />
            <Button title="English" kind="light" onPress={() => { setLang('en'); setStep('details'); }} />
          </View>
        </>
      )}

      {step === 'details' && (
        <>
          <Title>{t.welcome}</Title>
          <Muted style={{ marginBottom: 18 }}>{t.activateIntro}</Muted>
          <Field label={t.participantId} placeholder={t.participantIdHint} autoCapitalize="characters" value={pid} onChangeText={setPid} />
          <Field label={t.mobile} keyboardType="phone-pad" maxLength={10} value={phone} onChangeText={(v) => setPhone(v.replace(/\D/g, ''))}
            placeholder="98XXXXXXXX" textContentType="telephoneNumber" />
          {err ? <Banner kind="error">{err}</Banner> : null}
          <Button title={t.sendCode} onPress={sendCode} busy={busy} />
          <Choice options={[['ta', 'தமிழ்'], ['en', 'English']]} value={lang} onChange={setLang} />
        </>
      )}

      {step === 'code' && (
        <>
          <Title>{t.verify}</Title>
          <Muted style={{ marginBottom: 18 }}>{t.enterCode} +91 {phone}</Muted>
          <Field label="OTP" keyboardType="number-pad" maxLength={6} value={code} onChangeText={(v) => setCode(v.replace(/\D/g, ''))}
            textContentType="oneTimeCode" autoComplete="sms-otp" autoFocus />
          {err ? <Banner kind="error">{err}</Banner> : null}
          <Button title={busy ? t.verifying : t.verify} onPress={verify} busy={busy} disabled={code.length !== 6} />
          <Button title={t.changeNumber} kind="ghost" onPress={() => { verification.current?.cancel(); setStep('details'); setCode(''); setErr(null); }} style={{ marginTop: 8 }} />
        </>
      )}
    </Screen>
  );
}
