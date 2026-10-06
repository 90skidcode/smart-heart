<?php

declare(strict_types=1);

// Case-number lifecycle against the real schema (MySQL 8).
// Usage: composer test:lifecycle (needs a freshly migrated DB_NAME; it inserts its own fixtures)
// Proves: issue → sign-in look-up → re-issue (old number dead, sessions revoked) → one active number per person
// → numbers never reused → caregiver numbers independent → withdrawal revokes everything.

require dirname(__DIR__, 2) . '/vendor/autoload.php';

use SmartHeart\Auth\CaseNumber as CN;
use SmartHeart\Config;
use SmartHeart\Infra\Database;

$config = Config::fromEnvironment(dirname(__DIR__, 2) . '/.env');
$pdo = Database::connect($config, $config->dbName);
$pepper = random_bytes(32);

$pass = 0;
$fail = 0;
function check(string $id, bool $ok, string $msg = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo ($ok ? 'ok   ' : 'FAIL ') . "$id $msg\n";
}
function dupError(callable $fn): bool
{
    try {
        $fn();
        return false;
    } catch (PDOException $e) {
        return ($e->errorInfo[1] ?? 0) === 1062; // ER_DUP_ENTRY
    }
}

// Fixtures
$pdo->exec("INSERT INTO sites (code, name, city) VALUES ('SRMC', 'Sri Ramachandra Medical Centre', 'Chennai')");
$site = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO users (site_id, email, full_name, password_hash) VALUES ($site, 'pi@example.in', 'Dr Bindu Charles', 'x')");
$staff = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO participants (site_id, study_id, screening_id, status, arm, registered_by) VALUES ($site, 'SMART-HEART-0048', 'SCR-0048', 'randomised', 'intervention', $staff)");
$pid = (int) $pdo->lastInsertId();
$pdo->exec("INSERT INTO caregivers (participant_id, relation) VALUES ($pid, 'daughter')");
$cgid = (int) $pdo->lastInsertId();

