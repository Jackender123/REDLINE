<?php
declare(strict_types=1);

/** Call mutation helpers only while holding the repair order's FOR UPDATE lock. */

/** A technician handover must not rewind the progress of the whole vehicle. */
function rl_status_after_technician_change(string $status): string
{
    // A genuinely completed order requires another final inspection if it is reopened
    // for additional commissioned work. Ordinary handovers preserve their exact stage.
    return $status === 'completed' ? 'inspecting' : $status;
}

/** Store separate completed/pending instructions in the existing session note column. */
function rl_format_technician_handover(string $completed, string $remaining): string
{
    $completed = trim($completed);
    $remaining = trim($remaining);
    if (mb_strlen($completed) < 5 || mb_strlen($remaining) < 5) {
        throw new DomainException('Describe both completed work and the work remaining (at least 5 characters each).');
    }
    $note = "COMPLETED WORK:\n" . $completed . "\n\nREMAINING WORK / NEXT ACTION:\n" . $remaining;
    if (mb_strlen($note) > 5000) {
        throw new DomainException('The combined handover summary must be 5000 characters or fewer.');
    }
    return $note;
}

function rl_work_session_current(PDO $pdo, int $orderId): ?array
{
    $stmt = $pdo->prepare('SELECT * FROM repair_order_work_sessions WHERE repair_order_id = ? ORDER BY id DESC LIMIT 1');
    $stmt->execute([$orderId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Latest finished handover still waiting for a replacement on an open order. */
function rl_pending_technician_handovers(PDO $pdo): array
{
    return $pdo->query("SELECT s.id, s.repair_order_id, s.technician_id, s.completed_at,
            s.completion_note, ro.order_code, ro.status, u.full_name AS technician_name,
            v.license_plate
        FROM repair_order_work_sessions s
        JOIN repair_orders ro ON ro.id = s.repair_order_id
        LEFT JOIN users u ON u.id = s.technician_id
        LEFT JOIN vehicles v ON v.id = ro.vehicle_id
        WHERE s.completion_type = 'handover' AND s.completed_at IS NOT NULL
          AND ro.status NOT IN ('completed', 'delivered', 'cancelled')
          AND ro.assigned_to = s.technician_id
          AND NOT EXISTS (SELECT 1 FROM repair_order_work_sessions newer
              WHERE newer.repair_order_id = s.repair_order_id AND newer.id > s.id)
        ORDER BY s.completed_at DESC, s.id DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
}

function rl_work_session_start(PDO $pdo, array $order, int $technicianId, ?int $assignedBy): int
{
    if (!$pdo->inTransaction() || empty($order['id']) || $technicianId <= 0) {
        throw new RuntimeException('Start a technician work session inside the locked repair order transaction.');
    }
    $current = rl_work_session_current($pdo, (int)$order['id']);
    if ($current && $current['completed_at'] === null) {
        throw new RuntimeException('Complete the current technician work session before assigning another technician.');
    }
    $stmt = $pdo->prepare('INSERT INTO repair_order_work_sessions (repair_order_id, technician_id, assigned_by) VALUES (?, ?, ?)');
    $stmt->execute([(int)$order['id'], $technicianId, $assignedBy]);
    return (int)$pdo->lastInsertId();
}

function rl_work_session_require_active(PDO $pdo, array $order, int $technicianId): int
{
    if (!$pdo->inTransaction()) throw new RuntimeException('Lock the repair order before updating technician work.');
    if ((int)($order['assigned_to'] ?? 0) !== $technicianId || $technicianId <= 0) {
        throw new DomainException('This work order is no longer assigned to you.', 403);
    }
    $orderId = (int)$order['id'];
    $current = rl_work_session_current($pdo, $orderId);
    if ($current) {
        if ((int)$current['technician_id'] !== $technicianId || $current['completed_at'] !== null) {
            throw new DomainException('Your work session is complete. Ask Admin to assign the next technician before continuing.');
        }
        // One-time legacy repair: charges created before this feature had no owner.
        // Attribute them only to the first session, never to a later replacement.
        $first = $pdo->prepare('SELECT id FROM repair_order_work_sessions WHERE repair_order_id = ? ORDER BY id ASC LIMIT 1');
        $first->execute([$orderId]);
        if ((int)$first->fetchColumn() === (int)$current['id']) {
            $pdo->prepare('UPDATE repair_order_items SET work_session_id = ? WHERE repair_order_id = ? AND work_session_id IS NULL')
                ->execute([(int)$current['id'], $orderId]);
            $pdo->prepare('UPDATE part_requests SET work_session_id = ? WHERE order_id = ? AND work_session_id IS NULL AND requested_by = ?')
                ->execute([(int)$current['id'], $orderId, $technicianId]);
        }
        return (int)$current['id'];
    }
    // Legacy orders acquire a session only during an authorized, locked mutation.
    $sessionId = rl_work_session_start($pdo, $order, $technicianId, null);
    $pdo->prepare('UPDATE repair_order_items SET work_session_id = ? WHERE repair_order_id = ? AND work_session_id IS NULL')
        ->execute([$sessionId, $orderId]);
    $pdo->prepare('UPDATE part_requests SET work_session_id = ? WHERE order_id = ? AND work_session_id IS NULL AND requested_by = ?')
        ->execute([$sessionId, $orderId, $technicianId]);
    return $sessionId;
}

function rl_work_session_close(PDO $pdo, array $order, int $technicianId, string $type, string $note): int
{
    if (!in_array($type, ['handover', 'completed', 'reassigned'], true)) {
        throw new InvalidArgumentException('Invalid technician work completion type.');
    }
    if (mb_strlen($note) > 5000) throw new DomainException('Work summary must be 5000 characters or fewer.');
    $sessionId = rl_work_session_require_active($pdo, $order, $technicianId);
    $pending = $pdo->prepare("SELECT id FROM part_requests WHERE order_id = ? AND status IN ('pending', 'approved') LIMIT 1 FOR UPDATE");
    $pending->execute([(int)$order['id']]);
    if ($pending->fetchColumn()) {
        throw new DomainException('Please wait for Admin to approve and process all outstanding part requests before completing or handing over this job.');
    }
    $stmt = $pdo->prepare('UPDATE repair_order_work_sessions SET completed_at = NOW(), completion_type = ?, completion_note = ? WHERE id = ? AND completed_at IS NULL');
    $stmt->execute([$type, trim($note), $sessionId]);
    if ($stmt->rowCount() !== 1) throw new DomainException('This technician work session has already been completed.');
    return $sessionId;
}

function rl_work_session_items(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare('SELECT i.*, s.technician_id, s.completed_at AS session_completed_at, u.full_name AS technician_name
        FROM repair_order_items i
        LEFT JOIN repair_order_work_sessions s ON s.id = i.work_session_id
        LEFT JOIN users u ON u.id = s.technician_id
        WHERE i.repair_order_id = ? ORDER BY i.id');
    $stmt->execute([$orderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function rl_work_sessions(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare("SELECT s.*, u.full_name AS technician_name,
        COALESCE(t.labor_total, 0) AS labor_total, COALESCE(t.parts_total, 0) AS parts_total
        FROM repair_order_work_sessions s
        LEFT JOIN users u ON u.id = s.technician_id
        LEFT JOIN (
            SELECT work_session_id,
                SUM(CASE WHEN item_type = 'labor' THEN subtotal ELSE 0 END) AS labor_total,
                SUM(CASE WHEN item_type = 'part' THEN subtotal ELSE 0 END) AS parts_total
            FROM repair_order_items WHERE repair_order_id = ? GROUP BY work_session_id
        ) t ON t.work_session_id = s.id
        WHERE s.repair_order_id = ? ORDER BY s.id");
    $stmt->execute([$orderId, $orderId]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}
