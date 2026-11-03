<?php
// www/src/modules/gara-management/api/orders/create.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../../../includes/auth.php';

// Kiểm tra quyền truy cập nhân viên / quản trị
$staff = function_exists('current_staff') ? current_staff() : null;
if (!$staff && function_exists('requireAuth')) {
    requireAuth();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../../../../../repair_orders.php');
    exit;
}

// 1. Nhận dữ liệu POST từ Form
$customerName    = trim((string)($_POST['customer_name'] ?? ''));
$customerPhone   = trim((string)($_POST['customer_phone'] ?? ''));
$licensePlate    = strtoupper(trim((string)($_POST['license_plate'] ?? '')));
$vehicleModel    = trim((string)($_POST['vehicle_model'] ?? ''));
$kmIn            = (int)($_POST['km_in'] ?? 0);
$vinNumber       = strtoupper(trim((string)($_POST['vin_number'] ?? '')));
$fuelLevel       = trim((string)($_POST['fuel_level'] ?? '1/2'));
$intakeNotes     = trim((string)($_POST['intake_notes'] ?? ''));
$serviceType     = trim((string)($_POST['service_type'] ?? 'OIL & FILTER CHANGE'));
$customerRequest = trim((string)($_POST['customer_request'] ?? ''));
$symptoms        = trim((string)($_POST['symptoms'] ?? ''));
$appointmentId   = (int)($_POST['appointment_id'] ?? 0);

// Kiểm tra dữ liệu bắt buộc
if (empty($customerName) || empty($customerPhone) || empty($licensePlate)) {
    echo "<script>alert('Vui lòng điền đầy đủ Tên khách hàng, Số điện thoại và Biển số xe!'); history.back();</script>";
    exit;
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
            'license_plate' => $licensePlate
        ];
        if (in_array('customer_id', $vehCols)) $vInsertData['customer_id'] = $customerId;
        if (in_array('model', $vehCols))        $vInsertData['model'] = $vehicleModel;
        if ($vinColName)                        $vInsertData[$vinColName] = $vinNumber;
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

    // Tự động xây dựng mảng INSERT cho repair_orders
    $dataToInsert = [
        'order_code' => $orderCode,
        'vehicle_id' => $vehicleId,
        'status'     => 'received'
    ];

    if (in_array('customer_id', $existingCols))     $dataToInsert['customer_id'] = $customerId;
    if (in_array('receptionist_id', $existingCols)) $dataToInsert['receptionist_id'] = $staffId;
    if (in_array('staff_id', $existingCols))        $dataToInsert['staff_id'] = $staffId;
    if (in_array('km_in', $existingCols))           $dataToInsert['km_in'] = $kmIn;
    if (in_array('fuel_level', $existingCols))      $dataToInsert['fuel_level'] = $fuelLevel;
    if (in_array('intake_notes', $existingCols))    $dataToInsert['intake_notes'] = $intakeNotes;
    if (in_array('symptoms', $existingCols))        $dataToInsert['symptoms'] = $symptoms;
    if (in_array('service_type', $existingCols))    $dataToInsert['service_type'] = $serviceType;
    if (in_array('customer_request', $existingCols))$dataToInsert['customer_request'] = $customerRequest;
    if (in_array('created_at', $existingCols))      $dataToInsert['created_at'] = date('Y-m-d H:i:s');
    if (in_array('updated_at', $existingCols))      $dataToInsert['updated_at'] = date('Y-m-d H:i:s');

    $fields = implode(', ', array_keys($dataToInsert));
    $placeholders = implode(', ', array_fill(0, count($dataToInsert), '?'));
    
    $stmtRO = $pdo->prepare("INSERT INTO repair_orders ($fields) VALUES ($placeholders)");
    $stmtRO->execute(array_values($dataToInsert));
    $orderId = $pdo->lastInsertId();

    // 5. Cập nhật trạng thái lịch hẹn trực tuyến nếu có
    if ($appointmentId > 0) {
        try {
            $stmtAppt = $pdo->prepare("UPDATE service_requests SET status = 'accepted' WHERE id = ?");
            $stmtAppt->execute([$appointmentId]);
        } catch (PDOException $e) {
            // Bỏ qua nếu bảng service_requests sử dụng giá trị enum khác
        }
    }

    $pdo->commit();

    // 6. Điều hướng về trang danh sách phiếu sửa chữa kèm tham số báo THÀNH CÔNG
    header('Location: ../../../../../repair_orders.php?created_success=1&code=' . urlencode($orderCode));
    exit;

} catch (PDOException $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $errorMsg = addslashes($e->getMessage());
    echo "<script>alert('Lỗi CSDL: " . $errorMsg . "'); history.back();</script>";
    exit;
}