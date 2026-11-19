<?php
/**
 * Turn an online service request into the single repair-order workflow.
 *
 * Caller MUST hold a database transaction; the service-request row is locked
 * so repeated/concurrent conversions cannot make duplicate orders.
 * Does not approve a request, assign a technician, or confirm a visit time.
 * Run migrations/20261118_direct_booking_orders.sql before enabling this code.
 */
require_once __DIR__ . '/customer_vehicle_sync.php';

function rl_request_to_order(PDO $pdo, int $requestId): int
{
    if (!$pdo->inTransaction()) {
        throw new LogicException('Request conversion requires an active transaction.');
    }
    if ($requestId < 1) throw new DomainException('Invalid service request.');

    $stmt = $pdo->prepare('SELECT * FROM service_requests WHERE id = ? FOR UPDATE');
    $stmt->execute([$requestId]);
    $request = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$request) throw new DomainException('The appointment no longer exists.');

    $linked = $pdo->prepare('SELECT id FROM repair_orders WHERE service_request_id = ? LIMIT 1');
    $linked->execute([$requestId]);
    $existingOrderId = (int) $linked->fetchColumn();
    if ($existingOrderId > 0) return $existingOrderId;
    if (($request['status'] ?? '') === 'cancelled') {
        throw new DomainException('Cancelled requests cannot become repair orders.');
    }

    // Presence of the column marks that the required migration has been run.
    // This check runs before any user/vehicle is modified.
    $migrationCheck = $pdo->query("SHOW COLUMNS FROM repair_orders LIKE 'intake_mode'");
    if (!$migrationCheck->fetch(PDO::FETCH_ASSOC)) {
        throw new RuntimeException('Run database migration 20261118_direct_booking_orders.sql first.');
    }

    $plate = strtoupper(trim((string) ($request['license_plate'] ?? '')));
    $plateKey = rl_sync_plate($plate);
    if (strlen($plateKey) < 3 || strlen($plate) > 20) {
        throw new DomainException('The request has no valid vehicle plate. Update its details before conversion.');
    }
    $make = trim((string) ($request['make'] ?? ''));
    $model = trim((string) ($request['model'] ?? ''));
    if ($make === '' || $model === '') {
        $described = preg_replace('/\[[^\]]*\]/', '', trim((string)($request['vehicle'] ?? '')));
        $parts = preg_split('/\s+/', trim($described), 2);
        $make = $make ?: ($parts[0] ?? '');
        $model = $model ?: ($parts[1] ?? '');
    }
    if ($make === '' || $model === '' || mb_strlen($make) > 60 || mb_strlen($model) > 100) {
        throw new DomainException('Vehicle make/model is incomplete. Please correct this request before conversion.');
    }
    $userId = (int) ($request['user_id'] ?? 0);
    // Legacy requests can predate user_id, even though their cars are already
    // present in the customer's portal garage. Avoid creating a second owner.
    if ($userId === 0) {
        $matchingPortal = null;
        foreach ($pdo->query('SELECT id, user_id, plate FROM portal_vehicles')->fetchAll(PDO::FETCH_ASSOC) as $pv) {
            if (rl_sync_plate((string)$pv['plate']) !== $plateKey) continue;
            if ($matchingPortal !== null) throw new DomainException('Several portal garages share this plate. Manual reconciliation is required.');
            $matchingPortal = $pv;
        }
        if ($matchingPortal !== null) {
            $ownerQuery = $pdo->prepare('SELECT id, phone, email FROM users WHERE id = ? AND is_customer = 1 AND is_active = 1');
            $ownerQuery->execute([(int)$matchingPortal['user_id']]);
            $owner = $ownerQuery->fetch(PDO::FETCH_ASSOC);
            $requestPhone = rl_sync_phone((string)($request['phone'] ?? ''));
            $requestEmail = strtolower(trim((string)($request['email'] ?? '')));
            if (!$owner || strlen($requestPhone) < 8
                || $requestPhone !== rl_sync_phone((string)$owner['phone'])
                || ($requestEmail !== '' && $requestEmail !== strtolower(trim((string)$owner['email'])))) {
                throw new DomainException('Portal garage ownership does not match this legacy request. Reconcile the customer before conversion.');
            }
            $userId = (int)$owner['id'];
        }
    }

    // Match the plate exactly after normalization, avoiding accidental vehicle
    // creation with a colliding registration from another customer.
    $allVehicles = $pdo->query('SELECT id, customer_id, license_plate FROM vehicles')->fetchAll(PDO::FETCH_ASSOC);
    $matchingVehicle = null;
    foreach ($allVehicles as $v) {
        if (rl_sync_plate((string) $v['license_plate']) !== $plateKey) continue;
        if ($matchingVehicle !== null) throw new DomainException('Several workshop vehicles share this plate. Resolve before conversion.');
        $matchingVehicle = $v;
    }

    if ($userId > 0) {
        $customerId = rl_sync_customer($pdo, $userId);
    } elseif ($matchingVehicle !== null) {
        // An anonymous legacy request may refer to an existing vehicle ONLY
        // when its phone matches the recorded owner. Never attach by plate alone.
        $owner = $pdo->prepare('SELECT id, phone FROM users WHERE id = ? AND is_customer = 1 AND is_active = 1 FOR UPDATE');
        $owner->execute([(int) $matchingVehicle['customer_id']]);
        $candidate = $owner->fetch(PDO::FETCH_ASSOC);
        $requestPhone = rl_sync_phone((string) ($request['phone'] ?? ''));
        if (!$candidate || strlen($requestPhone) < 8 || $requestPhone !== rl_sync_phone((string) $candidate['phone'])) {
            throw new DomainException('Existing vehicle owner does not match this legacy request. Check the customer manually.');
        }
        $customerId = (int) $candidate['id'];
    } else {
        // Preserve anonymous historical requests as separate, non-login customer
        // profiles. Do not merge accounts based only on matching phone numbers.
        $fullName = trim((string) ($request['full_name'] ?? ''));
        $phone = trim((string) ($request['phone'] ?? ''));
        if ($fullName === '' || strlen(rl_sync_phone($phone)) < 8) {
            throw new DomainException('Legacy request lacks a valid name or phone number.');
        }
        $role = $pdo->query("SELECT id FROM roles WHERE name IN ('Customer', 'Customers') ORDER BY (name = 'Customer') DESC LIMIT 1")->fetchColumn();
        if (!$role) throw new RuntimeException('Customer role is missing from the database.');
        $contact = (string) ($request['contact_method'] ?? 'phone');
        if (!in_array($contact, ['phone', 'zalo', 'email'], true)) $contact = 'phone';
        $insertGuest = $pdo->prepare('INSERT INTO users (role_id, full_name, phone, email, zalo, contact_method, is_customer, is_active) VALUES (?, ?, ?, NULL, ?, ?, 1, 1)');
        $insertGuest->execute([(int)$role, $fullName, $phone, $request['zalo'] ?: null, $contact]);
        $customerId = (int)$pdo->lastInsertId();
        // Do not attach an unknown email to a new account with no password.
    }

    if ($matchingVehicle && (int)$matchingVehicle['customer_id'] !== $customerId) {
        throw new DomainException('This plate is already registered to another customer. The request was not converted.');
    }

    // Prefer the customer's saved garage vehicle when one is linked to this
    // booking, then fall back to an exact same-owner plate match.
    $portalVehicleId = 0;
    if ($userId > 0) {
        $pref = $pdo->prepare('SELECT portal_vehicle_id FROM booking_preferences WHERE service_request_id = ? LIMIT 1');
        $pref->execute([$requestId]);
        $portalVehicleId = (int)$pref->fetchColumn();
        if (!$portalVehicleId) {
            $pvs = $pdo->prepare('SELECT id, plate FROM portal_vehicles WHERE user_id = ?');
            $pvs->execute([$userId]);
            foreach ($pvs->fetchAll(PDO::FETCH_ASSOC) as $pv) {
                if (rl_sync_plate((string)$pv['plate']) !== $plateKey) continue;
                if ($portalVehicleId) throw new DomainException('Multiple saved vehicles share this plate.');
                $portalVehicleId = (int)$pv['id'];
            }
        }
    }
    if ($portalVehicleId > 0) {
        // Verify the portal plate equals the submitted one: no cross-vehicle link.
        $pvStmt = $pdo->prepare('SELECT plate FROM portal_vehicles WHERE id = ? AND user_id = ?');
        $pvStmt->execute([$portalVehicleId, $userId]);
        $portalPlate = $pvStmt->fetchColumn();
        if ($portalPlate === false || rl_sync_plate((string)$portalPlate) !== $plateKey) {
            throw new DomainException('Saved garage vehicle no longer matches the booking.');
        }
        $vehicleId = rl_sync_vehicle($pdo, $portalVehicleId, $userId, $customerId);
    } elseif ($matchingVehicle !== null) {
        $vehicleId = (int)$matchingVehicle['id'];
    } else {
        $vin = trim((string) ($request['vin_number'] ?? '')) ?: null;
        $year = !empty($request['year']) ? (int)$request['year'] : null;
        $type = 'gasoline';
        $mileage = !empty($request['mileage']) ? (int)$request['mileage'] : 0;
        $vehicleStmt = $pdo->prepare('INSERT INTO vehicles (customer_id, license_plate, brand, model, vin, vin_number, year, color, engine_type, odometer) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $vehicleStmt->execute([$customerId, $plate, $make, $model, $vin, $vin, $year, $request['color'] ?: null, $type, $mileage]);
        $vehicleId = (int)$pdo->lastInsertId();
    }

    $serviceType = trim((string) ($request['service_type'] ?: $request['service'] ?: 'General Service'));
    $serviceType = mb_substr($serviceType, 0, 50);
    $description = trim((string)($request['service'] ?: $serviceType));
    $message = trim((string) ($request['message'] ?? ''));
    // Order code is a random public reference; source request ID remains unique.
    $orderCode = 'RO-' . strtoupper(bin2hex(random_bytes(6)));
    $orderStmt = $pdo->prepare('INSERT INTO repair_orders
        (order_code, vehicle_id, customer_id, service_request_id, customer_request,
         symptoms, service_type, status, intake_mode, km_in, odometer, fuel_level, note)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $mileage = !empty($request['mileage']) ? (int) $request['mileage'] : null;
    $orderStmt->execute([
        $orderCode, $vehicleId, $customerId, $requestId,
        $description, $message !== '' ? $message : null, $serviceType,
        'received', 'online_booking', $mileage, $mileage,
        $request['fuel_level'] ?: null,
        'Automatically linked to online service request #' . $requestId . '. Appointment time and vehicle arrival are NOT confirmed.'
    ]);
    $orderId = (int)$pdo->lastInsertId();
    $pdo->prepare("UPDATE service_requests SET status = 'processed' WHERE id = ? AND status <> 'cancelled'")
        ->execute([$requestId]);
    return $orderId;
}
