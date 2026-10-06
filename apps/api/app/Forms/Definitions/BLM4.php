<?php

/* BL-01 Module 4 · Clinical measurements. Clinic BP = mean of two readings (Calc::meanBp, vector B4). */
return [
    'code' => 'BL-M4',
    'title' => 'Clinical Measurements',
    'eyebrow' => 'BL-01 · Module 4',
    'description' => 'Two seated BP readings at least 1 minute apart; the mean is calculated. LVEF from SCR-01, with the echo date.',
    'group' => 'Baseline',
    'gate' => ['consented'],
    'calculator' => App\Forms\Calculators\ClinicalCalculator::class,
    'sections' => [
        [
            'title' => 'Blood pressure and heart rate',
            'entered_by' => 'Assessor',
            'fields' => [
                ['code' => 'BL_BP_DATE', 'label' => 'Date measured', 'type' => 'date', 'required' => true, 'not_future' => true],
                ['code' => 'BL_SBP1', 'label' => 'Reading 1 — systolic', 'type' => 'number', 'unit' => 'mmHg', 'integer' => true, 'required' => true, 'min' => 60, 'max' => 260, 'plausible_min' => 80, 'plausible_max' => 220],
                ['code' => 'BL_DBP1', 'label' => 'Reading 1 — diastolic', 'type' => 'number', 'unit' => 'mmHg', 'integer' => true, 'required' => true, 'min' => 30, 'max' => 160, 'plausible_min' => 40, 'plausible_max' => 130],
                ['code' => 'BL_SBP2', 'label' => 'Reading 2 — systolic', 'type' => 'number', 'unit' => 'mmHg', 'integer' => true, 'required' => true, 'min' => 60, 'max' => 260, 'plausible_min' => 80, 'plausible_max' => 220],
                ['code' => 'BL_DBP2', 'label' => 'Reading 2 — diastolic', 'type' => 'number', 'unit' => 'mmHg', 'integer' => true, 'required' => true, 'min' => 30, 'max' => 160, 'plausible_min' => 40, 'plausible_max' => 130],
                ['code' => 'BL_SBP_MEAN', 'label' => 'Mean systolic', 'type' => 'computed', 'unit' => 'mmHg'],
                ['code' => 'BL_DBP_MEAN', 'label' => 'Mean diastolic', 'type' => 'computed', 'unit' => 'mmHg'],
                ['code' => 'BL_HR', 'label' => 'Resting heart rate', 'type' => 'number', 'unit' => 'bpm', 'integer' => true, 'required' => true, 'min' => 25, 'max' => 220, 'plausible_min' => 40, 'plausible_max' => 130],
                ['code' => 'BL_SPO2', 'label' => 'SpO₂ (room air)', 'type' => 'number', 'unit' => '%', 'integer' => true, 'required' => true, 'min' => 50, 'max' => 100, 'plausible_min' => 88],
                ['code' => 'BL_RHYTHM', 'label' => 'Rhythm', 'type' => 'radio', 'required' => true, 'options' => ['Sinus', 'Atrial fibrillation / flutter', 'Other']],
            ],
        ],
        [
            'title' => 'Left ventricular function',
            'entered_by' => 'PI Enters',
            'fields' => [
                ['code' => 'BL_LVEF', 'label' => 'LVEF (from SCR-01)', 'type' => 'computed', 'unit' => '%'],
                ['code' => 'BL_LVEF_BAND', 'label' => 'LVEF band', 'type' => 'computed'],
                ['code' => 'BL_ECHO_DATE', 'label' => 'Date of the echo that gave this LVEF', 'type' => 'date', 'required' => true, 'not_future' => true],
            ],
        ],
    ],
];
