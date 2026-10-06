<?php

namespace App\Forms\Calculators;

use App\Forms\Calculator;
use App\Forms\EligibilityEngine;
use App\Forms\FormContext;

class ScreeningCalculator extends Calculator
{
    public static function compute(array $data, FormContext $ctx): array
    {
        return EligibilityEngine::evaluate($data)['computed'];
    }

    /** Stop rule: a screen failure can be documented as soon as one exclusion is found. */
    public static function missingAllowed(array $computed): bool
    {
        return ($computed['ELIG_STATUS'] ?? null) === 'NOT_ELIGIBLE';
    }

    public static function signBlock(array $data, array $computed, FormContext $ctx): ?string
    {
        return ($computed['ELIG_STATUS'] ?? null) === 'INCOMPLETE'
            ? 'Eligibility is still pending (missing, "Unknown" or PI-review answers). Resolve them before signing.'
            : null;
    }
}
