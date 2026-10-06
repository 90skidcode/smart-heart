import assert from 'node:assert/strict';
import { test } from 'node:test';
import { check, fmtReading, measuredAt } from '../src/validate.js';

const t = { rangeBp: 'bp', rangeGlucose: 'glu', rangeWeight: 'wt' };

test('blood pressure limits match the server', () => {
  assert.deepEqual(check('bp', { sbp: '132', dbp: '84', pulse: '' }, t), [{ sbp: 132, dbp: 84 }, null]);
  assert.deepEqual(check('bp', { sbp: '132', dbp: '84', pulse: '70' }, t), [{ sbp: 132, dbp: 84, pulse: 70 }, null]);
  for (const f of [{ sbp: '59', dbp: '40' }, { sbp: '261', dbp: '90' }, { sbp: '80', dbp: '90' }, { sbp: '120', dbp: '' }, { sbp: '120', dbp: '80', pulse: '250' }]) {
    assert.equal(check('bp', { pulse: '', ...f }, t)[1], 'bp', JSON.stringify(f));
  }
});

test('sugar and weight', () => {
  assert.deepEqual(check('glucose', { mg_dl: '110', context: 'fasting' }, t), [{ mg_dl: 110, context: 'fasting' }, null]);
  assert.equal(check('glucose', { mg_dl: '700', context: 'random' }, t)[1], 'glu');
  assert.deepEqual(check('weight', { kg: '72,5' }, t), [{ kg: 72.5 }, null]); // comma decimal from some keyboards
  assert.equal(check('weight', { kg: '300' }, t)[1], 'wt');
});

test('measurement time', () => {
  const now = new Date(2026, 9, 8, 14, 30);
  assert.equal(measuredAt('now', '', now), now);
  assert.equal(measuredAt('earlier', '07:05', now).getHours(), 7);
  assert.equal(measuredAt('earlier', '15:00', now), null); // later than now
  assert.equal(measuredAt('earlier', '7.05', now), null);
});

test('display', () => {
  assert.equal(fmtReading({ type: 'bp', values: { sbp: 130, dbp: 80, pulse: 70 } }), '130/80 mmHg · 70/min');
  assert.equal(fmtReading({ type: 'glucose', values: { mg_dl: 95 } }), '95 mg/dL');
});
