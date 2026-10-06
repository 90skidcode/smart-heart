# SMART-HEART participant app (Android)

React Native with Expo SDK 57. Intervention-arm participants and their caregivers use it; the control arm does not.

| Screen | What it does |
|---|---|
| Sign-in | Language (Tamil / English) → Participant ID + mobile number → SMS code (Firebase). |
| Today | Today's medicines by time of day with **Taken / Skip**, today's readings, "waiting to be sent" count. Caregivers see the same, read-only, with a **Seen** button. |
| Medicines | The list the study team published, with times and instructions. |
| Readings | Add blood pressure, sugar or weight (now or earlier today); last 30 days. |
| Learn | Education articles and FAQ from the admin panel. |
| Settings | Language, reminder times, call the study team, sign out. |

No questionnaire scores, arm details or other eCRF data are ever shown in the app.

## How data moves

- Every dose answer and reading gets a UUID on the phone and goes into an **outbox** (`src/outbox.js`) first. It is sent in batches straight away if online, when the connection returns, and each time the app is opened. The server ignores repeats, so retries never double-count.
- The last copy of each screen is cached, so the app opens and shows information offline.
- Medicine **reminders are local notifications** (`src/reminders.js`), rebuilt when the list, the times or the language change. They work without internet, and the lock screen never shows medicine names.
- **Push** (FCM) only says "your list was updated / something new to read"; the app then fetches the details.

## Build

React Native Firebase needs native code, so the app runs as a **development build or APK**, not in Expo Go.

```bash
cd apps/mobile
npm install
cp /path/to/google-services.json .        # from Firebase (see the main README §8); not committed

# Point the app at your server (inlined at build time):
echo "EXPO_PUBLIC_API_BASE=https://YOUR-DOMAIN/api" > .env

npm test                                   # unit tests (reading checks)
npm run export:check                       # bundles the JavaScript to check it compiles

# APK with EAS (free Expo account):
npm install -g eas-cli && eas login
eas build -p android --profile preview     # set EXPO_PUBLIC_API_BASE in eas.json first
```

`eas build` prints a download link for the APK. Install it on the participant's phone (allow "install unknown apps" once). The SHA-1 / SHA-256 of the signing key that EAS creates must be added to the Firebase Android app, or SMS sign-in will fail. Get them with `eas credentials`.

For day-to-day development: `eas build -p android --profile development`, install that once, then `npm start`.

### Local development without Firebase

Set `EXPO_PUBLIC_DEV_LOGIN=true` in `.env`, and on a **local** server `APP_ENV=local` + `APP_DEV_LOGIN=true`. The app then skips the SMS step and signs in as the number typed. The server ignores this outside `APP_ENV=local`/`testing`. Never build a participant APK with it on.

## Tamil review (required before go-live)

All Tamil interface text in `src/i18n.js` is a **draft** written for this build. A native Tamil speaker on the study team must check every line, and the PI must approve the two health messages (`readingHigh`, `readingLow`). The same applies to the Tamil push texts in `apps/api/app/Services/App/PushService.php`.

## Dependencies

expo, react-native, @react-native-firebase/{app,auth,messaging} (SMS sign-in, push), expo-notifications (reminders), expo-secure-store (sign-in token in the Android keystore), @react-native-async-storage/async-storage (outbox and cache), @react-native-community/netinfo (send when back online), expo-crypto (UUIDs), expo-constants, expo-dev-client, react-native-safe-area-context. There is no navigation library: five tabs are switched in `App.js`.

## Not yet in the app
Health Connect sync (Mi Fitness watch, OMRON connect BP monitor) and background sync every 4–6 hours come in the device-integration phase. The server already accepts `source: "health_connect"` with a device name.
