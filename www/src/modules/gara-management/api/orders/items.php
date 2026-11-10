<?php
// www/src/modules/gara-management/api/orders/items.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../includes/auth.php';
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
    header('Location: ../../../../../repair_orders.php');
    exit;
}

if (in_array($action, ['add', 'delete'], true)) {
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
        error_log('Repair item save: ' . $e->getMessage());
        http_response_code(500);
        exit('The item could not be saved. Reload the order and try again.');
    }
    header("Location: ../../../../../repair_orders_detail.php?id={$orderId}");
    exit;
}

// 2. XÓA HẠNG MỤC
if ($action === 'delete') {
    $itemId = (int)($_POST['item_id'] ?? 0);
    try {
        $pdo->beginTransaction();
        lockRepairOrder($pdo, $orderId);
        $stmt =$pdo->prepare("DELETE FROM repair_order_items WHERE id = ? AND repair_order_id = ?");
        $stmt->execute([$itemId,$orderId]);

        recalculateOrderTotal($pdo,$orderId);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('Repair item removal: ' . $e->getMessage());
        http_response_code(500);
        exit('The item could not be removed. Reload the order and try again.');
    }
    header("Location: ../../../../../repair_orders_detail.php?id={$orderId}");
    exit;
}

// 3. CẬP NHẬT TRẠNG THÁI PHIẾU & TRỪ KHO TỰ ĐỘNG
if ($action === 'update_status') {
    $newStatus = trim((string)($_POST['status'] ?? 'in_progress'));
    
    // Kiểm tra trạng thái cũ để tránh trừ kho 2 lần
    $stmtOld =$pdo->prepare("SELECT status, is_stock_deducted FROM repair_orders WHERE id = ?");
    $stmtOld->execute([$orderId]);
    $roData =$stmtOld->fetch(PDO::FETCH_ASSOC);

    if ($newStatus === 'completed' && $roData && (int)$roData['is_stock_deducted'] === 0) {
        // Lấy danh sách phụ tùng đã dùng trong phiếu
        $stmtParts =$pdo->prepare("SELECT item_name, quantity FROM repair_order_items WHERE repair_order_id = ? AND item_type = 'part'");
        $stmtParts->execute([$orderId]);
        $usedParts =$stmtParts->fetchAll(PDO::FETCH_ASSOC);

        foreach ($usedParts as$p) {
            $stmtDeduct =$pdo->prepare("UPDATE parts SET stock_quantity = GREATEST(0, stock_quantity - ?) WHERE name = ?");
            $stmtDeduct->execute([$p['quantity'],$p['item_name']]);
        }

        $pdo->prepare("UPDATE repair_orders SET is_stock_deducted = 1 WHERE id = ?")->execute([$orderId]);
    }

    $stmt =$pdo->prepare("UPDATE repair_orders SET status = ?, updated_at = NOW() WHERE id = ?");
    $stmt->execute([$newStatus,$orderId]);

    header("Location: ../../../../../repair_orders_detail.php?id={$orderId}");
    exit;
}

// Hàm tính lại tổng tiền phiếu sửa chữa
function lockRepairOrder(PDO $pdo, int $orderId): void {
    $stmt = $pdo->prepare('SELECT id FROM repair_orders WHERE id = ? FOR UPDATE');
    $stmt->execute([$orderId]);
    if (!$stmt->fetchColumn()) throw new RuntimeException('Repair order not found.');
}

function recalculateOrderTotal($pdo,$orderId) {
    $stmtSum =$pdo->prepare("SELECT SUM(subtotal) FROM repair_order_items WHERE repair_order_id = ?");
    $stmtSum->execute([$orderId]);
    $total = (float)$stmtSum->fetchColumn();

    $stmtUp =$pdo->prepare("UPDATE repair_orders SET total_amount = ?, final_amount = total_amount - discount_amount WHERE id = ?");
    $stmtUp->execute([$total,$orderId]);
}
