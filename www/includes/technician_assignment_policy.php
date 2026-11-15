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
        $warnings[] = 'Shift roster could not be checked. Install database/technician_upgrade.sql and register an approved shift, or supply an administrator exception reason.';
    }
    return ['open_jobs' => $open, 'shift' => $shift, 'warnings' => $warnings];
}
