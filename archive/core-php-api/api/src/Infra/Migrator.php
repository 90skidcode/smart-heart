<?php

declare(strict_types=1);

namespace SmartHeart\Infra;

use PDO;
use RuntimeException;
use SmartHeart\Config;

/**
 * Applies db/migrations/NNNN_name.sql files in order and records each one in schema_migrations.
 * A file that was applied and then edited is an error: write a new migration instead.
 * MySQL cannot roll back DDL, so a migration that fails part-way must be repaired by hand
 * (the error names the statement that failed).
 */
final class Migrator
{
    /** @var callable(string): void */
    private $log;

    public function __construct(
        private readonly Config $config,
        private readonly string $directory,
        ?callable $log = null,
    ) {
        $this->log = $log ?? static function (string $line): void {
        };
    }

    /** Drops and recreates the database. Refused in production. */
    public function fresh(): void
    {
        if ($this->config->isProduction()) {
            throw new RuntimeException('Refusing to drop the database when APP_ENV=production');
        }
        $server = Database::connect($this->config, null);
        $server->exec('DROP DATABASE IF EXISTS `' . $this->config->dbName . '`');
        ($this->log)("Dropped {$this->config->dbName}");
    }

    /** @return list<string> versions applied by this run */
    public function migrate(): array
    {
        $server = Database::connect($this->config, null);
        $server->exec('CREATE DATABASE IF NOT EXISTS `' . $this->config->dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci');

        $db = Database::connect($this->config, $this->config->dbName);
        $db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            version    VARCHAR(190) NOT NULL PRIMARY KEY,
            checksum   CHAR(64) NOT NULL,
            applied_at DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3)
        ) ENGINE=InnoDB');
        $applied = $db->query('SELECT version, checksum FROM schema_migrations')->fetchAll(PDO::FETCH_KEY_PAIR);

        $ran = [];
        foreach ($this->files() as $version => $path) {
            $sql = (string) file_get_contents($path);
            $checksum = hash('sha256', $sql);
            if (isset($applied[$version])) {
                if (!hash_equals($applied[$version], $checksum)) {
                    throw new RuntimeException("Migration $version was changed after it was applied. Add a new migration instead.");
                }
                continue;
            }
            foreach (SqlScript::split($sql) as $n => $statement) {
                try {
                    $db->exec($statement);
                } catch (\PDOException $e) {
                    $preview = substr(preg_replace('/\s+/', ' ', $statement) ?? '', 0, 120);
                    throw new RuntimeException("Migration $version failed at statement " . ($n + 1) . " ($preview): " . $e->getMessage(), 0, $e);
                }
            }
            $db->prepare('INSERT INTO schema_migrations (version, checksum) VALUES (?, ?)')->execute([$version, $checksum]);
            ($this->log)("Applied $version");
            $ran[] = $version;
        }
        return $ran;
    }

    /** @return array<string, string> version => path, in order */
    private function files(): array
    {
        $files = [];
        foreach (glob(rtrim($this->directory, '/') . '/*.sql') ?: [] as $path) {
            $files[basename($path, '.sql')] = $path;
        }
        ksort($files, SORT_STRING);
        return $files;
    }
}
