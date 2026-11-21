<?php
/** Behavior checks without requiring a PDO driver or altering a database. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/includes/customer_appointments.php';

final class InMemoryAppointmentStatement extends PDOStatement {
    public function __construct(private array $rows) {}
    public function execute(?array $params = null): bool { return true; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
}
final class InMemoryAppointmentPDO extends PDO {
    public function __construct(private array $rows) {}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new InMemoryAppointmentStatement($this->rows);
    }
}
function pendingRow(int $id, string $message, string $created = '2026-11-19 10:00:00'): array {
    return [
        'id' => $id, 'service_type' => 'Engine diagnostic', 'service' => '',
        'vehicle' => 'Audi A8', 'license_plate' => '68A-999.99',
        'message' => $message, 'request_status' => 'processed', 'created_at' => $created,
        'order_id' => $id + 100, 'order_code' => 'RO-' . $id, 'order_status' => 'received',
        'intake_mode' => 'online_booking', 'assigned_to' => null,
        'preferred_technician_name' => '', 'assigned_technician_name' => '',
    ];
}
function assertAppointment(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$now = new DateTimeImmutable('2026-11-21 10:00:00', new DateTimeZone('Asia/Bangkok'));
$noDate = rl_customer_appointments(new InMemoryAppointmentPDO([
    pendingRow(6, 'No preferred date yet.'),
]), 7, $now);
assertAppointment($noDate['next']['id'] === 6 && $noDate['next']['requested_at'] === null
    && $noDate['next']['group'] === 'pending' && !$noDate['next']['preferred_elapsed'],
    'Unscheduled pending request must appear without a fabricated date.');
$past = rl_customer_appointments(new InMemoryAppointmentPDO([
    pendingRow(6, 'Preferred appointment: 2026-11-13 at 09:00.'),
]), 7, $now);
assertAppointment($past['next']['id'] === 6 && $past['next']['preferred_elapsed'] === true,
    'Unapproved request with elapsed preferred date stays in the featured card.');
$future = rl_customer_appointments(new InMemoryAppointmentPDO([
    pendingRow(6, 'No preferred date yet.', '2026-11-20 10:00:00'),
    pendingRow(9, 'Preferred appointment: 2026-11-30 at 14:30.', '2026-11-15 10:00:00'),
]), 7, $now);
assertAppointment($future['next']['id'] === 9 && !$future['next']['preferred_elapsed'],
    'Future requested appointment takes priority over unscheduled pending request.');
$latest = rl_customer_appointments(new InMemoryAppointmentPDO([
    pendingRow(6, 'No preferred date yet.', '2026-11-16 10:00:00'),
    pendingRow(8, 'Preferred appointment: 2026-11-10 at 14:30.', '2026-11-19 10:00:00'),
]), 7, $now);
assertAppointment($latest['next']['id'] === 8, 'Latest pending request is used when no future visit is found.');
$empty = rl_customer_appointments(new InMemoryAppointmentPDO([]), 7, $now);
assertAppointment($empty['next'] === null && $empty['pending_count'] === 0,
    'Customer without requests still sees empty state.');
echo "PASS: 5 next requested visit behavior checks\n";
