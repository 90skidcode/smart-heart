<?php

declare(strict_types=1);

namespace SmartHeart\Auth;

use PDO;
use SmartHeart\Http\HttpError;
use SmartHeart\Http\Input;
use SmartHeart\Http\Request;
use SmartHeart\Http\Response;
use SmartHeart\Infra\AuditLog;
use SmartHeart\Infra\Clock;
use SmartHeart\Infra\Crypto;
use SmartHeart\Infra\Tx;

/**
 * Staff sign-in: password (Argon2id) → optional TOTP step → session. 5 failed passwords or codes
 * lock the account for 15 minutes. Unknown, disabled and wrong-password sign-ins all get the same 401.
 */
final readonly class StaffAuthController
{
    public const MAX_FAILURES = 5;
    public const LOCK_MINUTES = 15;
    private const MFA_TOKEN_SECONDS = 300;
    private const SIGNATURE_TOKEN_SECONDS = 300;
    private const RECOVERY_CODES = 10;

    public function __construct(
        private PDO $db,
        private Jwt $jwt,
        private TokenService $tokens,
        private Authenticator $auth,
        private Crypto $crypto,
        private AuditLog $audit,
        private Clock $clock,
    ) {
    }

    /** POST /auth/login */
    public function login(Request $request): Response
    {
        $in = Input::fromJson($request);
        $email = $in->string('email', 190);
        $password = $in->string('password', 1024);
        $in->validate();

        $user = $this->findUser('email = ?', [$email]);
        if ($user === null || $user['status'] === 'disabled') {
            password_verify($password, self::dummyHash()); // same work as a real check, so timing reveals nothing
            $this->audit->record(['type' => 'user', 'id' => $user['id'] ?? null], 'login_failed', 'users', $user['id'] ?? null,
                newValues: ['email' => $email], reason: $user === null ? 'unknown email' : 'account disabled', context: $request->auditContext());
            throw HttpError::unauthorized('Email or password is incorrect');
        }
        $this->assertNotLocked($user);

        if (!password_verify($password, $user['password_hash'])) {
            $this->recordFailure($user, $request, 'wrong password');
            throw HttpError::unauthorized('Email or password is incorrect');
        }

        $now = Clock::sql($this->clock->now());
        $rehash = password_needs_rehash($user['password_hash'], PASSWORD_ARGON2ID) ? password_hash($password, PASSWORD_ARGON2ID) : null;
        $this->db->prepare("UPDATE users SET failed_logins = 0, locked_until = NULL, status = 'active',
                password_hash = COALESCE(?, password_hash) WHERE id = ?")->execute([$rehash, $user['id']]);

        if ((int) $user['mfa_enabled'] === 1) {
            return Response::json([
                'mfa_required' => true,
                'mfa_token' => $this->jwt->issue(['typ' => 'mfa', 'sub' => (int) $user['id']], self::MFA_TOKEN_SECONDS),
            ]);
        }
        return Response::json(Tx::run($this->db, function () use ($user, $request, $now) {
            $this->db->prepare('UPDATE users SET last_login_at = ? WHERE id = ?')->execute([$now, $user['id']]);
            $this->audit->record(['type' => 'user', 'id' => (int) $user['id']], 'login', 'users', (int) $user['id'],
                newValues: ['mfa' => 'not_enrolled'], context: $request->auditContext());
            return $this->tokens->open('user', (int) $user['id'], $request);
        }));
    }

    /** POST /auth/mfa/verify — a 6-digit code, or one of the recovery codes. */
    public function verifyMfa(Request $request): Response
    {
        $in = Input::fromJson($request);
        $mfaToken = $in->string('mfa_token', 2048);
        $code = $in->string('code', 6, required: false);
        $recovery = $in->string('recovery_code', 32, required: false);
        if ($code === null && $recovery === null) {
            $in->fail('code', 'required', 'Send code or recovery_code');
        }
        $in->validate();

        try {
            $userId = (int) $this->jwt->verify((string) $mfaToken, ['mfa'])['sub'];
        } catch (InvalidToken) {
            throw HttpError::unauthorized('Sign in again');
        }

        $result = Tx::run($this->db, function () use ($userId, $code, $recovery, $request) {
            $user = $this->findUser('id = ?', [$userId], forUpdate: true);
            if ($user === null || $user['status'] === 'disabled' || (int) $user['mfa_enabled'] !== 1) {
                throw HttpError::unauthorized('Sign in again');
            }
            $this->assertNotLocked($user);

            $method = $code !== null ? $this->useTotp($user, $code) : $this->useRecoveryCode($user, (string) $recovery);
            if ($method === null) {
                return null; // record the failure outside this transaction's rollback
            }
            $this->db->prepare('UPDATE users SET failed_logins = 0, last_login_at = ? WHERE id = ?')
                ->execute([Clock::sql($this->clock->now()), $userId]);
            $this->audit->record(['type' => 'user', 'id' => $userId], 'login', 'users', $userId,
                newValues: ['mfa' => $method], context: $request->auditContext());
            return $this->tokens->open('user', $userId, $request);
        });

        if ($result === null) {
            $this->recordFailure($this->findUser('id = ?', [$userId]), $request, 'wrong MFA code');
            throw HttpError::unauthorized('That code did not work');
        }
        return Response::json($result);
    }

    /** POST /auth/mfa/enroll — new secret (pending until confirmed) and fresh recovery codes. */
    public function enrollMfa(Request $request): Response
    {
        $me = $this->auth->staff($request);
        return Response::json(Tx::run($this->db, function () use ($me, $request) {
            $user = $this->findUser('id = ?', [$me->id], forUpdate: true);
            if ((int) $user['mfa_enabled'] === 1) {
                throw new HttpError(409, 'conflict', 'MFA already enabled', 'Ask an administrator to reset MFA before enrolling a new authenticator.');
            }
            $secret = Totp::newSecret();
            $codes = [];
            for ($i = 0; $i < self::RECOVERY_CODES; $i++) {
                $codes[] = self::recoveryCode();
            }
            $this->db->prepare('UPDATE users SET mfa_pending_secret_enc = ? WHERE id = ?')
                ->execute([$this->crypto->encrypt($secret, "users.mfa_pending:$me->id"), $me->id]);
            $this->db->prepare('DELETE FROM mfa_recovery_codes WHERE user_id = ?')->execute([$me->id]);
            $insert = $this->db->prepare('INSERT INTO mfa_recovery_codes (user_id, code_hash) VALUES (?, ?)');
            foreach ($codes as $c) {
                $insert->execute([$me->id, $this->recoveryHash($c)]);
            }
            $this->audit->record($me->actor(), 'update', 'users', $me->id, newValues: ['mfa' => 'enrolment_started'], context: $request->auditContext());

            return ['otpauth_uri' => Totp::uri($secret, $user['email']), 'recovery_codes' => $codes];
        }));
    }

    /** POST /auth/mfa/confirm — the first code from the authenticator turns MFA on. */
    public function confirmMfa(Request $request): Response
    {
        $me = $this->auth->staff($request);
        $in = Input::fromJson($request);
        $code = $in->string('code', 6);
        $in->validate();

        Tx::run($this->db, function () use ($me, $code, $request) {
            $user = $this->findUser('id = ?', [$me->id], forUpdate: true);
            if ($user['mfa_pending_secret_enc'] === null) {
                throw new HttpError(409, 'conflict', 'No enrolment in progress', 'Start with POST /auth/mfa/enroll.');
            }
            $secret = $this->crypto->decrypt($user['mfa_pending_secret_enc'], "users.mfa_pending:$me->id");
            $step = Totp::verify($secret, (string) $code, $this->clock->now()->getTimestamp(), null)
                ?? throw HttpError::validation([['field' => 'code', 'code' => 'invalid', 'message' => 'That code did not match. Check the phone clock and try the next code.']]);

            $this->db->prepare('UPDATE users SET mfa_secret_enc = ?, mfa_pending_secret_enc = NULL, mfa_enabled = 1, mfa_last_step = ? WHERE id = ?')
                ->execute([$this->crypto->encrypt($secret, "users.mfa_secret:$me->id"), $step, $me->id]);
            $this->audit->record($me->actor(), 'update', 'users', $me->id,
                oldValues: ['mfa_enabled' => false], newValues: ['mfa_enabled' => true], context: $request->auditContext());
        });
        return Response::noContent();
    }

    /** GET /auth/me */
    public function me(Request $request): Response
    {
        $me = $this->auth->staff($request);
        $stmt = $this->db->prepare('SELECT u.id, u.email, u.full_name, u.mfa_enabled, u.status, u.locked_until,
                s.id AS site_id, s.code AS site_code, s.name AS site_name, s.timezone
            FROM users u JOIN sites s ON s.id = u.site_id WHERE u.id = ?');
        $stmt->execute([$me->id]);
        $u = $stmt->fetch();
        $claims = $this->tokens->staffClaims($me->id);

        return Response::json([
            'id' => (int) $u['id'],
            'email' => $u['email'],
            'full_name' => $u['full_name'],
            'site' => ['id' => (int) $u['site_id'], 'code' => $u['site_code'], 'name' => $u['site_name'], 'timezone' => $u['timezone']],
            'roles' => $claims['roles'],
            'permissions' => $claims['perms'],
            'blinded' => $claims['blinded'],
            'mfa_enabled' => (bool) $u['mfa_enabled'],
            'status' => $u['status'],
        ]);
    }

    /** POST /auth/reauth — password (and TOTP when enrolled) again, for a 5-minute signature token. */
    public function reauth(Request $request): Response
    {
        $me = $this->auth->staff($request);
        $in = Input::fromJson($request);
        $password = $in->string('password', 1024);
        $totp = $in->string('totp', 6, required: false);
        $in->validate();

        $ok = Tx::run($this->db, function () use ($me, $password, $totp) {
            $user = $this->findUser('id = ?', [$me->id], forUpdate: true);
            $this->assertNotLocked($user);
            if (!password_verify($password, $user['password_hash'])) {
                return false;
            }
            return (int) $user['mfa_enabled'] !== 1 || ($totp !== null && $this->useTotp($user, $totp) !== null);
        });
        if (!$ok) {
            $this->recordFailure($this->findUser('id = ?', [$me->id]), $request, 'wrong password or code at re-authentication');
            throw HttpError::unauthorized('Password or code is incorrect');
        }

        $expires = $this->clock->now()->modify('+' . self::SIGNATURE_TOKEN_SECONDS . ' seconds');
        $this->audit->record($me->actor(), 'login', 'users', $me->id, reason: 'Re-authenticated for e-signature', context: $request->auditContext());
        return Response::json([
            'signature_token' => $this->jwt->issue(['typ' => 'signature', 'sub' => $me->id, 'sid' => $me->sessionId], self::SIGNATURE_TOKEN_SECONDS),
            'expires_at' => Clock::iso($expires),
        ]);
    }

    /** Checks a TOTP code and burns its step. @return 'totp'|null */
    private function useTotp(array $user, string $code): ?string
    {
        $secret = $this->crypto->decrypt($user['mfa_secret_enc'], 'users.mfa_secret:' . $user['id']);
        $last = $user['mfa_last_step'] === null ? null : (int) $user['mfa_last_step'];
        $step = Totp::verify($secret, $code, $this->clock->now()->getTimestamp(), $last);
        if ($step === null) {
            return null;
        }
        $this->db->prepare('UPDATE users SET mfa_last_step = ? WHERE id = ?')->execute([$step, $user['id']]);
        return 'totp';
    }

    /** @return 'recovery_code'|null */
    private function useRecoveryCode(array $user, string $code): ?string
    {
        $stmt = $this->db->prepare('UPDATE mfa_recovery_codes SET used_at = ? WHERE user_id = ? AND code_hash = ? AND used_at IS NULL');
        $stmt->execute([Clock::sql($this->clock->now()), $user['id'], $this->recoveryHash($code)]);
        return $stmt->rowCount() === 1 ? 'recovery_code' : null;
    }

    private function recoveryHash(string $code): string
    {
        return $this->crypto->mac('mfa-recovery-code', strtoupper((string) preg_replace('/[\s-]+/', '', $code)));
    }

    /** 10 characters from the case-number alphabet (no look-alikes), shown as XXXXX-XXXXX. */
    private static function recoveryCode(): string
    {
        $alphabet = CaseNumber::ALPHABET;
        $s = '';
        for ($i = 0; $i < 10; $i++) {
            $s .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }
        return substr($s, 0, 5) . '-' . substr($s, 5);
    }

    /** Counts a failure; the 5th locks the account for 15 minutes. Commits on its own. */
    private function recordFailure(?array $user, Request $request, string $reason): void
    {
        if ($user === null) {
            return;
        }
        Tx::run($this->db, function () use ($user, $request, $reason) {
            $failures = (int) $this->db->query('SELECT failed_logins FROM users WHERE id = ' . (int) $user['id'] . ' FOR UPDATE')->fetchColumn() + 1;
            if ($failures >= self::MAX_FAILURES) {
                $until = Clock::sql($this->clock->now()->modify('+' . self::LOCK_MINUTES . ' minutes'));
                $this->db->prepare("UPDATE users SET failed_logins = 0, status = 'locked', locked_until = ? WHERE id = ?")->execute([$until, $user['id']]);
                $reason .= '; account locked for ' . self::LOCK_MINUTES . ' minutes';
            } else {
                $this->db->prepare('UPDATE users SET failed_logins = ? WHERE id = ?')->execute([$failures, $user['id']]);
            }
            $this->audit->record(['type' => 'user', 'id' => (int) $user['id']], 'login_failed', 'users', (int) $user['id'],
                reason: $reason, context: $request->auditContext());
        });
    }

    /** @throws HttpError 423 while a lock is in force (a lock with no end time is an administrator's lock) */
    private function assertNotLocked(array $user): void
    {
        if ($user['status'] !== 'locked') {
            return;
        }
        $now = $this->clock->now();
        if ($user['locked_until'] !== null && $user['locked_until'] <= Clock::sql($now)) {
            return; // lock expired
        }
        $headers = [];
        if ($user['locked_until'] !== null) {
            $until = new \DateTimeImmutable($user['locked_until'], new \DateTimeZone('UTC'));
            $headers['Retry-After'] = (string) max(1, $until->getTimestamp() - $now->getTimestamp());
        }
        throw new HttpError(423, 'account-locked', 'Account locked', 'Too many failed attempts. Try again later or contact the study administrator.', [], $headers);
    }

    /** @param list<mixed> $params */
    private function findUser(string $where, array $params, bool $forUpdate = false): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM users WHERE $where" . ($forUpdate ? ' FOR UPDATE' : ''));
        $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }

    private static function dummyHash(): string
    {
        static $hash = null;
        return $hash ??= password_hash(random_bytes(16), PASSWORD_ARGON2ID);
    }
}
