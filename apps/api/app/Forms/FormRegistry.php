<?php

namespace App\Forms;

use App\Forms\Instruments\Instruments;
use App\Support\InstrumentTexts;
use InvalidArgumentException;

/**
 * All eCRF form definitions. Most live in Definitions/*.php; questionnaire forms
 * (PRO-*) are built from App\Forms\Instruments\Instruments so their items, response
 * values and wording come from one place.
 */
class FormRegistry
{
    /** Form code => definition file, or "instrument:KEY". Order = order in the participant record. */
    public const FORMS = [
        'REG-01' => 'REG01.php',
        'SCR-01' => 'SCR01.php',
        'CON-01' => 'CON01.php',
        'BL-M1' => 'BLM1.php',
        'BL-M2' => 'BLM2.php',
        'BL-M3' => 'BLM3.php',
        'BL-M4' => 'BLM4.php',
        'BL-M5' => 'BLM5.php',
        'BL-M6' => 'BLM6.php',
        'BL-M7' => 'BLM7.php',
        'BL-M8' => 'BLM8.php',
        'PRO-PHQ9' => 'instrument:PHQ9',
        'PRO-GAD7' => 'instrument:GAD7',
        'PRO-EQ5D' => 'instrument:EQ5D',
        'PRO-DASI' => 'instrument:DASI',
        'PRO-MARS5' => 'instrument:MARS5',
        'SAF-01' => 'SAF01.php',
        'WD-01' => 'WD01.php',
    ];

    /** Shown as locked placeholders until their inputs arrive. */
    public const PLANNED = [
        'PRO-DHRX' => ['DHRx · Digital Health Readiness', 'Awaiting the DHRx wording and scoring manual.'],
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
        $src = self::FORMS[$code];
        if (str_starts_with($src, 'instrument:')) {
            return self::instrumentForm(substr($src, 11)); // not cached: texts can change at runtime
        }

        return self::$cache[$code] ??= require __DIR__.'/Definitions/'.$src;
    }

    /** Build a PRO form definition from an instrument; staff entry uses the English text. */
    private static function instrumentForm(string $key): array
    {
        $inst = Instruments::get($key);
        $t = fn (string $k) => InstrumentTexts::text($key, 'en', $k);
        $fields = [];
        foreach ($inst['items'] as $n => $it) {
            $label = $t($it['code']) ?? 'Item '.($n + 1).' — licensed text not entered yet';
            if (isset($it['scale'])) {
                $fields[] = ['code' => $it['code'], 'label' => $label, 'type' => 'scale', 'min' => $it['scale'][0], 'max' => $it['scale'][1],
                    'required' => $it['required'] ?? true, 'low_label' => $t("{$it['code']}.low"), 'high_label' => $t("{$it['code']}.high")];

                continue;
            }
            $fields[] = ['code' => $it['code'], 'label' => $label, 'type' => 'choice', 'required' => $it['required'] ?? true,
                'options' => array_map(fn ($o) => ['value' => $o['value'], 'label' => $t("{$it['code']}.{$o['key']}") ?? (string) $o['value']], $it['options'])];
        }

        return [
            'code' => $inst['form'],
            'title' => $inst['title'],
            'eyebrow' => 'PRO-01 · Participant-reported',
            'description' => trim(($t('instructions') ?? '').' Completed by the participant (tablet self-entry). Scores are never shown to the participant.'),
            'group' => 'PROs',
            'gate' => ['consented', 'instrument_en:'.$key],
            'instrument' => $key,
            'licensed' => $inst['licensed'],
            'source' => $inst['source'],
            'self_entry' => true,
            'calculator' => $inst['calculator'],
            'sections' => [['title' => $inst['title'], 'entered_by' => 'Participant', 'fields' => $fields]],
        ];
    }

    /** Flat list of field definitions keyed by field code (table columns not expanded). */
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

    /** Names of the server-computed values a form stores (for exports and the data dictionary). */
    public static function computedKeys(string $code): array
    {
        $calc = self::get($code)['calculator'] ?? null;

        return $calc ? array_keys($calc::compute([], new FormContext(null, date('Y-m-d')))) : [];
    }

    /** Permission screen key: "SCR-01" => "form_scr01", any BL module => "form_baseline", any PRO => "form_pro". */
    public static function screenKey(string $code): string
    {
        return match (true) {
            str_starts_with($code, 'BL-') => 'form_baseline',
            str_starts_with($code, 'PRO-') => 'form_pro',
            default => 'form_'.strtolower(str_replace('-', '', $code)),
        };
    }
}
