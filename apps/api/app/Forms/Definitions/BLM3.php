<?php

/* BL-01 Module 3 · Anthropometry. BMI and category computed by Calc (Asian cut-offs, PROPOSED). */
return [
    'code' => 'BL-M3',
    'title' => 'Anthropometry',
    'eyebrow' => 'BL-01 · Module 3',
    'description' => 'Height, weight and waist. BMI is calculated, never typed.',
    'group' => 'Baseline',
    'gate' => ['consented'],
    'calculator' => App\Forms\Calculators\AnthropometryCalculator::class,
    'sections' => [[
        'title' => 'Measurements',
        'entered_by' => 'Assessor',
        'fields' => [
            ['code' => 'BL_MEAS_DATE', 'label' => 'Date measured', 'type' => 'date', 'required' => true, 'not_future' => true],
            ['code' => 'BL_HEIGHT_CM', 'label' => 'Height', 'type' => 'number', 'unit' => 'cm', 'required' => true, 'min' => 120, 'max' => 220, 'plausible_min' => 135, 'plausible_max' => 200],
            ['code' => 'BL_WEIGHT_KG', 'label' => 'Weight', 'type' => 'number', 'unit' => 'kg', 'required' => true, 'min' => 30, 'max' => 250, 'plausible_min' => 35, 'plausible_max' => 160],
            ['code' => 'BL_WAIST_CM', 'label' => 'Waist circumference', 'type' => 'number', 'unit' => 'cm', 'required' => true, 'min' => 40, 'max' => 200, 'plausible_min' => 55, 'plausible_max' => 150],
            ['code' => 'BL_HIP_CM', 'label' => 'Hip circumference', 'type' => 'number', 'unit' => 'cm', 'required' => false, 'min' => 50, 'max' => 200],
            ['code' => 'BL_BMI', 'label' => 'BMI', 'type' => 'computed', 'unit' => 'kg/m²'],
            ['code' => 'BL_BMI_CATEGORY', 'label' => 'BMI category (Asian cut-offs, PROPOSED)', 'type' => 'computed'],
            ['code' => 'BL_WHR', 'label' => 'Waist–hip ratio', 'type' => 'computed'],
        ],
    ]],
];
