<?php
/**
 * Xử lý đăng xuất hệ thống
 * Vị trí tệp: www/logout.php
 */

require_once __DIR__ . '/src/includes/auth.php';

// BẮT BUỘC: Mở session để đọc CSRF và xóa dữ liệu đăng nhập
start_session();
session_start();
session_unset();
session_destroy();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    logout_user();
}

// Chuyển hướng về trang đăng nhập
header('Location: /login.php');
exit;