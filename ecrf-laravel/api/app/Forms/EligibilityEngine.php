<?php

namespace App\Forms;

use DateTimeImmutable;

/**
 * SCR-01 automatic eligibility, per the Eligibility Summary in SMART_HEART_eCRF.html.
 *
 * Each criterion returns:
 *   inclusion: 'met' | 'not_met' | 'pending'
 *   exclusion: 'absent' | 'present' | 'pending'
 * "pending" = not yet answered, or answered "Unknown".
 *
 * Overall ELIG_STATUS:
 *   NOT_ELIGIBLE  any inclusion not_met or any exclusion present (screen failure)
 *   INCOMPLETE    otherwise, if anything is pending
 *   ELIGIBLE      all inclusions met, all exclusions absent
 */
class EligibilityEngine
{
    public const PCI_WINDOW_DAYS = 30;
    public const LVEF_MIN = 40;
    public const EGFR_MIN = 45;
    public const SBP_MAX = 160;   // SBP >= 160 excludes
    public const DBP_MAX = 100;   // DBP >= 100 excludes

    public static function evaluate(array $d): array
    {
        $age = self::yearsBetween($d['SCR_DOB'] ?? null, $d['SCR_DATE'] ?? null);
        $pciDays = (($d['SCR_PCI_DONE'] ?? null) === 'Yes')
            ? self::daysBetween($d['SCR_PCI_DATE'] ?? null, $d['SCR_DATE'] ?? null)
            : null;

        $c = [];

        // ---------- Inclusion ----------
        $c[] = self::crit('INC_AGE', 'inclusion', 'Age ≥ 18 years',
            $age === null ? 'pending' : ($age >= 18 ? 'met' : 'not_met'),
            $age === null ? 'Enter date of birth and screening date' : "{$age} years");

        $dx = $d['SCR_DIAGNOSIS'] ?? null;
        $dxDetail = $dx === 'Acute Coronary Syndrome (ACS)' ? ($d['SCR_ACS_SUBTYPE'] ?? 'ACS — subtype pending') : $dx;
        $dxStatus = match (true) {
            $dx === null => 'pending',
            $dx === 'Other' => 'not_met',
            $dx === 'Acute Coronary Syndrome (ACS)' && empty($d['SCR_ACS_SUBTYPE']) => 'pending',
            default => 'met',
        };
        $c[] = self::crit('INC_DIAGNOSIS', 'inclusion', 'ACS / Stable IHD diagnosis', $dxStatus, $dxDetail);

        $pciDone = $d['SCR_PCI_DONE'] ?? null;
        if ($pciDone === null) {
            $pci = ['pending', null];
        } elseif ($pciDone === 'No') {
            $pci = ['not_met', 'No PCI'];
        } elseif ($pciDays === null) {
            $pci = ['pending', 'Enter PCI date and screening date'];
        } elseif ($pciDays < 0) {
            $pci = ['pending', 'PCI date is after the screening date — check dates'];
        } elseif ($pciDays <= self::PCI_WINDOW_DAYS) {
            $pci = ['met', "{$pciDays} day(s) before screening"];
        } else {
            $pci = ['not_met', "{$pciDays} days before screening (window ≤ ".self::PCI_WINDOW_DAYS.')'];
        }
        $c[] = self::crit('INC_PCI', 'inclusion', 'PCI within ≤ 30 days', $pci[0], $pci[1]);

        $phone = $d['SCR_SMARTPHONE'] ?? null;
        $os = $d['SCR_PHONE_OS'] ?? null;
        $ph = match (true) {
            $phone === null => ['pending', null],
            $phone === 'No' => ['not_met', 'No smartphone access'],
            $os === null => ['pending', 'Operating system not entered'],
            $os === 'Android' => ['met', 'Android'],
            default => ['not_met', "{$os} — study app is Android only"],
        };
        $c[] = self::crit('INC_SMARTPHONE', 'inclusion', 'Compatible Android smartphone access', $ph[0], $ph[1]);

        // ---------- Exclusion ----------
        $c[] = self::yesNo('EXC_CABG', 'CABG history', $d['SCR_CABG'] ?? null);

        $lvef = self::num($d['SCR_LVEF'] ?? null);
        $c[] = self::crit('EXC_LVEF', 'exclusion', 'LVEF < 40%',
            $lvef === null ? 'pending' : ($lvef < self::LVEF_MIN ? 'present' : 'absent'),
            $lvef === null ? null : "LVEF {$lvef}%");

        $c[] = self::yesNo('EXC_CARDIAC_ARREST', 'Previous cardiac arrest', $d['SCR_CARDIAC_ARREST'] ?? null);
        $c[] = self::yesNo('EXC_VENT_ARRHYTHMIA', 'Complex ventricular arrhythmia', $d['SCR_VENT_ARRHYTHMIA'] ?? null);
        $c[] = self::yesNo('EXC_CARDIOGENIC_SHOCK', 'Cardiogenic shock', $d['SCR_CARDIOGENIC_SHOCK'] ?? null);

        $dm = $d['SCR_T2DM'] ?? null;
        if ($dm === null) {
            $dmc = ['pending', null];
        } elseif ($dm === 'No') {
            $dmc = ['absent', 'No type 2 diabetes'];
        } else {
            $parts = ['SCR_RETINOPATHY' => 'retinopathy', 'SCR_NEUROPATHY' => 'neuropathy', 'SCR_FOOT_ULCER' => 'foot ulcer'];
            $present = [];
            $pending = false;
            foreach ($parts as $k => $label) {
                $v = $d[$k] ?? null;
                if ($v === 'Yes') {
                    $present[] = $label;
                } elseif ($v === null || $v === 'Unknown') {
                    $pending = true;
                }
            }
            $dmc = $present ? ['present', 'Has '.implode(', ', $present)] : ($pending ? ['pending', 'Complications not fully assessed'] : ['absent', 'No complications']);
        }
        $c[] = self::crit('EXC_DM_COMPLICATIONS', 'exclusion', 'Diabetes complications (retinopathy / neuropathy / foot ulcer)', $dmc[0], $dmc[1]);

        $egfr = self::num($d['SCR_EGFR'] ?? null);
        $c[] = self::crit('EXC_EGFR', 'exclusion', 'eGFR < 45 mL/min/1.73m²',
            $egfr === null ? 'pending' : ($egfr < self::EGFR_MIN ? 'present' : 'absent'),
            $egfr === null ? null : "eGFR {$egfr}");

        $sbp = self::num($d['SCR_SBP'] ?? null);
        $dbp = self::num($d['SCR_DBP'] ?? null);
        $htn = ($sbp === null || $dbp === null) ? 'pending'
            : (($sbp >= self::SBP_MAX || $dbp >= self::DBP_MAX) ? 'present' : 'absent');
        $c[] = self::crit('EXC_UNCONTROLLED_HTN', 'exclusion', 'Uncontrolled hypertension (SBP ≥ 160 or DBP ≥ 100)', $htn,
            ($sbp === null || $dbp === null) ? null : "BP {$sbp}/{$dbp}");

        $unsafe = 'Yes — prevents safe app use';
        $vis = $d['SCR_VISUAL'] ?? null;
        $hear = $d['SCR_HEARING'] ?? null;
        $sens = ($vis === $unsafe || $hear === $unsafe) ? 'present' : (($vis === null || $hear === null) ? 'pending' : 'absent');
        $c[] = self::crit('EXC_SENSORY', 'exclusion', 'Unsafe sensory impairment', $sens, null);

        $cog = $d['SCR_COGNITIVE'] ?? null;
        $c[] = self::crit('EXC_COGNITIVE', 'exclusion', 'Unsafe cognitive impairment',
            $cog === null ? 'pending' : ($cog === $unsafe ? 'present' : 'absent'), null);

        // ---------- Overall ----------
        $fails = array_values(array_filter($c, fn ($x) => in_array($x['status'], ['not_met', 'present'], true)));
        $pend = array_values(array_filter($c, fn ($x) => $x['status'] === 'pending'));
        $overall = $fails ? 'NOT_ELIGIBLE' : ($pend ? 'INCOMPLETE' : 'ELIGIBLE');

        $computed = [
            'SCR_AGE' => $age,
            'SCR_PCI_DAYS' => $pciDays,
            'ELIG_STATUS' => $overall,
            'SCR_FAIL_REASONS' => array_map(fn ($x) => $x['label'].($x['detail'] ? " ({$x['detail']})" : ''), $fails),
            'AGE_STRATUM' => $age === null ? null : ($age < 60 ? '<60' : '≥60'),
        ];
        foreach ($c as $x) {
            $computed[$x['code']] = $x['status'];
        }

        return ['status' => $overall, 'criteria' => $c, 'computed' => $computed];
    }

    private static function crit(string $code, string $kind, string $label, string $status, ?string $detail): array
    {
        return compact('code', 'kind', 'label', 'status', 'detail');
    }

    private static function yesNo(string $code, string $label, ?string $v): array
    {
        return self::crit($code, 'exclusion', $label,
            match ($v) { 'Yes' => 'present', 'No' => 'absent', default => 'pending' },
            $v === 'Unknown' ? 'Unknown — clarify before deciding' : null);
    }

    private static function num(mixed $v): ?float
    {
        return is_numeric($v) ? (float) $v + 0 : null;
    }

    private static function date(?string $v): ?DateTimeImmutable
    {
        if (! FormValidator::isDate($v)) {
            return null;
        }

        return new DateTimeImmutable($v);
    }

    public static function yearsBetween(?string $from, ?string $to): ?int
    {
        $a = self::date($from);
        $b = self::date($to);
        if (! $a || ! $b || $a > $b) {
            return null;
        }

        return $a->diff($b)->y;
    }

    public static function daysBetween(?string $from, ?string $to): ?int
    {
        $a = self::date($from);
        $b = self::date($to);
        if (! $a || ! $b) {
            return null;
        }

        return (int) $a->diff($b)->format('%r%a');
    }
}
