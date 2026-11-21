<?php
/** Driver-independent behavioral test for customer appointment approval states. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/includes/customer_appointments.php';

class PendingRowsStatement extends PDOStatement {
    public function __construct(private array $rows) {}
    public function execute(?array $params = null): bool { return true; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
}
class PendingRowsPdo extends PDO {
    public function __construct(public array $rows) {}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        if (!str_contains($query, 'WHERE sr.user_id = :user_id')) throw new RuntimeException('Missing customer ownership filter.');
        return new PendingRowsStatement($this->rows);
    }
}
function pending_fixture(int $id, string $status, ?int $orderId, string $orderStatus,
    string $mode, ?int $technician, string $date): array {
    return [
        'id' => $id, 'service_type' => 'Inspection', 'service' => 'Inspection', 'vehicle' => 'Toyota',
        'license_plate' => '12345', 'message' => 'Preferred appointment: ' . $date . ' at 14:30. Awaiting workshop confirmation.',
        'request_status' => $status, 'created_at' => '2026-11-21 09:00:00', 'order_id' => $orderId,
        'order_code' => $orderId === null ? null : 'RO-' . $orderId,
        'order_status' => $orderStatus, 'intake_mode' => $mode, 'assigned_to' => $technician,
        'preferred_technician_name' => '', 'assigned_technician_name' => '',
    ];
}
$pdo = new PendingRowsPdo([
    pending_fixture(1, 'processed', 101, 'received', 'online_booking', null, '2026-11-22'),
    pending_fixture(2, 'processed', 102, 'received', 'online_booking', 42, '2026-11-23'),
    pending_fixture(3, 'processed', 103, 'received', 'online_booking', null, '2026-11-15'),
    pending_fixture(4, 'pending', null, '', '', null, '2026-11-24'),
    pending_fixture(5, 'cancelled', 105, 'received', 'online_booking', null, '2026-11-25'),
    pending_fixture(6, 'processed', 106, 'completed', 'online_booking', 42, '2026-11-30'),
]);
$now = new DateTimeImmutable('2026-11-21 09:00:00', new DateTimeZone('Asia/Bangkok'));
$result = rl_customer_appointments($pdo, 7, $now);
$items = array_column($result['items'], null, 'id');
$checks = [
    $result['counts'] === ['pending' => 3, 'upcoming' => 1, 'past' => 1, 'cancelled' => 1],
    $result['pending_count'] === 3,
    $items[1]['group'] === 'pending' && $items[1]['status_label'] === 'Awaiting admin approval',
    $items[2]['group'] === 'upcoming' && $items[2]['status_label'] === 'Technician assigned',
    $items[3]['group'] === 'pending' && $items[3]['requested_at'] < $now,
    $items[4]['group'] === 'pending' && $items[4]['status_label'] === 'Awaiting admin review',
    $items[5]['group'] === 'cancelled',
    $items[6]['group'] === 'past',
    $result['next']['id'] === 1,
];
foreach ($checks as $i => $ok) { if (!$ok) throw new RuntimeException('Approval behavior mismatch #' . ($i + 1)); }
// Approval is represented by the technician assignment, without changing service_requests.status.
$pdo->rows[0]['assigned_to'] = 42;
$approved = rl_customer_appointments($pdo, 7, $now);
if ($approved['pending_count'] !== 2 || array_column($approved['items'], null, 'id')[1]['group'] !== 'upcoming') {
    throw new RuntimeException('Approved booking did not leave the pending group.');
}
echo 'PASS: ' . (count($checks) + 1) . " pending approval behavior checks\n";
