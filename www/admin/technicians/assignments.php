<?php
/** REDLINE Motorsport – Technician Assignments Console (Admin). */
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/repair_work_sessions.php';
$staff = current_staff();
if (!$staff || ($staff['role_name'] ?? '') !== 'Admin') {
    header('Location: ../../auth/login.php');
    exit;
}
// Deployment marker: verify this value in the page source / response headers after upload.
header('X-REDLINE-Technician-UI: split-workspace-shift-ajax-20261120');
$pdo = db();
function ta_e($value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

// Create employee accounts here instead of keeping a separate Staff & Team page.
// The form and POST handler intentionally remain restricted to administrators.
function ta_staff_username(string $value): string
{
    $value = strtr(trim($value), ['Đ' => 'D', 'đ' => 'd']);
    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
        if ($ascii !== false) $value = $ascii;
    }
    $value = strtolower($value);
    $value = preg_replace('/\s+/u', '.', $value) ?? $value;
    $value = preg_replace('/[^a-z0-9._-]+/', '', $value) ?? '';
    return trim($value, '._-');
}
$createEmployeeError = '';
$createEmployeeNotice = (string)($_SESSION['redline_employee_created_notice'] ?? '');
unset($_SESSION['redline_employee_created_notice']);
$showCreateEmployee = isset($_GET['create_employee']) && $_GET['create_employee'] === '1';
$employeeCsrf = csrf_token();

