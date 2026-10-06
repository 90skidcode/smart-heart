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
            if (in_array($f['type'], ['computed', 'info'], true) || ! array_key_exists($code, $input)) {
                continue;
            }
            $v = $input[$code];
            if ($f['type'] === 'table') {
                $v = self::cleanTable($f, $v);
            } elseif ($f['type'] === 'checkboxes') {
                $v = is_array($v) ? array_values(array_intersect($f['options'], $v)) : null;
            }
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
            if (in_array($f['type'], ['computed', 'info'], true) || ! self::isVisible($f, $data)) {
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

                case 'choice':
                    if (! in_array($v, array_column($f['options'], 'value'), true)) {
                        $errors[$code] = 'Not one of the allowed answers.';
                    }
                    break;

                case 'checkboxes':
                    if (! is_array($v) || array_diff($v, $f['options'])) {
                        $errors[$code] = 'Not one of the allowed options.';
                    } elseif (! empty($f['required']) && ! $v) {
                        $missing[] = $code;
                    }
                    break;

                case 'scale':
                    if (! is_int($v) || $v < $f['min'] || $v > $f['max']) {
                        $errors[$code] = "Must be a whole number from {$f['min']} to {$f['max']}.";
                    }
                    break;

                case 'table':
                    $rowErrors = self::validateTable($f, $v, $today);
                    if ($rowErrors) {
                        $errors[$code] = $rowErrors;
                    } elseif (! empty($f['required']) && ! $v) {
                        $missing[] = $code;
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

    /** Keep known columns, trim strings, drop fully empty rows. */
    private static function cleanTable(array $f, mixed $rows): ?array
    {
        if (! is_array($rows)) {
            return null;
        }
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $clean = [];
            foreach ($f['columns'] as $col) {
                $v = $row[$col['code']] ?? null;
                if (is_string($v)) {
                    $v = trim($v);
                }
                if ($col['type'] === 'checkboxes') {
                    $v = is_array($v) ? array_values(array_intersect($col['options'], $v)) : [];
                }
                $clean[$col['code']] = ($v === '' ? null : $v);
            }
            if (array_filter($clean, fn ($v) => $v !== null && $v !== [])) {
                $out[] = $clean;
            }
        }

        return $out ?: null;
    }

    /** Validate each row of a table field. Returns "Row n: message" strings joined, or ''. */
    private static function validateTable(array $f, mixed $rows, string $today): string
    {
        if (! is_array($rows)) {
            return 'Invalid rows.';
        }
        $msgs = [];
        foreach (array_values($rows) as $i => $row) {
            foreach ($f['columns'] as $col) {
                $v = $row[$col['code']] ?? null;
                $n = $i + 1;
                if ($v === null || $v === []) {
                    if (! empty($col['required'])) {
                        $msgs[] = "Row {$n}: {$col['label']} is required.";
                    }
                    continue;
                }
                $bad = match ($col['type']) {
                    'select' => ! in_array($v, $col['options'], true),
                    'checkboxes' => ! is_array($v) || array_diff($v, $col['options']),
                    'date' => ! self::isDate($v) || (! empty($col['not_future']) && $v > $today),
                    'number' => ! is_numeric($v) || (isset($col['min']) && $v < $col['min']) || (isset($col['max']) && $v > $col['max']),
                    default => ! is_string($v) || mb_strlen($v) > 200,
                };
                if ($bad) {
                    $msgs[] = "Row {$n}: {$col['label']} is not valid.";
                }
            }
        }

        return implode(' ', $msgs);
    }

    public static function isDate(mixed $v): bool
    {
        if (! is_string($v) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }
}
