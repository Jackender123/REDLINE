<?php
declare(strict_types=1);

require_once __DIR__ . '/service_catalog.php';

/** Read customer-owned fabrication requests and the actual linked workshop state. */
function rl_customer_fabrication(PDO $pdo, int $userId): array
{
    $result = ['items' => [], 'counts' => ['total' => 0, 'active' => 0, 'completed' => 0, 'cancelled' => 0], 'featured' => null];
    if ($userId < 1) return $result;

    $services = [];
    foreach (rl_service_page_options('fabrication') as $value => $label) {
        $services['FABRICATION: ' . $value] = $label;
        // Generic "Other" requests cannot be attributed to fabrication without their prefix.
        if ($value !== 'OTHER / NOT SURE') $services[$value] = $label;
    }
    $params = ['user_id' => $userId];
    $predicates = [];
    foreach (['service_type', 'service'] as $column) {
        $placeholders = [];
        foreach (array_keys($services) as $index => $value) {
            $key = $column . '_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = $value;
        }
        $predicates[] = 'UPPER(TRIM(sr.' . $column . ')) IN (' . implode(', ', $placeholders) . ')';
    }
    $stmt = $pdo->prepare('SELECT sr.id, sr.service_type, sr.service, sr.vehicle, sr.license_plate,
            sr.status AS request_status, sr.created_at,
            ro.id AS order_id, ro.order_code, ro.status AS order_status
        FROM service_requests sr
        LEFT JOIN repair_orders ro ON ro.service_request_id = sr.id
        WHERE sr.user_id = :user_id AND (' . implode(' OR ', $predicates) . ')
        ORDER BY sr.created_at DESC, sr.id DESC');
    $stmt->execute($params);
    $progress = [
        'received' => [1, 'Vehicle received', 'cyan', 'The workshop has received your vehicle. Check your repair order for updates.'],
        'diagnosing' => [2, 'Assessment in progress', 'cyan', 'The workshop is assessing your vehicle and the requested work.'],
        'repairing' => [3, 'Work in progress', 'amber', 'The linked workshop order records work in progress.'],
        'waiting_parts' => [3, 'Waiting for parts', 'amber', 'Work is waiting for the parts or materials recorded by the workshop.'],
        'inspecting' => [4, 'Final inspection', 'cyan', 'The workshop is inspecting the work before completion.'],
        'completed' => [5, 'Completed', 'green', 'The workshop has marked the linked repair order complete. Contact the team about collection.'],
        'delivered' => [5, 'Delivered', 'green', 'The linked workshop order records the vehicle as delivered.'],
    ];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $serviceValue = strtoupper(trim((string) $row['service_type']));
        if (!isset($services[$serviceValue])) $serviceValue = strtoupper(trim((string) $row['service']));
        $requestStatus = (string) $row['request_status'];
        $orderId = $row['order_id'] === null ? null : (int) $row['order_id'];
        $orderStatus = (string) ($row['order_status'] ?? '');
        if ($requestStatus === 'cancelled') {
            $group = 'cancelled';
            [$stage, $label, $tone, $note] = [0, 'Cancelled', 'red', 'This fabrication request has been cancelled. Contact the workshop if you want to discuss a new request.'];
        } elseif ($orderId !== null) {
            $group = in_array($orderStatus, ['completed', 'delivered'], true) ? 'completed' : 'active';
            [$stage, $label, $tone, $note] = $progress[$orderStatus]
                ?? [0, 'Workshop update pending', 'cyan', 'A workshop order is linked. Open repair progress for the latest information.'];
        } else {
            $group = 'active';
            [$stage, $label, $tone, $note] = $requestStatus === 'processed'
                ? [0, 'Under review', 'cyan', 'The workshop is reviewing your request. Scope, price and timing have not been confirmed here.']
                : [0, 'Request received', 'amber', 'Your request is awaiting workshop review. The team will contact you to discuss scope, price and timing.'];
        }
        $item = [
            'id' => (int) $row['id'],
            'reference' => '#REQ-' . (int) $row['id'],
            'service' => $services[$serviceValue],
            'service_value' => str_starts_with($serviceValue, 'FABRICATION: ') ? $serviceValue : 'FABRICATION: ' . $serviceValue,
            'vehicle' => trim((string) $row['vehicle']) ?: 'Vehicle details pending',
            'plate' => strtoupper(trim((string) $row['license_plate'])),
            'created_at' => (string) $row['created_at'],
            'request_status' => $requestStatus,
            'order_id' => $orderId,
            'order_code' => (string) ($row['order_code'] ?? ''),
            'order_status' => $orderStatus,
            'status_label' => $label,
            'tone' => $tone,
            'note' => $note,
            'stage' => $stage,
            'group' => $group,
        ];
        $result['items'][] = $item;
        ++$result['counts']['total'];
        ++$result['counts'][$group];
        if ($result['featured'] === null && $group === 'active') $result['featured'] = $item;
    }
    $result['featured'] ??= $result['items'][0] ?? null;
    return $result;
}
