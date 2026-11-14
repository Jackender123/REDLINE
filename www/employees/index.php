<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';

$staff = current_staff();
$role = $staff['role_name'] ?? '';
if (!$staff) {
    header('Location: ../auth/login.php');
    exit;
}
if ($role !== 'Technician') {
    header('Location: ' . ($role === 'Admin' ? '../admin/index.php' : '../admin/appointments/list.php'));
    exit;
}

$pdo = db();
$staffId = (int)$staff['id'];
$userName = (string)$staff['full_name'];
$csrfToken = csrf_token();
$noticeMsg = '';
$errorMsg = '';
$allowedStatuses = [
    'received' => 'Received',
    'diagnosing' => 'Diagnosing',
    'repairing' => 'In progress',
    'waiting_parts' => 'Waiting for parts',
    'completed' => 'Repair complete',
];
$paymentMethods = [
    'cash' => 'Shiftsh',
    'transfer' => 'Bank transfer',
    'qr' => 'QR',
    'card' => 'Shiftrd',
];

function employee_e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function employee_money(float $amount): string
{
    return number_format($amount, 0, ',', '.') . ' ₫';
}

function employee_progress_tracker(string $status, bool $compact = false): void
{
    $steps = [
        ['label' => 'Received'],
        ['label' => 'Diagnosis'],
        ['label' => 'Repair'],
        ['label' => 'Inspection'],
        ['label' => 'Handover'],
    ];
    $stepByStatus = [
        'received' => 1,
        'diagnosing' => 2,
        'repairing' => 3,
        'waiting_parts' => 3,
        'inspecting' => 4,
        'completed' => 5,
        'delivered' => 5,
    ];
    $currentStep = $stepByStatus[$status] ?? 1;
    $percent = (int)round($currentStep / count($steps) * 100);

    if ($compact) {
        ?>
        <div class="mt-2 min-w-28" role="progressbar" aria-label="Job progress: <?= employee_e($steps[$currentStep - 1]['label']) ?>" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $percent ?>">
            <div class="h-1.5 overflow-hidden rounded-full bg-slate-200">
                <div class="h-full rounded-full bg-brand-red transition-all duration-500" style="width: <?= $percent ?>%"></div>
            </div>
            <p class="mt-1 text-[11px] text-brand-muted">Step <?= $currentStep ?>/<?= count($steps) ?> · <?= employee_e($steps[$currentStep - 1]['label']) ?></p>
        </div>
        <?php
        return;
    }
    ?>
    <section class="rounded-2xl border border-brand-border bg-white p-5 sm:p-6" aria-label="Repair progress">
        <div class="mb-5 flex flex-wrap items-end justify-between gap-2">
            <div>
                <h3 class="font-bold text-brand-dark">Repair progress</h3>
                <p class="mt-1 text-sm text-brand-muted">Step <?= $currentStep ?>/<?= count($steps) ?> · <?= employee_e($steps[$currentStep - 1]['label']) ?></p>
            </div>
            <span class="text-sm font-bold text-brand-red"><?= $percent ?>%</span>
        </div>
        <div class="relative" role="group" aria-label="Work progress steps">
            <div class="absolute left-[10%] right-[10%] top-3 h-1 -translate-y-1/2 rounded-full bg-slate-200" role="progressbar" aria-label="Work progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?= $percent ?>">
                <div class="h-full rounded-full bg-brand-red transition-all duration-500" style="width: <?= (int)round(($currentStep - 1) / (count($steps) - 1) * 100) ?>%"></div>
            </div>
            <ol class="relative grid grid-cols-5">
                <?php foreach ($steps as $index => $step): ?>
                    <?php $stepNumber = $index + 1; $isComplete = $stepNumber <= $currentStep; ?>
                    <li class="flex min-w-0 flex-col items-center text-center"<?= $stepNumber === $currentStep ? ' aria-current="step"' : '' ?>>
                        <span class="flex h-6 w-6 items-center justify-center rounded-full border-2 text-[10px] font-bold <?= $isComplete ? 'border-brand-red bg-brand-red text-white' : 'border-slate-300 bg-white text-brand-muted' ?>"><?= $stepNumber ?></span>
                        <span class="mt-2 px-0.5 text-[9px] font-semibold leading-tight sm:text-[11px] <?= $isComplete ? 'text-brand-red' : 'text-brand-muted' ?>"><?= employee_e($step['label']) ?></span>
                    </li>
                <?php endforeach; ?>
            </ol>
        </div>
    </section>
    <?php
}

