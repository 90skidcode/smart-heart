<?php

/*
 * BL-01 Module 5 · Laboratory data. Each result keeps the value and unit as entered;
 * the canonical value is computed (Calc::convertLab, vectors B5–B7). Friedewald LDL,
 * non-HDL and CKD-EPI 2021 eGFR are computed (B8–B13).
 */
$lab = function (string $key, string $label, array $units, array $extra = []) {
    $k = "BL_{$key}";

    return [
        ['code' => $k, 'label' => $label, 'type' => 'number', 'required' => $extra['required'] ?? true, 'min' => 0, 'max' => 5000],
        ['code' => "{$k}_UNIT", 'label' => "{$label} unit", 'type' => 'select', 'required' => $extra['required'] ?? true, 'options' => $units, 'default' => $units[0]],
        ['code' => "{$k}_DATE", 'label' => "{$label} sample date", 'type' => 'date', 'required' => $extra['required'] ?? true, 'not_future' => true],
    ];
};

return [
    'code' => 'BL-M5',
    'title' => 'Laboratory Data',
    'eyebrow' => 'BL-01 · Module 5',
    'description' => 'Enter each result with the unit printed on the lab report. Canonical values, Friedewald LDL, non-HDL and CKD-EPI 2021 eGFR are calculated.',
    'group' => 'Baseline',
    'gate' => ['consented'],
    'calculator' => App\Forms\Calculators\LabsCalculator::class,
    'sections' => [
        ['title' => 'Glycaemia', 'entered_by' => 'PI / Assessor', 'fields' => array_merge(
            $lab('HBA1C', 'HbA1c', ['%', 'mmol/mol']),
            $lab('FBG', 'Fasting glucose', ['mg/dL', 'mmol/L'], ['required' => false]),
            $lab('PPBG', 'Post-meal glucose', ['mg/dL', 'mmol/L'], ['required' => false]),
            [['code' => 'BL_HBA1C_PCT', 'label' => 'HbA1c (canonical)', 'type' => 'computed', 'unit' => '%']],
        )],
        ['title' => 'Lipids', 'entered_by' => 'PI / Assessor', 'fields' => array_merge(
            $lab('TC', 'Total cholesterol', ['mg/dL', 'mmol/L']),
            $lab('LDL', 'LDL cholesterol', ['mg/dL', 'mmol/L']),
            [['code' => 'BL_LDL_METHOD', 'label' => 'LDL method on the report', 'type' => 'radio', 'required' => true,
                'options' => ['Measured directly', 'Calculated by the lab (Friedewald)', 'Calculated by the lab (other formula)', 'Not stated']]],
            $lab('HDL', 'HDL cholesterol', ['mg/dL', 'mmol/L']),
            $lab('TG', 'Triglycerides', ['mg/dL', 'mmol/L']),
            [
                ['code' => 'BL_LDL_MGDL', 'label' => 'LDL (canonical)', 'type' => 'computed', 'unit' => 'mg/dL'],
                ['code' => 'BL_LDL_FRIEDEWALD', 'label' => 'Friedewald LDL (check)', 'type' => 'computed', 'unit' => 'mg/dL'],
                ['code' => 'BL_NON_HDL', 'label' => 'Non-HDL cholesterol', 'type' => 'computed', 'unit' => 'mg/dL'],
            ],
        )],
        ['title' => 'Renal function', 'entered_by' => 'PI / Assessor', 'fields' => array_merge(
            $lab('CREAT', 'Serum creatinine', ['mg/dL', 'µmol/L']),
            [
                ['code' => 'BL_EGFR_EQUATION', 'label' => 'eGFR equation used by the lab (for the SCR-01 value)', 'type' => 'select', 'required' => true,
                    'options' => ['CKD-EPI 2021', 'CKD-EPI 2009', 'MDRD', 'Cockcroft-Gault', 'Not stated']],
                ['code' => 'BL_EGFR_LAB', 'label' => 'eGFR as reported (from SCR-01)', 'type' => 'computed', 'unit' => 'mL/min/1.73m²'],
                ['code' => 'BL_EGFR_CKDEPI', 'label' => 'eGFR, CKD-EPI 2021 (calculated)', 'type' => 'computed', 'unit' => 'mL/min/1.73m²'],
                ['code' => 'BL_EGFR_DIFF_PCT', 'label' => 'Difference reported vs calculated', 'type' => 'computed', 'unit' => '%'],
            ],
        )],
    ],
];
