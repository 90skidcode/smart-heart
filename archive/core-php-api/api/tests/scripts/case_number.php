<?php

declare(strict_types=1);

// Tests for CaseNumber.php. Run: composer test:case-number
// Writes packages/contracts/case-number/case_number_vectors.json for the app-side cross-check (crosscheck.ts).

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use SmartHeart\Auth\CaseNumber as CN;

$pass = 0;
$fail = 0;
function check(string $id, bool $ok, string $msg = ''): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
    } else {
        $fail++;
        echo "FAIL $id $msg\n";
    }
}

/** Independent check: the "validate" form of Luhn mod N over the full string must sum to 0 mod N. */
function luhnValidateFull(string $s): bool
{
    $a = CN::ALPHABET;
    $n = strlen($a);
    $factor = 1;
    $sum = 0;
    for ($i = strlen($s) - 1; $i >= 0; $i--) {
        $cp = strpos($a, $s[$i]);
        if ($cp === false) {
            return false;
        }
        $addend = $factor * $cp;
        $factor = $factor === 2 ? 1 : 2;
        $sum += intdiv($addend, $n) + ($addend % $n);
    }
    return $sum % $n === 0;
}

// N1-N3 · hand-computed check characters
check('N1', CN::checkCharacter('22222222') === '2', 'all-zero body → 2');
check('N2', CN::checkCharacter('2222222Z') === '3', 'Z (29) doubled = 58 → 1 + 28 = 29 → check 1 = "3"');
check('N3', CN::checkCharacter('K7QM3XPD') === 'X', 'worked example K7Q-M3X-PDX');
check('N3b', luhnValidateFull('K7QM3XPDX'), 'worked example validates in full');

// N4 · 10,000 generated numbers: shape, check character, independent validation
$alpha = preg_quote(CN::ALPHABET, '/');
$re = "/^[$alpha]{3}-[$alpha]{3}-[$alpha]{3}$/";
$seen = [];
$counts = array_fill_keys(str_split(CN::ALPHABET), 0);
$shapeOk = true;
$wellFormedOk = true;
$independentOk = true;
for ($i = 0; $i < 10000; $i++) {
    $c = CN::generate();
    $shapeOk = $shapeOk && preg_match($re, $c) === 1;
    $wellFormedOk = $wellFormedOk && CN::isWellFormed($c);
    $independentOk = $independentOk && luhnValidateFull(CN::normalise($c));
    $seen[$c] = ($seen[$c] ?? 0) + 1;
    foreach (str_split(substr(CN::normalise($c), 0, 8)) as $ch) {
        $counts[$ch]++;
    }
}
check('N4a', $shapeOk, 'format XXX-XXX-XXX from the alphabet');
check('N4b', $wellFormedOk, 'every generated number is well formed');
check('N4c', $independentOk, 'every generated number passes the independent Luhn mod 30 check');

// N5 · random characters are uniform: each symbol within ±10% of 80,000 / 30
$expected = 80000 / 30;
$worst = 0.0;
foreach ($counts as $n) {
    $worst = max($worst, abs($n - $expected) / $expected);
}
check('N5', $worst < 0.10, sprintf('worst symbol deviation %.1f%%', $worst * 100));

// N6 · collisions are negligible (expected ≈ 0.00008 in 10,000 draws)
$dupes = count(array_filter($seen, fn($k) => $k > 1));
check('N6', $dupes <= 1, "$dupes duplicate(s)");

// N7 · every single-character substitution is caught (200 numbers × 9 positions × 29 alternatives)
$missed = 0;
$tried = 0;
for ($i = 0; $i < 200; $i++) {
    $s = CN::normalise(CN::generate());
    for ($p = 0; $p < 9; $p++) {
        foreach (str_split(CN::ALPHABET) as $ch) {
            if ($ch === $s[$p]) {
                continue;
            }
            $t = $s;
            $t[$p] = $ch;
            $tried++;
            if (CN::isWellFormed($t)) {
                $missed++;
            }
        }
    }
}
check('N7', $missed === 0, "$missed of $tried substitutions slipped through");

