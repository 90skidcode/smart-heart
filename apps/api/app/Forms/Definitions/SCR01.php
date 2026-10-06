<?php

/*
 * SCR-01 · Screening & Eligibility
 * Source: SMART_HEART_eCRF.html, panel "SCR-01" (Screens 1–8 + Eligibility Summary).
 *
 * Range keys:
 *   min / max                 hard limits — value is rejected outside them.
 *   plausible_min / _max      soft limits — value is accepted only with an override reason.
 *
 * Computed fields (SCR_AGE, SCR_PCI_DAYS, ELIG_*) are produced by EligibilityEngine,
 * never typed by staff.
 */
$yn = ['No', 'Yes'];
$ynu = ['No', 'Yes', 'Unknown'];

return [
    'code' => 'SCR-01',
    'title' => 'Eligibility Assessment',
    'eyebrow' => 'SCR-01 · Screening & Eligibility',
    'description' => 'Complete only the information required to determine eligibility. Each criterion is checked as you enter it; the participant cannot move to consent unless all criteria are met.',
    'group' => 'Screening',
    'gate' => ['reg_yes'],
    'calculator' => App\Forms\Calculators\ScreeningCalculator::class,
    'sections' => [
        [
            'title' => 'Screen 1 — Age',
            'entered_by' => 'PI Enters',
            'fields' => [
                ['code' => 'SCR_DATE', 'label' => 'Date of screening', 'type' => 'date', 'required' => true, 'not_future' => true],
                ['code' => 'SCR_DOB', 'label' => 'Date of birth', 'type' => 'date', 'required' => true, 'not_future' => true],
                ['code' => 'SCR_AGE', 'label' => 'Age (system-computed)', 'type' => 'computed', 'unit' => 'years'],
            ],
        ],
        [
            'title' => 'Screen 2 — Cardiovascular Diagnosis',
            'entered_by' => 'PI Enters',
            'fields' => [
                ['code' => 'SCR_DIAGNOSIS', 'label' => 'Index cardiovascular diagnosis', 'type' => 'radio', 'required' => true,
                    'options' => ['Acute Coronary Syndrome (ACS)', 'Stable Ischaemic Heart Disease', 'Other']],
                ['code' => 'SCR_DIAGNOSIS_OTHER', 'label' => 'Other diagnosis (specify)', 'type' => 'text', 'required' => true,
                    'show_if' => ['field' => 'SCR_DIAGNOSIS', 'equals' => 'Other']],
                ['code' => 'SCR_ACS_SUBTYPE', 'label' => 'ACS subtype', 'type' => 'radio', 'required' => true,
                    'options' => ['STEMI', 'NSTEMI', 'Unstable Angina'],
                    'show_if' => ['field' => 'SCR_DIAGNOSIS', 'equals' => 'Acute Coronary Syndrome (ACS)']],
            ],
        ],
        [
            'title' => 'Screen 3 — PCI',
            'entered_by' => 'PI Enters',
            'fields' => [
                ['code' => 'SCR_PCI_DONE', 'label' => 'Has participant undergone PCI?', 'type' => 'radio', 'required' => true, 'options' => ['Yes', 'No']],
                ['code' => 'SCR_PCI_DATE', 'label' => 'Date of PCI', 'type' => 'date', 'required' => true, 'not_future' => true,
                    'show_if' => ['field' => 'SCR_PCI_DONE', 'equals' => 'Yes']],
                ['code' => 'SCR_PCI_DAYS', 'label' => 'Days from PCI to screening (system-computed)', 'type' => 'computed', 'unit' => 'days',
                    'show_if' => ['field' => 'SCR_PCI_DONE', 'equals' => 'Yes']],
                ['code' => 'SCR_PCI_INDICATION', 'label' => 'PCI indication', 'type' => 'select', 'required' => true,
                    'options' => ['ACS', 'Stable IHD', 'Other'], 'show_if' => ['field' => 'SCR_PCI_DONE', 'equals' => 'Yes']],
                ['code' => 'SCR_PCI_VESSELS', 'label' => 'Vessels treated', 'type' => 'number', 'required' => true, 'integer' => true,
                    'min' => 1, 'max' => 5, 'show_if' => ['field' => 'SCR_PCI_DONE', 'equals' => 'Yes']],
                ['code' => 'SCR_PCI_STENTS', 'label' => 'Stents implanted', 'type' => 'number', 'required' => true, 'integer' => true,
                    'min' => 0, 'max' => 10, 'plausible_max' => 5, 'show_if' => ['field' => 'SCR_PCI_DONE', 'equals' => 'Yes']],
                ['code' => 'SCR_PCI_ACCESS', 'label' => 'Access site', 'type' => 'select', 'required' => true,
                    'options' => ['Femoral', 'Radial', 'Brachial'], 'show_if' => ['field' => 'SCR_PCI_DONE', 'equals' => 'Yes']],
            ],
        ],
        [
            'title' => 'Screen 4 — CABG (Exclusion Check)',
            'entered_by' => 'PI Confirms',
            'fields' => [
                ['code' => 'SCR_CABG', 'label' => 'Previous or current CABG?', 'type' => 'radio', 'required' => true, 'options' => $yn],
            ],
        ],
        [
            'title' => 'Screen 5 — High-Risk Cardiac Conditions',
            'entered_by' => 'PI Enters',
            'fields' => [
                ['code' => 'SCR_LVEF', 'label' => 'LVEF', 'type' => 'number', 'unit' => '%', 'required' => true, 'integer' => true,
                    'min' => 5, 'max' => 90, 'plausible_min' => 15, 'plausible_max' => 80],
                ['code' => 'SCR_LVEF_SOURCE', 'label' => 'LVEF source', 'type' => 'select', 'required' => true,
                    'options' => ['Echo report', 'Ventriculography', 'Cardiac MRI', 'Other']],
                ['code' => 'SCR_CARDIAC_ARREST', 'label' => 'Previous cardiac arrest', 'type' => 'radio', 'required' => true, 'options' => $ynu],
                ['code' => 'SCR_VENT_ARRHYTHMIA', 'label' => 'Complex ventricular arrhythmia', 'type' => 'radio', 'required' => true, 'options' => $ynu],
                ['code' => 'SCR_CARDIOGENIC_SHOCK', 'label' => 'Cardiogenic shock', 'type' => 'radio', 'required' => true, 'options' => $ynu],
            ],
        ],
        [
            'title' => 'Screen 6 — Diabetes / Renal / Blood Pressure',
            'entered_by' => 'PI Enters',
            'fields' => [
                ['code' => 'SCR_T2DM', 'label' => 'Type 2 diabetes?', 'type' => 'radio', 'required' => true, 'options' => $yn],
                ['code' => 'SCR_RETINOPATHY', 'label' => 'Diabetic retinopathy?', 'type' => 'radio', 'required' => true, 'options' => $ynu,
                    'show_if' => ['field' => 'SCR_T2DM', 'equals' => 'Yes']],
                ['code' => 'SCR_NEUROPATHY', 'label' => 'Peripheral neuropathy?', 'type' => 'radio', 'required' => true, 'options' => $ynu,
                    'show_if' => ['field' => 'SCR_T2DM', 'equals' => 'Yes']],
                ['code' => 'SCR_FOOT_ULCER', 'label' => 'Diabetic foot ulcer?', 'type' => 'radio', 'required' => true, 'options' => $ynu,
                    'show_if' => ['field' => 'SCR_T2DM', 'equals' => 'Yes']],
                ['code' => 'SCR_EGFR', 'label' => 'eGFR', 'type' => 'number', 'unit' => 'mL/min/1.73m²', 'required' => true,
                    'min' => 2, 'max' => 200, 'plausible_min' => 10, 'plausible_max' => 140],
                ['code' => 'SCR_SBP', 'label' => 'Screening BP — systolic', 'type' => 'number', 'unit' => 'mmHg', 'required' => true, 'integer' => true,
                    'min' => 50, 'max' => 300, 'plausible_min' => 70, 'plausible_max' => 250],
                ['code' => 'SCR_DBP', 'label' => 'Screening BP — diastolic', 'type' => 'number', 'unit' => 'mmHg', 'required' => true, 'integer' => true,
                    'min' => 20, 'max' => 200, 'plausible_min' => 40, 'plausible_max' => 140],
                ['code' => 'SCR_ON_ANTIHYPERTENSIVE', 'label' => 'Currently on antihypertensive treatment?', 'type' => 'radio', 'required' => true,
                    'options' => ['Yes', 'No'], 'note' => 'BP ≥ 160/100 excludes only when on treatment; untreated high BP is held for PI review.'],
            ],
        ],
        [
            'title' => 'Screen 7 — Sensory & Cognitive',
            'entered_by' => 'PI Assesses',
            'fields' => [
                ['code' => 'SCR_VISUAL', 'label' => 'Visual impairment', 'type' => 'radio', 'required' => true,
                    'options' => ['No', 'Yes — manageable with aids', 'Yes — prevents safe app use']],
                ['code' => 'SCR_HEARING', 'label' => 'Hearing impairment', 'type' => 'radio', 'required' => true,
                    'options' => ['No', 'Yes — manageable with aids', 'Yes — prevents safe app use']],
                ['code' => 'SCR_COGNITIVE', 'label' => 'Cognitive impairment', 'type' => 'radio', 'required' => true,
                    'options' => ['No', 'Yes — caregiver can support app use', 'Yes — prevents safe app use']],
            ],
        ],
        [
            'title' => 'Screen 8 — Digital Access',
            'subtitle' => 'DHRx is administered after eligibility is confirmed (PRO-01), not here.',
            'entered_by' => 'PI Confirms',
            'fields' => [
                ['code' => 'SCR_SMARTPHONE', 'label' => 'Smartphone access (participant or household member)?', 'type' => 'radio', 'required' => true, 'options' => ['Yes', 'No']],
                ['code' => 'SCR_PHONE_USER', 'label' => 'Primary smartphone user', 'type' => 'radio', 'required' => true,
                    'options' => ['Participant', 'Household member / caregiver'], 'show_if' => ['field' => 'SCR_SMARTPHONE', 'equals' => 'Yes']],
                ['code' => 'SCR_PHONE_OS', 'label' => 'Operating system', 'type' => 'radio', 'required' => true,
                    'options' => ['Android', 'iOS', 'Other'], 'show_if' => ['field' => 'SCR_SMARTPHONE', 'equals' => 'Yes']],
            ],
        ],
    ],
];
