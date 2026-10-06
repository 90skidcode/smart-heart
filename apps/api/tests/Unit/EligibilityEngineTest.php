<?php

namespace Tests\Unit;

use App\Forms\EligibilityEngine;
use App\Forms\FormValidator;
use PHPUnit\Framework\TestCase;

class EligibilityEngineTest extends TestCase
{
    /** The eligible example from the eCRF prototype (Rajan Pillai, SH-0031). */
    private function prototype(): array
    {
        return [
            'SCR_DATE' => '2026-08-04', 'SCR_DOB' => '1971-05-15',
            'SCR_DIAGNOSIS' => 'Acute Coronary Syndrome (ACS)', 'SCR_ACS_SUBTYPE' => 'NSTEMI',
            'SCR_PCI_DONE' => 'Yes', 'SCR_PCI_DATE' => '2026-08-03', 'SCR_PCI_INDICATION' => 'ACS',
            'SCR_PCI_VESSELS' => 1, 'SCR_PCI_STENTS' => 1, 'SCR_PCI_ACCESS' => 'Femoral',
            'SCR_CABG' => 'No', 'SCR_LVEF' => 52, 'SCR_LVEF_SOURCE' => 'Echo report',
            'SCR_CARDIAC_ARREST' => 'No', 'SCR_VENT_ARRHYTHMIA' => 'No', 'SCR_CARDIOGENIC_SHOCK' => 'No',
            'SCR_T2DM' => 'Yes', 'SCR_RETINOPATHY' => 'No', 'SCR_NEUROPATHY' => 'No', 'SCR_FOOT_ULCER' => 'No',
            'SCR_EGFR' => 68, 'SCR_SBP' => 138, 'SCR_DBP' => 86, 'SCR_ON_ANTIHYPERTENSIVE' => 'Yes',
            'SCR_VISUAL' => 'No', 'SCR_HEARING' => 'No', 'SCR_COGNITIVE' => 'No',
            'SCR_SMARTPHONE' => 'Yes', 'SCR_PHONE_USER' => 'Participant', 'SCR_PHONE_OS' => 'Android',
        ];
    }

    private function evalWith(array $over): array
    {
        return EligibilityEngine::evaluate(array_merge($this->prototype(), $over), ['android']);
    }

    public function test_prototype_participant_is_eligible(): void
    {
        $r = EligibilityEngine::evaluate($this->prototype(), ['android']);
        $this->assertSame('ELIGIBLE', $r['status']);
        $this->assertSame(55, $r['computed']['SCR_AGE']);
        $this->assertSame(1, $r['computed']['SCR_PCI_DAYS']);
        $this->assertSame('ELIG-1.0', $r['computed']['ELIG_ENGINE']);
        $this->assertSame([], $r['computed']['SCR_FAIL_REASONS']);
    }

    public function test_empty_form_is_incomplete(): void
    {
        $this->assertSame('INCOMPLETE', EligibilityEngine::evaluate([], ['android'])['status']);
    }

    public function test_under_18_fails(): void
    {
        $r = $this->evalWith(['SCR_DOB' => '2010-01-01']);
        $this->assertSame('NOT_ELIGIBLE', $r['status']);
        $this->assertSame('fail', $r['computed']['ELIG_AGE_GE_18']);
        $this->assertSame(1, $r['stop_at_screen']);
        $this->assertSame('not_evaluated', $r['computed']['ELIG_LVEF_GE_40']);
    }

    public function test_pci_window_boundaries(): void
    {
        $this->assertSame('ELIGIBLE', $this->evalWith(['SCR_PCI_DATE' => '2026-07-05'])['status']);   // 30 days
        $this->assertSame('NOT_ELIGIBLE', $this->evalWith(['SCR_PCI_DATE' => '2026-07-04'])['status']); // 31 days
        $this->assertSame('INCOMPLETE', $this->evalWith(['SCR_PCI_DATE' => '2026-08-10'])['status']);  // after screening
        $this->assertSame('NOT_ELIGIBLE', $this->evalWith(['SCR_PCI_DONE' => 'No', 'SCR_PCI_DATE' => null])['status']);
    }

    public function test_threshold_boundaries(): void
    {
        $this->assertSame('ELIGIBLE', $this->evalWith(['SCR_LVEF' => 40])['status']);
        $this->assertSame('NOT_ELIGIBLE', $this->evalWith(['SCR_LVEF' => 39])['status']);
        $this->assertSame('ELIGIBLE', $this->evalWith(['SCR_EGFR' => 45])['status']);
        $this->assertSame('NOT_ELIGIBLE', $this->evalWith(['SCR_EGFR' => 44.9])['status']);
        $this->assertSame('ELIGIBLE', $this->evalWith(['SCR_SBP' => 159, 'SCR_DBP' => 99])['status']);
        $this->assertSame('NOT_ELIGIBLE', $this->evalWith(['SCR_SBP' => 160])['status']);
        $this->assertSame('NOT_ELIGIBLE', $this->evalWith(['SCR_DBP' => 100])['status']);
        // "Despite treatment": high BP while untreated is held for PI review, not excluded (vector E12).
        $this->assertSame('INCOMPLETE', $this->evalWith(['SCR_SBP' => 165, 'SCR_ON_ANTIHYPERTENSIVE' => 'No'])['status']);
    }

