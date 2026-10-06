<?php

/* BL-01 Module 1 · Participant details (demographics). */
return [
    'code' => 'BL-M1',
    'title' => 'Participant Details',
    'eyebrow' => 'BL-01 · Module 1',
    'description' => 'Socio-demographic details. Date of birth and age come from SCR-01.',
    'group' => 'Baseline',
    'gate' => ['consented'],
    'sections' => [[
        'title' => 'Demographics',
        'entered_by' => 'PI Enters',
        'fields' => [
            ['code' => 'BL_SEX', 'label' => 'Sex', 'type' => 'radio', 'required' => true, 'options' => ['Male', 'Female', 'Other']],
            ['code' => 'BL_MARITAL', 'label' => 'Marital status', 'type' => 'select', 'required' => true,
                'options' => ['Married', 'Single', 'Widowed', 'Separated / divorced']],
            ['code' => 'BL_EDUCATION', 'label' => 'Highest education', 'type' => 'select', 'required' => true,
                'options' => ['No formal education', 'Primary (1–5)', 'Middle (6–8)', 'Secondary (9–10)', 'Higher secondary (11–12)', 'Diploma', 'Graduate', 'Postgraduate']],
            ['code' => 'BL_EMPLOYMENT', 'label' => 'Employment', 'type' => 'select', 'required' => true,
                'options' => ['Employed full-time', 'Employed part-time', 'Self-employed', 'Unemployed', 'Homemaker', 'Retired', 'Student']],
            ['code' => 'BL_OCCUPATION', 'label' => 'Occupation (if working)', 'type' => 'text', 'required' => false],
            ['code' => 'BL_RESIDENCE', 'label' => 'Residence', 'type' => 'radio', 'required' => true, 'options' => ['Urban', 'Semi-urban', 'Rural']],
            ['code' => 'BL_DISTANCE_KM', 'label' => 'Distance from home to hospital', 'type' => 'number', 'unit' => 'km', 'required' => false,
                'min' => 0, 'max' => 2000, 'plausible_max' => 500],
            ['code' => 'BL_INCOME', 'label' => 'Monthly household income', 'type' => 'select', 'required' => false,
                'options' => ['< ₹10,000', '₹10,000–25,000', '₹25,001–50,000', '₹50,001–1,00,000', '> ₹1,00,000', 'Prefers not to say']],
            ['code' => 'BL_LIVES_ALONE', 'label' => 'Lives alone?', 'type' => 'radio', 'required' => true, 'options' => ['Yes', 'No']],
            ['code' => 'BL_PREF_LANGUAGE', 'label' => 'Preferred language', 'type' => 'radio', 'required' => true, 'options' => ['Tamil', 'English']],
        ],
    ]],
];
