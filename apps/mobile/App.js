import { StatusBar } from 'expo-status-bar';
import { useCallback, useEffect, useState } from 'react';
import { ActivityIndicator, AppState, BackHandler, Pressable, StyleSheet, Text, View } from 'react-native';
import { SafeAreaProvider, SafeAreaView } from 'react-native-safe-area-context';
import { api, setSignedOutHandler } from './src/api';
import { COLORS } from './src/config';
import { strings } from './src/i18n';
import { flush, startAutoFlush } from './src/outbox';
import { currentPushToken, registerPush } from './src/push';
import { clearReminders, setupNotifications, syncReminders } from './src/reminders';
import ActivateScreen from './src/screens/ActivateScreen';
import LearnScreen from './src/screens/LearnScreen';
import MedsScreen from './src/screens/MedsScreen';
import ReadingsScreen from './src/screens/ReadingsScreen';
import SettingsScreen from './src/screens/SettingsScreen';
import TodayScreen from './src/screens/TodayScreen';
import { AppCtx, useApp } from './src/state';
import { getJSON, secure, setJSON, wipe } from './src/storage';

const TABS = [
  ['today', '🏠', 'tabToday', TodayScreen],
  ['meds', '💊', 'tabMeds', MedsScreen],
  ['readings', '❤', 'tabReadings', ReadingsScreen],
  ['learn', '📖', 'tabLearn', LearnScreen],
  ['settings', '⚙', 'tabSettings', SettingsScreen],
];

export default function App() {
  const [boot, setBoot] = useState(true);
  const [me, setMeState] = useState(null);
  const [lang, setLangState] = useState(null);
  const [notice, setNotice] = useState(null);

  const setLang = useCallback((l) => { setLangState(l); setJSON('shi_lang', l); }, []);
  const setMe = useCallback((m) => { setMeState(m); setJSON('shi_me', m); if (m?.lang) setLang(m.lang); }, [setLang]);

  /** reason: null = the person chose to sign out; otherwise a wording key shown on the sign-in screen. */
  const signOut = useCallback(async (reason = null) => {
    if (!reason) {
      // Tell the server (best effort) so this phone stops getting pushes.
      await api('/app/logout', { method: 'POST', body: { fcm_token: await currentPushToken() } }).catch(() => {});
    }
    const keepLang = await getJSON('shi_lang');
    await clearReminders().catch(() => {});
    await wipe();
    if (keepLang) await setJSON('shi_lang', keepLang);
    setMeState(null);
    setNotice(reason);
  }, []);

  useEffect(() => {
    setSignedOutHandler((code) => signOut(code === 'app_access_ended' ? 'accessEnded' : 'signedOutAgain'));
  }, [signOut]);

  // Boot: a saved session works offline; "me" is refreshed when online.
  useEffect(() => {
    (async () => {
      setLangState(await getJSON('shi_lang'));
      if (await secure.get()) {
        const cached = await getJSON('shi_me');
        if (cached) setMeState(cached);
        api('/app/me').then(setMe).catch(() => {});
      }
      setBoot(false);
    })();
  }, [setMe]);

  const onActivated = async (token, m) => {
    await secure.set(token);
    setNotice(null);
    setMe(m);
  };

  if (boot) return <View style={st.center}><ActivityIndicator size="large" color={COLORS.teal} /></View>;

  return (
    <SafeAreaProvider>
      <AppCtx.Provider value={{ me, setMe, lang: lang || 'ta', setLang, signOut }}>
        <SafeAreaView style={{ flex: 1, backgroundColor: COLORS.bg }} edges={['top', 'bottom']}>
          <StatusBar style="dark" />
          {me ? <Main /> : <ActivateScreen lang={lang} setLang={setLang} onActivated={onActivated} notice={notice} />}
        </SafeAreaView>
      </AppCtx.Provider>
    </SafeAreaProvider>
  );
}

function Main() {
  const [tab, setTab] = useState('today');
  const { me, lang } = useApp();
  const t = strings(lang);
  const times = JSON.stringify(me.reminder_times);

  // Send waiting items and rebuild reminders now, when the phone comes back online, and each time the app is opened.
  useEffect(() => {
    const reminderTimes = JSON.parse(times);
    const refresh = async () => {
      flush().catch(() => {});
      let meds = null;
      try {
        meds = await api('/app/medications');
        await setJSON('shi_meds', { data: meds, updatedAt: new Date().toISOString() });
      } catch {
        meds = (await getJSON('shi_meds'))?.data;
      }
      if (meds) await syncReminders(meds, reminderTimes, lang, !me.read_only).catch(() => {});
    };
    setupNotifications().catch(() => {}).finally(refresh);
    const unNet = startAutoFlush();
    const appSub = AppState.addEventListener('change', (s) => s === 'active' && refresh());
    return () => { unNet(); appSub.remove(); };
  }, [times, me.read_only, lang]);

  useEffect(() => {
    let unsub = () => {};
    registerPush().then((u) => { if (u) unsub = u; }).catch(() => {});
    return () => unsub();
  }, []);

  // Android back button returns to Today before leaving the app.
  useEffect(() => {
    const sub = BackHandler.addEventListener('hardwareBackPress', () => {
      if (tab !== 'today') { setTab('today'); return true; }
      return false;
    });
    return () => sub.remove();
  }, [tab]);

  const Current = TABS.find((x) => x[0] === tab)[3];
  return (
    <View style={{ flex: 1 }}>
      <View style={{ flex: 1 }}><Current /></View>
      <View style={st.tabbar} accessibilityRole="tablist">
        {TABS.map(([key, icon, label]) => (
          <Pressable key={key} accessibilityRole="tab" accessibilityState={{ selected: tab === key }} accessibilityLabel={t[label]}
            onPress={() => setTab(key)} style={st.tab}>
            <Text style={[st.tabIcon, tab === key && { opacity: 1 }]}>{icon}</Text>
            <Text style={[st.tabText, tab === key && st.tabOn]} numberOfLines={1}>{t[label]}</Text>
          </Pressable>
        ))}
      </View>
    </View>
  );
}

const st = StyleSheet.create({
  center: { flex: 1, alignItems: 'center', justifyContent: 'center', backgroundColor: COLORS.bg },
  tabbar: { flexDirection: 'row', backgroundColor: '#fff', borderTopWidth: 1, borderTopColor: COLORS.line, paddingVertical: 6 },
  tab: { flex: 1, alignItems: 'center', paddingVertical: 4, minHeight: 56, justifyContent: 'center' },
  tabIcon: { fontSize: 22, opacity: 0.6 },
  tabText: { fontSize: 12, color: COLORS.muted, marginTop: 2 },
  tabOn: { color: COLORS.teal, fontWeight: '700' },
});
