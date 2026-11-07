<?php
// api/invoices/create.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

requireAuth(['Admin', 'Staff']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse('error', 405, 'Phương thức không được hỗ trợ!', null, 'ERR_METHOD_NOT_ALLOWED');
}

// Bổ sung đọc dữ liệu linh hoạt (JSON + Form-encode)
$inputData = json_decode(file_get_contents('php://input'), true) ?? [];
$requestData = array_merge($_POST, $inputData);

$orderId       = (int)($requestData['order_id'] ?? 0);
$discount      = (float)($requestData['discount'] ?? 0);
$paymentMethod = sanitizeInput($requestData['payment_method'] ?? 'cash');

$allowedMethods = ['cash', 'transfer', 'card'];
if ($orderId <= 0 || !in_array($paymentMethod, $allowedMethods) || $discount < 0) {
    sendJsonResponse('error', 400, 'Dữ liệu đầu vào không hợp lệ!', null, 'ERR_VALIDATION_FAILED');
}

$pdo = getDBConnection();

// 1. Kiểm tra đơn sửa chữa
$stmt = $pdo->prepare("SELECT id, status FROM repair_orders WHERE id = ?");
$stmt->execute([$orderId]);
$order = $stmt->fetch();

if (!$order) {
    sendJsonResponse('error', 404, 'Không tìm thấy phiếu sửa chữa!', null, 'ERR_ORDER_NOT_FOUND');
}

// 2. Kiểm tra đơn đã tồn tại hóa đơn chưa
$stmtCheckInv = $pdo->prepare("SELECT id, invoice_code FROM invoices WHERE order_id = ? AND payment_status != 'cancelled'");
$stmtCheckInv->execute([$orderId]);
$existingInv = $stmtCheckInv->fetch();

if ($existingInv) {
    sendJsonResponse('error', 400, 'Đơn sửa chữa này đã được xuất hóa đơn (' . $existingInv['invoice_code'] . ')!', null, 'ERR_INVOICE_EXISTS');
}

// 3. Tính tổng tiền dịch vụ
$stmtSvc = $pdo->prepare("SELECT SUM(quantity * price) AS total FROM order_services WHERE order_id = ?");
$stmtSvc->execute([$orderId]);
$totalServices = (float)($stmtSvc->fetch()['total'] ?? 0);

// 4. Tính tổng tiền phụ tùng
$stmtPart = $pdo->prepare("SELECT SUM(quantity * price) AS total FROM order_parts WHERE order_id = ?");
$stmtPart->execute([$orderId]);
$totalParts = (float)($stmtPart->fetch()['total'] ?? 0);

$grandTotal = max(0, ($totalServices + $totalParts) - $discount);
$invoiceCode = 'INV-' . date('Ymd') . '-' . rand(100, 999);
$createdBy = $_SESSION['user_id'] ?? null;

// 5. Lưu hóa đơn vào CSDL
$stmtInsert = $pdo->prepare("
    INSERT INTO invoices 
    (invoice_code, order_id, total_services, total_parts, discount, grand_total, payment_status, payment_method, created_by, created_at) 
    VALUES (?, ?, ?, ?, ?, ?, 'paid', ?, ?, NOW())
");

$success = $stmtInsert->execute([
    $invoiceCode,
    $orderId,
    $totalServices,
    $totalParts,
    $discount,
    $grandTotal,
    $paymentMethod,
    $createdBy
]);

if ($success) {
    $invoiceId = $pdo->lastInsertId();

    // Cập nhật phiếu sửa chữa sang 'completed' (nếu chưa)
    $stmtUpdateOrder = $pdo->prepare("UPDATE repair_orders SET status = 'completed', updated_at = NOW() WHERE id = ?");
    $stmtUpdateOrder->execute([$orderId]);

    sendJsonResponse('success', 201, 'Tạo hóa đơn và thanh toán thành công!', [
        'invoice_id'     => (int)$invoiceId,
        'invoice_code'   => $invoiceCode,
        'order_id'       => $orderId,
        'total_services' => $totalServices,
        'total_parts'    => $totalParts,
        'discount'       => $discount,
        'grand_total'    => $grandTotal,
        'payment_status' => 'paid',
        'payment_method' => $paymentMethod
    ]);
} else {
    sendJsonResponse('error', 500, 'Lỗi hệ thống, không thể tạo hóa đơn!', null, 'ERR_SERVER_ERROR');
}