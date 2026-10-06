<?php

/*
 * CON-01 · Informed Consent Record
 * Not in the eCRF prototype as a panel; added because baseline must stay locked
 * until consent is recorded (Build doc, Section 2 "Form gating").
 * Unlocks only when SCR-01 is PI-signed with status ELIGIBLE.
 */
return [
    'code' => 'CON-01',
    'title' => 'Informed Consent Record',
    'eyebrow' => 'CON-01 · Consent',
    'description' => 'Record that written informed consent was obtained. Baseline forms stay locked until this form is PI-signed with consent given.',
    'group' => 'Consent',
    'gate' => ['eligible_signed'],
    'sections' => [
        [
            'title' => 'Consent Process',
            'entered_by' => 'PI Enters',
            'fields' => [
                ['code' => 'CON_DATE', 'label' => 'Date consent obtained', 'type' => 'date', 'required' => true, 'not_future' => true],
                ['code' => 'CON_TIME', 'label' => 'Time consent obtained', 'type' => 'time', 'required' => true],
                ['code' => 'CON_PIS_VERSION', 'label' => 'Participant Information Sheet version', 'type' => 'text', 'required' => true, 'default' => 'v3.0, Feb 2026'],
                ['code' => 'CON_LANGUAGE', 'label' => 'Language of consent', 'type' => 'radio', 'required' => true, 'options' => ['Tamil', 'English']],
                ['code' => 'CON_GIVEN', 'label' => 'Did the participant give consent?', 'type' => 'radio', 'required' => true, 'options' => ['Yes', 'No']],
                ['code' => 'CON_DECLINE_REASON', 'label' => 'Reason for declining (if offered)', 'type' => 'text', 'required' => false,
                    'show_if' => ['field' => 'CON_GIVEN', 'equals' => 'No']],
                ['code' => 'CON_SIGN_METHOD', 'label' => 'Participant signed by', 'type' => 'radio', 'required' => true,
                    'options' => ['Signature', 'Thumb impression'], 'show_if' => ['field' => 'CON_GIVEN', 'equals' => 'Yes']],
                ['code' => 'CON_WITNESS_NAME', 'label' => 'Impartial witness name', 'type' => 'text', 'required' => true,
                    'show_if' => ['field' => 'CON_SIGN_METHOD', 'equals' => 'Thumb impression']],
                ['code' => 'CON_TAKEN_BY', 'label' => 'Consent taken by (name)', 'type' => 'text', 'required' => true,
                    'show_if' => ['field' => 'CON_GIVEN', 'equals' => 'Yes']],
                ['code' => 'CON_COPY_GIVEN', 'label' => 'Signed copy given to participant?', 'type' => 'radio', 'required' => true,
                    'options' => ['Yes', 'No'], 'show_if' => ['field' => 'CON_GIVEN', 'equals' => 'Yes']],
                ['code' => 'CON_CAREGIVER_VIEW', 'label' => 'Agrees a nominated caregiver may view their daily tasks (if allocated to intervention)', 'type' => 'radio', 'required' => true,
                    'options' => ['Yes', 'No'], 'show_if' => ['field' => 'CON_GIVEN', 'equals' => 'Yes']],
            ],
        ],
    ],
];
