<?php

declare(strict_types=1);

namespace SmartHeart\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SmartHeart\Auth\InvalidToken;
use SmartHeart\Auth\Jwt;
use SmartHeart\Infra\FrozenClock;

final class JwtTest extends TestCase
{
    private FrozenClock $clock;
    private Jwt $jwt;
    private string $key;

    protected function setUp(): void
    {
        $this->clock = new FrozenClock();
        $this->key = str_repeat('k', 32);
        $this->jwt = new Jwt(['k1' => $this->key], 'k1', $this->clock);
    }

    /** RFC 7515 appendix A.1: the published HS256 example signature. */
    public function testSignatureMatchesRfc7515Example(): void
    {
        $key = base64_decode(strtr('AyM1SysPpbyDfgZld3umj1qzKObwVMkoqQ-EstJQLr_T-1qS0gZH75aKtMN3Yj0iPS4hcgUuTwjAzZr1Z9CAow', '-_', '+/'));
        $input = 'eyJ0eXAiOiJKV1QiLA0KICJhbGciOiJIUzI1NiJ9'
            . '.eyJpc3MiOiJqb2UiLA0KICJleHAiOjEzMDA4MTkzODAsDQogImh0dHA6Ly9leGFtcGxlLmNvbS9pc19yb290Ijp0cnVlfQ';

        self::assertSame('dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk', Jwt::sign($input, $key));
    }

    public function testRoundTrip(): void
    {
        $claims = $this->jwt->verify($this->jwt->issue(['typ' => 'user', 'sub' => 12], 900), ['user']);

        self::assertSame(12, $claims['sub']);
        self::assertSame($this->clock->now()->getTimestamp() + 900, $claims['exp']);
    }

    public function testExpiresExactlyAtExp(): void
    {
        $token = $this->jwt->issue(['typ' => 'user'], 900);
        $this->clock->advance('+899 seconds');
        $this->jwt->verify($token, ['user']);
        $this->clock->advance('+1 second');
        $this->expectExceptionObject(new InvalidToken('expired'));
        $this->jwt->verify($token, ['user']);
    }

    public function testOneTokenTypeCannotStandInForAnother(): void
    {
        $this->expectExceptionObject(new InvalidToken('wrong token type'));
        $this->jwt->verify($this->jwt->issue(['typ' => 'mfa'], 300), ['user', 'participant']);
    }

    public function testPreviousKeyStillVerifiesAfterRotation(): void
    {
        $old = new Jwt(['k0' => str_repeat('o', 32)], 'k0', $this->clock);
        $rotated = new Jwt(['k1' => $this->key, 'k0' => str_repeat('o', 32)], 'k1', $this->clock);

        self::assertSame('user', $rotated->verify($old->issue(['typ' => 'user'], 60), ['user'])['typ']);
        $this->expectExceptionObject(new InvalidToken('bad header'));
        $this->jwt->verify($old->issue(['typ' => 'user'], 60), ['user']); // k0 retired
    }

    /** @return iterable<string, array{callable(Jwt, string): string}> */
    public static function forgeries(): iterable
    {
        $b64 = static fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $parts = static fn(string $t) => explode('.', $t);

        yield 'alg none, no signature' => [static function (Jwt $j, string $t) use ($b64, $parts) {
            [, $p] = $parts($t);
            return $b64('{"alg":"none","typ":"JWT","kid":"k1"}') . ".$p.";
        }];
        yield 'alg none with the old signature' => [static function (Jwt $j, string $t) use ($b64, $parts) {
            [, $p, $s] = $parts($t);
            return $b64('{"alg":"none","typ":"JWT","kid":"k1"}') . ".$p.$s";
        }];
        yield 'alg HS512' => [static function (Jwt $j, string $t) use ($b64, $parts) {
            [, $p, $s] = $parts($t);
            return $b64('{"alg":"HS512","typ":"JWT","kid":"k1"}') . ".$p.$s";
        }];
        yield 'lower-case alg' => [static function (Jwt $j, string $t) use ($b64, $parts) {
            [, $p, $s] = $parts($t);
            return $b64('{"alg":"hs256","typ":"JWT","kid":"k1"}') . ".$p.$s";
        }];
        yield 'unknown kid' => [static function (Jwt $j, string $t) use ($b64, $parts) {
            [, $p, $s] = $parts($t);
            return $b64('{"alg":"HS256","typ":"JWT","kid":"k9"}') . ".$p.$s";
        }];
        yield 'payload changed (sub 12 → 1)' => [static function (Jwt $j, string $t) use ($b64, $parts) {
            [$h, $p, $s] = $parts($t);
            $claims = json_decode(base64_decode(strtr($p, '-_', '+/')), true);
            $claims['sub'] = 1;
            return "$h." . $b64(json_encode($claims)) . ".$s";
        }];
        yield 'typ changed to admin-like value' => [static function (Jwt $j, string $t) use ($b64, $parts) {
            [$h, $p, $s] = $parts($t);
            $claims = json_decode(base64_decode(strtr($p, '-_', '+/')), true);
            $claims['perms'] = ['app_access.issue'];
            return "$h." . $b64(json_encode($claims)) . ".$s";
        }];
        yield 'signature from another key' => [static function (Jwt $j, string $t) use ($parts) {
            [$h, $p] = $parts($t);
            return "$h.$p." . Jwt::sign("$h.$p", str_repeat('x', 32));
        }];
        yield 'signature truncated' => [static fn(Jwt $j, string $t) => substr($t, 0, -2)];
        yield 'padding added' => [static fn(Jwt $j, string $t) => $t . '='];
        yield 'two parts' => [static fn(Jwt $j, string $t) => implode('.', array_slice($parts($t), 0, 2))];
        yield 'four parts' => [static fn(Jwt $j, string $t) => "$t.x"];
        yield 'empty' => [static fn() => ''];
        yield 'garbage' => [static fn() => 'not.a.token'];
    }

    #[DataProvider('forgeries')]
    public function testForgeriesAreRejected(callable $forge): void
    {
        $token = $this->jwt->issue(['typ' => 'user', 'sub' => 12, 'perms' => []], 900);
        $this->expectException(InvalidToken::class);
        $this->jwt->verify($forge($this->jwt, $token), ['user']);
    }

    public function testMissingExpIsRejected(): void
    {
        $b64 = static fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
        $input = $b64('{"alg":"HS256","typ":"JWT","kid":"k1"}') . '.' . $b64('{"typ":"user","sub":1}');
        $this->expectExceptionObject(new InvalidToken('expired'));
        $this->jwt->verify($input . '.' . Jwt::sign($input, $this->key), ['user']);
    }

    public function testShortKeysAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Jwt(['k1' => 'short'], 'k1', $this->clock);
    }
}
