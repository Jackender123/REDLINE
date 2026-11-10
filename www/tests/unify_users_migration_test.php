<?php
/** Migration regression test. All database writes use a uniquely named fixture. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/database/migrations/20261110_unify_users.php';
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$database = 'redline_users_test_' . bin2hex(random_bytes(5));
$backup = sys_get_temp_dir() . '/' . $database . '.sql';
$checks = 0;
function verify(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    ++$checks;
}
try {
    $pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$database`");
    $pdo->exec(<<<'SQL'
CREATE TABLE roles (id INT UNSIGNED PRIMARY KEY, name VARCHAR(50));
INSERT INTO roles VALUES (1,'Admin');
CREATE TABLE users (id INT UNSIGNED PRIMARY KEY, role_id INT UNSIGNED, username VARCHAR(50), password VARCHAR(255), full_name VARCHAR(150), email VARCHAR(190), phone VARCHAR(30), is_active TINYINT, created_at DATETIME, updated_at DATETIME, FOREIGN KEY (role_id) REFERENCES roles(id));
INSERT INTO users VALUES (1,1,'admin','staff-hash','Staff','shared@example.test','0901111111',1,'2020-01-01','2020-01-01');
CREATE TABLE portal_users (id INT UNSIGNED PRIMARY KEY, full_name VARCHAR(120), email VARCHAR(190), phone VARCHAR(30), password_hash VARCHAR(255), role VARCHAR(20), is_active TINYINT, created_at DATETIME, notify_offers TINYINT, zalo VARCHAR(30));
INSERT INTO portal_users VALUES (1,'Customer','customer@example.test','090 222 2222','customer-hash','customer',1,'2021-01-01',1,'0902222222'), (2,'Staff Profile','SHARED@example.test','0901111111','portal-staff-hash','customer',1,'2021-01-01',0,NULL), (3,'Old Portal Admin','portal-admin@example.test','0903333333','portal-admin-hash','admin',1,'2021-01-01',0,NULL);
CREATE TABLE customers (id INT UNSIGNED PRIMARY KEY, portal_user_id INT UNSIGNED, full_name VARCHAR(150), email VARCHAR(190), phone VARCHAR(30), address VARCHAR(255), notes TEXT, is_active TINYINT, created_at DATETIME);
INSERT INTO customers VALUES (1,1,'Workshop Customer',NULL,'0902222222','Workshop address','Keep these notes',1,'2020-01-01'), (2,NULL,'Walk In',NULL,'0904444444',NULL,NULL,1,'2022-01-01'), (3,NULL,'Staff Customer','shared@example.test','0901111111',NULL,NULL,1,'2020-01-01'), (4,NULL,'Unlinked Customer',NULL,'0903333333',NULL,NULL,1,'2021-01-01');
CREATE TABLE vehicles (id INT UNSIGNED PRIMARY KEY, customer_id INT UNSIGNED, updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, FOREIGN KEY (customer_id) REFERENCES customers(id));
INSERT INTO vehicles VALUES (1,1,'2020-01-01'),(2,2,'2020-01-01'),(3,3,'2020-01-01'),(4,4,'2020-01-01');
CREATE TABLE appointments (id INT UNSIGNED PRIMARY KEY, customer_id INT UNSIGNED, FOREIGN KEY (customer_id) REFERENCES customers(id));
INSERT INTO appointments VALUES (1,1);
CREATE TABLE portal_vehicles (id INT UNSIGNED PRIMARY KEY, user_id INT UNSIGNED, FOREIGN KEY (user_id) REFERENCES portal_users(id));
INSERT INTO portal_vehicles VALUES (1,1),(2,2),(3,3);
CREATE TABLE remember_tokens (id INT UNSIGNED PRIMARY KEY, user_id INT UNSIGNED, FOREIGN KEY (user_id) REFERENCES portal_users(id));
INSERT INTO remember_tokens VALUES (1,1);
CREATE TABLE repair_orders (id INT UNSIGNED PRIMARY KEY, customer_id INT, assigned_to INT UNSIGNED, created_by INT, FOREIGN KEY (assigned_to) REFERENCES users(id));
INSERT INTO repair_orders VALUES (1,1,1,1),(2,2,1,1);
CREATE TABLE service_requests (id INT UNSIGNED PRIMARY KEY, user_id INT UNSIGNED);
INSERT INTO service_requests VALUES (1,1),(2,2),(3,NULL);
SQL);
    $report = rl_unify_users($pdo);
    verify($report['unified_count'] === 4, 'Overlapping IDs, shared emails, linked profiles and normalized phones consolidate correctly.');
    verify($report['id_maps']['portal_users'][2] === 1, 'A shared email retains the staff primary key.');
    verify((int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn() === 4, 'Dry run leaves source data unchanged.');
    $pdo->exec("INSERT INTO customers VALUES (5,NULL,'Ambiguous','customer@example.test','0904444444',NULL,NULL,1,'2022-01-01')");
    try {
        rl_unify_users($pdo, $backup, true);
        throw new RuntimeException('Ambiguous identities should fail preflight.');
    } catch (RuntimeException $ex) {
        verify(str_contains($ex->getMessage(), 'Ambiguous'), 'Conflicting phone/email identities stop before any mutation.');
    }
    verify((int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn() === 5, 'Failed preflight leaves legacy records intact.');
    $pdo->exec('DELETE FROM customers WHERE id = 5');
    $pdo->exec('INSERT INTO service_requests VALUES (4,999)');
    try {
        rl_unify_users($pdo, $backup, true);
        throw new RuntimeException('Orphaned references should fail preflight.');
    } catch (RuntimeException $ex) {
        verify(str_contains($ex->getMessage(), 'Orphaned'), 'Orphaned IDs stop before any mutation.');
    }
    $pdo->exec('DELETE FROM service_requests WHERE id = 4');
    $report = rl_unify_users($pdo, $backup, true);
    verify(is_file($backup) && filesize($backup) > 1000 && is_file($backup . '.ids.json'), 'Migration saves SQL data and ID mappings before mutation.');
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    verify(!array_intersect(['customers', 'portal_users', 'users_before_unify', 'users_unified'], $tables), 'Only users remains as an identity table.');
    verify((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 4, 'Migration writes every distinct person exactly once.');
    $staff = $pdo->query('SELECT * FROM users WHERE id = 1')->fetch();
    verify($staff['username'] === 'admin' && $staff['password_hash'] === 'staff-hash' && (int) $staff['role_id'] === 1 && (int) $staff['is_customer'] === 1, 'Merged staff retain their staff credentials, role and customer membership.');
    $customerId = $report['id_maps']['portal_users'][1];
    $customer = $pdo->query("SELECT * FROM users WHERE id = $customerId")->fetch();
    verify($customer['password_hash'] === 'customer-hash' && $customer['address'] === 'Workshop address' && $customer['notes'] === 'Keep these notes', 'Customer credentials and workshop-only data survive.');
    verify((int) $customer['notify_offers'] === 1 && $customer['zalo'] === '0902222222', 'Customer notification and contact preferences survive.');
    verify($customer['created_at'] === '2020-01-01 00:00:00', 'Earliest creation date survives merging.');
    verify((int) $pdo->query('SELECT customer_id FROM vehicles WHERE id = 1')->fetchColumn() === $customerId, 'Workshop vehicle ownership is remapped.');
    verify($pdo->query('SELECT updated_at FROM vehicles WHERE id = 1')->fetchColumn() === '2020-01-01 00:00:00', 'Remapping ownership preserves modification dates.');
    verify((int) $pdo->query('SELECT customer_id FROM appointments WHERE id = 1')->fetchColumn() === $customerId, 'Legacy appointments use unified customer IDs.');
    verify((int) $pdo->query('SELECT user_id FROM portal_vehicles WHERE id = 1')->fetchColumn() === $customerId, 'Portal vehicle ownership is remapped.');
    verify((int) $pdo->query('SELECT user_id FROM portal_vehicles WHERE id = 2')->fetchColumn() === 1, 'Merged staff retain their portal vehicles.');
    verify((int) $pdo->query('SELECT customer_id FROM repair_orders WHERE id = 1')->fetchColumn() === $customerId, 'Unconstrained repair-order customer IDs are remapped.');
    verify((int) $pdo->query('SELECT user_id FROM service_requests WHERE id = 1')->fetchColumn() === $customerId, 'Unconstrained website bookings are remapped.');
    verify((int) $pdo->query('SELECT assigned_to FROM repair_orders WHERE id = 1')->fetchColumn() === 1 && (int) $pdo->query('SELECT created_by FROM repair_orders WHERE id = 1')->fetchColumn() === 1, 'Staff assignments and audit actors keep their original IDs.');
    verify($pdo->query("SELECT user_id FROM service_requests WHERE id = 3")->fetchColumn() === null, 'Guest bookings retain nullable ownership.');
    verify((int) $pdo->query('SELECT COUNT(*) FROM remember_tokens')->fetchColumn() === 0, 'Legacy remember cookies are invalidated.');
    verify($pdo->query("SELECT role_id FROM users WHERE email = 'portal-admin@example.test'")->fetchColumn() === null, 'A legacy portal role does not grant new staff privileges.');
    verify($pdo->query("SELECT password_hash FROM users WHERE phone = '0904444444'")->fetchColumn() === null, 'Walk-ins remain without login credentials.');
    verify((int) $pdo->query("SELECT COUNT(*) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IN ('customers','portal_users')")->fetchColumn() === 0, 'No foreign key references a removed table.');
    verify(rl_unify_users($pdo, $backup, true)['already_migrated'] === true, 'Running migration again is a no-op.');
    // Restore the backup into this isolated fixture and confirm original IDs and passwords.
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) $pdo->exec("DROP TABLE `$table`");
    $pdo->exec(file_get_contents($backup));
    verify((int) $pdo->query('SELECT COUNT(*) FROM customers')->fetchColumn() === 4 && (int) $pdo->query('SELECT COUNT(*) FROM portal_users')->fetchColumn() === 3, 'The backup restores both complete legacy tables.');
    verify($pdo->query('SELECT password FROM users WHERE id = 1')->fetchColumn() === 'staff-hash', 'The backup restores original staff credentials.');
    echo "PASS: $checks migration checks\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS `$database`");
    foreach ([$backup, $backup . '.ids.json'] as $path) if (is_file($path)) unlink($path);
}
