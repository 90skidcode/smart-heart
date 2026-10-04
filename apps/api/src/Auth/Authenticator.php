<?php

declare(strict_types=1);

namespace SmartHeart\Auth;

use PDO;
use SmartHeart\Http\HttpError;
use SmartHeart\Http\Request;

/**
 * Turns `Authorization: Bearer <access token>` into a Principal. Besides the signature and expiry,
 * it checks that the session (refresh family) is still live and, for staff, that the account is
 * not disabled — so revocation is immediate. Every failure is the same plain 401.
 */
final readonly class Authenticator
{
    public function __construct(private Jwt $jwt, private TokenService $tokens, private PDO $db)
    {
    }

    public function staff(Request $request): Principal
    {
        return $this->resolve($request, ['user']);
    }

    /** A participant or a caregiver (caregivers are read-only; endpoints that write must check). */
    public function app(Request $request): Principal
    {
        return $this->resolve($request, ['participant', 'caregiver']);
    }

    public function any(Request $request): Principal
    {
        return $this->resolve($request, ['user', 'participant', 'caregiver']);
    }

    /** @param list<string> $kinds */
    private function resolve(Request $request, array $kinds): Principal
    {
        $token = $request->bearerToken() ?? throw HttpError::unauthorized();
        try {
            $c = $this->jwt->verify($token, $kinds);
        } catch (InvalidToken) {
            throw HttpError::unauthorized();
        }
        if (!is_int($c['sub'] ?? null) || !is_string($c['sid'] ?? null) || !$this->tokens->isLive($c['sid'])) {
            throw HttpError::unauthorized();
        }

        if ($c['typ'] === 'user') {
            $status = $this->db->prepare('SELECT status FROM users WHERE id = ?');
            $status->execute([$c['sub']]);
            if (in_array($status->fetchColumn(), [false, 'disabled'], true)) {
                throw HttpError::unauthorized();
            }
            return new Principal('user', $c['sub'], $c['sid'], (int) $c['site'], $c['roles'], $c['perms'], (bool) $c['blinded']);
        }
        return new Principal($c['typ'], $c['sub'], $c['sid'], participantId: (int) $c['pid'], credentialId: $c['cred'] ?? null);
    }
}
