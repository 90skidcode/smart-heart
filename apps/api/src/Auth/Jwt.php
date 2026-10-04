<?php

declare(strict_types=1);

namespace SmartHeart\Auth;

use JsonException;
use SmartHeart\Infra\Clock;

/**
 * Minimal JWS/JWT, HS256 only (RFC 7515 / 7519), for tokens this API issues to itself.
 *
 * Verification is deliberately strict: exactly three base64url parts with no padding; header
 * `alg` must be exactly HS256 (so "none" and algorithm switching are impossible); `kid` must name
 * a configured key; the signature is checked in constant time before the payload is read; `exp`
 * is required and `nbf`/`iat` may not lie in the future. Tokens are signed, not encrypted: never
 * put anything secret in the claims.
 */
final readonly class Jwt
{
    /** @param array<string, string> $keys kid => key (at least 32 bytes each) */
    public function __construct(private array $keys, private string $currentKid, private Clock $clock)
    {
        foreach ($keys as $kid => $key) {
            if (strlen($key) < 32) {
                throw new \InvalidArgumentException("JWT key $kid is shorter than 32 bytes");
            }
        }
        if (!isset($keys[$currentKid])) {
            throw new \InvalidArgumentException("No JWT key with id $currentKid");
        }
    }

    /** Signs the claims with the current key, adding iat and exp. */
    public function issue(array $claims, int $ttlSeconds): string
    {
        $now = $this->clock->now()->getTimestamp();
        $claims += ['iat' => $now, 'exp' => $now + $ttlSeconds];
        $header = self::b64(self::json(['alg' => 'HS256', 'typ' => 'JWT', 'kid' => $this->currentKid]));
        $payload = self::b64(self::json($claims));
        return "$header.$payload." . self::sign("$header.$payload", $this->keys[$this->currentKid]);
    }

    /** base64url(HMAC-SHA256(key, signing input)) — the JWS HS256 signature. */
    public static function sign(string $signingInput, string $key): string
    {
        return self::b64(hash_hmac('sha256', $signingInput, $key, true));
    }

    /**
     * The verified claims. `$types` lists the accepted values of the `typ` claim
     * (user, participant, caregiver, mfa, signature), so one kind of token can never stand in for another.
     *
     * @param list<string> $types
     * @return array<string, mixed>
     * @throws InvalidToken
     */
    public function verify(string $token, array $types): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new InvalidToken('malformed');
        }
        [$h, $p, $s] = $parts;
        $header = self::decodeJson($h);
        if (($header['alg'] ?? null) !== 'HS256' || !is_string($header['kid'] ?? null) || !isset($this->keys[$header['kid']])) {
            throw new InvalidToken('bad header');
        }
        self::unb64($s); // canonical base64url, or InvalidToken
        if (!hash_equals(self::sign("$h.$p", $this->keys[$header['kid']]), $s)) {
            throw new InvalidToken('bad signature');
        }

        $claims = self::decodeJson($p);
        $now = $this->clock->now()->getTimestamp();
        if (!is_int($claims['exp'] ?? null) || $claims['exp'] <= $now) {
            throw new InvalidToken('expired');
        }
        if ((isset($claims['nbf']) && (!is_int($claims['nbf']) || $claims['nbf'] > $now))
            || (isset($claims['iat']) && (!is_int($claims['iat']) || $claims['iat'] > $now + 60))) {
            throw new InvalidToken('not yet valid');
        }
        if (!in_array($claims['typ'] ?? null, $types, true)) {
            throw new InvalidToken('wrong token type');
        }
        return $claims;
    }

    private static function json(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $text): string
    {
        if ($text === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $text) || strlen($text) % 4 === 1) {
            throw new InvalidToken('malformed');
        }
        $raw = base64_decode(strtr($text, '-_', '+/'), true);
        if ($raw === false || self::b64($raw) !== $text) { // rejects non-canonical encodings
            throw new InvalidToken('malformed');
        }
        return $raw;
    }

    /** @return array<string, mixed> */
    private static function decodeJson(string $part): array
    {
        try {
            $data = json_decode(self::unb64($part), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidToken('malformed');
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new InvalidToken('malformed');
        }
        return $data;
    }
}
