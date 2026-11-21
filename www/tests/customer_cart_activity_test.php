<?php
/** Run with: php www/tests/customer_cart_activity_test.php */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/includes/customer_cart_activity.php';

class CartActivityRows extends PDOStatement
{
    public function __construct(private array $rows) {}
    public function execute(?array $params = null): bool { return true; }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
}
class CartActivityFakePdo extends PDO
{
    public function __construct(public array $requests, public array $orders) {}
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_contains($query, 'FROM service_requests sr')) {
            if (!str_contains($query, 'WHERE sr.user_id = :user_id')) throw new RuntimeException('Requests not scoped to customer');
            return new CartActivityRows($this->requests);
        }
        if (str_contains($query, 'FROM repair_orders r')) {
            if (!str_contains($query, 'WHERE COALESCE(r.customer_id, v.customer_id) = :customer_id')) throw new RuntimeException('Orders not scoped to customer');
            return new CartActivityRows($this->orders);
        }
        throw new RuntimeException('Unexpected query');
    }
}
function cartRequest(int $id, ?int $orderId, ?int $assigned, string $orderStatus, string $reqStatus = 'processed'): array
{
    return [
        'id' => $id, 'service_type' => 'Inspection', 'service' => 'Inspection', 'vehicle' => 'Audi A8', 'license_plate' => '68A-999.99',
        'message' => 'Preferred appointment: 2026-11-30 at 14:30. Awaiting workshop confirmation.',
        'request_status' => $reqStatus, 'created_at' => '2026-11-21 10:00:00',
        'order_id' => $orderId, 'order_code' => $orderId === null ? null : 'RO-' . $orderId,
        'order_status' => $orderStatus, 'intake_mode' => 'online_booking', 'assigned_to' => $assigned,
        'preferred_technician_name' => '', 'assigned_technician_name' => '',
    ];
}
function cartOrder(int $id, string $status, ?int $assigned = 3, string $mode = 'workshop_intake'): array
{
    return [
        'id' => $id, 'order_code' => 'RO-' . $id, 'status' => $status,
        'assigned_to' => $assigned, 'intake_mode' => $mode, 'service_type' => 'Oil & Filter',
        'brand' => 'Toyota', 'model' => 'Camry', 'license_plate' => '51A-123.45',
    ];
}
$pdo = new CartActivityFakePdo(
    [cartRequest(11, 101, null, 'received'), cartRequest(12, 102, 4, 'received'), cartRequest(13, 103, 4, 'repairing')],
    [cartOrder(101, 'received', null, 'online_booking'), cartOrder(102, 'received', 4, 'online_booking'), cartOrder(103, 'repairing'),
        cartOrder(104, 'completed'), cartOrder(105, 'received', null, 'online_booking'), cartOrder(106, 'received', 4, 'online_booking')]
);
$activity = rl_cart_activity($pdo, 7);
$checks = [
    $activity['total'] === 6,
    $activity['counts'] === ['active' => 1, 'pending' => 2, 'assigned' => 2, 'ready' => 1],
    count(array_filter($activity['items'], static fn($it) => $it['reference'] === '#REQ-11')) === 1,
    count(array_filter($activity['items'], static fn($it) => $it['reference'] === '#REQ-12')) === 1,
    count(array_filter($activity['items'], static fn($it) => $it['reference'] === 'RO-103')) === 1,
    $activity['items'][0]['kind'] === 'active',
    $activity['items'][1]['kind'] === 'pending',
    $activity['items'][0]['url'] === '?page=orders',
    str_contains($activity['items'][1]['url'], 'tab=pending'),
    rl_cart_activity($pdo, 0)['total'] === 0,
];
foreach ($checks as $index => $ok) {
    if (!$ok) throw new RuntimeException('Cart activity check #' . ($index + 1) . ' failed');
}
// Match the user's reported situation: one active repair + one unapproved appointment.
$pdo2 = new CartActivityFakePdo([cartRequest(21, 201, null, 'received')],
    [cartOrder(201, 'received', null, 'online_booking'), cartOrder(202, 'repairing')]);
$two = rl_cart_activity($pdo2, 7);
if ($two['total'] !== 2 || $two['counts']['pending'] !== 1 || $two['counts']['active'] !== 1) {
    throw new RuntimeException('One active + one pending should make two cart items');
}
// User-supplied text must not turn into HTML or script execution.
$two['items'][0]['title'] = '<img src=x onerror=alert(1)>';
ob_start();
rl_cart_panel($two, '/customer/index.php', '/auth/login.php');
$html = (string) ob_get_clean();
if (str_contains($html, '<img src=x') || !str_contains($html, '&lt;img')) {
    throw new RuntimeException('Customer text not escaped');
}
if (!str_contains($html, '2 items') || !str_contains($html, 'Awaiting admin approval')) {
    throw new RuntimeException('Cart HTML is missing live counts');
}
echo 'PASS: 13 customer cart activity/ownership/display checks' . PHP_EOL;