function employee_order(PDO $pdo, int $orderId, int $staffId, bool $lock = false): ?array
{
    $sql = "
        SELECT ro.*, v.license_plate, v.brand, v.model, v.year AS vehicle_year,
               v.engine_type, v.odometer AS vehicle_odometer,
               c.full_name AS customer_name, c.phone AS customer_phone,
               c.address AS customer_address
        FROM repair_orders ro
        JOIN vehicles v ON v.id = ro.vehicle_id
        LEFT JOIN users c ON c.id = COALESCE(ro.customer_id, v.customer_id)
        WHERE ro.id = :order_id AND ro.assigned_to = :staff_id
        LIMIT 1" . ($lock ? ' FOR UPDATE' : '');
    $stmt = $pdo->prepare($sql);
    $stmt->execute(['order_id' => $orderId, 'staff_id' => $staffId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    return $order ?: null;
}

function employee_invoice(PDO $pdo, int $invoiceId): ?array
{
    $stmt = $pdo->prepare(
        'SELECT i.*, ro.order_code, ro.status AS order_status,
                c.full_name AS customer_name, c.phone AS customer_phone, c.address AS customer_address,
                v.license_plate, v.brand, v.model
         FROM invoices i
         JOIN repair_orders ro ON ro.id = i.order_id
         JOIN vehicles v ON v.id = ro.vehicle_id
         LEFT JOIN users c ON c.id = COALESCE(ro.customer_id, v.customer_id)
         WHERE i.id = :id LIMIT 1'
    );
    $stmt->execute(['id' => $invoiceId]);
    $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    return $invoice ?: null;
}

function employee_invoice_balance(PDO $pdo, array $invoice): array
{
    $stmt = $pdo->prepare(
        "SELECT
            COALESCE(SUM(CASE WHEN transaction_type = 'payment' THEN amount ELSE -amount END), 0) AS net_paid,
            COUNT(*) AS transaction_count
         FROM cash_transactions
         WHERE invoice_id = :invoice_id AND status = 'completed'"
    );
    $stmt->execute(['invoice_id' => $invoice['id']]);
    $data = $stmt->fetch(PDO::FETCH_ASSOC);
    $netPaid = (float)$data['net_paid'];
    if ((int)$data['transaction_count'] === 0 && $invoice['payment_status'] === 'paid') {
        $netPaid = (float)$invoice['grand_total'];
    }
    $netPaid = max(0, min((float)$invoice['grand_total'], $netPaid));
    return [
        'paid' => $netPaid,
        'balance' => max(0, (float)$invoice['grand_total'] - $netPaid),
        'transaction_count' => (int)$data['transaction_count'],
    ];
}

function employee_order_rows(PDO $pdo, int $staffId, string $search = '', string $status = '', ?int $limit = null): array
{
    $sql = "
        SELECT ro.id, ro.order_code, ro.status, ro.received_at, ro.updated_at,
               ro.customer_request, ro.symptoms, ro.service_type,
               COALESCE((SELECT MAX(osh.created_at) FROM order_status_history osh
                         WHERE osh.order_id = ro.id AND osh.to_status = 'completed'), ro.updated_at) AS completed_at,
               ti.technical_note,
               v.license_plate, v.brand, v.model, v.engine_type,
               c.full_name AS customer_name
        FROM repair_orders ro
        JOIN vehicles v ON v.id = ro.vehicle_id
        LEFT JOIN users c ON c.id = COALESCE(ro.customer_id, v.customer_id)
        LEFT JOIN technical_inspections ti ON ti.order_id = ro.id
        WHERE ro.assigned_to = :staff_id";
    $params = ['staff_id' => $staffId];
    if ($search !== '') {
        $sql .= ' AND (ro.order_code LIKE :search_code OR v.license_plate LIKE :search_plate OR c.full_name LIKE :search_customer)';
        $like = '%' . $search . '%';
        $params['search_code'] = $like;
        $params['search_plate'] = $like;
        $params['search_customer'] = $like;
    }
    if ($status === 'history') {
        $sql .= " AND ro.status IN ('completed', 'delivered')";
    } elseif ($status !== '' && isset([
        'received' => true, 'diagnosing' => true, 'repairing' => true,
        'waiting_parts' => true, 'inspecting' => true, 'completed' => true, 'delivered' => true,
    ][$status])) {
        $sql .= ' AND ro.status = :status';
        $params['status'] = $status;
    }
    $sql .= " ORDER BY FIELD(ro.status, 'received', 'waiting_parts', 'diagnosing', 'repairing', 'inspecting', 'completed', 'delivered'), ro.updated_at DESC";
    if ($limit !== null) {
        $sql .= ' LIMIT ' . max(1, min(100, $limit));
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function employee_date_range(string $from, string $to): array
{
    $today = new DateTimeImmutable('today');
    $fromDate = DateTimeImmutable::createFromFormat('!Y-m-d', $from);
    $toDate = DateTimeImmutable::createFromFormat('!Y-m-d', $to);
    if (!$fromDate || $fromDate->format('Y-m-d') !== $from) {
        $fromDate = $today->modify('-29 days');
    }
    if (!$toDate || $toDate->format('Y-m-d') !== $to) {
        $toDate = $today;
    }
    if ($fromDate > $toDate) {
        throw new RuntimeException('The start date must be on or before the end date.');
    }
    return [$fromDate->format('Y-m-d'), $toDate->format('Y-m-d')];
}

function tc_e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function tc_money($v): string { return number_format((float)$v, 0, ',', '.') . ' ₫'; }
function tc_period(string $period): array {
 $today = new DateTimeImmutable('today');
 switch ($period) {
  case 'day': $start=$today; $end=$today->modify('+1 day'); break;
  case 'week': $start=$today->modify('-'.($today->format('N')-1).' days'); $end=$start->modify('+7 days'); break;
  case 'year': $start=$today->setDate((int)$today->format('Y'),1,1); $end=$start->modify('+1 year'); break;
  default: $period='month'; $start=$today->modify('first day of this month'); $end=$start->modify('+1 month');
 }
 return [$period,$start->format('Y-m-d'),$end->format('Y-m-d')];
}

// Weekly schedule registration: weeks begin on Monday and registration is limited to the next 90 days.
$todaySchedule = new DateTimeImmutable('today');
$minWeekSchedule = $todaySchedule->modify('-' . ((int)$todaySchedule->format('N') - 1) . ' days');
$maxScheduleDate = $todaySchedule->modify('+90 days');
$requestedWeek = (string) ($_GET['week'] ?? $minWeekSchedule->format('Y-m-d'));
$parsedWeek = DateTimeImmutable::createFromFormat('!Y-m-d', $requestedWeek);
if (!$parsedWeek || $parsedWeek->format('Y-m-d') !== $requestedWeek || (int)$parsedWeek->format('N') !== 1 || $parsedWeek < $minWeekSchedule || $parsedWeek > $maxScheduleDate) {
    $parsedWeek = $minWeekSchedule;
}
$scheduleWeekStart = $parsedWeek;
$scheduleWeekEnd = $parsedWeek->modify('+6 days');
$scheduleWeekDates = [];
for ($di = 0; $di < 7; $di++) $scheduleWeekDates[] = $parsedWeek->modify('+' . $di . ' days');
$scheduleNextWeek = $scheduleWeekStart->modify('+7 days');
$schedulePrevWeek = $scheduleWeekStart->modify('-7 days');
$scheduleBusy = [];

if ($role === 'Technician' && ($_GET['view'] ?? '') === 'schedule') {
$message=''; $error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
 try {
  if (!csrf_valid($_POST['csrf']??null)) throw new RuntimeException('Your session has expired.');
  $action=(string)($_POST['action']??'');
  if ($action==='create_week') {
   $selected = $_POST['slots'] ?? [];
   $note = trim((string)($_POST['note'] ?? ''));
   if (!is_array($selected) || count($selected) < 1 || count($selected) > 14 || mb_strlen($note) > 500) throw new RuntimeException('Select 1–14 shifts and enter no more than 500 characters in the note.');
   if (count(array_unique($selected)) !== count($selected)) throw new RuntimeException('Duplicate shifts were selected.');
   $registrations = [];
   foreach ($selected as $slot) {
    if (!is_string($slot) || !preg_match('/^(\d{4}-\d{2}-\d{2})\|(morning|afternoon)$/D', $slot, $matches)) throw new RuntimeException('Invalid shift selection.');
    $date = $matches[1]; $shift = $matches[2];
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date || $d < $todaySchedule || $d > $maxScheduleDate || $d < $scheduleWeekStart || $d > $scheduleWeekEnd) throw new RuntimeException('Only select shifts in the displayed week and within the next 90 days.');
    $registrations[] = [$date, $shift];
   }
   $pdo->beginTransaction();
   $lock = $pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE'); $lock->execute([$staffId]);
   $check = $pdo->prepare("SELECT shift_type FROM technician_shifts WHERE technician_id=? AND work_date=? AND status IN ('pending','approved') FOR UPDATE");
   $insert = $pdo->prepare('INSERT INTO technician_shifts(technician_id,work_date,shift_type,note) VALUES(?,?,?,?)');
   foreach ($registrations as [$date,$shift]) {
    $check->execute([$staffId,$date]);
    foreach ($check->fetchAll() as $existing) {
     if ($existing['shift_type'] === 'full' || $existing['shift_type'] === $shift) throw new RuntimeException('Shift ' . $date . ' has already been requested or approved. Remove the duplicate shift.');
    }
    $insert->execute([$staffId,$date,$shift,$note]);
   }
   $pdo->commit();
   $message = 'Submitted ' . count($registrations) . ' shift requests for admin approval.';
  } elseif ($action==='create') {
   $date=(string)($_POST['work_date']??''); $shift=(string)($_POST['shift_type']??''); $note=trim((string)($_POST['note']??''));
   $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
   if (!$d || $d->format('Y-m-d')!==$date || $date<date('Y-m-d') || $date>(new DateTimeImmutable('+90 days'))->format('Y-m-d')) throw new RuntimeException('Only valid dates within the next 90 days can be registered.');
   if (!in_array($shift,['morning','afternoon','full'],true) || mb_strlen($note)>500) throw new RuntimeException('Invalid shift or note.');
   $pdo->beginTransaction();
   $lock=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$lock->execute([$staffId]);
   $check=$pdo->prepare("SELECT shift_type FROM technician_shifts WHERE technician_id=? AND work_date=? AND status IN ('pending','approved') FOR UPDATE");
   $check->execute([$staffId,$date]);
   foreach ($check->fetchAll() as $row) if ($row['shift_type']==='full' || $shift==='full' || $row['shift_type']===$shift) throw new RuntimeException('A duplicate shift or full-day registration already exists.');
   $pdo->prepare('INSERT INTO technician_shifts(technician_id,work_date,shift_type,note) VALUES(?,?,?,?)')->execute([$staffId,$date,$shift,$note]);
   $pdo->commit(); $message='Schedule submitted. Awaiting admin approval.';
  } elseif ($action==='cancel') {
   $id=(int)($_POST['id']??0);
   $stmt=$pdo->prepare("UPDATE technician_shifts SET status='cancelled' WHERE id=? AND technician_id=? AND status='pending'");
   $stmt->execute([$id,$staffId]); if (!$stmt->rowCount()) throw new RuntimeException('Only pending registrations can be cancelled.');
   $message='Request cancelled.';
  }
 } catch (Throwable $e) {if($pdo->inTransaction())$pdo->rollBack(); $error=$e instanceof RuntimeException?$e->getMessage():'Unable to save the schedule. Check that the database table is installed.';}
}
try {$stmt=$pdo->prepare('SELECT * FROM technician_shifts WHERE technician_id=? ORDER BY work_date DESC,id DESC LIMIT 150');$stmt->execute([$staffId]);$rows=$stmt->fetchAll();}catch(Throwable $e){$rows=[];$error='Missing technician_shifts table. Run the database migration first.';}
// Pending and approved shift registrations lock their corresponding checkboxes.
try {
 $busyStmt = $pdo->prepare("SELECT work_date,shift_type,status FROM technician_shifts WHERE technician_id=? AND work_date BETWEEN ? AND ? AND status IN ('pending','approved')");
 $busyStmt->execute([$staffId, $scheduleWeekStart->format('Y-m-d'), $scheduleWeekEnd->format('Y-m-d')]);
 foreach ($busyStmt->fetchAll() as $busyRow) {
  $dayKey = $busyRow['work_date'];
  foreach (($busyRow['shift_type'] === 'full' ? ['morning','afternoon'] : [$busyRow['shift_type']]) as $shiftKey) {
   $scheduleBusy[$dayKey][$shiftKey] = $busyRow['status'];
  }
 }
} catch (Throwable $ignored) { /* The table error is already displayed above. */ }
$shifts=['morning'=>'Morning (07:30–11:30)','afternoon'=>'Afternoon (13:00–17:00)','full'=>'Full day (07:30–17:00)'];
$statuses=['pending'=>'Pending approval','approved'=>'Approved','rejected'=>'Rejected','cancelled'=>'Shiftncelled'];
$attendance=['unconfirmed'=>'Not confirmed','present'=>'Present','absent'=>'Absent'];

}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && !(($role === 'Technician') && (($_GET['view'] ?? '') === 'schedule'))) {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $errorMsg = 'Your session is invalid. Please reload the page.';
    } else {
        $action = (string)$_POST['action'];
        $uploadedPath = null;
        try {
            if ($role === 'Technician') {
                $orderId = (int)($_POST['order_id'] ?? 0);
                $order = employee_order($pdo, $orderId, $staffId);
                if (!$order) {
                    throw new RuntimeException('The assigned job could not be found for this account.');
                }
                if (in_array($order['status'], ['completed', 'delivered'], true)) {
                    throw new RuntimeException('This work order is complete and can no longer be edited.');
                }

                if ($action === 'update_job_status') {
                    $newStatus = (string)($_POST['job_status'] ?? '');
                    if (!isset($allowedStatuses[$newStatus])) {
                        throw new RuntimeException('Invalid job status.');
                    }
                    $pdo->beginTransaction();
                    $lockedOrder = employee_order($pdo, $orderId, $staffId, true);
                    if (!$lockedOrder || in_array($lockedOrder['status'], ['completed', 'delivered'], true)) {
                        throw new RuntimeException('This work order is complete or is no longer assigned to you.');
                    }
                    if ($newStatus !== $lockedOrder['status']) {
                        $pdo->prepare('UPDATE repair_orders SET status = :status, updated_at = NOW() WHERE id = :id')
                            ->execute(['status' => $newStatus, 'id' => $orderId]);
                        $pdo->prepare(
                            'INSERT INTO order_status_history (order_id, created_by, from_status, to_status, note)
                             VALUES (:order_id, :user_id, :from_status, :to_status, :note)'
                        )->execute([
                            'order_id' => $orderId,
                            'user_id' => $staffId,
                            'from_status' => $lockedOrder['status'],
                            'to_status' => $newStatus,
                            'note' => trim((string)($_POST['progress_note'] ?? '')),
                        ]);
                    }
                    $pdo->commit();
                    $noticeMsg = 'Job status updated.';
                } elseif ($action === 'save_inspection') {
                    $voltageInput = trim((string)($_POST['hybrid_voltage'] ?? ''));
                    $temperatureInput = trim((string)($_POST['hybrid_temperature'] ?? ''));
                    if (($voltageInput !== '' && (!is_numeric($voltageInput) || (float)$voltageInput < 0 || (float)$voltageInput > 999999.99))
                        || ($temperatureInput !== '' && (!is_numeric($temperatureInput) || (float)$temperatureInput < -273 || (float)$temperatureInput > 999.99))) {
                        throw new RuntimeException('The battery voltage or temperature is invalid.');
                    }
                    $pdo->prepare(
                        'INSERT INTO technical_inspections
                            (order_id, updated_by, technical_note, customer_complaints, inspection_needed,
                             repairs_performed, engine_result, brakes_result, electrical_result, ac_result,
                             hybrid_battery_status, hybrid_voltage, hybrid_temperature, hybrid_cooling_result,
                             inverter_result, electric_motor_result, dtc_codes)
                         VALUES
                            (:order_id, :updated_by, :technical_note, :customer_complaints, :inspection_needed,
                             :repairs_performed, :engine_result, :brakes_result, :electrical_result, :ac_result,
                             :hybrid_battery_status, :hybrid_voltage, :hybrid_temperature, :hybrid_cooling_result,
                             :inverter_result, :electric_motor_result, :dtc_codes)
                         ON DUPLICATE KEY UPDATE
                            updated_by = VALUES(updated_by), technical_note = VALUES(technical_note),
                            customer_complaints = VALUES(customer_complaints), inspection_needed = VALUES(inspection_needed),
                            repairs_performed = VALUES(repairs_performed), engine_result = VALUES(engine_result),
                            brakes_result = VALUES(brakes_result), electrical_result = VALUES(electrical_result),
                            ac_result = VALUES(ac_result), hybrid_battery_status = VALUES(hybrid_battery_status),
                            hybrid_voltage = VALUES(hybrid_voltage), hybrid_temperature = VALUES(hybrid_temperature),
                            hybrid_cooling_result = VALUES(hybrid_cooling_result), inverter_result = VALUES(inverter_result),
                            electric_motor_result = VALUES(electric_motor_result), dtc_codes = VALUES(dtc_codes)'
                    )->execute([
                        'order_id' => $orderId,
                        'updated_by' => $staffId,
                        'technical_note' => trim((string)($_POST['technical_note'] ?? '')),
                        'customer_complaints' => trim((string)($_POST['customer_complaints'] ?? '')),
                        'inspection_needed' => trim((string)($_POST['inspection_needed'] ?? '')),
                        'repairs_performed' => trim((string)($_POST['repairs_performed'] ?? '')),
                        'engine_result' => trim((string)($_POST['engine_result'] ?? '')),
                        'brakes_result' => trim((string)($_POST['brakes_result'] ?? '')),
                        'electrical_result' => trim((string)($_POST['electrical_result'] ?? '')),
                        'ac_result' => trim((string)($_POST['ac_result'] ?? '')),
                        'hybrid_battery_status' => trim((string)($_POST['hybrid_battery_status'] ?? '')),
                        'hybrid_voltage' => $voltageInput === '' ? null : (float)$voltageInput,
                        'hybrid_temperature' => $temperatureInput === '' ? null : (float)$temperatureInput,
                        'hybrid_cooling_result' => trim((string)($_POST['hybrid_cooling_result'] ?? '')),
                        'inverter_result' => trim((string)($_POST['inverter_result'] ?? '')),
                        'electric_motor_result' => trim((string)($_POST['electric_motor_result'] ?? '')),
                        'dtc_codes' => trim((string)($_POST['dtc_codes'] ?? '')),
                    ]);
                    $noticeMsg = 'Inspection notes and results saved.';
                } elseif ($action === 'request_part') {
                    $partId = (int)($_POST['part_id'] ?? 0);
                    $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
                    if ($partId <= 0 || !$quantity || $quantity < 1 || $quantity > 10000) {
                        throw new RuntimeException('Select a part and enter a valid quantity.');
                    }
                    $partCheck = $pdo->prepare('SELECT id FROM parts WHERE id = :id AND is_active = 1');
                    $partCheck->execute(['id' => $partId]);
                    if (!$partCheck->fetchColumn()) {
                        throw new RuntimeException('This part is no longer available.');
                    }
                    $requestCode = 'PR-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
                    $pdo->prepare(
                        'INSERT INTO part_requests
                            (request_code, order_id, part_id, requested_by, quantity, note)
                         VALUES (:code, :order_id, :part_id, :user_id, :quantity, :note)'
                    )->execute([
                        'code' => $requestCode,
                        'order_id' => $orderId,
                        'part_id' => $partId,
                        'user_id' => $staffId,
                        'quantity' => $quantity,
                        'note' => trim((string)($_POST['request_note'] ?? '')),
                    ]);
                    $noticeMsg = 'Part request ' . $requestCode . ' submitted.';
                } elseif ($action === 'upload_work_image') {
                    $imageType = (string)($_POST['image_type'] ?? '');
                    if (!in_array($imageType, ['before', 'after'], true)) {
                        throw new RuntimeException('Select whether this is a before- or after-repair image.');
                    }
                    $file = $_FILES['work_image'] ?? null;
                    if (!$file || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
                        throw new RuntimeException('Image upload failed. Please select the file again.');
                    }
                    if ((int)$file['size'] <= 0 || (int)$file['size'] > 5 * 1024 * 1024) {
                        throw new RuntimeException('The maximum image size is 5 MB.');
                    }
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime = $finfo->file($file['tmp_name']);
                    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                    if (!isset($extensions[$mime]) || @getimagesize($file['tmp_name']) === false) {
                        throw new RuntimeException('Only valid JPEG, PNG, or WebP images are accepted.');
                    }
                    $uploadDirectory = APP_ROOT . '/uploads/work-orders/' . $orderId;
                    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0750, true) && !is_dir($uploadDirectory)) {
                        throw new RuntimeException('Could not create the image directory. Check write permissions.');
                    }
                    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
                    $absolutePath = $uploadDirectory . '/' . $filename;
                    if (!move_uploaded_file($file['tmp_name'], $absolutePath)) {
                        throw new RuntimeException('Could not save the image to the server.');
                    }
                    $uploadedPath = 'uploads/work-orders/' . $orderId . '/' . $filename;
                    $pdo->prepare(
                        'INSERT INTO work_order_images (order_id, uploaded_by, image_type, image_path)
                         VALUES (:order_id, :user_id, :type, :path)'
                    )->execute([
                        'order_id' => $orderId,
                        'user_id' => $staffId,
                        'type' => $imageType,
                        'path' => $uploadedPath,
                    ]);
                    $uploadedPath = null;
                    $noticeMsg = 'Image uploaded to the work order.';
                } else {
                    throw new RuntimeException('This technician action is not supported.');
                }
            } elseif ($role === 'Shiftshier') {
                if ($action === 'create_invoice') {
                    $orderId = (int)($_POST['order_id'] ?? 0);
                    $discount = filter_var($_POST['discount'] ?? '0', FILTER_VALIDATE_FLOAT);
                    if ($orderId <= 0 || $discount === false || $discount < 0) {
                        throw new RuntimeException('The invoice details are invalid.');
                    }
                    $pdo->beginTransaction();
                    $orderStmt = $pdo->prepare(
                        "SELECT ro.id, ro.status FROM repair_orders ro
                         WHERE ro.id = :id AND ro.status = 'completed' FOR UPDATE"
                    );
                    $orderStmt->execute(['id' => $orderId]);
                    if (!$orderStmt->fetch(PDO::FETCH_ASSOC)) {
                        throw new RuntimeException('Invoices can only be created for completed and inspected work orders.');
                    }
                    $existing = $pdo->prepare("SELECT id FROM invoices WHERE order_id = :id AND payment_status <> 'cancelled' LIMIT 1");
                    $existing->execute(['id' => $orderId]);
                    if ($existing->fetchColumn()) {
                        throw new RuntimeException('This work order already has an invoice.');
                    }

                    $itemStmt = $pdo->prepare(
                        'SELECT item_type, item_name AS description, quantity, unit_price
                         FROM repair_order_items WHERE repair_order_id = :id ORDER BY id'
                    );
                    $itemStmt->execute(['id' => $orderId]);
                    $lineItems = [];
                    foreach ($itemStmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
                        $lineItems[] = [
                            'type' => $line['item_type'] === 'part' ? 'part' : 'service',
                            'description' => (string)$line['description'],
                            'quantity' => max(1, (int)$line['quantity']),
                            'unit_price' => (float)$line['unit_price'],
                        ];
                    }
                    if (!$lineItems) {
                        $serviceStmt = $pdo->prepare(
                            'SELECT s.name AS description, os.quantity, os.price AS unit_price
                             FROM order_services os LEFT JOIN services s ON s.id = os.service_id
                             WHERE os.order_id = :id'
                        );
                        $serviceStmt->execute(['id' => $orderId]);
                        foreach ($serviceStmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
                            $lineItems[] = [
                                'type' => 'service',
                                'description' => (string)($line['description'] ?: 'Repair service'),
                                'quantity' => max(1, (int)$line['quantity']),
                                'unit_price' => (float)$line['unit_price'],
                            ];
                        }
                        $partStmt = $pdo->prepare(
                            'SELECT p.name AS description, op.quantity, op.price AS unit_price
                             FROM order_parts op JOIN parts p ON p.id = op.part_id
                             WHERE op.order_id = :id'
                        );
                        $partStmt->execute(['id' => $orderId]);
                        foreach ($partStmt->fetchAll(PDO::FETCH_ASSOC) as $line) {
                            $lineItems[] = [
                                'type' => 'part',
                                'description' => (string)$line['description'],
                                'quantity' => max(1, (int)$line['quantity']),
                                'unit_price' => (float)$line['unit_price'],
                            ];
                        }
                    }
                    if (!$lineItems) {
                        throw new RuntimeException('This work order has no service or part items to invoice.');
                    }
                    $serviceTotal = 0.0;
                    $partsTotal = 0.0;
                    foreach ($lineItems as &$line) {
                        $line['subtotal'] = round($line['quantity'] * $line['unit_price'], 2);
                        if ($line['type'] === 'part') {
                            $partsTotal += $line['subtotal'];
                        } else {
                            $serviceTotal += $line['subtotal'];
                        }
                    }
                    unset($line);
                    $grandTotal = round($serviceTotal + $partsTotal - $discount, 2);
                    if ($discount > $serviceTotal + $partsTotal || $grandTotal <= 0) {
                        throw new RuntimeException('The discount is invalid, or the invoice total must be greater than zero.');
                    }
                    $invoiceCode = 'INV-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
                    $pdo->prepare(
                        "INSERT INTO invoices
                            (invoice_code, order_id, total_services, total_parts, discount, grand_total,
                             payment_status, payment_method, created_by)
                         VALUES (:code, :order_id, :services, :parts, :discount, :total, 'unpaid', 'cash', :user_id)"
                    )->execute([
                        'code' => $invoiceCode,
                        'order_id' => $orderId,
                        'services' => $serviceTotal,
                        'parts' => $partsTotal,
                        'discount' => $discount,
                        'total' => $grandTotal,
                        'user_id' => $staffId,
                    ]);
                    $invoiceId = (int)$pdo->lastInsertId();
                    $insertItem = $pdo->prepare(
                        'INSERT INTO invoice_items (invoice_id, item_type, description, quantity, unit_price, subtotal)
                         VALUES (:invoice_id, :type, :description, :quantity, :unit_price, :subtotal)'
                    );
                    foreach ($lineItems as $line) {
                        $insertItem->execute([
                            'invoice_id' => $invoiceId,
                            'type' => $line['type'],
                            'description' => $line['description'],
                            'quantity' => $line['quantity'],
                            'unit_price' => $line['unit_price'],
                            'subtotal' => $line['subtotal'],
                        ]);
                    }
                    $pdo->commit();
                    $noticeMsg = 'Invoice ' . $invoiceCode . ' created.';
                } elseif ($action === 'record_transaction') {
                    $invoiceId = (int)($_POST['invoice_id'] ?? 0);
                    $amount = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_FLOAT);
                    $method = (string)($_POST['payment_method'] ?? '');
                    $type = (string)($_POST['transaction_type'] ?? 'payment');
                    if ($invoiceId <= 0 || $amount === false || $amount <= 0 || !isset($paymentMethods[$method])
                        || !in_array($type, ['payment', 'refund'], true)) {
                        throw new RuntimeException('The transaction details are invalid.');
                    }
                    $pdo->beginTransaction();
                    $invoiceStmt = $pdo->prepare('SELECT * FROM invoices WHERE id = :id FOR UPDATE');
                    $invoiceStmt->execute(['id' => $invoiceId]);
                    $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$invoice || $invoice['payment_status'] === 'cancelled') {
                        throw new RuntimeException('A valid invoice could not be found.');
                    }
                    $balance = employee_invoice_balance($pdo, $invoice);
                    $paid = $balance['paid'];
                    if ($type === 'payment' && $amount > $balance['balance']) {
                        throw new RuntimeException('The payment cannot exceed the outstanding balance.');
                    }
                    if ($type === 'refund' && $amount > $paid) {
                        throw new RuntimeException('The refund cannot exceed the amount paid.');
                    }
                    $amount = round($amount, 2);
                    $transactionCode = 'TX-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(4)));
                    $pdo->prepare(
                        'INSERT INTO cash_transactions
                            (transaction_code, invoice_id, transaction_type, amount, payment_method, note, created_by)
                         VALUES (:code, :invoice_id, :type, :amount, :method, :note, :user_id)'
                    )->execute([
                        'code' => $transactionCode,
                        'invoice_id' => $invoiceId,
                        'type' => $type,
                        'amount' => $amount,
                        'method' => $method,
                        'note' => trim((string)($_POST['transaction_note'] ?? '')),
                        'user_id' => $staffId,
                    ]);
                    $newPaid = $paid + ($type === 'payment' ? $amount : -$amount);
                    $newStatus = $newPaid <= 0 && $type === 'refund'
                        ? 'refunded'
                        : ($newPaid >= (float)$invoice['grand_total'] ? 'paid' : ($newPaid > 0 ? 'partial' : 'unpaid'));
                    $pdo->prepare(
                        'UPDATE invoices SET payment_status = :status, payment_method = :method, updated_at = NOW()
                         WHERE id = :id'
                    )->execute(['status' => $newStatus, 'method' => $method, 'id' => $invoiceId]);
                    $pdo->commit();
                    $noticeMsg = ($type === 'refund' ? 'Refund recorded: ' : 'Payment recorded: ')
                        . $transactionCode . '.';
                } else {
                    throw new RuntimeException('This cashier action is not supported.');
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($uploadedPath !== null) {
                $absolutePath = APP_ROOT . '/' . $uploadedPath;
                if (is_file($absolutePath)) {
                    unlink($absolutePath);
                }
            }
            if ($e instanceof RuntimeException) {
                $errorMsg = $e->getMessage();
            } else {
                error_log('Employee portal action failed: ' . $e->getMessage());
                $errorMsg = 'A system error prevented this action. Please try again.';
            }
        }
    }
}

