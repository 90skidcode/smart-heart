<?php

/*
 * SAF-01 · Baseline safety clearance before randomisation (eCRF prototype, panel SAF-01).
 * Opens when every BL-01 module and the required baseline PROs are complete. The PI
 * reviews the summary (computed from the other forms) and signs. Clearance = Yes and
 * a PI signature are the last gate before RAND-01.
 */
$yn = ['Yes', 'No'];

return [
    'code' => 'SAF-01',
    'title' => 'Baseline Safety Clearance',
    'eyebrow' => 'SAF-01 · Pre-randomisation',
    'description' => 'The PI reviews the baseline data below and confirms the participant is safe to randomise and to start the exercise protocol.',
    'group' => 'Safety',
    'gate' => ['consented', 'baseline_ready'],
    'calculator' => App\Forms\Calculators\SafetyCalculator::class,
    'sections' => [
        ['title' => 'Baseline summary (from the other forms)', 'entered_by' => 'System', 'fields' => [
            ['code' => 'SAF_SUM_BP', 'label' => 'Clinic BP (mean of 2)', 'type' => 'computed'],
            ['code' => 'SAF_SUM_HR', 'label' => 'Resting heart rate', 'type' => 'computed', 'unit' => 'bpm'],
            ['code' => 'SAF_SUM_SPO2', 'label' => 'SpO₂', 'type' => 'computed', 'unit' => '%'],
            ['code' => 'SAF_SUM_LVEF', 'label' => 'LVEF', 'type' => 'computed'],
            ['code' => 'SAF_SUM_EGFR', 'label' => 'eGFR (reported / CKD-EPI 2021)', 'type' => 'computed'],
            ['code' => 'SAF_SUM_6MWT', 'label' => '6MWT', 'type' => 'computed'],
            ['code' => 'SAF_SUM_DASI', 'label' => 'DASI', 'type' => 'computed'],
            ['code' => 'SAF_SUM_PHQ9', 'label' => 'PHQ-9', 'type' => 'computed'],
            ['code' => 'SAF_SUM_GAD7', 'label' => 'GAD-7', 'type' => 'computed'],
            ['code' => 'SAF_SUM_MEDS', 'label' => 'Medications', 'type' => 'computed'],
            ['code' => 'SAF_OPEN_ALERTS', 'label' => 'Open safety alerts', 'type' => 'computed'],
        ]],
        ['title' => 'PI checklist', 'entered_by' => 'PI Confirms', 'fields' => [
            ['code' => 'SAF_VITALS_OK', 'label' => 'Vitals reviewed and acceptable', 'type' => 'radio', 'required' => true, 'options' => $yn],
            ['code' => 'SAF_NO_ARRHYTHMIA', 'label' => 'No complex arrhythmia or cardiogenic shock since PCI', 'type' => 'radio', 'required' => true, 'options' => $yn],
            ['code' => 'SAF_NO_DECOMP', 'label' => 'No acute decompensation since PCI', 'type' => 'radio', 'required' => true, 'options' => $yn],
            ['code' => 'SAF_PHQ9_ITEM9_REVIEWED', 'label' => 'PHQ-9 item 9 status reviewed (and pathway followed if positive)', 'type' => 'radio', 'required' => true, 'options' => $yn],
            ['code' => 'SAF_MOOD_REVIEW_DONE', 'label' => 'PHQ-9 / GAD-7 ≥ 10 clinical review documented, if applicable', 'type' => 'radio', 'required' => true, 'options' => ['Yes', 'No', 'Not applicable']],
            ['code' => 'SAF_MEDS_OK', 'label' => 'Medications reviewed (DAPT and statin, unless contraindicated)', 'type' => 'radio', 'required' => true, 'options' => $yn],
            ['code' => 'SAF_EXERCISE_SAFE', 'label' => 'Safe to start a home exercise programme', 'type' => 'radio', 'required' => true, 'options' => $yn],
            ['code' => 'SAF_CLEARED', 'label' => 'Safety clearance for randomisation', 'type' => 'radio', 'required' => true,
                'options' => ['Yes', 'No']],
            ['code' => 'SAF_DEFER_REASON', 'label' => 'Reason clearance is not given (randomisation deferred)', 'type' => 'text', 'required' => true,
                'show_if' => ['field' => 'SAF_CLEARED', 'equals' => 'No']],
            ['code' => 'SAF_NOTES', 'label' => 'Notes', 'type' => 'text', 'required' => false],
        ]],
    ],
];
