<?php
// api/orders/cancel.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

requireAuth(['Admin', 'Staff']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse('error', 405, 'Phương thức không được hỗ trợ!', null, 'ERR_METHOD_NOT_ALLOWED');
}

$orderId = (int)($_POST['order_id'] ?? 0);
$reason  = sanitizeInput($_POST['reason'] ?? 'Khách hàng hủy yêu cầu');

if (empty($orderId)) {
    sendJsonResponse('error', 400, 'ID phiếu không được để trống!', null, 'ERR_VALIDATION_FAILED');
}

$pdo = getDBConnection();

$stmt = $pdo->prepare("SELECT id, status FROM repair_orders WHERE id = ?");
$stmt->execute([$orderId]);
$order = $stmt->fetch();

if (!$order) {
    sendJsonResponse('error', 404, 'Không tìm thấy phiếu sửa chữa!', null, 'ERR_ORDER_NOT_FOUND');
}

if ($order['status'] === 'completed') {
    sendJsonResponse('error', 400, 'Không thể hủy đơn sửa chữa đã hoàn thành!', null, 'ERR_ORDER_COMPLETED');
}

$stmt = $pdo->prepare("UPDATE repair_orders SET status = 'cancelled', note = CONCAT(IFNULL(note, ''), ' | Lý do hủy: ', ?), updated_at = NOW() WHERE id = ?");

if ($stmt->execute([$reason, $orderId])) {
    sendJsonResponse('success', 200, 'Hủy đơn sửa chữa thành công!', [
        'order_id' => $orderId,
        'status'   => 'cancelled',
        'reason'   => $reason
    ]);
} else {
    sendJsonResponse('error', 500, 'Lỗi hệ thống, không thể hủy phiếu!', null, 'ERR_SERVER_ERROR');
}