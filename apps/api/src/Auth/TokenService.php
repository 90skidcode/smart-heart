<?php

declare(strict_types=1);

namespace SmartHeart\Auth;

use PDO;
use SmartHeart\Http\HttpError;
use SmartHeart\Http\Request;
use SmartHeart\Infra\AuditLog;
use SmartHeart\Infra\Clock;
use SmartHeart\Infra\Tx;
use SmartHeart\Infra\Uuid;

/**
 * Sessions. A session is a refresh-token family: each refresh token is opaque, stored only as a
 * SHA-256 hash, and single-use. Refreshing revokes the old token and issues the next in the same
 * family; presenting an already-rotated token revokes the whole family (it may have been stolen).
 *
 * Grace: on a weak mobile signal the reply carrying the new token can be lost, and the app retries
 * with the old one. Within REUSE_GRACE_SECONDS of the rotation, while the session is still live, that
 * retry is served: the undelivered successor is retired and a fresh token issued. Later replays end
 * the session as above. The replayed token's rotation time is never reset, so retries cannot extend
 * the window.
 *
 * Access tokens carry the family id (`sid`), and every request checks the family is still live,
 * so logout, re-issue and withdrawal take effect immediately rather than when the access token expires.
 */
final readonly class TokenService
{
    public const STAFF_ACCESS_SECONDS = 900;          // contract: 15 min
    public const STAFF_REFRESH_SECONDS = 8 * 3600;     // decided 2026-10-04: one working day
    public const REUSE_GRACE_SECONDS = 30;             // decided 2026-10-04

    public function __construct(
        private PDO $db,
        private Jwt $jwt,
        private Clock $clock,
        private AuditLog $audit,
    ) {
    }

    /**
     * Opens a new session and returns its first token pair. Call inside the caller's transaction.
     *
     * @param 'user'|'participant'|'caregiver' $subjectType
     * @param array{device_id?: ?string, device_label?: ?string, app_version?: ?string} $device
     * @return array{access_token: string, refresh_token: string, expires_in: int, token_type: string}
     */
    public function open(string $subjectType, int $subjectId, Request $request, array $device = [], ?int $credentialId = null): array
    {
        $family = Uuid::v4();
        $refresh = $this->insertRefresh($subjectType, $subjectId, $family, $request, $device, $credentialId);
        return $this->pair($subjectType, $subjectId, $family, $credentialId, $device['device_id'] ?? null, $refresh);
    }

    /** @return array{access_token: string, refresh_token: string, expires_in: int, token_type: string} */
    public function refresh(string $refreshToken, Request $request): array
    {
        // Null means a rotated token was replayed: the family revocation must commit, then the request fails.
        $pair = Tx::run($this->db, function () use ($refreshToken, $request) {
            $stmt = $this->db->prepare('SELECT * FROM auth_refresh_tokens WHERE token_hash = ? FOR UPDATE');
            $stmt->execute([hash('sha256', $refreshToken)]);
            $row = $stmt->fetch();
            $now = $this->clock->now();

            if ($row === false || $row['expires_at'] <= Clock::sql($now)) {
                throw HttpError::unauthorized();
            }
            $retry = false;
            if ($row['revoked_at'] !== null) {
                if ($row['revoke_reason'] !== 'rotated') {
                    throw HttpError::unauthorized();
                }
                $graceStart = Clock::sql($now->modify('-' . self::REUSE_GRACE_SECONDS . ' seconds'));
                if ($row['revoked_at'] <= $graceStart) {
                    $this->revokeFamily($row['family_id'], 'rotated_reuse');
                    $this->audit->record(['type' => $row['subject_type'], 'id' => (int) $row['subject_id']], 'logout',
                        'auth_refresh_tokens', (int) $row['id'], null, null, ['family_id' => $row['family_id']],
                        'Rotated refresh token presented again; session revoked', $request->auditContext());
                    return null;
                }
                if (!$this->isLive($row['family_id'])) {
                    throw HttpError::unauthorized(); // signed out meanwhile: a retry must not revive it
                }
                $this->revokeFamily($row['family_id'], 'rotated'); // the successor the app never received
                $retry = true;
            }

            $subjectType = $row['subject_type'];
            $subjectId = (int) $row['subject_id'];
            $credentialId = $row['credential_id'] === null ? null : (int) $row['credential_id'];
            $this->assertSubjectMaySignIn($subjectType, $subjectId, $credentialId);

            if ($retry) {
                $this->db->prepare('UPDATE auth_refresh_tokens SET last_used_at = ? WHERE id = ?')->execute([Clock::sql($now), $row['id']]);
            } else {
                $this->db->prepare("UPDATE auth_refresh_tokens SET revoked_at = ?, revoke_reason = 'rotated', last_used_at = ? WHERE id = ?")
                    ->execute([Clock::sql($now), Clock::sql($now), $row['id']]);
            }
            $device = ['device_id' => $row['device_id'], 'device_label' => $row['device_label'], 'app_version' => $row['app_version']];
            $refresh = $this->insertRefresh($subjectType, $subjectId, $row['family_id'], $request, $device, $credentialId);

            return $this->pair($subjectType, $subjectId, $row['family_id'], $credentialId, $row['device_id'], $refresh);
        });
        return $pair ?? throw HttpError::unauthorized();
    }

    public function isLive(string $familyId): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM auth_refresh_tokens WHERE family_id = ? AND revoked_at IS NULL AND expires_at > ? LIMIT 1');
        $stmt->execute([$familyId, Clock::sql($this->clock->now())]);
        return $stmt->fetchColumn() !== false;
    }

    /** @return int sessions ended */
    public function revokeFamily(string $familyId, string $reason): int
    {
        return $this->revokeWhere('family_id = ?', [$familyId], $reason);
    }

    /** @return int sessions ended */
    public function revokeSubject(string $subjectType, int $subjectId, string $reason): int
    {
        return $this->revokeWhere('subject_type = ? AND subject_id = ?', [$subjectType, $subjectId], $reason);
    }

    /** @return int sessions ended */
    public function revokeCredential(int $credentialId, string $reason): int
    {
        return $this->revokeWhere('credential_id = ?', [$credentialId], $reason);
    }

    /** @param list<mixed> $params */
    private function revokeWhere(string $where, array $params, string $reason): int
    {
        $now = Clock::sql($this->clock->now());
        $live = $this->db->prepare("SELECT COUNT(DISTINCT family_id) FROM auth_refresh_tokens WHERE $where AND revoked_at IS NULL AND expires_at > ?");
        $live->execute([...$params, $now]);
        $this->db->prepare("UPDATE auth_refresh_tokens SET revoked_at = ?, revoke_reason = ? WHERE $where AND revoked_at IS NULL")
            ->execute([$now, $reason, ...$params]);
        return (int) $live->fetchColumn();
    }

    /** Refuses a refresh for a disabled user, or for an app subject whose case number or case has ended. */
    private function assertSubjectMaySignIn(string $subjectType, int $subjectId, ?int $credentialId): void
    {
        if ($subjectType === 'user') {
            $stmt = $this->db->prepare("SELECT 1 FROM users WHERE id = ? AND status <> 'disabled' AND (locked_until IS NULL OR locked_until <= ?)");
            $stmt->execute([$subjectId, Clock::sql($this->clock->now())]);
        } else {
            $stmt = $this->db->prepare("SELECT 1 FROM app_credentials c JOIN participants p ON p.id = c.participant_id
                WHERE c.id = ? AND c.status = 'active' AND p.arm = 'intervention'
                  AND p.status NOT IN ('withdrawn','lost_to_follow_up','completed')");
            $stmt->execute([$credentialId]);
        }
        if ($stmt->fetchColumn() === false) {
            throw HttpError::unauthorized();
        }
    }

    /** @param array{device_id?: ?string, device_label?: ?string, app_version?: ?string} $device */
    private function insertRefresh(string $subjectType, int $subjectId, string $family, Request $request, array $device, ?int $credentialId): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $lifetime = $this->lifetimes($subjectType)['refresh'];
        $this->db->prepare('INSERT INTO auth_refresh_tokens (subject_type, subject_id, credential_id, token_hash, family_id,
                device_id, device_label, app_version, user_agent, ip, expires_at, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute([
            $subjectType, $subjectId, $credentialId, hash('sha256', $token), $family,
            $device['device_id'] ?? null, $device['device_label'] ?? null, $device['app_version'] ?? null,
            $request->userAgent() ?: null, @inet_pton($request->ip) ?: null,
            Clock::sql($this->clock->now()->modify("+$lifetime seconds")), Clock::sql($this->clock->now()),
        ]);
        return $token;
    }

    /** @return array{access_token: string, refresh_token: string, expires_in: int, token_type: string} */
    private function pair(string $subjectType, int $subjectId, string $family, ?int $credentialId, ?string $deviceId, string $refresh): array
    {
        $ttl = $this->lifetimes($subjectType)['access'];
        $claims = ['typ' => $subjectType, 'sub' => $subjectId, 'sid' => $family];

        if ($subjectType === 'user') {
            $claims += $this->staffClaims($subjectId);
        } else {
            $pid = $subjectType === 'participant'
                ? $subjectId
                : (int) $this->scalar('SELECT participant_id FROM caregivers WHERE id = ?', [$subjectId]);
            $claims += ['pid' => $pid, 'cred' => $credentialId, 'dev' => $deviceId];
        }

        return [
            'access_token' => $this->jwt->issue($claims, $ttl),
            'refresh_token' => $refresh,
            'expires_in' => $ttl,
            'token_type' => 'Bearer',
        ];
    }

    /** @return array{site: int, roles: list<string>, perms: list<string>, blinded: bool} */
    public function staffClaims(int $userId): array
    {
        $roles = $this->db->prepare('SELECT r.code, r.is_blinded FROM user_roles ur JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? ORDER BY r.id');
        $roles->execute([$userId]);
        $roles = $roles->fetchAll();
        $perms = $this->db->prepare('SELECT DISTINCT p.code FROM user_roles ur JOIN role_permissions rp ON rp.role_id = ur.role_id
            JOIN permissions p ON p.id = rp.permission_id WHERE ur.user_id = ? ORDER BY p.code');
        $perms->execute([$userId]);

        return [
            'site' => (int) $this->scalar('SELECT site_id FROM users WHERE id = ?', [$userId]),
            'roles' => array_column($roles, 'code'),
            'perms' => $perms->fetchAll(PDO::FETCH_COLUMN),
            'blinded' => in_array(1, array_map('intval', array_column($roles, 'is_blinded')), true),
        ];
    }

    /** @return array{access: int, refresh: int} seconds */
    private function lifetimes(string $subjectType): array
    {
        if ($subjectType === 'user') {
            return ['access' => self::STAFF_ACCESS_SECONDS, 'refresh' => self::STAFF_REFRESH_SECONDS];
        }
        $tokens = AppSettings::signIn($this->db)['tokens'];
        return $subjectType === 'participant'
            ? ['access' => $tokens['participant_access_min'] * 60, 'refresh' => $tokens['refresh_days_participant'] * 86400]
            : ['access' => $tokens['caregiver_access_min'] * 60, 'refresh' => $tokens['refresh_days_caregiver'] * 86400];
    }

    /** @param list<mixed> $params */
    private function scalar(string $sql, array $params): mixed
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }
}
