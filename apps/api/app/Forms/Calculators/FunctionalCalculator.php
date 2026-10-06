<?php

namespace App\Forms\Calculators;

use App\Calc\Calc;
use App\Forms\Calculator;
use App\Forms\FormContext;

class FunctionalCalculator extends Calculator
{
    public static function compute(array $data, FormContext $ctx): array
    {
        $m = $data['BL_6MWT_M'] ?? null;

        return ['BL_6MWT_CATEGORY' => (($data['BL_6MWT_DONE'] ?? null) === 'Yes' && is_numeric($m)) ? Calc::sixMwtCategory((int) $m) : null];
    }
}
