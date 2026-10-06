<?php

/*
 * BL-01 Module 6 · Medications at discharge. One row per drug. Once this module is
 * PI-signed, the list is what the participant app shows (Phase 3) — the app never
 * keeps its own editable copy.
 */
return [
    'code' => 'BL-M6',
    'title' => 'Medications',
    'eyebrow' => 'BL-01 · Module 6',
    'description' => 'Discharge medications, verified against the discharge summary. The signed list is sent to the participant app.',
    'group' => 'Baseline',
    'gate' => ['consented'],
    'calculator' => App\Forms\Calculators\MedicationCalculator::class,
    'sections' => [[
        'title' => 'Medication list',
        'entered_by' => 'PI Enters',
        'fields' => [
            ['code' => 'BL_MEDS', 'label' => 'Medications', 'type' => 'table', 'required' => true, 'columns' => [
                ['code' => 'class', 'label' => 'Drug class', 'type' => 'select', 'required' => true, 'options' => [
                    'Antiplatelet — aspirin', 'Antiplatelet — P2Y12 inhibitor', 'Anticoagulant', 'Statin', 'Other lipid-lowering',
                    'Beta-blocker', 'ACE inhibitor', 'ARB', 'ARNI', 'MRA', 'SGLT2 inhibitor', 'Other antidiabetic', 'Insulin',
                    'Calcium channel blocker', 'Diuretic', 'Nitrate', 'Proton pump inhibitor', 'Other']],
                ['code' => 'drug', 'label' => 'Drug name', 'type' => 'text', 'required' => true],
                ['code' => 'dose', 'label' => 'Dose', 'type' => 'text', 'required' => true, 'placeholder' => 'e.g. 75 mg'],
                ['code' => 'frequency', 'label' => 'Frequency', 'type' => 'select', 'required' => true,
                    'options' => ['Once daily', 'Twice daily', 'Three times daily', 'Four times daily', 'At night', 'Weekly', 'As needed']],
                ['code' => 'times', 'label' => 'Times', 'type' => 'checkboxes', 'required' => false, 'options' => ['Morning', 'Afternoon', 'Evening', 'Night']],
                ['code' => 'start', 'label' => 'Start date', 'type' => 'date', 'required' => false, 'not_future' => true],
            ]],
            ['code' => 'BL_MEDS_SOURCE', 'label' => 'Verified against', 'type' => 'radio', 'required' => true,
                'options' => ['Discharge summary', 'Prescription', 'Medication packs seen', 'Participant / caregiver report']],
            ['code' => 'BL_DAPT', 'label' => 'On dual antiplatelet therapy (DAPT)', 'type' => 'computed'],
            ['code' => 'BL_ON_STATIN', 'label' => 'On a statin', 'type' => 'computed'],
            ['code' => 'BL_ON_BETABLOCKER', 'label' => 'On a beta-blocker', 'type' => 'computed'],
            ['code' => 'BL_MED_COUNT', 'label' => 'Number of medicines', 'type' => 'computed'],
        ],
    ]],
];
