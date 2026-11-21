<?php
// api/auth/login.php

require_once __DIR__ . '/../../includes/api_functions.php';
require_once __DIR__ . '/../../includes/auth.php';
start_session();

// Chỉ nhận phương thức POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse('error', 405, 'Unsupported request method.', null, 'ERR_METHOD_NOT_ALLOWED');
}

$username = is_string($_POST['username'] ?? null) ? trim($_POST['username']) : '';
$password = is_string($_POST['password'] ?? null) ? $_POST['password'] : '';

if ($username === '' || strlen($username) > 190 || $password === '') {
    sendJsonResponse('error', 400, 'Enter your username and password.', null, 'ERR_VALIDATION_FAILED');
}

$pdo = getDBConnection();
$stmt = $pdo->prepare("
    SELECT u.*, r.name AS role_name 
    FROM users u 
    JOIN roles r ON u.role_id = r.id 
    WHERE u.username = ?
");
$stmt->execute([$username]);
$user = $stmt->fetch();

$attemptKey = strtolower(trim((string) ($user['email'] ?? ''))) ?: strtolower($username);
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
if (is_locked_out($attemptKey, $ip, false)) {
    header('Retry-After: ' . (LOCKOUT_MINUTES * 60));
    sendJsonResponse('error', 429, 'Too many failed login attempts. Please try again later.', null, 'ERR_RATE_LIMIT');
}

if (!$user
    || !in_array($user['role_name'], STAFF_PORTAL_ROLES, true)
    || !password_verify($password, (string) $user['password_hash'])) {
    record_failed_attempt($attemptKey, $ip);
    sendJsonResponse('error', 401, 'Incorrect username or password.', null, 'ERR_UNAUTHORIZED');
}

if ($user['is_active'] == 0) {
    record_failed_attempt($attemptKey, $ip);
    sendJsonResponse('error', 403, 'Your account has been locked.', null, 'ERR_FORBIDDEN');
}

// Lưu thông tin làm việc vào Session
clear_failed_attempts($attemptKey, $ip);
login_staff($user);

sendJsonResponse('success', 200, 'Signed in successfully.', [
    'user_id'   => $user['id'],
    'username'  => $user['username'],
    'full_name' => $user['full_name'],
    'role'      => $user['role_name'],
    'csrf'      => csrf_token()
]);
