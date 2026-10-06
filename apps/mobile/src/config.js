// Build-time settings (EXPO_PUBLIC_* values are inlined by Expo when the app is built).
// Set them in apps/mobile/.env or in the EAS build profile — see README.
export const API_BASE = (process.env.EXPO_PUBLIC_API_BASE || 'https://smartheart.example.org/api').replace(/\/$/, '');

// Local development only: skip Firebase and sign in with "dev:+91…" (the server must also have APP_DEV_LOGIN=true
// and APP_ENV=local). Never enable in a build given to participants.
export const DEV_LOGIN = process.env.EXPO_PUBLIC_DEV_LOGIN === 'true';

export const COLORS = {
  red: '#C8102E', navy: '#0D2137', teal: '#00837A', tealLight: '#E8F8F7', gold: '#F4A723', goldLight: '#FFF8EC',
  bg: '#F3F5F8', card: '#FFFFFF', text: '#1A1F2C', muted: '#5A6475', line: '#D5D9E0', ok: '#1A5A3A', okLight: '#E8F5E9', redLight: '#FFF0F2',
};

export const SLOTS = ['Morning', 'Afternoon', 'Evening', 'Night'];
