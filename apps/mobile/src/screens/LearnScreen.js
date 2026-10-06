import { useState } from 'react';
import { Pressable, Text } from 'react-native';
import { Banner, Card, Choice, Muted, Screen, Title } from '../components/ui';
import { COLORS } from '../config';
import { useApp, useCached, useT } from '../state';

export default function LearnScreen() {
  const { lang } = useApp();
  const t = useT();
  const content = useCached(`/app/content?lang=${lang}`, `shi_content_${lang}`);
  const [type, setType] = useState('education');
  const [open, setOpen] = useState(null);
  const items = (content.data?.items || []).filter((c) => c.type === type);

  return (
    <Screen refreshing={content.loading} onRefresh={() => content.reload()}>
      <Title>{t.learnTitle}</Title>
      {content.offline && <Banner kind="warn">{t.offlineShowingSaved}</Banner>}
      <Choice options={[['education', t.education], ['faq', t.faq]]} value={type} onChange={(v) => { setType(v); setOpen(null); }} />
      {items.length === 0 ? <Card><Muted>{t.nothingYet}</Muted></Card> : items.map((c) => (
        <Card key={c.id}>
          <Pressable accessibilityRole="button" accessibilityState={{ expanded: open === c.id }} onPress={() => setOpen(open === c.id ? null : c.id)}>
            {c.category ? <Muted>{c.category}</Muted> : null}
            <Text style={{ fontSize: 19, fontWeight: '700', color: COLORS.navy }} lang={c.lang}>{open === c.id ? '▾ ' : '▸ '}{c.title}</Text>
            {lang === 'ta' && c.lang === 'en' ? <Muted>{t.englishOnly}</Muted> : null}
          </Pressable>
          {open === c.id && <Text style={{ fontSize: 17, lineHeight: 26, color: COLORS.text, marginTop: 10 }} lang={c.lang}>{c.body}</Text>}
        </Card>
      ))}
    </Screen>
  );
}
