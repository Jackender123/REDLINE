<?php
/** Explicit staff POST: create/open linked order without the old intake form. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/booking_order_sync.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function from_request_response(int $status, array $data): void
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    from_request_response(405, ['ok' => false, 'message' => 'POST required.']);
}
$staff = current_staff();
if (!$staff || !in_array($staff['role_name'] ?? '', ['Admin', 'Receptionist'], true)) {
    from_request_response(403, ['ok' => false, 'message' => 'Workshop staff access required.']);
}
if (!csrf_valid($_POST['csrf'] ?? null)) {
    from_request_response(403, ['ok' => false, 'message' => 'Session expired. Refresh the page.']);
}
$requestId = filter_var($_POST['service_request_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$requestId) {
    from_request_response(422, ['ok' => false, 'message' => 'Choose a valid appointment.']);
}
$pdo = db();
try {
    $pdo->beginTransaction();
    $orderId = rl_request_to_order($pdo, (int)$requestId);
    $pdo->commit();
    $code = $pdo->prepare('SELECT order_code FROM repair_orders WHERE id = ?');
    $code->execute([$orderId]);
    from_request_response(200, ['ok' => true, 'order_id' => $orderId, 'order_code' => (string)$code->fetchColumn()]);
} catch (DomainException $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    from_request_response(409, ['ok' => false, 'message' => $exception->getMessage()]);
} catch (Throwable $exception) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Booking->order conversion failed for request ' . $requestId . ': ' . $exception->getMessage());
    from_request_response(500, ['ok' => false, 'message' => 'Cannot link this request to a repair order. Check the database migration and customer/vehicle records.']);
}
