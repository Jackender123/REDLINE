<?php
/** Fabrication read-model checks run only against disposable SQLite memory. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/includes/customer_fabrication.php';

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE service_requests (id INTEGER PRIMARY KEY, user_id INTEGER, service_type TEXT, service TEXT, vehicle TEXT, license_plate TEXT, status TEXT, created_at TEXT)');
$pdo->exec('CREATE TABLE repair_orders (id INTEGER PRIMARY KEY, service_request_id INTEGER UNIQUE, order_code TEXT, status TEXT)');
$insert = $pdo->prepare('INSERT INTO service_requests VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
$rows = [
    [1, 7, 'FABRICATION: CUSTOM EXHAUST SYSTEMS', '', 'Toyota Camry', '51g-001.01', 'pending', '2026-11-01 09:00:00'],
    [2, 7, ' fabrication: roll cage fabrication ', '', 'BMW', 'ROLL-2', 'processed', '2026-11-02 09:00:00'],
    [3, 7, '', 'HEADERS & METALWORK', 'Honda', 'HEAD-3', 'pending', '2026-11-03 09:00:00'],
    [4, 7, 'WIDEBODY CONVERSIONS', '', 'Audi', 'WIDE-4', 'processed', '2026-11-04 09:00:00'],
    [5, 7, 'FABRICATION: OTHER / NOT SURE', '', '', null, 'pending', '2026-11-05 09:00:00'],
    [6, 7, 'OTHER / NOT SURE', '', 'Ambiguous other', '', 'pending', '2026-11-06 09:00:00'],
    [7, 7, 'PERFORMANCE: CUSTOM CHASSIS & SUSPENSION', '', 'Performance car', '', 'pending', '2026-11-07 09:00:00'],
    [8, 7, 'OIL & FILTER CHANGE', '', 'Ordinary service', '', 'pending', '2026-11-08 09:00:00'],
    [9, 8, 'FABRICATION: CUSTOM EXHAUST SYSTEMS', '', 'Private other customer vehicle', 'PRIVATE', 'pending', '2026-11-09 09:00:00'],
    [10, 7, 'CUSTOM EXHAUST SYSTEMS AND MORE', '', 'Substring mismatch', '', 'pending', '2026-11-10 09:00:00'],
    [11, 7, 'FABRICATION: WIDEBODY CONVERSIONS', '', 'Cancelled Audi', '', 'cancelled', '2026-11-11 09:00:00'],
    [12, 7, 'FABRICATION: HEADERS & METALWORK', '', 'Delivered Honda', '', 'processed', '2026-11-12 09:00:00'],
    [13, 7, 'FABRICATION: CUSTOM EXHAUST SYSTEMS', '', 'Waiting Toyota', '', 'processed', '2026-11-13 09:00:00'],
    [14, 7, 'FABRICATION: ROLL CAGE FABRICATION', '', 'Inspected BMW', '', 'processed', '2026-11-14 09:00:00'],
    [15, 7, 'FABRICATION: ROLL CAGE FABRICATION', '', 'Unknown status BMW', '', 'processed', '2026-11-15 09:00:00'],
    [16, 7, 'repair', 'fabrication: widebody conversions', 'Fallback service car', '', 'pending', '2026-11-16 09:00:00'],
];
foreach ($rows as $row) $insert->execute($row);
$pdo->exec("INSERT INTO repair_orders VALUES (21, 2, 'RO-ASSESS', 'diagnosing'), (31, 3, 'RO-WORK', 'repairing'), (41, 4, 'RO-DONE', 'completed'), (111, 11, 'RO-CANCEL', 'repairing'), (121, 12, 'RO-DELIVERED', 'delivered'), (131, 13, 'RO-PARTS', 'waiting_parts'), (141, 14, 'RO-INSPECT', 'inspecting'), (151, 15, 'RO-UNKNOWN', 'legacy_state')");
$checks = 0;
function fabrication_check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    ++$checks;
}
$result = rl_customer_fabrication($pdo, 7);
$items = array_column($result['items'], null, 'id');
fabrication_check(count($items) === 11 && !isset($items[9]), 'Only fabrication requests owned by this customer are returned.');
fabrication_check(!isset($items[6], $items[7], $items[8], $items[10]), 'Ambiguous other services, performance, ordinary services and substring matches are excluded.');
fabrication_check($result['counts'] === ['total' => 11, 'active' => 8, 'completed' => 2, 'cancelled' => 1], 'Counts come from actual active, terminal and cancelled request states.');
fabrication_check($items[2]['service'] === 'ROLL CAGE FABRICATION', 'Exact matching accepts surrounding whitespace and case differences.');
fabrication_check($items[3]['service_value'] === 'FABRICATION: HEADERS & METALWORK', 'Unprefixed known legacy fabrication services receive their canonical value.');
fabrication_check($items[16]['service'] === 'WIDEBODY CONVERSIONS', 'A recognized fallback service field remains discoverable when service_type is generic.');
fabrication_check($items[5]['service'] === 'Other / Not sure' && $items[5]['vehicle'] === 'Vehicle details pending', 'Explicit fabrication Other requests are included with truthful missing-vehicle fallback.');
fabrication_check($items[1]['plate'] === '51G-001.01' && $items[5]['plate'] === '', 'Plates normalize safely without invented vehicle identity.');
fabrication_check($items[1]['stage'] === 0 && $items[1]['status_label'] === 'Request received' && $items[1]['order_id'] === null, 'A pending request never invents fabrication progress.');
fabrication_check($items[2]['stage'] === 2 && $items[2]['status_label'] === 'Assessment in progress', 'Diagnosing maps to assessment rather than invented 3D design completion.');
fabrication_check($items[3]['stage'] === 3 && $items[3]['status_label'] === 'Work in progress', 'Actual repair work maps to the production stage.');
fabrication_check($items[13]['stage'] === 3 && $items[13]['status_label'] === 'Waiting for parts', 'Parts delays remain visible and distinct.');
fabrication_check($items[14]['stage'] === 4 && $items[14]['status_label'] === 'Final inspection', 'Inspection uses the actual linked order state.');
fabrication_check($items[4]['stage'] === 5 && $items[12]['stage'] === 5 && $items[12]['status_label'] === 'Delivered', 'Only completed or delivered work reaches the final stage.');
fabrication_check($items[11]['group'] === 'cancelled' && $items[11]['stage'] === 0 && $items[11]['tone'] === 'red', 'Request cancellation overrides progress on a linked order.');
fabrication_check($items[15]['stage'] === 0 && $items[15]['status_label'] === 'Workshop update pending', 'Unknown order states cannot imply a fabricated completion stage.');
fabrication_check($result['featured']['id'] === 16 && $items[2]['order_code'] === 'RO-ASSESS', 'Latest active request is featured and linked identifiers remain real.');
fabrication_check(array_column($result['items'], 'id') === [16, 15, 14, 13, 12, 11, 5, 4, 3, 2, 1], 'History sorts by most recent submission without sample dates.');
$pdo->exec("UPDATE service_requests SET status = 'processed' WHERE id = 5");
$processed = array_column(rl_customer_fabrication($pdo, 7)['items'], null, 'id');
fabrication_check($processed[5]['stage'] === 0 && $processed[5]['status_label'] === 'Under review', 'Processing without a linked order never claims price, design or schedule approval.');
fabrication_check(rl_customer_fabrication($pdo, 99) === ['items' => [], 'counts' => ['total' => 0, 'active' => 0, 'completed' => 0, 'cancelled' => 0], 'featured' => null], 'Customers with no fabrication requests receive a real empty state.');
fabrication_check(rl_customer_fabrication($pdo, 0)['counts']['total'] === 0, 'Invalid customer identities cannot load another customer\'s requests.');
fabrication_check((int) $pdo->query('SELECT COUNT(*) FROM service_requests')->fetchColumn() === 16 && (int) $pdo->query('SELECT COUNT(*) FROM repair_orders')->fetchColumn() === 8, 'Loading fabrication data never creates or alters project records.');
echo "PASS: $checks customer fabrication checks\n";
