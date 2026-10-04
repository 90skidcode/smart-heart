<?php

declare(strict_types=1);

namespace SmartHeart;

use PDO;
use SmartHeart\AppAccess\CaseNumberController;
use SmartHeart\Auth\AppAuthController;
use SmartHeart\Auth\Authenticator;
use SmartHeart\Auth\Jwt;
use SmartHeart\Auth\SessionController;
use SmartHeart\Auth\StaffAuthController;
use SmartHeart\Auth\TokenService;
use SmartHeart\Http\Kernel;
use SmartHeart\Http\Request;
use SmartHeart\Http\Response;
use SmartHeart\Http\Router;
use SmartHeart\Infra\AuditLog;
use SmartHeart\Infra\Clock;
use SmartHeart\Infra\Crypto;
use SmartHeart\Infra\Database;
use SmartHeart\Infra\Secrets;
use SmartHeart\System\HealthController;

/**
 * Wires services and routes. Paths are relative to the /api/v1 base path. Services are built on
 * first use, so a request that never touches the database never opens a connection.
 */
final class App
{
    public const BASE_PATH = '/api/v1';

    /** @var array<string, object> */
    private array $services = [];

    /** @var callable(string): void */
    private $logError;

    private function __construct(
        private readonly Config $config,
        private readonly Secrets $secrets,
        private readonly Clock $clock,
        ?callable $logError,
    ) {
        $this->logError = $logError ?? static fn(string $line) => error_log($line);
    }

    public static function kernel(Config $config, Secrets $secrets, ?Clock $clock = null, ?callable $logError = null): Kernel
    {
        return (new self($config, $secrets, $clock ?? new Clock(), $logError))->build();
    }

    private function build(): Kernel
    {
        $r = new Router();
        $r->add('GET', '/health', fn(Request $q) => (new HealthController($this->database(), $this->config->version))($q));

        // Auth · Staff
        $r->add('POST', '/auth/login', fn(Request $q) => $this->staffAuth()->login($q));
        $r->add('POST', '/auth/mfa/verify', fn(Request $q) => $this->staffAuth()->verifyMfa($q));
        $r->add('POST', '/auth/mfa/enroll', fn(Request $q) => $this->staffAuth()->enrollMfa($q));
        $r->add('POST', '/auth/mfa/confirm', fn(Request $q) => $this->staffAuth()->confirmMfa($q));
        $r->add('GET', '/auth/me', fn(Request $q) => $this->staffAuth()->me($q));
        $r->add('POST', '/auth/reauth', fn(Request $q) => $this->staffAuth()->reauth($q));
        $r->add('POST', '/auth/refresh', fn(Request $q) => $this->sessions()->refresh($q));
        $r->add('POST', '/auth/logout', fn(Request $q) => $this->sessions()->logout($q));

        // Auth · Participant
        $r->add('POST', '/app/auth/login', fn(Request $q) => $this->appAuth()->login($q));

        // App Access
        $base = '/participants/{participantId}/case-numbers';
        $r->add('GET', $base, fn(Request $q, array $p) => $this->caseNumbers()->list($q, $p));
        $r->add('POST', $base, fn(Request $q, array $p) => $this->caseNumbers()->issue($q, $p));
        $r->add('POST', "$base/{credentialId}/revoke", fn(Request $q, array $p) => $this->caseNumbers()->revoke($q, $p));
        $r->add('POST', "$base/{credentialId}/sign-out", fn(Request $q, array $p) => $this->caseNumbers()->signOut($q, $p));

        return new Kernel($r, $this->config->problemBaseUri, $this->logError);
    }

    // ── services (one each per request) ──

    private function database(): Database
    {
        return $this->services[Database::class] ??= new Database($this->config);
    }

    private function pdo(): PDO
    {
        return $this->database()->pdo();
    }

    private function audit(): AuditLog
    {
        return $this->services[AuditLog::class] ??= new AuditLog($this->pdo(), $this->clock);
    }

    private function jwt(): Jwt
    {
        return $this->services[Jwt::class] ??= new Jwt($this->secrets->jwtKeys, $this->secrets->jwtCurrentKid, $this->clock);
    }

    private function tokens(): TokenService
    {
        return $this->services[TokenService::class] ??= new TokenService($this->pdo(), $this->jwt(), $this->clock, $this->audit());
    }

    private function authenticator(): Authenticator
    {
        return $this->services[Authenticator::class] ??= new Authenticator($this->jwt(), $this->tokens(), $this->pdo());
    }

    private function staffAuth(): StaffAuthController
    {
        return new StaffAuthController($this->pdo(), $this->jwt(), $this->tokens(), $this->authenticator(),
            new Crypto($this->secrets), $this->audit(), $this->clock);
    }

    private function sessions(): SessionController
    {
        return new SessionController($this->tokens(), $this->authenticator(), $this->audit());
    }

    private function appAuth(): AppAuthController
    {
        return new AppAuthController($this->pdo(), $this->tokens(), $this->secrets, $this->audit(), $this->clock, $this->logError);
    }

    private function caseNumbers(): CaseNumberController
    {
        return new CaseNumberController($this->pdo(), $this->authenticator(), $this->tokens(), $this->secrets,
            $this->audit(), $this->clock, $this->config);
    }
}