$view = (string)($_GET['view'] ?? 'dashboard');
$technicianViews = ['dashboard', 'jobs', 'job', 'parts', 'history', 'schedule', 'earnings'];
$cashierViews = ['dashboard', 'invoices', 'invoice', 'new-invoice', 'transactions', 'reports'];
if (!in_array($view, $technicianViews, true)) {
    $view = 'dashboard';
}

if ($role === 'Technician' && ($_GET['view'] ?? '') === 'earnings') {
[$period,$from,$to]=tc_period((string)($_GET['period']??'month'));
$error='';$rate=null;$shifts=[];$orders=[];$chart=[];$base=0;$bonus=0;$days=0;
try {
 $stmt=$pdo->prepare('SELECT day_rate,completed_order_bonus FROM technician_pay_rates WHERE technician_id=?');$stmt->execute([$staffId]);$rate=$stmt->fetch();
 $stmt=$pdo->prepare("SELECT work_date,shift_type,status,attendance FROM technician_shifts WHERE technician_id=? AND work_date>=? AND work_date<? ORDER BY work_date DESC");$stmt->execute([$staffId,$from,$to]);$shifts=$stmt->fetchAll();
 foreach($shifts as $r){ if($r['status']==='approved' && $r['attendance']==='present')$days+=($r['shift_type']==='full'?1:.5); }
 // The first completed status event is the completion date. Fall back to updated_at for older orders.
 $stmt=$pdo->prepare("SELECT ro.id,ro.order_code,ro.status,v.license_plate,v.brand,v.model, COALESCE((SELECT MIN(h.created_at) FROM order_status_history h WHERE h.order_id=ro.id AND h.to_status='completed'),ro.updated_at) AS completed_at FROM repair_orders ro JOIN vehicles v ON v.id=ro.vehicle_id WHERE ro.assigned_to=? AND ro.status IN ('completed','delivered') HAVING completed_at>=? AND completed_at<? ORDER BY completed_at DESC");
 $stmt->execute([$staffId,$from,$to]);$orders=$stmt->fetchAll();
 $base=$days*(float)($rate['day_rate']??0);$bonus=count($orders)*(float)($rate['completed_order_bonus']??0);
 // Display activity in date buckets, respecting the selected interval.
 $bucket=[];foreach($orders as $r){$d=new DateTimeImmutable($r['completed_at']);$key=$period==='day'?$d->format('H:00'):($period==='year'?$d->format('m/Y'):($period==='month'?'Week '.(int)ceil((int)$d->format('j')/7):$d->format('d/m')));$bucket[$key]=($bucket[$key]??0)+1;}
 if($period==='week'){for($i=0;$i<7;$i++){$d=(new DateTimeImmutable($from))->modify('+'.$i.' day');$key=$d->format('d/m');$chart[$key]=$bucket[$key]??0;}}
 elseif($period==='month'){for($i=1;$i<=5;$i++){$key='Week '.$i;$chart[$key]=$bucket[$key]??0;}}
 elseif($period==='year'){for($i=1;$i<=12;$i++){$key=sprintf('%02d/%s',$i,substr($from,0,4));$chart[$key]=$bucket[$key]??0;}}
 else{foreach(['07:00','09:00','11:00','13:00','15:00','17:00'] as $key)$chart[$key]=$bucket[$key]??0;foreach($bucket as $key=>$v)$chart[$key]=$v;ksort($chart);}
} catch(Throwable $e){$error='Schedule/payroll tables are missing or the database is unavailable. Run the SQL migration.';}
$max=max(1,...array_values($chart?:[0]));

}

$search = trim((string)($_GET['q'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$selectedOrder = null;
$inspection = [];
$orderItems = [];
$orderImages = [];
$orderRequests = [];
$selectedInvoice = null;
$invoiceItems = [];
$invoiceTransactions = [];
$selectedBalance = ['paid' => 0.0, 'balance' => 0.0, 'transaction_count' => 0];
$jobs = [];
$dashboardStats = [];
$parts = [];
$requests = [];
$invoices = [];
$unbilledOrders = [];
$transactions = [];
$revenueRows = [];
$reportTotals = ['payments' => 0.0, 'refunds' => 0.0, 'net' => 0.0, 'invoice_count' => 0, 'unpaid_count' => 0];
$fromDate = (new DateTimeImmutable('today'))->modify('-29 days')->format('Y-m-d');
$toDate = (new DateTimeImmutable('today'))->format('Y-m-d');
$reportPeriod = (string)($_GET['period'] ?? 'day');

if ($role === 'Technician') {
    if ($view === 'job') {
        $selectedOrder = employee_order($pdo, (int)($_GET['id'] ?? 0), $staffId);
        if ($selectedOrder) {
            $stmt = $pdo->prepare('SELECT * FROM technical_inspections WHERE order_id = :id LIMIT 1');
            $stmt->execute(['id' => $selectedOrder['id']]);
            $inspection = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
            $stmt = $pdo->prepare('SELECT * FROM repair_order_items WHERE repair_order_id = :id ORDER BY item_type, id');
            $stmt->execute(['id' => $selectedOrder['id']]);
            $orderItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt = $pdo->prepare('SELECT * FROM work_order_images WHERE order_id = :id ORDER BY created_at DESC');
            $stmt->execute(['id' => $selectedOrder['id']]);
            $orderImages = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $stmt = $pdo->prepare(
                'SELECT pr.*, p.part_code, p.name AS part_name, p.stock_quantity
                 FROM part_requests pr JOIN parts p ON p.id = pr.part_id
                 WHERE pr.order_id = :order_id AND pr.requested_by = :user_id
                 ORDER BY pr.requested_at DESC'
            );
            $stmt->execute(['order_id' => $selectedOrder['id'], 'user_id' => $staffId]);
            $orderRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            http_response_code(404);
            $errorMsg = 'The work order assigned to you could not be found.';
        }
    } elseif ($view === 'jobs') {
        $jobs = employee_order_rows($pdo, $staffId, $search, $statusFilter);
    } elseif ($view === 'history') {
        $jobs = employee_order_rows($pdo, $staffId, $search, 'history');
    } elseif ($view === 'parts') {
        $stmt = $pdo->prepare(
            'SELECT pr.*, ro.order_code, p.part_code, p.name AS part_name, p.stock_quantity
             FROM part_requests pr JOIN repair_orders ro ON ro.id = pr.order_id
             JOIN parts p ON p.id = pr.part_id
             WHERE pr.requested_by = :user_id ORDER BY pr.requested_at DESC LIMIT 200'
        );
        $stmt->execute(['user_id' => $staffId]);
        $requests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $parts = $pdo->query('SELECT id, part_code, name, stock_quantity FROM parts WHERE is_active = 1 ORDER BY name')
            ->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare(
            "SELECT status, COUNT(*) AS amount FROM repair_orders
             WHERE assigned_to = :id GROUP BY status"
        );
        $stmt->execute(['id' => $staffId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $dashboardStats[$row['status']] = (int)$row['amount'];
        }
        $jobs = employee_order_rows($pdo, $staffId, '', '', 8);
        $priorityJobs = employee_order_rows($pdo, $staffId, '', 'received');
        $priorityJobs = array_merge($priorityJobs, employee_order_rows($pdo, $staffId, '', 'waiting_parts'));
    }
} else {
    if ($view === 'new-invoice') {
        $unbilledOrders = $pdo->query(
            "SELECT ro.id, ro.order_code, ro.received_at, ro.final_amount,
                    c.full_name AS customer_name, v.license_plate, v.brand, v.model
             FROM repair_orders ro JOIN vehicles v ON v.id = ro.vehicle_id
             LEFT JOIN users c ON c.id = COALESCE(ro.customer_id, v.customer_id)
             WHERE ro.status = 'completed'
               AND NOT EXISTS (
                    SELECT 1 FROM invoices i WHERE i.order_id = ro.id AND i.payment_status <> 'cancelled'
               )
             ORDER BY ro.updated_at DESC LIMIT 100"
        )->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($view === 'invoice') {
        $selectedInvoice = employee_invoice($pdo, (int)($_GET['id'] ?? 0));
        if ($selectedInvoice) {
            $selectedBalance = employee_invoice_balance($pdo, $selectedInvoice);
            $stmt = $pdo->prepare('SELECT * FROM invoice_items WHERE invoice_id = :id ORDER BY id');
            $stmt->execute(['id' => $selectedInvoice['id']]);
            $invoiceItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$invoiceItems) {
                $stmt = $pdo->prepare(
                    "SELECT item_type, item_name AS description, quantity, unit_price,
                            subtotal FROM repair_order_items WHERE repair_order_id = :id ORDER BY id"
                );
                $stmt->execute(['id' => $selectedInvoice['order_id']]);
                $invoiceItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            $stmt = $pdo->prepare(
                'SELECT ct.*, u.full_name AS staff_name
                 FROM cash_transactions ct JOIN users u ON u.id = ct.created_by
                 WHERE ct.invoice_id = :id ORDER BY ct.created_at DESC'
            );
            $stmt->execute(['id' => $selectedInvoice['id']]);
            $invoiceTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            http_response_code(404);
            $errorMsg = 'Invoice not found.';
        }
    } elseif ($view === 'transactions') {
        $transactions = $pdo->query(
            'SELECT ct.*, i.invoice_code, u.full_name AS staff_name
             FROM cash_transactions ct JOIN invoices i ON i.id = ct.invoice_id
             JOIN users u ON u.id = ct.created_by
             ORDER BY ct.created_at DESC LIMIT 200'
        )->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($view === 'reports') {
        try {
            [$fromDate, $toDate] = employee_date_range(
                (string)($_GET['from'] ?? $fromDate),
                (string)($_GET['to'] ?? $toDate)
            );
        } catch (RuntimeException $e) {
            $errorMsg = $e->getMessage();
        }
        if (($_GET['export'] ?? '') === 'csv') {
            $stmt = $pdo->prepare(
                'SELECT ct.transaction_code, i.invoice_code, ct.transaction_type, ct.amount,
                        ct.payment_method, ct.status, ct.created_at, u.full_name AS staff_name,
                        c.full_name AS customer_name
                 FROM cash_transactions ct JOIN invoices i ON i.id = ct.invoice_id
                 JOIN users u ON u.id = ct.created_by
                 JOIN repair_orders ro ON ro.id = i.order_id
                 JOIN vehicles v ON v.id = ro.vehicle_id
                 LEFT JOIN users c ON c.id = COALESCE(ro.customer_id, v.customer_id)
                 WHERE ct.created_at >= :from_date AND ct.created_at < DATE_ADD(:to_date, INTERVAL 1 DAY)
                 ORDER BY ct.created_at DESC'
            );
            $stmt->execute(['from_date' => $fromDate, 'to_date' => $toDate]);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="revenue-' . $fromDate . '-to-' . $toDate . '.csv"');
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Transaction code', 'Invoice', 'Type', 'Amount', 'Method', 'Status', 'Date', 'Shiftshier', 'Customer']);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $values = array_values($row);
                foreach ($values as &$value) {
                    $value = (string)$value;
                    if (preg_match('/^[=+\-@]/', $value)) {
                        $value = "'" . $value;
                    }
                }
                unset($value);
                fputcsv($output, $values);
            }
            fclose($output);
            exit;
        }
        $period = in_array($reportPeriod, ['day', 'month'], true) ? $reportPeriod : 'day';
        $dateExpression = $period === 'month' ? "DATE_FORMAT(ct.created_at, '%Y-%m')" : 'DATE(ct.created_at)';
        $stmt = $pdo->prepare(
            "SELECT {$dateExpression} AS period_key,
                    SUM(CASE WHEN ct.transaction_type = 'payment' THEN ct.amount ELSE 0 END) AS payments,
                    SUM(CASE WHEN ct.transaction_type = 'refund' THEN ct.amount ELSE 0 END) AS refunds
             FROM cash_transactions ct
             WHERE ct.status = 'completed'
               AND ct.created_at >= :from_date AND ct.created_at < DATE_ADD(:to_date, INTERVAL 1 DAY)
             GROUP BY period_key ORDER BY period_key"
        );
        $stmt->execute(['from_date' => $fromDate, 'to_date' => $toDate]);
        $revenueRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $methodStmt = $pdo->prepare(
            "SELECT payment_method,
                    SUM(CASE WHEN transaction_type = 'payment' THEN amount ELSE -amount END) AS net_amount,
                    SUM(CASE WHEN transaction_type = 'payment' THEN amount ELSE 0 END) AS payments,
                    SUM(CASE WHEN transaction_type = 'refund' THEN amount ELSE 0 END) AS refunds
             FROM cash_transactions
             WHERE status = 'completed'
               AND created_at >= :from_date AND created_at < DATE_ADD(:to_date, INTERVAL 1 DAY)
             GROUP BY payment_method ORDER BY payment_method"
        );
        $methodStmt->execute(['from_date' => $fromDate, 'to_date' => $toDate]);
        $methodTotals = $methodStmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($methodTotals as $row) {
            $reportTotals['payments'] += (float)$row['payments'];
            $reportTotals['refunds'] += (float)$row['refunds'];
            $reportTotals['net'] += (float)$row['net_amount'];
        }
        $invoiceCounts = $pdo->prepare(
            "SELECT COUNT(*) AS invoice_count,
                    COALESCE(SUM(payment_status = 'unpaid'), 0) AS unpaid_count
             FROM invoices WHERE created_at >= :from_date AND created_at < DATE_ADD(:to_date, INTERVAL 1 DAY)"
        );
        $invoiceCounts->execute(['from_date' => $fromDate, 'to_date' => $toDate]);
        $counts = $invoiceCounts->fetch(PDO::FETCH_ASSOC);
        $reportTotals['invoice_count'] = (int)$counts['invoice_count'];
        $reportTotals['unpaid_count'] = (int)$counts['unpaid_count'];
    } elseif ($view === 'invoices') {
        $statusFilter = (string)($_GET['status'] ?? '');
        $invoiceSearch = trim((string)($_GET['q'] ?? ''));
        $fromDate = trim((string)($_GET['from'] ?? ''));
        $toDate = trim((string)($_GET['to'] ?? ''));
        $sql = "SELECT i.*, ro.order_code, c.full_name AS customer_name,
                       v.license_plate, v.brand, v.model
                FROM invoices i JOIN repair_orders ro ON ro.id = i.order_id
                JOIN vehicles v ON v.id = ro.vehicle_id
                LEFT JOIN users c ON c.id = COALESCE(ro.customer_id, v.customer_id)
                WHERE 1=1";
        $params = [];
        if ($invoiceSearch !== '') {
            $sql .= ' AND (i.invoice_code LIKE :search_invoice OR ro.order_code LIKE :search_order
                      OR c.full_name LIKE :search_customer OR v.license_plate LIKE :search_plate)';
            $like = '%' . $invoiceSearch . '%';
            $params += [
                'search_invoice' => $like, 'search_order' => $like,
                'search_customer' => $like, 'search_plate' => $like,
            ];
        }
        if (in_array($statusFilter, ['unpaid', 'partial', 'paid', 'refunded'], true)) {
            $sql .= ' AND i.payment_status = :status';
            $params['status'] = $statusFilter;
        }
        if ($fromDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
            $sql .= ' AND i.created_at >= :from_date';
            $params['from_date'] = $fromDate;
        }
        if ($toDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
            $sql .= ' AND i.created_at < DATE_ADD(:to_date, INTERVAL 1 DAY)';
            $params['to_date'] = $toDate;
        }
        $sql .= ' ORDER BY i.created_at DESC LIMIT 200';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');
        $stmt = $pdo->prepare(
            "SELECT COUNT(*) AS count_today,
                    COALESCE(SUM(CASE WHEN payment_status = 'unpaid' THEN 1 ELSE 0 END), 0) AS unpaid,
                    COALESCE(SUM(CASE WHEN payment_status = 'partial' THEN 1 ELSE 0 END), 0) AS partial,
                    COALESCE(SUM(CASE WHEN payment_status = 'paid' THEN 1 ELSE 0 END), 0) AS paid
             FROM invoices WHERE created_at >= :today AND created_at < DATE_ADD(:today_end, INTERVAL 1 DAY)"
        );
        $stmt->execute(['today' => $today, 'today_end' => $today]);
        $dashboardStats = $stmt->fetch(PDO::FETCH_ASSOC);
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(CASE WHEN transaction_type = 'payment' THEN amount ELSE -amount END), 0)
             FROM cash_transactions WHERE status = 'completed'
               AND created_at >= :today AND created_at < DATE_ADD(:today_end, INTERVAL 1 DAY)"
        );
        $stmt->execute(['today' => $today, 'today_end' => $today]);
        $dashboardStats['revenue_today'] = (float)$stmt->fetchColumn();
        $stmt = $pdo->prepare(
            "SELECT COALESCE(SUM(i.grand_total), 0) FROM invoices i
             WHERE i.payment_status = 'paid' AND i.created_at >= :today
               AND i.created_at < DATE_ADD(:today_end, INTERVAL 1 DAY)
               AND NOT EXISTS (SELECT 1 FROM cash_transactions ct WHERE ct.invoice_id = i.id)"
        );
        $stmt->execute(['today' => $today, 'today_end' => $today]);
        $dashboardStats['revenue_today'] += (float)$stmt->fetchColumn();
        $dashboardStats['unpaid'] = (int)$dashboardStats['unpaid'];
        $dashboardStats['partial'] = (int)$dashboardStats['partial'];
        $dashboardStats['paid'] = (int)$dashboardStats['paid'];
        $dashboardStats['count_today'] = (int)$dashboardStats['count_today'];
        $invoices = $pdo->query(
            'SELECT i.*, ro.order_code, c.full_name AS customer_name,
                    v.license_plate, v.brand, v.model
             FROM invoices i JOIN repair_orders ro ON ro.id = i.order_id
             JOIN vehicles v ON v.id = ro.vehicle_id
             LEFT JOIN users c ON c.id = COALESCE(ro.customer_id, v.customer_id)
             ORDER BY i.created_at DESC LIMIT 8'
        )->fetchAll(PDO::FETCH_ASSOC);
        $chartFrom = (new DateTimeImmutable('today'))->modify('-6 days')->format('Y-m-d');
        $chartTo = (new DateTimeImmutable('today'))->format('Y-m-d');
        $stmt = $pdo->prepare(
            "SELECT DATE(created_at) AS period_key,
                    SUM(CASE WHEN transaction_type = 'payment' THEN amount ELSE -amount END) AS net
             FROM cash_transactions WHERE status = 'completed'
               AND created_at >= :from_date AND created_at < DATE_ADD(:to_date, INTERVAL 1 DAY)
             GROUP BY DATE(created_at)"
        );
        $stmt->execute(['from_date' => $chartFrom, 'to_date' => $chartTo]);
        $chartMap = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $chartMap[$row['period_key']] = (float)$row['net'];
        }
        $stmt = $pdo->prepare(
            "SELECT DATE(i.created_at) AS period_key, SUM(i.grand_total) AS net
             FROM invoices i WHERE i.payment_status = 'paid'
               AND i.created_at >= :from_date AND i.created_at < DATE_ADD(:to_date, INTERVAL 1 DAY)
               AND NOT EXISTS (SELECT 1 FROM cash_transactions ct WHERE ct.invoice_id = i.id)
             GROUP BY DATE(i.created_at)"
        );
        $stmt->execute(['from_date' => $chartFrom, 'to_date' => $chartTo]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $chartMap[$row['period_key']] = ($chartMap[$row['period_key']] ?? 0) + (float)$row['net'];
        }
        for ($offset = 6; $offset >= 0; $offset--) {
            $date = (new DateTimeImmutable('today'))->modify('-' . $offset . ' days');
            $key = $date->format('Y-m-d');
            $revenueRows[] = ['period_key' => $key, 'label' => $date->format('d/m'), 'net' => $chartMap[$key] ?? 0];
        }
    }
}

$pageTitle = 'Technician Center';
$viewTitles = [
    'dashboard' => 'Dashboard',
    'jobs' => 'Job List',
    'job' => 'Work Order Details',
    'parts' => 'Part Requests',
    'history' => 'Job History',
    'schedule' => 'Work Schedule',
    'earnings' => 'Statistics & Earnings',
    'invoices' => 'Invoices',
    'invoice' => 'Invoice Details',
    'new-invoice' => 'Create Invoice',
    'transactions' => 'Transaction History',
    'reports' => 'Revenue Reports',
];
$navItems = ['dashboard' => 'Overview', 'jobs' => 'Repair Orders', 'schedule' => 'Work Schedule', 'earnings' => 'Statistics & Earnings', 'parts' => 'Parts Requests', 'history' => 'Work History'];
$engineLabels = ['gasoline' => 'Gasoline', 'diesel' => 'Diesel', 'hybrid' => 'Hybrid', 'ev' => 'EV'];
$statusLabels = $allowedStatuses + ['inspecting' => 'Final inspection', 'delivered' => 'Delivered'];
$statusLabels += ['unpaid' => 'Unpaid', 'partial' => 'Partially paid', 'paid' => 'Paid', 'refunded' => 'Refunded'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= employee_e($pageTitle) ?> | REDLINE</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: {
            colors: { brand: { bg: '#f5f6f8', surface: '#fff', border: '#e2e8f0', red: '#EF4057', dark: '#111827', muted: '#64748b' } },
            fontFamily: { display: ['Barlow Condensed', 'sans-serif'], sans: ['Barlow', 'sans-serif'] }
        } } };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Barlow:wght@400;500;600;700;800&family=Barlow+Condensed:wght@500;600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/css/index_management.css?v=<?= filemtime(__DIR__ . '/../assets/css/index_management.css') ?>">
    <link rel="stylesheet" href="../assets/css/employees_command_center.css?v=<?= filemtime(__DIR__ . '/../assets/css/employees_command_center.css') ?>">
    <?php if ($role === 'Technician'): ?><link rel="stylesheet" href="../assets/css/technician_full.css?v=<?= filemtime(__DIR__ . '/../assets/css/technician_full.css') ?>"><?php endif; ?>
