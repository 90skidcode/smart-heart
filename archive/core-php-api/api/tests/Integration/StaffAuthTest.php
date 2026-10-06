<?php

declare(strict_types=1);

namespace SmartHeart\Tests\Integration;

use SmartHeart\Auth\Totp;

final class StaffAuthTest extends IntegrationCase
{
    public function testWrongAndUnknownLookTheSame(): void
    {
        self::user('same@example.in', ['pi']);
        $wrong = $this->call('POST', '/auth/login', ['email' => 'same@example.in', 'password' => 'nope']);
        $unknown = $this->call('POST', '/auth/login', ['email' => 'nobody@example.in', 'password' => 'nope']);

        self::assertSame(401, $wrong->status);
        self::assertSame(401, $unknown->status);
        self::assertSame($wrong->body['detail'], $unknown->body['detail']);
    }

    public function testFiveFailuresLockForFifteenMinutes(): void
    {
        self::user('lock@example.in', ['pi']);
        for ($i = 1; $i <= 5; $i++) {
            self::assertSame(401, $this->call('POST', '/auth/login', ['email' => 'lock@example.in', 'password' => "bad $i"])->status);
        }
        $locked = $this->call('POST', '/auth/login', ['email' => 'lock@example.in', 'password' => 'correct horse battery']);
        self::assertSame(423, $locked->status);
        self::assertSame('900', $locked->headers['Retry-After']);

        $this->clock->advance('+15 minutes');
        $this->staffLogin('lock@example.in');
        self::assertSame(0, (int) self::scalar("SELECT failed_logins FROM users WHERE email = 'lock@example.in'"));
    }

    public function testMeShowsRolesPermissionsAndBlinding(): void
    {
        self::user('pi@example.in', ['pi']);
        self::user('assessor@example.in', ['assessor']);

        $pi = $this->call('GET', '/auth/me', token: $this->staffLogin('pi@example.in')['access_token'])->body;
        self::assertSame(['pi'], $pi['roles']);
        self::assertSame(['app_access.issue', 'app_access.view'], $pi['permissions']);
        self::assertFalse($pi['blinded']);
        self::assertSame('SRMC', $pi['site']['code']);

        $assessor = $this->call('GET', '/auth/me', token: $this->staffLogin('assessor@example.in')['access_token'])->body;
        self::assertTrue($assessor['blinded']);
        self::assertSame([], $assessor['permissions']);
    }

    public function testRefreshRotatesAndReuseKillsTheSession(): void
    {
        self::user('rotate@example.in', ['pi']);
        $first = $this->staffLogin('rotate@example.in');

        $second = $this->call('POST', '/auth/refresh', ['refresh_token' => $first['refresh_token']]);
        self::assertSame(200, $second->status);
        self::assertNotSame($first['refresh_token'], $second->body['refresh_token']);
        self::assertSame(200, $this->call('GET', '/auth/me', token: $second->body['access_token'])->status);

        // The old refresh token is replayed after the 30-second retry window (stolen?): the whole session ends.
        $this->clock->advance('+31 seconds');
        self::assertSame(401, $this->call('POST', '/auth/refresh', ['refresh_token' => $first['refresh_token']])->status);
        self::assertSame(401, $this->call('GET', '/auth/me', token: $second->body['access_token'])->status);
        self::assertSame(401, $this->call('POST', '/auth/refresh', ['refresh_token' => $second->body['refresh_token']])->status);
        self::assertSame('rotated_reuse', self::scalar("SELECT revoke_reason FROM auth_refresh_tokens WHERE revoke_reason <> 'rotated' ORDER BY id DESC LIMIT 1"));
    }

    public function testRetryWithinThirtySecondsIsServedOnce(): void
    {
        self::user('retry@example.in', ['pi']);
        $first = $this->staffLogin('retry@example.in');
        $lost = $this->call('POST', '/auth/refresh', ['refresh_token' => $first['refresh_token']])->body; // reply never arrives

        $this->clock->advance('+20 seconds');
        $retry = $this->call('POST', '/auth/refresh', ['refresh_token' => $first['refresh_token']]);
        self::assertSame(200, $retry->status);
        self::assertSame(200, $this->call('GET', '/auth/me', token: $retry->body['access_token'])->status);
        self::assertSame('rotated', self::scalar('SELECT revoke_reason FROM auth_refresh_tokens WHERE token_hash = ?', [hash('sha256', $lost['refresh_token'])]));

        // The window counts from the first rotation; retrying does not extend it.
        $this->clock->advance('+15 seconds');
        self::assertSame(401, $this->call('POST', '/auth/refresh', ['refresh_token' => $first['refresh_token']])->status);
        self::assertSame(401, $this->call('GET', '/auth/me', token: $retry->body['access_token'])->status, 'session ended');
    }

    public function testRetryCannotReviveASignedOutSession(): void
    {
        self::user('revive@example.in', ['pi']);
        $first = $this->staffLogin('revive@example.in');
        $second = $this->call('POST', '/auth/refresh', ['refresh_token' => $first['refresh_token']])->body;
        self::assertSame(204, $this->call('POST', '/auth/logout', token: $second['access_token'])->status);

        self::assertSame(401, $this->call('POST', '/auth/refresh', ['refresh_token' => $first['refresh_token']])->status);
    }

