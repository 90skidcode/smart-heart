import * as Notifications from 'expo-notifications';
import { SLOTS } from './config';
import { strings } from './i18n';
import { getJSON, setJSON } from './storage';

// Medicine reminders are LOCAL notifications, so they work without internet.
// They are rebuilt whenever the medicine list, the reminder times or the language change.
const KEY = 'shi_reminder_sig';
export const CHANNEL = 'medicines';

Notifications.setNotificationHandler({
  handleNotification: async () => ({ shouldPlaySound: true, shouldSetBadge: false, shouldShowBanner: true, shouldShowList: true }),
});

export async function setupNotifications() {
  await Notifications.setNotificationChannelAsync(CHANNEL, {
    name: 'Medicine reminders',
    importance: Notifications.AndroidImportance.HIGH,
    vibrationPattern: [0, 300, 200, 300],
  });
  const { status } = await Notifications.getPermissionsAsync();
  if (status === 'granted') return true;
  return (await Notifications.requestPermissionsAsync()).status === 'granted';
}

/** meds: published list; times: {Morning: "08:00", ...}. Caregivers get no reminders. */
export async function syncReminders(meds, times, lang, enabled = true) {
  const slots = enabled ? SLOTS.filter((s) => (meds?.items || []).some((m) => m.times.includes(s) && !['Weekly', 'As needed'].includes(m.frequency))) : [];
  const sig = JSON.stringify({ slots, t: slots.map((s) => times[s]), lang, v: meds?.version });
  if ((await getJSON(KEY)) === sig) return;
  await Notifications.cancelAllScheduledNotificationsAsync();
  const t = strings(lang);
  for (const s of slots) {
    const [hour, minute] = (times[s] || '08:00').split(':').map(Number);
    await Notifications.scheduleNotificationAsync({
      // No medicine names on the lock screen.
      content: { title: t.reminderTitle, body: t.reminderBody(t.slot[s]), data: { slot: s } },
      trigger: { type: Notifications.SchedulableTriggerInputTypes.DAILY, hour, minute, channelId: CHANNEL },
    });
  }
  await setJSON(KEY, sig);
}

export const clearReminders = () => Notifications.cancelAllScheduledNotificationsAsync();
