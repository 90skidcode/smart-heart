<?php

namespace App\Forms;

use InvalidArgumentException;

/**
 * Loads eCRF form definitions. Each definition lists sections and fields with
 * field codes matching SMART_HEART_eCRF.html, plus validation ranges.
 * Plain PHP: no framework dependency, so it can be unit-tested anywhere.
 */
class FormRegistry
{
    /** Form code => definition file. Order = order shown in the participant record. */
    public const FORMS = [
        'REG-01' => 'REG01.php',
        'SCR-01' => 'SCR01.php',
        'CON-01' => 'CON01.php',
    ];

    /** Forms planned for later phases; shown as locked placeholders in the UI. */
    public const PLANNED = [
        'BL-01' => 'Baseline CRF',
        'PRO-01' => 'Participant PROs (tablet self-entry)',
        'SAF-01' => 'Safety Clearance',
        'RAND-01' => 'Randomisation',
    ];

    private static array $cache = [];

    public static function codes(): array
    {
        return array_keys(self::FORMS);
    }

    public static function exists(string $code): bool
    {
        return isset(self::FORMS[$code]);
    }

    public static function get(string $code): array
    {
        if (! self::exists($code)) {
            throw new InvalidArgumentException("Unknown form {$code}");
        }

        return self::$cache[$code] ??= require __DIR__.'/Definitions/'.self::FORMS[$code];
    }

    /** Flat list of field definitions keyed by field code. */
    public static function fields(string $code): array
    {
        $out = [];
        foreach (self::get($code)['sections'] as $section) {
            foreach ($section['fields'] as $field) {
                $out[$field['code']] = $field;
            }
        }

        return $out;
    }

    /** Permission screen key for a form, e.g. "SCR-01" => "form_scr01". */
    public static function screenKey(string $code): string
    {
        return 'form_'.strtolower(str_replace('-', '', $code));
    }
}
