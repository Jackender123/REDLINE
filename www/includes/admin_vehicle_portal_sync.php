<?php
/** Make staff-registered vehicles visible in the customer's garage without duplicate IDs. */
function rl_link_workshop_vehicle(PDO $pdo, int $vehicleId): int
{
    $s = $pdo->prepare('SELECT v.*, ev.battery_capacity, ev.battery_soh FROM vehicles v LEFT JOIN vehicle_ev_details ev ON ev.vehicle_id = v.id WHERE v.id = ? FOR UPDATE');
    $s->execute([$vehicleId]);
    $vehicle = $s->fetch(PDO::FETCH_ASSOC);
    if (!$vehicle) throw new RuntimeException('Workshop vehicle not found.');
    $userId = (int)$vehicle['customer_id'];
    $s = $pdo->prepare('SELECT id FROM users WHERE id = ? AND is_customer = 1 AND is_active = 1');
    $s->execute([$userId]);
    if (!$s->fetchColumn()) throw new RuntimeException('Vehicle owner is not an active customer.');

    $s = $pdo->prepare('SELECT * FROM portal_vehicles WHERE workshop_vehicle_id = ? FOR UPDATE');
    $s->execute([$vehicleId]);
    $linked = $s->fetch(PDO::FETCH_ASSOC);
    if ($linked) {
        if ((int)$linked['user_id'] !== $userId) throw new RuntimeException('Vehicle belongs to another portal customer.');
        return (int)$linked['id']; // Preserve existing customer-managed details and photo.
    }

    // Existing portal record with the same plate and owner may already have a photo.
    // Normalize punctuation/case so 68A-123.45 and 68A12345 match.
    $normalized = static function (string $plate): string {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper($plate));
    };
    $s = $pdo->prepare('SELECT id, plate, workshop_vehicle_id FROM portal_vehicles WHERE user_id = ? FOR UPDATE');
    $s->execute([$userId]);
    $match = null;
    foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
        if ($normalized((string)$candidate['plate']) !== $normalized((string)$vehicle['license_plate'])) continue;
        if ($candidate['workshop_vehicle_id'] !== null && (int)$candidate['workshop_vehicle_id'] !== $vehicleId) {
            throw new RuntimeException('This plate is already linked to a different workshop vehicle.');
        }
        if ($match !== null) throw new RuntimeException('Multiple portal vehicles have this plate. Please resolve duplicates first.');
        $match = $candidate;
    }
    if ($match) {
        $pdo->prepare('UPDATE portal_vehicles SET workshop_vehicle_id = ?, updated_at = NOW() WHERE id = ? AND user_id = ? AND workshop_vehicle_id IS NULL')
            ->execute([$vehicleId, $match['id'], $userId]);
        return (int)$match['id'];
    }

    $pdo->prepare('INSERT INTO portal_vehicles
        (user_id, make, model, year, plate, vin, mileage, color, engine_type, battery_capacity, battery_soh, workshop_vehicle_id, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())')
        ->execute([$userId, $vehicle['brand'], $vehicle['model'] ?: '', $vehicle['year'],
            $vehicle['license_plate'], $vehicle['vin'] ?: ($vehicle['vin_number'] ?? null),
            $vehicle['odometer'], $vehicle['color'], $vehicle['engine_type'],
            $vehicle['battery_capacity'], $vehicle['battery_soh'], $vehicleId]);
    return (int)$pdo->lastInsertId();
}

/** Optional staff photo: same public/uploads/vehicles path as customer uploads. */
function rl_save_staff_vehicle_photo(array $upload): ?string
{
    $error = $upload['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($error === UPLOAD_ERR_NO_FILE) return null;
    if ($error !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'] ?? '')) throw new RuntimeException('Vehicle photo upload failed.');
    if (($upload['size'] ?? 0) > 5 * 1024 * 1024) throw new RuntimeException('Vehicle photo must not exceed 5 MB.');
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($upload['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if (!$ext || !@getimagesize($upload['tmp_name'])) throw new RuntimeException('Upload a valid JPG, PNG or WebP image.');
    $folder = dirname(__DIR__) . '/uploads/vehicles';
    if (!is_dir($folder) && !@mkdir($folder, 0755, true)) throw new RuntimeException('Vehicle photo folder is not writable.');
    $filename = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($upload['tmp_name'], $folder . '/' . $filename)) throw new RuntimeException('Unable to save vehicle photo.');
    return 'uploads/vehicles/' . $filename;
}
