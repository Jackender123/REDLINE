<?php
/** Read customer-owned garage and workshop history without changing vehicle links. */
function rl_customer_service_history(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT id, plate, workshop_vehicle_id FROM portal_vehicles WHERE user_id = ? ORDER BY id');
    $stmt->execute([$userId]);
    $garage = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$garage) return [];

    $history = [];
    $garageByPlate = [];
    foreach ($garage as $vehicle) {
        $history[(int) $vehicle['id']] = [];
        $plate = rl_sync_plate((string) $vehicle['plate']);
        if ($plate !== '') $garageByPlate[$plate][] = (int) $vehicle['id'];
    }

    $stmt = $pdo->prepare('SELECT s.id, s.vehicle_id, NULL AS order_code, s.title, s.status, s.estimate, s.notes, s.updated_at
        FROM service_orders s JOIN portal_vehicles v ON v.id = s.vehicle_id WHERE v.user_id = ?');
    $stmt->execute([$userId]);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $history[(int) $row['vehicle_id']][] = $row;
    }

    $stmt = $pdo->prepare('SELECT id, license_plate FROM vehicles WHERE customer_id = ? ORDER BY id');
    $stmt->execute([$userId]);
    $workshop = $stmt->fetchAll(PDO::FETCH_UNIQUE | PDO::FETCH_ASSOC);
    $workshopByPlate = [];
    foreach ($workshop as $id => $vehicle) {
        $plate = rl_sync_plate((string) $vehicle['license_plate']);
        if ($plate !== '') $workshopByPlate[$plate][] = (int) $id;
    }

    $garageByWorkshop = [];
    if ($workshop) {
        $in = implode(',', array_fill(0, count($workshop), '?'));
        // A legacy plate match must never borrow a workshop link saved on another garage row.
        $stmt = $pdo->prepare("SELECT id, workshop_vehicle_id FROM portal_vehicles WHERE workshop_vehicle_id IN ($in)");
        $stmt->execute(array_keys($workshop));
        $claimed = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $claim) {
            $claimed[(int) $claim['workshop_vehicle_id']][] = (int) $claim['id'];
        }
        foreach ($garage as $vehicle) {
            $garageId = (int) $vehicle['id'];
            $workshopId = (int) $vehicle['workshop_vehicle_id'];
            if ($workshopId) {
                // Explicit links survive plate edits, but never cross workshop customer ownership.
                if (isset($workshop[$workshopId]) && ($claimed[$workshopId] ?? []) === [$garageId]) {
                    $garageByWorkshop[$workshopId] = $garageId;
                }
                continue;
            }
            $plate = rl_sync_plate((string) $vehicle['plate']);
            if ($plate === '' || count($garageByPlate[$plate] ?? []) !== 1
                || count($workshopByPlate[$plate] ?? []) !== 1) continue;
            $workshopId = $workshopByPlate[$plate][0];
            if (!isset($claimed[$workshopId])) $garageByWorkshop[$workshopId] = $garageId;
        }
    }

    if ($garageByWorkshop) {
        $in = implode(',', array_fill(0, count($garageByWorkshop), '?'));
        $stmt = $pdo->prepare("SELECT r.id, r.vehicle_id, r.order_code, r.service_type AS title, r.status,
                r.estimated_cost AS estimate, r.symptoms AS notes, r.updated_at
            FROM repair_orders r JOIN vehicles v ON v.id = r.vehicle_id
            WHERE r.vehicle_id IN ($in) AND COALESCE(r.customer_id, v.customer_id) = ?");
        $stmt->execute(array_merge(array_keys($garageByWorkshop), [$userId]));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $row['vehicle_id'] = $garageByWorkshop[(int) $row['vehicle_id']];
            $history[$row['vehicle_id']][] = $row;
        }
    }

    foreach ($history as &$rows) {
        usort($rows, static function (array $a, array $b): int {
            return strcmp((string) $b['updated_at'], (string) $a['updated_at'])
                ?: (($b['order_code'] !== null) <=> ($a['order_code'] !== null))
                ?: ((int) $b['id'] <=> (int) $a['id']);
        });
    }
    unset($rows);
    return $history;
}
