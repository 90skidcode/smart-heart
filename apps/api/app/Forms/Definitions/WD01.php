<?php

/* WD-01 · Withdrawal / end of participation. Signing it stops all data entry and app access. */
return [
    'code' => 'WD-01',
    'title' => 'Withdrawal',
    'eyebrow' => 'WD-01 · End of participation',
    'description' => 'Record why participation ended. Once signed, no further data can be entered and the participant app is switched off.',
    'group' => 'Study status',
    'gate' => ['consented'],
    'sections' => [[
        'title' => 'Withdrawal details',
        'entered_by' => 'PI Enters',
        'fields' => [
            ['code' => 'WD_DATE', 'label' => 'Date of withdrawal', 'type' => 'date', 'required' => true, 'not_future' => true],
            ['code' => 'WD_TYPE', 'label' => 'Type', 'type' => 'radio', 'required' => true, 'options' => [
                'Participant withdrew consent', 'Lost to follow-up', 'PI decision (safety)', 'PI decision (other)', 'Death', 'Other']],
            ['code' => 'WD_REASON', 'label' => 'Reason (as given)', 'type' => 'text', 'required' => true],
            ['code' => 'WD_KEEP_DATA', 'label' => 'May data collected so far be used?', 'type' => 'radio', 'required' => true,
                'options' => ['Yes', 'No — participant asked for it not to be used']],
            ['code' => 'WD_RECORDS_FOLLOWUP', 'label' => 'May outcomes still be collected from hospital records?', 'type' => 'radio', 'required' => true,
                'options' => ['Yes', 'No', 'Not applicable']],
            ['code' => 'WD_DEVICES_RETURNED', 'label' => 'Study devices returned', 'type' => 'radio', 'required' => true,
                'options' => ['Yes', 'No', 'Not issued']],
        ],
    ]],
];
