<?php
// Admin-only, audited reopening of a completed repair order.
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/repair_work_sessions.php';

$staff = function_exists('current_staff') ? current_staff() : null;
if (!$staff || ($staff['role_name'] ?? '') !== 'Admin') {
    http_response_code(403);
    exit('Administrator access is required.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}
if (!csrf_valid($_POST['csrf'] ?? null)) {
    http_response_code(403);
    exit('Your session has expired. Please reload the page.');
}

$orderId = filter_var($_POST['repair_order_id'] ?? null, FILTER_VALIDATE_INT);
$reason = trim((string)($_POST['reason'] ?? ''));
if (!$orderId || $orderId <= 0) {
    http_response_code(400);
    exit('Invalid repair order ID.');
}
$back = '../../admin/orders/detail.php?id=' . (int)$orderId;
$pdo = db();
try {
    if (mb_strlen($reason, 'UTF-8') < 10 || mb_strlen($reason, 'UTF-8') > 500) {
        throw new RuntimeException('Please provide a reason between 10 and 500 characters.');
    }
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT id, status, assigned_to FROM repair_orders WHERE id = ? FOR UPDATE');
    $stmt->execute([(int)$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) {
        throw new RuntimeException('Repair order not found.');
    }
    if ($order['status'] !== 'completed') {
        throw new RuntimeException('Only completed repair orders can be reopened. Delivered orders remain locked.');
    }
    if (empty($order['assigned_to'])) {
        throw new RuntimeException('This order has no assigned technician. Please contact an administrator.');
    }
    // Prevent reopening an order that has moved into billing, including paid/partial invoices.
    // Cancelled invoices do not block reopening.
    $invoiceStmt = $pdo->prepare("SELECT id FROM invoices WHERE order_id = ? AND payment_status <> 'cancelled' LIMIT 1");
    $invoiceStmt->execute([(int)$orderId]);
    if ($invoiceStmt->fetchColumn()) {
        throw new RuntimeException('An invoice already exists for this repair order. Resolve billing before reopening.');
    }
    $previousSession = rl_work_session_current($pdo, (int)$orderId);
    if (!$previousSession) {
        rl_work_session_require_active($pdo, $order, (int)$order['assigned_to']);
        $previousSession = rl_work_session_current($pdo, (int)$orderId);
    }
    if ((int)$previousSession['technician_id'] !== (int)$order['assigned_to']) {
        throw new RuntimeException('The work session does not match the assigned technician. Review the repair order before reopening.');
    }
    if (empty($previousSession['completed_at'])) {
        rl_work_session_close($pdo, $order, (int)$order['assigned_to'], 'completed', 'Completed before Admin reopened the repair order.');
    }
    rl_work_session_start($pdo, $order, (int)$order['assigned_to'], (int)$staff['id']);
    $pdo->prepare('DELETE FROM repair_order_cost_reviews WHERE order_id = ?')->execute([(int)$orderId]);
    $update = $pdo->prepare("UPDATE repair_orders SET status = 'inspecting', updated_at = NOW() WHERE id = ? AND status = 'completed'");
    $update->execute([(int)$orderId]);
    if ($update->rowCount() !== 1) {
        throw new RuntimeException('The order was changed by another user. Reload and try again.');
    }
    $history = $pdo->prepare('INSERT INTO order_status_history (order_id, created_by, from_status, to_status, note) VALUES (?, ?, ?, ?, ?)');
    $history->execute([(int)$orderId, (int)$staff['id'], 'completed', 'inspecting', 'Admin reopened completed repair order. Reason: ' . $reason]);
    $pdo->commit();
    $_SESSION['order_approval_notice'][(int)$orderId] = 'Repair order reopened for Final Inspection in a new work session. Previous work and charges remain recorded.';
} catch (RuntimeException | DomainException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['order_approval_error'][(int)$orderId] = $e->getMessage();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Repair order reopen failed: ' . $e->getMessage());
    $_SESSION['order_approval_error'][(int)$orderId] = 'Unable to reopen this repair order. Please check the server log.';
}
header('Location: ' . $back, true, 303);
exit;
