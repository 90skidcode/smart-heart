import Constants from 'expo-constants';
import { useState } from 'react';
import { Alert, Linking } from 'react-native';
import { api } from '../api';
import { Banner, Button, Card, Choice, Field, H2, Muted, Screen, Title } from '../components/ui';
import { SLOTS } from '../config';
import { useApp, useT } from '../state';

const HHMM = /^([01]\d|2[0-3]):[0-5]\d$/;

export default function SettingsScreen() {
  const { me, setMe, lang, setLang, signOut } = useApp();
  const t = useT();
  const [times, setTimes] = useState(me.reminder_times);
  const [msg, setMsg] = useState(null);
  const [busy, setBusy] = useState(false);

  const changeLang = async (l) => {
    setLang(l);
    try { setMe(await api('/app/me', { method: 'PUT', body: { lang: l } })); } catch { /* kept locally; sent next time */ }
  };
  const saveTimes = async () => {
    setMsg(null);
    if (!SLOTS.every((s) => HHMM.test(times[s] || ''))) return setMsg(['error', t.remindersHint]);
    setBusy(true);
    try {
      setMe(await api('/app/me', { method: 'PUT', body: { reminder_times: times } }));
      setMsg(['ok', `✓ ${t.saved}`]);
    } catch (e) {
      setMsg(['error', e.offline ? t.noNetwork : t.errorGeneric]);
    } finally {
      setBusy(false);
    }
  };
  const confirmSignOut = () => Alert.alert(t.signOut, t.signOutConfirm, [{ text: t.no, style: 'cancel' }, { text: t.yes, style: 'destructive', onPress: () => signOut() }]);

  return (
    <Screen>
      <Title>{t.settingsTitle}</Title>
      <H2>{t.language}</H2>
      <Choice options={[['ta', 'தமிழ்'], ['en', 'English']]} value={lang} onChange={changeLang} />

      {!me.read_only && (
        <>
          <H2>{t.reminders}</H2>
          <Card>
            <Muted style={{ marginBottom: 10 }}>{t.remindersHint}</Muted>
            {SLOTS.map((s) => (
              <Field key={s} label={t.slot[s]} value={times[s] || ''} maxLength={5} keyboardType="numbers-and-punctuation"
                onChangeText={(v) => setTimes({ ...times, [s]: v })} />
            ))}
            {msg ? <Banner kind={msg[0]}>{msg[1]}</Banner> : null}
            <Button title={t.saveTimes} onPress={saveTimes} busy={busy} />
          </Card>
        </>
      )}

      {me.study_phone ? <Button title={`📞 ${t.callTeam}`} kind="light" onPress={() => Linking.openURL(`tel:${me.study_phone}`)} style={{ marginTop: 16 }} /> : null}
      <Button title={t.signOut} kind="danger" onPress={confirmSignOut} style={{ marginTop: 16 }} />
      <Muted style={{ textAlign: 'center', marginTop: 20 }}>{me.study_id} · {t.version} {Constants.expoConfig?.version}</Muted>
    </Screen>
  );
}
