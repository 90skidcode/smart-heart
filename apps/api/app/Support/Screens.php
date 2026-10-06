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
        'form_baseline' => ['group' => 'eCRF forms', 'label' => 'BL-01 Baseline modules 1–8', 'write_label' => 'Enter / edit'],
        'form_pro' => ['group' => 'eCRF forms', 'label' => 'PRO-01 Questionnaires (responses)', 'write_label' => 'Enter / start tablet self-entry'],
        'form_saf01' => ['group' => 'eCRF forms', 'label' => 'SAF-01 Safety clearance', 'write_label' => 'Enter / edit'],
        'form_wd01' => ['group' => 'eCRF forms', 'label' => 'WD-01 Withdrawal', 'write_label' => 'Enter / edit'],
        'ccsps' => ['group' => 'eCRF forms', 'label' => 'CCSPS composite score', 'write_label' => null],
        'randomisation' => ['group' => 'Randomisation', 'label' => 'Randomisation status', 'write_label' => 'Randomise participants'],
        'randomisation_list' => ['group' => 'Randomisation', 'label' => 'Allocation list', 'write_label' => 'Upload / extend list'],
        'view_allocation' => ['group' => 'Randomisation', 'label' => 'See allocated arm (unblinded)', 'write_label' => null],
        'alerts' => ['group' => 'Safety', 'label' => 'Safety alerts', 'write_label' => 'Acknowledge / close'],
        'sign_forms' => ['group' => 'eCRF control', 'label' => 'E-signature', 'write_label' => 'Sign forms (PI)'],
        'unlock_forms' => ['group' => 'eCRF control', 'label' => 'Unlock signed forms', 'write_label' => 'Unlock with reason'],
        'clinician_dashboard' => ['group' => 'Monitoring (Phase 2)', 'label' => 'Intervention monitoring dashboard', 'write_label' => 'Push care plans'],
        'control_arm' => ['group' => 'Monitoring (Phase 2)', 'label' => 'Control-arm follow-up', 'write_label' => 'Create / send links'],
        'export_deidentified' => ['group' => 'Data', 'label' => 'De-identified export', 'write_label' => null],
        'export_identified' => ['group' => 'Data', 'label' => 'Identified export (names, phones)', 'write_label' => null],
        'audit' => ['group' => 'Data', 'label' => 'Audit trail', 'write_label' => null],
        'instruments' => ['group' => 'Administration', 'label' => 'Questionnaire texts & EQ-5D value set', 'write_label' => 'Edit'],
        'scoring' => ['group' => 'Administration', 'label' => 'CCSPS scoring thresholds', 'write_label' => 'Draft / approve'],
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
