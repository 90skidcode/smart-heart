<?php

declare(strict_types=1);

namespace SmartHeart\Infra;

use PDO;
use Pdo\Mysql;
use SmartHeart\Config;

/**
 * One lazily opened MySQL connection per request. Sessions run in UTC (every timestamp is
 * stored in UTC; IST calendar dates are derived in PHP), with strict SQL mode and real prepares.
 */
final class Database
{
    private ?PDO $pdo = null;

    public function __construct(private readonly Config $config)
    {
    }

    public function pdo(): PDO
    {
        return $this->pdo ??= self::connect($this->config, $this->config->dbName);
    }

    /** A connection to the named database, or to the server with no database selected when $dbName is null. */
    public static function connect(Config $config, ?string $dbName): PDO
    {
        $target = $config->dbSocket !== null
            ? 'unix_socket=' . $config->dbSocket
            : 'host=' . $config->dbHost . ';port=' . $config->dbPort;
        $dsn = 'mysql:' . $target . ($dbName !== null ? ';dbname=' . $dbName : '') . ';charset=utf8mb4';

        return PDO::connect($dsn, $config->dbUser, $config->dbPassword, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            Mysql::ATTR_INIT_COMMAND => "SET time_zone = '+00:00', sql_mode = 'STRICT_ALL_TABLES,ONLY_FULL_GROUP_BY,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'",
        ]);
    }
}
