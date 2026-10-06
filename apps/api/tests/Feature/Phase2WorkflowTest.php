<?php

namespace Tests\Feature;

use App\Forms\Instruments\Instruments;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\SafetyAlert;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Baseline → PROs (tablet self-entry) → SAF-01 → RAND-01 → CCSPS → withdrawal. */
class Phase2WorkflowTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private int $pid;

    private string $pw = 'Secret12345';

    protected function setUp(): void
    {
        parent::setUp();
        config(['smartheart.alerts.critical_emails' => ['pi@test.local'], 'smartheart.alerts.escalation_emails' => ['copi@test.local'],
            'smartheart.alerts.coordinator_phone' => '9000000000']);
        $this->seed(DatabaseSeeder::class);
        User::create(['name' => 'Dr PI', 'email' => 'pi@test.local', 'password' => $this->pw,
            'role_id' => Role::where('name', 'PI / Research Coordinator')->value('id'), 'must_change_password' => false]);
        $this->token = $this->postJson('/api/auth/login', ['email' => 'pi@test.local', 'password' => $this->pw])->json('token');
        $this->pid = $this->consentedParticipant();
    }

    private function api(): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token);
    }

    private function form(string $code, array $data, bool $sign = false): void
    {
        $base = "/api/participants/{$this->pid}/forms/{$code}";
        $this->api()->putJson($base, ['data' => $data])->assertOk();
        $this->api()->postJson("{$base}/complete")->assertOk();
        if ($sign) {
            $this->api()->postJson("{$base}/sign", ['password' => $this->pw])->assertOk();
        }
    }

    private function consentedParticipant(): int
    {
        $today = now()->toDateString();
        $id = $this->api()->postJson('/api/participants', ['full_name' => 'Rajan', 'phone' => '9876543210',
            'data' => ['REG_DATE' => $today, 'REG_REFERRAL_SOURCE' => 'Cardiology OPD', 'REG_POTENTIALLY_ELIGIBLE' => 'Yes']])->json('id');
        $this->pid = $id;
        $this->form('SCR-01', [
            'SCR_DATE' => $today, 'SCR_DOB' => now()->subYears(55)->subDays(10)->toDateString(),
            'SCR_DIAGNOSIS' => 'Acute Coronary Syndrome (ACS)', 'SCR_ACS_SUBTYPE' => 'NSTEMI', 'SCR_PCI_DONE' => 'Yes',
            'SCR_PCI_DATE' => now()->subDays(2)->toDateString(), 'SCR_PCI_INDICATION' => 'ACS', 'SCR_PCI_VESSELS' => 1, 'SCR_PCI_STENTS' => 1,
            'SCR_PCI_ACCESS' => 'Radial', 'SCR_CABG' => 'No', 'SCR_LVEF' => 52, 'SCR_LVEF_SOURCE' => 'Echo report', 'SCR_CARDIAC_ARREST' => 'No',
            'SCR_VENT_ARRHYTHMIA' => 'No', 'SCR_CARDIOGENIC_SHOCK' => 'No', 'SCR_T2DM' => 'Yes', 'SCR_RETINOPATHY' => 'No',
            'SCR_NEUROPATHY' => 'No', 'SCR_FOOT_ULCER' => 'No', 'SCR_EGFR' => 68, 'SCR_SBP' => 138, 'SCR_DBP' => 86,
            'SCR_ON_ANTIHYPERTENSIVE' => 'Yes', 'SCR_VISUAL' => 'No', 'SCR_HEARING' => 'No', 'SCR_COGNITIVE' => 'No',
            'SCR_SMARTPHONE' => 'Yes', 'SCR_PHONE_USER' => 'Participant', 'SCR_PHONE_OS' => 'Android',
        ], true);
        $this->form('CON-01', ['CON_DATE' => $today, 'CON_TIME' => '10:00', 'CON_PIS_VERSION' => 'v3.0', 'CON_LANGUAGE' => 'Tamil',
            'CON_GIVEN' => 'Yes', 'CON_SIGN_METHOD' => 'Signature', 'CON_TAKEN_BY' => 'Dr PI', 'CON_COPY_GIVEN' => 'Yes', 'CON_CAREGIVER_VIEW' => 'Yes'], true);

        return $id;
    }

    private function baseline(): void
    {
        $d = now()->subDay()->toDateString();
        $this->form('BL-M1', ['BL_SEX' => 'Male', 'BL_MARITAL' => 'Married', 'BL_EDUCATION' => 'Graduate', 'BL_EMPLOYMENT' => 'Retired',
            'BL_RESIDENCE' => 'Urban', 'BL_LIVES_ALONE' => 'No', 'BL_PREF_LANGUAGE' => 'Tamil']);
        $yes = ['BL_PRIOR_MI' => 'No', 'BL_PRIOR_PCI' => 'No', 'BL_HTN' => 'Yes', 'BL_DYSLIPIDAEMIA' => 'Yes', 'BL_CKD' => 'No', 'BL_STROKE' => 'No',
            'BL_PAD' => 'No', 'BL_HF' => 'No', 'BL_COPD' => 'No', 'BL_THYROID' => 'No', 'BL_FAMILY_CAD' => 'Unknown'];
        $this->form('BL-M2', $yes + ['BL_ADMIT_DATE' => now()->subDays(3)->toDateString(), 'BL_KILLIP' => 'I', 'BL_NYHA' => 'I', 'BL_CULPRIT' => ['RCA'],
            'BL_DISCEASED' => null, 'BL_DISEASED_VESSELS' => '1', 'BL_RESIDUAL_DISEASE' => 'No', 'BL_STENT_TYPE' => 'Drug-eluting', 'BL_DISCHARGE_DATE' => $d]);
        $this->form('BL-M3', ['BL_MEAS_DATE' => $d, 'BL_HEIGHT_CM' => 165, 'BL_WEIGHT_KG' => 72, 'BL_WAIST_CM' => 92]);
        $this->form('BL-M4', ['BL_BP_DATE' => $d, 'BL_SBP1' => 138, 'BL_DBP1' => 86, 'BL_SBP2' => 136, 'BL_DBP2' => 84, 'BL_HR' => 72,
            'BL_SPO2' => 97, 'BL_RHYTHM' => 'Sinus', 'BL_ECHO_DATE' => $d]);
        $this->form('BL-M5', ['BL_HBA1C' => 53, 'BL_HBA1C_UNIT' => 'mmol/mol', 'BL_HBA1C_DATE' => $d, 'BL_TC' => 184, 'BL_TC_UNIT' => 'mg/dL', 'BL_TC_DATE' => $d,
            'BL_LDL' => 2.6, 'BL_LDL_UNIT' => 'mmol/L', 'BL_LDL_DATE' => $d, 'BL_LDL_METHOD' => 'Measured directly', 'BL_HDL' => 42, 'BL_HDL_UNIT' => 'mg/dL',
            'BL_HDL_DATE' => $d, 'BL_TG' => 162, 'BL_TG_UNIT' => 'mg/dL', 'BL_TG_DATE' => $d, 'BL_CREAT' => 1.1, 'BL_CREAT_UNIT' => 'mg/dL',
            'BL_CREAT_DATE' => $d, 'BL_EGFR_EQUATION' => 'Not stated']);
        $this->form('BL-M6', ['BL_MEDS_SOURCE' => 'Discharge summary', 'BL_MEDS' => [
            ['class' => 'Antiplatelet — aspirin', 'drug' => 'Aspirin', 'dose' => '75 mg', 'frequency' => 'Once daily', 'times' => ['Morning']],
            ['class' => 'Antiplatelet — P2Y12 inhibitor', 'drug' => 'Ticagrelor', 'dose' => '90 mg', 'frequency' => 'Twice daily', 'times' => ['Morning', 'Night']],
            ['class' => 'Statin', 'drug' => 'Atorvastatin', 'dose' => '80 mg', 'frequency' => 'At night', 'times' => ['Night']],
        ]]);
        $this->form('BL-M7', ['BL_SMOKING' => 'Never', 'BL_ALCOHOL' => 'None', 'BL_PA_DAYS' => 3, 'BL_PA_MIN_WEEK' => 90, 'BL_SLEEP_HOURS' => 6.5]);
        $this->form('BL-M8', ['BL_6MWT_DONE' => 'No', 'BL_6MWT_NOT_DONE_REASON' => 'Not done before discharge']);
    }

    private function loadLicensedTexts(): void
    {
        foreach (['EQ5D', 'MARS5'] as $key) {
            $inst = Instruments::get($key);
            $texts = array_fill_keys(Instruments::textKeys($inst), 'Licensed wording (test)');
            $this->api()->putJson("/api/instruments/{$key}/en", ['texts' => $texts, 'source_note' => 'Test licence copy'])->assertOk();
        }
    }

    public function test_baseline_calculations_use_calc_engine_and_other_forms(): void
    {
        $this->baseline();
        $p = fn ($code) => $this->api()->getJson("/api/participants/{$this->pid}/forms/{$code}")->json('computed');
        $this->assertSame(26.4, $p('BL-M3')['BL_BMI']);                  // B1
        $this->assertSame('obese', $p('BL-M3')['BL_BMI_CATEGORY']);       // B2
        $this->assertEquals(137.0, $p('BL-M4')['BL_SBP_MEAN']);           // B4 (JSON drops the .0)
        $labs = $p('BL-M5');
        $this->assertSame(100.5, $labs['BL_LDL_MGDL']);                   // B5
        $this->assertEquals(7.0, $labs['BL_HBA1C_PCT']);                  // B6
        $this->assertSame(109.6, $labs['BL_LDL_FRIEDEWALD']);             // B8
        $this->assertSame(79, $labs['BL_EGFR_CKDEPI']);                   // B11 (male 55, Cr 1.1, sex from BL-M1, DOB from SCR-01)
        $this->assertSame('Yes', $p('BL-M6')['BL_DAPT']);

        // Lab outside the plausible canonical range is rejected.
        $this->api()->putJson("/api/participants/{$this->pid}/forms/BL-M5", ['data' => ['BL_HBA1C' => 25, 'BL_HBA1C_UNIT' => '%'],
            'reasons' => ['BL_HBA1C' => 'test', 'BL_HBA1C_UNIT' => 'test']])->assertStatus(422)->assertJsonPath('errors.BL_HBA1C', fn ($m) => str_contains($m, 'plausible'));
        // Medication frequency must match the ticked times.
        $this->api()->putJson("/api/participants/{$this->pid}/forms/BL-M6", ['data' => ['BL_MEDS' => [
            ['class' => 'Statin', 'drug' => 'X', 'dose' => '1', 'frequency' => 'Twice daily', 'times' => ['Morning']]]], 'reasons' => ['BL_MEDS' => 'test']])
            ->assertStatus(422);
    }

    public function test_licensed_questionnaires_stay_locked_until_text_entered(): void
    {
        $this->api()->getJson("/api/participants/{$this->pid}/forms/PRO-EQ5D")->assertJsonPath('locked_reason', fn ($r) => str_contains((string) $r, 'licensed'));
        $this->api()->postJson("/api/participants/{$this->pid}/forms/PRO-PHQ9/self-entry", ['lang' => 'ta'])->assertStatus(422); // no validated Tamil yet
        $this->loadLicensedTexts();
        $this->api()->getJson("/api/participants/{$this->pid}/forms/PRO-EQ5D")->assertJsonPath('locked_reason', null);
        $this->assertTrue(AuditLog::where('action', 'instrument_text_changed')->exists());
    }

    public function test_self_entry_item9_raises_alert_and_never_returns_scores(): void
    {
        Mail::fake();
        $tok = $this->api()->postJson("/api/participants/{$this->pid}/forms/PRO-PHQ9/self-entry", ['lang' => 'en'])->assertOk()->json('token');
        $q = $this->getJson("/api/self-entry/{$tok}")->assertOk();
        $this->assertCount(10, $q->json('items'));
        $this->assertArrayNotHasKey('study_id', $q->json());

        $answers = ['PHQ9_Q1' => 1, 'PHQ9_Q2' => 0, 'PHQ9_Q3' => 1, 'PHQ9_Q4' => 0, 'PHQ9_Q5' => 0, 'PHQ9_Q6' => 0, 'PHQ9_Q7' => 0, 'PHQ9_Q8' => 0];
        $this->postJson("/api/self-entry/{$tok}/submit", ['answers' => $answers])->assertStatus(422); // item 9 unanswered
        $res = $this->postJson("/api/self-entry/{$tok}/submit", ['answers' => $answers + ['PHQ9_Q9' => 1]])->assertOk();
        $this->assertTrue($res->json('done'));
        $this->assertStringContainsString('14416', $res->json('support_message'));
        $this->assertStringContainsString('9000000000', $res->json('support_message'));
        $this->assertStringNotContainsString('total', strtolower($res->getContent()));

        // Total 3 (minimal) but item 9 = 1 → critical alert anyway (vector Q2).
        $alert = SafetyAlert::where('rule', 'PHQ9_ITEM9')->first();
        $this->assertNotNull($alert);
        $this->assertSame('critical', $alert->severity);
        $this->assertNotNull($alert->notified_at);
        $this->getJson("/api/self-entry/{$tok}")->assertStatus(410); // session locked after submit

        // Escalation after 2 h without acknowledgement.
        $this->travel(121)->minutes();
        $this->assertSame(1, app(\App\Services\SafetyAlertService::class)->escalateOverdue());
        $this->assertNotNull($alert->fresh()->escalated_at);

        // The 2 h jump also ended the staff session (15 min idle), so sign in again.
        $this->token = $this->postJson('/api/auth/login', ['email' => 'pi@test.local', 'password' => $this->pw])->json('token');
        $this->api()->postJson("/api/alerts/{$alert->id}/close", ['note' => 'short'])->assertStatus(422);
        $this->api()->postJson("/api/alerts/{$alert->id}/acknowledge")->assertOk()->assertJsonPath('status', 'acknowledged');
        $this->api()->postJson("/api/alerts/{$alert->id}/close", ['note' => 'Called participant, psychiatry review booked.'])->assertOk();
    }

    public function test_full_baseline_to_randomisation_with_blinding(): void
    {
        $this->baseline();
        $this->loadLicensedTexts();
        $this->form('PRO-PHQ9', array_combine(array_map(fn ($i) => "PHQ9_Q{$i}", range(1, 9)), [0, 0, 1, 0, 0, 0, 0, 0, 0]));
        $this->form('PRO-GAD7', array_combine(array_map(fn ($i) => "GAD7_Q{$i}", range(1, 7)), [0, 1, 0, 0, 0, 0, 0]));
        $this->form('PRO-EQ5D', ['EQ5D_MO' => 2, 'EQ5D_SC' => 1, 'EQ5D_UA' => 1, 'EQ5D_PD' => 1, 'EQ5D_AD' => 1, 'EQ5D_VAS' => 70]);
        $this->form('PRO-DASI', array_combine(array_map(fn ($i) => "DASI_Q{$i}", range(1, 12)), [1, 1, 1, 1, 0, 0, 1, 1, 1, 1, 0, 0]));
        $this->api()->getJson("/api/participants/{$this->pid}/forms/PRO-DASI")->assertJsonPath('computed.DASI_METS', 6.9); // Q10
        $this->api()->getJson("/api/participants/{$this->pid}/forms/SAF-01")->assertJsonPath('locked_reason', fn ($r) => str_contains($r, 'PRO-MARS5'));
        $this->form('PRO-MARS5', ['MARS5_Q1' => 5, 'MARS5_Q2' => 5, 'MARS5_Q3' => 4, 'MARS5_Q4' => 5, 'MARS5_Q5' => 5]);

        $saf = $this->api()->getJson("/api/participants/{$this->pid}/forms/SAF-01")->assertJsonPath('locked_reason', null);
        $this->assertSame('137.0/85.0 mmHg', $saf->json('computed.SAF_SUM_BP'));

        // Not yet cleared → cannot randomise.
        $this->api()->getJson("/api/participants/{$this->pid}/randomisation")->assertJsonPath('blockers', fn ($b) => count($b) >= 1);
        $this->form('SAF-01', ['SAF_VITALS_OK' => 'Yes', 'SAF_NO_ARRHYTHMIA' => 'Yes', 'SAF_NO_DECOMP' => 'Yes', 'SAF_PHQ9_ITEM9_REVIEWED' => 'Yes',
            'SAF_MOOD_REVIEW_DONE' => 'Not applicable', 'SAF_MEDS_OK' => 'Yes', 'SAF_EXERCISE_SAFE' => 'Yes', 'SAF_CLEARED' => 'Yes'], true);
        $this->assertSame('ready_to_randomise', $this->api()->getJson("/api/participants/{$this->pid}")->json('status'));

        // Upload list: unbalanced block rejected (vector R2), good list accepted.
        $bad = "stratum,seq_no,block_no,arm\nlt60,1,1,intervention\nlt60,2,1,intervention\nge60,1,1,control\nge60,2,1,intervention\n";
        $this->api()->postJson('/api/randomisation/list', ['name' => 'v1', 'csv' => $bad])->assertStatus(422);
        $good = "stratum,seq_no,block_no,arm\nlt60,1,1,control\nlt60,2,1,intervention\nge60,1,1,intervention\nge60,2,1,control\n";
        $this->api()->postJson('/api/randomisation/list', ['name' => 'v1', 'csv' => $good])->assertOk()->assertJsonPath('strata.lt60.remaining', 2);

        $this->api()->postJson("/api/participants/{$this->pid}/randomise", ['password' => 'wrong'])->assertStatus(422);
        $r = $this->api()->postJson("/api/participants/{$this->pid}/randomise", ['password' => $this->pw])->assertOk();
        $this->assertSame('control', $r->json('arm'));            // first lt60 row (age 55)
        $this->assertSame('lt60', $r->json('age_stratum'));
        // Retry returns the same allocation and uses no new row.
        $this->api()->postJson("/api/participants/{$this->pid}/randomise", ['password' => $this->pw])->assertOk()->assertJsonPath('already', true);
        $this->api()->getJson('/api/randomisation')->assertJsonPath('strata.lt60.used', 1);
        // Audit records the slot, never the arm.
        $audit = AuditLog::where('action', 'randomised')->first();
        $this->assertStringNotContainsString('control', json_encode($audit->toArray()));

        // A blinded role sees "blinded", and cannot filter by arm.
        $role = Role::where('name', 'Cardiologist')->first();
        $role->permissions()->where('screen', 'view_allocation')->update(['can_read' => false]);
        User::create(['name' => 'Blind', 'email' => 'blind@test.local', 'password' => $this->pw, 'role_id' => $role->id, 'must_change_password' => false]);
        $this->token = $this->postJson('/api/auth/login', ['email' => 'blind@test.local', 'password' => $this->pw])->json('token');
        $this->api()->getJson("/api/participants/{$this->pid}")->assertJsonPath('arm', 'blinded');
        $this->assertCount(1, $this->api()->getJson('/api/participants?arm=intervention')->json()); // filter ignored
        $this->api()->getJson('/api/dashboard')->assertJsonPath('counts.intervention', null);
    }

    public function test_ccsps_scores_only_with_approved_thresholds(): void
    {
        $this->baseline();
        $c = $this->api()->getJson("/api/participants/{$this->pid}/ccsps")->assertOk();
        $this->assertSame(0, $c->json('total.domains_scored'));
        $this->assertSame('No approved threshold yet', $c->json('domains.0.pending_reason'));

        $this->api()->postJson('/api/scoring-configs', ['domain' => 'bmi', 'rule' => ['ideal_below' => 30, 'poor_at_or_above' => 23], 'note' => 'wrong order'])->assertStatus(422);
        $id = $this->api()->postJson('/api/scoring-configs', ['domain' => 'bmi', 'rule' => ['ideal_below' => 23, 'poor_at_or_above' => 25], 'note' => 'Asian cut-offs (PI)'])
            ->assertCreated()->json('id');
        $this->assertSame(0, $this->api()->getJson("/api/participants/{$this->pid}/ccsps")->json('total.domains_scored')); // draft not used
        $this->api()->postJson("/api/scoring-configs/{$id}/approve", ['password' => $this->pw])->assertOk();
        $c = $this->api()->getJson("/api/participants/{$this->pid}/ccsps");
        $bmi = collect($c->json('domains'))->firstWhere('domain', 'bmi');
        $this->assertSame(0, $bmi['score']); // BMI 26.4 ≥ 25 → poor
        $this->assertSame(1, $c->json('total.domains_scored'));
    }

    public function test_withdrawal_locks_record(): void
    {
        $this->form('WD-01', ['WD_DATE' => now()->toDateString(), 'WD_TYPE' => 'Participant withdrew consent', 'WD_REASON' => 'Moving away',
            'WD_KEEP_DATA' => 'Yes', 'WD_RECORDS_FOLLOWUP' => 'Yes', 'WD_DEVICES_RETURNED' => 'Not issued'], true);
        $this->assertSame('withdrawn', $this->api()->getJson("/api/participants/{$this->pid}")->json('status'));
        $this->api()->putJson("/api/participants/{$this->pid}/forms/BL-M1", ['data' => ['BL_SEX' => 'Male']])->assertStatus(423);
    }
}
