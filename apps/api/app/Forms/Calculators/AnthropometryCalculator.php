<?php

namespace App\Forms\Calculators;

use App\Calc\Calc;
use App\Forms\Calculator;
use App\Forms\FormContext;

class AnthropometryCalculator extends Calculator
{
    public static function compute(array $data, FormContext $ctx): array
    {
        $h = $data['BL_HEIGHT_CM'] ?? null;
        $w = $data['BL_WEIGHT_KG'] ?? null;
        $bmi = null;
        if (is_numeric($h) && is_numeric($w) && $h >= 120 && $h <= 220 && $w >= 30 && $w <= 250) {
            $bmi = Calc::bmi((float) $h, (float) $w);
        }
        $waist = $data['BL_WAIST_CM'] ?? null;
        $hip = $data['BL_HIP_CM'] ?? null;

        return [
            'BL_BMI' => $bmi,
            'BL_BMI_CATEGORY' => $bmi === null ? null : Calc::bmiCategoryAsian($bmi),
            'BL_WHR' => (is_numeric($waist) && is_numeric($hip) && $hip > 0) ? Calc::r($waist / $hip, 2) : null,
        ];
    }
}
