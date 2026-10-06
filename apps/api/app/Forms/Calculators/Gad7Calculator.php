<?php

namespace App\Forms\Calculators;

use App\Calc\Calc;
use App\Forms\Calculator;
use App\Forms\FormContext;

/** GAD-7 via Calc::gad7 (vectors Q6–Q7). */
class Gad7Calculator extends Calculator
{
    public static function compute(array $data, FormContext $ctx): array
    {
        $a = [];
        for ($i = 1; $i <= 7; $i++) {
            $a[(string) $i] = $data["GAD7_Q{$i}"] ?? null;
        }
        $r = Calc::gad7($a, (int) config('smartheart.scoring.prorate_max_missing', 0));

        return [
            'GAD7_TOTAL' => $r['total'],
            'GAD7_SEVERITY' => $r['severity'],
            'GAD7_REVIEW_FLAG' => $r['clinical_review'] === null ? null : ($r['clinical_review'] ? 'Yes' : 'No'),
            'GAD7_MISSING' => $r['missing'],
            'GAD7_ENGINE' => 'GAD7-1.0',
        ];
    }
}
