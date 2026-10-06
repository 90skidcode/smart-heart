import { ActivityIndicator, Pressable, RefreshControl, ScrollView, StyleSheet, Text, TextInput, View } from 'react-native';
import { COLORS } from '../config';

// Large text and touch targets: many participants are older adults.
export function Screen({ children, refreshing, onRefresh }) {
  return (
    <ScrollView style={s.screen} contentContainerStyle={s.content} keyboardShouldPersistTaps="handled"
      refreshControl={onRefresh ? <RefreshControl refreshing={!!refreshing} onRefresh={onRefresh} colors={[COLORS.teal]} /> : undefined}>
      {children}
    </ScrollView>
  );
}

export const Title = ({ children }) => <Text style={s.title} accessibilityRole="header">{children}</Text>;
export const H2 = ({ children }) => <Text style={s.h2} accessibilityRole="header">{children}</Text>;
export const Muted = ({ children, style }) => <Text style={[s.muted, style]}>{children}</Text>;
export const Card = ({ children, style }) => <View style={[s.card, style]}>{children}</View>;

export function Button({ title, onPress, kind = 'primary', disabled, busy, small, style, accessibilityLabel }) {
  const k = { primary: [COLORS.teal, '#fff'], danger: [COLORS.red, '#fff'], light: ['#fff', COLORS.text], ghost: ['transparent', COLORS.teal] }[kind];
  return (
    <Pressable accessibilityRole="button" accessibilityLabel={accessibilityLabel || title} accessibilityState={{ disabled: !!(disabled || busy) }}
      onPress={onPress} disabled={disabled || busy}
      style={({ pressed }) => [s.btn, small && s.btnSmall, { backgroundColor: k[0], opacity: disabled ? 0.45 : pressed ? 0.8 : 1 },
        kind === 'light' && s.btnLight, style]}>
      {busy ? <ActivityIndicator color={k[1]} /> : <Text style={[s.btnText, small && s.btnTextSmall, { color: k[1] }]}>{title}</Text>}
    </Pressable>
  );
}

export function Field({ label, error, ...props }) {
  return (
    <View style={{ marginBottom: 14 }}>
      <Text style={s.label}>{label}</Text>
      <TextInput accessibilityLabel={label} placeholderTextColor={COLORS.muted} style={[s.input, error && { borderColor: COLORS.red }]} {...props} />
      {error ? <Text style={s.error}>{error}</Text> : null}
    </View>
  );
}

export function Banner({ kind = 'info', children }) {
  const c = { info: [COLORS.tealLight, COLORS.teal], warn: [COLORS.goldLight, '#8B5B00'], error: [COLORS.redLight, COLORS.red], ok: [COLORS.okLight, COLORS.ok] }[kind];
  return <View style={[s.banner, { backgroundColor: c[0], borderColor: c[1] }]} accessibilityRole="alert"><Text style={[s.bannerText, { color: COLORS.text }]}>{children}</Text></View>;
}

export function Choice({ options, value, onChange }) {
  return (
    <View style={s.choiceRow} accessibilityRole="radiogroup">
      {options.map(([v, label]) => (
        <Pressable key={v} accessibilityRole="radio" accessibilityState={{ checked: value === v }} onPress={() => onChange(v)}
          style={[s.choice, value === v && s.choiceOn]}>
          <Text style={[s.choiceText, value === v && { color: '#fff', fontWeight: '700' }]}>{label}</Text>
        </Pressable>
      ))}
    </View>
  );
}

export const s = StyleSheet.create({
  screen: { flex: 1, backgroundColor: COLORS.bg },
  content: { padding: 16, paddingBottom: 40 },
  title: { fontSize: 26, fontWeight: '700', color: COLORS.navy, marginBottom: 6 },
  h2: { fontSize: 19, fontWeight: '700', color: COLORS.navy, marginTop: 18, marginBottom: 8 },
  muted: { fontSize: 15, color: COLORS.muted, lineHeight: 21 },
  card: { backgroundColor: COLORS.card, borderRadius: 14, padding: 16, marginBottom: 10 },
  btn: { minHeight: 54, borderRadius: 12, alignItems: 'center', justifyContent: 'center', paddingHorizontal: 18 },
  btnSmall: { minHeight: 44, paddingHorizontal: 14, borderRadius: 10 },
  btnLight: { borderWidth: 2, borderColor: COLORS.line },
  btnText: { fontSize: 18, fontWeight: '700' },
  btnTextSmall: { fontSize: 16 },
  label: { fontSize: 16, fontWeight: '600', color: COLORS.text, marginBottom: 6 },
  input: { borderWidth: 2, borderColor: COLORS.line, borderRadius: 10, backgroundColor: '#fff', fontSize: 20, paddingHorizontal: 14, paddingVertical: 12, color: COLORS.text },
  error: { color: COLORS.red, fontSize: 15, marginTop: 4 },
  banner: { borderLeftWidth: 5, borderRadius: 10, padding: 14, marginBottom: 12 },
  bannerText: { fontSize: 16, lineHeight: 23 },
  choiceRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 8, marginBottom: 14 },
  choice: { borderWidth: 2, borderColor: COLORS.line, borderRadius: 22, paddingHorizontal: 16, paddingVertical: 10, backgroundColor: '#fff' },
  choiceOn: { backgroundColor: COLORS.teal, borderColor: COLORS.teal },
  choiceText: { fontSize: 16, color: COLORS.text },
});
