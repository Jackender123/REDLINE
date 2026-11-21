<?php
// Admin repair-cost approval. JSON for the embedded inspector; POST/redirect for non-JS clients.
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/repair_cost_review.php';
$isJsonRequest = stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;

function rl_cost_review_reply(int $orderId, bool $ok, string $message, int $status = 422): never
{
    if ($GLOBALS['isJsonRequest']) {
        http_response_code($ok ? 200 : $status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        echo json_encode(['ok' => $ok, 'message' => $message, 'repair_order_id' => $orderId], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
    if ($orderId > 0) {
        $_SESSION[$ok ? 'cost_review_notice' : 'cost_review_error'][$orderId] = $message;
    }
    $url = '../../admin/orders/detail.php?id=' . $orderId;
    if (($_POST['embed'] ?? '') === '1') $url .= '&embed=1';
    header('Location: ' . $url . '#cost-review-heading', true, 303);
    exit;
}

$staff = current_staff();
if (!$staff || ($staff['role_name'] ?? '') !== 'Admin') rl_cost_review_reply(0, false, 'Administrator access required.', 403);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') rl_cost_review_reply(0, false, 'POST required.', 405);
if (!csrf_valid($_POST['csrf'] ?? null)) rl_cost_review_reply(0, false, 'Session expired. Reload the order and retry.', 403);
$orderId = filter_var($_POST['repair_order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$orderId) rl_cost_review_reply(0, false, 'Invalid repair order.', 422);
$type = (string)($_POST['review_type'] ?? '');
$reason = trim((string)($_POST['review_reason'] ?? ''));

try {
    $pdo = db();
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT id, status FROM repair_orders WHERE id = ? FOR UPDATE');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order || $order['status'] !== 'completed') {
        throw new RuntimeException('Repair Order must be Completed before cost review.');
    }
    $invoice = $pdo->prepare("SELECT id FROM invoices WHERE order_id = ? AND payment_status <> 'cancelled' LIMIT 1");
    $invoice->execute([$orderId]);
    if ($invoice->fetchColumn()) throw new RuntimeException('This order already has an active invoice.');

    $snapshot = rl_cost_snapshot($pdo, (int)$orderId);
    rl_cost_review_validate_choice($snapshot, $type, $reason);
    $save = $pdo->prepare('INSERT INTO repair_order_cost_reviews (order_id, reviewed_by, review_type, reason, items_hash, reviewed_total, reviewed_at) VALUES (?, ?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE reviewed_by=VALUES(reviewed_by), review_type=VALUES(review_type), reason=VALUES(reason), items_hash=VALUES(items_hash), reviewed_total=VALUES(reviewed_total), reviewed_at=NOW()');
    $save->execute([$orderId, (int)$staff['id'], $type, $reason, $snapshot['hash'], $snapshot['total']]);
    $pdo->prepare('INSERT INTO order_status_history (order_id, created_by, from_status, to_status, note) VALUES (?, ?, ?, ?, ?)')
        ->execute([$orderId, (int)$staff['id'], 'completed', 'completed', 'Admin reviewed repair costs (' . $type . '): ' . rl_usd($snapshot['total']) . '.' . ($reason === '' ? '' : ' Reason: ' . $reason)]);
    $pdo->commit();
    $message = 'Repair costs approved. You may now create an invoice.';
    // Keep the confirmation available after refreshing the embedded inspector.
    $_SESSION['cost_review_notice'][$orderId] = $message;
    rl_cost_review_reply((int)$orderId, true, $message, 200);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    if ($e instanceof RuntimeException && !($e instanceof PDOException)) {
        rl_cost_review_reply((int)$orderId, false, $e->getMessage(), 422);
    }
    error_log('Cost review failed for order #' . $orderId . ': ' . $e->getMessage());
    rl_cost_review_reply((int)$orderId, false, 'Cost approval could not be saved. Check the repair_order_cost_reviews table and server logs.', 500);
}
