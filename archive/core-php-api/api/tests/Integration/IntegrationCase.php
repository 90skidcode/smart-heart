<?php

declare(strict_types=1);

namespace SmartHeart\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use SmartHeart\App;
use SmartHeart\Config;
use SmartHeart\Http\Kernel;
use SmartHeart\Http\Request;
use SmartHeart\Http\Response;
use SmartHeart\Infra\Database;
use SmartHeart\Infra\FrozenClock;
use SmartHeart\Infra\Migrator;
use SmartHeart\Infra\Secrets;

/**
 * A freshly migrated *_test database per test class, a frozen clock, and HTTP calls through the real
 * kernel. Never runs against a database whose name does not end in _test.
 */
abstract class IntegrationCase extends TestCase
{
    protected static Config $config;
    protected static Secrets $secrets;
    protected static PDO $db;
    protected FrozenClock $clock;
    protected Kernel $kernel;
    /** @var list<string> */
    protected array $logged = [];

    public static function setUpBeforeClass(): void
    {
        self::$config = Config::fromEnvironment(dirname(__DIR__, 2) . '/.env');
        if (!str_ends_with(self::$config->dbName, '_test')) {
            self::fail('Integration tests drop the database. Set DB_NAME to a *_test database (composer test:db does this).');
        }
        $migrator = new Migrator(self::$config, dirname(__DIR__, 4) . '/db/migrations');
        $migrator->fresh();
        $migrator->migrate();
        self::$db = Database::connect(self::$config, self::$config->dbName);
        self::$secrets = Secrets::random();
    }

    protected function setUp(): void
    {
        $this->clock = new FrozenClock('2026-10-03 04:30:00'); // 10:00 IST
        $this->kernel = App::kernel(self::$config, self::$secrets, $this->clock, function (string $line) {
            $this->logged[] = $line;
        });
    }

    /** @param array<string, mixed>|null $body */
    protected function call(string $method, string $path, ?array $body = null, ?string $token = null, string $ip = '10.0.0.1'): Response
    {
        $headers = ['user-agent' => 'phpunit'];
        if ($body !== null) {
            $headers['content-type'] = 'application/json';
        }
        if ($token !== null) {
            $headers['authorization'] = "Bearer $token";
        }
        $response = $this->kernel->handle(new Request($method, $path, [], $headers, $body === null ? '' : json_encode($body), $ip));
        if ($response->status === 500) {
            self::fail('500: ' . implode("\n", $this->logged));
        }
        return $response;
    }

    protected static function insert(string $table, array $row): int
    {
        $cols = implode(', ', array_keys($row));
        $marks = implode(', ', array_fill(0, count($row), '?'));
        self::$db->prepare("INSERT INTO $table ($cols) VALUES ($marks)")->execute(array_values($row));
        return (int) self::$db->lastInsertId();
    }

    protected static function scalar(string $sql, array $params = []): mixed
    {
        $stmt = self::$db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn();
    }

    protected static function site(string $code): int
    {
        return (int) (self::scalar('SELECT id FROM sites WHERE code = ?', [$code]) ?: self::insert('sites', ['code' => $code, 'name' => "$code hospital"]));
    }

    /** A staff user with the given roles; password "correct horse battery". */
    protected static function user(string $email, array $roles, string $site = 'SRMC'): int
    {
        static $hash = null;
        $hash ??= password_hash('correct horse battery', PASSWORD_ARGON2ID);
        $id = self::insert('users', ['site_id' => self::site($site), 'email' => $email, 'full_name' => ucfirst(strtok($email, '@')), 'password_hash' => $hash]);
        foreach ($roles as $role) {
            self::$db->prepare('INSERT INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE code = ?')->execute([$id, $role]);
        }
        return $id;
    }

    protected static function participant(string $studyId, ?string $arm = 'intervention', string $status = 'randomised', string $site = 'SRMC', string $language = 'en'): int
    {
        $by = (int) (self::scalar('SELECT id FROM users ORDER BY id LIMIT 1') ?: self::user('registrar@example.in', ['data_manager']));
        return self::insert('participants', [
            'site_id' => self::site($site), 'study_id' => $studyId, 'screening_id' => 'S' . substr(md5($studyId), 0, 8),
            'status' => $status, 'arm' => $arm, 'registered_by' => $by, 'preferred_language' => $language,
        ]);
    }

    /** Signs a staff user in (no MFA) and returns the token pair. */
    protected function staffLogin(string $email): array
    {
        $r = $this->call('POST', '/auth/login', ['email' => $email, 'password' => 'correct horse battery']);
        self::assertSame(200, $r->status, json_encode($r->body));
        return $r->body;
    }

    protected function appLogin(string $caseNumber, string $installId, string $ip = '10.1.0.1'): Response
    {
        return $this->call('POST', '/app/auth/login', [
            'case_number' => $caseNumber, 'install_id' => $installId, 'platform' => 'android',
            'os_version' => '14', 'device_model' => 'Redmi Note 12', 'app_version' => '1.0.0',
        ], ip: $ip);
    }

    protected static function installId(): string
    {
        return \SmartHeart\Infra\Uuid::v4();
    }
}
