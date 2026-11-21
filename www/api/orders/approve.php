<?php
declare(strict_types=1);

header('X-REDLINE-Assignment-Build: 20261121-shift-required');
header('X-REDLINE-Progress-Build: 20261120-p1');
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/technician_assignment_policy.php';
require_once __DIR__ . '/../../includes/repair_work_sessions.php';

// Embedded Repair Order Detail submits asynchronously so the result cannot
// disappear when the appointments inspector reloads its iframe. Plain forms
// still use POST/Redirect/GET (including embed=1).
$isJsonRequest = stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
function rl_assignment_reply(int $orderId, bool $ok, string $message, ?array $technician = null, int $errorStatus = 422): never
{
    if ($GLOBALS['isJsonRequest']) {
        http_response_code($ok ? 200 : $errorStatus);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        echo json_encode([
            'ok' => $ok, 'message' => $message, 'repair_order_id' => $orderId,
            'technician' => $technician,
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
    $_SESSION[$ok ? 'order_approval_notice' : 'order_approval_error'][$orderId] = $message;
    $embed = ($_POST['embed'] ?? '') === '1' ? '&embed=1' : '';
    header('Location: ../../admin/orders/detail.php?id=' . $orderId . $embed . '#technician-assignment', true, 303);
    exit;
}

function rl_assignment_reject(string $message, int $status): never
{
    if ($GLOBALS['isJsonRequest']) {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
    http_response_code($status);
    exit($message);
}

$staff = current_staff();
if (!$staff || $staff['role_name'] !== 'Admin') {
    rl_assignment_reject('Only an administrator can approve and send repair orders.', 403);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    rl_assignment_reject('Use the approval form on the repair order.', 405);
}
if (!csrf_valid($_POST['csrf'] ?? null)) {
    rl_assignment_reject('Your session expired. Reload the order and try again.', 403);
}
$orderId = filter_var($_POST['repair_order_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$employeeId = filter_var($_POST['employee_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if (!$orderId || !$employeeId) {
    rl_assignment_reject('Choose a repair order and an active technician.', 422);
}

$action = (string) ($_POST['assignment_action'] ?? 'approve');
$reason = trim((string) ($_POST['reason'] ?? ''));
if (!in_array($action, ['approve', 'reassign'], true) || ($action === 'reassign' && (mb_strlen($reason) < 8 || mb_strlen($reason) > 500))) {
    rl_assignment_reject('Reassignment requires a reason of 8–500 characters.', 422);
}
$pdo = db();
$success = false;
$successTechnician = null;
$resultMessage = '';
$resultHttpStatus = 422;
try {
    // Do not attempt a partial assignment when the session/billing migration
    // has not been installed: this must either be assigned and visible to the
    // technician with an auditable work session, or roll back completely.
    $columnCheck = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    foreach ([['repair_order_work_sessions', 'id'], ['repair_order_items', 'work_session_id'], ['part_requests', 'work_session_id']] as [$table, $column]) {
        $columnCheck->execute([$table, $column]);
        if (!(int)$columnCheck->fetchColumn()) {
            throw new DomainException('The technician handover database migration is missing. Import www/database/migrations/20261119_technician_work_sessions.sql into the active database, then retry.');
        }
    }
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT id, status, assigned_to, service_request_id FROM repair_orders WHERE id = ? FOR UPDATE');
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$order) throw new DomainException('Repair order not found.');
    $oldEmployeeId = (int) ($order['assigned_to'] ?? 0);
    if (array_key_exists('expected_assigned_to', $_POST)) {
        $expectedEmployeeId = filter_var($_POST['expected_assigned_to'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        if ($expectedEmployeeId === false || $expectedEmployeeId !== $oldEmployeeId) {
            throw new DomainException('The assigned technician has changed. Reload the repair order before assigning again.');
        }
    }
    if ($action === 'approve' && $oldEmployeeId !== 0) throw new DomainException('This order is already assigned. Use Reassign Technician instead.');
    if ($action === 'reassign' && $oldEmployeeId === 0) throw new DomainException('This order has not been assigned yet.');
    if ($action === 'reassign' && $oldEmployeeId === (int) $employeeId) throw new DomainException('Choose a different technician for reassignment.');
    if (in_array($order['status'], ['delivered', 'cancelled'], true)
        || ($action === 'approve' && $order['status'] === 'completed')) {
        throw new DomainException('Closed orders cannot be sent to an employee.');
    }
    $invoice = $pdo->prepare("SELECT id FROM invoices WHERE order_id = ? AND payment_status <> 'cancelled' LIMIT 1");
    $invoice->execute([$orderId]);
    if ($invoice->fetchColumn()) throw new DomainException('An invoice already exists. Resolve billing before changing the technician.');
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
    // Mandatory server-side check: do not allow form tampering or an exception
    // reason to assign a technician without an approved shift today.
    $shiftBlocker = rl_assignment_shift_blocker($readiness);
    if ($shiftBlocker !== '') {
        throw new DomainException($shiftBlocker);
    }
    $reasonLength = mb_strlen($reason);
    if ($reasonLength > 500) {
        throw new DomainException('Assignment reason must be 500 characters or fewer.');
    }
    $blockingWarnings = rl_assignment_blocking_warnings($readiness, $action === 'approve');
    if ($blockingWarnings && $reasonLength < 8) {
        throw new DomainException('This technician needs an exception reason (8–500 characters): ' . implode(' ', $blockingWarnings));
    }
    $exceptionNote = [];
    if ($readiness['warnings']) $exceptionNote[] = implode(' ', $readiness['warnings']);
    if ($reason !== '') $exceptionNote[] = 'Admin reason: ' . $reason;
    $auditSuffix = $exceptionNote ? ' | ' . implode(' | ', $exceptionNote) : '';

    if ($action === 'reassign') {
        $pending = $pdo->prepare("SELECT id FROM part_requests WHERE order_id = ? AND status IN ('pending', 'approved') LIMIT 1 FOR UPDATE");
        $pending->execute([$orderId]);
        if ($pending->fetchColumn()) {
            throw new DomainException('Resolve pending or approved parts requests before handing this repair to another technician.');
        }
        $previousSession = rl_work_session_current($pdo, $orderId);
        if (!$previousSession) {
            // Capture pre-upgrade work before opening a separate session for the next technician.
            rl_work_session_require_active($pdo, $order, $oldEmployeeId);
            $previousSession = rl_work_session_current($pdo, $orderId);
        }
        if ((int)$previousSession['technician_id'] !== $oldEmployeeId) {
            throw new DomainException('The work session does not match the assigned technician. Reload and review the repair order.');
        }
        if (empty($previousSession['completed_at'])) {
            rl_work_session_close($pdo, $order, $oldEmployeeId,
                $order['status'] === 'completed' ? 'completed' : 'reassigned', $reason);
        }
        // A fresh review is required after more work is commissioned, even if prices stay the same.
        $pdo->prepare('DELETE FROM repair_order_cost_reviews WHERE order_id = ?')->execute([$orderId]);
    }
    rl_work_session_start($pdo, $order, (int)$employeeId, (int)$staff['id']);
    // Repair progress belongs to the ORDER, not to the technician's work session.
    // Keep the last saved stage for every ordinary handover; only reopening a truly
    // completed repair requires a new final-inspection step.
    $nextStatus = $action === 'reassign'
        ? rl_status_after_technician_change((string)$order['status'])
        : (string)$order['status'];
    // Employee workspaces and APIs use assigned_to as their access boundary.
    $pdo->prepare('UPDATE repair_orders SET assigned_to = ?, technician_id = ?, status = ?, updated_at = NOW() WHERE id = ?')
        ->execute([$employeeId, $employeeId, $nextStatus, $orderId]);
    $pdo->prepare('INSERT INTO order_status_history (order_id, created_by, from_status, to_status, note) VALUES (?, ?, ?, ?, ?)')
        ->execute([$orderId, $staff['id'], $order['status'], $nextStatus, ($action === 'reassign' ? 'Admin reassigned technician #' . $oldEmployeeId . ' -> #' . $employeeId . ': ' . $reason : ($customerPreferenceUsed ? 'Admin approved customer-selected technician: ' : 'Admin assigned technician (no active customer preference): ') . $employee['full_name'] . ' (#' . $employeeId . ')') . $auditSuffix]);
    // Verify the exact access key used by Technician Workspace before commit.
    $visible = $pdo->prepare('SELECT ro.assigned_to, ro.order_code FROM repair_orders ro WHERE ro.id = ? AND ro.assigned_to = ?');
    $visible->execute([$orderId, $employeeId]);
    $verifiedOrder = $visible->fetch(PDO::FETCH_ASSOC);
    if (!$verifiedOrder) throw new RuntimeException('The technician assignment could not be verified.');
    $pdo->commit();
    $success = true;
    $successTechnician = ['id' => (int)$employeeId, 'name' => (string)$employee['full_name']];
    $resultMessage = ($action === 'reassign' ? 'Transferred successfully to ' : 'Approved and sent successfully to ')
        . $employee['full_name'] . '. The order is available in their Technician Workspace → Repair Orders.'
        . ($action === 'reassign' ? ' Saved repair progress and previous technicians’ work are retained.' : '');
} catch (DomainException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $resultMessage = $e->getMessage();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('Repair order approval failed for #' . $orderId . ': ' . $e->getMessage());
    $resultMessage = 'Could not assign the technician. Check the active database migration and server error log, then retry.';
    $resultHttpStatus = 500;
}
rl_assignment_reply((int)$orderId, $success, $resultMessage, $successTechnician, $resultHttpStatus);
