<?php

/*
 * REG-01 · Participant Registration (pre-screening)
 * Source: SMART_HEART_eCRF.html, panel "REG-01".
 * Identity fields (name, phone, hospital number, address) are stored on the
 * participant record, not in this form, so de-identified exports can drop them.
 */
return [
    'code' => 'REG-01',
    'title' => 'Participant Registration',
    'eyebrow' => 'REG-01 · Pre-Screening',
    'description' => 'Create a minimal record before screening begins. No eligibility decisions at this stage.',
    'group' => 'Screening',
    'gate' => [],
    'sections' => [
        [
            'title' => 'Referral Information',
            'entered_by' => 'PI Enters',
            'fields' => [
                ['code' => 'REG_DATE', 'label' => 'Date of registration', 'type' => 'date', 'required' => true, 'not_future' => true],
                ['code' => 'REG_REFERRAL_SOURCE', 'label' => 'Source of referral', 'type' => 'radio', 'required' => true,
                    'options' => ['PCI Unit — inpatient', 'Cardiology OPD', 'Cardiology Ward', 'Other']],
                ['code' => 'REG_REFERRAL_OTHER', 'label' => 'Other referral source (specify)', 'type' => 'text', 'required' => true,
                    'show_if' => ['field' => 'REG_REFERRAL_SOURCE', 'equals' => 'Other']],
                ['code' => 'REG_POTENTIALLY_ELIGIBLE', 'label' => 'Potentially eligible post-PCI participant identified?', 'type' => 'radio', 'required' => true,
                    'options' => ['Yes', 'No']],
            ],
        ],
    ],
];
