<?php
/**
 * Xử lý đăng xuất hệ thống
 * Vị trí tệp: www/logout.php
 */

require_once __DIR__ . '/src/includes/auth.php';

// Khởi động phiên làm việc nếu chưa mở
if (function_exists('start_session')) {
    start_session();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Xóa dữ liệu tài khoản nếu hàm logout_user() tồn tại trong auth.php
if (function_exists('logout_user')) {
    logout_user();
}

// Xóa hoàn toàn Session & Cookie
$_SESSION = array();
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params["path"],
        $params["domain"],
        $params["secure"],
        $params["httponly"]
    );
}
session_destroy();

// Chuyển hướng tương đối về trang đăng nhập login.php
header('Location: login.php?logged_out=1');
exit;