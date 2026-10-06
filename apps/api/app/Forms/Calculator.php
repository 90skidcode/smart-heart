<?php

namespace App\Forms;

/**
 * Optional per-form logic, named in a definition's 'calculator' key.
 * Every clinical calculation itself lives in App\Calc\Calc; calculators only map
 * form fields to Calc inputs and back.
 */
abstract class Calculator
{
    /** Server-computed fields stored with the form (never typed by staff). */
    public static function compute(array $data, FormContext $ctx): array
    {
        return [];
    }

    /** Cross-field validation errors, field => message. These block saving. */
    public static function validate(array $data, FormContext $ctx): array
    {
        return [];
    }

    /** True when the form may be completed with required fields still empty (e.g. SCR-01 stop rule). */
    public static function missingAllowed(array $computed): bool
    {
        return false;
    }

    /** A message when the complete form must not be signed yet, else null. */
    public static function signBlock(array $data, array $computed, FormContext $ctx): ?string
    {
        return null;
    }
}
