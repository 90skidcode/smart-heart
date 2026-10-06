// App-side cross-check: caseNumber.ts must agree with CaseNumber.php on every vector.
// Run after run_tests.php:  node --experimental-strip-types crosscheck.ts
import { readFileSync } from 'node:fs';
import { checkCaseNumber, checkCharacter } from './caseNumber.ts';

type Vectors = {
  fixed: { body: string; check: string }[];
  valid: { input: string; body: string; check: string }[];
  invalid: { input: string }[];
};
const v: Vectors = JSON.parse(readFileSync(new URL('./case_number_vectors.json', import.meta.url), 'utf8'));

let pass = 0;
let fail = 0;
const check = (id: string, ok: boolean, msg = '') => {
  if (ok) pass++;
  else {
    fail++;
    console.log(`FAIL ${id} ${msg}`);
  }
};

for (const f of v.fixed) check(`fixed ${f.body}`, checkCharacter(f.body) === f.check);
for (const x of v.valid) {
  check(`valid ${x.input}`, checkCaseNumber(x.input).ok && checkCharacter(x.body) === x.check);
}
for (const x of v.invalid) check(`invalid ${x.input}`, !checkCaseNumber(x.input).ok);

const reason = (s: string) => {
  const r = checkCaseNumber(s);
  return r.ok ? 'ok' : r.reason;
};
check('reason look_alike', reason('K7Q-M3O-PDX') === 'look_alike');
check('reason too_short', reason('K7Q-M3X-PD') === 'too_short');
check('reason too_long', reason('K7Q-M3X-PDXX') === 'too_long');
check('reason typo', reason('K7Q-M3X-PD7') === 'typo');
check('reason not_allowed', reason('K7Q-M3X-PD#') === 'not_allowed');
check('lower case and spaces', reason(' k7q m3x-pdx ') === 'ok');
const okResult = checkCaseNumber('k7qm3xpdx');
check('display format', okResult.ok && okResult.display === 'K7Q-M3X-PDX');

console.log(`${pass} passed, ${fail} failed`);
process.exit(fail === 0 ? 0 : 1);
