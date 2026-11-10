<?php
/** Shared customer/vehicle data between the customer garage and workshop intake. */

function rl_sync_phone(string $phone): string
{
    return preg_replace('/\D/', '', $phone);
}

function rl_sync_plate(string $plate): string
{
    return preg_replace('/[^A-Z0-9]/', '', strtoupper($plate));
}

/** Follow the portal's existing additive schema upgrade convention. Run before transactions. */
function rl_sync_ensure_schema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) return;
    $columns = [
        'users' => [
            'zalo' => 'VARCHAR(30) NULL',
            'contact_method' => "ENUM('phone','zalo','email') NOT NULL DEFAULT 'phone'",
        ],
        'portal_vehicles' => [
            'workshop_vehicle_id' => 'INT UNSIGNED NULL UNIQUE',
            'engine_type' => "ENUM('gasoline','diesel','hybrid','ev') NOT NULL DEFAULT 'gasoline'",
            'battery_capacity' => 'DECIMAL(6,2) NULL',
            'battery_soh' => 'DECIMAL(5,2) NULL',
        ],
        // appointment_id refers to the legacy appointments table, not website bookings.
        'repair_orders' => ['service_request_id' => 'INT UNSIGNED NULL UNIQUE'],
    ];
    foreach ($columns as $table => $definitions) {
        $have = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
        foreach ($definitions as $column => $definition) {
            if (!isset($have[$column])) {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
            }
        }
    }
    // Both forms accept the same lengths; do not truncate customer-entered details.
    foreach ([
        'users' => ['full_name' => [150, 'NOT NULL'], 'phone' => [30, 'NULL'], 'email' => [190, 'NULL']],
        'vehicles' => ['license_plate' => [20, 'NOT NULL'], 'brand' => [60, 'NOT NULL']],
        'portal_vehicles' => ['vin' => [50, 'NULL']],
    ] as $table => $definitions) {
        $have = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
        foreach ($definitions as $column => [$length, $nullable]) {
            if (isset($have[$column]) && preg_match('/varchar\((\d+)\)/i', $have[$column]['Type'], $m)
                && (int) $m[1] < $length) {
                $pdo->exec("ALTER TABLE `$table` MODIFY `$column` VARCHAR($length) $nullable");
            }
        }
    }
    $ready = true;
}

/** The profile and workshop customer now use the same users primary key. */
function rl_sync_customer(PDO $pdo, int $userId, ?array $previous = null): int
{
    $s = $pdo->prepare('SELECT * FROM users WHERE id = ? AND is_customer = 1 AND is_active = 1 FOR UPDATE');
    $s->execute([$userId]);
    $user = $s->fetch(PDO::FETCH_ASSOC);
    if (!$user) throw new RuntimeException('Customer account not found.');
    if (trim((string) $user['phone']) === '') {
        throw new RuntimeException('Please add a phone number to the customer profile first.');
    }
    foreach ($pdo->query('SELECT id, phone FROM users WHERE is_customer = 1')->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
        if ((int) $candidate['id'] !== $userId && rl_sync_phone((string) $candidate['phone']) === rl_sync_phone((string) $user['phone'])) {
            throw new RuntimeException('These contact details are linked to another customer account.');
        }
    }
    return $userId;
}