</head>
<body class="employee-command text-white antialiased h-screen overflow-hidden flex selection:bg-red selection:text-white">
    <div id="mobile-sidebar-backdrop" onclick="toggleEmployeeSidebar()" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-40 hidden md:hidden" aria-hidden="true"></div>
    <aside id="employee-sidebar" class="sidebar no-print w-64 shrink-0 fixed md:relative z-50 h-[calc(100%-2rem)] my-4 ml-4 py-5 px-4 overflow-y-auto transform -translate-x-full md:translate-x-0 transition-transform duration-300 ease-in-out flex flex-col justify-between" aria-label="Employee navigation">
        <div class="space-y-7">
            <a href="../index.php" class="flex items-center gap-3 px-1 hover:opacity-90 transition">
                
                <span class="w-10 h-10 rounded-xl flex items-center justify-center shadow-lg"
                    style="background-color: #EF4057; box-shadow: 0 8px 20px rgba(239,64,87,0.35);">
                    <svg class="w-5 h-5 text-white"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        viewBox="0 0 24 24">
                        <path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94z"/>
                    </svg>
                </span>

                <span class="leading-none">
                    <span class="block font-display text-2xl font-bold tracking-wider text-white">REDLINE</span>
                    <span class="block text-[10px] tracking-[.2em] text-white/80 mt-1">GARA MANAGEMENT</span>
                </span>
            </a>
            <nav class="space-y-6" aria-label="Workspace">
                <div>
                    <div class="text-[11px] font-semibold tracking-[.18em] text-white/75 px-3 mb-2">TECHNICIAN WORKSPACE</div>
                    <div class="space-y-1">
                        <?php
                        $employeeNavIcons = [
                            'dashboard' => '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>',
                            'jobs' => '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>',
                            'parts' => '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/>',
                            'schedule' => '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M7 3v4M17 3v4M3 10h18"/>',
                            'earnings' => '<path d="M3 3v18h18"/><path d="m7 16 4-5 4 3 5-8"/>',
                            'history' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
                            'invoices' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8"/>',
                            'new-invoice' => '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M12 12v7m-3-3.5h6"/>',
                            'transactions' => '<path d="M3 7h18M5 4h14a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2Z"/><path d="M7 15h4"/>',
                            'reports' => '<path d="M3 3v18h18"/><path d="m7 16 4-5 4 3 5-8"/>',
                        ];
                        $activeNavView = $view === 'job' ? 'jobs' : ($view === 'invoice' ? 'invoices' : $view);
                        ?>
                        <?php foreach ($navItems as $navView => $navTitle): ?>
                            <a href="index.php?view=<?= employee_e($navView) ?>"
                               class="nav-item<?= $activeNavView === $navView ? ' active' : '' ?>"
                               <?= $activeNavView === $navView ? 'aria-current="page"' : '' ?>>
                                <svg class="w-[18px] h-[18px] shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><?= $employeeNavIcons[$navView] ?? '' ?></svg>
                                <span><?= employee_e($navTitle) ?></span>
                            </a>
                        <?php endforeach; ?>

                    </div>
                </div>
            </nav>
        </div>
        <div class="pt-4 mt-6 border-t border-white/10 flex items-center justify-between gap-2">
            <div class="flex items-center gap-3 min-w-0">
                <div class="w-9 h-9 rounded-full bg-white/15 border border-white/20 flex items-center justify-center font-semibold text-white text-xs shrink-0"><?= employee_e(strtoupper(substr($userName, 0, 1))) ?></div>
                <div class="min-w-0">
                    <div class="text-[13px] font-semibold text-white truncate"><?= employee_e($userName) ?></div>
                    <div class="text-[11px] text-white/80 truncate"><?= employee_e($role) ?></div>
                </div>
            </div>
            <a href="../auth/logout.php" class="text-white/85 hover:text-white p-2 rounded-lg hover:bg-white/10 transition" title="Log out" aria-label="Log out">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M17 16l4-4m0 0-4-4m4 4H7m6 4v1a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3V7a3 3 0 0 1 3-3h4a3 3 0 0 1 3 3v1"/></svg>
            </a>
        </div>
    </aside>
    <main class="employee-content flex-1 min-w-0 h-full overflow-y-auto p-5 lg:p-8 space-y-6">
        <div class="employee-topbar no-print flex items-center justify-between gap-4">
            <button type="button" onclick="toggleEmployeeSidebar()" class="md:hidden text-white hover:text-red p-2 glass-dark transition" aria-label="Open navigation" aria-controls="employee-sidebar" aria-expanded="false" id="employee-menu-button">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18H4"/></svg>
            </button>
            <span class="text-xs font-semibold uppercase tracking-[.18em] text-white/75 ml-auto">REDLINE / <?= employee_e($role) ?></span>
        </div>
        <?php // Pages with their own main heading should not display the shared heading again. ?>
        <?php if (!in_array($view, ['earnings', 'jobs', 'history'], true)): ?>
        <header class="employee-heading flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-xs font-bold uppercase tracking-[.2em] text-red-300">REDLINE / TECHNICIAN CENTER</p>
                <h1 class="employee-admin-title display mt-1 text-3xl sm:text-4xl font-bold uppercase text-white"><?= employee_e($viewTitles[$view]) ?><span class="employee-title-dot">.</span></h1>
            </div>
            <div class="employee-date-pill"><span class="employee-live-dot"></span><span><?= date('d/m/Y') ?></span><span class="employee-date-divider"></span><span><?= date('H:i') ?></span></div>
        </header>
        <?php endif; ?>

        <?php if ($noticeMsg !== ''): ?>
            <div class="employee-alert employee-alert-success mb-5 rounded-xl border px-4 py-3 text-sm font-medium"><?= employee_e($noticeMsg) ?></div>
        <?php endif; ?>
        <?php if ($errorMsg !== ''): ?>
            <div class="employee-alert employee-alert-error mb-5 rounded-xl border px-4 py-3 text-sm font-medium"><?= employee_e($errorMsg) ?></div>
        <?php endif; ?>

        <?php if ($role === 'Technician'): ?>
            <?php if ($view === 'schedule'): ?>
<p class="tc-muted">Register work shifts and track manager approvals.</p>
<?php if($message):?><p class="tc-success"><?=tc_e($message)?></p><?php endif;?><?php if($error):?><p class="tc-error"><?=tc_e($error)?></p><?php endif;?>
<section class="tc-panel tc-week-panel" aria-labelledby="shift-register-title">
 <div class="tc-week-heading">
  <div><p class="tc-week-eyebrow">WEEKLY SHIFT REGISTRATION</p><h2 id="shift-register-title">Select Work Shifts</h2><p class="tc-muted">Select your available time slots, then submit them for admin approval.</p></div>
  <nav class="tc-week-nav" aria-label="Change week">
   <?php if ($schedulePrevWeek >= $minWeekSchedule): ?><a href="index.php?view=schedule&amp;week=<?=tc_e($schedulePrevWeek->format('Y-m-d'))?>" aria-label="Previous week">‹</a><?php endif; ?>
   <strong><?=tc_e($scheduleWeekStart->format('d/m'))?> – <?=tc_e($scheduleWeekEnd->format('d/m/Y'))?></strong>
   <?php if ($scheduleNextWeek <= $maxScheduleDate): ?><a href="index.php?view=schedule&amp;week=<?=tc_e($scheduleNextWeek->format('Y-m-d'))?>" aria-label="Next week">›</a><?php endif; ?>
  </nav>
 </div>
 <div class="tc-week-legend"><span><i class="tc-legend-dot available"></i>Available</span><span><i class="tc-legend-dot chosen"></i>Selected</span><span><i class="tc-legend-dot booked"></i>Requested / Approved</span></div>
 <form id="tc-week-form" method="post" action="index.php?view=schedule&amp;week=<?=tc_e($scheduleWeekStart->format('Y-m-d'))?>">
  <input type="hidden" name="csrf" value="<?=tc_e($csrfToken)?>"><input type="hidden" name="action" value="create_week">
  <div class="tc-week-scroll"><div class="tc-week-grid">
   <div class="tc-week-corner">SHIFT / DAY</div>
   <?php foreach ($scheduleWeekDates as $day): ?>
    <div class="tc-week-day <?= $day->format('Y-m-d') === $todaySchedule->format('Y-m-d') ? 'is-today' : '' ?>"><strong><?=tc_e(['Mon','Tue','Wed','Thu','Fri','Sat','Sun'][(int)$day->format('N') - 1])?></strong><span><?=tc_e($day->format('d/m'))?></span></div>
   <?php endforeach; ?>
   <?php foreach (['morning'=>['MORNING','07:30 – 11:30'],'afternoon'=>['AFTERNOON','13:00 – 17:00']] as $shiftKey=>$shiftMeta): ?>
    <div class="tc-week-shift"><strong><?=tc_e($shiftMeta[0])?></strong><small><?=tc_e($shiftMeta[1])?></small></div>
    <?php foreach ($scheduleWeekDates as $day):
     $dateKey = $day->format('Y-m-d');
     $busyStatus = $scheduleBusy[$dateKey][$shiftKey] ?? null;
     $expired = $day < $todaySchedule || $day > $maxScheduleDate;
     $disabled = $expired || $busyStatus !== null;
    ?>
    <label class="tc-slot <?= $disabled ? 'is-disabled' : '' ?> <?= $busyStatus ? 'is-booked' : '' ?>" title="<?=tc_e($busyStatus === 'approved' ? 'Approved' : ($busyStatus === 'pending' ? 'Pending approval' : ($expired ? 'Outside registration period' : 'Select shift')))?>">
     <input class="tc-slot-input" type="checkbox" name="slots[]" value="<?=tc_e($dateKey.'|'.$shiftKey)?>" <?= $disabled ? 'disabled' : '' ?> aria-label="<?=tc_e($shiftMeta[0].' on '.$day->format('d/m/Y'))?>">
     <span class="tc-slot-mark" aria-hidden="true"><?= $busyStatus === 'approved' ? '✓' : ($busyStatus === 'pending' ? '◷' : ($expired ? '—' : '＋')) ?></span>
     <small><?= $busyStatus === 'approved' ? 'Approved' : ($busyStatus === 'pending' ? 'Pending approval' : ($expired ? 'Unavailable' : 'Select shift')) ?></small>
    </label>
    <?php endforeach; ?>
   <?php endforeach; ?>
  </div></div>
  <div class="tc-week-submit"><div class="tc-week-count"><strong id="tc-slot-count">0 shifts selected</strong><small>You may select both morning and afternoon shifts on the same day.</small></div><label class="tc-week-note">Notes (optional)<textarea name="note" maxlength="500" rows="2" placeholder="Example: Available on Saturday afternoon..."></textarea></label><button type="submit" class="tc-btn" id="tc-submit-week" disabled>Submit Registration <span aria-hidden="true">→</span></button></div>
 </form>
