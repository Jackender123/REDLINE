<?php
// api/customers/list.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJsonResponse('error', 405, 'Phương thức không được hỗ trợ!', null, 'ERR_METHOD_NOT_ALLOWED');
}

$keyword = sanitizeInput($_GET['keyword'] ?? '');
$pdo = getDBConnection();

if (!empty($keyword)) {
    // Tìm kiếm theo Tên hoặc Số điện thoại
    $stmt = $pdo->prepare("
        SELECT id, full_name, phone, email, address, created_at 
        FROM customers 
        WHERE full_name LIKE ? OR phone LIKE ? 
        ORDER BY id DESC
    ");
    $searchTerm = "%{$keyword}%";
    $stmt->execute([$searchTerm, $searchTerm]);
} else {
    // Lấy toàn bộ danh sách
    $stmt = $pdo->query("
        SELECT id, full_name, phone, email, address, created_at 
        FROM customers 
        ORDER BY id DESC
    ");
}

$customers = $stmt->fetchAll();

sendJsonResponse('success', 200, 'Lấy danh sách khách hàng thành công!', [
    'total'     => count($customers),
    'customers' => $customers
]);