<?php

declare(strict_types=1);

namespace SmartHeart\Auth;

/** TOTP for staff MFA (RFC 6238: HMAC-SHA1, 30-second steps, 6 digits), as authenticator apps expect. */
final class Totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function newSecret(): string
    {
        return random_bytes(20);
    }

    public static function code(string $secret, int $step, int $digits = self::DIGITS): string
    {
        $hmac = hash_hmac('sha1', pack('J', $step), $secret, true);
        $offset = ord($hmac[19]) & 0x0f;
        $binary = (unpack('N', substr($hmac, $offset, 4))[1] & 0x7fffffff) % (10 ** $digits);
        return str_pad((string) $binary, $digits, '0', STR_PAD_LEFT);
    }

    public static function step(int $unixTime): int
    {
        return intdiv($unixTime, self::PERIOD);
    }

    /**
     * The matching step for `$code` within ±1 step of `$unixTime` (clock drift), or null.
     * Steps at or before `$lastUsedStep` are refused, so a code works once.
     */
    public static function verify(string $secret, string $code, int $unixTime, ?int $lastUsedStep): ?int
    {
        if (!preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $now = self::step($unixTime);
        $match = null;
        foreach ([$now - 1, $now, $now + 1] as $step) { // check all three so timing does not reveal which matched
            if (hash_equals(self::code($secret, $step), $code) && ($lastUsedStep === null || $step > $lastUsedStep)) {
                $match ??= $step;
            }
        }
        return $match;
    }

    public static function uri(string $secret, string $account, string $issuer = 'SMART-HEART'): string
    {
        return 'otpauth://totp/' . rawurlencode($issuer) . ':' . rawurlencode($account)
            . '?secret=' . self::base32($secret) . '&issuer=' . rawurlencode($issuer)
            . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD;
    }

    public static function base32(string $raw): string
    {
        $bits = '';
        foreach (str_split($raw) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }
}
