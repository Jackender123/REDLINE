<?php
/** Appointment read-model checks use only a disposable SQLite in-memory database. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/includes/customer_appointments.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE service_requests (id INTEGER PRIMARY KEY, user_id INTEGER, service_type TEXT, service TEXT, vehicle TEXT, license_plate TEXT, message TEXT, status TEXT, created_at TEXT)');
$pdo->exec('CREATE TABLE repair_orders (id INTEGER PRIMARY KEY, service_request_id INTEGER UNIQUE, order_code TEXT, status TEXT)');
$insert = $pdo->prepare('INSERT INTO service_requests VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
$rows = [
    [1, 7, 'Oil service', 'Legacy service', 'Toyota Camry', '51g-001.01', 'Preferred appointment: 2026-11-15 at 09:00. Awaiting workshop confirmation.', 'pending', '2026-11-01 10:00:00'],
    [2, 7, '', 'Legacy inspection', '', null, 'Preferred appointment: 2026-11-16 at 10:30.', 'processed', '2026-11-02 10:00:00'],
    [3, 7, 'Old request', '', 'Ford Ranger', '51D-003.03', 'Preferred appointment: 2026-11-13 at 09:00.', 'pending', '2026-11-03 10:00:00'],
    [4, 7, 'Cancelled request', '', 'BMW', '', 'Preferred appointment: 2026-11-17 at 14:30.', 'cancelled', '2026-11-04 10:00:00'],
    [5, 7, 'Future but finished', '', 'BMW', '', 'Preferred appointment: 2026-11-18 at 16:00.', 'processed', '2026-11-05 10:00:00'],
    [6, 7, 'Missing schedule', '', 'Honda', '', 'Please call me.', 'pending', '2026-11-06 10:00:00'],
    [7, 7, 'Impossible date', '', 'Honda', '', 'Preferred appointment: 2026-02-30 at 09:00.', 'pending', '2026-11-07 10:00:00'],
    [8, 7, 'Impossible time', '', 'Honda', '', 'Preferred appointment: 2026-11-15 at 25:00.', 'pending', '2026-11-08 10:00:00'],
    [9, 7, 'Active repair', '', 'Mazda', '', 'Preferred appointment: 2026-11-19 at 10:30.', 'processed', '2026-11-09 10:00:00'],
    [10, 8, 'Other customer private booking', '', 'Secret vehicle', 'PRIVATE', 'Preferred appointment: 2026-11-14 at 11:00.', 'pending', '2026-11-10 10:00:00'],
    [11, 7, 'Cancellation overrides linked repair', '', 'BMW', '', 'Preferred appointment: 2026-11-20 at 09:00.', 'cancelled', '2026-11-11 10:00:00'],
    [12, 7, 'Delivered without schedule', '', 'BMW', '', '', 'processed', '2026-11-12 10:00:00'],
    [13, 7, '', '', '', '', 'Preferred appointment: 2026-11-14 at 03:00.', 'pending', '2026-11-13 10:00:00'],
];
foreach ($rows as $row) $insert->execute($row);
$pdo->exec("INSERT INTO repair_orders VALUES (51, 5, 'RO-FINISHED', 'completed'), (91, 9, 'RO-ACTIVE', 'repairing'), (111, 11, 'RO-CANCELLED', 'repairing'), (121, 12, 'RO-DELIVERED', 'delivered')");
$checks = 0;
function appointments_check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    ++$checks;
}
$now = new DateTimeImmutable('2026-11-14 03:00:00', new DateTimeZone('UTC'));
$result = rl_customer_appointments($pdo, 7, $now);
$items = array_column($result['items'], null, 'id');
appointments_check(count($items) === 12 && !isset($items[10]), 'Only requests belonging to the signed-in customer are returned.');
appointments_check($result['counts'] === ['upcoming' => 6, 'past' => 4, 'cancelled' => 2], 'Future, unscheduled, elapsed, terminal and cancelled requests have accurate counts.');
appointments_check($result['pending_count'] === 6, 'Pending count includes only pending requests without workshop intake, including elapsed unanswered requests.');
appointments_check($items[1]['requested_at'] instanceof DateTimeImmutable && $items[1]['requested_at']->format('Y-m-d H:i e') === '2026-11-15 09:00 Asia/Bangkok', 'Preferred times are parsed in Bangkok timezone.');
appointments_check($items[7]['requested_at'] === null && $items[8]['requested_at'] === null, 'Invalid calendar dates and overflowing times are not normalized into appointments.');
appointments_check($items[6]['requested_at'] === null && $items[6]['group'] === 'upcoming', 'Requests without a preferred schedule remain visible with no invented appointment time.');
appointments_check($items[13]['group'] === 'past', 'The injected current time is converted to Bangkok before comparing preferred times.');
appointments_check($items[2]['service'] === 'Legacy inspection' && $items[2]['vehicle'] === 'Vehicle details pending', 'Legacy service names and missing vehicle details have truthful fallbacks.');
appointments_check($items[13]['service'] === 'Service request' && $items[1]['plate'] === '51G-001.01', 'Blank service names and plate formatting have usable presentation values.');
appointments_check($items[1]['status_label'] === 'Awaiting confirmation' && $items[2]['status_label'] === 'Under review', 'Pending and processed requests never claim that the schedule is confirmed.');
appointments_check($items[3]['status_label'] === 'Awaiting confirmation' && str_contains($items[3]['note'], 'has passed'), 'An elapsed preferred date does not imply service completion.');
appointments_check($items[5]['group'] === 'past' && $items[5]['status_label'] === 'Service completed', 'Completed workshop orders are past even with future preferred dates.');
appointments_check($items[12]['group'] === 'past' && $items[12]['status_label'] === 'Vehicle delivered', 'Delivered orders without preferred dates are past.');
appointments_check($items[9]['status_label'] === 'Repair in progress' && $items[9]['order_id'] === 91 && $items[9]['order_code'] === 'RO-ACTIVE', 'Linked repair progress and identifiers come from the real repair order.');
appointments_check($items[11]['group'] === 'cancelled' && $items[11]['status_label'] === 'Cancelled' && $items[11]['tone'] === 'red', 'Cancellation overrides linked workshop progress.');
$upcoming = array_values(array_filter($result['items'], static fn(array $item): bool => $item['group'] === 'upcoming'));
appointments_check(array_column($upcoming, 'id') === [1, 2, 9, 8, 7, 6], 'Upcoming scheduled visits sort first by preferred time, then unscheduled requests by newest submission.');
$past = array_values(array_filter($result['items'], static fn(array $item): bool => $item['group'] === 'past'));
appointments_check(array_column($past, 'id') === [5, 13, 3, 12], 'Past requests sort newest preferred date first with submission time as fallback.');
$cancelled = array_values(array_filter($result['items'], static fn(array $item): bool => $item['group'] === 'cancelled'));
appointments_check(array_column($cancelled, 'id') === [11, 4], 'Cancelled requests sort newest first.');
appointments_check($result['next']['id'] === 1 && $result['next']['reference'] === '#REQ-1', 'The next visit is the earliest future noncancelled, nonterminal scheduled request.');
appointments_check(rl_customer_appointments($pdo, 99, $now) === ['items' => [], 'counts' => ['upcoming' => 0, 'past' => 0, 'cancelled' => 0], 'pending_count' => 0, 'next' => null], 'A customer without requests receives accurate empty counts.');
$later = rl_customer_appointments($pdo, 7, new DateTimeImmutable('2027-01-01', new DateTimeZone('Asia/Bangkok')));
appointments_check($later['next'] === null, 'Unscheduled requests never become an invented next visit.');
appointments_check((int) $pdo->query('SELECT COUNT(*) FROM service_requests')->fetchColumn() === 13 && (int) $pdo->query('SELECT COUNT(*) FROM repair_orders')->fetchColumn() === 4, 'Loading appointment data never alters request or order rows.');
echo "PASS: $checks customer appointment checks\n";
