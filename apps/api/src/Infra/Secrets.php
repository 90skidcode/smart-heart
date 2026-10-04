<?php

declare(strict_types=1);

namespace SmartHeart\Infra;

use InvalidArgumentException;

/**
 * Keys from server environment variables. Every key is base64 and at least 32 bytes.
 *
 *   JWT_SIGNING_KEY          HS256 key for access, MFA and signature tokens
 *   JWT_SIGNING_KEY_ID       its id, written as "kid" in each token (default k1)
 *   JWT_PREVIOUS_KEYS        optional "kid:base64,kid:base64" still accepted after a rotation
 *   CASE_NUMBER_PEPPER_<n>   HMAC key(s) for case numbers; n is app_credentials.pepper_key_id (1–255)
 *   CASE_NUMBER_PEPPER_CURRENT  which n new numbers use (default 1)
 *   DATA_ENCRYPTION_KEY      AES-256-GCM key for encrypted columns (exactly 32 bytes)
 *   DATA_ENCRYPTION_KEY_ID   its id, stored in each ciphertext (1–255, default 1)
 *
 * Missing or short keys stop the app at start-up rather than at first use.
 */
final readonly class Secrets
{
    /**
     * @param array<string, string> $jwtKeys kid => key; the current one first
     * @param array<int, string> $peppers key id => pepper
     */
    public function __construct(
        public array $jwtKeys,
        public string $jwtCurrentKid,
        public array $peppers,
        public int $currentPepperId,
        public string $dataKey,
        public int $dataKeyId,
    ) {
        if (!isset($jwtKeys[$jwtCurrentKid], $peppers[$currentPepperId])) {
            throw new InvalidArgumentException('The current JWT key and case-number pepper must be configured');
        }
        if (strlen($dataKey) !== 32 || $dataKeyId < 1 || $dataKeyId > 255) {
            throw new InvalidArgumentException('DATA_ENCRYPTION_KEY must be 32 bytes and DATA_ENCRYPTION_KEY_ID 1–255');
        }
    }

    public static function fromEnvironment(?string $envFile = null): self
    {
        $env = EnvFile::read($envFile);

        $kid = $env('JWT_SIGNING_KEY_ID') ?? 'k1';
        $jwtKeys = [$kid => self::key('JWT_SIGNING_KEY', $env('JWT_SIGNING_KEY'))];
        foreach (array_filter(explode(',', $env('JWT_PREVIOUS_KEYS') ?? '')) as $pair) {
            [$oldKid, $b64] = array_pad(explode(':', trim($pair), 2), 2, '');
            $jwtKeys[$oldKid] ??= self::key("JWT_PREVIOUS_KEYS[$oldKid]", $b64);
        }

        $peppers = [];
        foreach ($env->all() as $name => $value) {
            if (preg_match('/^CASE_NUMBER_PEPPER_([1-9]\d{0,2})$/', $name, $m) && (int) $m[1] <= 255) {
                $peppers[(int) $m[1]] = self::key($name, $value);
            }
        }

        return new self(
            $jwtKeys,
            $kid,
            $peppers,
            (int) ($env('CASE_NUMBER_PEPPER_CURRENT') ?? '1'),
            self::key('DATA_ENCRYPTION_KEY', $env('DATA_ENCRYPTION_KEY')),
            (int) ($env('DATA_ENCRYPTION_KEY_ID') ?? '1'),
        );
    }

    /** Fresh random keys, for tests and for generating a local .env. */
    public static function random(): self
    {
        return new self(['k1' => random_bytes(32)], 'k1', [1 => random_bytes(32)], 1, random_bytes(32), 1);
    }

    private static function key(string $name, ?string $b64): string
    {
        $raw = base64_decode((string) $b64, true);
        if ($raw === false || strlen($raw) < 32) {
            throw new InvalidArgumentException("$name must be set to at least 32 random bytes, base64-encoded (php bin/generate-secrets.php)");
        }
        return $raw;
    }
}
