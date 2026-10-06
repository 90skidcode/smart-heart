<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End-to-end checks of the Phase 1 eCRF rules. Run with:  php artisan test
 */
class EcrfWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    private User $pi;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        $this->pi = User::create([
            'name' => 'Dr Test PI', 'email' => 'pi@test.local', 'password' => 'Secret12345',
            'role_id' => Role::where('name', 'PI / Research Coordinator')->value('id'), 'must_change_password' => false,
        ]);
        $this->token = $this->login('pi@test.local', 'Secret12345');
    }

    private function login(string $email, string $password): string
    {
        return $this->postJson('/api/auth/login', compact('email', 'password'))->assertOk()->json('token');
    }

    private function api(): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->token);
    }

    private function scr(array $over = []): array
    {
        return array_merge([
            'SCR_DATE' => now()->toDateString(), 'SCR_DOB' => '1971-05-15',
            'SCR_DIAGNOSIS' => 'Acute Coronary Syndrome (ACS)', 'SCR_ACS_SUBTYPE' => 'NSTEMI',
            'SCR_PCI_DONE' => 'Yes', 'SCR_PCI_DATE' => now()->subDay()->toDateString(), 'SCR_PCI_INDICATION' => 'ACS',
            'SCR_PCI_VESSELS' => 1, 'SCR_PCI_STENTS' => 1, 'SCR_PCI_ACCESS' => 'Radial',
            'SCR_CABG' => 'No', 'SCR_LVEF' => 52, 'SCR_LVEF_SOURCE' => 'Echo report',
            'SCR_CARDIAC_ARREST' => 'No', 'SCR_VENT_ARRHYTHMIA' => 'No', 'SCR_CARDIOGENIC_SHOCK' => 'No',
            'SCR_T2DM' => 'No', 'SCR_EGFR' => 68, 'SCR_SBP' => 138, 'SCR_DBP' => 86,
            'SCR_VISUAL' => 'No', 'SCR_HEARING' => 'No', 'SCR_COGNITIVE' => 'No',
            'SCR_SMARTPHONE' => 'Yes', 'SCR_PHONE_USER' => 'Participant', 'SCR_PHONE_OS' => 'Android',
        ], $over);
    }

    private function register(): int
    {
        return $this->api()->postJson('/api/participants', [
            'full_name' => 'Rajan Pillai', 'phone' => '9876543210', 'hospital_number' => 'MRN1',
            'data' => ['REG_DATE' => now()->toDateString(), 'REG_REFERRAL_SOURCE' => 'Cardiology OPD', 'REG_POTENTIALLY_ELIGIBLE' => 'Yes'],
        ])->assertCreated()->assertJsonPath('study_id', 'SMART-HEART-0001')->json('id');
    }

    public function test_full_path_to_consent(): void
    {
        $id = $this->register();
        $base = "/api/participants/{$id}/forms";

        // Consent is locked before screening is signed.
        $this->api()->putJson("$base/CON-01", ['data' => ['CON_DATE' => now()->toDateString()]])->assertStatus(423);

        $this->api()->putJson("$base/SCR-01", ['data' => $this->scr()])->assertOk()
            ->assertJsonPath('eligibility.status', 'ELIGIBLE');
        $this->api()->postJson("$base/SCR-01/complete")->assertOk()->assertJsonPath('status', 'complete');
        $this->api()->postJson("$base/SCR-01/sign", ['password' => 'wrong'])->assertStatus(422);
        $this->api()->postJson("$base/SCR-01/sign", ['password' => 'Secret12345'])->assertOk()->assertJsonPath('status', 'signed');
        $this->assertSame('eligible', $this->api()->getJson("/api/participants/{$id}")->json('status'));

        // Signed forms cannot be edited.
        $this->api()->putJson("$base/SCR-01", ['data' => $this->scr(['SCR_LVEF' => 55])])->assertStatus(423);

        $this->api()->putJson("$base/CON-01", ['data' => [
            'CON_DATE' => now()->toDateString(), 'CON_TIME' => '10:30', 'CON_PIS_VERSION' => 'v3.0', 'CON_LANGUAGE' => 'Tamil',
            'CON_GIVEN' => 'Yes', 'CON_SIGN_METHOD' => 'Signature', 'CON_TAKEN_BY' => 'Dr Test PI', 'CON_COPY_GIVEN' => 'Yes', 'CON_CAREGIVER_VIEW' => 'Yes',
        ]])->assertOk();
        $this->api()->postJson("$base/CON-01/complete")->assertOk();
        $this->api()->postJson("$base/CON-01/sign", ['password' => 'Secret12345'])->assertOk();
        $this->assertSame('consented', $this->api()->getJson("/api/participants/{$id}")->json('status'));

        // Unlocking screening is blocked while consent is signed.
        $this->api()->postJson("$base/SCR-01/unlock", ['reason' => 'Correct LVEF'])->assertStatus(422);
    }

    public function test_screen_failure_can_be_signed_early(): void
    {
        $id = $this->register();
        $base = "/api/participants/{$id}/forms";
        // Only date fields + CABG = Yes: stop rule allows completion with other fields empty.
        $this->api()->putJson("$base/SCR-01", ['data' => ['SCR_DATE' => now()->toDateString(), 'SCR_DOB' => '1960-01-01', 'SCR_CABG' => 'Yes']])
            ->assertOk()->assertJsonPath('eligibility.status', 'NOT_ELIGIBLE');
        $this->api()->postJson("$base/SCR-01/complete")->assertOk();
        $this->api()->postJson("$base/SCR-01/sign", ['password' => 'Secret12345'])->assertOk();
        $p = $this->api()->getJson("/api/participants/{$id}")->assertJsonPath('status', 'screen_failure');
        $this->assertSame(['CABG history'], $p->json('screen_fail_reasons'));
        $this->api()->getJson('/api/dashboard')->assertJsonPath('counts.screen_failure', 1);
    }

    public function test_reason_for_change_and_range_override(): void
    {
        $id = $this->register();
        $base = "/api/participants/{$id}/forms";
        // SBP 255 is above the plausible range: completion needs an override reason.
        $this->api()->putJson("$base/SCR-01", ['data' => $this->scr(['SCR_SBP' => 255])])->assertOk();
        $this->api()->postJson("$base/SCR-01/complete")->assertStatus(422)->assertJsonPath('unconfirmed.0', 'SCR_SBP');
        $this->api()->putJson("$base/SCR-01", ['data' => $this->scr(['SCR_SBP' => 255]), 'overrides' => ['SCR_SBP' => 'Verified on repeat reading']])->assertOk();
        $this->api()->postJson("$base/SCR-01/complete")->assertOk();

        // Now complete: changing a value needs a reason.
        $this->api()->putJson("$base/SCR-01", ['data' => $this->scr(['SCR_SBP' => 140])])
            ->assertStatus(422)->assertJsonPath('need_reasons.0', 'SCR_SBP');
        $this->api()->putJson("$base/SCR-01", ['data' => $this->scr(['SCR_SBP' => 140]), 'reasons' => ['SCR_SBP' => 'Transcription error']])->assertOk();

        $log = AuditLog::where('field', 'SCR_SBP')->where('action', 'field_changed')->latest('id')->first();
        $this->assertSame('255', $log->old_value);
        $this->assertSame('140', $log->new_value);
        $this->assertSame('Transcription error', $log->reason);
        $this->assertTrue(AuditLog::where('action', 'range_override')->where('field', 'SCR_SBP')->exists());
    }

    public function test_audit_log_is_append_only(): void
    {
        $this->register();
        $log = AuditLog::first();
        $this->expectException(\LogicException::class);
        $log->update(['action' => 'tampered']);
    }

    public function test_permissions_matrix_is_enforced(): void
    {
        $cardio = User::create([
            'name' => 'Dr Cardio', 'email' => 'cardio@test.local', 'password' => 'Secret12345',
            'role_id' => Role::where('name', 'Cardiologist')->value('id'), 'must_change_password' => false,
        ]);
        $this->token = $this->login('cardio@test.local', 'Secret12345');
        $this->api()->getJson('/api/dashboard')->assertOk();
        $this->api()->postJson('/api/participants', [])->assertForbidden();
        $this->api()->getJson('/api/users')->assertForbidden();
        $this->api()->getJson('/api/export/data?type=identified')->assertForbidden();
    }

    public function test_lockout_and_forced_password_change(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'pi@test.local', 'password' => 'bad'])->assertStatus(422);
        }
        $this->postJson('/api/auth/login', ['email' => 'pi@test.local', 'password' => 'Secret12345'])->assertStatus(423);

        $this->pi->forceFill(['locked_until' => null, 'must_change_password' => true])->save();
        $this->token = $this->login('pi@test.local', 'Secret12345');
        $this->api()->getJson('/api/participants')->assertStatus(403)->assertJsonPath('code', 'password_change_required');
    }

    public function test_deidentified_export_has_no_identity(): void
    {
        $this->register();
        $csv = $this->api()->get('/api/export/data?type=deidentified')->assertOk()->streamedContent();
        $this->assertStringNotContainsString('Rajan', $csv);
        $this->assertStringNotContainsString('9876543210', $csv);
        $this->assertStringContainsString('SMART-HEART-0001', $csv);
        $this->assertTrue(AuditLog::where('action', 'export')->exists());
    }
}
