<?php

/* BL-01 Module 8 · Functional assessment: 6-Minute Walk Test (assessor-administered). DASI is in PRO-01. */
return [
    'code' => 'BL-M8',
    'title' => 'Functional Assessment (6MWT)',
    'eyebrow' => 'BL-01 · Module 8',
    'description' => '6-Minute Walk Test done in clinic per the ATS protocol. DASI is completed by the participant in PRO-01.',
    'group' => 'Baseline',
    'gate' => ['consented'],
    'calculator' => App\Forms\Calculators\FunctionalCalculator::class,
    'sections' => [[
        'title' => '6-Minute Walk Test',
        'entered_by' => 'Assessor',
        'fields' => [
            ['code' => 'BL_6MWT_DONE', 'label' => '6MWT done?', 'type' => 'radio', 'required' => true, 'options' => ['Yes', 'No']],
            ['code' => 'BL_6MWT_NOT_DONE_REASON', 'label' => 'Reason not done', 'type' => 'select', 'required' => true,
                'options' => ['Unsafe in PI judgement', 'Participant declined', 'Mobility limitation', 'Not done before discharge', 'Other'],
                'show_if' => ['field' => 'BL_6MWT_DONE', 'equals' => 'No']],
            ['code' => 'BL_6MWT_DATE', 'label' => 'Test date', 'type' => 'date', 'required' => true, 'not_future' => true, 'show_if' => ['field' => 'BL_6MWT_DONE', 'equals' => 'Yes']],
            ['code' => 'BL_6MWT_M', 'label' => 'Distance walked', 'type' => 'number', 'unit' => 'm', 'integer' => true, 'required' => true,
                'min' => 0, 'max' => 1000, 'plausible_min' => 50, 'plausible_max' => 750, 'show_if' => ['field' => 'BL_6MWT_DONE', 'equals' => 'Yes']],
            ['code' => 'BL_6MWT_HR_PRE', 'label' => 'Heart rate before', 'type' => 'number', 'unit' => 'bpm', 'integer' => true, 'required' => true,
                'min' => 25, 'max' => 220, 'show_if' => ['field' => 'BL_6MWT_DONE', 'equals' => 'Yes']],
            ['code' => 'BL_6MWT_HR_POST', 'label' => 'Heart rate after', 'type' => 'number', 'unit' => 'bpm', 'integer' => true, 'required' => true,
                'min' => 25, 'max' => 220, 'show_if' => ['field' => 'BL_6MWT_DONE', 'equals' => 'Yes']],
            ['code' => 'BL_6MWT_SPO2_PRE', 'label' => 'SpO₂ before', 'type' => 'number', 'unit' => '%', 'integer' => true, 'required' => true,
                'min' => 50, 'max' => 100, 'show_if' => ['field' => 'BL_6MWT_DONE', 'equals' => 'Yes']],
            ['code' => 'BL_6MWT_SPO2_POST', 'label' => 'SpO₂ after', 'type' => 'number', 'unit' => '%', 'integer' => true, 'required' => true,
                'min' => 50, 'max' => 100, 'show_if' => ['field' => 'BL_6MWT_DONE', 'equals' => 'Yes']],
            ['code' => 'BL_6MWT_BORG', 'label' => 'Borg dyspnoea (0–10) at end', 'type' => 'number', 'required' => true,
                'min' => 0, 'max' => 10, 'show_if' => ['field' => 'BL_6MWT_DONE', 'equals' => 'Yes']],
            ['code' => 'BL_6MWT_STOPPED', 'label' => 'Stopped early or rested?', 'type' => 'radio', 'required' => true, 'options' => ['No', 'Yes'],
                'show_if' => ['field' => 'BL_6MWT_DONE', 'equals' => 'Yes']],
            ['code' => 'BL_6MWT_STOP_REASON', 'label' => 'Reason', 'type' => 'text', 'required' => true, 'show_if' => ['field' => 'BL_6MWT_STOPPED', 'equals' => 'Yes']],
            ['code' => 'BL_6MWT_CATEGORY', 'label' => '6MWT category (portal bands, PROPOSED)', 'type' => 'computed'],
        ],
    ]],
];
