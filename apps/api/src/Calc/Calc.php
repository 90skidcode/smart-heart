<?php
/**
 * SMART-HEART reference calculation library (PHP 8.3, no dependencies).
 *
 * One pure function per calculation in the SMART-HEART Calculation Specification.
 * The production API must call these functions (or a port that passes the same
 * test vectors) — never re-implement a formula in the web or mobile client.
 *
 * Conventions
 *  - Dates are 'YYYY-MM-DD' calendar dates in Asia/Kolkata.
 *  - Rounding: half away from zero (PHP round()), applied BEFORE classification.
 *  - Missing input → null result with a reason; missing is never scored as 0.
 *  - Anything marked PROPOSED is a default that needs PI / statistician sign-off.
 */
declare(strict_types=1);

namespace SmartHeart\Calc;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

final class Calc
{
    public const VERSION = '1.0.0-draft';
    public const TZ = 'Asia/Kolkata';

    // ───────────────────────────── helpers ─────────────────────────────

    public static function r(float $v, int $dp = 0): float
    {
        return round($v, $dp, PHP_ROUND_HALF_UP); // half away from zero
    }

    private static function date(string $ymd): DateTimeImmutable
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) {
            throw new InvalidArgumentException("Bad date: $ymd");
        }
        return new DateTimeImmutable($ymd . ' 00:00:00', new DateTimeZone(self::TZ));
    }

    /** IST calendar date of a UTC timestamp, e.g. '2026-10-02T19:00:00Z' → '2026-10-03'. */
    public static function localDate(string $utcTimestamp): string
    {
        $t = new DateTimeImmutable($utcTimestamp, new DateTimeZone('UTC'));
        return $t->setTimezone(new DateTimeZone(self::TZ))->format('Y-m-d');
    }

    /** Calendar days from $from to $to (Day 0 = $from). Negative if $to is earlier. */
    public static function daysBetween(string $from, string $to): int
    {
        $diff = self::date($from)->diff(self::date($to));
        return $diff->invert ? -$diff->days : $diff->days;
    }

    /** Completed years on $on. 29 Feb birthdays count as reached on 1 Mar in non-leap years. */
    public static function ageYears(string $dob, string $on): int
    {
        if (self::daysBetween($dob, $on) < 0) {
            throw new InvalidArgumentException('Date of birth is after the reference date');
        }
        return self::date($dob)->diff(self::date($on))->y;
    }

    private static function mean(array $xs): ?float
    {
        return $xs ? array_sum($xs) / count($xs) : null;
    }

    // ─────────────────────── SCR-01 eligibility (ELIG-1.0) ───────────────────────

    /**
     * Evaluates screens 1–8 in order. The first exclusion stops evaluation
     * (later criteria = not_evaluated). 'unknown' answers hold the result at pending.
     * Statuses: pass | fail | pending | n/a | not_evaluated
     */
    public static function eligibility(array $s): array
    {
        $c = [];
        $stop = null;
        $add = function (int $screen, string $code, string $label, string $status, string $evidence) use (&$c, &$stop) {
            if ($stop !== null) {
                $status = 'not_evaluated';
                $evidence = 'stopped at screen ' . $stop;
            }
            $c[] = compact('screen', 'code', 'label', 'status', 'evidence');
            if ($status === 'fail' && $stop === null) {
                $stop = $screen;
            }
        };
        $yn = fn($v) => $v === null ? 'pending' : ($v === 'yes' ? 'fail' : ($v === 'unknown' ? 'pending' : 'pass'));

        // Screen 1 · age ≥ 18 on screening date
        if (empty($s['dob']) || empty($s['screening_date'])) {
            $add(1, 'AGE_GE_18', 'Age ≥ 18 years', 'pending', 'date of birth or screening date missing');
        } else {
            $age = self::ageYears($s['dob'], $s['screening_date']);
            $add(1, 'AGE_GE_18', 'Age ≥ 18 years', $age >= 18 ? 'pass' : 'fail', "$age years");
        }

        // Screen 2 · ACS (with subtype) or stable IHD
        $dx = $s['index_diagnosis'] ?? null;
        if ($dx === null) {
            $add(2, 'DX_ACS_OR_SIHD', 'ACS or stable IHD', 'pending', 'diagnosis missing');
        } elseif ($dx === 'acs' && empty($s['acs_subtype'])) {
            $add(2, 'DX_ACS_OR_SIHD', 'ACS or stable IHD', 'pending', 'ACS subtype missing');
        } else {
            $ok = in_array($dx, ['acs', 'stable_ihd'], true);
            $add(2, 'DX_ACS_OR_SIHD', 'ACS or stable IHD', $ok ? 'pass' : 'fail', $dx . ($dx === 'acs' ? ' / ' . $s['acs_subtype'] : ''));
        }

        // Screen 3 · PCI done, 0–30 days before screening
        if (($s['pci_done'] ?? null) === false) {
            $add(3, 'PCI_LE_30D', 'PCI within 30 days', 'fail', 'no PCI');
        } elseif (empty($s['pci_date']) || empty($s['screening_date'])) {
            $add(3, 'PCI_LE_30D', 'PCI within 30 days', 'pending', 'PCI date missing');
        } else {
            $d = self::daysBetween($s['pci_date'], $s['screening_date']);
            if ($d < 0) {
                throw new InvalidArgumentException('PCI date is after the screening date');
            }
            $add(3, 'PCI_LE_30D', 'PCI within 30 days', $d <= 30 ? 'pass' : 'fail', "$d days");
        }

        // Screen 4 · CABG
        $cabg = $s['cabg_history'] ?? null;
        $add(4, 'NO_CABG', 'No previous or current CABG', $cabg === null ? 'pending' : ($cabg ? 'fail' : 'pass'), $cabg === null ? 'missing' : ($cabg ? 'CABG' : 'none'));

        // Screen 5 · LVEF and high-risk conditions
        $ef = $s['lvef_pct'] ?? null;
        $add(5, 'LVEF_GE_40', 'LVEF ≥ 40%', $ef === null ? 'pending' : ($ef < 40 ? 'fail' : 'pass'), $ef === null ? 'missing' : "LVEF $ef%");
        foreach (['prior_cardiac_arrest' => 'No previous cardiac arrest', 'complex_vent_arrhythmia' => 'No complex ventricular arrhythmia', 'cardiogenic_shock' => 'No cardiogenic shock'] as $k => $label) {
            $add(5, strtoupper($k), $label, $yn($s[$k] ?? null), $s[$k] ?? 'missing');
        }

        // Screen 6 · diabetes complications (only if T2DM), eGFR, BP
        $dm = $s['t2dm'] ?? null;
        foreach (['retinopathy' => 'No diabetic retinopathy', 'peripheral_neuropathy' => 'No diabetic neuropathy', 'diabetic_foot_ulcer' => 'No diabetic foot ulcer'] as $k => $label) {
            if ($dm === false) {
                $add(6, strtoupper($k), $label, 'n/a', 'not diabetic');
            } elseif ($dm === null) {
                $add(6, strtoupper($k), $label, 'pending', 'diabetes status missing');
            } else {
                $add(6, strtoupper($k), $label, $yn($s[$k] ?? null), $s[$k] ?? 'missing');
            }
        }
        $egfr = $s['egfr'] ?? null;
        $add(6, 'EGFR_GE_45', 'eGFR ≥ 45 mL/min/1.73m²', $egfr === null ? 'pending' : ($egfr < 45 ? 'fail' : 'pass'), $egfr === null ? 'missing' : "eGFR $egfr");
        $sbp = $s['sbp'] ?? null;
        $dbp = $s['dbp'] ?? null;
        if ($sbp === null || $dbp === null) {
            $add(6, 'BP_CONTROLLED', 'Not SBP ≥ 160 or DBP ≥ 100 despite treatment', 'pending', 'BP missing');
        } else {
            $high = $sbp >= 160 || $dbp >= 100;
            $treated = $s['on_antihypertensive'] ?? null;
            if (!$high) {
                $add(6, 'BP_CONTROLLED', 'Not SBP ≥ 160 or DBP ≥ 100 despite treatment', 'pass', "$sbp/$dbp");
            } elseif ($treated === true) {
                $add(6, 'BP_CONTROLLED', 'Not SBP ≥ 160 or DBP ≥ 100 despite treatment', 'fail', "$sbp/$dbp on treatment");
            } else {
                $add(6, 'BP_CONTROLLED', 'Not SBP ≥ 160 or DBP ≥ 100 despite treatment', 'pending', "$sbp/$dbp, treatment status " . ($treated === false ? 'untreated: PI review' : 'missing'));
            }
        }

        // Screen 7 · sensory / cognitive
        foreach (['visual_impairment' => 'No unsafe visual impairment', 'hearing_impairment' => 'No unsafe hearing impairment', 'cognitive_impairment' => 'No unsafe cognitive impairment'] as $k => $label) {
            $v = $s[$k] ?? null;
            $add(7, strtoupper($k), $label, $v === null ? 'pending' : (str_ends_with($v, 'unsafe') ? 'fail' : 'pass'), $v ?? 'missing');
        }

        // Screen 8 · digital access
        $phone = $s['smartphone_access'] ?? null;
        $os = $s['phone_os'] ?? null;
        $status = $phone === null ? 'pending' : (!$phone ? 'fail' : (in_array($os, ['android', 'ios'], true) ? 'pass' : 'pending'));
        $add(8, 'DIGITAL_ACCESS', 'Compatible smartphone (self or household)', $status, $phone === null ? 'missing' : ($phone ? ($os ?? 'OS missing') : 'no smartphone'));

        $statuses = array_column($c, 'status');
        $result = in_array('fail', $statuses, true) ? 'ineligible' : (in_array('pending', $statuses, true) ? 'pending' : 'eligible');
        return [
            'engine' => 'ELIG-1.0',
            'result' => $result,
            'stop_at_screen' => $stop,
            'failures' => array_values(array_map(fn($x) => $x['code'], array_filter($c, fn($x) => $x['status'] === 'fail'))),
            'pending' => array_values(array_map(fn($x) => $x['code'], array_filter($c, fn($x) => $x['status'] === 'pending'))),
            'criteria' => $c,
        ];
    }

    // ─────────────────────────── baseline clinical ───────────────────────────

    public static function bmi(float $heightCm, float $weightKg): float
    {
        if ($heightCm < 120 || $heightCm > 220 || $weightKg < 30 || $weightKg > 250) {
            throw new InvalidArgumentException('Height or weight outside plausible range');
        }
        return self::r($weightKg / (($heightCm / 100) ** 2), 1);
    }

    /** PROPOSED Asian / Indian cut-offs (WHO expert consultation 2004; Indian consensus 2009). */
    public static function bmiCategoryAsian(float $bmi): string
    {
        return match (true) {
            $bmi < 18.5 => 'underweight',
            $bmi < 23.0 => 'normal',
            $bmi < 25.0 => 'overweight',
            default => 'obese',
        };
    }

    /** Clinic BP: mean of ≥ 2 readings, 1 dp. $readings = [[sbp, dbp], ...] */
    public static function meanBp(array $readings): array
    {
        if (count($readings) < 2) {
            throw new InvalidArgumentException('At least two readings are required');
        }
        foreach ($readings as [$s, $d]) {
            if ($s < 60 || $s > 260 || $d < 30 || $d > 160 || $d >= $s) {
                throw new InvalidArgumentException("Implausible BP $s/$d");
            }
        }
        return [
            'sbp' => self::r(self::mean(array_column($readings, 0)), 1),
            'dbp' => self::r(self::mean(array_column($readings, 1)), 1),
        ];
    }

    /** Lab unit table: canonical = value × factor + offset. */
    public const LABS = [
        'HBA1C' => ['canonical' => '%', 'alt' => ['mmol/mol' => [0.09148, 2.152]], 'range' => [3.5, 18]],
        'LDL' => ['canonical' => 'mg/dL', 'alt' => ['mmol/L' => [38.67, 0]], 'range' => [10, 400]],
        'TC' => ['canonical' => 'mg/dL', 'alt' => ['mmol/L' => [38.67, 0]], 'range' => [50, 500]],
        'HDL' => ['canonical' => 'mg/dL', 'alt' => ['mmol/L' => [38.67, 0]], 'range' => [10, 150]],
        'TG' => ['canonical' => 'mg/dL', 'alt' => ['mmol/L' => [88.57, 0]], 'range' => [20, 2000]],
        'CREAT' => ['canonical' => 'mg/dL', 'alt' => ['µmol/L' => [1 / 88.42, 0]], 'range' => [0.2, 15]],
        'FBG' => ['canonical' => 'mg/dL', 'alt' => ['mmol/L' => [18.016, 0]], 'range' => [40, 600]],
        'PPBG' => ['canonical' => 'mg/dL', 'alt' => ['mmol/L' => [18.016, 0]], 'range' => [40, 700]],
        'EGFR' => ['canonical' => 'mL/min/1.73m²', 'alt' => [], 'range' => [5, 150]],
    ];

    public static function convertLab(string $code, float $value, string $unit): array
    {
        $t = self::LABS[$code] ?? throw new InvalidArgumentException("Unknown lab $code");
        if ($unit === $t['canonical']) {
            $v = $value;
        } elseif (isset($t['alt'][$unit])) {
            [$f, $o] = $t['alt'][$unit];
            $v = $value * $f + $o;
        } else {
            throw new InvalidArgumentException("Unit $unit not accepted for $code");
        }
        $dp = in_array($code, ['HBA1C', 'CREAT'], true) ? 2 : 1;
        $v = self::r($v, $dp);
        [$lo, $hi] = $t['range'];
        if ($v < $lo || $v > $hi) {
            throw new InvalidArgumentException("$code $v {$t['canonical']} outside plausible range $lo–$hi");
        }
        return ['value' => $v, 'unit' => $t['canonical']];
    }

    /** Friedewald LDL-C (mg/dL). Not valid when TG ≥ 400 mg/dL → null. */
    public static function ldlFriedewald(float $tc, float $hdl, float $tg): ?float
    {
        return $tg >= 400 ? null : self::r($tc - $hdl - $tg / 5, 1);
    }

    public static function nonHdl(float $tc, float $hdl): float
    {
        return self::r($tc - $hdl, 1);
    }

    /** CKD-EPI 2021 race-free creatinine equation. $scr in mg/dL. Returns whole number. */
    public static function egfrCkdEpi2021(float $scr, int $age, string $sex): int
    {
        $female = $sex === 'F';
        $k = $female ? 0.7 : 0.9;
        $a = $female ? -0.241 : -0.302;
        $v = 142 * min($scr / $k, 1) ** $a * max($scr / $k, 1) ** -1.200 * 0.9938 ** $age * ($female ? 1.012 : 1.0);
        return (int) self::r($v);
    }

    /** PROPOSED: flag an entered eGFR that differs from the CKD-EPI value by more than $tolPct %. */
    public static function egfrConsistency(float $entered, int $computed, float $tolPct = 15.0): array
    {
        $pct = self::r(abs($entered - $computed) / $computed * 100, 1);
        return ['difference_pct' => $pct, 'flag' => $pct > $tolPct];
    }

    /** 6MWT category as drawn in the clinician portal: <350 poor, 350–500 intermediate, >500 ideal. */
    public static function sixMwtCategory(int $metres): string
    {
        return $metres < 350 ? 'poor' : ($metres <= 500 ? 'intermediate' : 'ideal');
    }

    /** LVEF band (universal definition of HF 2021): ≤40 reduced, 41–49 mildly reduced, ≥50 preserved. */
    public static function efBand(int $ef): string
    {
        return $ef >= 50 ? 'preserved' : ($ef >= 41 ? 'mildly_reduced' : 'reduced');
    }

    // ─────────────────────────── questionnaires ───────────────────────────

    /**
     * Generic 0–3 sum score with optional prorating.
     * $prorateMaxMissing = 0 means no prorating (PROPOSED default until the SAP decides).
     */
    private static function sumScore(array $answers, int $items, int $prorateMaxMissing): array
    {
        $vals = [];
        for ($i = 1; $i <= $items; $i++) {
            $v = $answers[(string) $i] ?? null;
            if ($v !== null && (!is_int($v) || $v < 0 || $v > 3)) {
                throw new InvalidArgumentException("Item $i must be 0–3");
            }
            if ($v !== null) {
                $vals[] = $v;
            }
        }
        $missing = $items - count($vals);
        if ($missing === 0) {
            return ['total' => array_sum($vals), 'missing' => 0, 'prorated' => false];
        }
        if ($missing <= $prorateMaxMissing) {
            return ['total' => (int) self::r(array_sum($vals) * $items / count($vals)), 'missing' => $missing, 'prorated' => true];
        }
        return ['total' => null, 'missing' => $missing, 'prorated' => false];
    }

    public static function phq9(array $answers, int $prorateMaxMissing = 0): array
    {
        $s = self::sumScore($answers, 9, $prorateMaxMissing);
        $t = $s['total'];
        $item9 = $answers['9'] ?? null;
        return $s + [
            'max' => 27,
            'severity' => $t === null ? null : match (true) {
                $t <= 4 => 'minimal', $t <= 9 => 'mild', $t <= 14 => 'moderate', $t <= 19 => 'moderately_severe', default => 'severe',
            },
            // Item 9 is evaluated even when the total cannot be computed.
            'item9_positive' => $item9 === null ? null : $item9 > 0,
            'clinical_review' => $t === null ? null : $t >= 10,
            'function_item' => $answers['F'] ?? null, // stored, never added to the total
        ];
    }

    public static function gad7(array $answers, int $prorateMaxMissing = 0): array
    {
        $s = self::sumScore($answers, 7, $prorateMaxMissing);
        $t = $s['total'];
        return $s + [
            'max' => 21,
            'severity' => $t === null ? null : match (true) {
                $t <= 4 => 'minimal', $t <= 9 => 'mild', $t <= 14 => 'moderate', default => 'severe',
            },
            'clinical_review' => $t === null ? null : $t >= 10,
            'function_item' => $answers['F'] ?? null,
        ];
    }

    public const DASI_WEIGHTS = [2.75, 1.75, 2.75, 5.50, 8.00, 2.70, 3.50, 8.00, 4.50, 5.25, 6.00, 7.50];

    /** DASI (Hlatky 1989). $yes = 12 values true/false (null = missing → score null). */
    public static function dasi(array $yes): array
    {
        if (count($yes) !== 12 || in_array(null, $yes, true)) {
            return ['score' => null, 'vo2peak' => null, 'mets' => null, 'reason' => 'all 12 items required'];
        }
        $score = 0.0;
        foreach (self::DASI_WEIGHTS as $i => $w) {
            $score += $yes[$i] ? $w : 0;
        }
        $score = self::r($score, 2);
        $vo2 = 0.43 * $score + 9.6;              // mL/kg/min
        return ['score' => $score, 'max' => 58.2, 'vo2peak' => self::r($vo2, 1), 'mets' => self::r($vo2 / 3.5, 1)];
    }

    /** EQ-5D-5L: 5 levels (1–5) + VAS 0–100 that the participant must set (no default). */
    public static function eq5d5l(array $levels, ?int $vas, ?array $valueSet = null): array
    {
        if (count($levels) !== 5 || in_array(null, $levels, true)) {
            return ['health_state' => null, 'vas' => $vas, 'utility' => null, 'reason' => 'all 5 dimensions required'];
        }
        foreach ($levels as $l) {
            if (!is_int($l) || $l < 1 || $l > 5) {
                throw new InvalidArgumentException('Levels must be 1–5');
            }
        }
        if ($vas !== null && ($vas < 0 || $vas > 100)) {
            throw new InvalidArgumentException('VAS must be 0–100');
        }
        $state = implode('', $levels);
        return [
            'health_state' => $state,
            'vas' => $vas, // null = not answered; never default to 50
            'utility' => $valueSet[$state] ?? null, // India value set (Jyani 2022), loaded under EuroQol licence
            'reason' => $valueSet === null ? 'value set not loaded' : null,
        ];
    }

    /** BRiDgE engagement readiness as in the app prototype: 8 items × 1–4. */
    public static function bridge(array $answers): array
    {
        if (count($answers) !== 8 || in_array(null, $answers, true)) {
            return ['score' => null, 'band' => null, 'reason' => 'all 8 items required'];
        }
        $t = array_sum($answers);
        return ['score' => $t, 'min' => 8, 'max' => 32, 'band' => $t >= 26 ? 'high' : ($t >= 18 ? 'moderate' : 'low')];
    }

    // ─────────────────────────── CCSPS composite ───────────────────────────

    /**
     * Domain 9 · mental health. Precedence rule from the eCRF: the worse of PHQ-9 and GAD-7 governs.
     * Mapping (PROPOSED — confirm against CCSPS_Specification §4):
     *   both 0–4 → 2 · worst 5–9 → 1 · worst ≥ 10 → 0. Either missing → pending (null).
     */
    public static function mentalHealthDomain(?int $phq9, ?int $gad7): ?int
    {
        if ($phq9 === null || $gad7 === null) {
            return null;
        }
        $rank = fn(int $t) => $t >= 10 ? 2 : ($t >= 5 ? 1 : 0);
        return 2 - max($rank($phq9), $rank($gad7));
    }

    /**
     * Generic three-band domain scorer driven by an approved config:
     *   ['ideal' => fn($x): bool, 'poor' => fn($x): bool] — anything else = intermediate.
     * A domain with no approved config is pending, never scored.
     */
    public static function scoreDomain(mixed $input, ?array $config): ?int
    {
        if ($input === null || $config === null) {
            return null;
        }
        if (($config['poor'])($input)) {
            return 0;
        }
        return ($config['ideal'])($input) ? 2 : 1;
    }

    /** $domains: 10 keys → 0|1|2|null. */
    public static function ccsps(array $domains, int $minDomainsForProportional = 8): array
    {
        $keys = ['bp', 'ldl', 'hba1c', 'diet', 'physical_activity', 'smoking', 'sleep', 'bmi', 'mental_health', 'medication_adherence'];
        $scored = [];
        $pending = [];
        foreach ($keys as $k) {
            $v = $domains[$k] ?? null;
            if ($v === null) {
                $pending[] = $k;
            } else {
                if (!in_array($v, [0, 1, 2], true)) {
                    throw new InvalidArgumentException("Domain $k must be 0, 1 or 2");
                }
                $scored[$k] = $v;
            }
        }
        $n = count($scored);
        $raw = array_sum($scored);
        return [
            'raw' => $raw,
            'domains_scored' => $n,
            'pending' => $pending,
            'max_possible' => 2 * $n,
            'complete' => $n === 10,
            'normalised_complete_case' => $n === 10 ? self::r($raw / 20 * 100, 1) : null,              // option A
            'normalised_proportional' => $n >= $minDomainsForProportional ? self::r($raw / (2 * $n) * 100, 1) : null, // option B
        ];
    }

    // ─────────────────────────── time anchors ───────────────────────────

    /** Rehab phase from PCI date (Day 0): I 0–7 · II 8–30 · III 31–90 · IV ≥ 91. */
    public static function rehabPhase(string $pciDate, string $on): int
    {
        $d = self::daysBetween($pciDate, $on);
        if ($d < 0) {
            throw new InvalidArgumentException('Date is before PCI');
        }
        return $d <= 7 ? 1 : ($d <= 30 ? 2 : ($d <= 90 ? 3 : 4));
    }

    public static function studyDay(string $randomisationDate, string $on): int
    {
        return self::daysBetween($randomisationDate, $on);
    }

    public static function visitWindow(string $anchor, int $offset, int $before, int $after): array
    {
        $t = self::date($anchor)->modify("+$offset days");
        return [
            'target' => $t->format('Y-m-d'),
            'start' => $t->modify("-$before days")->format('Y-m-d'),
            'end' => $t->modify("+$after days")->format('Y-m-d'),
        ];
    }

    public static function visitStatus(array $window, string $today, bool $complete): string
    {
        if ($complete) {
            return 'complete';
        }
        if (self::daysBetween($today, $window['start']) > 0) {
            return 'scheduled';
        }
        return self::daysBetween($window['end'], $today) > 0 ? 'missed' : 'open';
    }

    // ─────────────────────────── exercise ───────────────────────────

    public static function hrMax(int $age, string $method = '220'): int
    {
        return match ($method) {
            '220' => 220 - $age,
            'gellish' => (int) self::r(207 - 0.7 * $age), // Gellish 2007, linear
            default => throw new InvalidArgumentException('Unknown HRmax method'),
        };
    }

    /**
     * %HRR intensity table copied from the app prototype (calcTHR). PROPOSED, needs clinical sign-off.
     * Phase 1 and NYHA IV → null (ADL only, no heart-rate zone).
     */
    public const INTENSITY = [
        'I' => [2 => [45, 54], 3 => [60, 69], 4 => [80, 89]],
        'II' => [2 => [40, 45], 3 => [55, 60], 4 => [70, 80]],
        'III' => [2 => [35, 40], 3 => [45, 54], 4 => [45, 54]],
        'IV' => [],
    ];

    public static function karvonen(int $hrMax, int $restingHr, int $lowPct, int $highPct): array
    {
        $hrr = $hrMax - $restingHr;
        return [
            'low' => (int) self::r($hrr * $lowPct / 100 + $restingHr),
            'high' => (int) self::r($hrr * $highPct / 100 + $restingHr),
        ];
    }

    /** Full THR with safety guards. $restingHr = 7-day mean resting HR from the wearable. */
    public static function thr(int $age, ?int $restingHr, string $nyha, int $phase, bool $onBetaBlocker = false, string $method = '220'): array
    {
        if ($restingHr === null) {
            return ['zone' => null, 'reason' => 'resting HR not available — no default allowed'];
        }
        if ($restingHr >= 100) {
            return ['zone' => null, 'reason' => 'resting HR ≥ 100 bpm — clinician review before any zone'];
        }
        $band = self::INTENSITY[$nyha][$phase] ?? null;
        if ($band === null) {
            return ['zone' => null, 'reason' => $phase === 1 ? 'Phase I — ADL only' : "no zone defined for NYHA $nyha"];
        }
        $max = self::hrMax($age, $method);
        return [
            'zone' => self::karvonen($max, $restingHr, $band[0], $band[1]),
            'hr_max' => $max,
            'pct_hrr' => $band,
            'primary_guide' => $onBetaBlocker ? 'RPE 11–13 (Borg 6–20); HR zone advisory only' : 'HR zone',
        ];
    }

    /** % of 1-minute HR samples within [low, high] inclusive; null if < 50 % of minutes have HR. */
    public static function timeInZone(array $samples, int $low, int $high, int $durationMin): ?int
    {
        $valid = array_filter($samples, fn($x) => $x !== null);
        if ($durationMin <= 0 || count($valid) < 0.5 * $durationMin) {
            return null;
        }
        $in = count(array_filter($valid, fn($x) => $x >= $low && $x <= $high));
        return (int) self::r($in / count($valid) * 100);
    }

    public static function sessionZoneStatus(int $avgHr, int $low, int $high): string
    {
        return $avgHr > $high ? 'above_zone' : ($avgHr < $low ? 'below_zone' : 'in_zone');
    }

    // ─────────────────────────── monitoring ───────────────────────────

    /** Mean of a metric over valid days. $days = [['value'=>…, 'wear_min'=>…], …] */
    public static function validDayMean(array $days, int $minWearMin = 600, int $minValidDays = 4): ?float
    {
        $v = array_column(array_filter($days, fn($d) => $d['value'] !== null && ($d['wear_min'] ?? 0) >= $minWearMin), 'value');
        return count($v) >= $minValidDays ? self::r(self::mean($v), 0) : null;
    }

    /** % decline of last-7 mean vs prior-7 mean (complete days, oldest first). Positive = decline. */
    public static function stepDecline(array $fourteenDays): ?float
    {
        if (count($fourteenDays) !== 14) {
            throw new InvalidArgumentException('Exactly 14 complete days required');
        }
        $prior = self::mean(array_slice($fourteenDays, 0, 7));
        $last = self::mean(array_slice($fourteenDays, 7, 7));
        return $prior > 0 ? self::r(($prior - $last) / $prior * 100, 1) : null;
    }

    /** Home BP: mean of all readings in the window; PROPOSED minimum 6 readings. */
    public static function homeBp(array $readings, int $minReadings = 6): ?array
    {
        if (count($readings) < $minReadings) {
            return null;
        }
        return ['sbp' => self::r(self::mean(array_column($readings, 0)), 0), 'dbp' => self::r(self::mean(array_column($readings, 1)), 0), 'n' => count($readings)];
    }

    /** True when each of the last $days complete days is above $threshold. */
    public static function sustainedAbove(array $daily, float $threshold, int $days): bool
    {
        $tail = array_slice($daily, -$days);
        return count($tail) === $days && count(array_filter($tail, fn($x) => $x !== null && $x > $threshold)) === $days;
    }

    /** PROPOSED HF rule: weight gain > $kg within any $days-day span. $series = date => kg */
    public static function weightGainFlag(array $series, float $kg = 2.0, int $days = 3): bool
    {
        $dates = array_keys($series);
        foreach ($dates as $i => $a) {
            foreach (array_slice($dates, $i + 1) as $b) {
                if (self::daysBetween($a, $b) <= $days && $series[$b] - $series[$a] > $kg) {
                    return true;
                }
            }
        }
        return false;
    }

    /** Prototype trend rule: last value vs first value, ±0.5 = stable. Kept for reference only. */
    public static function trendPrototype(array $series, bool $lowerIsBetter): string
    {
        $diff = end($series) - reset($series);
        if (abs($diff) < 0.5) {
            return 'stable';
        }
        return ($lowerIsBetter ? $diff < 0 : $diff > 0) ? 'improving' : 'worsening';
    }

    /** PROPOSED trend rule: mean of last 3 days vs first 3 days, with a per-metric minimum change. */
    public static function trend(array $series, bool $lowerIsBetter, float $minChange): string
    {
        if (count($series) < 6) {
            return 'insufficient_data';
        }
        $diff = self::mean(array_slice($series, -3)) - self::mean(array_slice($series, 0, 3));
        if (abs($diff) < $minChange) {
            return 'stable';
        }
        return ($lowerIsBetter ? $diff < 0 : $diff > 0) ? 'improving' : 'worsening';
    }

    public static function sleepBand(float $hours): string
    {
        return $hours >= 7 ? 'ideal' : ($hours >= 6 ? 'short' : 'poor');
    }

    public static function stepsStatus(int $avg, int $goal): string
    {
        return $avg >= $goal ? 'on_target' : ($avg >= 0.6 * $goal ? 'below_goal' : 'low');
    }

    // ─────────────────────────── adherence ───────────────────────────

    /** Doses taken ÷ doses due (excludes doses not yet due and PRN). */
    public static function doseAdherence(int $taken, int $due): ?int
    {
        if ($taken > $due) {
            throw new InvalidArgumentException('Taken cannot exceed due');
        }
        return $due === 0 ? null : (int) self::r($taken / $due * 100);
    }

    public static function daptBelowTarget(array $daptPct, int $threshold = 70): bool
    {
        return count(array_filter($daptPct, fn($p) => $p !== null && $p < $threshold)) > 0;
    }

    /** DHI adherence meter: unweighted mean of the 7 sub-domains (prototype); all 7 required. */
    public static function dhiMeter(array $sub): ?int
    {
        if (count($sub) !== 7 || in_array(null, $sub, true)) {
            return null;
        }
        return (int) self::r(self::mean($sub));
    }

    public static function pct(int $num, int $den): ?int
    {
        return $den === 0 ? null : (int) self::r($num / $den * 100);
    }

    /** Fluid: glasses × mL per glass compared with a target (minimum) or a limit (maximum, HF). */
    public static function fluid(int $glasses, int $mlPerGlass, int $targetMl, string $mode = 'target'): array
    {
        $ml = $glasses * $mlPerGlass;
        $met = $mode === 'limit' ? $ml <= $targetMl : $ml >= $targetMl;
        return ['ml' => $ml, 'met' => $met, 'mode' => $mode];
    }

    public static function saltToSodium(float $saltG): float
    {
        return self::r($saltG / 2.54, 2);
    }

    // ─────────────────────────── alerts ───────────────────────────

    /**
     * Symptom cluster exactly as the clinician prototype computes it:
     * a day counts when ≥ 2 of {chest_pain, breathlessness, ankle_swelling} are ≥ 2 (moderate);
     * fires when ≥ 2 such days fall in the last 7 logged days.
     */
    public static function symptomCluster(array $days): array
    {
        $red = ['chest_pain', 'breathlessness', 'ankle_swelling'];
        $window = array_slice($days, -7);
        $flagged = 0;
        foreach ($window as $d) {
            $n = count(array_filter($red, fn($k) => ($d[$k] ?? 0) >= 2));
            if ($n >= 2) {
                $flagged++;
            }
        }
        return ['days_flagged' => $flagged, 'window' => count($window), 'fires' => $flagged >= 2];
    }

    /** PROPOSED: any single day with chest pain ≥ 2, or any symptom = 3 (severe), is a same-day critical alert. */
    public static function singleDayRedFlag(array $day): bool
    {
        if (($day['chest_pain'] ?? 0) >= 2) {
            return true;
        }
        foreach ($day as $k => $v) {
            if (is_int($v) && $v >= 3) {
                return true;
            }
        }
        return false;
    }

    // ─────────────────────────── randomisation ───────────────────────────

    public static function ageStratum(int $ageAtRandomisation): string
    {
        return $ageAtRandomisation < 60 ? 'lt60' : 'ge60';
    }

    /** Import check for the statistician's list: each block must be balanced 1:1. */
    public static function validateSequence(array $rows): array
    {
        $blocks = [];
        foreach ($rows as $r) {
            $blocks[$r['stratum'] . '#' . $r['block_no']][] = $r['arm'];
        }
        $bad = [];
        foreach ($blocks as $k => $arms) {
            $c = array_count_values($arms);
            if (($c['intervention'] ?? 0) !== ($c['control'] ?? 0)) {
                $bad[] = $k;
            }
        }
        return ['blocks' => count($blocks), 'unbalanced' => $bad];
    }

    /** Signed difference for display: '+5', '−3', '0' (prototype always printed ▲). */
    public static function signedDelta(float $current, float $baseline, int $dp = 0): string
    {
        $d = self::r($current - $baseline, $dp);
        return $d > 0 ? '+' . $d : ($d < 0 ? '−' . abs($d) : '0');
    }
}
