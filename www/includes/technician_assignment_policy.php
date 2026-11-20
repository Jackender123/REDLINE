<?php
declare(strict_types=1);
/** Shared assignment rules. This checks today's approved shift, not the customer's requested slot. */
const RL_TECH_OPEN_JOB_GUIDELINE = 5;

function rl_assignment_readiness(PDO $pdo, int $technicianId, ?int $excludeOrderId = null): array
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM repair_orders WHERE assigned_to = :tech AND status NOT IN ('completed', 'delivered', 'cancelled') AND (:skip_a IS NULL OR id <> :skip_b)");
    $stmt->execute(['tech' => $technicianId, 'skip_a' => $excludeOrderId, 'skip_b' => $excludeOrderId]);
    $open = (int)$stmt->fetchColumn();
    $shift = 'unverified';
    try {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM technician_shifts WHERE technician_id = ? AND work_date = ? AND status = 'approved'");
        $stmt->execute([$technicianId, date('Y-m-d')]);
        $shift = (int)$stmt->fetchColumn() > 0 ? 'approved' : 'not_registered';
    } catch (PDOException $ex) {
        // Older deployments may not have the roster module. Do not silently certify availability.
        error_log('Technician shift check unavailable: ' . $ex->getMessage());
    }
    $warnings = [];
    if ($open >= RL_TECH_OPEN_JOB_GUIDELINE) {
        $warnings[] = "Technician has {$open} open repair orders (guideline: " . RL_TECH_OPEN_JOB_GUIDELINE . ').';
    }
    if ($shift === 'not_registered') {
        $warnings[] = 'No approved shift is registered for today. Register one in Shifts & Payroll or document an administrator exception.';
    } elseif ($shift === 'unverified') {
        $warnings[] = 'Shift roster could not be checked. Install database/migrations/20261115_workshop_updates.sql and register an approved shift, or supply an administrator exception reason.';
    }
    return ['open_jobs' => $open, 'shift' => $shift, 'warnings' => $warnings];
}

/**
 * Booking/initial assignment may happen days before work begins: no approved
 * shift on the calendar *today* must not silently block dispatch. The shift
 * warning is retained in the audit trail and shown to Admin. Workload remains
 * a blocking exception, and every reassignment requires an explicit reason.
 * A different technician than the customer's active preference also requires
 * its own reason (checked in approve.php).
 */
function rl_assignment_blocking_warnings(array $readiness, bool $initialAssignment): array
{
    $warnings = $readiness['warnings'] ?? [];
    if (!$initialAssignment) return $warnings;
    return array_values(array_filter($warnings, static function (string $warning): bool {
        return !str_starts_with($warning, 'No approved shift is registered for today.')
            && !str_starts_with($warning, 'Shift roster could not be checked.');
    }));
}
