<?php
declare(strict_types=1);
// CLI only. Never publish a default admin credential.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../www/config/database.php';

try {
    $pdo = db();
    $pdo->beginTransaction();
    $role = $pdo->query("SELECT id FROM roles WHERE name='Admin' FOR UPDATE")->fetchColumn();
    if (!$role) throw new RuntimeException('Import the fresh schema first.');
    $check = $pdo->prepare('SELECT COUNT(*) FROM users WHERE role_id=?');
    $check->execute([$role]);
    if ($check->fetchColumn()) throw new RuntimeException('An admin already exists. No data changed.');
    fwrite(STDOUT, 'Admin email: ');
    $email = strtolower(trim((string)fgets(STDIN)));
    fwrite(STDOUT, 'Full name: ');
    $name = trim((string)fgets(STDIN));
    $password = getenv('REDLINE_INITIAL_ADMIN_PASSWORD');
    if ($password === false) {
        fwrite(STDOUT, 'Password (input visible; use a private terminal): ');
        $password = rtrim((string)fgets(STDIN), "\r\n");
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email)>190 || $name==='' || strlen($name)>150 || strlen($password)<12 || strlen($password)>72) {
        throw new RuntimeException('Enter a valid email, name and password of 12–72 bytes.');
    }
    $stmt=$pdo->prepare('INSERT INTO users (role_id,full_name,email,password_hash,is_customer,is_active) VALUES (?,?,?,?,0,1)');
    $stmt->execute([$role,$name,$email,password_hash($password,PASSWORD_DEFAULT)]);
    $pdo->commit();
    fwrite(STDOUT, "Initial admin created. Sign in using the email provided.\n");
} catch(Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, $e instanceof PDOException ? "Database error; check configuration and duplicate email.\n" : $e->getMessage()."\n");
    exit(1);
}