/** Push a saved garage vehicle to its workshop record without changing either ID. */
function rl_sync_vehicle(PDO $pdo, int $portalVehicleId, int $userId, int $customerId): int
{
    $s = $pdo->prepare('SELECT * FROM portal_vehicles WHERE id = ? AND user_id = ? FOR UPDATE');
    $s->execute([$portalVehicleId, $userId]);
    $v = $s->fetch(PDO::FETCH_ASSOC);
    if (!$v) throw new RuntimeException('The saved vehicle does not belong to this customer.');
    $vehicleId = $v['workshop_vehicle_id'];
    // Check all plates, including when editing a linked vehicle's registration.
    foreach ($pdo->query('SELECT id, customer_id, license_plate FROM vehicles')->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
        if (rl_sync_plate((string) $candidate['license_plate']) !== rl_sync_plate((string) $v['plate'])) continue;
        if ((int) $candidate['customer_id'] !== $customerId || ($vehicleId && (int) $candidate['id'] !== (int) $vehicleId)) {
            throw new RuntimeException('This license plate is already registered to another vehicle or customer.');
        }
        $vehicleId = (int) $candidate['id'];
    }
    if ($vehicleId) {
        $s = $pdo->prepare('SELECT * FROM vehicles WHERE id = ? FOR UPDATE');
        $s->execute([$vehicleId]);
        $workshop = $s->fetch(PDO::FETCH_ASSOC);
        if (!$workshop || (int) $workshop['customer_id'] !== $customerId) throw new RuntimeException('The workshop vehicle belongs to another customer.');
        // On the first link, retain optional details already recorded at the workshop.
        if (!$v['workshop_vehicle_id']) {
            $v['vin'] = $v['vin'] ?: ($workshop['vin'] ?: $workshop['vin_number']);
            $v['year'] = $v['year'] ?? $workshop['year'];
            $v['color'] = $v['color'] ?: $workshop['color'];
            $v['mileage'] = $v['mileage'] ?? $workshop['odometer'];
            if ($v['engine_type'] === 'gasoline' && in_array($workshop['engine_type'], ['diesel', 'hybrid', 'ev'], true)) {
                $v['engine_type'] = $workshop['engine_type'];
            }
            $s = $pdo->prepare('SELECT battery_capacity, battery_soh FROM vehicle_ev_details WHERE vehicle_id = ?');
            $s->execute([$vehicleId]);
            $battery = $s->fetch(PDO::FETCH_ASSOC) ?: [];
            $v['battery_capacity'] = $v['battery_capacity'] ?? ($battery['battery_capacity'] ?? null);
            $v['battery_soh'] = $v['battery_soh'] ?? ($battery['battery_soh'] ?? null);
            $pdo->prepare('UPDATE portal_vehicles SET vin = ?, year = ?, color = ?, mileage = ?, engine_type = ?, battery_capacity = ?, battery_soh = ? WHERE id = ? AND user_id = ?')
                ->execute([$v['vin'], $v['year'], $v['color'], $v['mileage'], $v['engine_type'], $v['battery_capacity'], $v['battery_soh'], $portalVehicleId, $userId]);
        }
    }
    $values = [$customerId, $v['plate'], $v['make'], $v['model'], $v['year'], $v['color'], $v['vin'], $v['vin'], $v['engine_type'], $v['engine_type'] === 'ev' ? 1 : 0];
    if ($vehicleId) {
        $pdo->prepare('UPDATE vehicles SET customer_id = ?, license_plate = ?, brand = ?, model = ?, year = ?, color = ?, vin = ?, vin_number = ?, engine_type = ?, is_ev = ?, odometer = COALESCE(?, odometer) WHERE id = ?')
            ->execute(array_merge($values, [$v['mileage'], $vehicleId]));
    } else {
        $pdo->prepare('INSERT INTO vehicles (customer_id, license_plate, brand, model, year, color, vin, vin_number, engine_type, is_ev, odometer) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(array_merge($values, [$v['mileage'] ?? 0]));
        $vehicleId = (int) $pdo->lastInsertId();
    }
    $pdo->prepare('UPDATE portal_vehicles SET workshop_vehicle_id = ? WHERE id = ? AND user_id = ?')->execute([$vehicleId, $portalVehicleId, $userId]);
    rl_sync_battery($pdo, (int) $vehicleId, $v['engine_type'], $v['battery_capacity'], $v['battery_soh']);
    return (int) $vehicleId;
}

function rl_sync_battery(PDO $pdo, int $vehicleId, string $engineType, $capacity, $soh): void
{
    if (in_array($engineType, ['ev', 'hybrid'], true)) {
        $pdo->prepare('INSERT INTO vehicle_ev_details (vehicle_id, battery_capacity, battery_soh) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE battery_capacity = VALUES(battery_capacity), battery_soh = VALUES(battery_soh)')
            ->execute([$vehicleId, $capacity === '' ? null : $capacity, $soh === '' ? null : $soh]);
    } else {
        $pdo->prepare('DELETE FROM vehicle_ev_details WHERE vehicle_id = ?')->execute([$vehicleId]);
    }
}
