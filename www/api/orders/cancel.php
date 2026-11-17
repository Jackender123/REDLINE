<?php
// api/orders/cancel.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/api_functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

requireAuth(['Admin', 'Staff']);
if (!in_array($_SESSION['role'] ?? '', ['Admin', 'Receptionist'], true)) {
    sendJsonResponse('error', 403, 'Bạn không có quyền hủy phiếu sửa chữa!', null, 'ERR_FORBIDDEN');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendJsonResponse('error', 405, 'Phương thức không được hỗ trợ!', null, 'ERR_METHOD_NOT_ALLOWED');
}
if (!csrf_valid($_POST['csrf'] ?? null)) {
    sendJsonResponse('error', 403, 'Reload the page and try again.', null, 'ERR_CSRF');
}

$orderId = (int)($_POST['order_id'] ?? 0);
$reason  = sanitizeInput($_POST['reason'] ?? 'Khách hàng hủy yêu cầu');

if (empty($orderId)) {
    sendJsonResponse('error', 400, 'ID phiếu không được để trống!', null, 'ERR_VALIDATION_FAILED');
}

$pdo = getDBConnection();

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT id, status, is_stock_deducted FROM repair_orders WHERE id = ? FOR UPDATE');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order || in_array($order['status'], ['completed','delivered','cancelled'], true)) {
        throw new DomainException('Order not found or already closed.');
    }
    $invoice = $pdo->prepare("SELECT id FROM invoices WHERE order_id = ? AND payment_status <> 'cancelled' LIMIT 1");
    $invoice->execute([$orderId]);
    if ($invoice->fetchColumn()) throw new DomainException('Resolve the invoice before cancelling this repair.');
    $issued = $pdo->prepare("SELECT id FROM part_requests WHERE order_id = ? AND status = 'fulfilled' LIMIT 1 FOR UPDATE");
    $issued->execute([$orderId]);
    $billedParts = $pdo->prepare("SELECT id FROM repair_order_items WHERE repair_order_id = ? AND item_type = 'part' LIMIT 1 FOR UPDATE");
    $billedParts->execute([$orderId]);
    if ($issued->fetchColumn() || $billedParts->fetchColumn() || (int)$order['is_stock_deducted']) {
        throw new DomainException('Parts have been issued or recorded. Cancellation requires physical stock reconciliation; automatic restocking is not allowed.');
    }
    $staff = current_staff();
    $pdo->prepare("UPDATE part_requests SET status = 'rejected', decided_by = ?, updated_at = NOW() WHERE order_id = ? AND status IN ('pending','approved')")
        ->execute([(int)$staff['id'], $orderId]);
    $stmt = $pdo->prepare("UPDATE repair_orders SET status = 'cancelled', note = CONCAT(IFNULL(note, ''), ' | Lý do hủy: ', ?), updated_at = NOW() WHERE id = ?");
    $stmt->execute([$reason, $orderId]);
    $verify = $pdo->prepare('SELECT status FROM repair_orders WHERE id = ?');
    $verify->execute([$orderId]);
    if ($verify->fetchColumn() !== 'cancelled') throw new RuntimeException('Cancelled status is unsupported. Run the database migration.');
    $pdo->prepare('INSERT INTO order_status_history (order_id, created_by, from_status, to_status, note) VALUES (?, ?, ?, ?, ?)')
        ->execute([$orderId, (int)$staff['id'], $order['status'], 'cancelled', 'Cancelled; unissued part requests rejected. Reason: ' . $reason]);
    $pdo->commit();
    sendJsonResponse('success', 200, 'Hủy đơn sửa chữa thành công!', [
        'order_id' => $orderId,
        'status'   => 'cancelled',
        'reason'   => $reason
    ]);
} catch (DomainException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    sendJsonResponse('error', 409, $e->getMessage(), null, 'ERR_ORDER_CANCEL_BLOCKED');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Repair cancellation failed: ' . $e->getMessage());
    sendJsonResponse('error', 500, 'Lỗi hệ thống, không thể hủy phiếu!', null, 'ERR_SERVER_ERROR');
}
