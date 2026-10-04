<?php

declare(strict_types=1);

// Applies db/migrations to DB_NAME. Usage: php bin/migrate.php [--fresh]
// --fresh drops the database first (refused when APP_ENV=production).

use SmartHeart\Config;
use SmartHeart\Infra\Migrator;

require dirname(__DIR__) . '/vendor/autoload.php';

$config = Config::fromEnvironment(dirname(__DIR__) . '/.env');
$migrator = new Migrator($config, dirname(__DIR__, 3) . '/db/migrations', static fn(string $line) => print "$line\n");

try {
    if (in_array('--fresh', $argv, true)) {
        $migrator->fresh();
    }
    $ran = $migrator->migrate();
    echo $ran === [] ? "{$config->dbName} is up to date\n" : count($ran) . " migration(s) applied to {$config->dbName}\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
