<?php

namespace App\Forms;

use App\Calc\Calc;

/**
 * SCR-01 eligibility. A thin adapter: it maps eCRF field values to the inputs of
 * App\Calc\Calc::eligibility (ELIG-1.0, docs/calculation-specification.md, vectors E1–E15)
 * and applies one study setting on top: which phone operating systems are accepted
 * (config smartheart.eligibility.allowed_os — Android only by default).
 *
 * Criterion statuses: pass | fail | pending | n/a | not_evaluated (after the first exclusion).
 * ELIG_STATUS:  ELIGIBLE | NOT_ELIGIBLE | INCOMPLETE
 */
class EligibilityEngine
{
    public const ENGINE = 'ELIG-1.0';

    /** Criterion codes, in screen order (used for exports and the data dictionary). */
    public const CODES = ['AGE_GE_18', 'DX_ACS_OR_SIHD', 'PCI_LE_30D', 'NO_CABG', 'LVEF_GE_40', 'PRIOR_CARDIAC_ARREST',
        'COMPLEX_VENT_ARRHYTHMIA', 'CARDIOGENIC_SHOCK', 'RETINOPATHY', 'PERIPHERAL_NEUROPATHY', 'DIABETIC_FOOT_ULCER',
        'EGFR_GE_45', 'BP_CONTROLLED', 'VISUAL_IMPAIRMENT', 'HEARING_IMPAIRMENT', 'COGNITIVE_IMPAIRMENT', 'DIGITAL_ACCESS'];

