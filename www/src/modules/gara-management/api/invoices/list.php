<?php
// api/invoices/list.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

requireAuth(['Admin', 'Staff']);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJsonResponse('error', 405, 'Phương thức không được hỗ trợ!', null, 'ERR_METHOD_NOT_ALLOWED');
}

$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = max(1, min(100, (int)($_GET['limit'] ?? 10)));
$offset = ($page - 1) * $limit;

$search        = sanitizeInput($_GET['search'] ?? '');
$paymentStatus = sanitizeInput($_GET['payment_status'] ?? '');

$pdo = getDBConnection();

$whereClauses = ["1=1"];
$params = [];

if (!empty($search)) {
    $whereClauses[] = "(i.invoice_code LIKE ? OR v.license_plate LIKE ? OR c.full_name LIKE ?)";
    $searchTerm = "%{$search}%";
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

if (!empty($paymentStatus) && in_array($paymentStatus, ['unpaid', 'paid', 'cancelled'])) {
    $whereClauses[] = "i.payment_status = ?";
    $params[] = $paymentStatus;
}

$whereSql = implode(' AND ', $whereClauses);

// 1. Đếm tổng số bản ghi
$countStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM invoices i
    JOIN repair_orders ro ON i.order_id = ro.id
    JOIN vehicles v ON ro.vehicle_id = v.id
    JOIN customers c ON v.customer_id = c.id
    WHERE {$whereSql}
");
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();

// 2. Lấy danh sách hóa đơn phân trang
$stmt = $pdo->prepare("
    SELECT 
        i.id AS invoice_id,
        i.invoice_code,
        i.order_id,
        i.total_services,
        i.total_parts,
        i.discount,
        i.grand_total,
        i.payment_status,
        i.payment_method,
        i.created_at,
        ro.order_code,
        v.license_plate,
        v.brand,
        v.model,
        c.full_name AS customer_name,
        c.phone AS customer_phone,
        u.full_name AS created_by_name
    FROM invoices i
    JOIN repair_orders ro ON i.order_id = ro.id
    JOIN vehicles v ON ro.vehicle_id = v.id
    JOIN customers c ON v.customer_id = c.id
    LEFT JOIN users u ON i.created_by = u.id
    WHERE {$whereSql}
    ORDER BY i.id DESC
    LIMIT ? OFFSET ?
");

$paramIndex = 1;
foreach ($params as $param) {
    $stmt->bindValue($paramIndex++, $param);
}
$stmt->bindValue($paramIndex++, $limit, PDO::PARAM_INT);
$stmt->bindValue($paramIndex++, $offset, PDO::PARAM_INT);

$stmt->execute();
$invoices = $stmt->fetchAll();

foreach ($invoices as &$inv) {
    $inv['invoice_id']     = (int)$inv['invoice_id'];
    $inv['order_id']       = (int)$inv['order_id'];
    $inv['total_services'] = (float)$inv['total_services'];
    $inv['total_parts']    = (float)$inv['total_parts'];
    $inv['discount']       = (float)$inv['discount'];
    $inv['grand_total']    = (float)$inv['grand_total'];
}

sendJsonResponse('success', 200, 'Lấy danh sách hóa đơn thành công!', [
    'invoices'   => $invoices,
    'pagination' => [
        'total_records' => $totalRecords,
        'current_page'  => $page,
        'limit'         => $limit,
        'total_pages'   => ceil($totalRecords / $limit)
    ]
]);