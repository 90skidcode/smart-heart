import { useEffect, useState } from 'react';
import { Text, View } from 'react-native';
import { api } from '../api';
import { Banner, Button, Card, H2, Muted, Screen, Title } from '../components/ui';
import { COLORS, SLOTS } from '../config';
import { fmtTime, localDate } from '../dates';
import { clearNotice, enqueue, flush, pending, status, subscribe } from '../outbox';
import { useApp, useCached, useT } from '../state';
import { fmtReading } from './ReadingsScreen';

export default function TodayScreen() {
  const { me } = useApp();
  const t = useT();
  const date = localDate();
  const today = useCached(`/app/today?date=${date}`, `shi_today_${date}`);
  const [box, setBox] = useState({ pending: 0, rejected: [], notices: [] });
  const [local, setLocal] = useState({ doses: [], readings: [] });
  const [seenBusy, setSeenBusy] = useState(false);

  // Answers still waiting in the outbox are shown as if sent.
  const refreshLocal = async () => setLocal({ doses: await pending('dose'), readings: await pending('reading') });
  useEffect(() => {
    refreshLocal();
    status().then(setBox);
    return subscribe((s) => { setBox(s); refreshLocal(); today.reload(true); });
  }, []); // eslint-disable-line react-hooks/exhaustive-deps

  const answer = async (d, st) => {
    await enqueue('dose', { schedule_version: today.data?.schedule_version, med_key: d.med_key, date, slot: d.slot, status: st, answered_at: new Date().toISOString() });
  };
  const doses = (today.data?.doses || []).map((d) => {
    const mine = local.doses.filter((x) => x.med_key === d.med_key && x.slot === d.slot && x.date === date).pop();
    return mine ? { ...d, status: mine.status } : d;
  });
  const sent = today.data?.readings || [];
  const readings = [...sent, ...local.readings.filter((r) => localDate(new Date(r.measured_at)) === date && !sent.some((x) => x.client_uuid === r.client_uuid))];

  const markSeen = async () => {
    setSeenBusy(true);
    try {
      await api('/app/seen', { method: 'POST', body: { date } });
      await today.reload();
    } catch {
      // offline: the button stays available
    } finally {
      setSeenBusy(false);
    }
  };

  return (
    <Screen refreshing={today.loading} onRefresh={() => { flush(); today.reload(); }}>
      <Title>{me.read_only ? t.caregiverFor(me.participant_name) : t.hello(me.name)}</Title>
      {me.read_only && <Muted style={{ marginBottom: 10 }}>{t.readOnly}</Muted>}
      {today.offline && <Banner kind="warn">{t.offlineShowingSaved}</Banner>}
      {box.notices.map((n) => (
        <View key={n.id}>
          <Banner kind="error">{n.flag === 'low' ? t.readingLow : t.readingHigh}</Banner>
          <Button title={t.ok} small kind="light" onPress={() => clearNotice(n.id)} style={{ marginBottom: 12 }} />
        </View>
      ))}

      <H2>{t.todayMeds}</H2>
      {doses.length === 0 ? <Card><Muted>{t.noMedsYet}</Muted></Card> : SLOTS.map((slot) => {
        const list = doses.filter((d) => d.slot === slot);
        if (!list.length) return null;
        return (
          <Card key={slot}>
            <Text style={{ fontSize: 18, fontWeight: '700', color: COLORS.navy, marginBottom: 8 }}>{t.slot[slot]}{list[0].time ? ` · ${list[0].time}` : ''}</Text>
            {list.map((d) => (
              <View key={d.med_key} style={{ paddingVertical: 10, borderTopWidth: 1, borderTopColor: COLORS.line }}>
                <Text style={{ fontSize: 18, fontWeight: '600', color: COLORS.text }}>{d.drug} <Text style={{ fontWeight: '400' }}>{d.dose}</Text></Text>
                {d.instructions ? <Muted>{d.instructions}</Muted> : null}
                {d.status ? (
                  <View style={{ flexDirection: 'row', alignItems: 'center', justifyContent: 'space-between', marginTop: 8 }}>
                    <Text style={{ fontSize: 17, fontWeight: '700', color: d.status === 'taken' ? COLORS.ok : '#8B5B00' }}>
                      {d.status === 'taken' ? `✓ ${t.taken}` : `– ${t.skipped}`}</Text>
                    {!me.read_only && <Button small kind="ghost" title={t.undo} onPress={() => answer(d, d.status === 'taken' ? 'skipped' : 'taken')} />}
                  </View>
                ) : me.read_only ? <Muted style={{ marginTop: 6 }}>{t.notAnswered}</Muted> : (
                  <View style={{ flexDirection: 'row', gap: 10, marginTop: 8 }}>
                    <Button style={{ flex: 2 }} title={`✓ ${t.markTaken}`} onPress={() => answer(d, 'taken')} accessibilityLabel={`${d.drug} ${t.markTaken}`} />
                    <Button style={{ flex: 1 }} kind="light" title={t.markSkipped} onPress={() => answer(d, 'skipped')} accessibilityLabel={`${d.drug} ${t.markSkipped}`} />
                  </View>
                )}
              </View>
            ))}
          </Card>
        );
      })}

      <H2>{t.todayReadings}</H2>
      <Card>
        {readings.length === 0 ? <Muted>{t.noReadingsToday}</Muted> : readings.map((r) => (
          <Text key={r.client_uuid} style={{ fontSize: 17, paddingVertical: 4, color: COLORS.text }}>{fmtTime(r.measured_at)} · {t[r.type]}: {fmtReading(r)}</Text>
        ))}
      </Card>

      {me.read_only && (
        <Card>
          {(today.data?.seen_by || []).map((s) => <Muted key={s.seen_at}>✓ {t.seenBy(s.name, fmtTime(s.seen_at))}</Muted>)}
          {!(today.data?.seen_by || []).some((s) => s.name === me.name) && <Button title={t.markSeen} onPress={markSeen} busy={seenBusy} style={{ marginTop: 8 }} />}
        </Card>
      )}

      <Muted style={{ marginTop: 12, textAlign: 'center' }}>
        {box.pending ? t.waitingToSend(box.pending) : t.allSent}{today.updatedAt ? ` · ${t.lastUpdated(fmtTime(today.updatedAt))}` : ''}
      </Muted>
    </Screen>
  );
}
