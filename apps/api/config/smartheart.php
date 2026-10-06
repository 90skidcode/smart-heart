<?php

return [
    'study_name' => env('STUDY_NAME', 'SMART-HEART'),
    'site_name' => env('STUDY_SITE', 'Sri Ramachandra Medical Centre'),

    // Generated IDs: SMART-HEART-0001 and SCR-0001. Set the start number if
    // paper CRFs have already used some numbers (e.g. 31 => next is 0032).
    'study_id_prefix' => env('STUDY_ID_PREFIX', 'SMART-HEART-'),
    'screening_id_prefix' => env('SCREENING_ID_PREFIX', 'SCR-'),
    'id_start' => (int) env('STUDY_ID_START', 1),
    'id_digits' => 4,

    'security' => [
        'idle_minutes' => (int) env('SESSION_IDLE_MINUTES', 15),
        'max_session_hours' => (int) env('SESSION_MAX_HOURS', 12),
        'lockout_attempts' => (int) env('LOGIN_LOCKOUT_ATTEMPTS', 5),
        'lockout_minutes' => (int) env('LOGIN_LOCKOUT_MINUTES', 15),
        'password_min_length' => 10,
    ],

    'eligibility' => [
        // Phone operating systems accepted at SCR-01 screen 8. The trial app is Android only;
        // add 'ios' here if the PI decides iPhone owners may join.
        'allowed_os' => array_filter(explode(',', env('ELIGIBLE_PHONE_OS', 'android'))),
    ],

    'baseline' => [
        // PRO-01 questionnaires that must be complete before SAF-01 / randomisation.
        // DHRx joins this list once its wording and scoring manual are available.
        'required_pros' => array_filter(explode(',', env('BASELINE_REQUIRED_PROS', 'PRO-PHQ9,PRO-GAD7,PRO-EQ5D,PRO-DASI,PRO-MARS5'))),
    ],

    'scoring' => [
        // PHQ-9 / GAD-7: missing items allowed before a total is prorated. 0 = never prorate (spec default until the SAP decides).
        'prorate_max_missing' => (int) env('PRO_PRORATE_MAX_MISSING', 0),
        'eq5d_value_set' => 'India (Jyani et al. 2022)',
    ],

    'ccsps' => [
        // SAP decision: complete_case (all 10 domains) | proportional (≥ min domains) | undecided
        'normalisation' => env('CCSPS_NORMALISATION', 'undecided'),
        'min_domains_proportional' => 8,
    ],

    'alerts' => [
        'critical_emails' => array_filter(array_map('trim', explode(',', env('ALERT_CRITICAL_EMAILS', '')))),
        'escalation_emails' => array_filter(array_map('trim', explode(',', env('ALERT_ESCALATION_EMAILS', '')))),
        'escalate_after_hours' => (int) env('ALERT_ESCALATE_AFTER_HOURS', 2),
        'coordinator_phone' => env('STUDY_COORDINATOR_PHONE', ''),
        // Shown to the participant on the tablet when PHQ-9 item 9 is positive (numbers from the calculation spec).
        'support_message' => 'Thank you for answering. Because of one of your answers, a member of the study team will talk with you today. '
            .'If you feel unsafe right now, please call Tele-MANAS on 14416 or 1800-891-4416 (free, 24 hours), or 104. Study coordinator: :phone',
    ],

    'self_entry' => [
        'minutes' => (int) env('SELF_ENTRY_MINUTES', 60),
    ],

    // Reasons offered when a saved value is changed (free text allowed with "Other").
    'change_reasons' => [
        'Transcription error',
        'Data entry correction',
        'New information from source document',
        'Clarified with participant',
        'Other',
    ],

    'signature_meanings' => [
        'SCR-01' => "I confirm that I have reviewed the participant's clinical information and that the above eligibility decision is accurate.",
        'SAF-01' => 'I have reviewed the baseline data and confirm the safety decision recorded on this form.',
        'WD-01' => 'I confirm the withdrawal details recorded on this form.',
        'default' => 'I confirm that the data in this form is accurate and complete, and matches the source documents.',
    ],

    // Blinded arm codes for the statistician's export.
    'arm_codes' => [
        'intervention' => env('ARM_CODE_INTERVENTION', 'A'),
        'control' => env('ARM_CODE_CONTROL', 'B'),
    ],

    'backup' => [
        'keep_days' => (int) env('BACKUP_KEEP_DAYS', 30),
        'key' => env('BACKUP_KEY'),
    ],

    // First administrator, created by "php artisan db:seed".
    'seed_admin' => [
        'name' => env('SEED_ADMIN_NAME', 'System Administrator'),
        'email' => env('SEED_ADMIN_EMAIL', 'admin@smartheart.local'),
        'password' => env('SEED_ADMIN_PASSWORD'),
    ],
];
