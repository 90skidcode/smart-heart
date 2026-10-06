<?php

namespace App\Forms;

/**
 * Validates submitted form data against a definition.
 *
 *  - errors:   hard problems (bad type, outside min/max, unknown option, future date).
 *              These block saving.
 *  - warnings: outside the plausible range. Accepted only with an override reason.
 *  - missing:  required visible fields left empty. These block "Mark complete".
 *
 * Hidden fields (show_if not satisfied) are ignored and cleared by clean().
 */
class FormValidator
{
    public static function isVisible(array $field, array $data): bool
    {
        if (empty($field['show_if'])) {
            return true;
        }
        $cond = $field['show_if'];

        return ($data[$cond['field']] ?? null) === $cond['equals'];
    }

    /**
     * Keep only known, visible, non-computed fields; trim strings; empty string => null.
     * Visibility is evaluated repeatedly so chained show_if rules settle.
     */
    public static function clean(string $formCode, array $input): array
    {
        $fields = FormRegistry::fields($formCode);
        $data = [];
        foreach ($fields as $code => $f) {
            if ($f['type'] === 'computed' || ! array_key_exists($code, $input)) {
                continue;
            }
            $v = $input[$code];
            if (is_string($v)) {
                $v = trim($v);
            }
            if ($v === '' || $v === []) {
                $v = null;
            }
            $data[$code] = $v;
        }
        for ($pass = 0; $pass < 3; $pass++) {
            foreach ($fields as $code => $f) {
                if (array_key_exists($code, $data) && ! self::isVisible($f, $data)) {
                    unset($data[$code]);
                }
            }
        }

        return $data;
    }

    public static function validate(string $formCode, array $data, ?string $today = null): array
    {
        $today ??= date('Y-m-d');
        $errors = [];
        $warnings = [];
        $missing = [];

        foreach (FormRegistry::fields($formCode) as $code => $f) {
            if ($f['type'] === 'computed' || ! self::isVisible($f, $data)) {
                continue;
            }
            $v = $data[$code] ?? null;

            if ($v === null) {
                if (! empty($f['required'])) {
                    $missing[] = $code;
                }
                continue;
            }

            switch ($f['type']) {
                case 'number':
                    if (! is_numeric($v)) {
                        $errors[$code] = 'Must be a number.';
                        break;
                    }
                    $n = (float) $v;
                    if (! empty($f['integer']) && floor($n) != $n) {
                        $errors[$code] = 'Must be a whole number.';
                        break;
                    }
                    if (isset($f['min']) && $n < $f['min']) {
                        $errors[$code] = "Must be at least {$f['min']}.";
                    } elseif (isset($f['max']) && $n > $f['max']) {
                        $errors[$code] = "Must be at most {$f['max']}.";
                    } elseif (isset($f['plausible_min']) && $n < $f['plausible_min']) {
                        $warnings[$code] = "Below the expected range (≥ {$f['plausible_min']}). Confirm with a reason.";
                    } elseif (isset($f['plausible_max']) && $n > $f['plausible_max']) {
                        $warnings[$code] = "Above the expected range (≤ {$f['plausible_max']}). Confirm with a reason.";
                    }
                    break;

                case 'date':
                    if (! self::isDate($v)) {
                        $errors[$code] = 'Must be a valid date (YYYY-MM-DD).';
                    } elseif (! empty($f['not_future']) && $v > $today) {
                        $errors[$code] = 'Date cannot be in the future.';
                    }
                    break;

                case 'time':
                    if (! is_string($v) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $v)) {
                        $errors[$code] = 'Must be a valid time (HH:MM).';
                    }
                    break;

                case 'radio':
                case 'select':
                    if (! in_array($v, $f['options'], true)) {
                        $errors[$code] = 'Not one of the allowed options.';
                    }
                    break;

                case 'text':
                    if (! is_string($v) || mb_strlen($v) > 500) {
                        $errors[$code] = 'Text must be under 500 characters.';
                    }
                    break;
            }
        }

        return ['errors' => $errors, 'warnings' => $warnings, 'missing' => $missing];
    }

    public static function isDate(mixed $v): bool
    {
        if (! is_string($v) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
