<?php
require_once __DIR__ . '/includes/auth.php';

// Chỉ đăng xuất khi gửi POST kèm CSRF token (tránh bị ép đăng xuất bằng link/ảnh)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_valid($_POST['csrf'] ?? null)) {
    logout_user();
}

header('Location: login.php');
exit;
