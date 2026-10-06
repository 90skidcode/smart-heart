// Case-number checks for the SHI app (React Native). Mirrors CaseNumber.php.
// The app only checks the format and the check character, so a typo is caught
// on the phone and never counts as a failed sign-in. The server decides the rest.

export const ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';
export const RANDOM_LENGTH = 8;
export const LENGTH = 9;
const LOOK_ALIKES = /[01ILOU]/;

export type CaseNumberCheck =
  | { ok: true; normalised: string; display: string }
  | { ok: false; reason: 'too_short' | 'too_long' | 'look_alike' | 'not_allowed' | 'typo' };

export function normalise(input: string): string {
  return input.replace(/[\s-]+/g, '').toUpperCase();
}

export function checkCharacter(body: string): string {
  const n = ALPHABET.length;
  let factor = 2;
  let sum = 0;
  for (let i = body.length - 1; i >= 0; i--) {
    const codePoint = ALPHABET.indexOf(body[i]);
    if (codePoint < 0) throw new Error(`Character outside the case-number alphabet: ${body[i]}`);
    const addend = factor * codePoint;
    factor = factor === 2 ? 1 : 2;
    sum += Math.floor(addend / n) + (addend % n);
  }
  return ALPHABET[(n - (sum % n)) % n];
}

export function format(normalised: string): string {
  return normalised.match(/.{1,3}/g)?.join('-') ?? '';
}

/** What the sign-in screen calls before it sends anything. */
export function checkCaseNumber(input: string): CaseNumberCheck {
  const s = normalise(input);
  if (LOOK_ALIKES.test(s)) return { ok: false, reason: 'look_alike' };
  if ([...s].some((c) => !ALPHABET.includes(c))) return { ok: false, reason: 'not_allowed' };
  if (s.length < LENGTH) return { ok: false, reason: 'too_short' };
  if (s.length > LENGTH) return { ok: false, reason: 'too_long' };
  if (checkCharacter(s.slice(0, RANDOM_LENGTH)) !== s[RANDOM_LENGTH]) return { ok: false, reason: 'typo' };
  return { ok: true, normalised: s, display: format(s) };
}
