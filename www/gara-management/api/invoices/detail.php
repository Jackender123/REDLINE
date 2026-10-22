<?php
// api/invoices/detail.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

requireAuth(['Admin', 'Staff']);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJsonResponse('error', 405, 'Phương thức không được hỗ trợ!', null, 'ERR_METHOD_NOT_ALLOWED');
}

$invoiceId = (int)($_GET['id'] ?? 0);

if ($invoiceId <= 0) {
    sendJsonResponse('error', 400, 'ID hóa đơn không hợp lệ!', null, 'ERR_VALIDATION_FAILED');
}

$pdo = getDBConnection();

// 1. Truy vấn thông tin chung hóa đơn, đơn sửa chữa, xe và khách hàng
$stmtInv = $pdo->prepare("
    SELECT 
        i.id AS invoice_id,
        i.invoice_code,
        i.total_services,
        i.total_parts,
        i.discount,
        i.grand_total,
        i.payment_status,
        i.payment_method,
        i.created_at,
        ro.id AS order_id,
        ro.order_code,
        ro.km_in,
        ro.symptoms,
        v.license_plate,
        v.brand,
        v.model,
        v.color,
        c.full_name AS customer_name,
        c.phone AS customer_phone,
        c.address AS customer_address,
        u_creator.full_name AS created_by_name,
        u_tech.full_name AS technician_name
    FROM invoices i
    JOIN repair_orders ro ON i.order_id = ro.id
    JOIN vehicles v ON ro.vehicle_id = v.id
    JOIN customers c ON v.customer_id = c.id
    LEFT JOIN users u_creator ON i.created_by = u_creator.id
    LEFT JOIN users u_tech ON ro.technician_id = u_tech.id
    WHERE i.id = ?
");
$stmtInv->execute([$invoiceId]);
$invoice = $stmtInv->fetch();

if (!$invoice) {
    sendJsonResponse('error', 404, 'Không tìm thấy hóa đơn!', null, 'ERR_INVOICE_NOT_FOUND');
}

$orderId = (int)$invoice['order_id'];

// 2. Chi tiết danh sách dịch vụ / tiền công
$stmtSvc = $pdo->prepare("
    SELECT 
        os.id,
        IFNULL(s.name, 'Dịch vụ / Tiền công ngoài danh mục') AS service_name,
        os.quantity,
        CAST(os.price AS FLOAT) AS unit_price,
        CAST((os.quantity * os.price) AS FLOAT) AS total_price
    FROM order_services os
    LEFT JOIN services s ON os.service_id = s.id
    WHERE os.order_id = ?
");
$stmtSvc->execute([$orderId]);
$services = $stmtSvc->fetchAll();

// 3. Chi tiết danh sách phụ tùng / vật tư
$stmtParts = $pdo->prepare("
    SELECT 
        op.id,
        IFNULL(p.name, 'Phụ tùng / Vật tư ngoài danh mục') AS part_name,
        op.quantity,
        CAST(op.price AS FLOAT) AS unit_price,
        CAST((op.quantity * op.price) AS FLOAT) AS total_price
    FROM order_parts op
    LEFT JOIN parts p ON op.part_id = p.id
    WHERE op.order_id = ?
");
$stmtParts->execute([$orderId]);
$parts = $stmtParts->fetchAll();

// Ép kiểu dữ liệu số
$invoice['invoice_id']     = (int)$invoice['invoice_id'];
$invoice['order_id']       = (int)$invoice['order_id'];
$invoice['total_services'] = (float)$invoice['total_services'];
$invoice['total_parts']    = (float)$invoice['total_parts'];
$invoice['discount']       = (float)$invoice['discount'];
$invoice['grand_total']    = (float)$invoice['grand_total'];

sendJsonResponse('success', 200, 'Lấy chi tiết hóa đơn thành công!', [
    'invoice_info' => $invoice,
    'details'      => [
        'services' => $services,
        'parts'    => $parts
    ]
]);