    public static function evaluate(array $d, ?array $allowedOs = null): array
    {
        $allowedOs ??= config('smartheart.eligibility.allowed_os', ['android']);
        $notes = [];

        $dob = self::date($d['SCR_DOB'] ?? null);
        $scr = self::date($d['SCR_DATE'] ?? null);
        $pci = self::date($d['SCR_PCI_DATE'] ?? null);
        if ($dob && $scr && $dob > $scr) {
            $notes['AGE_GE_18'] = 'Date of birth is after the screening date — check dates';
            $dob = null;
        }
        if ($pci && $scr && $pci > $scr) {
            $notes['PCI_LE_30D'] = 'PCI date is after the screening date — check dates';
            $pci = null;
        }

        $in = [
            'dob' => $dob,
            'screening_date' => $scr,
            'index_diagnosis' => self::map($d['SCR_DIAGNOSIS'] ?? null, [
                'Acute Coronary Syndrome (ACS)' => 'acs', 'Stable Ischaemic Heart Disease' => 'stable_ihd', 'Other' => 'other']),
            'acs_subtype' => self::map($d['SCR_ACS_SUBTYPE'] ?? null, ['STEMI' => 'stemi', 'NSTEMI' => 'nstemi', 'Unstable Angina' => 'ua']),
            'pci_done' => self::bool($d['SCR_PCI_DONE'] ?? null),
            'pci_date' => $pci,
            'cabg_history' => self::bool($d['SCR_CABG'] ?? null),
            'lvef_pct' => self::num($d['SCR_LVEF'] ?? null),
            'prior_cardiac_arrest' => self::ynu($d['SCR_CARDIAC_ARREST'] ?? null),
            'complex_vent_arrhythmia' => self::ynu($d['SCR_VENT_ARRHYTHMIA'] ?? null),
            'cardiogenic_shock' => self::ynu($d['SCR_CARDIOGENIC_SHOCK'] ?? null),
            't2dm' => self::bool($d['SCR_T2DM'] ?? null),
            'retinopathy' => self::ynu($d['SCR_RETINOPATHY'] ?? null),
            'peripheral_neuropathy' => self::ynu($d['SCR_NEUROPATHY'] ?? null),
            'diabetic_foot_ulcer' => self::ynu($d['SCR_FOOT_ULCER'] ?? null),
            'egfr' => self::num($d['SCR_EGFR'] ?? null),
            'sbp' => self::num($d['SCR_SBP'] ?? null),
            'dbp' => self::num($d['SCR_DBP'] ?? null),
            'on_antihypertensive' => self::bool($d['SCR_ON_ANTIHYPERTENSIVE'] ?? null),
            'visual_impairment' => self::impair($d['SCR_VISUAL'] ?? null),
            'hearing_impairment' => self::impair($d['SCR_HEARING'] ?? null),
            'cognitive_impairment' => self::impair($d['SCR_COGNITIVE'] ?? null),
            'smartphone_access' => self::bool($d['SCR_SMARTPHONE'] ?? null),
            'phone_os' => self::map($d['SCR_PHONE_OS'] ?? null, ['Android' => 'android', 'iOS' => 'ios', 'Other' => 'other']),
        ];

        $r = Calc::eligibility($in);
        $criteria = $r['criteria'];

        // Study setting: accepted phone OS (Calc accepts Android and iOS; the trial app is Android only).
        foreach ($criteria as $i => $c) {
            if ($c['code'] === 'DIGITAL_ACCESS' && $c['status'] === 'pass' && ! in_array($in['phone_os'], $allowedOs, true)) {
                $criteria[$i]['status'] = 'fail';
                $criteria[$i]['evidence'] = "{$in['phone_os']} — study app supports ".implode(', ', $allowedOs).' only';
            }
            if (isset($notes[$c['code']]) && $c['status'] === 'pending') {
                $criteria[$i]['evidence'] = $notes[$c['code']];
            }
        }

        $statuses = array_column($criteria, 'status');
        $result = in_array('fail', $statuses, true) ? 'NOT_ELIGIBLE' : (in_array('pending', $statuses, true) ? 'INCOMPLETE' : 'ELIGIBLE');
        $fails = array_values(array_filter($criteria, fn ($c) => $c['status'] === 'fail'));
        $stop = $fails[0]['screen'] ?? null;

        $age = ($dob && $scr) ? Calc::ageYears($dob, $scr) : null;
        $pciDays = ($pci && $scr && $in['pci_done'] === true) ? Calc::daysBetween($pci, $scr) : null;

        $computed = [
            'SCR_AGE' => $age,
            'SCR_PCI_DAYS' => $pciDays,
            'ELIG_ENGINE' => self::ENGINE,
            'ELIG_STATUS' => $result,
            'ELIG_STOP_SCREEN' => $stop,
            'SCR_FAIL_REASONS' => array_map(fn ($c) => $c['label'].($c['evidence'] ? " ({$c['evidence']})" : ''), $fails),
        ];
        foreach ($criteria as $c) {
            $computed['ELIG_'.$c['code']] = $c['status'];
        }

        $out = array_map(fn ($c) => [
            'code' => $c['code'], 'screen' => $c['screen'], 'label' => $c['label'],
            'status' => $c['status'], 'detail' => $c['evidence'],
        ], $criteria);

        return ['status' => $result, 'engine' => self::ENGINE, 'stop_at_screen' => $stop, 'criteria' => $out, 'computed' => $computed];
    }

    // ---------------------------------------------------------------- mapping helpers

    private static function date(mixed $v): ?string
    {
        return FormValidator::isDate($v) ? $v : null;
    }

    private static function bool(mixed $v): ?bool
    {
        return $v === 'Yes' ? true : ($v === 'No' ? false : null);
    }

    private static function ynu(mixed $v): ?string
    {
        return self::map($v, ['Yes' => 'yes', 'No' => 'no', 'Unknown' => 'unknown']);
    }

    private static function impair(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        if ($v === 'No') {
            return 'no';
        }

        return str_contains($v, 'prevents') ? 'yes_unsafe' : 'yes_with_support';
    }

    private static function num(mixed $v): int|float|null
    {
        return is_numeric($v) ? $v + 0 : null;
    }

    private static function map(mixed $v, array $m): ?string
    {
        return $v === null ? null : ($m[$v] ?? null);
    }
}
