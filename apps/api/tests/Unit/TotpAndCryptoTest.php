<?php

declare(strict_types=1);

namespace SmartHeart\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use SmartHeart\Auth\Totp;
use SmartHeart\Infra\AuditLog;
use SmartHeart\Infra\Crypto;
use SmartHeart\Infra\Secrets;

final class TotpAndCryptoTest extends TestCase
{
    /** RFC 6238 appendix B (SHA-1 secret "12345678901234567890"), 8-digit values. */
    public function testRfc6238Vectors(): void
    {
        $secret = '12345678901234567890';
        $expected = [59 => '94287082', 1111111109 => '07081804', 1111111111 => '14050471', 1234567890 => '89005924', 2000000000 => '69279037', 20000000000 => '65353130'];
        foreach ($expected as $time => $code) {
            self::assertSame($code, Totp::code($secret, Totp::step($time), 8), "T=$time");
        }
        self::assertSame('287082', Totp::code($secret, Totp::step(59))); // 6 digits = last 6
    }

    public function testVerifyAllowsOneStepOfDriftAndNoReplay(): void
    {
        $secret = Totp::newSecret();
        $t = 1_790_000_000;
        $step = Totp::step($t);

        self::assertSame($step - 1, Totp::verify($secret, Totp::code($secret, $step - 1), $t, null));
        self::assertSame($step + 1, Totp::verify($secret, Totp::code($secret, $step + 1), $t, null));
        self::assertNull(Totp::verify($secret, Totp::code($secret, $step - 2), $t, null));
        self::assertNull(Totp::verify($secret, Totp::code($secret, $step), $t, $step), 'already used');
        self::assertNull(Totp::verify($secret, '12345', $t, null));
        self::assertNull(Totp::verify($secret, '12345a', $t, null));
    }

    public function testBase32AndUri(): void
    {
        self::assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', Totp::base32('12345678901234567890'));
        self::assertSame(
            'otpauth://totp/SMART-HEART:pi%40example.in?secret=GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ&issuer=SMART-HEART&algorithm=SHA1&digits=6&period=30',
            Totp::uri('12345678901234567890', 'pi@example.in'),
        );
    }

    public function testEncryptionRoundTripAndContextBinding(): void
    {
        $crypto = new Crypto(Secrets::random());
        $blob = $crypto->encrypt('secret value', 'users.mfa_secret:12');

        self::assertSame('secret value', $crypto->decrypt($blob, 'users.mfa_secret:12'));
        self::assertNotSame($blob, $crypto->encrypt('secret value', 'users.mfa_secret:12'), 'fresh nonce each time');

        $this->expectException(RuntimeException::class);
        $crypto->decrypt($blob, 'users.mfa_secret:13'); // copied to another user's row
    }

    public function testTamperedCiphertextIsRejected(): void
    {
        $crypto = new Crypto(Secrets::random());
        $blob = $crypto->encrypt('secret value', 'ctx');
        $blob[strlen($blob) - 1] = chr(ord($blob[strlen($blob) - 1]) ^ 1);

        $this->expectException(RuntimeException::class);
        $crypto->decrypt($blob, 'ctx');
    }

    public function testOtherKeyCannotDecrypt(): void
    {
        $blob = (new Crypto(Secrets::random()))->encrypt('secret value', 'ctx');
        $this->expectException(RuntimeException::class);
        (new Crypto(Secrets::random()))->decrypt($blob, 'ctx');
    }

    public function testCanonicalJsonIsKeyOrderIndependent(): void
    {
        self::assertSame(
            AuditLog::canonical(['b' => 1, 'a' => ['y' => 2, 'x' => [3, 1]]]),
            AuditLog::canonical(['a' => ['x' => [3, 1], 'y' => 2], 'b' => 1]),
        );
        self::assertSame('{"a":{"x":[3,1],"y":2},"b":1}', AuditLog::canonical(['b' => 1, 'a' => ['y' => 2, 'x' => [3, 1]]]));
    }
}
