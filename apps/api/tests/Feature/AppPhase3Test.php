<?php

namespace Tests\Feature;

use App\Models\AppUser;
use App\Models\AuditLog;
use App\Models\CrfForm;
use App\Models\Participant;
use App\Models\Role;
use App\Models\SafetyAlert;
use App\Models\User;
use App\Services\App\FirebaseTokenVerifier;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Phase 3: app activation (Firebase OTP), caregiver read-only, medicines, doses, readings, content. */
class AppPhase3Test extends TestCase
{
    use RefreshDatabase;

    private string $staff;

    private Participant $p;

    private $key;

    protected function setUp(): void
    {
        parent::setUp();
        config(['smartheart.app.firebase_project_id' => 'smart-heart-test', 'smartheart.app.firebase_credentials' => '']);
        $this->seed(DatabaseSeeder::class);
        $pi = User::create(['name' => 'Dr PI', 'email' => 'pi@test.local', 'password' => 'Secret12345',
            'role_id' => Role::where('name', 'PI / Research Coordinator')->value('id'), 'must_change_password' => false]);
        $this->staff = $this->postJson('/api/auth/login', ['email' => 'pi@test.local', 'password' => 'Secret12345'])->json('token');
        $this->p = Participant::create(['study_id' => 'SMART-HEART-0007', 'screening_id' => 'SCR-0007', 'full_name' => 'Rajan K Pillai',
            'phone' => '9876543210', 'status' => 'randomised', 'arm' => 'intervention', 'age_stratum' => 'lt60', 'created_by' => $pi->id]);
        foreach (['CON-01' => ['CON_GIVEN' => 'Yes', 'CON_CAREGIVER_VIEW' => 'Yes'], 'BL-M6' => ['BL_MEDS_SOURCE' => 'Discharge summary', 'BL_MEDS' => [
            ['class' => 'Antiplatelet — aspirin', 'drug' => 'Aspirin', 'dose' => '75 mg', 'frequency' => 'Once daily', 'times' => ['Morning']],
            ['class' => 'Statin', 'drug' => 'Atorvastatin', 'dose' => '80 mg', 'frequency' => 'At night', 'times' => ['Night']],
            ['class' => 'Nitrate', 'drug' => 'Sorbitrate', 'dose' => '5 mg', 'frequency' => 'As needed', 'times' => []],
        ]]] as $code => $data) {
            CrfForm::create(['participant_id' => $this->p->id, 'form_code' => $code, 'visit' => 'BL', 'status' => 'signed', 'data' => $data, 'computed' => [], 'updated_by' => $pi->id]);
        }

        // A Firebase-like signing key whose certificate the verifier will trust.
        $this->key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'test'], $this->key), null, $this->key, 1);
        openssl_x509_export($cert, $pem);
        Cache::put(FirebaseTokenVerifier::CACHE_KEY, ['kid1' => $pem], 3600);
    }

    private function staffApi(): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$this->staff);
    }

    private function idToken(string $phone, array $override = [], string $kid = 'kid1'): string
    {
        $enc = fn (array $x) => rtrim(strtr(base64_encode(json_encode($x)), '+/', '-_'), '=');
        $now = time();
        $unsigned = $enc(['alg' => 'RS256', 'kid' => $kid, 'typ' => 'JWT']).'.'.$enc($override + [
            'iss' => 'https://securetoken.google.com/smart-heart-test', 'aud' => 'smart-heart-test', 'auth_time' => $now,
            'sub' => 'uid-'.$phone, 'iat' => $now, 'exp' => $now + 3600, 'phone_number' => $phone]);
        openssl_sign($unsigned, $sig, $this->key, OPENSSL_ALGO_SHA256);

        return $unsigned.'.'.rtrim(strtr(base64_encode($sig), '+/', '-_'), '=');
    }

    private function activate(string $phone, string $pid = 'SMART-HEART-0007', int $status = 200)
    {
        return $this->withHeader('Authorization', '')->postJson('/api/app/activate', ['participant_id' => $pid, 'id_token' => $this->idToken($phone), 'device_name' => 'Redmi Note'])
            ->assertStatus($status);
    }

    private function app(string $token): static
    {
        return $this->withHeader('Authorization', 'Bearer '.$token);
    }

    public function test_firebase_token_checks(): void
    {
        $v = app(FirebaseTokenVerifier::class);
        $this->assertSame('9876543210', $v->verify($this->idToken('+919876543210'))['phone']);
        foreach ([['aud' => 'other-project'], ['iss' => 'https://evil'], ['exp' => time() - 3600], ['phone_number' => '+15551234567']] as $bad) {
            try {
                $v->verify($this->idToken('+919876543210', $bad));
                $this->fail('accepted bad claims '.json_encode($bad));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $tampered = $this->idToken('+919876543210');
        [$h, , $s] = explode('.', $tampered);
        $forged = rtrim(strtr(base64_encode(json_encode(['phone_number' => '+919999999999', 'aud' => 'smart-heart-test'])), '+/', '-_'), '=');
        $this->expectException(\InvalidArgumentException::class);
        $v->verify("{$h}.{$forged}.{$s}");
    }

    public function test_activation_requires_enabled_access_and_matching_phone(): void
    {
        $this->activate('+919876543210', status: 422)->assertJsonPath('code', 'not_registered'); // not enabled yet

        // Control arm never gets the app; blinded roles cannot open the app screens.
        $this->p->forceFill(['arm' => 'control'])->save();
        $this->staffApi()->postJson("/api/participants/{$this->p->id}/app/enable")->assertStatus(422);
        $this->p->forceFill(['arm' => 'intervention'])->save();

        $this->staffApi()->postJson("/api/participants/{$this->p->id}/app/enable")->assertOk()->assertJsonPath('users.0.status', 'invited');
        $this->activate('+919000000001', status: 422);                       // someone else's phone
        $this->activate('+919876543210', 'SMART-HEART-0008', 422);           // wrong participant ID
        $tok = $this->activate('+919876543210', '7')->assertJsonPath('me.role', 'participant')
            ->assertJsonPath('me.name', 'Rajan')->assertJsonMissingPath('me.arm')->json('token');
        $this->app($tok)->getJson('/api/app/me')->assertOk()->assertJsonPath('read_only', false);
        $this->assertTrue(AuditLog::where('action', 'app_activated')->exists());

        // Staff tokens do not work on the app API and vice versa.
        $this->staffApi()->getJson('/api/app/me')->assertStatus(401);
        $this->app($tok)->getJson('/api/participants')->assertStatus(401);

        // Withdrawal ends app access.
        $this->p->forceFill(['status' => 'withdrawn'])->save();
        $this->app($tok)->getJson('/api/app/me')->assertStatus(401)->assertJsonPath('code', 'app_access_ended');
    }

    public function test_medicines_doses_and_offline_retries(): void
    {
        $this->staffApi()->postJson("/api/participants/{$this->p->id}/app/enable")->assertOk();
        $tok = $this->activate('+919876543210')->json('token');
        $this->app($tok)->getJson('/api/app/medications')->assertJsonPath('items', []); // nothing until staff publish

        $draft = $this->staffApi()->getJson("/api/participants/{$this->p->id}/app")->json('draft.items');
        $this->assertCount(3, $draft);
        $bad = $draft;
        $bad[0]['times'] = [];
        $this->staffApi()->postJson("/api/participants/{$this->p->id}/app/medications", ['items' => $bad])->assertStatus(422);
        $draft[0]['instructions'] = 'After breakfast';
        $this->staffApi()->postJson("/api/participants/{$this->p->id}/app/medications", ['items' => $draft, 'note' => 'Checked with discharge summary'])
            ->assertOk()->assertJsonPath('schedule.version', 1);

        $today = $this->app($tok)->getJson('/api/app/today')->assertOk()->json('doses');
        $this->assertSame(['Aspirin', 'Atorvastatin'], array_column($today, 'drug'));     // "As needed" has no checklist row
        $this->assertSame(['08:00', '21:00'], array_column($today, 'time'));

        $u1 = (string) Str::uuid();
        $dose = ['client_uuid' => $u1, 'schedule_version' => 1, 'med_key' => $today[0]['med_key'], 'date' => now()->toDateString(),
            'slot' => 'Morning', 'status' => 'taken', 'answered_at' => now()->toIso8601String()];
        $wrongSlot = ['slot' => 'Night', 'client_uuid' => (string) Str::uuid()] + $dose;
        $r = $this->app($tok)->postJson('/api/app/doses', ['doses' => [$dose, $dose, $wrongSlot]])->assertOk()->json('results');
        $this->assertSame(['saved', 'duplicate', 'rejected'], array_column($r, 'result'));
        // Participant changes the answer later (new uuid): latest answer wins, change audited.
        $this->app($tok)->postJson('/api/app/doses', ['doses' => [['client_uuid' => (string) Str::uuid(), 'status' => 'skipped',
            'answered_at' => now()->addMinute()->toIso8601String()] + $dose]])->assertJsonPath('results.0.result', 'updated');
        $this->app($tok)->getJson('/api/app/today')->assertJsonPath('doses.0.status', 'skipped');
        $this->assertTrue(AuditLog::where('action', 'dose_changed')->exists());

        $sum = $this->staffApi()->getJson("/api/participants/{$this->p->id}/app")->json('doses');
        $this->assertSame(['due' => 2, 'taken' => 0, 'skipped' => 1, 'unanswered' => 1], array_intersect_key(end($sum), array_flip(['due', 'taken', 'skipped', 'unanswered'])));
    }

    public function test_readings_validation_idempotency_and_alerts(): void
    {
        $this->staffApi()->postJson("/api/participants/{$this->p->id}/app/enable")->assertOk();
        $tok = $this->activate('+919876543210')->json('token');
        $at = now()->subHours(2)->toIso8601String();
        $ok = ['client_uuid' => (string) Str::uuid(), 'type' => 'bp', 'values' => ['sbp' => 132, 'dbp' => 84, 'pulse' => 70], 'measured_at' => $at, 'source' => 'health_connect', 'device' => 'OMRON connect'];
        $high = ['client_uuid' => (string) Str::uuid(), 'type' => 'bp', 'values' => ['sbp' => 192, 'dbp' => 100], 'measured_at' => $at, 'source' => 'manual'];
        $bad = ['client_uuid' => (string) Str::uuid(), 'type' => 'bp', 'values' => ['sbp' => 80, 'dbp' => 90], 'measured_at' => $at, 'source' => 'manual'];
        $sugar = ['client_uuid' => (string) Str::uuid(), 'type' => 'glucose', 'values' => ['mg_dl' => 62, 'context' => 'fasting'], 'measured_at' => $at, 'source' => 'manual'];
        $future = ['client_uuid' => (string) Str::uuid(), 'type' => 'weight', 'values' => ['kg' => 72.35], 'measured_at' => now()->addDay()->toIso8601String(), 'source' => 'manual'];
        $r = $this->app($tok)->postJson('/api/app/readings', ['readings' => [$ok, $high, $bad, $sugar, $future, $ok]])->assertOk()->json('results');
        $this->assertSame(['saved', 'saved', 'rejected', 'saved', 'rejected', 'duplicate'], array_column($r, 'result'));
        $this->assertSame([null, 'high', null, 'low'], array_slice(array_map(fn ($x) => $x['flag'] ?? null, $r), 0, 4));
        $this->assertSame(['BP_HIGH', 'GLUCOSE_LOW'], SafetyAlert::orderBy('id')->pluck('rule')->all());
        $this->assertSame('warning', SafetyAlert::first()->severity);

        $list = $this->app($tok)->getJson('/api/app/readings?type=bp')->json('readings');
        $this->assertCount(2, $list);
        $staff = $this->staffApi()->getJson("/api/participants/{$this->p->id}/app")->json('readings');
        $this->assertSame('OMRON connect', collect($staff)->firstWhere('source', 'health_connect')['device']);

        $csv = $this->staffApi()->get('/api/export/data?dataset=readings&arm_coding=ab')->assertOk()->streamedContent();
        $lines = array_values(array_filter(explode("\n", trim($csv))));
        $this->assertCount(4, $lines); // header + 3 saved readings
        $this->assertStringContainsString('SMART-HEART-0007,A,bp,132,84,70', $lines[1]);
        $this->assertStringNotContainsString('Rajan', $csv);
    }

    public function test_caregiver_is_read_only_and_can_mark_seen(): void
    {
        $this->staffApi()->postJson("/api/participants/{$this->p->id}/app/enable")->assertOk();
        $this->staffApi()->postJson("/api/participants/{$this->p->id}/app/caregivers", ['name' => 'Lakshmi', 'relation' => 'Wife', 'phone' => '9876543210'])
            ->assertStatus(422); // must be their own number
        $res = $this->staffApi()->postJson("/api/participants/{$this->p->id}/app/caregivers", ['name' => 'Lakshmi', 'relation' => 'Wife', 'phone' => '9445566778'])->assertOk();
        $cg = collect($res->json('users'))->firstWhere('role', 'caregiver');

        $tok = $this->activate('+919445566778')->assertJsonPath('me.role', 'caregiver')->assertJsonPath('me.read_only', true)
            ->assertJsonPath('me.participant_name', 'Rajan')->json('token');
        $this->app($tok)->getJson('/api/app/today')->assertOk();
        $this->app($tok)->postJson('/api/app/readings', ['readings' => [['client_uuid' => (string) Str::uuid(), 'type' => 'weight',
            'values' => ['kg' => 70], 'measured_at' => now()->toIso8601String(), 'source' => 'manual']]])->assertStatus(403)->assertJsonPath('code', 'read_only');
        $this->app($tok)->putJson('/api/app/me', ['reminder_times' => ['Morning' => '07:00']])->assertStatus(403);
        $this->app($tok)->putJson('/api/app/me', ['lang' => 'en'])->assertOk()->assertJsonPath('lang', 'en');
        $this->app($tok)->postJson('/api/app/seen', ['date' => now()->toDateString()])->assertOk();
        $this->app($tok)->postJson('/api/app/seen', ['date' => now()->toDateString()])->assertOk(); // idempotent
        $this->app($tok)->getJson('/api/app/today')->assertJsonPath('seen_by.0.name', 'Lakshmi');

        // No caregiver if consent did not allow it.
        CrfForm::where('form_code', 'CON-01')->update(['data' => json_encode(['CON_GIVEN' => 'Yes', 'CON_CAREGIVER_VIEW' => 'No'])]);
        $this->staffApi()->postJson("/api/participants/{$this->p->id}/app/caregivers", ['name' => 'Son', 'relation' => 'Son', 'phone' => '9445566779'])->assertStatus(422);

        // Revoking ends access immediately.
        $this->staffApi()->postJson("/api/app-users/{$cg['id']}/revoke", ['reason' => 'Caregiver moved away'])->assertOk();
        $this->app($tok)->getJson('/api/app/me')->assertStatus(401);
    }

    public function test_content_tamil_served_only_after_review_and_push_skipped_without_credentials(): void
    {
        Http::fake();
        $id = $this->staffApi()->postJson('/api/content', ['type' => 'faq', 'title_en' => 'Can I climb stairs?', 'body_en' => 'Ask your doctor.',
            'title_ta' => 'படிக்கட்டு ஏறலாமா?', 'body_ta' => 'மருத்துவரிடம் கேளுங்கள்.', 'ta_reviewed' => false])->assertCreated()->json('id');
        $this->staffApi()->postJson('/api/participants/'.$this->p->id.'/app/enable')->assertOk();
        $tok = $this->activate('+919876543210')->json('token');
        $this->app($tok)->getJson('/api/app/content')->assertJsonPath('items', []); // draft not visible
        $this->staffApi()->postJson("/api/content/{$id}/status", ['status' => 'published', 'notify' => true])->assertOk();
        $this->app($tok)->getJson('/api/app/content?lang=ta')->assertJsonPath('items.0.lang', 'en'); // Tamil not reviewed → English
        $this->staffApi()->putJson("/api/content/{$id}", ['type' => 'faq', 'title_en' => 'Can I climb stairs?', 'body_en' => 'Ask your doctor.',
            'title_ta' => 'படிக்கட்டு ஏறலாமா?', 'body_ta' => 'மருத்துவரிடம் கேளுங்கள்.', 'ta_reviewed' => true])->assertOk();
        $this->app($tok)->getJson('/api/app/content?lang=ta')->assertJsonPath('items.0.title', 'படிக்கட்டு ஏறலாமா?');
        Http::assertNothingSent();
    }
}
