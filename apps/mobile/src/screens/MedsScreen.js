import { Text, View } from 'react-native';
import { Banner, Card, Muted, Screen, Title } from '../components/ui';
import { COLORS } from '../config';
import { useCached, useT } from '../state';

export default function MedsScreen() {
  const t = useT();
  const meds = useCached('/app/medications', 'shi_meds');
  const items = meds.data?.items || [];
  const when = (m) => (m.frequency === 'As needed' ? t.asNeeded : m.frequency === 'Weekly' ? t.weekly : m.times.map((s) => t.slot[s]).join(' · '));

  return (
    <Screen refreshing={meds.loading} onRefresh={() => meds.reload()}>
      <Title>{t.medsTitle}</Title>
      {meds.offline && <Banner kind="warn">{t.offlineShowingSaved}</Banner>}
      {items.length === 0 ? <Card><Muted>{t.noMedsYet}</Muted></Card> : items.map((m) => (
        <Card key={m.key}>
          <Text style={{ fontSize: 20, fontWeight: '700', color: COLORS.navy }}>{m.drug}</Text>
          <Text style={{ fontSize: 17, color: COLORS.text, marginTop: 2 }}>{m.dose}</Text>
          <View style={{ marginTop: 8, backgroundColor: COLORS.tealLight, borderRadius: 8, padding: 10 }}>
            <Text style={{ fontSize: 16, color: COLORS.teal, fontWeight: '600' }}>{when(m)}</Text>
            {m.instructions ? <Text style={{ fontSize: 15, color: COLORS.text, marginTop: 4 }}>{m.instructions}</Text> : null}
          </View>
        </Card>
      ))}
      {meds.data?.version ? <Muted style={{ textAlign: 'center', marginTop: 8 }}>{t.medsFrom(meds.data.version)}</Muted> : null}
    </Screen>
  );
}
