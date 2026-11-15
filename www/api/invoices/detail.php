<?php
// api/invoices/detail.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/api_functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

requireAuth(['Admin']);
if (!in_array($_SESSION['role'] ?? '', ['Admin'], true)) {
    sendJsonResponse('error', 403, 'Bạn không có quyền xem hóa đơn!', null, 'ERR_FORBIDDEN');
}

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
    LEFT JOIN users c ON c.id = COALESCE(ro.customer_id, v.customer_id)
    LEFT JOIN users u_creator ON i.created_by = u_creator.id
    LEFT JOIN users u_tech ON ro.assigned_to = u_tech.id
    WHERE i.id = ?
");
$stmtInv->execute([$invoiceId]);
$invoice = $stmtInv->fetch();

if (!$invoice) {
    sendJsonResponse('error', 404, 'Không tìm thấy hóa đơn!', null, 'ERR_INVOICE_NOT_FOUND');
}

$orderId = (int)$invoice['order_id'];

// Repair Order items are the canonical invoicing source (not legacy order_services/order_parts).
$stmtSvc = $pdo->prepare("SELECT id, item_name AS service_name, quantity, unit_price,
                                  subtotal AS total_price
                           FROM repair_order_items
                           WHERE repair_order_id = ? AND item_type = 'labor' ORDER BY id");
$stmtSvc->execute([$orderId]);
$services = $stmtSvc->fetchAll(PDO::FETCH_ASSOC);
$stmtParts = $pdo->prepare("SELECT id, item_name AS part_name, quantity, unit_price,
                                    subtotal AS total_price
                             FROM repair_order_items
                             WHERE repair_order_id = ? AND item_type = 'part' ORDER BY id");
$stmtParts->execute([$orderId]);
$parts = $stmtParts->fetchAll(PDO::FETCH_ASSOC);

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