    public function test_exclusions_and_unknowns(): void
    {
        $this->assertSame('NOT_ELIGIBLE', $this->evalWith(['SCR_CABG' => 'Yes'])['status']);
        $this->assertSame('NOT_ELIGIBLE', $this->evalWith(['SCR_CARDIOGENIC_SHOCK' => 'Yes'])['status']);
        $this->assertSame('INCOMPLETE', $this->evalWith(['SCR_CARDIAC_ARREST' => 'Unknown'])['status']);
        $this->assertSame('NOT_ELIGIBLE', $this->evalWith(['SCR_NEUROPATHY' => 'Yes'])['status']);
        $this->assertSame('INCOMPLETE', $this->evalWith(['SCR_RETINOPATHY' => 'Unknown'])['status']);
        $this->assertSame('ELIGIBLE', $this->evalWith(['SCR_T2DM' => 'No', 'SCR_RETINOPATHY' => null, 'SCR_NEUROPATHY' => null, 'SCR_FOOT_ULCER' => null])['status']);
        $this->assertSame('ELIGIBLE', $this->evalWith(['SCR_VISUAL' => 'Yes — manageable with aids'])['status']);
        $this->assertSame('NOT_ELIGIBLE', $this->evalWith(['SCR_HEARING' => 'Yes — prevents safe app use'])['status']);
        $this->assertSame('ELIGIBLE', $this->evalWith(['SCR_COGNITIVE' => 'Yes — caregiver can support app use'])['status']);
        $this->assertSame('NOT_ELIGIBLE', $this->evalWith(['SCR_COGNITIVE' => 'Yes — prevents safe app use'])['status']);
    }

    public function test_phone_rules(): void
    {
        $this->assertSame('NOT_ELIGIBLE', $this->evalWith(['SCR_PHONE_OS' => 'iOS'])['status']);
        $this->assertSame('NOT_ELIGIBLE', $this->evalWith(['SCR_SMARTPHONE' => 'No'])['status']);
        $this->assertSame('ELIGIBLE', $this->evalWith(['SCR_PHONE_USER' => 'Household member / caregiver'])['status']);
    }

    public function test_diagnosis_rules(): void
    {
        $this->assertSame('NOT_ELIGIBLE', $this->evalWith(['SCR_DIAGNOSIS' => 'Other'])['status']);
        $this->assertSame('ELIGIBLE', $this->evalWith(['SCR_DIAGNOSIS' => 'Stable Ischaemic Heart Disease'])['status']);
        $this->assertSame('INCOMPLETE', $this->evalWith(['SCR_ACS_SUBTYPE' => null])['status']);
    }

    public function test_stop_rule_records_first_exclusion_only(): void
    {
        $r = $this->evalWith(['SCR_CABG' => 'Yes', 'SCR_LVEF' => 30]);
        $this->assertSame(4, $r['stop_at_screen']);
        $this->assertCount(1, $r['computed']['SCR_FAIL_REASONS']);
        $this->assertStringContainsString('CABG', $r['computed']['SCR_FAIL_REASONS'][0]);
        $this->assertSame('not_evaluated', $r['computed']['ELIG_LVEF_GE_40']);
    }

    public function test_ios_follows_study_setting(): void
    {
        $this->assertSame('NOT_ELIGIBLE', $this->evalWith(['SCR_PHONE_OS' => 'iOS'])['status']);
        $r = EligibilityEngine::evaluate(array_merge($this->prototype(), ['SCR_PHONE_OS' => 'iOS']), ['android', 'ios']);
        $this->assertSame('ELIGIBLE', $r['status']);
    }

    public function test_bad_dates_hold_rather_than_crash(): void
    {
        $r = $this->evalWith(['SCR_PCI_DATE' => '2026-08-10']);
        $this->assertSame('INCOMPLETE', $r['status']);
        $this->assertStringContainsString('after the screening date', $r['criteria'][2]['detail']);
    }

    public function test_validator_ranges_and_visibility(): void
    {
        $data = FormValidator::clean('SCR-01', $this->prototype() + ['UNKNOWN_FIELD' => 'x']);
        $this->assertArrayNotHasKey('UNKNOWN_FIELD', $data);
        $v = FormValidator::validate('SCR-01', $data, '2026-08-04');
        $this->assertSame([], $v['errors']);
        $this->assertSame([], $v['warnings']);
        $this->assertSame([], $v['missing']);

        $v = FormValidator::validate('SCR-01', array_merge($data, ['SCR_SBP' => 255]), '2026-08-04');
        $this->assertArrayHasKey('SCR_SBP', $v['warnings']);
        $v = FormValidator::validate('SCR-01', array_merge($data, ['SCR_SBP' => 400]), '2026-08-04');
        $this->assertArrayHasKey('SCR_SBP', $v['errors']);
        $v = FormValidator::validate('SCR-01', array_merge($data, ['SCR_DATE' => '2026-08-05']), '2026-08-04');
        $this->assertArrayHasKey('SCR_DATE', $v['errors']);

        // Hidden fields are dropped: no diabetes => complication answers removed.
        $clean = FormValidator::clean('SCR-01', array_merge($data, ['SCR_T2DM' => 'No']));
        $this->assertArrayNotHasKey('SCR_RETINOPATHY', $clean);

        // Chained visibility: CON witness only when thumb impression AND consent given.
        $c = FormValidator::clean('CON-01', ['CON_GIVEN' => 'No', 'CON_SIGN_METHOD' => 'Thumb impression', 'CON_WITNESS_NAME' => 'X']);
        $this->assertArrayNotHasKey('CON_WITNESS_NAME', $c);
    }
}