    public function testLogoutAndExpiryEndAccessImmediately(): void
    {
        self::user('out@example.in', ['pi']);
        $pair = $this->staffLogin('out@example.in');
        self::assertSame(204, $this->call('POST', '/auth/logout', token: $pair['access_token'])->status);
        self::assertSame(401, $this->call('GET', '/auth/me', token: $pair['access_token'])->status);

        $pair = $this->staffLogin('out@example.in');
        $this->clock->advance('+15 minutes');
        self::assertSame(401, $this->call('GET', '/auth/me', token: $pair['access_token'])->status);
        self::assertSame(200, $this->call('POST', '/auth/refresh', ['refresh_token' => $pair['refresh_token']])->status);
    }

    public function testDisabledUserLosesAccessMidSession(): void
    {
        $id = self::user('gone@example.in', ['pi']);
        $pair = $this->staffLogin('gone@example.in');
        self::$db->exec("UPDATE users SET status = 'disabled' WHERE id = $id");

        self::assertSame(401, $this->call('GET', '/auth/me', token: $pair['access_token'])->status);
        self::assertSame(401, $this->call('POST', '/auth/refresh', ['refresh_token' => $pair['refresh_token']])->status);
    }

    public function testMfaEnrolConfirmVerifyAndRecoveryCodes(): void
    {
        self::user('mfa@example.in', ['pi']);
        $token = $this->staffLogin('mfa@example.in')['access_token'];

        $enrol = $this->call('POST', '/auth/mfa/enroll', token: $token)->body;
        self::assertCount(10, $enrol['recovery_codes']);
        parse_str((string) parse_url($enrol['otpauth_uri'], PHP_URL_QUERY), $q);
        $secret = self::base32Decode($q['secret']);
        $now = fn() => $this->clock->now()->getTimestamp();

        self::assertSame(422, $this->call('POST', '/auth/mfa/confirm', ['code' => '000000'], $token)->status);
        self::assertSame(204, $this->call('POST', '/auth/mfa/confirm', ['code' => Totp::code($secret, Totp::step($now()))], $token)->status);
        self::assertNull(self::scalar("SELECT mfa_pending_secret_enc FROM users WHERE email = 'mfa@example.in'"));

        // Password now leads to the MFA step.
        $step1 = $this->call('POST', '/auth/login', ['email' => 'mfa@example.in', 'password' => 'correct horse battery'])->body;
        self::assertTrue($step1['mfa_required']);
        self::assertArrayNotHasKey('access_token', $step1);

        // The code used to confirm cannot be replayed; the next one works once.
        $sameCode = Totp::code($secret, Totp::step($now()));
        self::assertSame(401, $this->call('POST', '/auth/mfa/verify', ['mfa_token' => $step1['mfa_token'], 'code' => $sameCode])->status);
        $this->clock->advance('+30 seconds');
        $next = Totp::code($secret, Totp::step($now()));
        self::assertSame(200, $this->call('POST', '/auth/mfa/verify', ['mfa_token' => $step1['mfa_token'], 'code' => $next])->status);
        self::assertSame(401, $this->call('POST', '/auth/mfa/verify', ['mfa_token' => $step1['mfa_token'], 'code' => $next])->status);

        // A recovery code works once, typed in lower case without the hyphen.
        $code = strtolower(str_replace('-', '', $enrol['recovery_codes'][3]));
        self::assertSame(200, $this->call('POST', '/auth/mfa/verify', ['mfa_token' => $step1['mfa_token'], 'recovery_code' => $code])->status);
        self::assertSame(401, $this->call('POST', '/auth/mfa/verify', ['mfa_token' => $step1['mfa_token'], 'recovery_code' => $code])->status);

        // An MFA token is not an access token, and an access token is not an MFA token.
        self::assertSame(401, $this->call('GET', '/auth/me', token: $step1['mfa_token'])->status);
        self::assertSame(401, $this->call('POST', '/auth/mfa/verify', ['mfa_token' => $token, 'code' => $next])->status);

        // The secret is stored encrypted, never in the clear.
        $stored = (string) self::scalar("SELECT mfa_secret_enc FROM users WHERE email = 'mfa@example.in'");
        self::assertStringNotContainsString($secret, $stored);
    }

    public function testReauthGivesAShortSignatureTokenThatIsNotAnAccessToken(): void
    {
        self::user('sign@example.in', ['pi']);
        $token = $this->staffLogin('sign@example.in')['access_token'];

        self::assertSame(401, $this->call('POST', '/auth/reauth', ['password' => 'wrong'], $token)->status);
        $r = $this->call('POST', '/auth/reauth', ['password' => 'correct horse battery'], $token);
        self::assertSame(200, $r->status);
        self::assertSame('2026-10-03T04:35:00.000Z', $r->body['expires_at']);
        self::assertSame(401, $this->call('GET', '/auth/me', token: $r->body['signature_token'])->status);
    }

    public function testValidationListsEveryBadField(): void
    {
        $r = $this->call('POST', '/auth/login', ['email' => 42]);
        self::assertSame(422, $r->status);
        self::assertSame(['email', 'password'], array_column($r->body['errors'], 'field'));
    }

    private static function base32Decode(string $s): string
    {
        $bits = '';
        foreach (str_split($s) as $c) {
            $bits .= str_pad(decbin(strpos('ABCDEFGHIJKLMNOPQRSTUVWXYZ234567', $c)), 5, '0', STR_PAD_LEFT);
        }
        return implode('', array_map(static fn($b) => chr(bindec($b)), str_split(substr($bits, 0, intdiv(strlen($bits), 8) * 8), 8)));
    }
}