/** Issue (or re-issue) a case number for one person, the way CredentialService does it. */
function issue(PDO $pdo, string $pepper, int $pid, string $type, int $subject, int $staff, ?string $reason = null): array
{
    $number = CN::generate();
    $pdo->beginTransaction();
    $old = $pdo->prepare("SELECT id, version FROM app_credentials WHERE subject_type = ? AND subject_id = ? AND status = 'active' FOR UPDATE");
    $old->execute([$type, $subject]);
    $prev = $old->fetch();
    $version = 1;
    if ($prev) {
        $version = (int) $prev['version'] + 1;
        $pdo->prepare("UPDATE app_credentials SET status = 'replaced', ended_at = NOW(3), ended_by = ?, end_reason = 'replaced' WHERE id = ?")
            ->execute([$staff, $prev['id']]);
        $pdo->prepare("UPDATE auth_refresh_tokens SET revoked_at = NOW(3), revoke_reason = 'case_number_reissued' WHERE credential_id = ? AND revoked_at IS NULL")
            ->execute([$prev['id']]);
    }
    $pdo->prepare('INSERT INTO app_credentials (participant_id, subject_type, subject_id, case_no_hmac, hint, version, issued_by, issue_reason) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$pid, $type, $subject, CN::hmac($number, $pepper), CN::hint($number), $version, $staff, $reason]);
    $id = (int) $pdo->lastInsertId();
    $pdo->commit();
    return ['id' => $id, 'number' => $number, 'version' => $version];
}

/** Sign-in look-up: active numbers only. */
function lookup(PDO $pdo, string $pepper, string $typed): ?array
{
    if (!CN::isWellFormed($typed)) {
        return null;
    }
    $q = $pdo->prepare("SELECT id, subject_type, subject_id, participant_id FROM app_credentials WHERE case_no_hmac = ? AND status = 'active'");
    $q->execute([CN::hmac($typed, $pepper)]);
    return $q->fetch() ?: null;
}

// L1 · issue and sign in, typed loosely
$first = issue($pdo, $pepper, $pid, 'participant', $pid, $staff);
$typed = strtolower(str_replace('-', ' ', $first['number']));
$hit = lookup($pdo, $pepper, $typed);
check('L1', $hit !== null && (int) $hit['subject_id'] === $pid, "issued {$first['number']}, signed in as \"$typed\"");

// L2 · the database holds no plain number
$row = $pdo->query("SELECT * FROM app_credentials WHERE id = {$first['id']}")->fetch();
$dump = implode('|', array_map('strval', $row));
check('L2', !str_contains($dump, CN::normalise($first['number'])) && strlen($row['case_no_hmac']) === 32 && $row['hint'] === CN::hint($first['number']), 'only HMAC + hint stored');

// L3 · a session opened with it
$pdo->prepare("INSERT INTO auth_refresh_tokens (subject_type, subject_id, credential_id, token_hash, family_id, device_id, expires_at) VALUES ('participant', ?, ?, ?, UUID(), 'install-1', NOW(3) + INTERVAL 90 DAY)")
    ->execute([$pid, $first['id'], hash('sha256', random_bytes(16))]);

// L4 · a second active number for the same person is refused by the database
check('L4', dupError(fn() => $pdo->prepare("INSERT INTO app_credentials (participant_id, subject_type, subject_id, case_no_hmac, hint, issued_by) VALUES (?,?,?,?,?,?)")
    ->execute([$pid, 'participant', $pid, CN::hmac(CN::generate(), $pepper), 'AAA', $staff])), 'uq_cred_active');

// L5 · re-issue: old number dead, its session revoked, new number works, version 2
$second = issue($pdo, $pepper, $pid, 'participant', $pid, $staff, 'Card lost');
check('L5a', lookup($pdo, $pepper, $first['number']) === null, 'old number no longer signs in');
check('L5b', lookup($pdo, $pepper, $second['number']) !== null && $second['version'] === 2, "new number {$second['number']} signs in, version 2");
$open = (int) $pdo->query("SELECT COUNT(*) FROM auth_refresh_tokens WHERE credential_id = {$first['id']} AND revoked_at IS NULL")->fetchColumn();
check('L5c', $open === 0, 'sessions opened with the old number are revoked');

// L6 · a number is never reused, even after it was replaced
check('L6', dupError(fn() => $pdo->prepare("INSERT INTO app_credentials (participant_id, subject_type, subject_id, case_no_hmac, hint, status, issued_by) VALUES (?,?,?,?,?,'revoked',?)")
    ->execute([$pid, 'participant', $pid, CN::hmac($first['number'], $pepper), CN::hint($first['number']), $staff])), 'uq_cred_hmac');

// L7 · caregiver gets an independent number; both work side by side
$cg = issue($pdo, $pepper, $pid, 'caregiver', $cgid, $staff);
$cgHit = lookup($pdo, $pepper, $cg['number']);
check('L7', $cgHit !== null && $cgHit['subject_type'] === 'caregiver' && lookup($pdo, $pepper, $second['number'])['subject_type'] === 'participant', 'caregiver and participant numbers both active');

// L8 · a wrong check character never reaches the database; an unknown well-formed number finds nothing
$s = CN::normalise($second['number']);
$typo = substr($s, 0, 8) . ($s[8] === '2' ? '3' : '2');
check('L8a', lookup($pdo, $pepper, $typo) === null && !CN::isWellFormed($typo), 'typo caught by the check character');
check('L8b', lookup($pdo, $pepper, CN::generate()) === null, 'random valid-looking number finds nothing');

// L9 · withdrawal revokes every number for the case
$pdo->prepare("UPDATE app_credentials SET status = 'revoked', ended_at = NOW(3), ended_by = ?, end_reason = 'withdrawn' WHERE participant_id = ? AND status = 'active'")
    ->execute([$staff, $pid]);
check('L9', lookup($pdo, $pepper, $second['number']) === null && lookup($pdo, $pepper, $cg['number']) === null, 'withdrawn: participant and caregiver numbers both dead');

// L10 · after revocation a fresh number can be issued again (e.g. withdrawal reversed)
$third = issue($pdo, $pepper, $pid, 'participant', $pid, $staff, 'Withdrawal reversed by PI');
check('L10', lookup($pdo, $pepper, $third['number']) !== null, 'new number after revocation');

// L11 · throttle query: 5 failures from one install in 15 minutes → blocked
$ins = $pdo->prepare("INSERT INTO app_login_attempts (ip, install_id, succeeded, failure) VALUES (INET6_ATON('203.0.113.7'), 'install-x', 0, 'unknown')");
for ($i = 0; $i < 5; $i++) {
    $ins->execute();
}
$fails = (int) $pdo->query("SELECT COUNT(*) FROM app_login_attempts WHERE install_id = 'install-x' AND succeeded = 0 AND created_at > NOW(3) - INTERVAL 15 MINUTE")->fetchColumn();
check('L11', $fails >= 5, "$fails failures in 15 min → block");

echo "$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
