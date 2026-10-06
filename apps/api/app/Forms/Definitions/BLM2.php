<?php

/* BL-01 Module 2 · Cardiovascular profile. Index event and PCI details are carried from SCR-01. */
$yn = ['No', 'Yes'];

return [
    'code' => 'BL-M2',
    'title' => 'Cardiovascular Profile',
    'eyebrow' => 'BL-01 · Module 2',
    'description' => 'Index event, coronary anatomy, history and comorbidities. Diagnosis and PCI date are taken from SCR-01.',
    'group' => 'Baseline',
    'gate' => ['consented'],
    'calculator' => App\Forms\Calculators\CvProfileCalculator::class,
    'sections' => [
        [
            'title' => 'Index event',
            'entered_by' => 'PI Enters',
            'fields' => [
                ['code' => 'BL_INDEX_DX', 'label' => 'Index diagnosis (from SCR-01)', 'type' => 'computed'],
                ['code' => 'BL_PCI_DATE', 'label' => 'PCI date (from SCR-01)', 'type' => 'computed'],
                ['code' => 'BL_ADMIT_DATE', 'label' => 'Date of admission for the index event', 'type' => 'date', 'required' => true, 'not_future' => true],
                ['code' => 'BL_KILLIP', 'label' => 'Killip class at presentation', 'type' => 'radio', 'required' => true, 'options' => ['I', 'II', 'III', 'IV', 'Not applicable (stable IHD)']],
                ['code' => 'BL_NYHA', 'label' => 'NYHA class at discharge', 'type' => 'radio', 'required' => true, 'options' => ['I', 'II', 'III', 'IV']],
                ['code' => 'BL_CULPRIT', 'label' => 'Culprit / treated vessel', 'type' => 'checkboxes', 'required' => true, 'options' => ['LM', 'LAD', 'LCx', 'RCA', 'Other']],
                ['code' => 'BL_DISEASED_VESSELS', 'label' => 'Number of diseased vessels', 'type' => 'radio', 'required' => true, 'options' => ['1', '2', '3']],
                ['code' => 'BL_RESIDUAL_DISEASE', 'label' => 'Residual significant disease after PCI?', 'type' => 'radio', 'required' => true, 'options' => $yn],
                ['code' => 'BL_STENT_TYPE', 'label' => 'Stent type', 'type' => 'select', 'required' => true, 'options' => ['Drug-eluting', 'Bare-metal', 'Drug-coated balloon only', 'None']],
                ['code' => 'BL_DISCHARGE_DATE', 'label' => 'Discharge date', 'type' => 'date', 'required' => true, 'not_future' => true],
            ],
        ],
        [
            'title' => 'History and comorbidities',
            'entered_by' => 'PI Enters',
            'fields' => [
                ['code' => 'BL_PRIOR_MI', 'label' => 'Previous MI (before the index event)', 'type' => 'radio', 'required' => true, 'options' => $yn],
                ['code' => 'BL_PRIOR_PCI', 'label' => 'Previous PCI', 'type' => 'radio', 'required' => true, 'options' => $yn],
                ['code' => 'BL_HTN', 'label' => 'Hypertension', 'type' => 'radio', 'required' => true, 'options' => $yn],
                ['code' => 'BL_T2DM', 'label' => 'Type 2 diabetes (from SCR-01)', 'type' => 'computed'],
                ['code' => 'BL_DM_DURATION', 'label' => 'Years since diabetes diagnosis', 'type' => 'number', 'unit' => 'years', 'required' => false, 'min' => 0, 'max' => 80],
                ['code' => 'BL_DYSLIPIDAEMIA', 'label' => 'Dyslipidaemia', 'type' => 'radio', 'required' => true, 'options' => $yn],
                ['code' => 'BL_CKD', 'label' => 'Chronic kidney disease', 'type' => 'radio', 'required' => true, 'options' => $yn],
                ['code' => 'BL_STROKE', 'label' => 'Stroke / TIA', 'type' => 'radio', 'required' => true, 'options' => $yn],
                ['code' => 'BL_PAD', 'label' => 'Peripheral arterial disease', 'type' => 'radio', 'required' => true, 'options' => $yn],
                ['code' => 'BL_HF', 'label' => 'Heart failure', 'type' => 'radio', 'required' => true, 'options' => $yn],
                ['code' => 'BL_COPD', 'label' => 'COPD / asthma', 'type' => 'radio', 'required' => true, 'options' => $yn],
                ['code' => 'BL_THYROID', 'label' => 'Thyroid disease', 'type' => 'radio', 'required' => true, 'options' => $yn],
                ['code' => 'BL_FAMILY_CAD', 'label' => 'Family history of premature CAD', 'type' => 'radio', 'required' => true, 'options' => ['No', 'Yes', 'Unknown']],
                ['code' => 'BL_OTHER_COMORBID', 'label' => 'Other comorbidities', 'type' => 'text', 'required' => false],
            ],
        ],
    ],
];
