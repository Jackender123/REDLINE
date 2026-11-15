<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/technician_assignment_policy.php';

$staff = current_staff();
if (!$staff || $staff['role_name'] !== 'Admin') {
    http_response_code(403);
    exit('Only an administrator can approve and send repair orders.');
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Use the approval form on the repair order.');
}
if (!csrf_valid($_POST['csrf'] ?? null)) {
    http_response_code(403);
    exit('Your session expired. Reload the order and try again.');
}
$orderId = filter_var($_POST['repair_order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$employeeId = filter_var($_POST['employee_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$orderId || !$employeeId) {
    http_response_code(422);
    exit('Choose a repair order and an active technician.');
}

$action = (string) ($_POST['assignment_action'] ?? 'approve');
$reason = trim((string) ($_POST['reason'] ?? ''));
if (!in_array($action, ['approve', 'reassign'], true) || ($action === 'reassign' && (mb_strlen($reason) < 8 || mb_strlen($reason) > 500))) {
    http_response_code(422);
    exit('Reassignment requires a reason of 8–500 characters.');
}
$pdo = db();
try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT id, status, assigned_to, service_request_id FROM repair_orders WHERE id = ? FOR UPDATE');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) throw new DomainException('Repair order not found.');
    $oldEmployeeId = (int) ($order['assigned_to'] ?? 0);
    if ($action === 'approve' && $oldEmployeeId !== 0) throw new DomainException('This order is already assigned. Use Reassign Technician instead.');
    if ($action === 'reassign' && $oldEmployeeId === 0) throw new DomainException('This order has not been assigned yet.');
    if ($action === 'reassign' && $oldEmployeeId === (int) $employeeId) throw new DomainException('Choose a different technician for reassignment.');
    if (in_array($order['status'], ['completed', 'delivered', 'cancelled'], true)) {
        throw new DomainException('Completed orders cannot be sent to an employee.');
    }
    $customerPreferenceUsed = false;
    if (!empty($order['service_request_id'])) {
        $stmt = $pdo->prepare('SELECT * FROM service_requests WHERE id = ? FOR UPDATE');
        $stmt->execute([$order['service_request_id']]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$request || $request['status'] === 'cancelled') {
            throw new DomainException('The linked appointment is missing or cancelled.');
        }
        $preferredId = (int)($request['preferred_technician_id'] ?? 0);
        if ($action === 'approve' && $preferredId > 0) {
            $prefStmt = $pdo->prepare("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND u.is_active = 1 AND r.name = 'Technician' LIMIT 1");
            $prefStmt->execute([$preferredId]);
            if ($prefStmt->fetchColumn()) {
                if ((int)$employeeId !== $preferredId && mb_strlen($reason) < 8) {
                    throw new DomainException('Changing the customer-preferred technician requires a reason of at least 8 characters.');
                }
                $customerPreferenceUsed = (int)$employeeId === $preferredId;
            }
        }
    }
    $stmt = $pdo->prepare("SELECT u.id, u.full_name FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND u.is_active = 1 AND r.name = 'Technician' FOR UPDATE");
    $stmt->execute([$employeeId]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$employee) throw new DomainException('Choose an active technician to receive this order.');

    $readiness = rl_assignment_readiness($pdo, (int)$employeeId, $orderId);
    $reasonLength = mb_strlen($reason);
    if ($reasonLength > 500) {
        throw new DomainException('Assignment reason must be 500 characters or fewer.');
    }
    if ($readiness['warnings'] && $reasonLength < 8) {
        throw new DomainException('This technician needs an exception reason (8–500 characters): ' . implode(' ', $readiness['warnings']));
    }
    $exceptionNote = [];
    if ($readiness['warnings']) $exceptionNote[] = implode(' ', $readiness['warnings']);
    if ($reason !== '') $exceptionNote[] = 'Admin reason: ' . $reason;
    $auditSuffix = $exceptionNote ? ' | ' . implode(' | ', $exceptionNote) : '';

    // Employee workspaces and APIs use assigned_to as their access boundary.
    $pdo->prepare('UPDATE repair_orders SET assigned_to = ?, technician_id = ?, updated_at = NOW() WHERE id = ?')
        ->execute([$employeeId, $employeeId, $orderId]);
    $pdo->prepare('INSERT INTO order_status_history (order_id, created_by, from_status, to_status, note) VALUES (?, ?, ?, ?, ?)')
        ->execute([$orderId, $staff['id'], $order['status'], $order['status'], ($action === 'reassign' ? 'Admin reassigned technician #' . $oldEmployeeId . ' -> #' . $employeeId . ': ' . $reason : ($customerPreferenceUsed ? 'Admin approved customer-selected technician: ' : 'Admin assigned technician (no active customer preference): ') . $employee['full_name'] . ' (#' . $employeeId . ')') . $auditSuffix]);
    $pdo->commit();
    $_SESSION['order_approval_notice'][$orderId] = ($action === 'reassign' ? 'Reassigned to ' : 'Approved and sent to ') . $employee['full_name'] . '.';
} catch (DomainException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $_SESSION['order_approval_error'][$orderId] = $e->getMessage();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Repair order approval failed: ' . $e->getMessage());
    $_SESSION['order_approval_error'][$orderId] = 'The order could not be sent. Reload the order and try again.';
}
header('Location: ../../admin/orders/detail.php?id=' . $orderId, true, 303);
exit;
