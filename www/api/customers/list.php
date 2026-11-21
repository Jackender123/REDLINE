<?php
// api/customers/list.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/api_functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

requireAuth();
if (!in_array($_SESSION['role'] ?? '', ['Admin', 'Receptionist'], true)) {
    sendJsonResponse('error', 403, 'You do not have permission to view customers.', null, 'ERR_FORBIDDEN');
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJsonResponse('error', 405, 'Unsupported request method.', null, 'ERR_METHOD_NOT_ALLOWED');
}

$keyword = sanitizeInput($_GET['keyword'] ?? '');
$pdo = getDBConnection();

if (!empty($keyword)) {
    // Tìm kiếm theo Tên hoặc Số điện thoại
    $stmt = $pdo->prepare("
        SELECT id, full_name, phone, email, address, created_at 
        FROM users
        WHERE is_customer = 1 AND (full_name LIKE ? OR phone LIKE ?)
        ORDER BY id DESC
    ");
    $searchTerm = "%{$keyword}%";
    $stmt->execute([$searchTerm, $searchTerm]);
} else {
    // Lấy toàn bộ danh sách
    $stmt = $pdo->query("
        SELECT id, full_name, phone, email, address, created_at 
        FROM users
        WHERE is_customer = 1
        ORDER BY id DESC
    ");
}

$customers = $stmt->fetchAll();

sendJsonResponse('success', 200, 'Customer list retrieved successfully.', [
    'total'     => count($customers),
    'customers' => $customers
]);
