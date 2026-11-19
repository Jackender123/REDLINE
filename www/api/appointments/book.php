<?php
/** Save a customer dashboard booking to the workshop's service-request inbox. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/service_catalog.php';
require_once __DIR__ . '/../../includes/booking_order_sync.php';

header('Content-Type: application/json; charset=utf-8');

function booking_response(int $status, array $body): void
{
    http_response_code($status);
    echo json_encode($body);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    booking_response(405, ['ok' => false, 'message' => 'Use POST to submit a booking.']);
}

try {
    $pdo = db();
    $user = current_user();
    if (!$user) {
        booking_response(401, ['ok' => false, 'message' => 'Please sign in again before booking.']);
    }
    // The dashboard uses its own CSRF token for all of its forms.
    $csrf = $_POST['csrf'] ?? null;
    if (!is_string($csrf) || empty($_SESSION['_csrf']) || !hash_equals($_SESSION['_csrf'], $csrf)) {
        booking_response(403, ['ok' => false, 'message' => 'Your session expired. Refresh the dashboard and try again.']);
    }

    $service = $_POST['service'] ?? '';
    $date = $_POST['date'] ?? '';
    $time = $_POST['time'] ?? '';
    $vehicleId = filter_var($_POST['vehicle_id'] ?? null, FILTER_VALIDATE_INT);
    $preferredRaw = trim((string)($_POST['preferred_technician_id'] ?? ''));
    $preferredTechnicianId = null;
    if ($preferredRaw !== '') {
        $preferredTechnicianId = filter_var($preferredRaw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$preferredTechnicianId) {
            booking_response(422, ['ok' => false, 'message' => 'Choose an available technician or select No preference.']);
        }
        $checkTechnician = $pdo->prepare("SELECT u.id FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND u.is_active = 1 AND r.name = 'Technician' LIMIT 1");
        $checkTechnician->execute([$preferredTechnicianId]);
        if (!$checkTechnician->fetchColumn()) {
            booking_response(422, ['ok' => false, 'message' => 'This technician is no longer available. Please select another or choose No preference.']);
        }
    }
    $services = array_merge(array_keys(rl_service_catalog()), [
        'Scheduled Maintenance', 'Inspection & Diagnostics', 'Tires & Suspension', 'Electrical & Climate Control',
    ]);
    $slots = ['09:00', '10:30', '14:30', '16:00'];
    $day = is_string($date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
    if (!$vehicleId || $vehicleId < 1 || !in_array($service, $services, true)
        || !$day || $day->format('Y-m-d') !== $date || $date < date('Y-m-d')
        || !in_array($time, $slots, true)) {
        booking_response(422, ['ok' => false, 'message' => 'Choose a vehicle, service, and valid appointment date and time.']);
    }

    // Resolve ownership and contact details on the server; ignore posted identities.
    $stmt = $pdo->prepare('SELECT * FROM portal_vehicles WHERE id = ? AND user_id = ?');
    $stmt->execute([$vehicleId, $user['id']]);
    $vehicle = $stmt->fetch();
    if (!$vehicle) {
        booking_response(422, ['ok' => false, 'message' => 'Choose a vehicle from your garage.']);
    }
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    $profile = $stmt->fetch();
    if (trim((string) ($profile['full_name'] ?? '')) === '' || trim((string) ($profile['phone'] ?? '')) === '') {
        booking_response(422, ['ok' => false, 'message' => 'Save your name and phone number in My Profile before booking.']);
    }

    // Keep booking requirements consistent with the staff intake API. A missing or
    // malformed phone would otherwise create an appointment that staff cannot receive.
    $bookingPhone = trim((string) ($profile['phone'] ?? ''));
    $bookingPhoneDigits = preg_replace('/\D/', '', $bookingPhone);
    if ($bookingPhone === '' || strlen($bookingPhone) > 30
        || strlen($bookingPhoneDigits) < 8 || strlen($bookingPhoneDigits) > 15) {
        booking_response(422, [
            'ok' => false,
            'message' => 'Update your phone number in My Profile (8–15 digits) before booking.'
        ]);
    }

    // Keep the preferred schedule in the message so existing staff intake sees it
    // without requiring a schema migration or confusing it with the submission date.
    $data = [
        'user_id' => $user['id'],
        'full_name' => mb_substr($profile['full_name'], 0, 120),
        'phone' => $profile['phone'],
        'license_plate' => $vehicle['plate'],
        'vehicle' => trim($vehicle['make'] . ' ' . $vehicle['model'] . ' ' . ($vehicle['year'] ?? '')),
        'vin_number' => $vehicle['vin'] ?? null,
        'service_type' => $service,
        'service' => $service,
        'message' => 'Preferred appointment: ' . $date . ' at ' . $time . '. Awaiting workshop confirmation.',
        'ip_address' => client_ip(),
        'status' => 'pending',
    ];
    // Older installations may not have the optional website booking fields yet.
    $optional = [
        'email' => $profile['email'], 'zalo' => $profile['zalo'] ?? null,
        'contact_method' => $profile['contact_method'] ?? 'phone',
        'make' => $vehicle['make'], 'model' => $vehicle['model'], 'year' => $vehicle['year'] ?? null,
        'mileage' => $vehicle['mileage'] ?? null, 'color' => $vehicle['color'] ?? null,
        'photo_path' => $vehicle['photo'] ?? null,
    ];
    // Read the columns from the SAME connection used to validate the technician
    // and insert the booking. Fail closed if this connection has a stale schema.
    $databaseName = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    if ($databaseName === '') {
        throw new RuntimeException('Booking database connection has no selected schema.');
    }
    $columnStatement = $pdo->prepare(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS '
        . 'WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?'
    );
    $columnStatement->execute([$databaseName, 'service_requests']);
    $columns = $columnStatement->fetchAll(PDO::FETCH_COLUMN, 0);

    if (!$columns) {
        error_log('Booking schema check failed: service_requests not visible in selected database ' . $databaseName);
        booking_response(503, ['ok' => false,
            'message' => 'Booking database is not configured correctly. Please contact the workshop.']);
    }
    if ($preferredTechnicianId !== null && !in_array('preferred_technician_id', $columns, true)) {
        error_log('Booking schema mismatch: service_requests.preferred_technician_id is absent in database '
            . $databaseName . ' (MySQL port ' . $pdo->query('SELECT @@port')->fetchColumn() . ')');
        booking_response(503, ['ok' => false,
            'message' => 'Technician selection is not configured in the booking database. Please contact the workshop.']);
    }

    $data += array_intersect_key($optional, array_flip($columns));
    if (in_array('preferred_technician_id', $columns, true)) {
        $data['preferred_technician_id'] = $preferredTechnicianId;
    }
    // Booking time is a proposal, not a confirmed slot. Prevent duplicate pending
    // proposals for the same customer + vehicle + date/time across concurrent POSTs.
    $selectedAt = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time);
    if (!$selectedAt || $selectedAt < new DateTimeImmutable('now')) {
        booking_response(422, ['ok' => false, 'message' => 'Select a future appointment time.']);
    }
    $lockKey = 'rlbooking_' . substr(hash('sha256', $user['id'] . ':' . $vehicleId), 0, 48);
    $lockStmt = $pdo->prepare('SELECT GET_LOCK(?, 5)');
    $lockStmt->execute([$lockKey]);
    if ((int)$lockStmt->fetchColumn() !== 1) {
        booking_response(503, ['ok' => false, 'message' => 'Booking is busy. Try again shortly.']);
    }
    $isDuplicate = false;
    try {
        $pdo->beginTransaction();
        $dup = $pdo->prepare("SELECT sr.id FROM booking_preferences bp
            JOIN service_requests sr ON sr.id = bp.service_request_id
            LEFT JOIN repair_orders ro ON ro.service_request_id = sr.id
            WHERE sr.status <> 'cancelled' AND (ro.id IS NULL OR ro.status NOT IN ('cancelled','delivered'))
              AND bp.user_id = ? AND bp.portal_vehicle_id = ?
              AND bp.preferred_date = ? AND bp.preferred_time = ? LIMIT 1");
        $dup->execute([$user['id'], $vehicleId, $date, $time]);
        $isDuplicate = (bool)$dup->fetchColumn();
        if ($isDuplicate) $pdo->rollBack();
        if (!$isDuplicate) {
            $names = implode(', ', array_keys($data));
            $params = implode(', ', array_fill(0, count($data), '?'));
            $pdo->prepare("INSERT INTO service_requests ($names) VALUES ($params)")->execute(array_values($data));
            $requestId = (int)$pdo->lastInsertId();
            $pdo->prepare('INSERT INTO booking_preferences
            (service_request_id, user_id, portal_vehicle_id, preferred_date, preferred_time)
            VALUES (?, ?, ?, ?, ?)')->execute([$requestId, $user['id'], $vehicleId, $date, $time]);
            rl_request_to_order($pdo, $requestId);
            $pdo->commit();
        }
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$lockKey]);
    }
    if ($isDuplicate) booking_response(409, ['ok' => false,
        'message' => 'You already submitted a request for this vehicle and time.']);
    booking_response(201, ['ok' => true, 'id' => $requestId,
        'message' => 'Your request has been saved and linked to a workshop order. The workshop will confirm the schedule.']);
} catch (Throwable $ex) {
    error_log('Dashboard booking failed: ' . $ex->getMessage());
    booking_response(500, ['ok' => false, 'message' => 'We could not save your request. Please try again.']);
}
