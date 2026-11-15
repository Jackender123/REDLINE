<?php
// Admin cost review: server-side validation + audit, prior to invoice creation.
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/repair_cost_review.php';
$staff = current_staff();
if (!$staff || ($staff['role_name'] ?? '') !== 'Admin') { http_response_code(403); exit('Administrator access required.'); }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('POST required.'); }
if (!csrf_valid($_POST['csrf'] ?? null)) { http_response_code(403); exit('Session expired. Reload the order.'); }
$orderId = filter_var($_POST['repair_order_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if (!$orderId) { http_response_code(422); exit('Invalid repair order.'); }
$type = (string)($_POST['review_type'] ?? '');
$reason = trim((string)($_POST['review_reason'] ?? ''));
$target = '../../admin/orders/detail.php?id=' . $orderId;
$pdo = db();
try {
    if (!in_array($type, ['standard','zero'], true)) throw new RuntimeException('Select normal cost review or zero-cost service.');
    if (mb_strlen($reason) > 500 || ($type === 'zero' && mb_strlen($reason) < 10)) {
        throw new RuntimeException('Zero-cost/warranty service needs a reason of 10–500 characters.');
    }
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT id, status FROM repair_orders WHERE id = ? FOR UPDATE');
    $stmt->execute([$orderId]); $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order || $order['status'] !== 'completed') throw new RuntimeException('Repair Order must be Completed before cost review.');
    $inv = $pdo->prepare("SELECT id FROM invoices WHERE order_id = ? AND payment_status <> 'cancelled' LIMIT 1");
    $inv->execute([$orderId]);
    if ($inv->fetchColumn()) throw new RuntimeException('This order already has an active invoice.');
    $snapshot = rl_cost_snapshot($pdo, (int)$orderId);
    if ($snapshot['pending'] > 0) throw new RuntimeException('Resolve all pending/approved part requests first.');
    if ($type === 'standard' && ($snapshot['count'] === 0 || $snapshot['total'] <= 0)) {
        throw new RuntimeException('No positive billable cost lines. Add service/parts or select Zero-cost Service with a reason.');
    }
    if ($type === 'zero' && $snapshot['total'] != 0.0) throw new RuntimeException('Zero-cost review requires total cost to be 0. Correct the items first.');
    $save = $pdo->prepare('INSERT INTO repair_order_cost_reviews (order_id, reviewed_by, review_type, reason, items_hash, reviewed_total, reviewed_at) VALUES (?, ?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE reviewed_by=VALUES(reviewed_by), review_type=VALUES(review_type), reason=VALUES(reason), items_hash=VALUES(items_hash), reviewed_total=VALUES(reviewed_total), reviewed_at=NOW()');
    $save->execute([$orderId, (int)$staff['id'], $type, $reason, $snapshot['hash'], $snapshot['total']]);
    $pdo->prepare('INSERT INTO order_status_history (order_id, created_by, from_status, to_status, note) VALUES (?, ?, ?, ?, ?)')->execute([$orderId, (int)$staff['id'], 'completed', 'completed', 'Admin reviewed repair costs (' . $type . '): ' . number_format($snapshot['total'], 2, '.', '') . ' VND.' . ($reason === '' ? '' : ' Reason: ' . $reason)]);
    $pdo->commit();
    $_SESSION['cost_review_notice'][$orderId] = 'Repair costs approved. You may now create an invoice.';
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e instanceof RuntimeException) $_SESSION['cost_review_error'][$orderId] = $e->getMessage();
    else { error_log('Cost review failed: ' . $e->getMessage()); $_SESSION['cost_review_error'][$orderId] = 'Cost review could not be saved. Check migration and server logs.'; }
}
header('Location: ' . $target, true, 303); exit;
