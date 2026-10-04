<?php

declare(strict_types=1);

namespace SmartHeart\Tests\Integration;

use SmartHeart\Auth\AppAuthController;
use SmartHeart\Auth\CaseNumber;
use SmartHeart\Infra\AuditLog;

final class CaseNumberFlowTest extends IntegrationCase
{
    private static string $pi;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::user('pi@example.in', ['pi']);
        self::user('assessor@example.in', ['assessor']);
        self::user('coach@other.in', ['care_coach'], 'OTHER');
    }

    protected function setUp(): void
    {
        parent::setUp();
        self::$pi = $this->staffLogin('pi@example.in')['access_token'];
    }

    private function issue(int $pid, array $body = ['subject_type' => 'participant'], ?string $token = null): \SmartHeart\Http\Response
    {
        return $this->call('POST', "/participants/$pid/case-numbers", $body, $token ?? self::$pi);
    }

    public function testIssueThenSignInOnOnePhoneAtATime(): void
    {
        $pid = self::participant('SMART-HEART-0101');
        $issued = $this->issue($pid);

        self::assertSame(201, $issued->status);
        $number = $issued->body['case_number'];
        self::assertTrue(CaseNumber::isWellFormed($number));
        self::assertMatchesRegularExpression('/^[2-9A-Z]{3}-[2-9A-Z]{3}-[2-9A-Z]{3}$/', $number);
        self::assertSame(1, $issued->body['version']);
        self::assertSame('en', $issued->body['card']['primary_language']);
        self::assertSame('Your SMART-HEART case number', $issued->body['card']['heading_en']);
        self::assertNotEmpty($issued->body['card']['heading_ta'], 'the card is always bilingual');
        self::assertNotEmpty($issued->body['card']['instructions_ta']);
        self::assertSame(1, (int) self::scalar("SELECT done FROM post_randomisation_tasks WHERE participant_id = ? AND task_code = 'APP_ACCOUNT'", [$pid]));

        // The plain number is stored nowhere: not in app_credentials, not in the audit trail.
        $compact = str_replace('-', '', $number);
        foreach (['app_credentials', 'audit_log'] as $table) {
            $dump = json_encode(self::$db->query("SELECT * FROM $table")->fetchAll(), JSON_INVALID_UTF8_SUBSTITUTE);
            self::assertStringNotContainsString($compact, $dump, $table);
            self::assertStringNotContainsString($number, $dump, $table);
        }

        // Typed in lower case with spaces, on phone A.
        $a = $this->appLogin(strtolower(str_replace('-', ' ', $number)), self::installId());
        self::assertSame(200, $a->status);
        self::assertSame('participant', $a->body['subject_type']);
        self::assertFalse($a->body['signed_out_other_device']);
        self::assertSame('en', $a->body['preferred_language']);
        self::assertNotNull(self::scalar('SELECT app_activated_at FROM participants WHERE id = ?', [$pid]));

        // Phone B signs in: phone A is signed out at once.
        $b = $this->appLogin($number, self::installId());
        self::assertTrue($b->body['signed_out_other_device']);
        self::assertSame(401, $this->call('POST', '/auth/logout', token: $a->body['access_token'])->status);
        self::assertSame(401, $this->call('POST', '/auth/refresh', ['refresh_token' => $a->body['refresh_token']])->status);
        self::assertSame(200, $this->call('POST', '/auth/refresh', ['refresh_token' => $b->body['refresh_token']])->status);

        // Staff see one live phone.
        $list = $this->call('GET', "/participants/$pid/case-numbers", token: self::$pi)->body;
        self::assertSame(substr($compact, -3), $list[0]['hint']);
        self::assertCount(1, $list[0]['sessions']);
        self::assertSame('Android 14 · Redmi Note 12', $list[0]['sessions'][0]['device_label']);
        self::assertArrayNotHasKey('case_number', $list[0]);
    }

    public function testReissueNeedsAReasonAndKillsTheOldNumberAndItsSessions(): void
    {
        $pid = self::participant('SMART-HEART-0102');
        $old = $this->issue($pid)->body['case_number'];
        $session = $this->appLogin($old, self::installId())->body;

        self::assertSame(422, $this->issue($pid)->status);
        $new = $this->issue($pid, ['subject_type' => 'participant', 'reason' => 'Card lost']);
        self::assertSame(201, $new->status);
        self::assertSame(2, $new->body['version']);
        self::assertSame(1, $new->body['sessions_revoked']);

        self::assertSame(401, $this->appLogin($old, self::installId(), '10.1.0.2')->status);
        self::assertSame(401, $this->call('POST', '/auth/refresh', ['refresh_token' => $session['refresh_token']])->status);
        self::assertSame(200, $this->appLogin($new->body['case_number'], self::installId(), '10.1.0.3')->status);
    }

    public function testWhoMayIssue(): void
    {
        $control = self::participant('SMART-HEART-0103', 'control');
        $withdrawn = self::participant('SMART-HEART-0104', 'intervention', 'withdrawn');
        $intervention = self::participant('SMART-HEART-0105');

        self::assertSame(409, $this->issue($control)->status);
        self::assertSame(409, $this->issue($withdrawn)->status);
        self::assertSame(403, $this->issue($intervention, token: $this->staffLogin('assessor@example.in')['access_token'])->status, 'blinded');
        self::assertSame(403, $this->call('GET', "/participants/$intervention/case-numbers", token: $this->staffLogin('assessor@example.in')['access_token'])->status);
        self::assertSame(404, $this->issue($intervention, token: $this->staffLogin('coach@other.in')['access_token'])->status, 'other site');
        self::assertSame(404, $this->issue(999999)->status);
        self::assertSame(401, $this->issue($intervention, token: 'nonsense')->status);
    }

    public function testCaregiverNumberIsSeparateAndRevocable(): void
    {
        $pid = self::participant('SMART-HEART-0106', language: 'ta');
        $cg = self::insert('caregivers', ['participant_id' => $pid, 'relation' => 'daughter']);
        $participantNumber = $this->issue($pid)->body['case_number'];
        $issued = $this->issue($pid, ['subject_type' => 'caregiver', 'caregiver_id' => $cg]);
        self::assertSame(201, $issued->status);
        self::assertSame('ta', $issued->body['card']['primary_language']);
        self::assertNotEmpty($issued->body['card']['heading_en'], 'the card is always bilingual');

        $login = $this->appLogin($issued->body['case_number'], self::installId(), '10.2.0.1');
        self::assertSame('caregiver', $login->body['subject_type']);
        self::assertNull($login->body['onboarding_step']);
        self::assertSame('active', self::scalar('SELECT invite_status FROM caregivers WHERE id = ?', [$cg]));
        // The caregiver's phone is not "another phone" for the participant.
        $participantLogin = $this->appLogin($participantNumber, self::installId(), '10.2.0.2');
        self::assertSame(200, $participantLogin->status);
        self::assertFalse($participantLogin->body['signed_out_other_device']);

        $credId = $issued->body['credential_id'];
        self::assertSame(422, $this->call('POST', "/participants/$pid/case-numbers/$credId/revoke", [], self::$pi)->status);
        self::assertSame(204, $this->call('POST', "/participants/$pid/case-numbers/$credId/revoke", ['reason' => 'Caregiver moved away'], self::$pi)->status);
        self::assertSame(401, $this->call('POST', '/auth/refresh', ['refresh_token' => $login->body['refresh_token']])->status);
        self::assertSame(401, $this->appLogin($issued->body['case_number'], self::installId(), '10.2.0.3')->status);
        self::assertSame('revoked', self::scalar('SELECT invite_status FROM caregivers WHERE id = ?', [$cg]));
        self::assertSame(409, $this->call('POST', "/participants/$pid/case-numbers/$credId/revoke", ['reason' => 'again'], self::$pi)->status);
    }

    public function testStaffSignOutKeepsTheNumberWorking(): void
    {
        $pid = self::participant('SMART-HEART-0107');
        $issued = $this->issue($pid)->body;
        $session = $this->appLogin($issued['case_number'], self::installId(), '10.3.0.1')->body;

        self::assertSame(204, $this->call('POST', "/participants/$pid/case-numbers/{$issued['credential_id']}/sign-out", token: self::$pi)->status);
        self::assertSame(401, $this->call('POST', '/auth/refresh', ['refresh_token' => $session['refresh_token']])->status);
        self::assertSame(200, $this->appLogin($issued['case_number'], self::installId(), '10.3.0.2')->status);
    }

    public function testWithdrawalStopsSignInAndRefresh(): void
    {
        $pid = self::participant('SMART-HEART-0108');
        $number = $this->issue($pid)->body['case_number'];
        $session = $this->appLogin($number, self::installId(), '10.4.0.1')->body;
        self::$db->exec("UPDATE participants SET status = 'withdrawn' WHERE id = $pid");

        self::assertSame(401, $this->call('POST', '/auth/refresh', ['refresh_token' => $session['refresh_token']])->status);
        self::assertSame(401, $this->appLogin($number, self::installId(), '10.4.0.2')->status);
    }

    public function testEveryFailureLooksTheSame(): void
    {
        $pid = self::participant('SMART-HEART-0109');
        $issued = $this->issue($pid)->body;
        $this->issue($pid, ['subject_type' => 'participant', 'reason' => 'Card lost']); // first number is now replaced

        $bodies = [];
        foreach (['K7Q-M3X-PD', 'K7Q-M3X-PD7', CaseNumber::generate(), $issued['case_number']] as $i => $input) {
            $r = $this->appLogin($input, self::installId(), "10.5.0.$i");
            self::assertSame(401, $r->status, $input);
            $bodies[] = $r->body['detail'];
        }
        self::assertSame([AppAuthController::FAILED_MESSAGE], array_values(array_unique($bodies)));
        self::assertSame(['malformed', 'malformed', 'unknown', 'ended'],
            self::$db->query("SELECT failure FROM app_login_attempts WHERE INET6_NTOA(ip) LIKE '10.5.0.%' ORDER BY id")->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function testThrottleAfterFiveFailuresFromOnePhone(): void
    {
        $pid = self::participant('SMART-HEART-0110');
        $number = $this->issue($pid)->body['case_number'];
        $install = self::installId();

        for ($i = 0; $i < 5; $i++) {
            self::assertSame(401, $this->appLogin(CaseNumber::generate(), $install, "10.6.0.$i")->status);
        }
        $blocked = $this->appLogin($number, $install, '10.6.0.9'); // right number, same phone, new IP
        self::assertSame(429, $blocked->status);
        self::assertSame('900', $blocked->headers['Retry-After']);

        $this->clock->advance('+16 minutes');
        self::assertSame(200, $this->appLogin($number, $install, '10.6.0.9')->status);
    }

    public function testAttackAlertAtFiftyFailuresAcrossClients(): void
    {
        $this->clock->advance('+1 day'); // clear failures from other tests out of the 10-minute window
        for ($i = 0; $i < 50; $i++) {
            $this->appLogin(CaseNumber::generate(), self::installId(), "10.7.0.$i");
        }
        self::assertCount(1, array_filter($this->logged, static fn($l) => str_starts_with($l, 'SECURITY:')));
        $this->appLogin(CaseNumber::generate(), self::installId(), '10.7.1.1');
        self::assertCount(1, array_filter($this->logged, static fn($l) => str_starts_with($l, 'SECURITY:')), 'alerts once, not per failure');
    }

    public function testAuditChainIsUnbroken(): void
    {
        $prev = str_repeat('0', 64);
        $rows = self::$db->query('SELECT * FROM audit_log ORDER BY id')->fetchAll();
        self::assertGreaterThan(20, count($rows));
        foreach ($rows as $row) {
            $canonical = AuditLog::canonical([
                'occurred_at' => $row['occurred_at'], 'actor_type' => $row['actor_type'], 'actor_id' => $row['actor_id'],
                'action' => $row['action'], 'entity_table' => $row['entity_table'], 'entity_id' => $row['entity_id'],
                'participant_id' => $row['participant_id'],
                'old_values' => $row['old_values'] === null ? null : json_decode($row['old_values'], true),
                'new_values' => $row['new_values'] === null ? null : json_decode($row['new_values'], true),
                'reason' => $row['reason'], 'request_id' => $row['request_id'],
            ]);
            self::assertSame($prev, $row['prev_hash'], "row {$row['id']}");
            self::assertSame(hash('sha256', $prev . $canonical), $row['row_hash'], "row {$row['id']}");
            $prev = $row['row_hash'];
        }
        self::assertSame($prev, self::scalar('SELECT last_hash FROM audit_chain_head WHERE id = 1'));
    }
}
