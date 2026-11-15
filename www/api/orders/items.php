<?php
// www/src/modules/gara-management/api/orders/items.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/vehicle_handover_payment.php';
$staff = function_exists('current_staff') ? current_staff() : null;
if (!$staff && function_exists('requireAuth')) {
    requireAuth();
}
if (!$staff || !in_array($staff['role_name'] ?? '', ['Admin', 'Receptionist'], true)) {
    http_response_code(403);
    exit('Bạn không có quyền chỉnh sửa hạng mục hoặc trạng thái phiếu.');
}

$pdo = db();
$action =$_POST['action'] ?? ($_GET['action'] ?? '');$orderId = (int)($_POST['repair_order_id'] ?? ($_GET['repair_order_id'] ?? 0));

if ($orderId <= 0) {
    header('Location: ../../admin/orders/list.php');
    exit;
}

if (in_array($action, ['add', 'delete', 'update_status'], true)) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: POST');
        http_response_code(405);
        exit('Submit item changes using the repair order form.');
    }
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        http_response_code(403);
        exit('Your session expired. Reload the repair order and try again.');
    }
}

// 1. THÊM HẠNG MỤC (TIỀN CÔNG HOẶC PHỤ TÙNG TỪ KHO)
if ($action === 'add') {
    $itemType  = trim((string)($_POST['item_type'] ?? 'labor'));
    $partId    = (int)($_POST['part_id'] ?? 0);
    $serviceId = (int)($_POST['service_id'] ?? 0);
    $itemName  = trim((string)($_POST['item_name'] ?? ''));
    $priceRaw = trim((string) ($_POST['unit_price'] ?? ''));
    $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 1000000]]);

    if (!in_array($itemType, ['labor', 'part'], true)) {
        http_response_code(422);
        exit('Choose labor or spare part.');
    }

    if ($itemType === 'part') {
        http_response_code(409);
        exit('To add billable spare parts, the assigned technician must request them and Inventory must fulfill the approved request. This records stock and billing together.');
    }

    if ($itemType === 'labor' && $serviceId > 0) {
        $stmtService = $pdo->prepare('SELECT name, price FROM services WHERE id = ? AND is_active = 1');
        $stmtService->execute([$serviceId]);
        $service = $stmtService->fetch(PDO::FETCH_ASSOC);
        if (!$service) {
            http_response_code(422);
            exit('The selected workshop service is unavailable.');
        }
        if ($itemName === '') $itemName = $service['name'];
        if ($priceRaw === '') $priceRaw = (string) $service['price'];
    }

    // Nếu chọn từ danh mục phụ tùng trong kho
    if ($itemType === 'part' &&$partId > 0) {
        $stmtP =$pdo->prepare("SELECT name, selling_price FROM parts WHERE id = ? AND is_active = 1 LIMIT 1");
        $stmtP->execute([$partId]);
        $part =$stmtP->fetch(PDO::FETCH_ASSOC);
        if (!$part) {
            http_response_code(422);
            exit('The selected spare part is unavailable.');
        }
        $itemName =$part['name'];
        if ($priceRaw === '') $priceRaw = (string) $part['selling_price'];
    }

    if ($itemName === '' || mb_strlen($itemName) > 255 || $quantity === false
        || !preg_match('/^[0-9]{1,12}(?:\.[0-9]{1,2})?$/', $priceRaw)
        || (float) $priceRaw * $quantity > 9999999999999.99) {
        http_response_code(422);
        exit('Enter a description, a non-negative price, and a whole quantity between 1 and 1000000.');
    }
    $unitPrice = (float) $priceRaw;
    $subtotal = round($unitPrice * $quantity, 2);
    try {
        $pdo->beginTransaction();
        lockRepairOrder($pdo, $orderId);
        $stmt =$pdo->prepare("
            INSERT INTO repair_order_items (repair_order_id, item_type, item_name, unit_price, quantity, subtotal)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$orderId,$itemType, $itemName,$unitPrice, $quantity,$subtotal]);

        recalculateOrderTotal($pdo,$orderId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof DomainException) { http_response_code(409); exit($e->getMessage()); }
        error_log('Repair item save: ' . $e->getMessage());
        http_response_code(500);
        exit('The item could not be saved. Reload the order and try again.');
    }
    header("Location: ../../admin/orders/detail.php?id={$orderId}");
    exit;
}

