<?php
declare(strict_types=1);

/** Read a customer's booking requests without treating preferred times as confirmed visits. */
function rl_customer_appointments(PDO $pdo, int $userId, ?DateTimeImmutable $now = null): array
{
    $zone = new DateTimeZone('Asia/Bangkok');
    $now = $now ? $now->setTimezone($zone) : new DateTimeImmutable('now', $zone);
    $result = ['items' => [], 'counts' => ['pending' => 0, 'upcoming' => 0, 'past' => 0, 'cancelled' => 0], 'pending_count' => 0, 'next' => null];
    if ($userId < 1) return $result;

    $stmt = $pdo->prepare('SELECT sr.id, sr.service_type, sr.service, sr.vehicle, sr.license_plate,
            sr.message, sr.status AS request_status, sr.created_at,
            ro.id AS order_id, ro.order_code, ro.status AS order_status,
            ro.intake_mode, ro.assigned_to,
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
        $cancelled = $requestStatus === 'cancelled' || $orderStatus === 'cancelled';
        $terminal = in_array($orderStatus, ['completed', 'delivered'], true);
        // Online bookings are converted to processed service requests immediately.
        // Approval is determined by the linked order's assigned_to, NOT sr.status.
        $awaitingApproval = !$cancelled && !$terminal && (
            ($orderId !== null && $orderStatus === 'received'
                && ($row['intake_mode'] ?? '') === 'online_booking' && empty($row['assigned_to']))
            || ($orderId === null && in_array($requestStatus, ['pending', 'processed'], true))
        );
        // Keep an unapproved booking in Pending even after its proposed time passes.
        $group = $cancelled ? 'cancelled' : ($awaitingApproval ? 'pending'
            : (($terminal || ($requestedAt && $requestedAt < $now)) ? 'past' : 'upcoming'));
        if ($cancelled) {
            [$label, $tone, $note] = ['Cancelled', 'red', 'This request has been cancelled. You can submit a new booking request when you are ready.'];
        } elseif ($orderId !== null) {
            if ($orderStatus === 'received' && ($row['intake_mode'] ?? '') === 'online_booking') {
                [$label, $tone, $note] = [
                    empty($row['assigned_to']) ? 'Awaiting admin approval' : 'Technician assigned',
                    'amber',
                    'Your request has a workshop order. The appointment time and physical vehicle arrival have not been confirmed.'
                ];
            } else {
            [$label, $tone, $note] = $progress[$orderStatus] ?? ['In workshop', 'cyan', 'A repair order is linked to this request. Check repair progress for the latest update.'];
            }
        } else {
            $label = 'Awaiting admin review';
            $tone = 'amber';
            $note = $requestedAt && $requestedAt < $now
                ? 'Your preferred date has passed, but this request still awaits workshop review. Please contact the workshop for a new time.'
                : ($requestedAt
                    ? 'Your preferred date and time are awaiting workshop review. Do not arrive until your visit is confirmed.'
                    : 'Your request is awaiting workshop review. The workshop will contact you to arrange a date.');
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
            'intake_mode' => (string) ($row['intake_mode'] ?? ''),
            'preferred_technician_name' => (string)($row['preferred_technician_name'] ?? ''),
            'assigned_technician_name' => (string)($row['assigned_technician_name'] ?? ''),
            'note' => $note,
        ];
        $result['items'][] = $item;
        ++$result['counts'][$group];
        if ($group === 'pending') ++$result['pending_count'];
    }

    $createdTime = static function (array $item) use ($zone): int {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $item['created_at'], $zone);
        return $date && $date->format('Y-m-d H:i:s') === $item['created_at'] ? $date->getTimestamp() : 0;
    };
    $groups = ['pending' => 0, 'upcoming' => 1, 'past' => 2, 'cancelled' => 3];
    usort($result['items'], static function (array $a, array $b) use ($groups, $createdTime): int {
        if ($a['group'] !== $b['group']) return $groups[$a['group']] <=> $groups[$b['group']];
        if (in_array($a['group'], ['pending', 'upcoming'], true)) {
            return (($a['requested_at']?->getTimestamp() ?? PHP_INT_MAX) <=> ($b['requested_at']?->getTimestamp() ?? PHP_INT_MAX))
                ?: ($createdTime($b) <=> $createdTime($a)) ?: ($b['id'] <=> $a['id']);
        }
        return (($b['requested_at']?->getTimestamp() ?? $createdTime($b)) <=> ($a['requested_at']?->getTimestamp() ?? $createdTime($a)))
            ?: ($b['id'] <=> $a['id']);
    });
    // Prefer the nearest proposed FUTURE visit. If none exists, retain the newest
    // unanswered request in the feature card even when its date is missing or
    // elapsed. Otherwise customers with a valid pending booking see an empty card.
    // Neither case means that the workshop has confirmed a visit.
    $pendingFallback = null;
    foreach ($result['items'] as $item) {
        if ($item['group'] === 'pending'
            && ($pendingFallback === null || $createdTime($item) > $createdTime($pendingFallback)
                || ($createdTime($item) === $createdTime($pendingFallback) && $item['id'] > $pendingFallback['id']))) {
            $pendingFallback = $item;
        }
        if (!in_array($item['group'], ['pending', 'upcoming'], true)
            || !$item['requested_at'] || $item['requested_at'] < $now) continue;
        if ($result['next'] === null || $item['requested_at'] < $result['next']['requested_at']) {
            $result['next'] = $item;
        }
    }
    if ($result['next'] === null) $result['next'] = $pendingFallback;
    if ($result['next'] !== null) {
        $result['next']['preferred_elapsed'] = $result['next']['requested_at'] !== null
            && $result['next']['requested_at'] < $now;
    }
    return $result;
}
