<?php

/**
 * Helper xác thực cho Redline customer portal.
 */

require_once __DIR__ . '/db.php';

$user = current_user(); // Lấy thông tin user nếu đã đăng nhập, ngược lại trả về null
if (!defined('REMEMBER_COOKIE')) {
    define('REMEMBER_COOKIE', 'redline_remember');   //nhớ đăng nhập
}

if (!defined('LOCKOUT_MINUTES')) {
    define('LOCKOUT_MINUTES', 15);      //chặn kẻ trộm cố tình thử chìa khóa liên tục
}

const MAX_FAILED_ATTEMPTS = 5;     // số lần sai tối đa
const REMEMBER_DAYS       = 30;    // "Keep me signed in"

/* ---------- Session ---------- */

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/* ---------- Tiện ích ---------- */

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function client_ip(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/* ---------- CSRF ---------- */

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_valid(?string $token): bool
{
    start_session();
    return is_string($token) && !empty($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
}

/* ---------- Chống brute-force ---------- */

function is_locked_out(string $email, string $ip): bool
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE (email = :email OR ip_address = :ip)
           AND attempted_at > (NOW() - INTERVAL ' . LOCKOUT_MINUTES . ' MINUTE)'
    );
    $stmt->execute(['email' => $email, 'ip' => $ip]);
    return (int) $stmt->fetchColumn() >= MAX_FAILED_ATTEMPTS;
}

function record_failed_attempt(string $email, string $ip): void
{
    db()->prepare('INSERT INTO login_attempts (email, ip_address) VALUES (:email, :ip)')
        ->execute(['email' => $email, 'ip' => $ip]);
}

function clear_failed_attempts(string $email, string $ip): void
{
    db()->prepare('DELETE FROM login_attempts WHERE email = :email OR ip_address = :ip')
        ->execute(['email' => $email, 'ip' => $ip]);
}

/* ---------- Đăng nhập / đăng xuất ---------- */

function login_user(array $user): void
{
    start_session();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];

    db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')
        ->execute(['id' => $user['id']]);
}

function current_user(): ?array
{
    start_session();

    if (!empty($_SESSION['user_id'])) {
        $stmt = db()->prepare('SELECT id, full_name, email, role FROM users WHERE id = :id AND is_active = 1');
        $stmt->execute(['id' => $_SESSION['user_id']]);
        $user = $stmt->fetch();
        if ($user) {
            return $user;
        }
        unset($_SESSION['user_id']);
    }

    return restore_from_remember_cookie();
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        header('Location: login.php');
        exit;
    }
    return $user;
}

function logout_user(): void
{
    start_session();
    forget_remember_token();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/* ---------- Remember me (selector + validator) ---------- */

function set_remember_cookie(string $value, int $expires): void
{
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    setcookie(REMEMBER_COOKIE, $value, [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function create_remember_token(int $userId): void
{
    $selector  = bin2hex(random_bytes(12));   // 24 ký tự
    $validator = bin2hex(random_bytes(32));
    $expires   = time() + REMEMBER_DAYS * 86400;

    db()->prepare(
        'INSERT INTO remember_tokens (user_id, selector, validator_hash, expires_at)
         VALUES (:uid, :sel, :val, :exp)'
    )->execute([
        'uid' => $userId,
        'sel' => $selector,
        'val' => hash('sha256', $validator),
        'exp' => date('Y-m-d H:i:s', $expires),
    ]);

    set_remember_cookie($selector . ':' . $validator, $expires);
}

function restore_from_remember_cookie(): ?array
{
    $cookie = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if (substr_count($cookie, ':') !== 1) {
        return null;
    }
    [$selector, $validator] = explode(':', $cookie);

    $stmt = db()->prepare(
        'SELECT t.id AS token_id, t.validator_hash, u.id, u.full_name, u.email, u.role
         FROM remember_tokens t
         JOIN users u ON u.id = t.user_id
         WHERE t.selector = :sel AND t.expires_at > NOW() AND u.is_active = 1'
    );
    $stmt->execute(['sel' => $selector]);
    $row = $stmt->fetch();

    if (!$row || !hash_equals($row['validator_hash'], hash('sha256', $validator))) {
        set_remember_cookie('', time() - 3600);
        return null;
    }

    // Xoay token: xóa cái cũ, cấp cái mới
    db()->prepare('DELETE FROM remember_tokens WHERE id = :id')->execute(['id' => $row['token_id']]);
    login_user($row);
    create_remember_token((int) $row['id']);

    return ['id' => $row['id'], 'full_name' => $row['full_name'], 'email' => $row['email'], 'role' => $row['role']];
}

function forget_remember_token(): void
{
    $cookie = $_COOKIE[REMEMBER_COOKIE] ?? '';
    if (strpos($cookie, ':') !== false) {
        [$selector] = explode(':', $cookie, 2);
        db()->prepare('DELETE FROM remember_tokens WHERE selector = :sel')->execute(['sel' => $selector]);
    }
    set_remember_cookie('', time() - 3600);
}