// 2. XÓA HẠNG MỤC
if ($action === 'delete') {
    $itemId = (int)($_POST['item_id'] ?? 0);
    try {
        $pdo->beginTransaction();
        lockRepairOrder($pdo, $orderId);
        $stmt =$pdo->prepare("DELETE FROM repair_order_items WHERE id = ? AND repair_order_id = ? AND part_request_id IS NULL");
        $stmt->execute([$itemId,$orderId]);
        if ($stmt->rowCount() !== 1) {
            throw new DomainException('Issued spare parts cannot be deleted directly. Use a controlled stock return/correction process.');
        }

        recalculateOrderTotal($pdo,$orderId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof DomainException) { http_response_code(409); exit($e->getMessage()); }
        error_log('Repair item removal: ' . $e->getMessage());
        http_response_code(500);
        exit('The item could not be removed. Reload the order and try again.');
    }
    header("Location: ../../admin/orders/detail.php?id={$orderId}");
    exit;
}

// 3. HANDOVER: only an Admin can move a completed, paid job to Delivered.
// Technician owns the repair workflow; this API must not skip diagnostic/inspection stages.
if ($action === 'update_status') {
    if (($staff['role_name'] ?? '') !== 'Admin') {
        http_response_code(403);
        exit('Only an administrator can confirm vehicle handover.');
    }
    $newStatus = trim((string)($_POST['status'] ?? ''));
    if ($newStatus !== 'delivered') {
        http_response_code(422);
        exit('Repair progress is updated by the assigned technician. Admin may only confirm handover after completion and payment.');
    }
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare('SELECT id, status, assigned_to FROM repair_orders WHERE id = ? FOR UPDATE');
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) throw new DomainException('Repair order not found.');
        if ($order['status'] !== 'completed') {
            throw new DomainException('The vehicle can only be handed over after the technician completes the repair.');
        }
        if (empty($order['assigned_to'])) throw new DomainException('Assign a technician before handover.');
        $handover = rl_handover_payment($pdo, $orderId, true);
        if (!$handover['allowed']) throw new DomainException($handover['message']);
        $pdo->prepare("UPDATE repair_orders SET status = 'delivered', updated_at = NOW() WHERE id = ? AND status = 'completed'")
            ->execute([$orderId]);
        $pdo->prepare('INSERT INTO order_status_history (order_id, created_by, from_status, to_status, note) VALUES (?, ?, ?, ?, ?)')
            ->execute([$orderId, (int)$staff['id'], 'completed', 'delivered', 'Admin confirmed vehicle handover after payment.']);
        $pdo->commit();
        $_SESSION['order_approval_notice'][$orderId] = 'Vehicle handover confirmed and recorded in the status history.';
    } catch (DomainException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['order_approval_error'][$orderId] = $e->getMessage();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Vehicle handover failed: ' . $e->getMessage());
        $_SESSION['order_approval_error'][$orderId] = 'Unable to confirm vehicle handover. Review the server log.';
    }
    header('Location: ../../admin/orders/detail.php?id=' . $orderId, true, 303);
    exit;
}

// Hàm tính lại tổng tiền phiếu sửa chữa
function lockRepairOrder(PDO $pdo, int $orderId): void {
    $stmt = $pdo->prepare('SELECT id, status FROM repair_orders WHERE id = ? FOR UPDATE');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) throw new RuntimeException('Repair order not found.');
    if (in_array($order['status'], ['completed', 'delivered', 'cancelled'], true)) {
        throw new DomainException('Items on a completed or delivered work order cannot be modified. Ask Admin to reopen the order first.');
    }
    $invoice = $pdo->prepare("SELECT id FROM invoices WHERE order_id = ? AND payment_status <> 'cancelled' LIMIT 1");
    $invoice->execute([$orderId]);
    if ($invoice->fetchColumn()) {
        throw new DomainException('Items cannot be modified after an invoice has been issued.');
    }
}

function recalculateOrderTotal($pdo,$orderId) {
    $stmtSum =$pdo->prepare("SELECT SUM(subtotal) FROM repair_order_items WHERE repair_order_id = ?");
    $stmtSum->execute([$orderId]);
    $total = (float)$stmtSum->fetchColumn();

    $stmtUp =$pdo->prepare("UPDATE repair_orders SET total_amount = ?, final_amount = total_amount - discount_amount WHERE id = ?");
    $stmtUp->execute([$total,$orderId]);
}

http_response_code(400);
exit('Unsupported repair order operation.');
