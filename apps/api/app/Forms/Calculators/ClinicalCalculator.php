<?php

namespace App\Forms\Calculators;

use App\Calc\Calc;
use App\Forms\Calculator;
use App\Forms\FormContext;

class ClinicalCalculator extends Calculator
{
    public static function compute(array $data, FormContext $ctx): array
    {
        $out = ['BL_SBP_MEAN' => null, 'BL_DBP_MEAN' => null];
        $r = [[$data['BL_SBP1'] ?? null, $data['BL_DBP1'] ?? null], [$data['BL_SBP2'] ?? null, $data['BL_DBP2'] ?? null]];
        if (! in_array(null, array_merge(...$r), true) && empty(self::validate($data, $ctx))) {
            $m = Calc::meanBp($r);
            $out = ['BL_SBP_MEAN' => $m['sbp'], 'BL_DBP_MEAN' => $m['dbp']];
        }
        $ef = $ctx->value('SCR-01', 'SCR_LVEF');

        return $out + [
            'BL_LVEF' => $ef,
            'BL_LVEF_BAND' => is_numeric($ef) ? Calc::efBand((int) $ef) : null,
        ];
    }

    public static function validate(array $data, FormContext $ctx): array
    {
        $e = [];
        foreach ([1, 2] as $i) {
            $s = $data["BL_SBP{$i}"] ?? null;
            $d = $data["BL_DBP{$i}"] ?? null;
            if (is_numeric($s) && is_numeric($d) && $d >= $s) {
                $e["BL_DBP{$i}"] = 'Diastolic must be lower than systolic.';
            }
        }

        return $e;
    }
}
