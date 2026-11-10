<?php
// www/src/modules/gara-management/api/orders/create.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../includes/auth.php';
require_once __DIR__ . '/../../../../includes/customer_vehicle_sync.php';

// Kiểm tra quyền truy cập nhân viên / quản trị
$staff = function_exists('current_staff') ? current_staff() : null;
if (!$staff || !in_array($staff['role_name'] ?? '', ['Admin', 'Receptionist'], true)) {
    header('Location: ../../../../../login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../../../../../employees.php');
    exit;
}

// 1. Nhận dữ liệu POST từ Form
$customerName    = trim((string)($_POST['customer_name'] ?? ''));
$customerPhone   = trim((string)($_POST['customer_phone'] ?? ''));
$customerEmail = trim((string) ($_POST['customer_email'] ?? ''));
$customerZalo = trim((string) ($_POST['customer_zalo'] ?? ''));
$contactMethod = (string) ($_POST['contact_method'] ?? 'phone');
$licensePlate    = strtoupper(trim((string)($_POST['license_plate'] ?? '')));
$vehicleBrand    = trim((string)($_POST['vehicle_brand'] ?? 'Khác'));
$vehicleModel    = trim((string)($_POST['vehicle_model'] ?? ''));
$kmRaw = trim((string) ($_POST['km_in'] ?? ''));
$kmIn = $kmRaw === '' ? null : (int) $kmRaw;
$vehicleYear = trim((string) ($_POST['vehicle_year'] ?? ''));
$vehicleColor = trim((string) ($_POST['vehicle_color'] ?? ''));
$vinNumber       = strtoupper(trim((string)($_POST['vin_number'] ?? '')));
$fuelLevel       = trim((string)($_POST['fuel_level'] ?? '1/2'));
$engineType = (string) ($_POST['engine_type'] ?? (($_POST['is_ev'] ?? '0') === '1' ? 'ev' : 'gasoline'));
$isEV = $engineType === 'ev';
$hasBattery = in_array($engineType, ['ev', 'hybrid'], true);
$batteryCapacity = trim((string)($_POST['battery_capacity'] ?? ''));
$batterySoH      = trim((string)($_POST['battery_soh'] ?? ''));
$intakeNotes     = trim((string)($_POST['intake_notes'] ?? ''));
$serviceType     = trim((string)($_POST['service_type'] ?? 'OIL & FILTER CHANGE'));
$customerRequest = trim((string)($_POST['customer_request'] ?? ''));
$symptoms        = trim((string)($_POST['symptoms'] ?? ''));
$appointmentId   = (int)($_POST['appointment_id'] ?? 0);
$portalUserId = (int) ($_POST['portal_user_id'] ?? 0);
$portalVehicleId = (int) ($_POST['portal_vehicle_id'] ?? 0);

// Kiểm tra dữ liệu bắt buộc
if (!csrf_valid($_POST['csrf'] ?? null)) {
    http_response_code(403);
    exit('Phiên làm việc không hợp lệ. Vui lòng tải lại trang.');
}
if ($customerName === '' || mb_strlen($customerName) > 120 || $customerPhone === '' || strlen($customerPhone) > 30
    || strlen(rl_sync_phone($customerPhone)) < 8 || strlen(rl_sync_phone($customerPhone)) > 15
    || ($customerZalo !== '' && (strlen(rl_sync_phone($customerZalo)) < 8 || strlen(rl_sync_phone($customerZalo)) > 15))
    || ($customerEmail !== '' && (!filter_var($customerEmail, FILTER_VALIDATE_EMAIL) || strlen($customerEmail) > 190))
    || !in_array($contactMethod, ['phone', 'zalo', 'email'], true)
    || ($contactMethod === 'zalo' && $customerZalo === '') || ($contactMethod === 'email' && $customerEmail === '')
    || !preg_match('/^[0-9A-Z][0-9A-Z .\-]{2,18}[0-9A-Z]$/', $licensePlate)
    || $vehicleBrand === '' || mb_strlen($vehicleBrand) > 60 || $vehicleModel === '' || mb_strlen($vehicleModel) > 80
    || mb_strlen($vehicleColor) > 40 || strlen($vinNumber) > 50
    || !in_array($engineType, ['gasoline', 'diesel', 'hybrid', 'ev'], true)
    || ($kmRaw !== '' && (!ctype_digit($kmRaw) || strlen($kmRaw) > 7))
    || ($vehicleYear !== '' && (!ctype_digit($vehicleYear) || (int) $vehicleYear < 1950 || (int) $vehicleYear > (int) date('Y') + 1))
    || ($hasBattery && (($batteryCapacity !== '' && (!is_numeric($batteryCapacity) || (float)$batteryCapacity < 0 || (float)$batteryCapacity > 9999.99))
        || ($batterySoH !== '' && (!is_numeric($batterySoH) || (float)$batterySoH < 0 || (float)$batterySoH > 100))))) {
    http_response_code(422);
    exit('Vui lòng kiểm tra thông tin tiếp nhận xe và thông số pin.');
}

