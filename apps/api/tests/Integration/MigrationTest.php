<?php

declare(strict_types=1);

namespace SmartHeart\Tests\Integration;

use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use SmartHeart\App;
use SmartHeart\Config;
use SmartHeart\Http\Request;
use SmartHeart\Infra\Database;
use SmartHeart\Infra\Migrator;
use SmartHeart\Infra\Secrets;

/** Runs against a real MySQL. Only touches databases whose name ends in _test. */
final class MigrationTest extends TestCase
{
    private static Config $config;
    private static PDO $db;

    public static function setUpBeforeClass(): void
    {
        self::$config = Config::fromEnvironment(dirname(__DIR__, 2) . '/.env');
        if (!str_ends_with(self::$config->dbName, '_test')) {
            self::fail('Integration tests drop the database. Set DB_NAME to a *_test database (composer test:db does this).');
        }
        $migrator = self::migrator();
        $migrator->fresh();
        $migrator->migrate();
        self::$db = Database::connect(self::$config, self::$config->dbName);
    }

    private static function migrator(): Migrator
    {
        return new Migrator(self::$config, dirname(__DIR__, 4) . '/db/migrations');
    }

    public function testBaselineCreatesEveryTableViewAndTrigger(): void
    {
        $counts = self::$db->query("SELECT table_type, COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE() GROUP BY table_type")->fetchAll(PDO::FETCH_KEY_PAIR);
        $triggers = self::$db->query('SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE()')->fetchColumn();

        self::assertSame(['BASE TABLE' => 68, 'VIEW' => 2], $counts); // 65 baseline + 2 from 0002 + schema_migrations
        self::assertSame(2, (int) $triggers);
    }

    public function testSecondRunAppliesNothing(): void
    {
        self::assertSame([], self::migrator()->migrate());
    }

    public function testEditedMigrationIsRejected(): void
    {
        self::$db->exec("UPDATE schema_migrations SET checksum = REPEAT('0', 64) WHERE version = '0001_baseline'");
        try {
            self::migrator()->migrate();
            self::fail('Expected the changed checksum to be rejected');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('0001_baseline was changed', $e->getMessage());
        } finally {
            $sql = (string) file_get_contents(dirname(__DIR__, 4) . '/db/migrations/0001_baseline.sql');
            self::$db->prepare("UPDATE schema_migrations SET checksum = ? WHERE version = '0001_baseline'")->execute([hash('sha256', $sql)]);
        }
    }

    public function testAuditLogIsAppendOnly(): void
    {
        self::$db->exec("INSERT INTO audit_log (actor_type, action, entity_table, row_hash, prev_hash)
            VALUES ('system', 'create', 'none', REPEAT('a', 64), REPEAT('0', 64))");
        $this->expectException(PDOException::class);
        $this->expectExceptionMessage('audit_log is append-only');
        self::$db->exec("UPDATE audit_log SET action = 'delete'");
    }

    public function testSessionsRunInUtc(): void
    {
        self::assertSame('+00:00', self::$db->query('SELECT @@session.time_zone')->fetchColumn());
    }

    public function testHealthIsOkAgainstTheRealDatabase(): void
    {
        $response = App::kernel(self::$config, Secrets::random())->handle(new Request('GET', '/health'));

        self::assertSame(200, $response->status);
        self::assertSame(['status' => 'ok', 'db' => 'ok', 'version' => self::$config->version], $response->body);
    }
}
