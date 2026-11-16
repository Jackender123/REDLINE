<?php
declare(strict_types=1);

// Both the page and its polling API use exactly the same ownership boundary.
function rl_tracking_orders(PDO $pdo, string $keyword, ?array $customer, ?array $staff): array
{
    if ($keyword === '' || strlen($keyword) > 120) return [];
    $params = [$keyword, $keyword, $keyword];
    if ($customer) {
        $scope = 'COALESCE(ro.customer_id, v.customer_id) = ?';
        $params[] = (int)$customer['id'];
    } elseif (in_array($staff['role_name'] ?? '', ['Admin', 'Receptionist'], true)) {
        $scope = '1 = 1';
    } elseif (($staff['role_name'] ?? '') === 'Technician') {
        $scope = 'ro.assigned_to = ?';
        $params[] = (int)$staff['id'];
    } else {
        return [];
    }
    $stmt = $pdo->prepare("SELECT ro.id, ro.order_code, ro.status, ro.created_at,
        ro.updated_at, v.license_plate, v.model AS vehicle_model, c.full_name AS customer_name
        FROM repair_orders ro LEFT JOIN vehicles v ON v.id = ro.vehicle_id
        LEFT JOIN users c ON c.id = COALESCE(ro.customer_id, v.customer_id)
        WHERE (ro.order_code = ? OR UPPER(v.license_plate) = UPPER(?) OR c.phone = ?)
          AND ($scope)
        ORDER BY ro.created_at DESC LIMIT 100");
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
