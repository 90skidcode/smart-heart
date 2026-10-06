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
