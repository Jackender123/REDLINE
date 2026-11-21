<?php
declare(strict_types=1);
/** REDLINE shift policy: a current, approved, non-absent shift is mandatory for assignment. */
const RL_TECH_OPEN_JOB_GUIDELINE = 5;

/**
 * Evaluate availability from the authoritative technician_shifts table.
 * A pending/rejected/cancelled or absent shift never authorizes dispatch.
 * Database errors fail closed, rather than treating missing roster data as approved.
 */
function rl_assignment_readiness(PDO $pdo, int $technicianId, ?int $excludeOrderId = null): array
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM repair_orders WHERE assigned_to = :tech AND status NOT IN ('completed', 'delivered', 'cancelled') AND (:skip_a IS NULL OR id <> :skip_b)");
    $stmt->execute(['tech' => $technicianId, 'skip_a' => $excludeOrderId, 'skip_b' => $excludeOrderId]);
    $open = (int)$stmt->fetchColumn();
    $shift = 'unverified';
    try {
        // Prioritize an approved usable shift if more than one shift exists today.
        $stmt = $pdo->prepare("SELECT attendance FROM technician_shifts WHERE technician_id = ? AND work_date = ? AND status = 'approved' ORDER BY CASE attendance WHEN 'present' THEN 0 WHEN 'unconfirmed' THEN 1 ELSE 2 END LIMIT 1");
        $stmt->execute([$technicianId, date('Y-m-d')]);
        $attendance = $stmt->fetchColumn();
        $shift = $attendance === false ? 'not_registered' : ($attendance === 'absent' ? 'absent' : 'approved');
    } catch (PDOException $ex) {
        error_log('Technician shift check unavailable: ' . $ex->getMessage());
    }
    $warnings = [];
    if ($open >= RL_TECH_OPEN_JOB_GUIDELINE) {
        $warnings[] = "Technician has {$open} open repair orders (guideline: " . RL_TECH_OPEN_JOB_GUIDELINE . ').';
    }
    if ($shift === 'not_registered') {
        $warnings[] = 'No approved shift is registered for today. Register and approve a shift in Shifts & Payroll before assigning.';
    } elseif ($shift === 'absent') {
        $warnings[] = 'Technician is marked absent today. Choose another technician or correct the attendance record.';
    } elseif ($shift === 'unverified') {
        $warnings[] = 'Shift roster could not be checked. Verify the shift database before assigning.';
    }
    return ['open_jobs' => $open, 'shift' => $shift, 'warnings' => $warnings];
}

/** All assignments, including advance bookings and handovers, require today's approved shift. */
function rl_assignment_shift_blocker(array $readiness): string
{
    if (($readiness['shift'] ?? 'unverified') === 'approved') return '';
    return match ($readiness['shift'] ?? 'unverified') {
        'absent' => 'Technician is marked absent today. Select a technician with an approved shift and available attendance.',
        'not_registered' => 'This technician has no approved shift today. Approve a shift in Shifts & Payroll before sending this order.',
        default => 'Technician shift availability cannot be verified. Assignment is blocked until the shift database is available.',
    };
}

/** Workload warnings can be overridden with a documented reason; shift checks cannot. */
function rl_assignment_blocking_warnings(array $readiness, bool $initialAssignment): array
{
    return array_values(array_filter($readiness['warnings'] ?? [], static fn(string $warning): bool => str_starts_with($warning, 'Technician has ')));
}
