<?php

namespace App\Forms\Calculators;

use App\Calc\Calc;
use App\Forms\Calculator;
use App\Forms\FormContext;

/** PHQ-9 via Calc::phq9 (vectors Q1–Q5). Item 9 is evaluated even when the total is null. */
class Phq9Calculator extends Calculator
{
    public static function compute(array $data, FormContext $ctx): array
    {
        $a = [];
        for ($i = 1; $i <= 9; $i++) {
            $a[(string) $i] = $data["PHQ9_Q{$i}"] ?? null;
        }
        $r = Calc::phq9($a, (int) config('smartheart.scoring.prorate_max_missing', 0));
        $yn = fn ($b) => $b === null ? null : ($b ? 'Yes' : 'No');

        return [
            'PHQ9_TOTAL' => $r['total'],
            'PHQ9_SEVERITY' => $r['severity'],
            'PHQ9_ITEM9_POSITIVE' => $yn($r['item9_positive']),
            'PHQ9_CLINICAL_REVIEW' => $yn($r['clinical_review']),
            'PHQ9_MISSING' => $r['missing'],
            'PHQ9_PRORATED' => $r['prorated'] ? 'Yes' : 'No',
            'PHQ9_ENGINE' => 'PHQ9-1.0',
        ];
    }
}
