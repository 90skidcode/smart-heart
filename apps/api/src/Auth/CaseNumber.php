<?php

declare(strict_types=1);

namespace SmartHeart\Auth;

use InvalidArgumentException;

/**
 * Case numbers: the only sign-in credential for the SHI participant app.
 * There is no OTP and no SMS anywhere in sign-in.
 *
 * Format
 *   9 characters from a 30-symbol alphabet with no look-alikes
 *   (no 0/O, no 1/I/L, no U), shown in three groups of three: K7Q-M3X-PDX.
 *   Characters 1-8 are random (random_int, a CSPRNG); character 9 is a
 *   Luhn mod 30 check character, so the app catches typos before it sends anything.
 *   Random part: 30^8 = 656,100,000,000 combinations (about 39 bits).
 *
 * Storage
 *   The number is shown once, when it is issued. The database keeps only
 *   HMAC-SHA256(pepper, normalised number) for look-up, plus the last group
 *   ("ends in PDX") so staff can tell which card a participant holds.
 *   The pepper lives in the secrets store, never in the database.
 */
final class CaseNumber
{
    public const ALPHABET = '23456789ABCDEFGHJKMNPQRSTVWXYZ';
    public const RANDOM_LENGTH = 8;
    public const LENGTH = 9;

    /** A new random case number, formatted for the card and the screen (K7Q-M3X-PDX). */
    public static function generate(): string
    {
        $max = strlen(self::ALPHABET) - 1;
        $body = '';
        for ($i = 0; $i < self::RANDOM_LENGTH; $i++) {
            $body .= self::ALPHABET[random_int(0, $max)];
        }
        return self::format($body . self::checkCharacter($body));
    }

    /** Upper-case and drop spaces and hyphens. Look-alikes (O, 0, I, 1, L, U) are not mapped: they make the number invalid. */
    public static function normalise(string $input): string
    {
        return strtoupper((string) preg_replace('/[\s\-]+/u', '', $input));
    }

    /** True when the input has 9 alphabet characters and a valid check character. */
    public static function isWellFormed(string $input): bool
    {
        $s = self::normalise($input);
        if (strlen($s) !== self::LENGTH || strspn($s, self::ALPHABET) !== self::LENGTH) {
            return false;
        }
        return self::checkCharacter(substr($s, 0, self::RANDOM_LENGTH)) === $s[self::RANDOM_LENGTH];
    }

    /** Luhn mod N check character over the alphabet (N = 30). */
    public static function checkCharacter(string $body): string
    {
        $n = strlen(self::ALPHABET);
        $factor = 2;
        $sum = 0;
        for ($i = strlen($body) - 1; $i >= 0; $i--) {
            $codePoint = strpos(self::ALPHABET, $body[$i]);
            if ($codePoint === false) {
                throw new InvalidArgumentException('Character outside the case-number alphabet: ' . $body[$i]);
            }
            $addend = $factor * $codePoint;
            $factor = $factor === 2 ? 1 : 2;
            $sum += intdiv($addend, $n) + ($addend % $n);
        }
        return self::ALPHABET[($n - ($sum % $n)) % $n];
    }

    /** K7QM3XPDX → K7Q-M3X-PDX */
    public static function format(string $normalised): string
    {
        return implode('-', str_split($normalised, 3));
    }

    /** The value stored in app_credentials.case_no_hmac (BINARY(32)) and used for look-up. */
    public static function hmac(string $input, string $pepper): string
    {
        if ($pepper === '') {
            throw new InvalidArgumentException('Pepper must not be empty');
        }
        return hash_hmac('sha256', self::normalise($input), $pepper, true);
    }

    /** Last group, shown to staff as "ends in PDX". */
    public static function hint(string $input): string
    {
        return substr(self::normalise($input), -3);
    }
}
