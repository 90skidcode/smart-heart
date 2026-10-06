<?php

namespace App\Forms\Calculators;

use App\Calc\Calc;
use App\Forms\Calculator;
use App\Forms\FormContext;

/** DASI via Calc::dasi (vectors Q8–Q11): score 0–58.2, VO₂peak = 0.43 × DASI + 9.6, METs = VO₂peak ÷ 3.5. */
class DasiCalculator extends Calculator
{
    public static function compute(array $data, FormContext $ctx): array
    {
        $yes = [];
        for ($i = 1; $i <= 12; $i++) {
            $v = $data["DASI_Q{$i}"] ?? null;
            $yes[] = $v === null ? null : $v === 1;
        }
        $r = Calc::dasi($yes);

        return ['DASI_SCORE' => $r['score'], 'DASI_VO2PEAK' => $r['vo2peak'], 'DASI_METS' => $r['mets'], 'DASI_ENGINE' => 'DASI-1.0'];
    }
}
