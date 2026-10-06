import { useEffect, useState } from 'react';
import { Text, View } from 'react-native';
import { Banner, Button, Card, Choice, Field, H2, Muted, Screen, Title } from '../components/ui';
import { COLORS } from '../config';
import { fmtDay, fmtTime } from '../dates';
import { enqueue, pending, subscribe } from '../outbox';
import { useApp, useCached, useT } from '../state';
import { check, fmtReading, measuredAt } from '../validate';

export { fmtReading };

const BLANK = { sbp: '', dbp: '', pulse: '', mg_dl: '', context: 'fasting', kg: '' };

export default function ReadingsScreen() {
  const { me } = useApp();
  const t = useT();
  const history = useCached('/app/readings?days=30', 'shi_readings');
  const [type, setType] = useState('bp');
  const [adding, setAdding] = useState(false);
  const [f, setF] = useState(BLANK);
  const [when, setWhen] = useState('now');
  const [hhmm, setHhmm] = useState('');
  const [err, setErr] = useState(null);
  const [done, setDone] = useState(false);
  const [local, setLocal] = useState([]);

  useEffect(() => {
    pending('reading').then(setLocal);
    return subscribe(() => { pending('reading').then(setLocal); history.reload(true); });
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  const save = async () => {
    setErr(null);
    const [values, e] = check(type, f, t);
    if (e) return setErr(e);
    const at = measuredAt(when, hhmm);
    if (!at) return setErr(t.badTime);
    await enqueue('reading', { type, values, measured_at: at.toISOString(), source: 'manual' });
    setF(BLANK);
    setAdding(false);
    setDone(true);
    setTimeout(() => setDone(false), 3000);
  };

  const sent = history.data?.readings || [];
  const all = [...local.filter((r) => !sent.some((s) => s.client_uuid === r.client_uuid)), ...sent]
    .sort((a, b) => b.measured_at.localeCompare(a.measured_at));
  const set = (k) => (v) => setF({ ...f, [k]: v });

  return (
    <Screen refreshing={history.loading} onRefresh={() => history.reload()}>
      <Title>{t.readingsTitle}</Title>
      {done && <Banner kind="ok">✓ {t.saved}</Banner>}
      {me.read_only ? <Muted style={{ marginBottom: 10 }}>{t.readOnly}</Muted> : !adding ? (
        <Button title={`＋ ${t.addReading}`} onPress={() => { setAdding(true); setErr(null); setWhen('now'); setHhmm(''); }} />
      ) : (
        <Card>
          <Choice options={[['bp', t.bp], ['glucose', t.glucose], ['weight', t.weight]]} value={type} onChange={(v) => { setType(v); setErr(null); }} />
          {type === 'bp' && (
            <>
              <Field label={t.sbp} keyboardType="number-pad" maxLength={3} value={f.sbp} onChangeText={set('sbp')} />
              <Field label={t.dbp} keyboardType="number-pad" maxLength={3} value={f.dbp} onChangeText={set('dbp')} />
              <Field label={t.pulse} keyboardType="number-pad" maxLength={3} value={f.pulse} onChangeText={set('pulse')} />
            </>
          )}
          {type === 'glucose' && (
            <>
              <Field label={t.mgdl} keyboardType="number-pad" maxLength={3} value={f.mg_dl} onChangeText={set('mg_dl')} />
              <Choice options={Object.entries(t.context)} value={f.context} onChange={set('context')} />
            </>
          )}
          {type === 'weight' && <Field label={t.kg} keyboardType="decimal-pad" maxLength={5} value={f.kg} onChangeText={set('kg')} />}
          <Text style={{ fontSize: 16, fontWeight: '600', marginBottom: 6, color: COLORS.text }}>{t.when}</Text>
          <Choice options={[['now', t.now], ['earlier', t.earlierToday]]} value={when} onChange={setWhen} />
          {when === 'earlier' && <Field label={t.timeHHMM} placeholder="07:30" keyboardType="numbers-and-punctuation" maxLength={5} value={hhmm} onChangeText={setHhmm} />}
          {err ? <Banner kind="error">{err}</Banner> : null}
          <View style={{ flexDirection: 'row', gap: 10 }}>
            <Button style={{ flex: 2 }} title={t.save} onPress={save} />
            <Button style={{ flex: 1 }} kind="light" title={t.cancel} onPress={() => setAdding(false)} />
          </View>
        </Card>
      )}

      <H2>{t.history}</H2>
      {history.offline && <Banner kind="warn">{t.offlineShowingSaved}</Banner>}
      <Card>
        {all.length === 0 ? <Muted>{t.nothingYet}</Muted> : all.map((r) => (
          <View key={r.client_uuid} style={{ flexDirection: 'row', justifyContent: 'space-between', paddingVertical: 8, borderBottomWidth: 1, borderBottomColor: COLORS.line }}>
            <View style={{ flex: 1 }}>
              <Text style={{ fontSize: 17, color: COLORS.text }}>{t[r.type]}: <Text style={{ fontWeight: '700' }}>{fmtReading(r)}</Text></Text>
              <Muted>{fmtDay(r.measured_at, me.lang)} {fmtTime(r.measured_at)} · {r.source === 'manual' ? t.typedIn : `${t.fromDevice}${r.device ? ` (${r.device})` : ''}`}</Muted>
            </View>
            {!r.id && <Text accessibilityLabel={t.waitingToSend(1)} style={{ fontSize: 18 }}>⏳</Text>}
          </View>
        ))}
      </Card>
    </Screen>
  );
}
