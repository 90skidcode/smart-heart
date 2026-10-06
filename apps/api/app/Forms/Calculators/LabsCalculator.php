<?php

namespace App\Forms\Calculators;

use App\Calc\Calc;
use App\Forms\Calculator;
use App\Forms\FormContext;
use InvalidArgumentException;

class LabsCalculator extends Calculator
{
    /** Form key => Calc::LABS code. */
    private const LABS = ['HBA1C' => 'HBA1C', 'FBG' => 'FBG', 'PPBG' => 'PPBG', 'TC' => 'TC', 'LDL' => 'LDL', 'HDL' => 'HDL', 'TG' => 'TG', 'CREAT' => 'CREAT'];

    /** Canonical value of one lab, or null when missing or invalid. */
    private static function canon(array $data, string $key): ?float
    {
        $v = $data["BL_{$key}"] ?? null;
        $u = $data["BL_{$key}_UNIT"] ?? null;
        if (! is_numeric($v) || ! $u) {
            return null;
        }
        try {
            return Calc::convertLab(self::LABS[$key], (float) $v, $u)['value'];
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public static function compute(array $data, FormContext $ctx): array
    {
        $c = [];
        foreach (array_keys(self::LABS) as $k) {
            $c[$k] = self::canon($data, $k);
        }
        $out = [];
        foreach ($c as $k => $v) {
            $out["BL_{$k}_CANON"] = $v;
        }
        $out['BL_HBA1C_PCT'] = $c['HBA1C'];
        $out['BL_LDL_MGDL'] = $c['LDL'];
        $out['BL_LDL_FRIEDEWALD'] = ($c['TC'] !== null && $c['HDL'] !== null && $c['TG'] !== null) ? Calc::ldlFriedewald($c['TC'], $c['HDL'], $c['TG']) : null;
        $out['BL_NON_HDL'] = ($c['TC'] !== null && $c['HDL'] !== null) ? Calc::nonHdl($c['TC'], $c['HDL']) : null;

        // CKD-EPI 2021 needs age on the sample date (DOB from SCR-01) and sex (BL-M1).
        $dob = $ctx->value('SCR-01', 'SCR_DOB');
        $sex = $ctx->value('BL-M1', 'BL_SEX');
        $date = $data['BL_CREAT_DATE'] ?? null;
        $egfr = null;
        if ($c['CREAT'] !== null && $dob && $date && in_array($sex, ['Male', 'Female'], true) && $date >= $dob) {
            $egfr = Calc::egfrCkdEpi2021($c['CREAT'], Calc::ageYears($dob, $date), $sex === 'Female' ? 'F' : 'M');
        }
        $lab = $ctx->value('SCR-01', 'SCR_EGFR');
        $out['BL_EGFR_LAB'] = $lab;
        $out['BL_EGFR_CKDEPI'] = $egfr;
        $cons = ($egfr && is_numeric($lab)) ? Calc::egfrConsistency((float) $lab, $egfr) : null;
        $out['BL_EGFR_DIFF_PCT'] = $cons['difference_pct'] ?? null;
        $out['BL_EGFR_FLAG'] = $cons['flag'] ?? null;
        $out['BL_EGFR_NOTE'] = $egfr === null && $c['CREAT'] !== null ? 'CKD-EPI needs sex (BL-01 Module 1) and date of birth (SCR-01).' : null;

        return $out;
    }

    public static function validate(array $data, FormContext $ctx): array
    {
        $e = [];
        foreach (self::LABS as $key => $code) {
            $v = $data["BL_{$key}"] ?? null;
            $u = $data["BL_{$key}_UNIT"] ?? null;
            if (! is_numeric($v) || ! $u) {
                continue;
            }
            try {
                Calc::convertLab($code, (float) $v, $u);
            } catch (InvalidArgumentException $ex) {
                $e["BL_{$key}"] = 'Outside the plausible range: '.$ex->getMessage().'.';
            }
        }

        return $e;
    }
}
