<?php
// api/auth/me.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

// Kích hoạt kiểm tra phiên làm việc
requireAuth();

sendJsonResponse('success', 200, 'Lấy thông tin người dùng thành công!', [
    'user_id'   => $_SESSION['user_id'],
    'username'  => $_SESSION['username'],
    'full_name' => $_SESSION['full_name'],
    'role'      => $_SESSION['role']
]);