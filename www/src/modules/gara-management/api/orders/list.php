<?php
// api/orders/list.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJsonResponse('error', 405, 'Phương thức không được hỗ trợ!', null, 'ERR_METHOD_NOT_ALLOWED');
}

$status  = sanitizeInput($_GET['status'] ?? '');
$keyword = sanitizeInput($_GET['keyword'] ?? '');

$pdo = getDBConnection();

$sql = "
    SELECT 
        ro.id AS order_id,
        ro.order_code,
        ro.km_in,
        ro.symptoms,
        ro.status,
        ro.created_at,
        v.license_plate,
        v.brand,
        v.model,
        c.id AS customer_id,
        c.full_name AS customer_name,
        c.phone AS customer_phone,
        u.full_name AS created_by_name
    FROM repair_orders ro
    JOIN vehicles v ON ro.vehicle_id = v.id
    JOIN customers c ON v.customer_id = c.id
    LEFT JOIN users u ON ro.created_by = u.id
    WHERE 1=1
";
$params = [];

if (!empty($status)) {
    $sql .= " AND ro.status = ?";
    $params[] = $status;
}

if (!empty($keyword)) {
    $sql .= " AND (ro.order_code LIKE ? OR v.license_plate LIKE ? OR c.full_name LIKE ? OR c.phone LIKE ?)";
    $searchTerm = "%{$keyword}%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$sql .= " ORDER BY ro.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$orders = $stmt->fetchAll();

sendJsonResponse('success', 200, 'Lấy danh sách đơn sửa chữa thành công!', [
    'total'  => count($orders),
    'orders' => $orders
]);