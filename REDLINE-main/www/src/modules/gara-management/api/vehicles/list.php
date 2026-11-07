<?php
// api/vehicles/list.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

requireAuth();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJsonResponse('error', 405, 'Phương thức không được hỗ trợ!', null, 'ERR_METHOD_NOT_ALLOWED');
}

$customerId = (int)($_GET['customer_id'] ?? 0);
$keyword    = sanitizeInput($_GET['keyword'] ?? '');

$pdo = getDBConnection();

$sql = "
    SELECT v.*, c.full_name AS customer_name, c.phone AS customer_phone
    FROM vehicles v
    JOIN customers c ON v.customer_id = c.id
    WHERE 1=1
";
$params = [];

if (!empty($customerId)) {
    $sql .= " AND v.customer_id = ?";
    $params[] = $customerId;
}

if (!empty($keyword)) {
    $sql .= " AND (v.license_plate LIKE ? OR v.brand LIKE ? OR v.model LIKE ?)";
    $searchTerm = "%{$keyword}%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$sql .= " ORDER BY v.id DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$vehicles = $stmt->fetchAll();

sendJsonResponse('success', 200, 'Lấy danh sách xe thành công!', [
    'total'    => count($vehicles),
    'vehicles' => $vehicles
]);