<?php

declare(strict_types=1);

// Creates a staff user (and the site, if new). For bootstrapping until Admin › Users exists.
//
//   php bin/create-user.php --email=pi@example.in --name="Dr Bindu Charles" --site=SRMC \
//       --site-name="Sri Ramachandra Medical Centre" --roles=pi
//
// The password is read from the terminal (not echoed), or from stdin when piped. At least 12 characters.

use SmartHeart\Config;
use SmartHeart\Infra\AuditLog;
use SmartHeart\Infra\Clock;
use SmartHeart\Infra\Database;
use SmartHeart\Infra\Tx;

require dirname(__DIR__) . '/vendor/autoload.php';

$opts = getopt('', ['email:', 'name:', 'site:', 'site-name::', 'roles:']);
foreach (['email', 'name', 'site', 'roles'] as $required) {
    if (!isset($opts[$required])) {
        fwrite(STDERR, "Missing --$required. See the comment at the top of this file.\n");
        exit(1);
    }
}
if (!filter_var($opts['email'], FILTER_VALIDATE_EMAIL)) {
    fwrite(STDERR, "Not an email address: {$opts['email']}\n");
    exit(1);
}

$interactive = function_exists('posix_isatty') ? posix_isatty(STDIN) : true;
if ($interactive) {
    echo 'Password (min 12 characters): ';
    shell_exec('stty -echo');
}
$password = rtrim((string) fgets(STDIN), "\r\n");
if ($interactive) {
    shell_exec('stty echo');
    echo "\n";
}
if (mb_strlen($password) < 12) {
    fwrite(STDERR, "Password must be at least 12 characters.\n");
    exit(1);
}

$config = Config::fromEnvironment(dirname(__DIR__) . '/.env');
$db = Database::connect($config, $config->dbName);
$audit = new AuditLog($db, new Clock());

try {
    $userId = Tx::run($db, function () use ($db, $opts, $password, $audit) {
        $site = $db->prepare('SELECT id FROM sites WHERE code = ?');
        $site->execute([$opts['site']]);
        $siteId = $site->fetchColumn();
        if ($siteId === false) {
            if (!isset($opts['site-name'])) {
                throw new RuntimeException("Site {$opts['site']} does not exist; add --site-name to create it");
            }
            $db->prepare('INSERT INTO sites (code, name) VALUES (?, ?)')->execute([$opts['site'], $opts['site-name']]);
            $siteId = $db->lastInsertId();
        }

        $roleCodes = array_map('trim', explode(',', $opts['roles']));
        $in = implode(',', array_fill(0, count($roleCodes), '?'));
        $roles = $db->prepare("SELECT code, id FROM roles WHERE code IN ($in)");
        $roles->execute($roleCodes);
        $roles = $roles->fetchAll(PDO::FETCH_KEY_PAIR);
        if ($unknown = array_diff($roleCodes, array_keys($roles))) {
            throw new RuntimeException('Unknown role(s): ' . implode(', ', $unknown));
        }

        $db->prepare('INSERT INTO users (site_id, email, full_name, password_hash, password_changed_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$siteId, $opts['email'], $opts['name'], password_hash($password, PASSWORD_ARGON2ID), Clock::sql((new Clock())->now())]);
        $userId = (int) $db->lastInsertId();
        $grant = $db->prepare('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)');
        foreach ($roles as $roleId) {
            $grant->execute([$userId, $roleId]);
        }
        $audit->record(['type' => 'system', 'id' => null], 'create', 'users', $userId,
            newValues: ['email' => $opts['email'], 'roles' => array_values($roleCodes)], reason: 'bin/create-user.php');
        return $userId;
    });
    echo "Created user $userId ({$opts['email']}). They should enrol MFA after first sign-in.\n";
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}