// N8 · adjacent swaps are caught, except the one pair Luhn mod 30 cannot see: 2 ↔ Z
$missedOther = 0;
$missed2Z = 0;
$swaps = 0;
for ($i = 0; $i < 500; $i++) {
    $s = CN::normalise(CN::generate());
    for ($p = 0; $p < 8; $p++) {
        if ($s[$p] === $s[$p + 1]) {
            continue;
        }
        $t = $s;
        [$t[$p], $t[$p + 1]] = [$s[$p + 1], $s[$p]];
        $swaps++;
        if (CN::isWellFormed($t)) {
            $pair = [$s[$p], $s[$p + 1]];
            sort($pair);
            if ($pair === ['2', 'Z']) {
                $missed2Z++;
            } else {
                $missedOther++;
            }
        }
    }
}
check('N8a', $missedOther === 0, "$missedOther of $swaps adjacent swaps slipped through");
check('N8b', CN::checkCharacter('2Z222222') === CN::checkCharacter('Z2222222'), 'documented blind spot: swapping 2 and Z is not detected');

// N9 · normalising what people type
check('N9a', CN::normalise(' k7q-m3x pdx ') === 'K7QM3XPDX');
check('N9b', CN::isWellFormed(' k7q-m3x pdx '), 'lower case, spaces and hyphens are accepted');
check('N9c', CN::format('K7QM3XPDX') === 'K7Q-M3X-PDX');

// N10 · rejected inputs
$bad = [
    'K7Q-M3X-PD' => 'too short',
    'K7Q-M3X-PDXX' => 'too long',
    'K7Q-M3O-PDX' => 'letter O',
    'K7Q-M30-PDX' => 'digit 0',
    'K7Q-M3I-PDX' => 'letter I',
    'K7Q-M31-PDX' => 'digit 1',
    'K7Q-M3L-PDX' => 'letter L',
    'K7Q-M3U-PDX' => 'letter U',
    'K7Q-M3X-PD7' => 'wrong check character',
    '' => 'empty',
    'K7Q-M3X-PD#' => 'symbol',
];
foreach ($bad as $in => $why) {
    check('N10 ' . $why, CN::isWellFormed($in) === false);
}

// N11 · HMAC for storage and look-up
$pepper = random_bytes(32);
$h1 = CN::hmac('K7Q-M3X-PDX', $pepper);
check('N11a', strlen($h1) === 32, '32 bytes for BINARY(32)');
check('N11b', $h1 === CN::hmac(' k7qm3x-pdx', $pepper), 'same number typed differently → same HMAC');
check('N11c', $h1 !== CN::hmac('K7Q-M3X-PDX', random_bytes(32)), 'a different pepper → a different HMAC');
check('N11d', $h1 !== CN::hmac('2222222Z3', $pepper), 'different numbers → different HMACs');
$threw = false;
try {
    CN::hmac('K7Q-M3X-PDX', '');
} catch (InvalidArgumentException) {
    $threw = true;
}
check('N11e', $threw, 'an empty pepper is refused');

// N12 · staff hint and N13 · alphabet guard
check('N12', CN::hint('K7Q-M3X-PDX') === 'PDX');
$threw = false;
try {
    CN::checkCharacter('K7QM3OPD');
} catch (InvalidArgumentException) {
    $threw = true;
}
check('N13', $threw, 'a character outside the alphabet throws');

// Vectors for the app-side cross-check
$vectors = [
    'fixed' => [
        ['body' => '22222222', 'check' => '2'],
        ['body' => '2222222Z', 'check' => '3'],
        ['body' => 'K7QM3XPD', 'check' => 'X'],
    ],
    'valid' => [],
    'invalid' => [],
];
for ($i = 0; $i < 300; $i++) {
    $c = CN::generate();
    $s = CN::normalise($c);
    $vectors['valid'][] = ['input' => $c, 'body' => substr($s, 0, 8), 'check' => $s[8]];
    $p = random_int(0, 8);
    do {
        $ch = CN::ALPHABET[random_int(0, 29)];
    } while ($ch === $s[$p]);
    $s[$p] = $ch;
    $vectors['invalid'][] = ['input' => CN::format($s)];
}
file_put_contents(dirname(__DIR__, 4) . '/packages/contracts/case-number/case_number_vectors.json', json_encode($vectors, JSON_PRETTY_PRINT));

echo "Example: " . CN::generate() . "\n";
echo "$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
