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

// 1. THÊM HẠNG MỤC (TIỀN CÔNG HOẶC PHỤ TÙNG TỪ KHO)
if ($action === 'add') {
    $itemType  = trim((string)($_POST['item_type'] ?? 'labor'));
    $partId    = (int)($_POST['part_id'] ?? 0);
    $itemName  = trim((string)($_POST['item_name'] ?? ''));
    $unitPrice = (float)($_POST['unit_price'] ?? 0);
    $quantity  = (int)($_POST['quantity'] ?? 1);

    // Nếu chọn từ danh mục phụ tùng trong kho
    if ($itemType === 'part' &&$partId > 0) {
        $stmtP =$pdo->prepare("SELECT name, selling_price FROM parts WHERE id = ? LIMIT 1");
        $stmtP->execute([$partId]);
        $part =$stmtP->fetch(PDO::FETCH_ASSOC);
        if ($part) {
            $itemName =$part['name'];
            if ($unitPrice <= 0) {
                $unitPrice = (float)$part['selling_price'];
            }
        }
    }

    if (!empty($itemName) && $quantity > 0) {$subtotal = $unitPrice * $quantity;

        $stmt =$pdo->prepare("
            INSERT INTO repair_order_items (repair_order_id, item_type, item_name, unit_price, quantity, subtotal)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$orderId,$itemType, $itemName,$unitPrice, $quantity,$subtotal]);

        recalculateOrderTotal($pdo,$orderId);
    }
    header("Location: ../../../../../repair_order_detail.php?id={$orderId}");
    exit;
}

// 2. XÓA HẠNG MỤC
if ($action === 'delete') {
    $itemId = (int)($_GET['item_id'] ?? 0);
    if ($itemId > 0) {
        $stmt =$pdo->prepare("DELETE FROM repair_order_items WHERE id = ? AND repair_order_id = ?");
        $stmt->execute([$itemId,$orderId]);

        recalculateOrderTotal($pdo,$orderId);
    }
    header("Location: ../../../../../repair_order_detail.php?id={$orderId}");
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

    header("Location: ../../../../../repair_order_detail.php?id={$orderId}");
    exit;
}

// Hàm tính lại tổng tiền phiếu sửa chữa
function recalculateOrderTotal($pdo,$orderId) {
    $stmtSum =$pdo->prepare("SELECT SUM(subtotal) FROM repair_order_items WHERE repair_order_id = ?");
    $stmtSum->execute([$orderId]);
    $total = (float)$stmtSum->fetchColumn();

    $stmtUp =$pdo->prepare("UPDATE repair_orders SET total_amount = ?, final_amount = total_amount - discount_amount WHERE id = ?");
    $stmtUp->execute([$total,$orderId]);
} 