</section>
<script>
(function(){
 const form=document.getElementById('tc-week-form'); if(!form) return;
 const boxes=[...form.querySelectorAll('.tc-slot-input:not(:disabled)')];
 const count=document.getElementById('tc-slot-count'),submit=document.getElementById('tc-submit-week');
 function update(){const n=boxes.filter(x=>x.checked).length;count.textContent=n+' shifts selected';submit.disabled=n===0;}
 boxes.forEach(x=>x.addEventListener('change',update));update();
 form.addEventListener('submit',e=>{if(!boxes.some(x=>x.checked)){e.preventDefault();update();}});
})();
</script>
<section class="tc-panel"><h2>My Schedule</h2><div class="tc-tablewrap"><table class="tc-table"><thead><tr><th>Date</th><th>Shift</th><th>Status</th><th>Attendance</th><th>Notes</th><th>Actions</th></tr></thead><tbody><?php foreach($rows as $r):?><tr><td><?=tc_e(date('d/m/Y',strtotime($r['work_date'])))?></td><td><?=tc_e($shifts[$r['shift_type']]??$r['shift_type'])?></td><td><span class="tc-chip <?=tc_e($r['status'])?>"><?=tc_e($statuses[$r['status']]??$r['status'])?></span></td><td><span class="tc-chip <?=tc_e($r['attendance'])?>"><?=tc_e($attendance[$r['attendance']]??$r['attendance'])?></span></td><td><?=tc_e($r['note'])?></td><td><?php if($r['status']==='pending'):?><form method="post" action="index.php?view=schedule"><input type="hidden" name="csrf" value="<?=tc_e($csrfToken)?>"><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><button class="tc-btn secondary">Shiftncel</button></form><?php else:?>—<?php endif;?></td></tr><?php endforeach;?><?php if(!$rows):?><tr><td colspan="6">No shifts registered yet.</td></tr><?php endif;?></tbody></table></div></section>
<p class="tc-note">Approved shifts are not automatically included in payroll. Admin must confirm attendance before workdays are counted.</p>

            <?php elseif ($view === 'earnings'): ?>
                <?php
                    $earnTotal = $base + $bonus;
                    $earnPart = $earnTotal > 0 ? round($base / $earnTotal * 100) : 0;
                    $earnPeriod = ['day'=>'Today','week'=>'This Week','month'=>'This Month','year'=>'This Year'][$period] ?? 'This Month';
                ?>
                <section class="rl-page-intro">
                    <div><span class="rl-eyebrow">PERFORMANCE / TECHNICIAN</span><h2>Performance <em>&amp;</em> Earnings<span class="rl-dot">.</span></h2><p>Track completed repair orders, confirmed workdays, and estimated earnings.</p></div>
                    <div class="rl-period-label"><span>●</span> <?= tc_e(date('d/m/Y',strtotime($from))) ?> — <?= tc_e(date('d/m/Y',strtotime($to.' -1 day'))) ?></div>
                </section>
                <?php if ($error): ?><div class="rl-message-error"><?=tc_e($error)?></div><?php endif; ?>
                <div class="rl-filter-strip" role="group" aria-label="Select reporting period">
                    <div class="rl-filter-caption">REPORTING PERIOD</div>
                    <div class="rl-period-tabs"><?php foreach(['day'=>'Date','week'=>'Week','month'=>'Month','year'=>'Year'] as $key=>$label): ?><a class="rl-period-btn <?= $period===$key?'is-current':'' ?>" href="index.php?view=earnings&amp;period=<?=tc_e($key)?>" <?= $period===$key?'aria-current="page"':'' ?>><?=tc_e($label)?></a><?php endforeach; ?></div>
                    <span class="rl-filter-period"><?=tc_e($earnPeriod)?></span>
                </div>
                <section class="rl-earn-kpis" aria-label="Performance summary">
                    <article class="rl-kpi"><div class="rl-kpi-label">COMPLETED ORDERS <span class="rl-kpi-symbol">↗</span></div><strong><?=count($orders)?></strong><small>Repair orders in this period</small></article>
                    <article class="rl-kpi"><div class="rl-kpi-label">WORKDAYS <span class="rl-kpi-symbol">◷</span></div><strong><?=number_format($days,1,',','.')?></strong><small>Confirmed by admin</small></article>
                    <article class="rl-kpi"><div class="rl-kpi-label">BASE PAY <span class="rl-kpi-symbol">▤</span></div><strong class="rl-money"><?=tc_e($rate?tc_money($base):'—')?></strong><small><?= $rate ? 'Based on the configured daily rate' : 'Rate not configured' ?></small></article>
                    <article class="rl-kpi rl-kpi-featured"><div class="rl-kpi-label">ESTIMATED TOTAL EARNINGS <span class="rl-kpi-symbol">◈</span></div><strong class="rl-money"><?=tc_e($rate?tc_money($earnTotal):'—')?></strong><small>Shift pay + completion bonuses</small></article>
                </section>
                <div class="rl-earn-main">
                    <section class="rl-surface rl-chart-card">
                        <header class="rl-surface-header"><div><span class="rl-eyebrow">WORK ACTIVITY</span><h3>Completed Orders Chart</h3><p>Work distribution for <?=tc_e($earnPeriod)?></p></div><span class="rl-count-pill"><?=count($orders)?> orders</span></header>
                        <div class="rl-chart-wrap"><div class="rl-chart-grid"><span></span><span></span><span></span><span></span></div><div class="rl-chart-columns">
                        <?php foreach($chart as $label=>$value): ?><div class="rl-chart-column" title="<?=tc_e($label)?>: <?=(int)$value?> orders"><span class="rl-chart-number"><?= (int)$value ?></span><div class="rl-chart-rail"><div class="rl-chart-fill" style="height:<?= $value > 0 ? max(8, round($value/$max*100)) : 2 ?>%"></div></div><span class="rl-chart-day"><?=tc_e($label)?></span></div><?php endforeach; ?>
                        <?php if (!$chart): ?><p class="rl-empty-chart">No data to display.</p><?php endif; ?>
                        </div></div>
                        <footer class="rl-chart-foot"><span class="rl-indicator"></span> Based on your completed repair orders</footer>
                    </section>
                    <section class="rl-surface rl-salary-card"><header class="rl-surface-header"><div><span class="rl-eyebrow">PAY BREAKDOWN</span><h3>Earnings Breakdown</h3><p>Pay is calculated using confirmed attendance</p></div></header>
                        <div class="rl-salary-total"><small>ESTIMATED TOTAL EARNINGS</small><strong><?=tc_e($rate?tc_money($earnTotal):'Not configured')?></strong></div>
                        <div class="rl-salary-track" role="img" aria-label="Base pay percentage: <?= (int)$earnPart ?> percent"><span style="width:<?= (int)$earnPart ?>%"></span></div>
                        <div class="rl-salary-line"><span><i class="rl-salary-dot base"></i>Base pay</span><strong><?=tc_e($rate?tc_money($base):'—')?></strong></div>
                        <div class="rl-salary-line"><span><i class="rl-salary-dot bonus"></i>Completion bonus</span><strong><?=tc_e($rate?tc_money($bonus):'—')?></strong></div>
                        <div class="rl-salary-rates"><div><small>Daily rate</small><strong><?=tc_e($rate?tc_money($rate['day_rate']):'Not configured')?></strong></div><div><small>Bonus per order</small><strong><?=tc_e($rate?tc_money($rate['completed_order_bonus']):'Not configured')?></strong></div></div>
                        <p class="rl-pay-disclaimer">ⓘ This is an estimate excluding allowances, deductions, and final payroll adjustments. Current rates may affect historical estimates.</p>
                    </section>
                </div>
                <section class="rl-surface rl-completed-card"><header class="rl-surface-header"><div><span class="rl-eyebrow">COMPLETED WORK</span><h3>Orders Completed This Period</h3><p>Work details and bonuses by order</p></div><span class="rl-count-pill"><?=count($orders)?> results</span></header>
                    <div class="rl-data-scroll"><table class="rl-data-table"><thead><tr><th>ORDER ID</th><th>VEHICLE DETAILS</th><th>COMPLETED</th><th>ORDER BONUS</th><th></th></tr></thead><tbody>
                    <?php foreach($orders as $r): ?><tr><td><span class="rl-code"><?=tc_e($r['order_code'])?></span></td><td><strong><?=tc_e(trim($r['brand'].' '.$r['model']))?></strong><small><?=tc_e($r['license_plate'])?></small></td><td><?=tc_e(date('d/m/Y · H:i',strtotime($r['completed_at'])))?></td><td class="rl-reward"><?=tc_e($rate?tc_money($rate['completed_order_bonus']):'—')?></td><td><a class="rl-view-link" href="index.php?view=job&amp;id=<?=(int)$r['id']?>">View order <span>↗</span></a></td></tr><?php endforeach;?>
                    <?php if (!$orders): ?><tr><td colspan="5" class="rl-empty-row">No repair orders completed in this period.</td></tr><?php endif; ?>
                    </tbody></table></div>
                </section>

            <?php elseif ($view === 'dashboard'): ?>
                <?php
                    $totalAssigned = array_sum($dashboardStats);
                    $doneCount = (int)($dashboardStats['completed'] ?? 0);
                    $activeCount = (int)($dashboardStats['diagnosing'] ?? 0) + (int)($dashboardStats['repairing'] ?? 0);
                    $waitingCount = (int)($dashboardStats['received'] ?? 0) + (int)($dashboardStats['waiting_parts'] ?? 0);
                    $completionPct = $totalAssigned > 0 ? (int)round($doneCount * 100 / $totalAssigned) : 0;
                    $statusBreakdown = [
                        ['received', 'Awaiting intake', 'sky'],
                        ['diagnosing', 'Diagnosing', 'amber'],
                        ['repairing', 'Under repair', 'violet'],
                        ['waiting_parts', 'Waiting for parts', 'orange'],
                        ['completed', 'Completed', 'green'],
                    ];
                ?>
                <section class="employee-hero" aria-label="Technician dashboard introduction">
                    <div class="employee-hero-copy">
                        <div class="employee-hero-kicker"><span class="employee-live-dot"></span> TECHNICIAN COMMAND CENTER <span class="employee-hero-line"></span> REDLINE GARAGE</div>
                        <h2>Welcome back,<br><span><?= employee_e($userName) ?></span><span class="employee-red-dot">.</span></h2>
                        <p>Track repairs, job progress, and your work schedule in one place.</p>
                        <div class="employee-hero-actions">
                            <a class="employee-action-primary" href="index.php?view=jobs">View assigned orders <span aria-hidden="true">↗</span></a>
                            <a class="employee-action-secondary" href="index.php?view=schedule">Register shifts <span aria-hidden="true">→</span></a>
                        </div>
                    </div>
                    <div class="employee-hero-emblem" aria-hidden="true"><span>R</span><small>EST. REDLINE</small></div>
                </section>
                <section class="employee-metrics" aria-label="Work statistics">
                    <a class="employee-metric" href="index.php?view=jobs"><div class="employee-metric-top"><span>TOTAL ASSIGNED ORDERS</span><span class="employee-metric-icon">▦</span></div><strong><?= $totalAssigned ?></strong><small>All your assigned orders <span aria-hidden="true">↗</span></small></a>
                    <a class="employee-metric" href="index.php?view=jobs&amp;status=repairing"><div class="employee-metric-top"><span>IN PROGRESS</span><span class="employee-metric-icon amber">◷</span></div><strong><?= $activeCount ?></strong><small>Diagnosis and repairs <span aria-hidden="true">↗</span></small></a>
                    <a class="employee-metric" href="index.php?view=history"><div class="employee-metric-top"><span>COMPLETED</span><span class="employee-metric-icon green">✓</span></div><strong><?= $doneCount ?></strong><small><?= $completionPct ?>% of all orders <span aria-hidden="true">↗</span></small></a>
                    <a class="employee-metric" href="index.php?view=jobs"><div class="employee-metric-top"><span>NEEDS ATTENTION</span><span class="employee-metric-icon rose">!</span></div><strong><?= $waitingCount ?></strong><small>Awaiting intake / parts <span aria-hidden="true">↗</span></small></a>
                </section>
                <section class="employee-dashboard-grid">
                    <div class="employee-panel employee-job-panel">
                        <div class="employee-panel-heading"><div><p class="employee-section-kicker">WORK QUEUE / 01</p><h2>Recent Repair Orders</h2><p>Jobs assigned to you</p></div><a class="employee-panel-link" href="index.php?view=jobs">View All Orders <span>↗</span></a></div>
                        <div class="employee-job-list">
                            <?php foreach (array_slice($jobs, 0, 6) as $job): ?>
                                <a class="employee-job-row" href="index.php?view=job&amp;id=<?= (int)$job['id'] ?>">
                                    <span class="employee-job-avatar">↗</span>
                                    <span class="employee-job-info"><strong><?= employee_e($job['order_code']) ?> <span>·</span> <?= employee_e($job['license_plate']) ?></strong><small><?= employee_e(trim($job['brand'] . ' ' . $job['model'])) ?> · <?= employee_e($job['customer_name'] ?? 'Customer name unavailable') ?></small></span>
                                    <span class="employee-job-status <?= employee_e($job['status']) ?>"><?= employee_e($statusLabels[$job['status']] ?? $job['status']) ?></span><span class="employee-job-arrow">›</span>
                                </a>
                            <?php endforeach; ?>
                            <?php if (!$jobs): ?><div class="employee-empty">No repair orders have been assigned to you.</div><?php endif; ?>
                        </div>
                    </div>
                    <div class="employee-dashboard-side">
                        <section class="employee-panel employee-progress-panel">
                            <div class="employee-panel-heading"><div><p class="employee-section-kicker">PERFORMANCE / 02</p><h2>Job Progress</h2></div><span class="employee-mini-badge">Overview</span></div>
                            <div class="employee-progress-total"><strong><?= $completionPct ?><span>%</span></strong><span>Completed Orders</span></div>
                            <div class="employee-progress-track"><span style="width: <?= $completionPct ?>%"></span></div>
                            <div class="employee-progress-list">
                                <?php foreach ($statusBreakdown as [$statusKey, $statusLabel, $color]): ?>
                                    <div class="employee-progress-line"><span class="employee-status-dot <?= $color ?>"></span><span><?= employee_e($statusLabel) ?></span><strong><?= (int)($dashboardStats[$statusKey] ?? 0) ?></strong></div>
                                <?php endforeach; ?>
                            </div>
                        </section>
                        <section class="employee-panel employee-quick-panel"><div class="employee-panel-heading"><div><p class="employee-section-kicker">QUICK ACCESS / 03</p><h2>Quick Access</h2></div></div><div class="employee-quick-links"><a href="index.php?view=earnings&amp;period=month"><span class="employee-quick-icon">↗</span><span><strong>Statistics &amp; Earnings</strong><small>Daily, weekly, monthly, yearly</small></span><b>›</b></a><a href="index.php?view=schedule"><span class="employee-quick-icon">▦</span><span><strong>Work Schedule</strong><small>Register and track shifts</small></span><b>›</b></a><a href="index.php?view=parts"><span class="employee-quick-icon">⬡</span><span><strong>Parts Requests</strong><small>Track parts requests</small></span><b>›</b></a></div></section>
                    </div>
                </section>
                <?php if ($priorityJobs): ?>
                <section class="employee-panel employee-attention"><div class="employee-panel-heading"><div><p class="employee-section-kicker">ACTION REQUIRED / 04</p><h2>Jobs Requiring Attention</h2></div><span class="employee-mini-badge warning"><?= count($priorityJobs) ?> orders</span></div><div class="employee-attention-grid"><?php foreach (array_slice($priorityJobs, 0, 4) as $job): ?><a href="index.php?view=job&amp;id=<?= (int)$job['id'] ?>"><strong><?= employee_e($job['order_code']) ?></strong><small><?= employee_e($job['license_plate']) ?> · <?= employee_e($job['customer_name'] ?? 'Customer') ?></small><span><?= employee_e($statusLabels[$job['status']] ?? $job['status']) ?> ↗</span></a><?php endforeach; ?></div></section>
                <?php endif; ?>
            <?php elseif ($view === 'jobs' || $view === 'history'): ?>
                <?php
                    $isHistoryView = $view === 'history';
                    $jobCount = count($jobs);
                    $jobDone = count(array_filter($jobs, static fn($j) => in_array($j['status'], ['completed','delivered'],true)));
                    $jobActive = count(array_filter($jobs, static fn($j) => in_array($j['status'], ['diagnosing','repairing','inspecting'],true)));
                    $jobWaiting = count(array_filter($jobs, static fn($j) => in_array($j['status'], ['received','waiting_parts'],true)));
                    $jobStatusStyles = ['received'=>'pending','waiting_parts'=>'pending','diagnosing'=>'active','repairing'=>'active','inspecting'=>'active','completed'=>'done','delivered'=>'done'];
                ?>
                <section class="rl-page-intro"><div><span class="rl-eyebrow">WORKSHOP / <?= $isHistoryView ? 'HISTORY' : 'REPAIR ORDERS' ?></span><h2><?= $isHistoryView ? 'Work History' : 'Repair Orders' ?><span class="rl-dot">.</span></h2><p><?= $isHistoryView ? 'Search completed orders and technical findings.' : 'Manage, track, and update your assigned repair orders.' ?></p></div><span class="rl-count-pill"><?= $jobCount ?> orders shown</span></section>
                <section class="rl-orders-stats" aria-label="Current list summary"><div><span>TOTAL ORDERS</span><strong><?= $jobCount ?></strong></div><div><span>IN PROGRESS</span><strong><?= $jobActive ?></strong></div><div><span>AWAITING ACTION</span><strong><?= $jobWaiting ?></strong></div><div><span>COMPLETED</span><strong><?= $jobDone ?></strong></div></section>
                <form method="get" class="rl-filter-strip rl-orders-filter">
                    <input type="hidden" name="view" value="<?=employee_e($view)?>">
                    <label class="rl-search-field"><span aria-hidden="true">⌕</span><input name="q" type="search" value="<?=employee_e($search)?>" placeholder="Search order code, license plate, customer..." aria-label="Search repair orders"></label>
                    <?php if (!$isHistoryView): ?><select name="status" aria-label="Filter by status"><option value="">All statuses</option><?php foreach (['received','diagnosing','repairing','waiting_parts','inspecting','completed','delivered'] as $statusKey): ?><option value="<?=employee_e($statusKey)?>" <?= $statusFilter === $statusKey?'selected':'' ?>><?=employee_e($statusLabels[$statusKey]??$statusKey)?></option><?php endforeach;?></select><?php endif; ?>
                    <button type="submit" class="rl-filter-submit">Filter Orders <span>↗</span></button>
                    <?php if ($search!=='' || $statusFilter!==''): ?><a class="rl-filter-clear" href="index.php?view=<?=employee_e($view)?>">Reset</a><?php endif; ?>
                </form>
                <section class="rl-orders-section" aria-label="<?= $isHistoryView ? 'Work History' : 'Repair order list' ?>">
                    <header class="rl-surface-header rl-orders-section-head">
                        <div><span class="rl-eyebrow"><?= $isHistoryView ? 'SERVICE ARCHIVE' : 'ASSIGNED WORK' ?></span>
                            <h3><?= $isHistoryView ? 'Completed Orders' : 'Assigned Repair Orders' ?></h3>
                            <p><?= $isHistoryView ? 'Previously completed jobs' : 'Select an order to view and update the job' ?></p>
                        </div>
                        <span class="rl-count-pill"><?= $jobCount ?> results</span>
                    </header>
                    <div class="rl-order-card-grid">
                        <?php foreach ($jobs as $job):
                            $jobStatus = (string)$job['status'];
                            $jobPercent = ['received'=>20,'diagnosing'=>40,'repairing'=>60,'waiting_parts'=>60,'inspecting'=>80,'completed'=>100,'delivered'=>100][$jobStatus] ?? 20;
                            $jobDescription = $isHistoryView
                                ? ($job['technical_note'] ?: 'No result notes yet')
                                : ($job['customer_request'] ?: ($job['service_type'] ?: 'No job description available'));
                            $jobDate = $isHistoryView ? $job['completed_at'] : $job['received_at'];
                        ?>
                        <article class="rl-order-card">
                            <div class="rl-order-card-top">
                                <div class="rl-order-card-id"><span class="rl-order-card-eyebrow">REPAIR ORDER</span><strong><?= employee_e($job['order_code']) ?></strong></div>
                                <span class="rl-state rl-state-<?= employee_e($jobStatusStyles[$jobStatus] ?? 'pending') ?>"><i></i><?= employee_e($statusLabels[$jobStatus] ?? $jobStatus) ?></span>
                            </div>
                            <div class="rl-order-card-vehicle"><div class="rl-order-car-icon" aria-hidden="true">⌁</div><div><h4><?= employee_e(trim($job['brand'] . ' ' . $job['model'])) ?></h4><span><?= employee_e($job['license_plate']) ?></span></div></div>
                            <div class="rl-order-card-details">
                                <div><span>CUSTOMER</span><strong><?= employee_e($job['customer_name'] ?? '—') ?></strong></div>
                                <div><span>ENGINE</span><strong><?= employee_e($engineLabels[$job['engine_type']] ?? 'Other') ?></strong></div>
                                <div><span><?= $isHistoryView ? 'COMPLETED' : 'RECEIVED' ?></span><strong><?= employee_e($jobDate ? date('d/m/Y', strtotime($jobDate)) : '—') ?></strong></div>
                            </div>
                            <div class="rl-order-card-task"><span><?= $isHistoryView ? 'TECHNICAL FINDINGS' : 'WORK DESCRIPTION' ?></span><p title="<?= employee_e($jobDescription) ?>"><?= employee_e($jobDescription) ?></p></div>
                            <div class="rl-order-card-footer">
                                <div class="rl-order-card-progress"><div><span>PROGRESS</span><strong><?= $jobPercent ?>%</strong></div><div class="rl-inline-progress" role="progressbar" aria-valuenow="<?= $jobPercent ?>" aria-valuemin="0" aria-valuemax="100"><span style="width:<?= $jobPercent ?>%"></span></div></div>
                                <a class="rl-view-link" href="index.php?view=job&amp;id=<?= (int)$job['id'] ?>">View Details <span aria-hidden="true">↗</span></a>
                            </div>
                        </article>
                        <?php endforeach; ?>
                        <?php if (!$jobs): ?><div class="rl-order-card-empty">No repair orders match your filters.</div><?php endif; ?>
                    </div>
                </section>
            <?php elseif ($view === 'job' && $selectedOrder): ?>
                <?php
                    $isHybrid = $selectedOrder['engine_type'] === 'hybrid';
                    $editable = !in_array($selectedOrder['status'], ['completed', 'delivered'], true);
                    $jobStatusLabel = $statusLabels[$selectedOrder['status']] ?? $selectedOrder['status'];
                ?>
                <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-brand-border bg-white p-5">
                    <div><p class="text-xs font-bold uppercase tracking-wider text-brand-muted">Work order</p><h2 class="display mt-1 text-2xl font-bold text-brand-dark"><?= employee_e($selectedOrder['order_code']) ?></h2><p class="mt-1 text-sm text-brand-muted"><?= employee_e($selectedOrder['customer_name'] ?? '—') ?> · <?= employee_e($selectedOrder['license_plate']) ?> · <?= employee_e($jobStatusLabel) ?></p></div>
                    <a href="index.php?view=jobs" class="rounded-xl border border-brand-border px-4 py-2 text-sm font-semibold">Job list</a>
                </div>
                <?php employee_progress_tracker($selectedOrder['status']); ?>
                <div class="grid gap-5 xl:grid-cols-2">
                    <section class="rounded-2xl border border-brand-border bg-white p-5">
                        <h3 class="font-bold text-brand-dark">Vehicle information</h3>
                        <dl class="mt-4 grid grid-cols-2 gap-4 text-sm">
                            <div><dt class="text-xs text-brand-muted">License plate</dt><dd class="mt-1 font-bold"><?= employee_e($selectedOrder['license_plate']) ?></dd></div>
                            <div><dt class="text-xs text-brand-muted">Make</dt><dd class="mt-1 font-bold"><?= employee_e($selectedOrder['brand']) ?></dd></div>
                            <div><dt class="text-xs text-brand-muted">Model</dt><dd class="mt-1 font-bold"><?= employee_e($selectedOrder['model']) ?></dd></div>
                            <div><dt class="text-xs text-brand-muted">Year</dt><dd class="mt-1 font-bold"><?= employee_e((string)($selectedOrder['vehicle_year'] ?: '—')) ?></dd></div>
                            <div><dt class="text-xs text-brand-muted">Engine type</dt><dd class="mt-1 font-bold"><?= employee_e($engineLabels[$selectedOrder['engine_type']] ?? 'Unknown') ?></dd></div>
                            <div><dt class="text-xs text-brand-muted">Current odometer</dt><dd class="mt-1 font-bold"><?= number_format((int)($selectedOrder['vehicle_odometer'] ?: $selectedOrder['odometer'] ?: $selectedOrder['km_in'])) ?> km</dd></div>
                        </dl>
                    </section>
                    <section class="rounded-2xl border border-brand-border bg-white p-5">
                        <h3 class="font-bold text-brand-dark">Repair information</h3>
                        <div class="mt-4 space-y-3 text-sm">
                            <div><p class="text-xs text-brand-muted">Reported symptoms</p><p class="mt-1 whitespace-pre-wrap"><?= employee_e($selectedOrder['symptoms'] ?: 'Not recorded') ?></p></div>
                            <div><p class="text-xs text-brand-muted">Customer request</p><p class="mt-1 whitespace-pre-wrap"><?= employee_e($selectedOrder['customer_request'] ?: 'Not recorded') ?></p></div>
                            <div><p class="text-xs text-brand-muted">Work order items</p>
                                <?php if ($orderItems): ?><ul class="mt-1 list-inside list-disc"><?php foreach ($orderItems as $item): ?><li><?= employee_e($item['item_name']) ?> (<?= employee_e($item['item_type'] === 'part' ? 'Part' : 'Service') ?>)</li><?php endforeach; ?></ul><?php else: ?><p class="mt-1"><?= employee_e($selectedOrder['service_type'] ?: 'No item details available') ?></p><?php endif; ?>
                            </div>
                        </div>
                    </section>
                </div>
                <section class="mt-5 rounded-2xl border border-brand-border bg-white p-5">
                    <div class="mb-4"><h3 class="font-bold text-brand-dark">Inspection results and technical notes</h3><p class="mt-1 text-xs text-brand-muted">Enter actual inspection or diagnostic results; leave blank if not measured.</p></div>
                    <?php if ($editable): ?>
                    <form method="post" class="grid gap-4 md:grid-cols-2">
                        <input type="hidden" name="csrf" value="<?= employee_e($csrfToken) ?>"><input type="hidden" name="action" value="save_inspection"><input type="hidden" name="order_id" value="<?= (int)$selectedOrder['id'] ?>">
                        <?php foreach ([
                            'technical_note' => 'Technical notes',
                            'customer_complaints' => 'Customer-reported issues',
                            'inspection_needed' => 'Items to inspect',
                            'repairs_performed' => 'Repairs performed',
                            'engine_result' => 'Engine system',
                            'brakes_result' => 'Brake system',
                            'electrical_result' => 'Electrical system',
                            'ac_result' => 'Air conditioning system',
                        ] as $field => $label): ?>
                            <label class="text-xs font-semibold text-slate-600"><?= employee_e($label) ?><textarea name="<?= employee_e($field) ?>" rows="3" class="mt-1 block w-full rounded-xl border border-brand-border px-3 py-2 text-sm"><?= employee_e($inspection[$field] ?? '') ?></textarea></label>
                        <?php endforeach; ?>
                        <?php if ($isHybrid): ?>
                            <div class="md:col-span-2 rounded-xl border border-amber-200 bg-amber-50 p-4">
                                <h4 class="font-bold text-amber-900">Hybrid system inspection</h4>
                                <p class="mt-1 text-xs text-amber-800">The values below are entered and saved manually; no automatic diagnostic data is available.</p>
                                <div class="mt-3 grid gap-3 md:grid-cols-2">
                                    <label class="text-xs font-semibold">Hybrid battery condition<input name="hybrid_battery_status" value="<?= employee_e($inspection['hybrid_battery_status'] ?? '') ?>" class="mt-1 block w-full rounded-lg border border-amber-200 bg-white px-3 py-2 text-sm"></label>
                                    <label class="text-xs font-semibold">Battery voltage (V)<input type="number" step="0.01" min="0" name="hybrid_voltage" value="<?= employee_e(isset($inspection['hybrid_voltage']) ? (string)$inspection['hybrid_voltage'] : '') ?>" class="mt-1 block w-full rounded-lg border border-amber-200 bg-white px-3 py-2 text-sm"></label>
                                    <label class="text-xs font-semibold">Battery temperature (°C)<input type="number" step="0.1" name="hybrid_temperature" value="<?= employee_e(isset($inspection['hybrid_temperature']) ? (string)$inspection['hybrid_temperature'] : '') ?>" class="mt-1 block w-full rounded-lg border border-amber-200 bg-white px-3 py-2 text-sm"></label>
                                    <?php foreach (['hybrid_cooling_result' => 'Battery cooling system', 'inverter_result' => 'Inverter', 'electric_motor_result' => 'Electric motor', 'dtc_codes' => 'DTC codes'] as $field => $label): ?>
                                        <label class="text-xs font-semibold"><?= employee_e($label) ?><textarea name="<?= employee_e($field) ?>" rows="2" class="mt-1 block w-full rounded-lg border border-amber-200 bg-white px-3 py-2 text-sm"><?= employee_e($inspection[$field] ?? '') ?></textarea></label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="md:col-span-2"><button class="rounded-xl bg-brand-dark px-5 py-3 text-sm font-bold text-white">Save inspection results</button></div>
                    </form>
                    <?php else: ?>
                        <p class="text-sm text-brand-muted">This work order is complete. Results are locked to preserve the job history.</p>
                        <div class="mt-4 grid gap-3 md:grid-cols-2"><?php foreach ($inspection as $field => $value): if (!in_array($field, ['id', 'order_id', 'updated_by', 'updated_at'], true) && $value !== null && $value !== ''): ?><div><p class="text-xs text-brand-muted"><?= employee_e(str_replace('_', ' ', $field)) ?></p><p class="mt-1 whitespace-pre-wrap text-sm"><?= employee_e((string)$value) ?></p></div><?php endif; endforeach; ?></div>
                    <?php endif; ?>
                </section>
                <div class="mt-5 grid gap-5 xl:grid-cols-2">
                    <section class="rounded-2xl border border-brand-border bg-white p-5">
                        <h3 class="font-bold text-brand-dark">Update job status</h3>
                        <?php if ($editable): ?>
                        <form method="post" class="mt-4 space-y-3">
                            <input type="hidden" name="csrf" value="<?= employee_e($csrfToken) ?>"><input type="hidden" name="action" value="update_job_status"><input type="hidden" name="order_id" value="<?= (int)$selectedOrder['id'] ?>">
                            <select name="job_status" required class="w-full rounded-xl border border-brand-border bg-white px-3 py-3 text-sm"><?php foreach ($allowedStatuses as $key => $label): ?><option value="<?= employee_e($key) ?>" <?= $selectedOrder['status'] === $key ? 'selected' : '' ?>><?= employee_e($label) ?></option><?php endforeach; ?></select>
                            <textarea name="progress_note" rows="2" placeholder="Add a note for this update" class="w-full rounded-xl border border-brand-border px-3 py-2 text-sm"></textarea>
                            <button class="rounded-xl bg-brand-red px-5 py-3 text-sm font-bold text-white">Update job</button>
                        </form>
                        <?php else: ?><p class="mt-3 text-sm text-brand-muted">This work order is complete or has been handed over.</p><?php endif; ?>
                    </section>
                    <section class="rounded-2xl border border-brand-border bg-white p-5">
                        <h3 class="font-bold text-brand-dark">Before and after photos</h3>
                        <?php if ($editable): ?>
                        <form method="post" enctype="multipart/form-data" class="mt-4 flex flex-wrap gap-3">
                            <input type="hidden" name="csrf" value="<?= employee_e($csrfToken) ?>"><input type="hidden" name="action" value="upload_work_image"><input type="hidden" name="order_id" value="<?= (int)$selectedOrder['id'] ?>">
                            <select name="image_type" class="rounded-xl border border-brand-border bg-white px-3 py-2 text-sm"><option value="before">Before repair</option><option value="after">After repair</option></select>
                            <input type="file" name="work_image" required accept="image/jpeg,image/png,image/webp" class="min-w-0 flex-1 text-xs">
                            <button class="rounded-xl bg-brand-dark px-4 py-2 text-sm font-bold text-white">Upload photo (max 5 MB)</button>
                        </form>
                        <?php endif; ?>
                        <div class="mt-4 grid grid-cols-2 gap-3"><?php foreach ($orderImages as $image): ?><figure><a href="<?= employee_e(app_url($image['image_path'])) ?>" target="_blank" rel="noopener"><img src="<?= employee_e(app_url($image['image_path'])) ?>" alt="<?= employee_e($image['image_type'] === 'before' ? 'Before' : 'After') ?> repair photo" class="aspect-video w-full rounded-lg border border-brand-border object-cover"></a><figcaption class="mt-1 text-xs text-brand-muted"><?= $image['image_type'] === 'before' ? 'Before' : 'After' ?> · <?= employee_e(date('m/d/Y H:i', strtotime($image['created_at']))) ?></figcaption></figure><?php endforeach; ?><?php if (!$orderImages): ?><p class="col-span-2 text-sm text-brand-muted">No photos yet.</p><?php endif; ?></div>
                    </section>
                </div>
                <section class="mt-5 rounded-2xl border border-brand-border bg-white p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3"><div><h3 class="font-bold text-brand-dark">Part requests</h3><p class="mt-1 text-xs text-brand-muted">Submit a request; this will not change stock levels.</p></div><a href="index.php?view=parts" class="text-sm font-bold text-brand-red">View all</a></div>
                    <?php if ($editable): ?>
                    <form method="post" class="mt-4 grid gap-3 sm:grid-cols-4">
                        <input type="hidden" name="csrf" value="<?= employee_e($csrfToken) ?>"><input type="hidden" name="action" value="request_part"><input type="hidden" name="order_id" value="<?= (int)$selectedOrder['id'] ?>">
                        <select name="part_id" required class="rounded-xl border border-brand-border bg-white px-3 py-2 text-sm sm:col-span-2"><option value="">Select a part</option><?php foreach ($parts ?: $pdo->query('SELECT id, part_code, name, stock_quantity FROM parts WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) as $part): ?><option value="<?= (int)$part['id'] ?>"><?= employee_e($part['part_code'] . ' · ' . $part['name']) ?> (stock: <?= (int)$part['stock_quantity'] ?>)</option><?php endforeach; ?></select>
                        <input type="number" name="quantity" required min="1" max="10000" placeholder="Quantity" class="rounded-xl border border-brand-border px-3 py-2 text-sm">
                        <input name="request_note" maxlength="500" placeholder="Reason / notes" class="rounded-xl border border-brand-border px-3 py-2 text-sm">
                        <div class="sm:col-span-4"><button class="rounded-xl bg-brand-dark px-5 py-2.5 text-sm font-bold text-white">Submit request</button></div>
                    </form>
                    <?php endif; ?>
                    <div class="mt-4 overflow-x-auto"><table class="w-full text-left text-sm"><thead class="text-xs uppercase text-brand-muted"><tr><th class="py-2">Request</th><th class="py-2">Part</th><th class="py-2">Stock</th><th class="py-2">Quantity</th><th class="py-2">Status</th></tr></thead><tbody class="divide-y divide-slate-100"><?php foreach ($orderRequests as $request): ?><tr><td class="py-3 font-semibold"><?= employee_e($request['request_code']) ?></td><td class="py-3"><?= employee_e($request['part_code'] . ' · ' . $request['part_name']) ?></td><td class="py-3"><?= (int)$request['stock_quantity'] ?></td><td class="py-3"><?= (int)$request['quantity'] ?></td><td class="py-3"><?= employee_e($request['status']) ?></td></tr><?php endforeach; ?><?php if (!$orderRequests): ?><tr><td colspan="5" class="py-4 text-brand-muted">No part requests yet.</td></tr><?php endif; ?></tbody></table></div>
                </section>
            <?php elseif ($view === 'parts'): ?>
                <div class="overflow-hidden rounded-2xl border border-brand-border bg-white">
                    <div class="overflow-x-auto"><table class="w-full min-w-[750px] text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase text-brand-muted"><tr><th class="px-4 py-4">Request ID</th><th class="px-4 py-4">Work order</th><th class="px-4 py-4">Part</th><th class="px-4 py-4">Stock</th><th class="px-4 py-4">Quantity</th><th class="px-4 py-4">Status</th><th class="px-4 py-4">Requested</th></tr></thead>
                        <tbody class="divide-y divide-slate-100"><?php foreach ($requests as $request): ?><tr><td class="px-4 py-4 font-bold"><?= employee_e($request['request_code']) ?></td><td class="px-4 py-4"><?= employee_e($request['order_code']) ?></td><td class="px-4 py-4"><?= employee_e($request['part_code'] . ' · ' . $request['part_name']) ?></td><td class="px-4 py-4"><?= (int)$request['stock_quantity'] ?></td><td class="px-4 py-4"><?= (int)$request['quantity'] ?></td><td class="px-4 py-4"><?= employee_e($request['status']) ?></td><td class="px-4 py-4"><?= employee_e(date('m/d/Y H:i', strtotime($request['requested_at']))) ?></td></tr><?php endforeach; ?><?php if (!$requests): ?><tr><td colspan="7" class="px-4 py-10 text-center text-brand-muted">No part requests yet.</td></tr><?php endif; ?></tbody>
                    </table></div>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <?php if ($view === 'dashboard'): ?>
                <?php $maxRevenue = max(1, 0, ...array_map(static fn(array $row): float => (float)$row['net'], $revenueRows)); ?>
                <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                    <?php foreach ([
                        ["Today's invoices", $dashboardStats['count_today'] ?? 0, 'text-brand-dark'],
                        ["Today's collected revenue", employee_money((float)($dashboardStats['revenue_today'] ?? 0)), 'text-emerald-700'],
                        ['Unpaid', $dashboardStats['unpaid'] ?? 0, 'text-rose-700'],
                        ['Partially paid', $dashboardStats['partial'] ?? 0, 'text-amber-700'],
                        ['Paid', $dashboardStats['paid'] ?? 0, 'text-emerald-700'],
                    ] as [$label, $value, $tone]): ?>
                        <article class="rounded-2xl border border-brand-border bg-white p-5"><p class="text-xs font-semibold uppercase text-brand-muted"><?= employee_e($label) ?></p><p class="display mt-3 text-3xl font-bold <?= employee_e($tone) ?>"><?= employee_e((string)$value) ?></p></article>
                    <?php endforeach; ?>
                </section>
                <section class="mt-6 rounded-2xl border border-brand-border bg-white p-5">
                    <div class="mb-5 flex items-center justify-between"><div><h2 class="font-bold text-brand-dark">Net revenue · last 7 days</h2><p class="mt-1 text-xs text-brand-muted">Payments minus refunds. Legacy invoices without transaction logs are identified separately.</p></div><a href="index.php?view=reports" class="text-sm font-bold text-brand-red">Reports</a></div>
                    <div class="flex h-52 items-end gap-3 border-b border-slate-200 px-2">
                        <?php foreach ($revenueRows as $row): $barHeight = max(2, (int)(max(0, (float)$row['net']) / $maxRevenue * 100)); ?>
                            <div class="flex h-full flex-1 flex-col items-center justify-end gap-2"><span class="text-[10px] font-semibold text-slate-600"><?= employee_e(employee_money((float)$row['net'])) ?></span><div class="w-full max-w-16 rounded-t-lg bg-brand-red" style="height: <?= $barHeight ?>%"></div><span class="pb-2 text-[10px] text-brand-muted"><?= employee_e($row['label']) ?></span></div>
                        <?php endforeach; ?>
                    </div>
                </section>
                <section class="mt-6 overflow-hidden rounded-2xl border border-brand-border bg-white">
                    <div class="flex items-center justify-between border-b border-brand-border px-5 py-4"><h2 class="font-bold text-brand-dark">Recent invoices</h2><a href="index.php?view=invoices" class="text-sm font-bold text-brand-red">All invoices</a></div>
                    <div class="overflow-x-auto"><table class="w-full min-w-[750px] text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-brand-muted"><tr><th class="px-4 py-3">Invoice</th><th class="px-4 py-3">Work order / customer</th><th class="px-4 py-3">License plate</th><th class="px-4 py-3">Created</th><th class="px-4 py-3">Total</th><th class="px-4 py-3">Payment status</th></tr></thead><tbody class="divide-y divide-slate-100"><?php foreach ($invoices as $invoice): ?><tr><td class="px-4 py-3"><a class="font-bold text-brand-red" href="index.php?view=invoice&amp;id=<?= (int)$invoice['id'] ?>"><?= employee_e($invoice['invoice_code']) ?></a></td><td class="px-4 py-3"><?= employee_e($invoice['order_code']) ?><br><span class="text-xs text-brand-muted"><?= employee_e($invoice['customer_name'] ?? '—') ?></span></td><td class="px-4 py-3"><?= employee_e($invoice['license_plate']) ?></td><td class="px-4 py-3"><?= employee_e(date('m/d/Y', strtotime($invoice['created_at']))) ?></td><td class="px-4 py-3 font-semibold"><?= employee_e(employee_money((float)$invoice['grand_total'])) ?></td><td class="px-4 py-3"><?= employee_e($statusLabels[$invoice['payment_status']] ?? $invoice['payment_status']) ?></td></tr><?php endforeach; ?><?php if (!$invoices): ?><tr><td colspan="6" class="px-4 py-8 text-center text-brand-muted">No invoices yet.</td></tr><?php endif; ?></tbody></table></div>
                </section>
            <?php elseif ($view === 'new-invoice'): ?>
                <div class="overflow-hidden rounded-2xl border border-brand-border bg-white">
                    <div class="border-b border-brand-border px-5 py-4"><h2 class="font-bold text-brand-dark">Completed work orders · not invoiced</h2><p class="mt-1 text-xs text-brand-muted">Invoices can only be created for completed and inspected work orders.</p></div>
                    <div class="overflow-x-auto"><table class="w-full min-w-[750px] text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-brand-muted"><tr><th class="px-4 py-3">Work order</th><th class="px-4 py-3">Customer</th><th class="px-4 py-3">Vehicle</th><th class="px-4 py-3">Received</th><th class="px-4 py-3">Action</th></tr></thead><tbody class="divide-y divide-slate-100"><?php foreach ($unbilledOrders as $order): ?><tr><td class="px-4 py-4 font-bold"><?= employee_e($order['order_code']) ?></td><td class="px-4 py-4"><?= employee_e($order['customer_name'] ?? '—') ?></td><td class="px-4 py-4"><?= employee_e($order['license_plate'] . ' · ' . $order['brand'] . ' ' . $order['model']) ?></td><td class="px-4 py-4"><?= employee_e(date('m/d/Y', strtotime($order['received_at']))) ?></td><td class="px-4 py-4"><form method="post" class="flex flex-wrap items-center gap-2"><input type="hidden" name="csrf" value="<?= employee_e($csrfToken) ?>"><input type="hidden" name="action" value="create_invoice"><input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>"><input type="number" name="discount" min="0" step="0.01" value="0" placeholder="Discount" class="w-28 rounded-lg border border-brand-border px-2 py-2"><button class="rounded-lg bg-brand-red px-3 py-2 text-xs font-bold text-white">Create invoice</button></form></td></tr><?php endforeach; ?><?php if (!$unbilledOrders): ?><tr><td colspan="5" class="px-4 py-10 text-center text-brand-muted">No eligible work orders to invoice.</td></tr><?php endif; ?></tbody></table></div>
                </div>
            <?php elseif ($view === 'invoices'): ?>
                <form method="get" class="mb-5 flex flex-wrap gap-3 rounded-2xl border border-brand-border bg-white p-4">
                    <input type="hidden" name="view" value="invoices">
                    <input name="q" value="<?= employee_e($search) ?>" placeholder="Invoice, work order, customer, or license plate" class="min-w-[220px] flex-1 rounded-xl border border-brand-border px-4 py-2.5 text-sm">
                    <input type="date" name="from" value="<?= employee_e($fromDate) ?>" class="rounded-xl border border-brand-border px-3 py-2 text-sm">
                    <input type="date" name="to" value="<?= employee_e($toDate) ?>" class="rounded-xl border border-brand-border px-3 py-2 text-sm">
                    <select name="status" class="rounded-xl border border-brand-border bg-white px-3 py-2 text-sm"><option value="">All statuses</option><?php foreach (['unpaid', 'partial', 'paid', 'refunded'] as $key): ?><option value="<?= employee_e($key) ?>" <?= $statusFilter === $key ? 'selected' : '' ?>><?= employee_e($statusLabels[$key]) ?></option><?php endforeach; ?></select>
                    <button class="rounded-xl bg-brand-dark px-5 py-2 text-sm font-bold text-white">Filter</button>
                </form>
                <div class="overflow-hidden rounded-2xl border border-brand-border bg-white"><div class="overflow-x-auto"><table class="w-full min-w-[850px] text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase text-brand-muted"><tr><th class="px-4 py-4">Invoice</th><th class="px-4 py-4">Work order</th><th class="px-4 py-4">Customer / vehicle</th><th class="px-4 py-4">Created</th><th class="px-4 py-4">Total</th><th class="px-4 py-4">Status</th><th class="px-4 py-4"></th></tr></thead>
                    <tbody class="divide-y divide-slate-100"><?php foreach ($invoices as $invoice): ?><tr><td class="px-4 py-4 font-bold text-brand-dark"><?= employee_e($invoice['invoice_code']) ?></td><td class="px-4 py-4"><?= employee_e($invoice['order_code']) ?></td><td class="px-4 py-4"><?= employee_e($invoice['customer_name'] ?? '—') ?><br><span class="text-xs text-brand-muted"><?= employee_e($invoice['license_plate'] . ' · ' . $invoice['brand'] . ' ' . $invoice['model']) ?></span></td><td class="px-4 py-4"><?= employee_e(date('m/d/Y', strtotime($invoice['created_at']))) ?></td><td class="px-4 py-4 font-semibold"><?= employee_e(employee_money((float)$invoice['grand_total'])) ?></td><td class="px-4 py-4"><?= employee_e($statusLabels[$invoice['payment_status']] ?? $invoice['payment_status']) ?></td><td class="px-4 py-4"><a href="index.php?view=invoice&amp;id=<?= (int)$invoice['id'] ?>" class="font-bold text-brand-red">Details</a></td></tr><?php endforeach; ?><?php if (!$invoices): ?><tr><td colspan="7" class="px-4 py-10 text-center text-brand-muted">No invoices found.</td></tr><?php endif; ?></tbody>
                </table></div></div>
            <?php elseif ($view === 'invoice' && $selectedInvoice): ?>
                <section class="rounded-2xl border border-brand-border bg-white p-5">
                    <div class="flex flex-wrap items-start justify-between gap-4"><div><p class="text-xs font-bold uppercase text-brand-muted">Invoice</p><h2 class="display mt-1 text-3xl font-bold text-brand-dark"><?= employee_e($selectedInvoice['invoice_code']) ?></h2><p class="mt-1 text-sm text-brand-muted">Work order <?= employee_e($selectedInvoice['order_code']) ?> · <?= employee_e(date('m/d/Y H:i', strtotime($selectedInvoice['created_at']))) ?></p></div><div class="flex gap-2"><a target="_blank" rel="noopener" href="../admin/invoices/print.php?id=<?= (int)$selectedInvoice['id'] ?>" class="rounded-xl border border-brand-border px-4 py-2 text-sm font-bold">Print invoice</a><span class="rounded-xl bg-slate-100 px-4 py-2 text-sm font-bold"><?= employee_e($statusLabels[$selectedInvoice['payment_status']] ?? $selectedInvoice['payment_status']) ?></span></div></div>
                    <div class="mt-5 grid gap-4 md:grid-cols-3"><div><p class="text-xs text-brand-muted">Customer</p><p class="mt-1 font-bold"><?= employee_e($selectedInvoice['customer_name'] ?? '—') ?></p><p class="text-sm"><?= employee_e($selectedInvoice['customer_phone'] ?? '—') ?></p><p class="text-sm text-brand-muted"><?= employee_e($selectedInvoice['customer_address'] ?? '') ?></p></div><div><p class="text-xs text-brand-muted">Vehicle</p><p class="mt-1 font-bold"><?= employee_e($selectedInvoice['license_plate']) ?></p><p class="text-sm"><?= employee_e(trim($selectedInvoice['brand'] . ' ' . $selectedInvoice['model'])) ?></p></div><div><p class="text-xs text-brand-muted">Paid / balance due</p><p class="mt-1 font-bold text-emerald-700"><?= employee_e(employee_money($selectedBalance['paid'])) ?></p><p class="text-sm text-rose-700"><?= employee_e(employee_money($selectedBalance['balance'])) ?></p></div></div>
                </section>
                <section class="mt-5 overflow-hidden rounded-2xl border border-brand-border bg-white">
                    <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-brand-muted"><tr><th class="px-4 py-3">Service / part</th><th class="px-4 py-3">Type</th><th class="px-4 py-3">Qty</th><th class="px-4 py-3">Unit price</th><th class="px-4 py-3">Subtotal</th></tr></thead><tbody class="divide-y divide-slate-100"><?php foreach ($invoiceItems as $item): ?><tr><td class="px-4 py-3"><?= employee_e($item['description'] ?? $item['item_name'] ?? '') ?></td><td class="px-4 py-3"><?= employee_e(in_array($item['item_type'] ?? '', ['part'], true) ? 'Part' : 'Service') ?></td><td class="px-4 py-3"><?= (int)$item['quantity'] ?></td><td class="px-4 py-3"><?= employee_e(employee_money((float)($item['unit_price'] ?? 0))) ?></td><td class="px-4 py-3"><?= employee_e(employee_money((float)($item['subtotal'] ?? ((float)$item['unit_price'] * (int)$item['quantity'])))) ?></td></tr><?php endforeach; ?></tbody><tfoot class="bg-slate-50 text-sm"><tr><td colspan="4" class="px-4 py-2 text-right">Services</td><td class="px-4 py-2 font-semibold"><?= employee_e(employee_money((float)$selectedInvoice['total_services'])) ?></td></tr><tr><td colspan="4" class="px-4 py-2 text-right">Parts</td><td class="px-4 py-2 font-semibold"><?= employee_e(employee_money((float)$selectedInvoice['total_parts'])) ?></td></tr><tr><td colspan="4" class="px-4 py-2 text-right">Discount</td><td class="px-4 py-2 font-semibold">− <?= employee_e(employee_money((float)$selectedInvoice['discount'])) ?></td></tr><tr><td colspan="4" class="px-4 py-3 text-right font-bold">Total due</td><td class="px-4 py-3 font-bold"><?= employee_e(employee_money((float)$selectedInvoice['grand_total'])) ?></td></tr></tfoot></table></div>
                </section>
                <div class="mt-5 grid gap-5 xl:grid-cols-2">
                    <section class="rounded-2xl border border-brand-border bg-white p-5">
                        <h3 class="font-bold text-brand-dark">Record a transaction</h3>
                        <?php if ($selectedInvoice['payment_status'] !== 'cancelled' && ($selectedBalance['balance'] > 0 || $selectedBalance['paid'] > 0)): ?>
                        <form method="post" class="mt-4 grid gap-3 sm:grid-cols-2">
                            <input type="hidden" name="csrf" value="<?= employee_e($csrfToken) ?>"><input type="hidden" name="action" value="record_transaction"><input type="hidden" name="invoice_id" value="<?= (int)$selectedInvoice['id'] ?>">
                            <label class="text-xs font-semibold">Transaction type<select name="transaction_type" class="mt-1 block w-full rounded-xl border border-brand-border bg-white px-3 py-2 text-sm"><option value="payment">Payment</option><?php if ($selectedBalance['paid'] > 0): ?><option value="refund">Refund</option><?php endif; ?></select></label>
                            <label class="text-xs font-semibold">Amount<input type="number" name="amount" required min="0.01" max="<?= employee_e(number_format(max($selectedBalance['paid'], $selectedBalance['balance']), 2, '.', '')) ?>" step="0.01" class="mt-1 block w-full rounded-xl border border-brand-border px-3 py-2 text-sm"></label>
                            <label class="text-xs font-semibold">Payment method<select name="payment_method" class="mt-1 block w-full rounded-xl border border-brand-border bg-white px-3 py-2 text-sm"><?php foreach ($paymentMethods as $key => $label): ?><option value="<?= employee_e($key) ?>"><?= employee_e($label) ?></option><?php endforeach; ?></select></label>
                            <label class="text-xs font-semibold">Note<input name="transaction_note" maxlength="255" class="mt-1 block w-full rounded-xl border border-brand-border px-3 py-2 text-sm"></label>
                            <div class="sm:col-span-2"><button class="rounded-xl bg-brand-red px-5 py-3 text-sm font-bold text-white">Save transaction</button></div>
                        </form>
                        <?php else: ?><p class="mt-3 text-sm text-brand-muted">This invoice cannot accept new transactions.</p><?php endif; ?>
                    </section>
                    <section class="rounded-2xl border border-brand-border bg-white p-5"><h3 class="font-bold text-brand-dark">Transaction history</h3><div class="mt-3 space-y-3"><?php foreach ($invoiceTransactions as $transaction): ?><div class="flex items-start justify-between gap-3 border-b border-slate-100 pb-3 text-sm"><div><p class="font-bold"><?= employee_e($transaction['transaction_code']) ?> · <?= $transaction['transaction_type'] === 'refund' ? 'Refund' : 'Payment' ?></p><p class="text-xs text-brand-muted"><?= employee_e($paymentMethods[$transaction['payment_method']] ?? $transaction['payment_method']) ?> · <?= employee_e($transaction['staff_name']) ?> · <?= employee_e(date('m/d/Y H:i', strtotime($transaction['created_at']))) ?></p><a class="mt-1 inline-block text-xs font-bold text-brand-red" target="_blank" rel="noopener" href="../admin/invoices/receipt.php?id=<?= (int)$transaction['id'] ?>">Print receipt</a></div><span class="font-bold <?= $transaction['transaction_type'] === 'refund' ? 'text-rose-700' : 'text-emerald-700' ?>"><?= $transaction['transaction_type'] === 'refund' ? '−' : '+' ?><?= employee_e(employee_money((float)$transaction['amount'])) ?></span></div><?php endforeach; ?><?php if (!$invoiceTransactions): ?><p class="text-sm text-brand-muted"><?= $selectedInvoice['payment_status'] === 'paid' ? 'This legacy invoice is marked paid, but has no detailed transaction log.' : 'No transactions yet.' ?></p><?php endif; ?></div></section>
                </div>
            <?php elseif ($view === 'transactions'): ?>
                <div class="overflow-hidden rounded-2xl border border-brand-border bg-white"><div class="overflow-x-auto"><table class="w-full min-w-[800px] text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-brand-muted"><tr><th class="px-4 py-4">Transaction ID</th><th class="px-4 py-4">Invoice</th><th class="px-4 py-4">Type</th><th class="px-4 py-4">Amount</th><th class="px-4 py-4">Payment method</th><th class="px-4 py-4">Date</th><th class="px-4 py-4">Staff</th><th class="px-4 py-4">Status</th></tr></thead><tbody class="divide-y divide-slate-100"><?php foreach ($transactions as $transaction): ?><tr><td class="px-4 py-4 font-bold"><?= employee_e($transaction['transaction_code']) ?></td><td class="px-4 py-4"><a class="text-brand-red" href="index.php?view=invoice&amp;id=<?= (int)$transaction['invoice_id'] ?>"><?= employee_e($transaction['invoice_code']) ?></a></td><td class="px-4 py-4"><?= $transaction['transaction_type'] === 'refund' ? 'Refund' : 'Payment' ?></td><td class="px-4 py-4"><?= employee_e(employee_money((float)$transaction['amount'])) ?></td><td class="px-4 py-4"><?= employee_e($paymentMethods[$transaction['payment_method']] ?? $transaction['payment_method']) ?></td><td class="px-4 py-4"><?= employee_e(date('m/d/Y H:i', strtotime($transaction['created_at']))) ?></td><td class="px-4 py-4"><?= employee_e($transaction['staff_name']) ?></td><td class="px-4 py-4"><?= employee_e($transaction['status']) ?></td></tr><?php endforeach; ?><?php if (!$transactions): ?><tr><td colspan="8" class="px-4 py-10 text-center text-brand-muted">No transactions yet.</td></tr><?php endif; ?></tbody></table></div></div>
            <?php elseif ($view === 'reports'): ?>
                <?php $chartMax = max(1, 0, ...array_map(static fn(array $row): float => (float)$row['payments'], $revenueRows)); ?>
                <form method="get" class="mb-5 flex flex-wrap items-end gap-3 rounded-2xl border border-brand-border bg-white p-4">
                    <input type="hidden" name="view" value="reports">
                    <label class="text-xs font-semibold">From date<input type="date" name="from" value="<?= employee_e($fromDate) ?>" class="mt-1 block rounded-xl border border-brand-border px-3 py-2 text-sm"></label>
                    <label class="text-xs font-semibold">To date<input type="date" name="to" value="<?= employee_e($toDate) ?>" class="mt-1 block rounded-xl border border-brand-border px-3 py-2 text-sm"></label>
                    <label class="text-xs font-semibold">Group by<select name="period" class="mt-1 block rounded-xl border border-brand-border bg-white px-3 py-2 text-sm"><option value="day" <?= $reportPeriod === 'day' ? 'selected' : '' ?>>Day</option><option value="month" <?= $reportPeriod === 'month' ? 'selected' : '' ?>>Month</option></select></label>
                    <button class="rounded-xl bg-brand-dark px-5 py-2.5 text-sm font-bold text-white">View report</button>
                    <a href="index.php?view=reports&amp;from=<?= employee_e($fromDate) ?>&amp;to=<?= employee_e($toDate) ?>&amp;export=csv" class="rounded-xl border border-brand-border px-5 py-2.5 text-sm font-bold">Export transactions (CSV)</a>
                </form>
                <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5"><article class="rounded-2xl border border-brand-border bg-white p-5"><p class="text-xs uppercase text-brand-muted">Invoices</p><p class="display mt-2 text-2xl font-bold text-brand-dark"><?= (int)$reportTotals['invoice_count'] ?></p></article><article class="rounded-2xl border border-brand-border bg-white p-5"><p class="text-xs uppercase text-brand-muted">Unpaid</p><p class="display mt-2 text-2xl font-bold text-rose-700"><?= (int)$reportTotals['unpaid_count'] ?></p></article><article class="rounded-2xl border border-brand-border bg-white p-5"><p class="text-xs uppercase text-brand-muted">Payments collected</p><p class="display mt-2 text-2xl font-bold text-emerald-700"><?= employee_e(employee_money($reportTotals['payments'])) ?></p></article><article class="rounded-2xl border border-brand-border bg-white p-5"><p class="text-xs uppercase text-brand-muted">Refunds</p><p class="display mt-2 text-2xl font-bold text-rose-700"><?= employee_e(employee_money($reportTotals['refunds'])) ?></p></article><article class="rounded-2xl border border-brand-border bg-white p-5"><p class="text-xs uppercase text-brand-muted">Net revenue</p><p class="display mt-2 text-2xl font-bold text-brand-dark"><?= employee_e(employee_money($reportTotals['net'])) ?></p></article></section>
                <section class="mt-5 rounded-2xl border border-brand-border bg-white p-5"><h2 class="font-bold text-brand-dark">Revenue by <?= $reportPeriod === 'month' ? 'month' : 'day' ?></h2><div class="mt-5 space-y-3"><?php foreach ($revenueRows as $row): $payments = (float)$row['payments']; $refunds = (float)$row['refunds']; $width = (int)($payments / $chartMax * 100); ?><div class="grid grid-cols-[90px_1fr_150px] items-center gap-3 text-xs"><span class="font-semibold"><?= employee_e($row['period_key']) ?></span><div class="h-3 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-brand-red" style="width: <?= $width ?>%"></div></div><span class="text-right font-semibold"><?= employee_e(employee_money($payments - $refunds)) ?></span></div><?php endforeach; ?><?php if (!$revenueRows): ?><p class="text-sm text-brand-muted">No transactions in this date range.</p><?php endif; ?></div></section>
                <section class="mt-5 overflow-hidden rounded-2xl border border-brand-border bg-white"><div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-brand-muted"><tr><th class="px-4 py-3">Payment method</th><th class="px-4 py-3">Payments</th><th class="px-4 py-3">Refunds</th><th class="px-4 py-3">Net</th></tr></thead><tbody class="divide-y divide-slate-100"><?php foreach ($methodTotals as $row): ?><tr><td class="px-4 py-3"><?= employee_e($paymentMethods[$row['payment_method']] ?? $row['payment_method']) ?></td><td class="px-4 py-3"><?= employee_e(employee_money((float)$row['payments'])) ?></td><td class="px-4 py-3"><?= employee_e(employee_money((float)$row['refunds'])) ?></td><td class="px-4 py-3 font-semibold"><?= employee_e(employee_money((float)$row['net_amount'])) ?></td></tr><?php endforeach; ?><?php if (!$methodTotals): ?><tr><td colspan="4" class="px-4 py-5 text-brand-muted">No data available.</td></tr><?php endif; ?></tbody></table></div></section>
            <?php endif; ?>
        <?php endif; ?>
    </main>
<script>
function toggleEmployeeSidebar() {
    const sidebar = document.getElementById('employee-sidebar');
    const backdrop = document.getElementById('mobile-sidebar-backdrop');
    const button = document.getElementById('employee-menu-button');
    const opened = sidebar.classList.toggle('-translate-x-full') === false;
    backdrop.classList.toggle('hidden', !opened);
    if (button) button.setAttribute('aria-expanded', String(opened));
}
document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && !document.getElementById('mobile-sidebar-backdrop').classList.contains('hidden')) toggleEmployeeSidebar();
});
</script>
</body>
</html>
