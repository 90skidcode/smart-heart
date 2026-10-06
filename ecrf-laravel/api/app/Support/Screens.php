<?php

namespace App\Support;

/**
 * Every screen/action the roles matrix controls. The admin assigns read and
 * write per role in the Roles screen. Write always implies read.
 * Add new keys here as later phases add screens; existing roles get no access
 * to a new screen until the admin grants it.
 */
class Screens
{
    public const ALL = [
        'dashboard' => ['group' => 'General', 'label' => 'Study dashboard', 'write_label' => null],
        'participants' => ['group' => 'Participants', 'label' => 'Participant list & identity details', 'write_label' => 'Register / edit identity'],
        'form_reg01' => ['group' => 'eCRF forms', 'label' => 'REG-01 Registration', 'write_label' => 'Enter / edit'],
        'form_scr01' => ['group' => 'eCRF forms', 'label' => 'SCR-01 Screening & Eligibility', 'write_label' => 'Enter / edit'],
        'form_con01' => ['group' => 'eCRF forms', 'label' => 'CON-01 Consent', 'write_label' => 'Enter / edit'],
        'sign_forms' => ['group' => 'eCRF control', 'label' => 'E-signature', 'write_label' => 'Sign forms (PI)'],
        'unlock_forms' => ['group' => 'eCRF control', 'label' => 'Unlock signed forms', 'write_label' => 'Unlock with reason'],
        'clinician_dashboard' => ['group' => 'Monitoring (Phase 2)', 'label' => 'Intervention monitoring dashboard', 'write_label' => 'Push care plans'],
        'control_arm' => ['group' => 'Monitoring (Phase 2)', 'label' => 'Control-arm follow-up', 'write_label' => 'Create / send links'],
        'export_deidentified' => ['group' => 'Data', 'label' => 'De-identified export', 'write_label' => null],
        'export_identified' => ['group' => 'Data', 'label' => 'Identified export (names, phones)', 'write_label' => null],
        'audit' => ['group' => 'Data', 'label' => 'Audit trail', 'write_label' => null],
        'users' => ['group' => 'Administration', 'label' => 'Users', 'write_label' => 'Create / edit / deactivate'],
        'roles' => ['group' => 'Administration', 'label' => 'Roles & permissions', 'write_label' => 'Edit matrix'],
    ];

    /** Screens the system Technical Admin role can never lose (prevents lock-out). */
    public const ADMIN_PROTECTED = ['users', 'roles', 'audit'];

    public static function keys(): array
    {
        return array_keys(self::ALL);
    }
}
