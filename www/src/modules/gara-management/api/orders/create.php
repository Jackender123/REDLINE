<?php
// www/src/modules/gara-management/api/orders/create.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../includes/auth.php';

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
$licensePlate    = strtoupper(trim((string)($_POST['license_plate'] ?? '')));
$vehicleBrand    = trim((string)($_POST['vehicle_brand'] ?? 'Khác'));
$vehicleModel    = trim((string)($_POST['vehicle_model'] ?? ''));
$kmIn            = (int)($_POST['km_in'] ?? 0);
$vinNumber       = strtoupper(trim((string)($_POST['vin_number'] ?? '')));
$fuelLevel       = trim((string)($_POST['fuel_level'] ?? '1/2'));
$isEV            = ($_POST['is_ev'] ?? '0') === '1';
$batteryCapacity = trim((string)($_POST['battery_capacity'] ?? ''));
$batterySoH      = trim((string)($_POST['battery_soh'] ?? ''));
$intakeNotes     = trim((string)($_POST['intake_notes'] ?? ''));
$serviceType     = trim((string)($_POST['service_type'] ?? 'OIL & FILTER CHANGE'));
$customerRequest = trim((string)($_POST['customer_request'] ?? ''));
$symptoms        = trim((string)($_POST['symptoms'] ?? ''));
$appointmentId   = (int)($_POST['appointment_id'] ?? 0);