// Technician part requests are now managed INSIDE Technician Workload (Admin only).
// Keep this audit method local to the page: Inventory still uses its own stock actions.
function ta_stock_record(PDO $pdo, int $partId, int $userId, string $direction, int $quantity, string $note): void
{
    if ($partId <= 0 || $userId <= 0 || $quantity <= 0 || !in_array($direction, ['import', 'export'], true)) {
        throw new InvalidArgumentException('Invalid stock movement.');
    }
    $stmt = $pdo->prepare('INSERT INTO inventory_transactions (part_id, user_id, type, quantity, note) VALUES (:part_id, :user_id, :type, :qty, :note)');
    $stmt->execute(['part_id' => $partId, 'user_id' => $userId, 'type' => $direction, 'qty' => $quantity, 'note' => mb_substr($note, 0, 255)]);
}
// Shift registration decisions inside this workspace: same authorization, CSRF,
// overlap and attendance rules as Shifts & Payroll. JSON for no-reload actions.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'shift_decision') {
    $ajax = stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
        || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
    $shiftError = '';
    $shiftResult = null;
    try {
        if (!csrf_valid($_POST['csrf'] ?? null)) throw new RuntimeException('Session expired. Refresh the page and try again.');
        $shiftId = filter_var($_POST['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        $decision = (string)($_POST['decision'] ?? '');
        if (!$shiftId || !in_array($decision, ['approve','reject','present','absent'], true)) {
            throw new RuntimeException('Invalid shift or decision.');
        }
        $pdo->beginTransaction();
        $lookup = $pdo->prepare('SELECT technician_id FROM technician_shifts WHERE id = ?');
        $lookup->execute([$shiftId]);
        $techId = (int)$lookup->fetchColumn();
        if (!$techId) throw new RuntimeException('Shift request not found.');
        // Lock technician first to serialize concurrent approvals for the same date.
        $lockTech = $pdo->prepare('SELECT u.id, u.is_active FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND r.name=\'Technician\' FOR UPDATE');
        $lockTech->execute([$techId]);
        $techRow = $lockTech->fetch(PDO::FETCH_ASSOC);
        if (!$techRow) throw new RuntimeException('Technician account no longer exists.');
        $stmt = $pdo->prepare('SELECT * FROM technician_shifts WHERE id=? FOR UPDATE');
        $stmt->execute([$shiftId]);
        $shift = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$shift || (int)$shift['technician_id'] !== $techId) throw new RuntimeException('Shift changed. Refresh this page.');
        if ($decision === 'approve') {
            if (!(int)$techRow['is_active']) throw new RuntimeException('Only active technicians can be approved.');
            if ($shift['status'] !== 'pending') throw new RuntimeException('Only pending shifts can be approved.');
            $conflict = $pdo->prepare("SELECT COUNT(*) FROM technician_shifts WHERE technician_id=? AND work_date=? AND id<>? AND status='approved' AND (shift_type='full' OR ?='full' OR shift_type=?)");
            $conflict->execute([$techId, $shift['work_date'], $shiftId, $shift['shift_type'], $shift['shift_type']]);
            if ((int)$conflict->fetchColumn()) throw new RuntimeException('An overlapping approved shift already exists.');
            $pdo->prepare("UPDATE technician_shifts SET status='approved', reviewed_by=?, reviewed_at=NOW() WHERE id=?")
                ->execute([(int)$staff['id'], $shiftId]);
            $shift['status'] = 'approved';
        } elseif ($decision === 'reject') {
            if ($shift['status'] !== 'pending') throw new RuntimeException('Only pending shifts can be rejected.');
            $pdo->prepare("UPDATE technician_shifts SET status='rejected', reviewed_by=?, reviewed_at=NOW() WHERE id=?")
                ->execute([(int)$staff['id'], $shiftId]);
            $shift['status'] = 'rejected';
        } else {
            if ($shift['status'] !== 'approved' || $shift['work_date'] > date('Y-m-d')) {
                throw new RuntimeException('Attendance requires an approved shift on or before today.');
            }
            $pdo->prepare('UPDATE technician_shifts SET attendance=? WHERE id=?')->execute([$decision, $shiftId]);
            $shift['attendance'] = $decision;
        }
        $pdo->commit();
        $shiftResult = [
            'ok' => true,
            'message' => 'Shift updated successfully.',
            'id' => (int)$shiftId,
            'status' => (string)$shift['status'],
            'attendance' => (string)($shift['attendance'] ?? 'unconfirmed'),
            'pending_count' => (int)$pdo->query("SELECT COUNT(*) FROM technician_shifts WHERE status='pending'")->fetchColumn(),
        ];
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $shiftError = $exception instanceof RuntimeException ? $exception->getMessage() : 'Unable to process shift. Check the database and server log.';
        if (!($exception instanceof RuntimeException)) error_log('Technician shift approval error: '.$exception->getMessage());
    }
    if ($ajax) {
        http_response_code($shiftResult ? 200 : 422);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store, private');
        echo json_encode($shiftResult ?: ['ok'=>false,'message'=>$shiftError], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }
    $_SESSION['redline_shift_action_notice'] = $shiftResult ? $shiftResult['message'] : $shiftError;
    $_SESSION['redline_shift_action_error'] = !$shiftResult;
    header('Location: assignments.php#shift-approval', true, 303);
    exit;
}

$selectedTechnician = filter_var($_GET['technician_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
$partRequestError = '';
$partRequestNotice = (string)($_SESSION['redline_part_request_notice'] ?? '');
unset($_SESSION['redline_part_request_notice']);
$showPartRequests = ($_GET['requests'] ?? '') === '1';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'part_request_decision') {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $partRequestError = 'Your session is invalid. Please refresh the page.';
    } else {
        $requestId = (int)($_POST['request_id'] ?? 0);
        $decision = (string)($_POST['decision'] ?? '');
        try {
            if ($requestId <= 0 || !in_array($decision, ['approved', 'rejected', 'fulfilled'], true)) {
                throw new RuntimeException('Invalid part request or status.');
            }
            // Lock ordering: repair order -> request -> part. Avoid races with completion and item changes.
            $lookup = $pdo->prepare('SELECT order_id FROM part_requests WHERE id = ?');
            $lookup->execute([$requestId]);
            $requestOrderId = (int) $lookup->fetchColumn();
            if ($requestOrderId <= 0) throw new RuntimeException('Part request not found.');
            $pdo->beginTransaction();
            $orderStmt = $pdo->prepare('SELECT id, status, assigned_to FROM repair_orders WHERE id = ? FOR UPDATE');
            $orderStmt->execute([$requestOrderId]);
            $order = $orderStmt->fetch(PDO::FETCH_ASSOC);
            if (!$order || empty($order['assigned_to']) || in_array($order['status'], ['completed', 'delivered', 'cancelled'], true)) {
                throw new RuntimeException('This work order must be assigned and active before parts can be approved or issued.');
            }
            $invoiceCheck = $pdo->prepare("SELECT id FROM invoices WHERE order_id = ? AND payment_status <> 'cancelled' LIMIT 1");
            $invoiceCheck->execute([$requestOrderId]);
            if ($invoiceCheck->fetchColumn()) throw new RuntimeException('This work order has already been invoiced.');
            $stmt = $pdo->prepare('SELECT * FROM part_requests WHERE id = :id FOR UPDATE');
            $stmt->execute(['id' => $requestId]);
            $request = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$request || (int)$request['order_id'] !== $requestOrderId) {
                throw new RuntimeException('Part request changed. Please reload the page.');
            }
            $requestQty = (int) $request['quantity'];
            if ($requestQty <= 0 || $requestQty > 1000000) throw new RuntimeException('Invalid requested quantity.');
            if ($decision !== 'rejected') {
                // Requests retain the technician's session even when assignment changes later.
                $workSessionId = rl_work_session_require_active($pdo, $order, (int)$request['requested_by']);
                $sessionStmt = $pdo->prepare('SELECT work_session_id FROM part_requests WHERE id = ?');
                $sessionStmt->execute([$requestId]);
                if ((int)$sessionStmt->fetchColumn() !== $workSessionId) {
                    throw new RuntimeException('This request belongs to a previous technician work session. Reject it and ask the current technician to submit a new request.');
                }
            }
            if ($decision === 'fulfilled') {
                if ($request['status'] !== 'approved') throw new RuntimeException('Only approved requests can be fulfilled.');
                // Missing migration must fail BEFORE stock is changed.
                $schemaStmt = $pdo->query("SHOW COLUMNS FROM repair_order_items LIKE 'part_request_id'");
                if (!$schemaStmt->fetch(PDO::FETCH_ASSOC)) {
                    throw new RuntimeException('Phase 2C database migration is required before fulfilling parts.');
                }
                $partStmt = $pdo->prepare('SELECT id, name, stock_quantity, selling_price, is_active FROM parts WHERE id = :id FOR UPDATE');
                $partStmt->execute(['id' => $request['part_id']]);
                $part = $partStmt->fetch(PDO::FETCH_ASSOC);
                if (!$part || !(int)$part['is_active'] || (int)$part['stock_quantity'] < $requestQty) {
                    throw new RuntimeException('The selected part is unavailable or stock is insufficient.');
                }
                $unitPrice = (float)$part['selling_price'];
                if (!is_finite($unitPrice) || $unitPrice < 0 || $unitPrice * $requestQty > 9999999999999.99) {
                    throw new RuntimeException('The part price or line total is invalid.');
                }
                // UNIQUE(part_request_id) prevents recording the same fulfillment twice.
                $insertItem = $pdo->prepare("INSERT INTO repair_order_items
                    (repair_order_id, item_type, item_name, unit_price, quantity, subtotal, part_request_id, work_session_id)
                    VALUES (:order_id, 'part', :name, :price, :qty, :subtotal, :request_id, :work_session_id)");
                $insertItem->execute([
                    'order_id' => $requestOrderId,
                    'name' => $part['name'],
                    'price' => $unitPrice,
                    'qty' => $requestQty,
                    'subtotal' => round($unitPrice * $requestQty, 2),
                    'request_id' => $requestId,
                    'work_session_id' => $workSessionId,
                ]);
                $updateStock = $pdo->prepare('UPDATE parts SET stock_quantity = stock_quantity - :qty
                    WHERE id = :id AND stock_quantity >= :required');
                $updateStock->execute(['qty' => $requestQty, 'id' => $part['id'], 'required' => $requestQty]);
                if ($updateStock->rowCount() !== 1) throw new RuntimeException('Stock changed during fulfillment. Reload and retry.');
                ta_stock_record($pdo, (int)$part['id'], (int)$staff['id'], 'export', $requestQty,
                    'Repair order #' . $requestOrderId . ' / request ' . $request['request_code']);
                $totalStmt = $pdo->prepare('SELECT COALESCE(SUM(subtotal),0) FROM repair_order_items WHERE repair_order_id = ?');
                $totalStmt->execute([$requestOrderId]);
                $total = (float) $totalStmt->fetchColumn();
                $pdo->prepare('UPDATE repair_orders SET total_amount = ?, final_amount = GREATEST(0, ? - COALESCE(discount_amount,0)), updated_at = NOW() WHERE id = ?')
                    ->execute([$total, $total, $requestOrderId]);
            } elseif ($request['status'] !== 'pending' && !($decision === 'rejected' && $request['status'] === 'approved')) {
                throw new RuntimeException('Only pending requests may be approved; pending or unissued approved requests may be rejected.');
            }
            $change = $pdo->prepare('UPDATE part_requests SET status = :status, decided_by = :user_id, updated_at = NOW()
                 WHERE id = :id AND status = :old_status');
            $change->execute([
                'status' => $decision,
                'user_id' => (int)$staff['id'],
                'id' => $requestId,
                'old_status' => $request['status'],
            ]);
            if ($change->rowCount() !== 1) throw new RuntimeException('This request has already been processed.');
            $pdo->commit();
            $partRequestNotice = $decision === 'approved' ? 'Part request approved.'
                : ($decision === 'rejected' ? 'Part request rejected.' : 'Part issued, stock movement recorded, and repair order total updated.');
            $_SESSION['redline_part_request_notice'] = $partRequestNotice;
            $returnUrl = 'assignments.php?requests=1';
            if ($selectedTechnician > 0) $returnUrl .= '&technician_id=' . $selectedTechnician;
            header('Location: ' . $returnUrl . '#technician-requests', true, 303);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof RuntimeException) {
                $partRequestError = $e->getMessage();
            } else {
                error_log('Part request processing failed: ' . $e->getMessage());
                $partRequestError = 'Unable to update the part request due to a server error.';
            }
        }
    }
}


if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_staff') {
    $showCreateEmployee = true;
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $createEmployeeError = 'Your session has expired. Please reload and try again.';
    } else {
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $username = ta_staff_username((string)($_POST['username'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));
        $phone = trim((string)($_POST['phone'] ?? ''));
        $roleName = trim((string)($_POST['role_name'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $allowedRoles = ['Receptionist', 'Technician', 'Cashier'];
        if ($fullName === '' || $username === '' || $email === '' || $phone === '' || $password === '') {
            $createEmployeeError = 'Please complete all required fields.';
        } elseif (mb_strlen($fullName) > 100 || strlen($username) > 50 || mb_strlen($email) > 100 || mb_strlen($phone) > 15) {
            $createEmployeeError = 'One or more fields exceed the maximum allowed length.';
        } elseif (!preg_match('/^[A-Za-z0-9._-]+$/D', $username)) {
            $createEmployeeError = 'Enter a valid username using letters, numbers, dots, underscores or hyphens.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $createEmployeeError = 'Please enter a valid login email address.';
        } elseif (strlen($password) < 8) {
            $createEmployeeError = 'The initial password must be at least 8 characters long.';
        } elseif (!in_array($roleName, $allowedRoles, true)) {
            $createEmployeeError = 'Please select a valid staff role.';
        } else {
            try {
                $stmtRole = $pdo->prepare('SELECT id FROM roles WHERE name = :name LIMIT 1');
                $stmtRole->execute(['name' => $roleName]);
                $roleId = $stmtRole->fetchColumn();
                if (!$roleId) throw new RuntimeException('Role not configured in the database.');
                $stmtIns = $pdo->prepare(
                    'INSERT INTO users (role_id, username, password_hash, full_name, email, phone, is_active, is_customer)
                     VALUES (:role_id, :username, :password, :full_name, :email, :phone, 1, 0)'
                );
                $stmtIns->execute([
                    'role_id' => $roleId,
                    'username' => $username,
                    'password' => password_hash($password, PASSWORD_DEFAULT),
                    'full_name' => $fullName,
                    'email' => $email,
                    'phone' => $phone,
                ]);
                $_SESSION['redline_employee_created_notice'] = 'Employee account created: ' . $fullName . ' (login: ' . $username . ').';
                // POST / Redirect / GET prevents duplicate employees on refresh.
                header('Location: assignments.php', true, 303);
                exit;
            } catch (PDOException $e) {
                error_log('Employee creation failed: ' . $e->getMessage());
                $createEmployeeError = $e->getCode() === '23000'
                    ? 'That username or email is already in use.'
                    : 'Unable to create the employee account. Please try again.';
            } catch (RuntimeException $e) {
                error_log('Employee role configuration error: ' . $e->getMessage());
                $createEmployeeError = 'The selected staff role is not available. Contact the administrator.';
            }
        }
    }
}
$userName = (string)($staff['full_name'] ?? 'Administrator');
$initials = '';
foreach (array_slice(preg_split('/\s+/u', trim($userName)) ?: [], -2) as $word) {
    if ($word !== '') $initials .= mb_strtoupper(mb_substr($word, 0, 1));
}
if ($initials === '') $initials = 'AD';
$userDisplayRole = 'Administrator';
$techs = []; $orders = []; $loadError = '';

try {
    $techs = $pdo->query("SELECT u.id, u.full_name, u.is_active, u.phone,
        COUNT(ro.id) AS active_jobs,
        COALESCE(SUM(ro.status = 'repairing'),0) AS repairing_jobs,
        COALESCE(SUM(ro.status = 'waiting_parts'),0) AS waiting_parts_jobs,
        COALESCE(SUM(ro.status = 'diagnosing'),0) AS diagnosing_jobs
      FROM users u
      INNER JOIN roles r ON r.id = u.role_id
      LEFT JOIN repair_orders ro ON ro.assigned_to = u.id
        AND ro.status NOT IN ('completed','delivered')
      WHERE r.name = 'Technician'
      GROUP BY u.id, u.full_name, u.is_active, u.phone
      ORDER BY u.is_active DESC, active_jobs ASC, u.full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    // Only filter by a technician known to the staff directory.
    $knownIds = array_map('intval', array_column($techs, 'id'));
    if ($selectedTechnician && !in_array($selectedTechnician, $knownIds, true)) $selectedTechnician = 0;
    $sql = "SELECT ro.id, ro.order_code, ro.status, ro.created_at, ro.expected_delivery,
                   ro.assigned_to, v.license_plate, v.brand, v.model,
                   u.full_name AS technician_name
            FROM repair_orders ro
            LEFT JOIN vehicles v ON v.id = ro.vehicle_id
            LEFT JOIN users u ON u.id = ro.assigned_to
            WHERE ro.status NOT IN ('completed','delivered')";
    // Load a bounded, common dataset; all client-side filters operate on this set.
    $sql .= ' ORDER BY (ro.assigned_to IS NULL) DESC, ro.created_at DESC, ro.id DESC LIMIT 500';
    $stmt = $pdo->query($sql);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
    error_log('Technician assignments load failed: ' . $exception->getMessage());
    $loadError = 'Unable to load technician assignments. Please refresh and try again.';
}
$partRequests = [];
$pendingRequests = 0;
$pendingRequestsByTechnician = [];
$partRequestsLoadError = '';
try {
    // Load the same bounded dataset for all technicians so changing the
    // in-page selector does not trigger a new navigation or lose scroll position.
    $requestSql = 'SELECT pr.*, ro.order_code, p.part_code, p.name AS part_name, p.stock_quantity,
                         requester.full_name AS requester_name
                   FROM part_requests pr
                   JOIN repair_orders ro ON ro.id = pr.order_id
                   JOIN parts p ON p.id = pr.part_id
                   JOIN users requester ON requester.id = pr.requested_by
                   ORDER BY FIELD(pr.status, "pending", "approved", "rejected", "fulfilled"), pr.requested_at DESC LIMIT 200';
    $partRequests = $pdo->query($requestSql)->fetchAll(PDO::FETCH_ASSOC);
    // Counts are calculated from the full table, not the 200 visible records.
    $counts = $pdo->query("SELECT requested_by, COUNT(*) AS total FROM part_requests WHERE status = 'pending' GROUP BY requested_by");
    foreach ($counts->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pendingRequestsByTechnician[(string)(int)$row['requested_by']] = (int)$row['total'];
        $pendingRequests += (int)$row['total'];
    }
} catch (Throwable $exception) {
    error_log('Technician requests load failed: ' . $exception->getMessage());
    $partRequestsLoadError = 'Unable to load part requests. Please refresh and try again.';
}
$partRequestActionUrl = 'assignments.php?requests=1' . ($selectedTechnician > 0 ? '&technician_id=' . $selectedTechnician : '') . '#technician-requests';
$showPartRequests = $showPartRequests || $partRequestError !== '';
$shiftRows = [];
$pendingShiftCount = 0;
$shiftLoadError = '';
$shiftActionNotice = (string)($_SESSION['redline_shift_action_notice'] ?? '');
$shiftActionError = (bool)($_SESSION['redline_shift_action_error'] ?? false);
unset($_SESSION['redline_shift_action_notice'], $_SESSION['redline_shift_action_error']);
try {
    $pendingShiftCount = (int)$pdo->query("SELECT COUNT(*) FROM technician_shifts WHERE status='pending'")->fetchColumn();
    $shiftRows = $pdo->query("SELECT s.id, s.technician_id, s.work_date, s.shift_type, s.status, s.attendance, u.full_name
       FROM technician_shifts s JOIN users u ON u.id=s.technician_id
       ORDER BY FIELD(s.status,'pending','approved','rejected','cancelled'), s.work_date DESC, s.id DESC LIMIT 100")
       ->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $exception) {
    error_log('Technician shifts list load failed: '.$exception->getMessage());
    $shiftLoadError = 'Unable to load shift registrations. Verify the technician_shifts table exists.';
}
$activeTechs = count(array_filter($techs, static fn(array $t): bool => (int)$t['is_active'] === 1));
$openJobCount = array_sum(array_map(static fn(array $t): int => (int)$t['active_jobs'], $techs));
$unassignedCount = count(array_filter($orders, static fn(array $o): bool => !$o['assigned_to']));
$iconMain = [
    ['../index.php','Overview','<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>',false],

    ['assignments.php','Technicians','<circle cx="12" cy="7" r="4"/><path d="M4 21v-2a8 8 0 0116 0v2"/>',true],
    ['../customers/list.php','Customers','<path d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>',false],
    ['../appointments/list.php','Appointments','<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',false],
    ['../invoices/list.php','Invoices','<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>',false],
    ['../products/list.php','Inventory','<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/>',false],
];
$iconSystem = [
    ['../technician_management.php','Shifts &amp; Payroll','<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 11h18"/>'],
    ['../settings/general.php','Settings','<path d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-.426 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><circle cx="12" cy="12" r="3"/>'],
];
$cssFile = __DIR__ . '/../../assets/css/technician_assignments.css';
$cssVersion = is_file($cssFile) ? substr(hash_file('sha256',$cssFile),0,12) : '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="redline-technician-ui" content="split-workspace-shift-ajax-20261120">
<title>Technicians &amp; Assignments | REDLINE Motorsport ERP</title>
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config={theme:{extend:{colors:{red:{DEFAULT:'#EF4057',hover:'#D92F46'},navy:'#0B2E4E'},fontFamily:{display:['"Barlow Condensed"','sans-serif'],sans:['Barlow','sans-serif']}}}};</script>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700&family=Barlow:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../../assets/css/technician_assignments.css?v=<?=ta_e($cssVersion)?>">
</head>
<body class="text-white font-sans antialiased h-screen overflow-hidden flex selection:bg-red selection:text-white tech-page">
<div id="mobile-sidebar-backdrop" onclick="toggleMobileSidebar()" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-40 hidden md:hidden"></div>
<aside id="main-sidebar" class="sidebar w-64 shrink-0 fixed md:relative z-50 h-[calc(100%-2rem)] my-4 ml-4 py-5 px-4 overflow-y-auto transform -translate-x-full md:translate-x-0 transition-transform duration-300 ease-in-out flex flex-col justify-between">
<div class="space-y-7">
<a href="../index.php" class="flex items-center gap-3 px-1 hover:opacity-90 transition">
<span class="w-10 h-10 rounded-xl bg-red flex items-center justify-center shadow-lg shadow-red/30"><svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94z"/></svg></span>
<span class="leading-none"><span class="block font-display text-2xl font-bold tracking-wider text-white">REDLINE</span><span class="block text-[10px] tracking-[.2em] text-white/80 mt-1">GARA MANAGEMENT</span></span>
</a>
<nav class="space-y-6">
<div><div class="text-[11px] font-semibold tracking-[.18em] text-white/75 px-3 mb-2">WORKSPACE</div><div class="space-y-1">
<?php foreach($iconMain as [$href,$label,$navSvg,$active]): ?>
<a href="<?=ta_e($href)?>" class="nav-item<?=$active?' active':''?>" <?=$active?'aria-current="page"':''?>><svg class="w-[18px] h-[18px] shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><?=$navSvg?></svg><span class="flex-1"><?=$label?></span><?php if($active): ?><span class="bg-red/80 text-white text-[10px] font-bold px-2 py-0.5 rounded-full"><?=count($techs)?></span><?php endif;?></a>
<?php endforeach; ?></div></div>
<div><div class="text-[11px] font-semibold tracking-[.18em] text-white/75 px-3 mb-2">SYSTEM</div><div class="space-y-1">
<?php foreach($iconSystem as [$href,$label,$navSvg]): ?><a href="<?=ta_e($href)?>" class="nav-item"><svg class="w-[18px] h-[18px] shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><?=$navSvg?></svg><span><?=$label?></span></a><?php endforeach; ?></div></div>
</nav></div>
<div class="pt-4 mt-6 border-t border-white/10 flex items-center justify-between gap-2">
<div class="flex items-center gap-3 min-w-0"><div class="w-9 h-9 rounded-full bg-white/15 border border-white/20 flex items-center justify-center font-semibold text-white text-xs shrink-0"><?=ta_e($initials)?></div><div class="min-w-0"><div class="text-[13px] font-semibold text-white truncate"><?=ta_e($userName)?></div><div class="text-[11px] text-white/80 truncate"><?=ta_e($userDisplayRole)?></div></div></div>
<a href="../../auth/logout.php" class="text-white/85 hover:text-white p-2 rounded-lg hover:bg-white/10 transition" title="Log out" aria-label="Log out"><svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg></a>
</div></aside>
<main id="assignments-main" class="flex-1 min-w-0 h-full overflow-y-auto p-5 lg:p-8 space-y-6">
<div class="flex items-center justify-between gap-4">
<div class="flex items-center gap-3 w-full max-w-xl"><button type="button" onclick="toggleMobileSidebar()" aria-label="Open menu" class="md:hidden text-white hover:text-red p-2 glass-dark transition"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg></button><div class="relative w-full"><span class="absolute inset-y-0 left-0 flex items-center pl-4 pointer-events-none text-white/60"><svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m16 16 5 5"/></svg></span><input id="topbar-search" type="search" class="glass-dark w-full pl-11 pr-20 py-2.5 text-sm placeholder-white/55 focus:outline-none focus:border-white/50" placeholder="Search technicians, repair orders, plates…" autocomplete="off"><kbd class="absolute inset-y-0 right-0 flex items-center pr-4 pointer-events-none text-[11px] text-white/50 font-sans">Ctrl K</kbd></div></div>
<button type="button" id="create-employee-button" aria-haspopup="dialog" aria-controls="staff-create-panel" aria-expanded="false" class="bg-red hover:bg-red-hover text-white font-display text-base font-semibold uppercase tracking-wider px-5 py-2.5 rounded-xl flex items-center gap-2 shadow-lg shadow-red/30 transition shrink-0"><svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24"><path d="M12 4v16M4 12h16"/></svg>Create Employee</button>
</div>
<?php if($createEmployeeNotice): ?><div class="glass p-4 text-emerald-200 border-emerald-400/40" role="status"><?=ta_e($createEmployeeNotice)?></div><?php endif; ?>
<?php if($loadError): ?><div class="glass p-4 text-rose-200 border-rose-400/40" role="alert"><?=ta_e($loadError)?></div><?php endif; ?>
<?php if($partRequestNotice): ?><div class="glass p-4 text-emerald-200 border-emerald-400/40" role="status"><?=ta_e($partRequestNotice)?></div><?php endif; ?>
<?php if($partRequestError): ?><div class="glass p-4 text-rose-200 border-rose-400/40" role="alert"><?=ta_e($partRequestError)?></div><?php endif; ?>

<header class="flex flex-col sm:flex-row sm:items-end justify-between gap-4"><div><p class="text-xs font-semibold tracking-[.18em] text-white/60 mb-1"><?=ta_e(strtoupper(date('l')) . ' · ' . strtoupper(date('j F Y')))?></p><h1 class="font-display text-5xl font-bold tracking-tight text-white uppercase leading-none">Technicians &amp; Assignments<span class="text-red">.</span></h1><p class="text-sm text-white/70 mt-2">Monitor technician workload, view active repair jobs, and manage assignments.</p></div><span class="glass-dark inline-flex items-center gap-2 px-3.5 py-2 text-xs font-semibold self-start sm:self-auto"><span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span><span class="font-mono text-white/80 uppercase tracking-wider font-bold">SERVICE OPERATIONS</span></span></header>
<div class="assignment-stats-grid grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-5">
<div class="glass p-5"><div class="flex justify-between items-start"><span class="label">Active Technicians</span><svg class="w-5 h-5 text-white/60" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="7" r="4"/><path d="M4 21v-2a8 8 0 0116 0v2"/></svg></div><div class="mt-4"><span class="font-display text-4xl font-bold"><?=$activeTechs?></span><p class="text-[11px] text-slate-300 mt-1 font-mono"><?=count($techs)?> technicians in directory</p></div></div>
<div class="glass p-5"><div class="flex justify-between items-start"><span class="label text-emerald-300">Assigned Open Jobs</span><svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 5h18v16H3zM7 3v4M17 3v4M3 10h18"/></svg></div><div class="mt-4"><span id="assigned-open-stat" class="font-display text-4xl font-bold text-emerald-300"><?=$openJobCount?></span><p class="text-[11px] text-slate-300 mt-1 font-mono">Assigned to technician accounts</p></div></div>
<div class="glass p-5"><div class="flex justify-between items-start"><span class="label text-rose-300">Unassigned Jobs</span><svg class="w-5 h-5 text-rose-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="m12 3 10 18H2zM12 9v5m0 4h.01"/></svg></div><div class="mt-4"><span id="unassigned-jobs-stat" class="font-display text-4xl font-bold text-rose-300"><?=$unassignedCount?></span><p class="text-[11px] text-slate-300 mt-1 font-mono">In the latest 500 open jobs</p></div></div>
<a href="#shift-approval" id="pending-shifts-card" class="glass p-5 block hover:border-amber-400/60 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-amber-400" aria-label="Jump to shift registrations and approvals">
<div class="flex justify-between items-start"><span class="label text-amber-300">Pending Shift Approvals</span><svg class="w-5 h-5 text-amber-300" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 11h18m5 5 2 2 4-4"/></svg></div>
<div class="mt-4"><span id="pending-shift-stat" class="font-display text-4xl font-bold text-amber-300"><?= (int)$pendingShiftCount ?></span><p class="text-[11px] text-slate-300 mt-1 font-mono">Review shifts below without leaving this page</p></div></a>
</div>
<section class="glass p-5" aria-labelledby="directory-title">
<div class="flex flex-wrap justify-between items-center gap-3 mb-4"><div><h2 id="directory-title" class="card-title">Technician Workload</h2><p class="text-xs text-white/65 mt-1">Choose a technician in the requests panel to view their workload and parts requests without reloading the page.</p></div></div>
<details class="tech-requests" id="technician-requests" <?= $showPartRequests ? 'open' : '' ?>>
    <summary class="tech-requests-toggle">
        <span class="tech-requests-icon" aria-hidden="true"><svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 4H5v17h14V4h-4M9 2h6v5H9zM9 12h6m-6 4h4"/></svg></span>
        <span class="tech-requests-heading">
            <strong>Technician requests</strong>
            <small>Approve, reject, or fulfill requested parts for repair orders</small>
            <span class="tech-requests-personnel">
                <span id="tech-heading-avatar" class="tech-avatar tech-requests-avatar" aria-hidden="true">ALL</span>
                <span class="tech-requests-personnel-details">
                    <label for="tech-requests-technician" class="tech-requests-select-label">Technician</label>
                    <select id="tech-requests-technician" class="tech-requests-select" aria-label="Filter requests and repair orders by technician">
                        <option value="all" <?= $selectedTechnician === 0 ? 'selected' : '' ?> data-avatar="ALL" data-state="<?=count($techs)?> technician accounts" data-jobs="<?=$openJobCount ?? 0?>" data-repairing="<?=array_sum(array_column($techs, 'repairing_jobs'))?>" data-waiting="<?=array_sum(array_column($techs, 'waiting_parts_jobs'))?>">All technicians</option>
                        <?php foreach ($techs as $t): ?>
                        <option value="<?=(int)$t['id']?>" <?= $selectedTechnician === (int)$t['id'] ? 'selected':'' ?> data-avatar="<?=ta_e(mb_strtoupper(mb_substr((string)$t['full_name'], 0, 1)))?>" data-state="<?= (int)$t['is_active'] ? 'Active account' : 'Inactive account' ?>" data-jobs="<?=(int)$t['active_jobs']?>" data-repairing="<?=(int)$t['repairing_jobs']?>" data-waiting="<?=(int)$t['waiting_parts_jobs']?>"><?=ta_e($t['full_name'])?></option>
                        <?php endforeach; ?>
                    </select>
                    <span id="tech-heading-state" class="tech-requests-personnel-state"></span>
                </span>
                <span class="tech-requests-personnel-stats" id="tech-heading-stats" aria-live="polite"></span>
            </span>
        </span>
        <span id="tech-requests-pending-count" class="tech-requests-count"><?= $pendingRequests ?> pending</span>
        <svg class="tech-requests-chevron" aria-hidden="true" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 9 6 6 6-6"/></svg>
    </summary>
    <div class="tech-requests-content">
        <p class="tech-requests-note">Approve a request first, then choose Fulfill to deduct stock once, record its stock movement and add a billable item to the repair order. Do not manually charge the same part a second time.</p>
        <?php if ($partRequestsLoadError): ?><p class="tech-requests-error" role="alert"><?= ta_e($partRequestsLoadError) ?></p><?php endif; ?>
        <div class="tech-requests-scroll"><table class="tech-requests-table">
            <thead><tr><th scope="col">Request / order</th><th scope="col">Part</th><th scope="col">Technician</th><th scope="col">Stock / requested</th><th scope="col">Status</th><th scope="col">Action</th></tr></thead>
            <tbody>
            <?php if (!$partRequests && !$partRequestsLoadError): ?><tr id="tech-requests-empty-dataset"><td colspan="6" class="tech-requests-empty">No part requests found. Requests submitted by technicians will appear here.</td></tr><?php endif; ?>
            <?php foreach ($partRequests as $request): ?>
            <tr data-request-technician="<?= (int)$request['requested_by'] ?>" data-request-status="<?= ta_e($request['status']) ?>">
                <td><strong class="tech-requests-code"><?= ta_e($request['request_code']) ?></strong><a class="tech-requests-order" href="../appointments/list.php?selected=o-<?= (int)$request['order_id'] ?>"><?= ta_e($request['order_code']) ?></a><small class="tech-requests-small"><?= ta_e($request['requested_at'] ?? '') ?></small></td>
                <td><strong class="tech-requests-part"><?= ta_e($request['part_name']) ?></strong><small class="tech-requests-small"><?= ta_e($request['part_code']) ?></small></td>
                <td><?= ta_e($request['requester_name']) ?></td>
                <td class="tech-requests-quantity <?= (int)$request['stock_quantity'] < (int)$request['quantity'] ? 'is-low' : '' ?>"><span><?= (int)$request['stock_quantity'] ?></span> / <?= (int)$request['quantity'] ?></td>
                <td><span class="tech-request-status tech-request-status--<?= ta_e($request['status']) ?>"><?= ta_e(ucfirst($request['status'])) ?></span></td>
                <td><div class="tech-requests-actions">
                    <?php $decisions = $request['status'] === 'pending' ? ['approved' => 'Approve', 'rejected' => 'Reject'] : ($request['status'] === 'approved' ? ['fulfilled' => 'Fulfill', 'rejected' => 'Reject'] : []); ?>
                    <?php if (!$decisions): ?><span class="tech-requests-processed">Processed</span><?php endif; ?>
                    <?php foreach ($decisions as $decision => $label): ?>
                    <form action="<?= ta_e($partRequestActionUrl) ?>" method="POST">
                        <input type="hidden" name="action" value="part_request_decision">
                        <input type="hidden" name="csrf" value="<?= ta_e($employeeCsrf) ?>">
                        <input type="hidden" name="request_id" value="<?= (int)$request['id'] ?>">
                        <input type="hidden" name="decision" value="<?= ta_e($decision) ?>">
                        <button type="submit" class="tech-request-button tech-request-button--<?= ta_e($decision) ?>" <?= $decision === 'fulfilled' ? 'onclick="return confirm(&quot;Issue parts from stock and add this charge to the repair order?&quot;)"' : ($decision === 'rejected' ? 'onclick="return confirm(&quot;Reject this technician part request?&quot;)"' : '') ?>><?= ta_e($label) ?></button>
                    </form>
                    <?php endforeach; ?>
                </div></td>
            </tr>
            <?php endforeach; ?>
            <tr id="tech-requests-no-match" hidden><td colspan="6" class="tech-requests-empty">No requests for this technician in the latest 200 requests.</td></tr>
            </tbody>
        </table></div>
        <p class="tech-requests-footer"><span id="tech-requests-visible-count"></span> Showing the latest 200 requests, prioritized by pending and approved status.</p>
    </div>
</details>
</section>
<div class="assignment-bottom-grid">
<section aria-labelledby="jobs-title" class="min-w-0"><div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 border-b border-white/15 pb-3"><div class="flex flex-wrap items-center gap-6 font-display uppercase tracking-wider text-sm" role="tablist" aria-label="Assignment status">
<button type="button" data-tab="ALL" class="tech-tab active">All <span id="count-all" class="tech-tab-count">0</span></button>
<button type="button" data-tab="unassigned" class="tech-tab">Unassigned <span id="count-unassigned" class="tech-tab-count">0</span></button>
<button type="button" data-tab="assigned" class="tech-tab">Assigned <span id="count-assigned" class="tech-tab-count">0</span></button></div>
<div class="w-full sm:w-72 relative"><span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-white/50"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="6" cy="6" r="5"/><path d="m10 10 4 4"/></svg></span><input type="search" id="table-search" class="glass-input w-full pl-8 pr-3 py-1.5 text-xs placeholder-white/40 font-semibold" placeholder="Filter orders and technicians…"></div></div>
<div class="glass p-4 mt-4 mb-4 tech-filters"><label>Technician<select id="filter-technician" class="glass-input px-3 py-2"><option value="all">All technicians / unassigned</option><option value="unassigned">Unassigned only</option><?php foreach($techs as $t): ?><option value="<?=(int)$t['id']?>" <?= $selectedTechnician === (int)$t['id'] ? 'selected':'' ?>><?=ta_e($t['full_name'])?></option><?php endforeach;?></select></label><label>Repair status<select id="filter-status" class="glass-input px-3 py-2"><option value="all">All active statuses</option><option value="received">Received</option><option value="diagnosing">Diagnosing</option><option value="repairing">Repairing</option><option value="waiting_parts">Waiting for parts</option><option value="inspecting">Inspecting</option></select></label><button type="button" id="clear-filters" class="glass-dark px-4 py-2 text-xs font-semibold">Clear filters</button></div>
<div class="glass overflow-hidden"><div class="flex flex-wrap justify-between items-center gap-3 px-6 py-4 border-b border-white/15"><div><h2 id="jobs-title" class="card-title">Open Repair Orders</h2><p class="text-xs text-white/60 mt-1">Latest 500 open orders · Assign or reassign from order details.</p></div><span id="matching-count" class="text-xs text-white/70 font-mono"></span></div><div class="overflow-x-auto"><table class="tech-table w-full text-left border-collapse text-[13px]"><thead><tr class="label border-b border-white/15 bg-white/5"><th class="py-3.5 px-6">Repair Order &amp; Date</th><th class="py-3.5 px-4">Vehicle &amp; Plate</th><th class="py-3.5 px-4">Technician</th><th class="py-3.5 px-4">Status</th><th class="py-3.5 px-4">Expected Delivery</th><th class="py-3.5 px-6 text-center">Action</th></tr></thead><tbody id="orders-tbody" class="divide-y divide-white/10"></tbody></table></div></div>
<nav class="tech-pagination" aria-label="Repair order pages"><span id="page-info">0 repair orders</span><div class="tech-page-actions"><label for="page-size">Rows per page</label><select id="page-size" class="glass-input px-2 py-1"><option>10</option><option selected>20</option><option>50</option></select><button type="button" id="page-prev" class="glass-dark px-3 py-1.5">Previous</button><span id="page-number">1 / 1</span><button type="button" id="page-next" class="glass-dark px-3 py-1.5">Next</button></div></nav>
</section>
<section id="shift-approval" class="glass min-w-0 p-5" aria-labelledby="shift-approval-title">
    <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
        <div><h2 id="shift-approval-title" class="card-title">Shift Registrations &amp; Attendance</h2>
        <p class="mt-1 text-xs text-white/65">Approve / Reject employee shifts and record attendance here. No page reload.</p></div>
        <span class="tech-requests-count"><span id="pending-shift-inline"><?= (int)$pendingShiftCount ?></span> pending</span>
    </div>
    <p id="shift-feedback" role="status" aria-live="polite" class="shift-feedback" <?= $shiftActionNotice ? '' : 'hidden' ?> style="<?= $shiftActionError ? 'color:#fecdd3' : '' ?>"><?= ta_e($shiftActionNotice) ?></p>
    <?php if($shiftLoadError): ?><p class="tech-requests-error" role="alert"><?= ta_e($shiftLoadError) ?></p><?php endif; ?>
    <div class="shift-review-scroll" role="region" aria-label="Shift registrations" tabindex="0">
    <table class="shift-review-table"><thead><tr><th>Technician</th><th>Date</th><th>Shift</th><th>Status</th><th>Attendance</th><th>Actions</th></tr></thead>
    <tbody id="shift-rows">
    <?php if (!$shiftRows && !$shiftLoadError): ?><tr><td colspan="6" class="text-slate-300">No technician shift registrations found.</td></tr><?php endif; ?>
    <?php foreach ($shiftRows as $shift):
        $shiftStatus=(string)$shift['status'];
        $attendance=(string)($shift['attendance'] ?? 'unconfirmed');
        $canAttend=$shiftStatus==='approved' && (string)$shift['work_date']<=date('Y-m-d');
        $shiftTitle=['morning'=>'Morning','afternoon'=>'Afternoon','full'=>'Full day'][(string)$shift['shift_type']] ?? ucfirst((string)$shift['shift_type']);
    ?>
    <tr id="shift-row-<?= (int)$shift['id'] ?>" data-status="<?= ta_e($shiftStatus) ?>" data-attendance="<?= ta_e($attendance) ?>" data-work-date="<?= ta_e($shift['work_date']) ?>">
        <td class="font-semibold"><?= ta_e($shift['full_name']) ?></td>
        <td class="whitespace-nowrap"><?= ta_e(date('d/m/Y', strtotime((string)$shift['work_date']))) ?></td>
        <td><?= ta_e($shiftTitle) ?></td>
        <td><span class="shift-pill shift-pill--<?= ta_e($shiftStatus) ?>" data-shift-status><?= ta_e(ucfirst($shiftStatus)) ?></span></td>
        <td><span class="shift-pill shift-pill--<?= ta_e($attendance) ?>" data-shift-attendance><?= ta_e(ucfirst($attendance)) ?></span></td>
        <td><div class="shift-actions" data-shift-actions>
           <?php if ($shiftStatus==='pending'): ?>
             <form action="assignments.php" method="post" data-shift-form><input type="hidden" name="action" value="shift_decision"><input type="hidden" name="csrf" value="<?=ta_e($employeeCsrf)?>"><input type="hidden" name="id" value="<?=(int)$shift['id']?>"><input type="hidden" name="decision" value="approve"><button class="shift-action shift-action--approve" type="submit">Approve</button></form>
             <form action="assignments.php" method="post" data-shift-form><input type="hidden" name="action" value="shift_decision"><input type="hidden" name="csrf" value="<?=ta_e($employeeCsrf)?>"><input type="hidden" name="id" value="<?=(int)$shift['id']?>"><input type="hidden" name="decision" value="reject"><button class="shift-action" type="submit">Reject</button></form>
           <?php elseif ($canAttend): ?>
             <form action="assignments.php" method="post" data-shift-form><input type="hidden" name="action" value="shift_decision"><input type="hidden" name="csrf" value="<?=ta_e($employeeCsrf)?>"><input type="hidden" name="id" value="<?=(int)$shift['id']?>"><input type="hidden" name="decision" value="present"><button class="shift-action <?= $attendance==='present'?'shift-action--active':'' ?>" type="submit">Present</button></form>
             <form action="assignments.php" method="post" data-shift-form><input type="hidden" name="action" value="shift_decision"><input type="hidden" name="csrf" value="<?=ta_e($employeeCsrf)?>"><input type="hidden" name="id" value="<?=(int)$shift['id']?>"><input type="hidden" name="decision" value="absent"><button class="shift-action <?= $attendance==='absent'?'shift-action--active':'' ?>" type="submit">Absent</button></form>
           <?php else: ?><span class="text-white/50 text-xs">—</span><?php endif; ?>
        </div></td>
    </tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <div class="mt-4 flex flex-wrap items-center justify-between gap-2"><span class="text-[11px] text-slate-300">Latest 100 shift registrations</span><a href="../technician_management.php#register-shift" class="text-xs font-semibold text-rose-300 hover:underline">Register a new approved shift / Payroll</a></div>
</section>
</div></main>
<aside id="assignment-inspector" class="assignment-inspector" aria-labelledby="assignment-inspector-title" hidden>
  <div class="assignment-inspector-header"><div class="min-w-0"><p>REDLINE / WORKSHOP</p><h2 id="assignment-inspector-title">Repair Order Detail</h2><span id="assignment-inspector-subtitle">Select an open repair order</span></div>
  <button id="assignment-inspector-close" type="button" aria-label="Close repair order detail" title="Close detail panel">&times;</button></div>
  <div id="assignment-inspector-loading" role="status">Loading repair order details...</div>
  <iframe id="assignment-inspector-frame" title="Repair order detail and technician assignment" referrerpolicy="same-origin" loading="eager"></iframe>
</aside>
        <!-- CREATE EMPLOYEE MODAL -->
        <div id="staff-create-panel" class="staff-modal fixed inset-0 z-[100] flex items-center justify-center px-4 py-4" role="dialog" aria-modal="true" aria-labelledby="staff-modal-title" tabindex="-1" <?= $showCreateEmployee ? '' : 'hidden' ?>>
            <div class="staff-modal-card">
                <button type="button" id="close-staff-form" aria-label="Close form" class="absolute right-5 top-4 text-white/65 hover:text-red text-2xl leading-none transition">&times;</button>
                <h3 id="staff-modal-title" class="font-display text-[28px] leading-tight font-bold uppercase tracking-wide text-white pr-8">Create Employee<span class="text-red">.</span></h3>
                <p class="text-xs text-white/60 mt-1">Register a new REDLINE staff account</p>
                <div class="modal-divider"></div>
                <?php if ($createEmployeeError): ?>
                <div class="staff-create-error" role="alert"><?=ta_e($createEmployeeError)?></div>
                <?php endif; ?>
                <form action="assignments.php" method="POST" class="staff-create-form">
                    <input type="hidden" name="action" value="add_staff">
                    <input type="hidden" name="csrf" value="<?= ta_e($employeeCsrf) ?>">

                    <div>
                        <label for="staff-full-name" class="label">Full Name (*)</label>
                        <input id="staff-full-name" type="text" name="full_name" required maxlength="100" placeholder="e.g. John Doe" value="<?= htmlspecialchars((string)(($_POST['action'] ?? '') === 'add_staff' && $createEmployeeError !== '' ? ($_POST['full_name'] ?? '') : ''), ENT_QUOTES, 'UTF-8') ?>" class="staff-modal-input">
                    </div>
                    <div>
                        <label for="staff-phone" class="label">Phone Number (*)</label>
                        <input id="staff-phone" type="tel" name="phone" required maxlength="15" placeholder="555-019-8871" value="<?= htmlspecialchars((string)(($_POST['action'] ?? '') === 'add_staff' && $createEmployeeError !== '' ? ($_POST['phone'] ?? '') : ''), ENT_QUOTES, 'UTF-8') ?>" class="staff-modal-input">
                    </div>
                    <div>
                        <label for="staff-email" class="label">Login Email (*)</label>
                        <input id="staff-email" type="email" name="email" required maxlength="100" placeholder="tech@redline.com" value="<?= htmlspecialchars((string)(($_POST['action'] ?? '') === 'add_staff' && $createEmployeeError !== '' ? ($_POST['email'] ?? '') : ''), ENT_QUOTES, 'UTF-8') ?>" class="staff-modal-input">
                    </div>
                    <div>
                        <label for="staff-username" class="label">Username (*)</label>
                        <input id="staff-username" type="text" name="username" required maxlength="100" autocomplete="username" placeholder="john.doe or alex.smith" value="<?= htmlspecialchars((string)(($_POST['action'] ?? '') === 'add_staff' && $createEmployeeError !== '' ? (ta_staff_username((string)($_POST['username'] ?? '')) ?: ($_POST['username'] ?? '')) : ''), ENT_QUOTES, 'UTF-8') ?>" class="staff-modal-input">
                        <p id="staff-username-help" class="text-[11px] text-white/65 mt-1" aria-live="polite">Spaces and Vietnamese accents are converted automatically. Example: Alex Smith → alex.smith.</p>
                    </div>
                    <div>
                        <label for="staff-role" class="label">Role / Position (*)</label>
                        <select id="staff-role" name="role_name" required class="staff-modal-input">
                            <?php $selectedRole = ($_POST['action'] ?? '') === 'add_staff' && $createEmployeeError !== '' ? ($_POST['role_name'] ?? 'Technician') : 'Technician'; ?>
                            <option value="Technician" <?= $selectedRole === 'Technician' ? 'selected' : '' ?>>Technician</option>
                            <option value="Receptionist" <?= $selectedRole === 'Receptionist' ? 'selected' : '' ?>>Receptionist</option>
                            <option value="Cashier" <?= $selectedRole === 'Cashier' ? 'selected' : '' ?>>Cashier</option>
                        </select>
                    </div>
                    <div>
                        <label for="staff-password" class="label">Initial Password (*)</label>
                        <input id="staff-password" type="password" name="password" required minlength="8" autocomplete="new-password" placeholder="At least 8 characters" class="staff-modal-input">
                    </div>
                    <button type="submit" class="w-full bg-red hover:bg-red-hover text-white font-display text-base uppercase font-bold py-2.5 px-6 rounded-xl shadow-lg shadow-red/25 transition">+ Create Staff Account</button>
                </form>
            </div>
        </div>


<script>
function toggleMobileSidebar(){document.getElementById('main-sidebar').classList.toggle('-translate-x-full');document.getElementById('mobile-sidebar-backdrop').classList.toggle('hidden');}
const rawOrders=<?=json_encode($orders,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE)?>;
let tab='ALL',page=1;
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const prettyStatus=s=>({'received':'Received','diagnosing':'Diagnosing','repairing':'Repairing','waiting_parts':'Waiting for parts','inspecting':'Inspecting'})[s]||s;
// Changing technicians never navigates: no href tech-cards and no history updates.
const requestSelector=document.getElementById('tech-requests-technician');
const requestsPanel=document.getElementById('technician-requests');
const pendingCounts=<?=json_encode($pendingRequestsByTechnician, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
const pendingTotal=<?= (int)$pendingRequests ?>;
function updateTechnicianView(syncJobs=true, openRequests=false){
    const option=requestSelector.selectedOptions[0];
    const selected=option?.value||'all';
    const avatar=document.getElementById('tech-heading-avatar');
    avatar.textContent=option?.dataset.avatar||'ALL';
    document.getElementById('tech-heading-state').textContent=option?.dataset.state||'';
    const jobs=Number(option?.dataset.jobs||0),repairing=Number(option?.dataset.repairing||0),waiting=Number(option?.dataset.waiting||0);
    const stats=document.getElementById('tech-heading-stats');
    stats.textContent=`${jobs} active jobs · ${repairing} repairing · ${waiting} waiting for parts`;
    const pending=selected==='all'?pendingTotal:(Number(pendingCounts[selected]||0));
    document.getElementById('tech-requests-pending-count').textContent=`${pending} pending`;
    const rows=document.querySelectorAll('.tech-requests-table tbody tr[data-request-technician]');
    let visible=0;
    rows.forEach(row=>{
        const show=selected==='all'||row.dataset.requestTechnician===selected;
        row.hidden=!show;
        if(show)visible++;
    });
    const empty=document.getElementById('tech-requests-no-match');
    if(empty)empty.hidden=visible>0||Boolean(document.querySelector('.tech-requests-error'))||Boolean(document.getElementById('tech-requests-empty-dataset'));
    document.getElementById('tech-requests-visible-count').textContent=`${visible} visible. `;
    if(syncJobs){
        document.getElementById('filter-technician').value=selected;
        page=1;render();
    }
    // POST actions still use the established PRG/CSRF flow, while preserving
    // whichever technician is currently selected in this in-page filter.
    const action=new URL('assignments.php',window.location.href);
    action.searchParams.set('requests','1');
    if(selected!=='all')action.searchParams.set('technician_id',selected);
    action.hash='technician-requests';
    document.querySelectorAll('.tech-requests-actions form').forEach(form=>{form.action=action.href;});
    // Only toggle the details panel; never mutate the address bar or navigate.
    // The selected ID is retained in the POST form action for server-side decisions.
    if(openRequests) requestsPanel.open=true;
}
requestSelector.addEventListener('click',event=>event.stopPropagation());
requestSelector.addEventListener('keydown',event=>event.stopPropagation());
requestSelector.addEventListener('change',()=>updateTechnicianView(true,true));
function render(){
 const phrase=document.getElementById('table-search').value.toLocaleLowerCase().trim();
 const tech=document.getElementById('filter-technician').value, status=document.getElementById('filter-status').value;
 const rows=rawOrders.filter(o=>{
 const a=o.assigned_to!==null && String(o.assigned_to)!=='0';
 if(tab==='assigned'&&!a || tab==='unassigned'&&a)return false;
 if(tech==='unassigned'&&a || tech!=='all'&&tech!=='unassigned'&&String(o.assigned_to)!==tech)return false;
 if(status!=='all'&&o.status!==status)return false;
 return [o.order_code,o.license_plate,o.brand,o.model,o.technician_name].some(v=>String(v??'').toLocaleLowerCase().includes(phrase));
 });
 const size=Number(document.getElementById('page-size').value)||20;
 const pages=Math.max(1,Math.ceil(rows.length/size));page=Math.min(page,pages);
 const start=(page-1)*size;
 document.getElementById('count-all').textContent=rawOrders.length;
 document.getElementById('count-assigned').textContent=rawOrders.filter(o=>o.assigned_to!==null&&String(o.assigned_to)!=='0').length;
 document.getElementById('count-unassigned').textContent=rawOrders.filter(o=>o.assigned_to===null||String(o.assigned_to)==='0').length;
 document.getElementById('matching-count').textContent=rows.length+' matches';
 document.getElementById('page-info').textContent=rows.length?`${start+1}–${Math.min(rows.length,start+size)} of ${rows.length} repair orders`:'0 repair orders';
 document.getElementById('page-number').textContent=`${page} / ${pages}`;
 document.getElementById('page-prev').disabled=page<=1;document.getElementById('page-next').disabled=page>=pages;
 document.getElementById('orders-tbody').innerHTML=rows.length?rows.slice(start,start+size).map(o=>{
 const vehicle=[o.brand,o.model].filter(Boolean).join(' ');
 const date=String(o.created_at||'').slice(0,10);
 const delivery=o.expected_delivery?esc(String(o.expected_delivery).slice(0,16)):'—';
 return `<tr class="hover:bg-white/10 transition border-t border-white/15"><td class="py-3.5 px-6"><span class="font-mono font-bold text-red text-sm">${esc(o.order_code)}</span><div class="text-xs text-slate-300 mt-1">${esc(date)}</div></td><td class="py-3.5 px-4"><span class="block text-white font-semibold">${esc(vehicle||'Vehicle')}</span><span class="text-xs text-slate-300 font-mono">${esc(o.license_plate||'N/A')}</span></td><td class="py-3.5 px-4">${o.technician_name?`<span class="text-white font-semibold">${esc(o.technician_name)}</span>`:'<span class="tech-badge unassigned">Unassigned</span>'}</td><td class="py-3.5 px-4"><span class="tech-badge ${esc(o.status)}">${esc(prettyStatus(o.status))}</span></td><td class="py-3.5 px-4 text-slate-200 text-xs font-mono">${delivery}</td><td class="py-3.5 px-6 text-center"><button type="button" data-open-order="${Number(o.id)}" class="glass-dark inline-flex px-3 py-1.5 rounded-lg text-xs font-semibold hover:bg-white/20">View / Assign</button></td></tr>`;
 }).join(''):'<tr><td colspan="6" class="p-9 text-center text-slate-300">No matching repair orders found.</td></tr>';
}
document.querySelectorAll('[data-tab]').forEach(b=>b.addEventListener('click',()=>{tab=b.dataset.tab;page=1;document.querySelectorAll('[data-tab]').forEach(x=>x.classList.toggle('active',x===b));render();}));
['topbar-search','table-search','filter-status','page-size'].forEach(id=>document.getElementById(id).addEventListener(['filter-status','page-size'].includes(id)?'change':'input',e=>{if(id==='topbar-search')document.getElementById('table-search').value=e.target.value;if(id==='table-search')document.getElementById('topbar-search').value=e.target.value;page=1;render();}));
document.getElementById('filter-technician').addEventListener('change',()=>{const chosen=document.getElementById('filter-technician').value;requestSelector.value=chosen==='unassigned'?'all':chosen;page=1;updateTechnicianView(false,true);render();});
document.getElementById('clear-filters').addEventListener('click',()=>{requestSelector.value='all';updateTechnicianView(false,true);document.getElementById('filter-technician').value='all';document.getElementById('filter-status').value='all';document.getElementById('topbar-search').value='';document.getElementById('table-search').value='';tab='ALL';page=1;document.querySelectorAll('[data-tab]').forEach(b=>b.classList.toggle('active',b.dataset.tab==='ALL'));render();});
document.getElementById('page-prev').addEventListener('click',()=>{page--;render();});document.getElementById('page-next').addEventListener('click',()=>{page++;render();});
document.addEventListener('keydown',e=>{if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='k'){e.preventDefault();document.getElementById('topbar-search').focus();}});
render();
updateTechnicianView(false,false);

// Repair order details stay in a 50%-width panel, as on Appointments.
const inspector = document.getElementById('assignment-inspector');
const inspectorFrame = document.getElementById('assignment-inspector-frame');
const inspectorLoading = document.getElementById('assignment-inspector-loading');
let inspectorOrderId = null;
let inspectorReturnFocus = null;
function openOrderInspector(orderId, button) {
    const order = rawOrders.find(item => Number(item.id) === Number(orderId));
    if (!order) return;
    inspectorReturnFocus = button || document.activeElement;
    inspectorOrderId = Number(orderId);
    inspector.hidden = false;
    document.body.classList.add('inspector-visible');
    inspectorLoading.hidden = false;
    inspectorLoading.textContent = 'Loading repair order details…';
    document.getElementById('assignment-inspector-subtitle').textContent = order.order_code + ' · ' + (order.technician_name || 'Unassigned');
    inspectorFrame.src = '../orders/detail.php?id=' + encodeURIComponent(orderId) + '&embed=1';
    document.getElementById('assignment-inspector-close').focus({preventScroll:true});
}
function closeOrderInspector() {
    if (inspector.hidden) return;
    inspector.hidden = true;
    document.body.classList.remove('inspector-visible');
    inspectorFrame.removeAttribute('src');
    const lastOrderId = inspectorOrderId;
    inspectorOrderId = null;
    if (inspectorReturnFocus?.isConnected) inspectorReturnFocus.focus({preventScroll:true});
    else if (lastOrderId !== null) document.querySelector(`[data-open-order="${lastOrderId}"]`)?.focus({preventScroll:true});
}
document.getElementById('orders-tbody').addEventListener('click', (event) => {
    const button = event.target.closest('[data-open-order]');
    if (button) openOrderInspector(Number(button.dataset.openOrder),button);
});
document.getElementById('assignment-inspector-close').addEventListener('click',closeOrderInspector);
inspectorFrame.addEventListener('load',() => {inspectorLoading.hidden = true;});
document.addEventListener('keydown',e => {
    if (e.key === 'Escape' && !inspector.hidden && document.getElementById('staff-create-panel').hidden) closeOrderInspector();
});
window.addEventListener('message', event => {
    if (event.origin !== window.location.origin || event.source !== inspectorFrame.contentWindow || !event.data) return;
    if (event.data.type === 'redline:close-order-inspector') {closeOrderInspector();return;}
    if (event.data.type === 'redline:assignment-result' && event.data.ok && inspectorOrderId) {
        const order = rawOrders.find(item => Number(item.id) === inspectorOrderId);
        const assigned = event.data.technician;
        if (order && assigned && Number(assigned.id) > 0) {
            const wasUnassigned = order.assigned_to === null || String(order.assigned_to) === '0';
            order.assigned_to = Number(assigned.id);
            order.technician_name = assigned.name || '';
            if (wasUnassigned) {
                const assignedStat=document.getElementById('assigned-open-stat');
                const unassignedStat=document.getElementById('unassigned-jobs-stat');
                assignedStat.textContent=String(Number(assignedStat.textContent)+1);
                unassignedStat.textContent=String(Math.max(0,Number(unassignedStat.textContent)-1));
            }
            document.getElementById('assignment-inspector-subtitle').textContent = order.order_code + ' · ' + assigned.name;
            render(); // Preserve current filters, page, and scroll position.
        }
    }
});

// Inline Shift Approvals: safe form target, AJAX feedback, no page reload/scroll reset.
const shiftSection = document.getElementById('shift-approval');
const shiftFeedback = document.getElementById('shift-feedback');
const shiftCsrf = <?=json_encode($employeeCsrf, JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
function shiftActionForm(id, decision, label, active = false) {
    const f = document.createElement('form'); f.method = 'post'; f.action='assignments.php';f.dataset.shiftForm='';
    for(const [name,value] of Object.entries({action:'shift_decision',csrf:shiftCsrf,id:String(id),decision})){
        const field=document.createElement('input');field.type='hidden';field.name=name;field.value=value;f.append(field);
    }
    const btn=document.createElement('button');btn.type='submit';btn.textContent=label;
    btn.className='shift-action'+(decision==='approve'?' shift-action--approve':'')+(active?' shift-action--active':'');
    f.append(btn);return f;
}
function refreshShiftRow(row, response){
    row.dataset.status=response.status;
    row.dataset.attendance=response.attendance;
    const status=row.querySelector('[data-shift-status]');
    status.textContent=response.status.charAt(0).toUpperCase()+response.status.slice(1);
    status.className='shift-pill shift-pill--'+response.status;
    const att=row.querySelector('[data-shift-attendance]');
    att.textContent=response.attendance.charAt(0).toUpperCase()+response.attendance.slice(1);
    att.className='shift-pill shift-pill--'+response.attendance;
    const actionBox=row.querySelector('[data-shift-actions]');actionBox.replaceChildren();
    if(response.status==='pending'){
        actionBox.append(shiftActionForm(response.id,'approve','Approve'),shiftActionForm(response.id,'reject','Reject'));
    }else if(response.status==='approved' && row.dataset.workDate <= '<?=date('Y-m-d')?>'){
        actionBox.append(shiftActionForm(response.id,'present','Present',response.attendance==='present'),shiftActionForm(response.id,'absent','Absent',response.attendance==='absent'));
    }else{const muted=document.createElement('span');muted.className='text-white/50 text-xs';muted.textContent='—';actionBox.append(muted);}
    for(const id of ['pending-shift-stat','pending-shift-inline']) document.getElementById(id).textContent=String(response.pending_count);
}
shiftSection.addEventListener('submit',async event=>{
    const form=event.target.closest('form[data-shift-form]');
    if(!form) return;
    event.preventDefault();
    const row=form.closest('tr');
    const submit=form.querySelector('button[type="submit"]');
    if(!row || !submit || row.dataset.pending==='1') return;
    row.dataset.pending='1'; submit.disabled=true;
    shiftFeedback.hidden=false;shiftFeedback.textContent='Saving shift…';shiftFeedback.style.color='#bfdbfe';
    try {
        const response=await fetch(form.getAttribute('action') || 'assignments.php',{
            method:'POST',credentials:'same-origin',cache:'no-store',
            headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}, body:new FormData(form)
        });
        const body=await response.text(); let data;
        try { data=JSON.parse(body); }catch(_){throw new Error('Server did not return JSON (HTTP '+response.status+'). Check the PHP handler/session.');}
        if(!response.ok || !data.ok) throw new Error(data.message || 'Shift action failed.');
        refreshShiftRow(row,data);
        shiftFeedback.textContent=data.message || 'Shift updated successfully.';shiftFeedback.style.color='#a7f3d0';
    }catch(error){
        shiftFeedback.textContent=error.message || 'Cannot contact the server.';shiftFeedback.style.color='#fecdd3';
    }finally{delete row.dataset.pending;submit.disabled=false;}
});

// Create employee popup: keep all actions inside the technician page.
document.addEventListener('DOMContentLoaded', function () {
    const trigger = document.getElementById('create-employee-button');
    const panel = document.getElementById('staff-create-panel');
    const card = panel?.querySelector('.staff-modal-card');
    const close = document.getElementById('close-staff-form');
    const fullName = document.getElementById('staff-full-name');
    const username = document.getElementById('staff-username');
    const usernameHelp = document.getElementById('staff-username-help');
    let previousFocus = null;
    if (!trigger || !panel || !card) return;
    const normalizeUsername = value => value.normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '').replace(/[đĐ]/g, c => c === 'đ' ? 'd' : 'D')
        .trim().toLowerCase().replace(/\s+/g, '.').replace(/[^a-z0-9._-]/g, '')
        .replace(/^[._-]+|[._-]+$/g, '');
    username?.addEventListener('input', () => {
        const result = normalizeUsername(username.value);
        if (usernameHelp) usernameHelp.textContent = result
            ? `Login username will be: ${result}` : 'Enter a username using letters or numbers.';
    });
    username?.addEventListener('blur', () => { username.value = normalizeUsername(username.value); });
    function setOpen(open) {
        panel.hidden = !open;
        document.body.classList.toggle('staff-modal-open', open);
        trigger.setAttribute('aria-expanded', open ? 'true' : 'false');
        if (open) { previousFocus = document.activeElement; fullName?.focus(); }
        else (previousFocus || trigger).focus();
    }
    trigger.addEventListener('click', () => setOpen(true));
    close?.addEventListener('click', () => setOpen(false));
    panel.addEventListener('click', event => { if (event.target === panel) setOpen(false); });
    document.addEventListener('keydown', event => {
        if (panel.hidden) return;
        if (event.key === 'Escape') { event.preventDefault(); setOpen(false); return; }
        if (event.key === 'Tab') {
            const focusable = Array.from(card.querySelectorAll('button, input, select, textarea, a[href]'))
                .filter(el => !el.disabled && el.getClientRects().length > 0);
            if (!focusable.length) return;
            const first = focusable[0], last = focusable[focusable.length - 1];
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
        }
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') event.stopImmediatePropagation();
    }, true);
    if (!panel.hidden) { document.body.classList.add('staff-modal-open'); trigger.setAttribute('aria-expanded', 'true'); fullName?.focus(); }
});
</script></body></html>
