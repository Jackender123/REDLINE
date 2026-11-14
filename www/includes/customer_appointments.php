<?php
declare(strict_types=1);

/** Read a customer's booking requests without treating preferred times as confirmed visits. */
function rl_customer_appointments(PDO $pdo, int $userId, ?DateTimeImmutable $now = null): array
{
    $zone = new DateTimeZone('Asia/Bangkok');
    $now = $now ? $now->setTimezone($zone) : new DateTimeImmutable('now', $zone);
    $result = ['items' => [], 'counts' => ['upcoming' => 0, 'past' => 0, 'cancelled' => 0], 'pending_count' => 0, 'next' => null];
    if ($userId < 1) return $result;

    $stmt = $pdo->prepare('SELECT sr.id, sr.service_type, sr.service, sr.vehicle, sr.license_plate,
            sr.message, sr.status AS request_status, sr.created_at,
            ro.id AS order_id, ro.order_code, ro.status AS order_status,
            preferred.full_name AS preferred_technician_name,
            assigned.full_name AS assigned_technician_name
        FROM service_requests sr
        LEFT JOIN repair_orders ro ON ro.service_request_id = sr.id
        LEFT JOIN users preferred ON preferred.id = sr.preferred_technician_id
        LEFT JOIN users assigned ON assigned.id = ro.assigned_to
        WHERE sr.user_id = :user_id');
    $stmt->execute(['user_id' => $userId]);
    $progress = [
        'received' => ['Vehicle received', 'cyan', 'The workshop has received your vehicle. Follow your repair order for progress.'],
        'diagnosing' => ['Diagnosis in progress', 'cyan', 'The workshop is diagnosing your vehicle. Follow your repair order for updates.'],
        'repairing' => ['Repair in progress', 'amber', 'Work is in progress on your vehicle. Follow your repair order for updates.'],
        'waiting_parts' => ['Waiting for parts', 'amber', 'The workshop is waiting for parts needed for your repair.'],
        'inspecting' => ['Final inspection', 'cyan', 'The workshop is inspecting your vehicle after the repair.'],
        'completed' => ['Service completed', 'green', 'The workshop has marked the linked repair order complete.'],
        'delivered' => ['Vehicle delivered', 'green', 'The linked repair order records your vehicle as delivered.'],
    ];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $requestedAt = null;
        if (preg_match('/Preferred appointment: (\d{4}-\d{2}-\d{2}) at (\d{2}:\d{2})\./', (string) $row['message'], $match)) {
            $value = $match[1] . ' ' . $match[2];
            $parsed = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $value, $zone);
            if ($parsed && $parsed->format('Y-m-d H:i') === $value) $requestedAt = $parsed;
        }
        $requestStatus = (string) $row['request_status'];
        $orderStatus = (string) ($row['order_status'] ?? '');
        $orderId = $row['order_id'] === null ? null : (int) $row['order_id'];
        $cancelled = $requestStatus === 'cancelled';
        $terminal = in_array($orderStatus, ['completed', 'delivered'], true);
        $group = $cancelled ? 'cancelled' : (($terminal || ($requestedAt && $requestedAt < $now)) ? 'past' : 'upcoming');
        if ($cancelled) {
            [$label, $tone, $note] = ['Cancelled', 'red', 'This request has been cancelled. You can submit a new booking request when you are ready.'];
        } elseif ($orderId !== null) {
            [$label, $tone, $note] = $progress[$orderStatus] ?? ['In workshop', 'cyan', 'A repair order is linked to this request. Check repair progress for the latest update.'];
        } else {
            $label = $requestStatus === 'processed' ? 'Under review' : 'Awaiting confirmation';
            $tone = $requestStatus === 'processed' ? 'cyan' : 'amber';
            $note = $group === 'past'
                ? 'This preferred date has passed. Contact the workshop for an update before planning a visit.'
                : ($requestedAt
                    ? 'Your preferred date and time are awaiting workshop confirmation. The workshop will contact you.'
                    : 'The workshop will contact you to arrange and confirm a date and time.');
        }
        $item = [
            'id' => (int) $row['id'],
            'reference' => '#REQ-' . (int) $row['id'],
            'service' => trim((string) $row['service_type']) ?: (trim((string) $row['service']) ?: 'Service request'),
            'vehicle' => trim((string) $row['vehicle']) ?: 'Vehicle details pending',
            'plate' => strtoupper(trim((string) $row['license_plate'])),
            'requested_at' => $requestedAt,
            'created_at' => (string) $row['created_at'],
            'group' => $group,
            'status_label' => $label,
            'tone' => $tone,
            'order_id' => $orderId,
            'order_code' => (string) ($row['order_code'] ?? ''),
            'request_status' => $requestStatus,
            'order_status' => $orderStatus,
            'preferred_technician_name' => (string)($row['preferred_technician_name'] ?? ''),
            'assigned_technician_name' => (string)($row['assigned_technician_name'] ?? ''),
            'note' => $note,
        ];
        $result['items'][] = $item;
        ++$result['counts'][$group];
        if (!$cancelled && $orderId === null && $requestStatus === 'pending') ++$result['pending_count'];
    }

    $createdTime = static function (array $item) use ($zone): int {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $item['created_at'], $zone);
        return $date && $date->format('Y-m-d H:i:s') === $item['created_at'] ? $date->getTimestamp() : 0;
    };
    $groups = ['upcoming' => 0, 'past' => 1, 'cancelled' => 2];
    usort($result['items'], static function (array $a, array $b) use ($groups, $createdTime): int {
        if ($a['group'] !== $b['group']) return $groups[$a['group']] <=> $groups[$b['group']];
        if ($a['group'] === 'upcoming') {
            return (($a['requested_at']?->getTimestamp() ?? PHP_INT_MAX) <=> ($b['requested_at']?->getTimestamp() ?? PHP_INT_MAX))
                ?: ($createdTime($b) <=> $createdTime($a)) ?: ($b['id'] <=> $a['id']);
        }
        return (($b['requested_at']?->getTimestamp() ?? $createdTime($b)) <=> ($a['requested_at']?->getTimestamp() ?? $createdTime($a)))
            ?: ($b['id'] <=> $a['id']);
    });
    foreach ($result['items'] as $item) {
        if ($item['group'] === 'upcoming' && $item['requested_at'] !== null) {
            $result['next'] = $item;
            break;
        }
    }
    return $result;
}
