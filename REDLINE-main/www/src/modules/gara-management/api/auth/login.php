<?php
// api/auth/login.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/functions.php';

// Chỉ nhận phương thức POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse('error', 405, 'Phương thức không được hỗ trợ!', null, 'ERR_METHOD_NOT_ALLOWED');
}

$username = sanitizeInput($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if (empty($username) || empty($password)) {
    sendJsonResponse('error', 400, 'Vui lòng nhập tên đăng nhập và mật khẩu!', null, 'ERR_VALIDATION_FAILED');
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

if (!$user || !password_verify($password, $user['password'])) {
    sendJsonResponse('error', 401, 'Tên đăng nhập hoặc mật khẩu không chính xác!', null, 'ERR_UNAUTHORIZED');
}

if ($user['is_active'] == 0) {
    sendJsonResponse('error', 403, 'Tài khoản của bạn đã bị khóa!', null, 'ERR_FORBIDDEN');
}

// Lưu thông tin làm việc vào Session
$_SESSION['user_id']   = $user['id'];
$_SESSION['username']  = $user['username'];
$_SESSION['full_name'] = $user['full_name'];
$_SESSION['role']      = $user['role_name'];
$_SESSION['is_active'] = $user['is_active'];

sendJsonResponse('success', 200, 'Đăng nhập thành công!', [
    'user_id'   => $user['id'],
    'username'  => $user['username'],
    'full_name' => $user['full_name'],
    'role'      => $user['role_name']
]);