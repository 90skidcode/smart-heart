<?php

namespace App\Services\App;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/**
 * Verifies a Firebase Auth ID token (phone sign-in) without extra packages:
 * RS256 signature against Google's published certificates, then audience, issuer and times
 * (https://firebase.google.com/docs/auth/admin/verify-id-tokens#verify_id_tokens_using_a_third-party_jwt_library).
 * Returns the verified 10-digit Indian mobile number and the Firebase uid.
 */
class FirebaseTokenVerifier
{
    public const CERTS_URL = 'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com';

    public const CACHE_KEY = 'firebase_securetoken_certs';

    private const LEEWAY = 60;

    /** @return array{phone: string, uid: string} */
    public function verify(string $jwt): array
    {
        if (config('smartheart.app.dev_login') && app()->environment('local', 'testing') && str_starts_with($jwt, 'dev:')) {
            return ['phone' => self::phone10(substr($jwt, 4)), 'uid' => 'dev-'.substr($jwt, 4)];
        }
        $project = (string) config('smartheart.app.firebase_project_id');
        if ($project === '') {
            throw new InvalidArgumentException('App sign-in is not configured on the server (FIREBASE_PROJECT_ID).');
        }

        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            throw new InvalidArgumentException('Invalid sign-in token.');
        }
        [$h, $p, $s] = $parts;
        $header = json_decode(self::b64($h), true);
        $claims = json_decode(self::b64($p), true);
        if (! is_array($header) || ! is_array($claims) || ($header['alg'] ?? '') !== 'RS256' || empty($header['kid'])) {
            throw new InvalidArgumentException('Invalid sign-in token.');
        }
        $cert = $this->certs()[$header['kid']] ?? null;
        if (! $cert || openssl_verify("{$h}.{$p}", self::b64($s), $cert, OPENSSL_ALGO_SHA256) !== 1) {
            throw new InvalidArgumentException('Sign-in token signature is not valid.');
        }

        $now = time();
        $checks = [
            ($claims['aud'] ?? null) === $project,
            ($claims['iss'] ?? null) === "https://securetoken.google.com/{$project}",
            is_int($claims['exp'] ?? null) && $claims['exp'] > $now - self::LEEWAY,
            is_int($claims['iat'] ?? null) && $claims['iat'] <= $now + self::LEEWAY,
            ! isset($claims['auth_time']) || $claims['auth_time'] <= $now + self::LEEWAY,
            is_string($claims['sub'] ?? null) && $claims['sub'] !== '',
        ];
        if (in_array(false, $checks, true)) {
            throw new InvalidArgumentException('Sign-in token has expired or is not for this app. Please verify your number again.');
        }
        if (empty($claims['phone_number'])) {
            throw new InvalidArgumentException('Please sign in with your mobile number.');
        }

        return ['phone' => self::phone10($claims['phone_number']), 'uid' => $claims['sub']];
    }

    /** kid => PEM certificate, cached for as long as Google says. */
    private function certs(): array
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (is_array($cached)) {
            return $cached;
        }
        $res = Http::timeout(10)->get(self::CERTS_URL);
        if (! $res->ok()) {
            throw new InvalidArgumentException('Could not reach the sign-in service. Please try again.');
        }
        preg_match('/max-age=(\d+)/', (string) $res->header('Cache-Control'), $m);
        Cache::put(self::CACHE_KEY, $res->json(), max(60, (int) ($m[1] ?? 3600)));

        return $res->json();
    }

    /** "+91 98765 43210" → "9876543210". */
    public static function phone10(string $e164): string
    {
        $d = preg_replace('/\D/', '', $e164);
        if (strlen($d) === 12 && str_starts_with($d, '91')) {
            $d = substr($d, 2);
        }
        if (! preg_match('/^[6-9]\d{9}$/', $d)) {
            throw new InvalidArgumentException('Only Indian mobile numbers can be used.');
        }

        return $d;
    }

    private static function b64(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/').str_repeat('=', (4 - strlen($s) % 4) % 4));
    }
}
