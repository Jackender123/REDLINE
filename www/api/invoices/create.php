<?php
// Safe invoice creation for API consumers. No implicit payment or status transition.
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/repair_cost_review.php';
header('Content-Type: application/json; charset=utf-8');
function invoice_api_reply(int $code, string $message, ?array $data = null): void {
    http_response_code($code);
    echo json_encode(['status' => $code < 400 ? 'success' : 'error', 'message' => $message, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}
$staff = current_staff();
if (!$staff || ($staff['role_name'] ?? '') !== 'Admin') invoice_api_reply(403, 'Administrator access required.');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') invoice_api_reply(405, 'POST required.');
$body = json_decode(file_get_contents('php://input'), true);
$data = array_merge($_POST, is_array($body) ? $body : []);
if (!csrf_valid($data['csrf'] ?? null)) invoice_api_reply(403, 'Session expired.');
$orderId = filter_var($data['order_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
$discount = filter_var($data['discount'] ?? '0', FILTER_VALIDATE_FLOAT);
$method = (string)($data['payment_method'] ?? 'cash');
if (!$orderId || $discount === false || !is_finite((float)$discount) || $discount < 0 || !in_array($method, ['cash','transfer','card'], true)) invoice_api_reply(422, 'Invalid invoice details.');
$pdo = db();
try {
    $pdo->beginTransaction();
    $q = $pdo->prepare('SELECT id, status FROM repair_orders WHERE id=? FOR UPDATE');
    $q->execute([$orderId]); $order = $q->fetch(PDO::FETCH_ASSOC);
    if (!$order || $order['status'] !== 'completed') throw new RuntimeException('Only Completed orders can be invoiced.');
    $q = $pdo->prepare('SELECT id FROM invoices WHERE order_id=? LIMIT 1');
    $q->execute([$orderId]);
    if ($q->fetchColumn()) throw new RuntimeException('Invoice already exists for this order.');
    $verified = rl_cost_verified($pdo, (int)$orderId, true);
    $subtotal = $verified['snapshot']['total'];
    if ($discount > $subtotal) throw new RuntimeException('Discount cannot exceed subtotal.');
    if ($verified['review']['review_type'] === 'zero' && $discount != 0) throw new RuntimeException('Zero-cost invoice cannot have a discount.');
    $paymentStatus = $verified['review']['review_type'] === 'zero' ? 'paid' : 'unpaid';
    $code = 'INV-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
    $q = $pdo->prepare('INSERT INTO invoices (invoice_code, order_id, total_services, total_parts, discount, grand_total, payment_status, payment_method, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())');
    $q->execute([$code, $orderId, $verified['snapshot']['labor'], $verified['snapshot']['parts'], $discount, $subtotal-$discount, $paymentStatus, $method, (int)$staff['id']]);
    $id = (int)$pdo->lastInsertId();
    $pdo->commit();
    invoice_api_reply(201, 'Invoice created; payment is not automatically recorded.', ['invoice_id'=>$id,'invoice_code'=>$code,'payment_status'=>$paymentStatus]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e instanceof RuntimeException) invoice_api_reply(409, $e->getMessage());
    error_log('Invoice API create error: ' . $e->getMessage());
    invoice_api_reply(500, 'Unable to create invoice. Check server logs.');
}