// Kiểm tra dữ liệu bắt buộc
if (!csrf_valid($_POST['csrf'] ?? null)) {
    http_response_code(403);
    exit('Phiên làm việc không hợp lệ. Vui lòng tải lại trang.');
}
if ($customerName === '' || $customerPhone === '' || $licensePlate === '' || $vehicleBrand === '' || $kmIn < 0
    || ($isEV && (($batteryCapacity !== '' && (!is_numeric($batteryCapacity) || (float)$batteryCapacity < 0))
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
    $pdo->beginTransaction();

    // 2. Xử lý Khách hàng (customers)
    $stmtC = $pdo->prepare("SELECT id FROM customers WHERE phone = ? LIMIT 1");
    $stmtC->execute([$customerPhone]);
    $customerId = $stmtC->fetchColumn();

    if (!$customerId) {
        $stmtInsC = $pdo->prepare("INSERT INTO customers (full_name, phone, created_at) VALUES (?, ?, NOW())");
        $stmtInsC->execute([$customerName, $customerPhone]);
        $customerId = $pdo->lastInsertId();
    } else {
        $stmtUpC = $pdo->prepare("UPDATE customers SET full_name = ? WHERE id = ?");
        $stmtUpC->execute([$customerName, $customerId]);
    }

    // 3. Xử lý Phương tiện (vehicles) - Kiểm tra cấu trúc cột thực tế của bảng vehicles
    $vehCols = [];
    $vColStmt = $pdo->query("SHOW COLUMNS FROM vehicles");
    while ($vRow = $vColStmt->fetch(PDO::FETCH_ASSOC)) {
        $vehCols[] = $vRow['Field'];
    }

    // Xác định tên cột lưu VIN trong bảng vehicles ('vin' hoặc 'vin_number')
    $vinColName = null;
    if (in_array('vin', $vehCols)) {
        $vinColName = 'vin';
    } elseif (in_array('vin_number', $vehCols)) {
        $vinColName = 'vin_number';
    }

    $stmtV = $pdo->prepare("SELECT id FROM vehicles WHERE license_plate = ? LIMIT 1");
    $stmtV->execute([$licensePlate]);
    $vehicleId = $stmtV->fetchColumn();

    if (!$vehicleId) {
        $vInsertData = [
            'license_plate' => $licensePlate,
            'brand' => $vehicleBrand,
        ];
        if (in_array('customer_id', $vehCols)) $vInsertData['customer_id'] = $customerId;
        if (in_array('model', $vehCols))        $vInsertData['model'] = $vehicleModel;
        if ($vinColName)                        $vInsertData[$vinColName] = $vinNumber;
        if (in_array('is_ev', $vehCols))        $vInsertData['is_ev'] = $isEV ? 1 : 0;
        if (in_array('engine_type', $vehCols))  $vInsertData['engine_type'] = $isEV ? 'ev' : 'gasoline';
        if (in_array('odometer', $vehCols))     $vInsertData['odometer'] = $kmIn;
        if (in_array('created_at', $vehCols))   $vInsertData['created_at'] = date('Y-m-d H:i:s');

        $vFields = implode(', ', array_keys($vInsertData));
        $vPlaceholders = implode(', ', array_fill(0, count($vInsertData), '?'));

        $stmtInsV = $pdo->prepare("INSERT INTO vehicles ($vFields) VALUES ($vPlaceholders)");
        $stmtInsV->execute(array_values($vInsertData));
        $vehicleId = $pdo->lastInsertId();
    } else {
        $vUpdateSets = [];
        $vUpdateVals = [];

        if (in_array('customer_id', $vehCols)) {
            $vUpdateSets[] = "customer_id = ?";
            $vUpdateVals[] = $customerId;
        }
        if (in_array('model', $vehCols)) {
            $vUpdateSets[] = "model = ?";
            $vUpdateVals[] = $vehicleModel;
        }
        if (in_array('brand', $vehCols)) {
            $vUpdateSets[] = "brand = ?";
            $vUpdateVals[] = $vehicleBrand;
        }
        if (in_array('is_ev', $vehCols)) {
            $vUpdateSets[] = "is_ev = ?";
            $vUpdateVals[] = $isEV ? 1 : 0;
        }
        if (in_array('engine_type', $vehCols)) {
            $vUpdateSets[] = "engine_type = ?";
            $vUpdateVals[] = $isEV ? 'ev' : 'gasoline';
        }
        if (in_array('odometer', $vehCols)) {
            $vUpdateSets[] = "odometer = ?";
            $vUpdateVals[] = $kmIn;
        }
        if ($vinColName) {
            $vUpdateSets[] = "$vinColName = ?";
            $vUpdateVals[] = $vinNumber;
        }
        if (in_array('updated_at', $vehCols)) {
            $vUpdateSets[] = "updated_at = ?";
            $vUpdateVals[] = date('Y-m-d H:i:s');
        }

        if (!empty($vUpdateSets)) {
            $vUpdateVals[] = $vehicleId;
            $stmtUpV = $pdo->prepare("UPDATE vehicles SET " . implode(', ', $vUpdateSets) . " WHERE id = ?");
            $stmtUpV->execute($vUpdateVals);
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
        $linkedOrder = $pdo->prepare('SELECT id FROM repair_orders WHERE appointment_id = :id LIMIT 1');
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
    if ($appointmentId > 0 && in_array('appointment_id', $existingCols)) $dataToInsert['appointment_id'] = $appointmentId;
    if (in_array('receptionist_id', $existingCols)) $dataToInsert['receptionist_id'] = $staffId;
    if (in_array('staff_id', $existingCols))        $dataToInsert['staff_id'] = $staffId;
    if (in_array('km_in', $existingCols))           $dataToInsert['km_in'] = $kmIn;
    if (in_array('fuel_level', $existingCols))      $dataToInsert['fuel_level'] = $fuelLevel;
    if (in_array('intake_notes', $existingCols))    $dataToInsert['intake_notes'] = $intakeNotes;
    if (in_array('symptoms', $existingCols))        $dataToInsert['symptoms'] = $symptoms;
    if (in_array('service_type', $existingCols))    $dataToInsert['service_type'] = $serviceType;
    if (in_array('customer_request', $existingCols))$dataToInsert['customer_request'] = $customerRequest;
    if (in_array('odometer', $existingCols))       $dataToInsert['odometer'] = $kmIn;
    if (in_array('ev_notes', $existingCols) && $isEV) $dataToInsert['ev_notes'] = trim('Dung lượng pin: ' . $batteryCapacity . ' kWh; SoH: ' . $batterySoH . '%');
    if (in_array('created_at', $existingCols))      $dataToInsert['created_at'] = date('Y-m-d H:i:s');
    if (in_array('updated_at', $existingCols))      $dataToInsert['updated_at'] = date('Y-m-d H:i:s');

    $fields = implode(', ', array_keys($dataToInsert));
    $placeholders = implode(', ', array_fill(0, count($dataToInsert), '?'));
    
    $stmtRO = $pdo->prepare("INSERT INTO repair_orders ($fields) VALUES ($placeholders)");
    $stmtRO->execute(array_values($dataToInsert));
    $orderId = $pdo->lastInsertId();

    if ($isEV && ($batteryCapacity !== '' || $batterySoH !== '')) {
        $pdo->prepare(
            'INSERT INTO vehicle_ev_details (vehicle_id, battery_capacity, battery_soh, updated_at)
             VALUES (:vehicle_id, :capacity, :soh, NOW())
             ON DUPLICATE KEY UPDATE battery_capacity = VALUES(battery_capacity), battery_soh = VALUES(battery_soh), updated_at = NOW()'
        )->execute([
            'vehicle_id' => $vehicleId,
            'capacity' => $batteryCapacity !== '' ? $batteryCapacity : null,
            'soh' => $batterySoH !== '' ? $batterySoH : null,
        ]);
    } elseif (!$isEV) {
        $pdo->prepare('DELETE FROM vehicle_ev_details WHERE vehicle_id = :vehicle_id')
            ->execute(['vehicle_id' => $vehicleId]);
    }

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