$uploadedPaths = [];
$uploadFiles = $_FILES['condition_images'] ?? null;
if (is_array($uploadFiles) && isset($uploadFiles['name']) && is_array($uploadFiles['name'])) {
    if (count($uploadFiles['name']) > 5) {
        http_response_code(422);
        exit('Chỉ được tải tối đa 5 ảnh hiện trạng.');
    }
    $uploadDir = dirname(__DIR__, 5) . '/public/uploads/reception';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        http_response_code(500);
        exit('Không thể tạo thư mục lưu ảnh tiếp nhận.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    $validImages = [];
    foreach ($uploadFiles['name'] as $index => $_) {
        $uploadError = $uploadFiles['error'][$index] ?? UPLOAD_ERR_NO_FILE;
        if ($uploadError === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($uploadError !== UPLOAD_ERR_OK || ($uploadFiles['size'][$index] ?? 0) > 5 * 1024 * 1024
            || !is_uploaded_file($uploadFiles['tmp_name'][$index] ?? '')) {
            http_response_code(422);
            exit('Ảnh không hợp lệ hoặc vượt quá giới hạn 5MB.');
        }
        $mime = $finfo->file($uploadFiles['tmp_name'][$index]);
        if (!isset($extensions[$mime])) {
            http_response_code(422);
            exit('Chỉ hỗ trợ ảnh JPG, PNG hoặc WebP.');
        }
        $validImages[] = ['tmp_name' => $uploadFiles['tmp_name'][$index], 'extension' => $extensions[$mime]];
    }
    foreach ($validImages as $image) {
        $filename = bin2hex(random_bytes(16)) . '.' . $image['extension'];
        if (!move_uploaded_file($image['tmp_name'], $uploadDir . DIRECTORY_SEPARATOR . $filename)) {
            foreach ($uploadedPaths as $path) {
                $absolutePath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
                if (is_file($absolutePath)) {
                    unlink($absolutePath);
                }
            }
            http_response_code(500);
            exit('Không thể lưu ảnh hiện trạng.');
        }
        $uploadedPaths[] = 'public/uploads/reception/' . $filename;
    }
}

try {
    $pdo = db();
    rl_sync_ensure_schema($pdo);
    $pdo->beginTransaction();

    // Resolve the appointment account on the server and validate vehicle ownership.
    if ($appointmentId > 0) {
        $s = $pdo->prepare('SELECT user_id FROM service_requests WHERE id = ? FOR UPDATE');
        $s->execute([$appointmentId]);
        $appointment = $s->fetch(PDO::FETCH_ASSOC);
        if (!$appointment) throw new RuntimeException('Appointment not found.');
        if (!empty($appointment['user_id'])) {
            if ($portalUserId && $portalUserId !== (int) $appointment['user_id']) throw new RuntimeException('The selected customer does not match this appointment.');
            $portalUserId = (int) $appointment['user_id'];
        }
    }
    $existingCustomer = null;
    foreach ($pdo->query('SELECT id, phone, portal_user_id FROM customers')->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
        if (rl_sync_phone((string) $candidate['phone']) !== rl_sync_phone($customerPhone)) continue;
        if ($existingCustomer) throw new RuntimeException('Multiple customers have this phone number. Please resolve the duplicate records.');
        $existingCustomer = $candidate;
    }
    if (!$portalUserId && !empty($existingCustomer['portal_user_id'])) $portalUserId = (int) $existingCustomer['portal_user_id'];
    if ($portalVehicleId && !$portalUserId) throw new RuntimeException('Select the vehicle owner first.');

    if ($portalUserId) {
        $s = $pdo->prepare('SELECT * FROM portal_users WHERE id = ? AND is_active = 1 FOR UPDATE');
        $s->execute([$portalUserId]);
        $portalCustomer = $s->fetch(PDO::FETCH_ASSOC);
        if (!$portalCustomer) throw new RuntimeException('Customer account not found.');
        if ($customerEmail === '') $customerEmail = $portalCustomer['email'];
        $pdo->prepare('UPDATE portal_users SET full_name = ?, phone = ?, email = ?, zalo = ?, contact_method = ? WHERE id = ?')
            ->execute([$customerName, $customerPhone, $customerEmail, $customerZalo ?: null, $contactMethod, $portalUserId]);
        $customerId = rl_sync_customer($pdo, $portalUserId, $portalCustomer);
        if (!$portalVehicleId) {
            $s = $pdo->prepare('SELECT id, plate FROM portal_vehicles WHERE user_id = ?');
            $s->execute([$portalUserId]);
            foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
                if (rl_sync_plate((string) $candidate['plate']) !== rl_sync_plate($licensePlate)) continue;
                if ($portalVehicleId) throw new RuntimeException('Multiple saved vehicles have this license plate.');
                $portalVehicleId = (int) $candidate['id'];
            }
        }
        $values = [$vehicleBrand, $vehicleModel, $licensePlate, $vehicleYear === '' ? null : (int) $vehicleYear,
            $vehicleColor === '' ? null : $vehicleColor, $vinNumber === '' ? null : $vinNumber, $engineType,
            $hasBattery && $batteryCapacity !== '' ? $batteryCapacity : null, $hasBattery && $batterySoH !== '' ? $batterySoH : null, $kmIn];
        if ($portalVehicleId) {
            $s = $pdo->prepare('SELECT id FROM portal_vehicles WHERE id = ? AND user_id = ? FOR UPDATE');
            $s->execute([$portalVehicleId, $portalUserId]);
            if (!$s->fetchColumn()) throw new RuntimeException('The saved vehicle does not belong to this customer.');
            $pdo->prepare('UPDATE portal_vehicles SET make = ?, model = ?, plate = ?, year = ?, color = ?, vin = ?, engine_type = ?, battery_capacity = ?, battery_soh = ?, mileage = COALESCE(?, mileage), updated_at = NOW() WHERE id = ? AND user_id = ?')
                ->execute(array_merge($values, [$portalVehicleId, $portalUserId]));
        } else {
            $pdo->prepare('INSERT INTO portal_vehicles (make, model, plate, year, color, vin, engine_type, battery_capacity, battery_soh, mileage, user_id, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')
                ->execute(array_merge($values, [$portalUserId]));
            $portalVehicleId = (int) $pdo->lastInsertId();
        }
        $vehicleId = rl_sync_vehicle($pdo, $portalVehicleId, $portalUserId, $customerId);
    } else {
        $customerId = $existingCustomer['id'] ?? null;
        $values = [$customerName, $customerPhone, $customerEmail ?: null, $customerZalo ?: null, $contactMethod];
        if ($customerId) {
            $pdo->prepare('UPDATE customers SET full_name = ?, phone = ?, email = COALESCE(?, email), zalo = COALESCE(?, zalo), contact_method = ? WHERE id = ?')->execute(array_merge($values, [$customerId]));
        } else {
            $pdo->prepare('INSERT INTO customers (full_name, phone, email, zalo, contact_method) VALUES (?, ?, ?, ?, ?)')->execute($values);
            $customerId = (int) $pdo->lastInsertId();
        }
        $vehicleId = null;
        foreach ($pdo->query('SELECT id, customer_id, license_plate FROM vehicles')->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
            if (rl_sync_plate((string) $candidate['license_plate']) !== rl_sync_plate($licensePlate)) continue;
            if ((int) $candidate['customer_id'] !== (int) $customerId || $vehicleId) throw new RuntimeException('This license plate is already registered to another vehicle or customer.');
            $vehicleId = (int) $candidate['id'];
        }
        $values = [$customerId, $licensePlate, $vehicleBrand, $vehicleModel, $vehicleYear === '' ? null : (int) $vehicleYear,
            $vehicleColor === '' ? null : $vehicleColor, $vinNumber === '' ? null : $vinNumber, $vinNumber === '' ? null : $vinNumber, $engineType, $isEV ? 1 : 0];
        if ($vehicleId) {
            $pdo->prepare('UPDATE vehicles SET customer_id = ?, license_plate = ?, brand = ?, model = ?, year = COALESCE(?, year), color = COALESCE(?, color), vin = COALESCE(?, vin), vin_number = COALESCE(?, vin_number), engine_type = ?, is_ev = ?, odometer = COALESCE(?, odometer) WHERE id = ?')
                ->execute(array_merge($values, [$kmIn, $vehicleId]));
        } else {
            $pdo->prepare('INSERT INTO vehicles (customer_id, license_plate, brand, model, year, color, vin, vin_number, engine_type, is_ev, odometer) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')->execute(array_merge($values, [$kmIn ?? 0]));
            $vehicleId = (int) $pdo->lastInsertId();
        }
    }

    // 4. Kiểm tra danh sách các cột hiện có của bảng repair_orders
    $existingCols = [];
    $colStmt = $pdo->query("SHOW COLUMNS FROM repair_orders");
    while ($row = $colStmt->fetch(PDO::FETCH_ASSOC)) {
        $existingCols[] = $row['Field'];
    }

    // Sinh mã phiếu ngẫu nhiên (VD: RO-A8B9C0)
    $orderCode = 'RO-' . strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 6));
    $staffId   = $staff['id'] ?? ($_SESSION['user_id'] ?? null);

    if ($appointmentId > 0) {
        $stmtAppt = $pdo->prepare('SELECT status FROM service_requests WHERE id = :id FOR UPDATE');
        $stmtAppt->execute(['id' => $appointmentId]);
        $appointmentStatus = $stmtAppt->fetchColumn();
        if ($appointmentStatus === false || !in_array($appointmentStatus, ['pending', 'processed', ''], true)) {
            throw new RuntimeException('Lịch hẹn không tồn tại, đã bị hủy hoặc không thể tiếp nhận.');
        }
        $linkedOrder = $pdo->prepare('SELECT id FROM repair_orders WHERE service_request_id = :id LIMIT 1');
        $linkedOrder->execute(['id' => $appointmentId]);
        if ($linkedOrder->fetchColumn()) {
            throw new RuntimeException('Lịch hẹn này đã được liên kết với một phiếu sửa chữa.');
        }
        if ($appointmentStatus !== 'processed') {
            $pdo->prepare("UPDATE service_requests SET status = 'processed' WHERE id = :id")
                ->execute(['id' => $appointmentId]);
        }
    }

    // Tự động xây dựng mảng INSERT cho repair_orders
    $dataToInsert = [
        'order_code' => $orderCode,
        'vehicle_id' => $vehicleId,
        'status'     => 'received'
    ];

    if (in_array('customer_id', $existingCols))     $dataToInsert['customer_id'] = $customerId;
    if ($appointmentId > 0) $dataToInsert['service_request_id'] = $appointmentId;
    if (in_array('receptionist_id', $existingCols)) $dataToInsert['receptionist_id'] = $staffId;
    if (in_array('staff_id', $existingCols))        $dataToInsert['staff_id'] = $staffId;
    if (in_array('km_in', $existingCols))           $dataToInsert['km_in'] = $kmIn;
    if (in_array('fuel_level', $existingCols))      $dataToInsert['fuel_level'] = $fuelLevel;
    if (in_array('intake_notes', $existingCols))    $dataToInsert['intake_notes'] = $intakeNotes;
    if (in_array('symptoms', $existingCols))        $dataToInsert['symptoms'] = $symptoms;
    if (in_array('service_type', $existingCols))    $dataToInsert['service_type'] = $serviceType;
    if (in_array('customer_request', $existingCols))$dataToInsert['customer_request'] = $customerRequest;
    if (in_array('odometer', $existingCols))       $dataToInsert['odometer'] = $kmIn;
    if (in_array('ev_notes', $existingCols) && $hasBattery) $dataToInsert['ev_notes'] = trim('Dung lượng pin: ' . $batteryCapacity . ' kWh; SoH: ' . $batterySoH . '%');
    if (in_array('created_at', $existingCols))      $dataToInsert['created_at'] = date('Y-m-d H:i:s');
    if (in_array('updated_at', $existingCols))      $dataToInsert['updated_at'] = date('Y-m-d H:i:s');

    $fields = implode(', ', array_keys($dataToInsert));
    $placeholders = implode(', ', array_fill(0, count($dataToInsert), '?'));
    
    $stmtRO = $pdo->prepare("INSERT INTO repair_orders ($fields) VALUES ($placeholders)");
    $stmtRO->execute(array_values($dataToInsert));
    $orderId = $pdo->lastInsertId();

    // Linked vehicles were synchronized with their portal record above, including
    // legacy battery details retained on the first association.
    if (!$portalUserId) rl_sync_battery($pdo, (int) $vehicleId, $engineType, $batteryCapacity, $batterySoH);

    foreach ($uploadedPaths as $imagePath) {
        $pdo->prepare('INSERT INTO reception_images (order_id, image_path, created_at) VALUES (:order_id, :image_path, NOW())')
            ->execute(['order_id' => $orderId, 'image_path' => $imagePath]);
    }

    $pdo->commit();

    // 6. Điều hướng về portal nhân viên kèm mã phiếu vừa tạo
    header('Location: ../../../../../employees.php?created_success=1&code=' . urlencode($orderCode));
    exit;

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    foreach ($uploadedPaths as $path) {
        $absolutePath = dirname(__DIR__, 5) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
        if (is_file($absolutePath)) {
            unlink($absolutePath);
        }
    }
    http_response_code(500);
    echo 'Không thể tạo phiếu tiếp nhận: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    exit;
}
