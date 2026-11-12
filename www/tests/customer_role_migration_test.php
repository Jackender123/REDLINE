<?php
/** Customer-role migration checks. Writes only to a unique isolated database. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/database/migrations/20261112_customer_role.php';
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$database = 'redline_role_test_' . bin2hex(random_bytes(5));
$backups = [];
$checks = 0;
function role_verify(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    ++$checks;
}
function role_snapshot(PDO $pdo): array {
    return ['roles' => $pdo->query('SELECT * FROM roles ORDER BY id')->fetchAll(),
        'users' => $pdo->query('SELECT * FROM users ORDER BY id')->fetchAll()];
}
function role_backup_path(string $suffix): string {
    global $database, $backups;
    $path = sys_get_temp_dir() . '/' . $database . '-' . $suffix . '.sql';
    $backups[] = $path;
    return $path;
}
try {
    $pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$database`");
    $pdo->exec(<<<'SQL'
CREATE TABLE roles (id INT UNSIGNED PRIMARY KEY, name VARCHAR(50) NOT NULL UNIQUE, description VARCHAR(255)) ENGINE=InnoDB;
INSERT INTO roles VALUES (1,'Admin','Admin access'),(2,'Receptionist',NULL),(3,'Technician',NULL),(4,'Cashier','Payment access');
CREATE TABLE users (id INT UNSIGNED PRIMARY KEY, role_id INT UNSIGNED NULL, is_customer TINYINT NOT NULL DEFAULT 1, username VARCHAR(50), password_hash VARCHAR(255), updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, FOREIGN KEY (role_id) REFERENCES roles(id)) ENGINE=InnoDB;
INSERT INTO users VALUES (1,1,1,'admin','admin-hash','2020-01-01'),(2,4,0,'cashier','cashier-hash','2020-01-02'),(3,NULL,1,NULL,'customer-hash','2020-01-03'),(4,NULL,1,NULL,NULL,'2020-01-04'),(5,3,0,'tech','tech-hash','2020-01-05'),(6,4,1,'cashiercustomer','dual-hash','2020-01-06'),(7,NULL,0,NULL,NULL,'2020-01-07');
SQL);
    $before = role_snapshot($pdo);
    $backup = role_backup_path('old-cashier');
    $preview = rl_migrate_customer_role($pdo, $backup);
    role_verify($preview['preview'] && $preview['cashiers_to_move'] === 2 && $preview['customers_to_assign'] === 2, 'Preview reports old cashiers and NULL-role customers.');
    role_verify(role_snapshot($pdo) === $before && !is_file($backup), 'Preview leaves all rows unchanged and creates no backup.');
    $report = rl_migrate_customer_role($pdo, $backup, true);
    role_verify($report['applied'] && is_file($backup) && filesize($backup) > 500, 'Apply saves a full SQL backup before changing roles.');
    role_verify($pdo->query('SELECT name FROM roles WHERE id=4')->fetchColumn() === 'Customers' && $pdo->query('SELECT name FROM roles WHERE id=5')->fetchColumn() === 'Cashier', 'Role 4 becomes Customers and Cashier moves to role 5.');
    role_verify($pdo->query('SELECT description FROM roles WHERE id=5')->fetchColumn() === 'Payment access', 'The new Cashier role retains its old description.');
    $after = role_snapshot($pdo);
    foreach ($before['users'] as $index => $user) {
        $expected = $user;
        if ((int) $user['role_id'] === 4) $expected['role_id'] = 5;
        elseif ($user['role_id'] === null && (int) $user['is_customer'] === 1) $expected['role_id'] = 4;
        role_verify($after['users'][$index] == $expected, 'User role corrections preserve IDs, customer flags, credentials and timestamps.');
    }
    $rerunBackup = role_backup_path('rerun');
    role_verify(rl_migrate_customer_role($pdo, $rerunBackup, true)['already_migrated'] && role_snapshot($pdo) === $after && !is_file($rerunBackup), 'Rerunning is a no-op and creates no redundant backup.');

    // Restore the full backup to prove that pre-migration roles and identities can be recovered.
    $pdo->exec(file_get_contents($backup));
    role_verify(role_snapshot($pdo) === $before, 'The backup restores every original role, credential and timestamp.');
    $pdo->exec("UPDATE roles SET name='Customers' WHERE id=4");
    $customersBefore = role_snapshot($pdo);
    $customerBackup = role_backup_path('already-customers');
    $preview = rl_migrate_customer_role($pdo, $customerBackup);
    role_verify($preview['cashiers_to_move'] === 0 && $preview['customers_to_mark'] === 1 && $preview['create_cashier_role'], 'Existing Customers 4 accounts are marked as customers and a missing Cashier 5 is planned.');
    rl_migrate_customer_role($pdo, $customerBackup, true);
    $customersAfter = role_snapshot($pdo);
    foreach ($customersBefore['users'] as $index => $user) {
        $expected = $user;
        if ((int) $user['role_id'] === 4) $expected['is_customer'] = 1;
        elseif ($user['role_id'] === null && (int) $user['is_customer'] === 1) $expected['role_id'] = 4;
        role_verify($customersAfter['users'][$index] == $expected, 'Existing customer roles retain identity, credentials, timestamps and dual staff profiles.');
    }
    role_verify($pdo->query('SELECT name FROM roles WHERE id=5')->fetchColumn() === 'Cashier', 'Already-Customers installations gain the separate Cashier role.');
    role_verify(rl_migrate_customer_role($pdo)['already_migrated'], 'Already-Customers migration is rerunnable.');

    // Each conflict must fail before creating a backup or changing any source row.
    foreach (["INSERT INTO roles VALUES (6,'Customer',NULL)", "UPDATE roles SET name='Manager' WHERE id=5"] as $index => $conflict) {
        $pdo->beginTransaction();
        $pdo->exec($conflict);
        $conflicting = role_snapshot($pdo);
        $conflictBackup = role_backup_path('conflict-' . $index);
        $failed = false;
        try { rl_migrate_customer_role($pdo, $conflictBackup, true); }
        catch (RuntimeException $ex) { $failed = str_contains($ex->getMessage(), 'Conflicting'); }
        role_verify($failed && role_snapshot($pdo) === $conflicting && !is_file($conflictBackup), 'A conflicting customer role or occupied role 5 aborts before mutation or backup.');
        $pdo->rollBack();
    }
    // Force a mid-migration write failure after Cashier 5 has been inserted.
    $pdo->exec('DELETE FROM roles WHERE id=5');
    $pdo->exec('UPDATE users SET is_customer=0, updated_at=updated_at WHERE id=2');
    $pdo->exec("CREATE TRIGGER reject_role_change BEFORE UPDATE ON users FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Test role write failure'");
    $rollbackBefore = role_snapshot($pdo);
    $rollbackBackup = role_backup_path('rollback');
    $failed = false;
    try { rl_migrate_customer_role($pdo, $rollbackBackup, true); }
    catch (PDOException $ex) { $failed = str_contains($ex->getMessage(), 'Test role write failure'); }
    role_verify($failed && role_snapshot($pdo) === $rollbackBefore && !$pdo->inTransaction(), 'A write failure rolls back role creation and customer changes together.');
    role_verify(is_file($rollbackBackup), 'A failed apply retains its pre-migration SQL backup.');
    echo "PASS: $checks customer-role migration checks\n";
} finally {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $pdo->exec("DROP DATABASE IF EXISTS `$database`");
    foreach ($backups as $path) if (is_file($path)) unlink($path);
}
