<?php

namespace App\Forms\Calculators;

use App\Forms\Calculator;
use App\Forms\FormContext;

/** MARS-5: 5 items scored 1–5, total 5–25 (higher = better adherence). Any missing item → total null (no prorating). */
class Mars5Calculator extends Calculator
{
    public static function compute(array $data, FormContext $ctx): array
    {
        $v = array_map(fn ($i) => $data["MARS5_Q{$i}"] ?? null, range(1, 5));

        return [
            'MARS5_TOTAL' => in_array(null, $v, true) ? null : array_sum($v),
            'MARS5_MISSING' => count(array_filter($v, fn ($x) => $x === null)),
            'MARS5_ENGINE' => 'MARS5-1.0',
        ];
    }
}
