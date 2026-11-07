<?php
declare(strict_types=1);

require_once __DIR__ . '/src/includes/auth.php';

$staff = current_staff();
$role = $staff['role_name'] ?? '';
if (!$staff) {
    header('Location: login.php');
    exit;
}
if (!in_array($role, ['Technician', 'Cashier'], true)) {
    header('Location: ' . ($role === 'Admin' ? 'src/modules/gara-management/index.php' : 'appointments.php'));
    exit;
}

$pdo = db();
$staffId = (int)$staff['id'];
$userName = (string)$staff['full_name'];
$csrfToken = csrf_token();
$noticeMsg = '';
$errorMsg = '';
$allowedStatuses = [
    'received' => 'Chờ tiếp nhận',
    'diagnosing' => 'Đang kiểm tra',
    'repairing' => 'Đang sửa chữa',
    'waiting_parts' => 'Chờ phụ tùng',
    'completed' => 'Hoàn thành sửa chữa',
];
$paymentMethods = [
    'cash' => 'Tiền mặt',
    'transfer' => 'Chuyển khoản',
    'qr' => 'QR',
    'card' => 'Thẻ ngân hàng',
];

function employee_e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function employee_money(float $amount): string
{
    return number_format($amount, 0, ',', '.') . ' ₫';
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
        LEFT JOIN customers c ON c.id = COALESCE(ro.customer_id, v.customer_id)
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
         LEFT JOIN customers c ON c.id = COALESCE(ro.customer_id, v.customer_id)
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
        LEFT JOIN customers c ON c.id = COALESCE(ro.customer_id, v.customer_id)
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
        throw new RuntimeException('Ngày bắt đầu phải trước hoặc bằng ngày kết thúc.');
    }
    return [$fromDate->format('Y-m-d'), $toDate->format('Y-m-d')];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $errorMsg = 'Phiên làm việc không hợp lệ. Vui lòng tải lại trang.';
    } else {
        $action = (string)$_POST['action'];
        $uploadedPath = null;
        try {
            if ($role === 'Technician') {
                $orderId = (int)($_POST['order_id'] ?? 0);
                $order = employee_order($pdo, $orderId, $staffId);
                if (!$order) {
                    throw new RuntimeException('Không tìm thấy công việc được phân công cho tài khoản này.');
                }
                if (in_array($order['status'], ['completed', 'delivered'], true)) {
                    throw new RuntimeException('Phiếu đã hoàn tất, không thể chỉnh sửa thêm.');
                }

                if ($action === 'update_job_status') {
                    $newStatus = (string)($_POST['job_status'] ?? '');
                    if (!isset($allowedStatuses[$newStatus])) {
                        throw new RuntimeException('Trạng thái công việc không hợp lệ.');
                    }
                    $pdo->beginTransaction();
                    $lockedOrder = employee_order($pdo, $orderId, $staffId, true);
                    if (!$lockedOrder || in_array($lockedOrder['status'], ['completed', 'delivered'], true)) {
                        throw new RuntimeException('Phiếu đã hoàn tất hoặc không còn được phân công cho bạn.');
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
                    $noticeMsg = 'Đã cập nhật trạng thái công việc.';
                } elseif ($action === 'save_inspection') {
                    $voltageInput = trim((string)($_POST['hybrid_voltage'] ?? ''));
                    $temperatureInput = trim((string)($_POST['hybrid_temperature'] ?? ''));
                    if (($voltageInput !== '' && (!is_numeric($voltageInput) || (float)$voltageInput < 0 || (float)$voltageInput > 999999.99))
                        || ($temperatureInput !== '' && (!is_numeric($temperatureInput) || (float)$temperatureInput < -273 || (float)$temperatureInput > 999.99))) {
                        throw new RuntimeException('Điện áp hoặc nhiệt độ pin không hợp lệ.');
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
                    $noticeMsg = 'Đã lưu ghi chú và kết quả kiểm tra.';
                } elseif ($action === 'request_part') {
                    $partId = (int)($_POST['part_id'] ?? 0);
                    $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
                    if ($partId <= 0 || !$quantity || $quantity < 1 || $quantity > 10000) {
                        throw new RuntimeException('Vui lòng chọn phụ tùng và số lượng hợp lệ.');
                    }
                    $partCheck = $pdo->prepare('SELECT id FROM parts WHERE id = :id AND is_active = 1');
                    $partCheck->execute(['id' => $partId]);
                    if (!$partCheck->fetchColumn()) {
                        throw new RuntimeException('Phụ tùng không còn khả dụng.');
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
                    $noticeMsg = 'Đã gửi yêu cầu phụ tùng ' . $requestCode . '.';
                } elseif ($action === 'upload_work_image') {
                    $imageType = (string)($_POST['image_type'] ?? '');
                    if (!in_array($imageType, ['before', 'after'], true)) {
                        throw new RuntimeException('Vui lòng chọn ảnh trước hoặc sau sửa chữa.');
                    }
                    $file = $_FILES['work_image'] ?? null;
                    if (!$file || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
                        throw new RuntimeException('Tải ảnh lên thất bại. Vui lòng chọn lại tệp.');
                    }
                    if ((int)$file['size'] <= 0 || (int)$file['size'] > 5 * 1024 * 1024) {
                        throw new RuntimeException('Dung lượng ảnh tối đa là 5 MB.');
                    }
                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime = $finfo->file($file['tmp_name']);
                    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                    if (!isset($extensions[$mime]) || @getimagesize($file['tmp_name']) === false) {
                        throw new RuntimeException('Chỉ chấp nhận ảnh JPEG, PNG hoặc WebP hợp lệ.');
                    }
                    $uploadDirectory = __DIR__ . '/uploads/work-orders/' . $orderId;
                    if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0750, true) && !is_dir($uploadDirectory)) {
                        throw new RuntimeException('Không thể tạo thư mục lưu ảnh. Vui lòng kiểm tra quyền ghi.');
                    }
                    $filename = bin2hex(random_bytes(16)) . '.' . $extensions[$mime];
                    $absolutePath = $uploadDirectory . '/' . $filename;
                    if (!move_uploaded_file($file['tmp_name'], $absolutePath)) {
                        throw new RuntimeException('Không thể lưu ảnh lên máy chủ.');
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
                    $noticeMsg = 'Đã tải ảnh lên phiếu sửa chữa.';
                } else {
                    throw new RuntimeException('Thao tác kỹ thuật không được hỗ trợ.');
                }
            } elseif ($role === 'Cashier') {
                if ($action === 'create_invoice') {
                    $orderId = (int)($_POST['order_id'] ?? 0);
                    $discount = filter_var($_POST['discount'] ?? '0', FILTER_VALIDATE_FLOAT);
                    if ($orderId <= 0 || $discount === false || $discount < 0) {
                        throw new RuntimeException('Thông tin lập hóa đơn không hợp lệ.');
                    }
                    $pdo->beginTransaction();
                    $orderStmt = $pdo->prepare(
                        "SELECT ro.id, ro.status FROM repair_orders ro
                         WHERE ro.id = :id AND ro.status = 'completed' FOR UPDATE"
                    );
                    $orderStmt->execute(['id' => $orderId]);
                    if (!$orderStmt->fetch(PDO::FETCH_ASSOC)) {
                        throw new RuntimeException('Chỉ lập hóa đơn cho phiếu đã hoàn thành và nghiệm thu.');
                    }
                    $existing = $pdo->prepare("SELECT id FROM invoices WHERE order_id = :id AND payment_status <> 'cancelled' LIMIT 1");
                    $existing->execute(['id' => $orderId]);
                    if ($existing->fetchColumn()) {
                        throw new RuntimeException('Phiếu sửa chữa này đã có hóa đơn.');
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
                                'description' => (string)($line['description'] ?: 'Dịch vụ sửa chữa'),
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
                        throw new RuntimeException('Phiếu chưa có hạng mục dịch vụ hoặc phụ tùng để lập hóa đơn.');
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
                        throw new RuntimeException('Giảm giá không hợp lệ hoặc tổng hóa đơn phải lớn hơn 0.');
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
                    $noticeMsg = 'Đã lập hóa đơn ' . $invoiceCode . '.';
                } elseif ($action === 'record_transaction') {
                    $invoiceId = (int)($_POST['invoice_id'] ?? 0);
                    $amount = filter_var($_POST['amount'] ?? null, FILTER_VALIDATE_FLOAT);
                    $method = (string)($_POST['payment_method'] ?? '');
                    $type = (string)($_POST['transaction_type'] ?? 'payment');
                    if ($invoiceId <= 0 || $amount === false || $amount <= 0 || !isset($paymentMethods[$method])
                        || !in_array($type, ['payment', 'refund'], true)) {
                        throw new RuntimeException('Thông tin giao dịch không hợp lệ.');
                    }
                    $pdo->beginTransaction();
                    $invoiceStmt = $pdo->prepare('SELECT * FROM invoices WHERE id = :id FOR UPDATE');
                    $invoiceStmt->execute(['id' => $invoiceId]);
                    $invoice = $invoiceStmt->fetch(PDO::FETCH_ASSOC);
                    if (!$invoice || $invoice['payment_status'] === 'cancelled') {
                        throw new RuntimeException('Không tìm thấy hóa đơn hợp lệ.');
                    }
                    $balance = employee_invoice_balance($pdo, $invoice);
                    $paid = $balance['paid'];
                    if ($type === 'payment' && $amount > $balance['balance']) {
                        throw new RuntimeException('Số tiền thu không được vượt quá số tiền còn thiếu.');
                    }
                    if ($type === 'refund' && $amount > $paid) {
                        throw new RuntimeException('Số tiền hoàn không được vượt quá số tiền đã thu.');
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
                    $noticeMsg = ($type === 'refund' ? 'Đã ghi nhận hoàn tiền ' : 'Đã ghi nhận thanh toán ')
                        . $transactionCode . '.';
                } else {
                    throw new RuntimeException('Thao tác thu ngân không được hỗ trợ.');
                }
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($uploadedPath !== null) {
                $absolutePath = __DIR__ . '/' . $uploadedPath;
                if (is_file($absolutePath)) {
                    unlink($absolutePath);
                }
            }
            if ($e instanceof RuntimeException) {
                $errorMsg = $e->getMessage();
            } else {
                error_log('Employee portal action failed: ' . $e->getMessage());
                $errorMsg = 'Không thể thực hiện thao tác do lỗi hệ thống. Vui lòng thử lại.';
            }
        }
    }
}

$view = (string)($_GET['view'] ?? 'dashboard');
$technicianViews = ['dashboard', 'jobs', 'job', 'parts', 'history'];
$cashierViews = ['dashboard', 'invoices', 'invoice', 'new-invoice', 'transactions', 'reports'];
if (!in_array($view, $role === 'Technician' ? $technicianViews : $cashierViews, true)) {
    $view = 'dashboard';
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
            $errorMsg = 'Không tìm thấy phiếu được phân công cho bạn.';
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
             LEFT JOIN customers c ON c.id = COALESCE(ro.customer_id, v.customer_id)
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
            $errorMsg = 'Không tìm thấy hóa đơn.';
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
                 LEFT JOIN customers c ON c.id = COALESCE(ro.customer_id, v.customer_id)
                 WHERE ct.created_at >= :from_date AND ct.created_at < DATE_ADD(:to_date, INTERVAL 1 DAY)
                 ORDER BY ct.created_at DESC'
            );
            $stmt->execute(['from_date' => $fromDate, 'to_date' => $toDate]);
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="revenue-' . $fromDate . '-to-' . $toDate . '.csv"');
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, ['Transaction code', 'Invoice', 'Type', 'Amount', 'Method', 'Status', 'Date', 'Cashier', 'Customer']);
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
                LEFT JOIN customers c ON c.id = COALESCE(ro.customer_id, v.customer_id)
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
             LEFT JOIN customers c ON c.id = COALESCE(ro.customer_id, v.customer_id)
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

$pageTitle = $role === 'Technician' ? 'Trung tâm kỹ thuật' : 'Quầy thu ngân';
$viewTitles = [
    'dashboard' => 'Tổng quan',
    'jobs' => 'Danh sách công việc',
    'job' => 'Chi tiết phiếu sửa chữa',
    'parts' => 'Yêu cầu phụ tùng',
    'history' => 'Lịch sử công việc',
    'invoices' => 'Danh sách hóa đơn',
    'invoice' => 'Chi tiết hóa đơn',
    'new-invoice' => 'Lập hóa đơn',
    'transactions' => 'Lịch sử giao dịch',
    'reports' => 'Báo cáo doanh thu',
];
$navItems = $role === 'Technician'
    ? ['dashboard' => 'Dashboard', 'jobs' => 'Công việc', 'parts' => 'Yêu cầu phụ tùng', 'history' => 'Lịch sử']
    : ['dashboard' => 'Dashboard', 'invoices' => 'Hóa đơn', 'new-invoice' => 'Lập hóa đơn', 'transactions' => 'Giao dịch', 'reports' => 'Báo cáo doanh thu'];
$engineLabels = ['gasoline' => 'Xăng', 'diesel' => 'Diesel', 'hybrid' => 'Hybrid', 'ev' => 'EV'];
$statusLabels = $allowedStatuses + ['inspecting' => 'Đang nghiệm thu', 'delivered' => 'Đã bàn giao'];
$statusLabels += ['unpaid' => 'Chưa thanh toán', 'partial' => 'Thanh toán một phần', 'paid' => 'Đã thanh toán', 'refunded' => 'Đã hoàn tiền'];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= employee_e($pageTitle) ?> | REDLINE</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = { theme: { extend: {
            colors: { brand: { bg: '#f5f6f8', surface: '#fff', border: '#e2e8f0', red: '#d90429', dark: '#111827', muted: '#64748b' } },
            fontFamily: { display: ['Oswald', 'sans-serif'], sans: ['Inter', 'sans-serif'] }
        } } };
    </script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Oswald:wght@500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: Inter, sans-serif; }
        .display { font-family: Oswald, sans-serif; }
        input, select, textarea { outline: none; }
        @media print { .no-print { display: none !important; } }
    </style>
</head>
<body class="min-h-screen bg-brand-bg text-slate-700">
<div class="min-h-screen md:flex">
    <aside class="no-print w-full bg-white border-b md:border-b-0 md:border-r border-brand-border md:w-64 md:min-h-screen md:fixed md:inset-y-0 md:left-0 flex flex-col">
        <div class="px-6 py-5 flex items-center justify-between border-b border-brand-border">
            <a href="employees.php" class="display text-2xl font-bold tracking-wider text-brand-dark"><span class="text-brand-red">///</span> REDLINE</a>
            <span class="rounded-full bg-red-50 px-3 py-1 text-[10px] font-bold uppercase text-brand-red"><?= $role === 'Technician' ? 'Technician' : 'Cashier' ?></span>
        </div>
        <div class="px-5 py-5 border-b border-brand-border">
            <p class="text-xs uppercase tracking-widest text-brand-muted">Xin chào</p>
            <p class="mt-1 font-bold text-brand-dark"><?= employee_e($userName) ?></p>
            <p class="mt-1 text-xs text-brand-muted"><?= $role === 'Technician' ? 'Khu vực kỹ thuật' : 'Khu vực thu ngân' ?></p>
        </div>
        <nav class="p-3 space-y-1">
            <?php foreach ($navItems as $navView => $navTitle): ?>
                <a href="employees.php?view=<?= employee_e($navView) ?>"
                   class="block rounded-xl px-4 py-3 text-sm font-semibold <?= $view === $navView ? 'bg-red-50 text-brand-red' : 'text-slate-600 hover:bg-slate-50' ?>">
                    <?= employee_e($navTitle) ?>
                </a>
            <?php endforeach; ?>
        </nav>
        <div class="mt-auto p-4 border-t border-brand-border">
            <a href="logout.php" class="block rounded-xl border border-brand-border px-4 py-3 text-sm font-semibold text-slate-600 hover:border-brand-red hover:text-brand-red">Đăng xuất</a>
        </div>
    </aside>
    <main class="min-w-0 flex-1 p-4 sm:p-6 lg:p-8 md:ml-64">
        <header class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <p class="text-xs font-bold uppercase tracking-[.2em] text-brand-red"><?= employee_e($pageTitle) ?></p>
                <h1 class="display mt-1 text-3xl font-bold uppercase text-brand-dark"><?= employee_e($viewTitles[$view]) ?></h1>
            </div>
            <div class="text-sm text-brand-muted"><?= date('d/m/Y H:i') ?></div>
        </header>

        <?php if ($noticeMsg !== ''): ?>
            <div class="mb-5 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm font-medium text-emerald-800"><?= employee_e($noticeMsg) ?></div>
        <?php endif; ?>
        <?php if ($errorMsg !== ''): ?>
            <div class="mb-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm font-medium text-rose-800"><?= employee_e($errorMsg) ?></div>
        <?php endif; ?>

        <?php if ($role === 'Technician'): ?>
            <?php if ($view === 'dashboard'): ?>
                <?php
                    $statusCards = [
                        ['received', 'Chờ tiếp nhận', 'text-sky-700', 'bg-sky-50'],
                        ['diagnosing', 'Đang kiểm tra', 'text-amber-700', 'bg-amber-50'],
                        ['repairing', 'Đang sửa chữa', 'text-violet-700', 'bg-violet-50'],
                        ['waiting_parts', 'Chờ phụ tùng', 'text-orange-700', 'bg-orange-50'],
                        ['completed', 'Đã hoàn thành', 'text-emerald-700', 'bg-emerald-50'],
                    ];
                    $totalAssigned = array_sum($dashboardStats);
                ?>
                <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                    <article class="rounded-2xl bg-brand-dark p-5 text-white sm:col-span-2 xl:col-span-1">
                        <p class="text-xs font-semibold uppercase tracking-wide text-slate-300">Tổng việc được giao</p>
                        <p class="display mt-3 text-4xl font-bold"><?= $totalAssigned ?></p>
                    </article>
                    <?php foreach ($statusCards as [$key, $label, $tone, $surface]): ?>
                        <article class="rounded-2xl border border-brand-border bg-white p-5">
                            <p class="text-xs font-semibold uppercase text-brand-muted"><?= employee_e($label) ?></p>
                            <p class="display mt-3 text-4xl font-bold <?= employee_e($tone) ?>"><?= (int)($dashboardStats[$key] ?? 0) ?></p>
                        </article>
                    <?php endforeach; ?>
                </section>
                <section class="mt-6 grid gap-6 xl:grid-cols-2">
                    <div class="rounded-2xl border border-brand-border bg-white">
                        <div class="flex items-center justify-between border-b border-brand-border px-5 py-4">
                            <h2 class="font-bold text-brand-dark">Công việc gần đây</h2>
                            <a class="text-sm font-semibold text-brand-red" href="employees.php?view=jobs">Xem tất cả</a>
                        </div>
                        <div class="divide-y divide-slate-100">
                            <?php foreach ($jobs as $job): ?>
                                <a href="employees.php?view=job&amp;id=<?= (int)$job['id'] ?>" class="flex items-center justify-between gap-4 px-5 py-4 hover:bg-slate-50">
                                    <div><p class="font-bold text-brand-dark"><?= employee_e($job['order_code']) ?> · <?= employee_e($job['license_plate']) ?></p><p class="mt-1 text-xs text-brand-muted"><?= employee_e($job['customer_name'] ?? 'Chưa có thông tin khách') ?> · <?= employee_e($job['brand'] . ' ' . $job['model']) ?></p></div>
                                    <span class="shrink-0 rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold"><?= employee_e($statusLabels[$job['status']] ?? $job['status']) ?></span>
                                </a>
                            <?php endforeach; ?>
                            <?php if (!$jobs): ?><p class="px-5 py-8 text-sm text-brand-muted">Chưa có công việc được phân công.</p><?php endif; ?>
                        </div>
                    </div>
                    <div class="rounded-2xl border border-brand-border bg-white">
                        <div class="border-b border-brand-border px-5 py-4"><h2 class="font-bold text-brand-dark">Cần ưu tiên</h2><p class="mt-1 text-xs text-brand-muted">Phiếu chờ tiếp nhận hoặc đang chờ phụ tùng</p></div>
                        <div class="divide-y divide-slate-100">
                            <?php foreach (array_slice($priorityJobs, 0, 8) as $job): ?>
                                <a href="employees.php?view=job&amp;id=<?= (int)$job['id'] ?>" class="flex items-center justify-between gap-4 px-5 py-4 hover:bg-slate-50">
                                    <div><p class="font-bold text-brand-dark"><?= employee_e($job['order_code']) ?> · <?= employee_e($job['license_plate']) ?></p><p class="mt-1 text-xs text-brand-muted"><?= employee_e($job['customer_name'] ?? 'Chưa có thông tin khách') ?></p></div>
                                    <span class="rounded-full bg-orange-50 px-3 py-1 text-xs font-semibold text-orange-700"><?= employee_e($statusLabels[$job['status']] ?? $job['status']) ?></span>
                                </a>
                            <?php endforeach; ?>
                            <?php if (!$priorityJobs): ?><p class="px-5 py-8 text-sm text-brand-muted">Không có công việc ưu tiên.</p><?php endif; ?>
                        </div>
                    </div>
                </section>
            <?php elseif ($view === 'jobs' || $view === 'history'): ?>
                <form method="get" class="mb-5 flex flex-wrap gap-3 rounded-2xl border border-brand-border bg-white p-4">
                    <input type="hidden" name="view" value="<?= employee_e($view) ?>">
                    <input name="q" value="<?= employee_e($search) ?>" placeholder="Mã phiếu, biển số, khách hàng..." class="min-w-[220px] flex-1 rounded-xl border border-brand-border px-4 py-2.5 text-sm">
                    <?php if ($view === 'jobs'): ?>
                        <select name="status" class="rounded-xl border border-brand-border bg-white px-4 py-2.5 text-sm">
                            <option value="">Tất cả trạng thái</option>
                            <?php foreach ($statusLabels as $key => $label): if (!in_array($key, ['inspecting', 'delivered', 'unpaid', 'partial', 'paid', 'refunded'], true)): ?>
                                <option value="<?= employee_e($key) ?>" <?= $statusFilter === $key ? 'selected' : '' ?>><?= employee_e($label) ?></option>
                            <?php endif; endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <button class="rounded-xl bg-brand-dark px-5 py-2.5 text-sm font-bold text-white">Lọc công việc</button>
                </form>
                <div class="overflow-hidden rounded-2xl border border-brand-border bg-white">
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[1000px] text-left text-sm">
                            <thead class="bg-slate-50 text-xs uppercase text-brand-muted"><tr>
                                <th class="px-4 py-4">Mã phiếu</th><th class="px-4 py-4">Khách hàng</th><th class="px-4 py-4">Xe</th><th class="px-4 py-4">Loại xe</th><th class="px-4 py-4">Hạng mục</th><?php if ($view === 'history'): ?><th class="px-4 py-4">Kết quả</th><?php endif; ?><th class="px-4 py-4"><?= $view === 'history' ? 'Ngày hoàn thành' : 'Ngày tiếp nhận' ?></th><th class="px-4 py-4">Trạng thái</th><th class="px-4 py-4"></th>
                            </tr></thead>
                            <tbody class="divide-y divide-slate-100">
                            <?php foreach ($jobs as $job): ?>
                                <tr>
                                    <td class="px-4 py-4 font-bold text-brand-dark"><?= employee_e($job['order_code']) ?></td>
                                    <td class="px-4 py-4"><?= employee_e($job['customer_name'] ?? '—') ?></td>
                                    <td class="px-4 py-4"><span class="font-bold"><?= employee_e($job['license_plate']) ?></span><br><span class="text-xs text-brand-muted"><?= employee_e(trim($job['brand'] . ' ' . $job['model'])) ?></span></td>
                                    <td class="px-4 py-4"><?= employee_e($engineLabels[$job['engine_type']] ?? 'Chưa xác định') ?></td>
                                    <td class="max-w-[200px] truncate px-4 py-4"><?= employee_e($job['customer_request'] ?: ($job['service_type'] ?: '—')) ?></td>
                                    <?php if ($view === 'history'): ?><td class="max-w-[220px] truncate px-4 py-4"><?= employee_e($job['technical_note'] ?: 'Chưa có ghi chú kết quả') ?></td><?php endif; ?>
                                    <td class="px-4 py-4"><?= employee_e(date('d/m/Y', strtotime($view === 'history' ? $job['completed_at'] : $job['received_at']))) ?></td>
                                    <td class="px-4 py-4"><?= employee_e($statusLabels[$job['status']] ?? $job['status']) ?></td>
                                    <td class="px-4 py-4"><a class="font-bold text-brand-red" href="employees.php?view=job&amp;id=<?= (int)$job['id'] ?>">Chi tiết</a></td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (!$jobs): ?><tr><td colspan="<?= $view === 'history' ? 9 : 8 ?>" class="px-4 py-10 text-center text-brand-muted">Không tìm thấy công việc.</td></tr><?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php elseif ($view === 'job' && $selectedOrder): ?>
                <?php
                    $isHybrid = $selectedOrder['engine_type'] === 'hybrid';
                    $editable = !in_array($selectedOrder['status'], ['completed', 'delivered'], true);
                    $jobStatusLabel = $statusLabels[$selectedOrder['status']] ?? $selectedOrder['status'];
                ?>
                <div class="mb-5 flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-brand-border bg-white p-5">
                    <div><p class="text-xs font-bold uppercase tracking-wider text-brand-muted">Phiếu sửa chữa</p><h2 class="display mt-1 text-2xl font-bold text-brand-dark"><?= employee_e($selectedOrder['order_code']) ?></h2><p class="mt-1 text-sm text-brand-muted"><?= employee_e($selectedOrder['customer_name'] ?? '—') ?> · <?= employee_e($selectedOrder['license_plate']) ?> · <?= employee_e($jobStatusLabel) ?></p></div>
                    <a href="employees.php?view=jobs" class="rounded-xl border border-brand-border px-4 py-2 text-sm font-semibold">Danh sách công việc</a>
                </div>
                <div class="grid gap-5 xl:grid-cols-2">
                    <section class="rounded-2xl border border-brand-border bg-white p-5">
                        <h3 class="font-bold text-brand-dark">Thông tin xe</h3>
                        <dl class="mt-4 grid grid-cols-2 gap-4 text-sm">
                            <div><dt class="text-xs text-brand-muted">Biển số</dt><dd class="mt-1 font-bold"><?= employee_e($selectedOrder['license_plate']) ?></dd></div>
                            <div><dt class="text-xs text-brand-muted">Hãng xe</dt><dd class="mt-1 font-bold"><?= employee_e($selectedOrder['brand']) ?></dd></div>
                            <div><dt class="text-xs text-brand-muted">Dòng xe</dt><dd class="mt-1 font-bold"><?= employee_e($selectedOrder['model']) ?></dd></div>
                            <div><dt class="text-xs text-brand-muted">Năm sản xuất</dt><dd class="mt-1 font-bold"><?= employee_e((string)($selectedOrder['vehicle_year'] ?: '—')) ?></dd></div>
                            <div><dt class="text-xs text-brand-muted">Loại động cơ</dt><dd class="mt-1 font-bold"><?= employee_e($engineLabels[$selectedOrder['engine_type']] ?? 'Chưa xác định') ?></dd></div>
                            <div><dt class="text-xs text-brand-muted">Số km hiện tại</dt><dd class="mt-1 font-bold"><?= number_format((int)($selectedOrder['vehicle_odometer'] ?: $selectedOrder['odometer'] ?: $selectedOrder['km_in'])) ?> km</dd></div>
                        </dl>
                    </section>
                    <section class="rounded-2xl border border-brand-border bg-white p-5">
                        <h3 class="font-bold text-brand-dark">Thông tin sửa chữa</h3>
                        <div class="mt-4 space-y-3 text-sm">
                            <div><p class="text-xs text-brand-muted">Tình trạng / khách phản ánh</p><p class="mt-1 whitespace-pre-wrap"><?= employee_e($selectedOrder['symptoms'] ?: 'Chưa ghi nhận') ?></p></div>
                            <div><p class="text-xs text-brand-muted">Yêu cầu khách hàng</p><p class="mt-1 whitespace-pre-wrap"><?= employee_e($selectedOrder['customer_request'] ?: 'Chưa ghi nhận') ?></p></div>
                            <div><p class="text-xs text-brand-muted">Hạng mục trên phiếu</p>
                                <?php if ($orderItems): ?><ul class="mt-1 list-inside list-disc"><?php foreach ($orderItems as $item): ?><li><?= employee_e($item['item_name']) ?> (<?= employee_e($item['item_type'] === 'part' ? 'Phụ tùng' : 'Dịch vụ') ?>)</li><?php endforeach; ?></ul><?php else: ?><p class="mt-1"><?= employee_e($selectedOrder['service_type'] ?: 'Chưa có hạng mục chi tiết') ?></p><?php endif; ?>
                            </div>
                        </div>
                    </section>
                </div>
                <section class="mt-5 rounded-2xl border border-brand-border bg-white p-5">
                    <div class="mb-4"><h3 class="font-bold text-brand-dark">Kết quả kiểm tra và ghi chú kỹ thuật</h3><p class="mt-1 text-xs text-brand-muted">Nhập kết quả thực tế từ kiểm tra hoặc thiết bị chẩn đoán; để trống nếu chưa đo.</p></div>
                    <?php if ($editable): ?>
                    <form method="post" class="grid gap-4 md:grid-cols-2">
                        <input type="hidden" name="csrf" value="<?= employee_e($csrfToken) ?>"><input type="hidden" name="action" value="save_inspection"><input type="hidden" name="order_id" value="<?= (int)$selectedOrder['id'] ?>">
                        <?php foreach ([
                            'technical_note' => 'Ghi chú kỹ thuật',
                            'customer_complaints' => 'Lỗi khách hàng phản ánh',
                            'inspection_needed' => 'Hạng mục cần kiểm tra',
                            'repairs_performed' => 'Hạng mục đã sửa chữa',
                            'engine_result' => 'Hệ thống động cơ',
                            'brakes_result' => 'Hệ thống phanh',
                            'electrical_result' => 'Hệ thống điện',
                            'ac_result' => 'Hệ thống điều hòa',
                        ] as $field => $label): ?>
                            <label class="text-xs font-semibold text-slate-600"><?= employee_e($label) ?><textarea name="<?= employee_e($field) ?>" rows="3" class="mt-1 block w-full rounded-xl border border-brand-border px-3 py-2 text-sm"><?= employee_e($inspection[$field] ?? '') ?></textarea></label>
                        <?php endforeach; ?>
                        <?php if ($isHybrid): ?>
                            <div class="md:col-span-2 rounded-xl border border-amber-200 bg-amber-50 p-4">
                                <h4 class="font-bold text-amber-900">Kiểm tra hệ thống Hybrid</h4>
                                <p class="mt-1 text-xs text-amber-800">Các giá trị bên dưới chỉ được lưu thủ công do nhân viên nhập; không có dữ liệu chẩn đoán tự động.</p>
                                <div class="mt-3 grid gap-3 md:grid-cols-2">
                                    <label class="text-xs font-semibold">Tình trạng pin Hybrid<input name="hybrid_battery_status" value="<?= employee_e($inspection['hybrid_battery_status'] ?? '') ?>" class="mt-1 block w-full rounded-lg border border-amber-200 bg-white px-3 py-2 text-sm"></label>
                                    <label class="text-xs font-semibold">Điện áp pin (V)<input type="number" step="0.01" min="0" name="hybrid_voltage" value="<?= employee_e(isset($inspection['hybrid_voltage']) ? (string)$inspection['hybrid_voltage'] : '') ?>" class="mt-1 block w-full rounded-lg border border-amber-200 bg-white px-3 py-2 text-sm"></label>
                                    <label class="text-xs font-semibold">Nhiệt độ pin (°C)<input type="number" step="0.1" name="hybrid_temperature" value="<?= employee_e(isset($inspection['hybrid_temperature']) ? (string)$inspection['hybrid_temperature'] : '') ?>" class="mt-1 block w-full rounded-lg border border-amber-200 bg-white px-3 py-2 text-sm"></label>
                                    <?php foreach (['hybrid_cooling_result' => 'Hệ thống làm mát pin', 'inverter_result' => 'Bộ biến tần Inverter', 'electric_motor_result' => 'Động cơ điện', 'dtc_codes' => 'Mã lỗi DTC'] as $field => $label): ?>
                                        <label class="text-xs font-semibold"><?= employee_e($label) ?><textarea name="<?= employee_e($field) ?>" rows="2" class="mt-1 block w-full rounded-lg border border-amber-200 bg-white px-3 py-2 text-sm"><?= employee_e($inspection[$field] ?? '') ?></textarea></label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                        <div class="md:col-span-2"><button class="rounded-xl bg-brand-dark px-5 py-3 text-sm font-bold text-white">Lưu kết quả kiểm tra</button></div>
                    </form>
                    <?php else: ?>
                        <p class="text-sm text-brand-muted">Phiếu đã hoàn tất. Kết quả được khóa để đảm bảo lịch sử công việc.</p>
                        <div class="mt-4 grid gap-3 md:grid-cols-2"><?php foreach ($inspection as $field => $value): if (!in_array($field, ['id', 'order_id', 'updated_by', 'updated_at'], true) && $value !== null && $value !== ''): ?><div><p class="text-xs text-brand-muted"><?= employee_e(str_replace('_', ' ', $field)) ?></p><p class="mt-1 whitespace-pre-wrap text-sm"><?= employee_e((string)$value) ?></p></div><?php endif; endforeach; ?></div>
                    <?php endif; ?>
                </section>
                <div class="mt-5 grid gap-5 xl:grid-cols-2">
                    <section class="rounded-2xl border border-brand-border bg-white p-5">
                        <h3 class="font-bold text-brand-dark">Cập nhật tiến độ</h3>
                        <?php if ($editable): ?>
                        <form method="post" class="mt-4 space-y-3">
                            <input type="hidden" name="csrf" value="<?= employee_e($csrfToken) ?>"><input type="hidden" name="action" value="update_job_status"><input type="hidden" name="order_id" value="<?= (int)$selectedOrder['id'] ?>">
                            <select name="job_status" required class="w-full rounded-xl border border-brand-border bg-white px-3 py-3 text-sm"><?php foreach ($allowedStatuses as $key => $label): ?><option value="<?= employee_e($key) ?>" <?= $selectedOrder['status'] === $key ? 'selected' : '' ?>><?= employee_e($label) ?></option><?php endforeach; ?></select>
                            <textarea name="progress_note" rows="2" placeholder="Ghi chú cho lần cập nhật này" class="w-full rounded-xl border border-brand-border px-3 py-2 text-sm"></textarea>
                            <button class="rounded-xl bg-brand-red px-5 py-3 text-sm font-bold text-white">Cập nhật công việc</button>
                        </form>
                        <?php else: ?><p class="mt-3 text-sm text-brand-muted">Phiếu đã hoàn tất hoặc đã bàn giao.</p><?php endif; ?>
                    </section>
                    <section class="rounded-2xl border border-brand-border bg-white p-5">
                        <h3 class="font-bold text-brand-dark">Ảnh trước / sau sửa chữa</h3>
                        <?php if ($editable): ?>
                        <form method="post" enctype="multipart/form-data" class="mt-4 flex flex-wrap gap-3">
                            <input type="hidden" name="csrf" value="<?= employee_e($csrfToken) ?>"><input type="hidden" name="action" value="upload_work_image"><input type="hidden" name="order_id" value="<?= (int)$selectedOrder['id'] ?>">
                            <select name="image_type" class="rounded-xl border border-brand-border bg-white px-3 py-2 text-sm"><option value="before">Trước sửa chữa</option><option value="after">Sau sửa chữa</option></select>
                            <input type="file" name="work_image" required accept="image/jpeg,image/png,image/webp" class="min-w-0 flex-1 text-xs">
                            <button class="rounded-xl bg-brand-dark px-4 py-2 text-sm font-bold text-white">Tải ảnh (tối đa 5 MB)</button>
                        </form>
                        <?php endif; ?>
                        <div class="mt-4 grid grid-cols-2 gap-3"><?php foreach ($orderImages as $image): ?><figure><a href="<?= employee_e($image['image_path']) ?>" target="_blank" rel="noopener"><img src="<?= employee_e($image['image_path']) ?>" alt="Ảnh <?= employee_e($image['image_type'] === 'before' ? 'trước' : 'sau') ?> sửa chữa" class="aspect-video w-full rounded-lg border border-brand-border object-cover"></a><figcaption class="mt-1 text-xs text-brand-muted"><?= $image['image_type'] === 'before' ? 'Trước' : 'Sau' ?> · <?= employee_e(date('d/m/Y H:i', strtotime($image['created_at']))) ?></figcaption></figure><?php endforeach; ?><?php if (!$orderImages): ?><p class="col-span-2 text-sm text-brand-muted">Chưa có hình ảnh.</p><?php endif; ?></div>
                    </section>
                </div>
                <section class="mt-5 rounded-2xl border border-brand-border bg-white p-5">
                    <div class="flex flex-wrap items-start justify-between gap-3"><div><h3 class="font-bold text-brand-dark">Yêu cầu phụ tùng</h3><p class="mt-1 text-xs text-brand-muted">Tạo yêu cầu; thao tác này không thay đổi tồn kho.</p></div><a href="employees.php?view=parts" class="text-sm font-bold text-brand-red">Theo dõi tất cả</a></div>
                    <?php if ($editable): ?>
                    <form method="post" class="mt-4 grid gap-3 sm:grid-cols-4">
                        <input type="hidden" name="csrf" value="<?= employee_e($csrfToken) ?>"><input type="hidden" name="action" value="request_part"><input type="hidden" name="order_id" value="<?= (int)$selectedOrder['id'] ?>">
                        <select name="part_id" required class="rounded-xl border border-brand-border bg-white px-3 py-2 text-sm sm:col-span-2"><option value="">Chọn phụ tùng</option><?php foreach ($parts ?: $pdo->query('SELECT id, part_code, name, stock_quantity FROM parts WHERE is_active = 1 ORDER BY name')->fetchAll(PDO::FETCH_ASSOC) as $part): ?><option value="<?= (int)$part['id'] ?>"><?= employee_e($part['part_code'] . ' · ' . $part['name']) ?> (tồn <?= (int)$part['stock_quantity'] ?>)</option><?php endforeach; ?></select>
                        <input type="number" name="quantity" required min="1" max="10000" placeholder="Số lượng" class="rounded-xl border border-brand-border px-3 py-2 text-sm">
                        <input name="request_note" maxlength="500" placeholder="Lý do / ghi chú" class="rounded-xl border border-brand-border px-3 py-2 text-sm">
                        <div class="sm:col-span-4"><button class="rounded-xl bg-brand-dark px-5 py-2.5 text-sm font-bold text-white">Gửi yêu cầu</button></div>
                    </form>
                    <?php endif; ?>
                    <div class="mt-4 overflow-x-auto"><table class="w-full text-left text-sm"><thead class="text-xs uppercase text-brand-muted"><tr><th class="py-2">Mã</th><th class="py-2">Phụ tùng</th><th class="py-2">Tồn</th><th class="py-2">SL yêu cầu</th><th class="py-2">Trạng thái</th></tr></thead><tbody class="divide-y divide-slate-100"><?php foreach ($orderRequests as $request): ?><tr><td class="py-3 font-semibold"><?= employee_e($request['request_code']) ?></td><td class="py-3"><?= employee_e($request['part_code'] . ' · ' . $request['part_name']) ?></td><td class="py-3"><?= (int)$request['stock_quantity'] ?></td><td class="py-3"><?= (int)$request['quantity'] ?></td><td class="py-3"><?= employee_e($request['status']) ?></td></tr><?php endforeach; ?><?php if (!$orderRequests): ?><tr><td colspan="5" class="py-4 text-brand-muted">Chưa có yêu cầu phụ tùng.</td></tr><?php endif; ?></tbody></table></div>
                </section>
            <?php elseif ($view === 'parts'): ?>
                <div class="overflow-hidden rounded-2xl border border-brand-border bg-white">
                    <div class="overflow-x-auto"><table class="w-full min-w-[750px] text-left text-sm">
                        <thead class="bg-slate-50 text-xs uppercase text-brand-muted"><tr><th class="px-4 py-4">Mã yêu cầu</th><th class="px-4 py-4">Phiếu</th><th class="px-4 py-4">Mã / tên phụ tùng</th><th class="px-4 py-4">Tồn kho</th><th class="px-4 py-4">Số lượng</th><th class="px-4 py-4">Trạng thái</th><th class="px-4 py-4">Ngày yêu cầu</th></tr></thead>
                        <tbody class="divide-y divide-slate-100"><?php foreach ($requests as $request): ?><tr><td class="px-4 py-4 font-bold"><?= employee_e($request['request_code']) ?></td><td class="px-4 py-4"><?= employee_e($request['order_code']) ?></td><td class="px-4 py-4"><?= employee_e($request['part_code'] . ' · ' . $request['part_name']) ?></td><td class="px-4 py-4"><?= (int)$request['stock_quantity'] ?></td><td class="px-4 py-4"><?= (int)$request['quantity'] ?></td><td class="px-4 py-4"><?= employee_e($request['status']) ?></td><td class="px-4 py-4"><?= employee_e(date('d/m/Y H:i', strtotime($request['requested_at']))) ?></td></tr><?php endforeach; ?><?php if (!$requests): ?><tr><td colspan="7" class="px-4 py-10 text-center text-brand-muted">Chưa có yêu cầu phụ tùng.</td></tr><?php endif; ?></tbody>
                    </table></div>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <?php if ($view === 'dashboard'): ?>
                <?php $maxRevenue = max(1, 0, ...array_map(static fn(array $row): float => (float)$row['net'], $revenueRows)); ?>
                <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                    <?php foreach ([
                        ['Hóa đơn hôm nay', $dashboardStats['count_today'] ?? 0, 'text-brand-dark'],
                        ['Doanh thu đã thu hôm nay', employee_money((float)($dashboardStats['revenue_today'] ?? 0)), 'text-emerald-700'],
                        ['Chưa thanh toán', $dashboardStats['unpaid'] ?? 0, 'text-rose-700'],
                        ['Thanh toán một phần', $dashboardStats['partial'] ?? 0, 'text-amber-700'],
                        ['Đã thanh toán', $dashboardStats['paid'] ?? 0, 'text-emerald-700'],
                    ] as [$label, $value, $tone]): ?>
                        <article class="rounded-2xl border border-brand-border bg-white p-5"><p class="text-xs font-semibold uppercase text-brand-muted"><?= employee_e($label) ?></p><p class="display mt-3 text-3xl font-bold <?= employee_e($tone) ?>"><?= employee_e((string)$value) ?></p></article>
                    <?php endforeach; ?>
                </section>
                <section class="mt-6 rounded-2xl border border-brand-border bg-white p-5">
                    <div class="mb-5 flex items-center justify-between"><div><h2 class="font-bold text-brand-dark">Doanh thu thuần 7 ngày</h2><p class="mt-1 text-xs text-brand-muted">Tổng tiền thu trừ giao dịch hoàn tiền; dữ liệu giao dịch cũ không có log được đánh dấu riêng tại hóa đơn.</p></div><a href="employees.php?view=reports" class="text-sm font-bold text-brand-red">Báo cáo</a></div>
                    <div class="flex h-52 items-end gap-3 border-b border-slate-200 px-2">
                        <?php foreach ($revenueRows as $row): $barHeight = max(2, (int)(max(0, (float)$row['net']) / $maxRevenue * 100)); ?>
                            <div class="flex h-full flex-1 flex-col items-center justify-end gap-2"><span class="text-[10px] font-semibold text-slate-600"><?= employee_e(employee_money((float)$row['net'])) ?></span><div class="w-full max-w-16 rounded-t-lg bg-brand-red" style="height: <?= $barHeight ?>%"></div><span class="pb-2 text-[10px] text-brand-muted"><?= employee_e($row['label']) ?></span></div>
                        <?php endforeach; ?>
                    </div>
                </section>
                <section class="mt-6 overflow-hidden rounded-2xl border border-brand-border bg-white">
                    <div class="flex items-center justify-between border-b border-brand-border px-5 py-4"><h2 class="font-bold text-brand-dark">Hóa đơn gần đây</h2><a href="employees.php?view=invoices" class="text-sm font-bold text-brand-red">Tất cả hóa đơn</a></div>
                    <div class="overflow-x-auto"><table class="w-full min-w-[750px] text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-brand-muted"><tr><th class="px-4 py-3">Mã hóa đơn</th><th class="px-4 py-3">Phiếu / khách</th><th class="px-4 py-3">Biển số</th><th class="px-4 py-3">Ngày lập</th><th class="px-4 py-3">Tổng tiền</th><th class="px-4 py-3">Thanh toán</th></tr></thead><tbody class="divide-y divide-slate-100"><?php foreach ($invoices as $invoice): ?><tr><td class="px-4 py-3"><a class="font-bold text-brand-red" href="employees.php?view=invoice&amp;id=<?= (int)$invoice['id'] ?>"><?= employee_e($invoice['invoice_code']) ?></a></td><td class="px-4 py-3"><?= employee_e($invoice['order_code']) ?><br><span class="text-xs text-brand-muted"><?= employee_e($invoice['customer_name'] ?? '—') ?></span></td><td class="px-4 py-3"><?= employee_e($invoice['license_plate']) ?></td><td class="px-4 py-3"><?= employee_e(date('d/m/Y', strtotime($invoice['created_at']))) ?></td><td class="px-4 py-3 font-semibold"><?= employee_e(employee_money((float)$invoice['grand_total'])) ?></td><td class="px-4 py-3"><?= employee_e($statusLabels[$invoice['payment_status']] ?? $invoice['payment_status']) ?></td></tr><?php endforeach; ?><?php if (!$invoices): ?><tr><td colspan="6" class="px-4 py-8 text-center text-brand-muted">Chưa có hóa đơn.</td></tr><?php endif; ?></tbody></table></div>
                </section>
            <?php elseif ($view === 'new-invoice'): ?>
                <div class="overflow-hidden rounded-2xl border border-brand-border bg-white">
                    <div class="border-b border-brand-border px-5 py-4"><h2 class="font-bold text-brand-dark">Phiếu đã hoàn thành, chưa có hóa đơn</h2><p class="mt-1 text-xs text-brand-muted">Chỉ tạo hóa đơn từ phiếu đã hoàn thành nghiệm thu.</p></div>
                    <div class="overflow-x-auto"><table class="w-full min-w-[750px] text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-brand-muted"><tr><th class="px-4 py-3">Phiếu</th><th class="px-4 py-3">Khách hàng</th><th class="px-4 py-3">Xe</th><th class="px-4 py-3">Ngày nhận</th><th class="px-4 py-3">Thao tác</th></tr></thead><tbody class="divide-y divide-slate-100"><?php foreach ($unbilledOrders as $order): ?><tr><td class="px-4 py-4 font-bold"><?= employee_e($order['order_code']) ?></td><td class="px-4 py-4"><?= employee_e($order['customer_name'] ?? '—') ?></td><td class="px-4 py-4"><?= employee_e($order['license_plate'] . ' · ' . $order['brand'] . ' ' . $order['model']) ?></td><td class="px-4 py-4"><?= employee_e(date('d/m/Y', strtotime($order['received_at']))) ?></td><td class="px-4 py-4"><form method="post" class="flex flex-wrap items-center gap-2"><input type="hidden" name="csrf" value="<?= employee_e($csrfToken) ?>"><input type="hidden" name="action" value="create_invoice"><input type="hidden" name="order_id" value="<?= (int)$order['id'] ?>"><input type="number" name="discount" min="0" step="0.01" value="0" placeholder="Giảm giá" class="w-28 rounded-lg border border-brand-border px-2 py-2"><button class="rounded-lg bg-brand-red px-3 py-2 text-xs font-bold text-white">Lập hóa đơn</button></form></td></tr><?php endforeach; ?><?php if (!$unbilledOrders): ?><tr><td colspan="5" class="px-4 py-10 text-center text-brand-muted">Không có phiếu đủ điều kiện lập hóa đơn.</td></tr><?php endif; ?></tbody></table></div>
                </div>
            <?php elseif ($view === 'invoices'): ?>
                <form method="get" class="mb-5 flex flex-wrap gap-3 rounded-2xl border border-brand-border bg-white p-4">
                    <input type="hidden" name="view" value="invoices">
                    <input name="q" value="<?= employee_e($search) ?>" placeholder="Mã hóa đơn, phiếu, khách, biển số" class="min-w-[220px] flex-1 rounded-xl border border-brand-border px-4 py-2.5 text-sm">
                    <input type="date" name="from" value="<?= employee_e($fromDate) ?>" class="rounded-xl border border-brand-border px-3 py-2 text-sm">
                    <input type="date" name="to" value="<?= employee_e($toDate) ?>" class="rounded-xl border border-brand-border px-3 py-2 text-sm">
                    <select name="status" class="rounded-xl border border-brand-border bg-white px-3 py-2 text-sm"><option value="">Tất cả trạng thái</option><?php foreach (['unpaid', 'partial', 'paid', 'refunded'] as $key): ?><option value="<?= employee_e($key) ?>" <?= $statusFilter === $key ? 'selected' : '' ?>><?= employee_e($statusLabels[$key]) ?></option><?php endforeach; ?></select>
                    <button class="rounded-xl bg-brand-dark px-5 py-2 text-sm font-bold text-white">Lọc</button>
                </form>
                <div class="overflow-hidden rounded-2xl border border-brand-border bg-white"><div class="overflow-x-auto"><table class="w-full min-w-[850px] text-left text-sm">
                    <thead class="bg-slate-50 text-xs uppercase text-brand-muted"><tr><th class="px-4 py-4">Mã hóa đơn</th><th class="px-4 py-4">Phiếu sửa chữa</th><th class="px-4 py-4">Khách hàng / xe</th><th class="px-4 py-4">Ngày lập</th><th class="px-4 py-4">Tổng tiền</th><th class="px-4 py-4">Trạng thái</th><th class="px-4 py-4"></th></tr></thead>
                    <tbody class="divide-y divide-slate-100"><?php foreach ($invoices as $invoice): ?><tr><td class="px-4 py-4 font-bold text-brand-dark"><?= employee_e($invoice['invoice_code']) ?></td><td class="px-4 py-4"><?= employee_e($invoice['order_code']) ?></td><td class="px-4 py-4"><?= employee_e($invoice['customer_name'] ?? '—') ?><br><span class="text-xs text-brand-muted"><?= employee_e($invoice['license_plate'] . ' · ' . $invoice['brand'] . ' ' . $invoice['model']) ?></span></td><td class="px-4 py-4"><?= employee_e(date('d/m/Y', strtotime($invoice['created_at']))) ?></td><td class="px-4 py-4 font-semibold"><?= employee_e(employee_money((float)$invoice['grand_total'])) ?></td><td class="px-4 py-4"><?= employee_e($statusLabels[$invoice['payment_status']] ?? $invoice['payment_status']) ?></td><td class="px-4 py-4"><a href="employees.php?view=invoice&amp;id=<?= (int)$invoice['id'] ?>" class="font-bold text-brand-red">Chi tiết</a></td></tr><?php endforeach; ?><?php if (!$invoices): ?><tr><td colspan="7" class="px-4 py-10 text-center text-brand-muted">Không tìm thấy hóa đơn.</td></tr><?php endif; ?></tbody>
                </table></div></div>
            <?php elseif ($view === 'invoice' && $selectedInvoice): ?>
                <section class="rounded-2xl border border-brand-border bg-white p-5">
                    <div class="flex flex-wrap items-start justify-between gap-4"><div><p class="text-xs font-bold uppercase text-brand-muted">Hóa đơn</p><h2 class="display mt-1 text-3xl font-bold text-brand-dark"><?= employee_e($selectedInvoice['invoice_code']) ?></h2><p class="mt-1 text-sm text-brand-muted">Phiếu <?= employee_e($selectedInvoice['order_code']) ?> · <?= employee_e(date('d/m/Y H:i', strtotime($selectedInvoice['created_at']))) ?></p></div><div class="flex gap-2"><a target="_blank" rel="noopener" href="invoice_print.php?id=<?= (int)$selectedInvoice['id'] ?>" class="rounded-xl border border-brand-border px-4 py-2 text-sm font-bold">In hóa đơn</a><span class="rounded-xl bg-slate-100 px-4 py-2 text-sm font-bold"><?= employee_e($statusLabels[$selectedInvoice['payment_status']] ?? $selectedInvoice['payment_status']) ?></span></div></div>
                    <div class="mt-5 grid gap-4 md:grid-cols-3"><div><p class="text-xs text-brand-muted">Khách hàng</p><p class="mt-1 font-bold"><?= employee_e($selectedInvoice['customer_name'] ?? '—') ?></p><p class="text-sm"><?= employee_e($selectedInvoice['customer_phone'] ?? '—') ?></p><p class="text-sm text-brand-muted"><?= employee_e($selectedInvoice['customer_address'] ?? '') ?></p></div><div><p class="text-xs text-brand-muted">Xe</p><p class="mt-1 font-bold"><?= employee_e($selectedInvoice['license_plate']) ?></p><p class="text-sm"><?= employee_e(trim($selectedInvoice['brand'] . ' ' . $selectedInvoice['model'])) ?></p></div><div><p class="text-xs text-brand-muted">Đã thu / còn thiếu</p><p class="mt-1 font-bold text-emerald-700"><?= employee_e(employee_money($selectedBalance['paid'])) ?></p><p class="text-sm text-rose-700"><?= employee_e(employee_money($selectedBalance['balance'])) ?></p></div></div>
                </section>
                <section class="mt-5 overflow-hidden rounded-2xl border border-brand-border bg-white">
                    <div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-brand-muted"><tr><th class="px-4 py-3">Dịch vụ / phụ tùng</th><th class="px-4 py-3">Loại</th><th class="px-4 py-3">SL</th><th class="px-4 py-3">Đơn giá</th><th class="px-4 py-3">Thành tiền</th></tr></thead><tbody class="divide-y divide-slate-100"><?php foreach ($invoiceItems as $item): ?><tr><td class="px-4 py-3"><?= employee_e($item['description'] ?? $item['item_name'] ?? '') ?></td><td class="px-4 py-3"><?= employee_e(in_array($item['item_type'] ?? '', ['part'], true) ? 'Phụ tùng' : 'Dịch vụ') ?></td><td class="px-4 py-3"><?= (int)$item['quantity'] ?></td><td class="px-4 py-3"><?= employee_e(employee_money((float)($item['unit_price'] ?? 0))) ?></td><td class="px-4 py-3"><?= employee_e(employee_money((float)($item['subtotal'] ?? ((float)$item['unit_price'] * (int)$item['quantity'])))) ?></td></tr><?php endforeach; ?></tbody><tfoot class="bg-slate-50 text-sm"><tr><td colspan="4" class="px-4 py-2 text-right">Tiền dịch vụ</td><td class="px-4 py-2 font-semibold"><?= employee_e(employee_money((float)$selectedInvoice['total_services'])) ?></td></tr><tr><td colspan="4" class="px-4 py-2 text-right">Tiền phụ tùng</td><td class="px-4 py-2 font-semibold"><?= employee_e(employee_money((float)$selectedInvoice['total_parts'])) ?></td></tr><tr><td colspan="4" class="px-4 py-2 text-right">Giảm giá</td><td class="px-4 py-2 font-semibold">− <?= employee_e(employee_money((float)$selectedInvoice['discount'])) ?></td></tr><tr><td colspan="4" class="px-4 py-3 text-right font-bold">Tổng cần thanh toán</td><td class="px-4 py-3 font-bold"><?= employee_e(employee_money((float)$selectedInvoice['grand_total'])) ?></td></tr></tfoot></table></div>
                </section>
                <div class="mt-5 grid gap-5 xl:grid-cols-2">
                    <section class="rounded-2xl border border-brand-border bg-white p-5">
                        <h3 class="font-bold text-brand-dark">Ghi nhận giao dịch</h3>
                        <?php if ($selectedInvoice['payment_status'] !== 'cancelled' && ($selectedBalance['balance'] > 0 || $selectedBalance['paid'] > 0)): ?>
                        <form method="post" class="mt-4 grid gap-3 sm:grid-cols-2">
                            <input type="hidden" name="csrf" value="<?= employee_e($csrfToken) ?>"><input type="hidden" name="action" value="record_transaction"><input type="hidden" name="invoice_id" value="<?= (int)$selectedInvoice['id'] ?>">
                            <label class="text-xs font-semibold">Loại giao dịch<select name="transaction_type" class="mt-1 block w-full rounded-xl border border-brand-border bg-white px-3 py-2 text-sm"><option value="payment">Thu tiền</option><?php if ($selectedBalance['paid'] > 0): ?><option value="refund">Hoàn tiền</option><?php endif; ?></select></label>
                            <label class="text-xs font-semibold">Số tiền<input type="number" name="amount" required min="0.01" max="<?= employee_e(number_format(max($selectedBalance['paid'], $selectedBalance['balance']), 2, '.', '')) ?>" step="0.01" class="mt-1 block w-full rounded-xl border border-brand-border px-3 py-2 text-sm"></label>
                            <label class="text-xs font-semibold">Phương thức<select name="payment_method" class="mt-1 block w-full rounded-xl border border-brand-border bg-white px-3 py-2 text-sm"><?php foreach ($paymentMethods as $key => $label): ?><option value="<?= employee_e($key) ?>"><?= employee_e($label) ?></option><?php endforeach; ?></select></label>
                            <label class="text-xs font-semibold">Ghi chú<input name="transaction_note" maxlength="255" class="mt-1 block w-full rounded-xl border border-brand-border px-3 py-2 text-sm"></label>
                            <div class="sm:col-span-2"><button class="rounded-xl bg-brand-red px-5 py-3 text-sm font-bold text-white">Lưu giao dịch</button></div>
                        </form>
                        <?php else: ?><p class="mt-3 text-sm text-brand-muted">Hóa đơn này không thể nhận giao dịch mới.</p><?php endif; ?>
                    </section>
                    <section class="rounded-2xl border border-brand-border bg-white p-5"><h3 class="font-bold text-brand-dark">Lịch sử giao dịch</h3><div class="mt-3 space-y-3"><?php foreach ($invoiceTransactions as $transaction): ?><div class="flex items-start justify-between gap-3 border-b border-slate-100 pb-3 text-sm"><div><p class="font-bold"><?= employee_e($transaction['transaction_code']) ?> · <?= $transaction['transaction_type'] === 'refund' ? 'Hoàn tiền' : 'Thanh toán' ?></p><p class="text-xs text-brand-muted"><?= employee_e($paymentMethods[$transaction['payment_method']] ?? $transaction['payment_method']) ?> · <?= employee_e($transaction['staff_name']) ?> · <?= employee_e(date('d/m/Y H:i', strtotime($transaction['created_at']))) ?></p><a class="mt-1 inline-block text-xs font-bold text-brand-red" target="_blank" rel="noopener" href="payment_receipt.php?id=<?= (int)$transaction['id'] ?>">In biên nhận</a></div><span class="font-bold <?= $transaction['transaction_type'] === 'refund' ? 'text-rose-700' : 'text-emerald-700' ?>"><?= $transaction['transaction_type'] === 'refund' ? '−' : '+' ?><?= employee_e(employee_money((float)$transaction['amount'])) ?></span></div><?php endforeach; ?><?php if (!$invoiceTransactions): ?><p class="text-sm text-brand-muted"><?= $selectedInvoice['payment_status'] === 'paid' ? 'Hóa đơn cũ đã đánh dấu thanh toán; hệ thống chưa có log giao dịch chi tiết.' : 'Chưa có giao dịch.' ?></p><?php endif; ?></div></section>
                </div>
            <?php elseif ($view === 'transactions'): ?>
                <div class="overflow-hidden rounded-2xl border border-brand-border bg-white"><div class="overflow-x-auto"><table class="w-full min-w-[800px] text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-brand-muted"><tr><th class="px-4 py-4">Mã giao dịch</th><th class="px-4 py-4">Hóa đơn</th><th class="px-4 py-4">Loại</th><th class="px-4 py-4">Số tiền</th><th class="px-4 py-4">Phương thức</th><th class="px-4 py-4">Thời gian</th><th class="px-4 py-4">Nhân viên</th><th class="px-4 py-4">Trạng thái</th></tr></thead><tbody class="divide-y divide-slate-100"><?php foreach ($transactions as $transaction): ?><tr><td class="px-4 py-4 font-bold"><?= employee_e($transaction['transaction_code']) ?></td><td class="px-4 py-4"><a class="text-brand-red" href="employees.php?view=invoice&amp;id=<?= (int)$transaction['invoice_id'] ?>"><?= employee_e($transaction['invoice_code']) ?></a></td><td class="px-4 py-4"><?= $transaction['transaction_type'] === 'refund' ? 'Hoàn tiền' : 'Thanh toán' ?></td><td class="px-4 py-4"><?= employee_e(employee_money((float)$transaction['amount'])) ?></td><td class="px-4 py-4"><?= employee_e($paymentMethods[$transaction['payment_method']] ?? $transaction['payment_method']) ?></td><td class="px-4 py-4"><?= employee_e(date('d/m/Y H:i', strtotime($transaction['created_at']))) ?></td><td class="px-4 py-4"><?= employee_e($transaction['staff_name']) ?></td><td class="px-4 py-4"><?= employee_e($transaction['status']) ?></td></tr><?php endforeach; ?><?php if (!$transactions): ?><tr><td colspan="8" class="px-4 py-10 text-center text-brand-muted">Chưa có giao dịch.</td></tr><?php endif; ?></tbody></table></div></div>
            <?php elseif ($view === 'reports'): ?>
                <?php $chartMax = max(1, 0, ...array_map(static fn(array $row): float => (float)$row['payments'], $revenueRows)); ?>
                <form method="get" class="mb-5 flex flex-wrap items-end gap-3 rounded-2xl border border-brand-border bg-white p-4">
                    <input type="hidden" name="view" value="reports">
                    <label class="text-xs font-semibold">Từ ngày<input type="date" name="from" value="<?= employee_e($fromDate) ?>" class="mt-1 block rounded-xl border border-brand-border px-3 py-2 text-sm"></label>
                    <label class="text-xs font-semibold">Đến ngày<input type="date" name="to" value="<?= employee_e($toDate) ?>" class="mt-1 block rounded-xl border border-brand-border px-3 py-2 text-sm"></label>
                    <label class="text-xs font-semibold">Nhóm theo<select name="period" class="mt-1 block rounded-xl border border-brand-border bg-white px-3 py-2 text-sm"><option value="day" <?= $reportPeriod === 'day' ? 'selected' : '' ?>>Ngày</option><option value="month" <?= $reportPeriod === 'month' ? 'selected' : '' ?>>Tháng</option></select></label>
                    <button class="rounded-xl bg-brand-dark px-5 py-2.5 text-sm font-bold text-white">Xem báo cáo</button>
                    <a href="employees.php?view=reports&amp;from=<?= employee_e($fromDate) ?>&amp;to=<?= employee_e($toDate) ?>&amp;export=csv" class="rounded-xl border border-brand-border px-5 py-2.5 text-sm font-bold">Xuất CSV giao dịch</a>
                </form>
                <section class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5"><article class="rounded-2xl border border-brand-border bg-white p-5"><p class="text-xs uppercase text-brand-muted">Số hóa đơn</p><p class="display mt-2 text-2xl font-bold text-brand-dark"><?= (int)$reportTotals['invoice_count'] ?></p></article><article class="rounded-2xl border border-brand-border bg-white p-5"><p class="text-xs uppercase text-brand-muted">Chưa thanh toán</p><p class="display mt-2 text-2xl font-bold text-rose-700"><?= (int)$reportTotals['unpaid_count'] ?></p></article><article class="rounded-2xl border border-brand-border bg-white p-5"><p class="text-xs uppercase text-brand-muted">Tổng tiền thu</p><p class="display mt-2 text-2xl font-bold text-emerald-700"><?= employee_e(employee_money($reportTotals['payments'])) ?></p></article><article class="rounded-2xl border border-brand-border bg-white p-5"><p class="text-xs uppercase text-brand-muted">Tổng hoàn tiền</p><p class="display mt-2 text-2xl font-bold text-rose-700"><?= employee_e(employee_money($reportTotals['refunds'])) ?></p></article><article class="rounded-2xl border border-brand-border bg-white p-5"><p class="text-xs uppercase text-brand-muted">Doanh thu thuần</p><p class="display mt-2 text-2xl font-bold text-brand-dark"><?= employee_e(employee_money($reportTotals['net'])) ?></p></article></section>
                <section class="mt-5 rounded-2xl border border-brand-border bg-white p-5"><h2 class="font-bold text-brand-dark">Doanh thu theo <?= $reportPeriod === 'month' ? 'tháng' : 'ngày' ?></h2><div class="mt-5 space-y-3"><?php foreach ($revenueRows as $row): $payments = (float)$row['payments']; $refunds = (float)$row['refunds']; $width = (int)($payments / $chartMax * 100); ?><div class="grid grid-cols-[90px_1fr_150px] items-center gap-3 text-xs"><span class="font-semibold"><?= employee_e($row['period_key']) ?></span><div class="h-3 overflow-hidden rounded-full bg-slate-100"><div class="h-full rounded-full bg-brand-red" style="width: <?= $width ?>%"></div></div><span class="text-right font-semibold"><?= employee_e(employee_money($payments - $refunds)) ?></span></div><?php endforeach; ?><?php if (!$revenueRows): ?><p class="text-sm text-brand-muted">Không có giao dịch trong khoảng thời gian này.</p><?php endif; ?></div></section>
                <section class="mt-5 overflow-hidden rounded-2xl border border-brand-border bg-white"><div class="overflow-x-auto"><table class="w-full text-left text-sm"><thead class="bg-slate-50 text-xs uppercase text-brand-muted"><tr><th class="px-4 py-3">Phương thức</th><th class="px-4 py-3">Đã thu</th><th class="px-4 py-3">Hoàn tiền</th><th class="px-4 py-3">Thuần</th></tr></thead><tbody class="divide-y divide-slate-100"><?php foreach ($methodTotals as $row): ?><tr><td class="px-4 py-3"><?= employee_e($paymentMethods[$row['payment_method']] ?? $row['payment_method']) ?></td><td class="px-4 py-3"><?= employee_e(employee_money((float)$row['payments'])) ?></td><td class="px-4 py-3"><?= employee_e(employee_money((float)$row['refunds'])) ?></td><td class="px-4 py-3 font-semibold"><?= employee_e(employee_money((float)$row['net_amount'])) ?></td></tr><?php endforeach; ?><?php if (!$methodTotals): ?><tr><td colspan="4" class="px-4 py-5 text-brand-muted">Chưa có dữ liệu.</td></tr><?php endif; ?></tbody></table></div></section>
            <?php endif; ?>
        <?php endif; ?>
    </main>
</div>
</body>
</html>
