<?php
/** Save a customer dashboard booking to the workshop's service-request inbox. */
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/service_catalog.php';

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
    $stmt = db()->prepare('SELECT * FROM portal_vehicles WHERE id = ? AND user_id = ?');
    $stmt->execute([$vehicleId, $user['id']]);
    $vehicle = $stmt->fetch();
    if (!$vehicle) {
        booking_response(422, ['ok' => false, 'message' => 'Choose a vehicle from your garage.']);
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$user['id']]);
    $profile = $stmt->fetch();
    if (trim((string) ($profile['full_name'] ?? '')) === '' || trim((string) ($profile['phone'] ?? '')) === '') {
        booking_response(422, ['ok' => false, 'message' => 'Save your name and phone number in My Profile before booking.']);
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
    $columns = db()->query('SHOW COLUMNS FROM service_requests')->fetchAll(PDO::FETCH_COLUMN);
    $data += array_intersect_key($optional, array_flip($columns));
    $names = implode(', ', array_keys($data));
    $params = implode(', ', array_fill(0, count($data), '?'));
    db()->prepare("INSERT INTO service_requests ($names) VALUES ($params)")->execute(array_values($data));
    booking_response(201, ['ok' => true, 'id' => (int) db()->lastInsertId()]);
} catch (Throwable $ex) {
    error_log('Dashboard booking failed: ' . $ex->getMessage());
    booking_response(500, ['ok' => false, 'message' => 'We could not save your request. Please try again.']);
}
