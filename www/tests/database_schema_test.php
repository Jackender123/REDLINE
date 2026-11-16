<?php
/** Fresh installation and workshop upgrade checks use only an isolated database. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);
$database = 'redline_schema_test_' . bin2hex(random_bytes(5));
$checks = 0;

function schema_check(bool $condition, string $message): void
{
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    ++$checks;
}

function schema_definitions(PDO $pdo): array
{
    $definitions = [];
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        $definitions[$table] = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
    }
    return $definitions;
}

function schema_workshop_check(PDO $pdo): void
{
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    schema_check(!array_diff(['technician_shifts', 'technician_pay_rates', 'repair_order_cost_reviews'], $tables),
        'Workshop shifts, pay rates and cost reviews are included.');
    $column = $pdo->query("SHOW COLUMNS FROM service_requests LIKE 'preferred_technician_id'")->fetch();
    schema_check($column && $column['Type'] === 'int unsigned' && $column['Null'] === 'YES',
        'Technician preference is a nullable unsigned user ID.');
    $index = $pdo->query("SHOW INDEX FROM service_requests WHERE Key_name = 'idx_service_requests_preferred_technician'")->fetch();
    schema_check($index && $index['Column_name'] === 'preferred_technician_id',
        'Technician preference has its lookup index.');
    $foreignKey = $pdo->query("SELECT REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
        FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'service_requests' AND COLUMN_NAME = 'preferred_technician_id'
        AND REFERENCED_TABLE_NAME IS NOT NULL")->fetch();
    schema_check($foreignKey && $foreignKey['REFERENCED_TABLE_NAME'] === 'users'
        && $foreignKey['REFERENCED_COLUMN_NAME'] === 'id', 'Technician preference references users.');
    $column = $pdo->query("SHOW COLUMNS FROM repair_order_items LIKE 'part_request_id'")->fetch();
    schema_check($column && $column['Type'] === 'bigint unsigned' && $column['Null'] === 'YES',
        'Issued parts can link to a nullable request ID.');
    $index = $pdo->query("SHOW INDEX FROM repair_order_items WHERE Key_name = 'uq_repair_item_part_request'")->fetch();
    schema_check($index && $index['Column_name'] === 'part_request_id' && (int) $index['Non_unique'] === 0,
        'Each fulfilled part request can be billed only once.');
    $status = $pdo->query("SHOW COLUMNS FROM repair_orders LIKE 'status'")->fetch();
    schema_check(str_contains($status['Type'], "'cancelled'"), 'Repair orders support cancellation.');
}

function schema_business_rows(PDO $pdo): array
{
    return [
        'users' => $pdo->query('SELECT id, role_id, username, full_name, is_customer FROM users ORDER BY id')->fetchAll(),
        'vehicles' => $pdo->query('SELECT id, customer_id, license_plate, brand FROM vehicles ORDER BY id')->fetchAll(),
        'repair_orders' => $pdo->query('SELECT id, vehicle_id, customer_id, order_code, customer_request, status FROM repair_orders ORDER BY id')->fetchAll(),
        'service_requests' => $pdo->query('SELECT id, user_id, full_name, phone, vehicle, service, status FROM service_requests ORDER BY id')->fetchAll(),
        'repair_order_items' => $pdo->query('SELECT id, repair_order_id, item_name, unit_price, quantity, subtotal FROM repair_order_items ORDER BY id')->fetchAll(),
    ];
}

try {
    $pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$database`");
    $sql = file_get_contents(dirname(__DIR__) . '/database/schema.sql');
    $sql = preg_replace('/CREATE DATABASE IF NOT EXISTS `redlinev1`.*?;/s', '', $sql);
    $sql = str_replace('USE `redlinev1`;', "USE `$database`;", $sql);
    $pdo->exec($sql);

    $roles = $pdo->query('SELECT id, name FROM roles ORDER BY id')->fetchAll(PDO::FETCH_KEY_PAIR);
    schema_check($roles === [1 => 'Admin', 2 => 'Receptionist', 3 => 'Technician', 4 => 'Customers', 5 => 'Cashier'],
        'A fresh installation seeds the five required roles.');
    foreach ($pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) as $table) {
        if ($table === 'roles') continue;
        schema_check((int) $pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn() === 0,
            'Fresh installations contain no business or demo records in ' . $table . '.');
    }
    schema_workshop_check($pdo);

    $upgrade = file_get_contents(dirname(__DIR__) . '/database/migrations/20261115_workshop_updates.sql');
    $fresh = schema_definitions($pdo);
    $pdo->exec($upgrade);
    $pdo->exec($upgrade);
    schema_check(schema_definitions($pdo) === $fresh, 'Applying the workshop upgrade twice leaves a fresh schema unchanged.');

    // Recreate the supported schema before the five workshop upgrades.
    $pdo->exec(<<<'SQL'
DROP TABLE technician_shifts, technician_pay_rates, repair_order_cost_reviews;
ALTER TABLE service_requests
    DROP FOREIGN KEY fk_service_requests_preferred_technician,
    DROP COLUMN preferred_technician_id;
ALTER TABLE repair_order_items DROP COLUMN part_request_id;
ALTER TABLE repair_orders MODIFY COLUMN status
    ENUM('received','diagnosing','repairing','waiting_parts','inspecting','completed','delivered','custom_review')
    NOT NULL DEFAULT 'received';
INSERT INTO users (id, role_id, username, full_name, is_customer) VALUES
    (10, 1, 'schema_admin', 'Fixture Admin', 0),
    (11, 3, 'schema_technician', 'Fixture Technician', 0),
    (12, 4, 'schema_customer', 'Fixture Customer', 1);
INSERT INTO vehicles (id, customer_id, license_plate, brand) VALUES (10, 12, 'SCHEMA-01', 'Fixture');
INSERT INTO repair_orders (id, vehicle_id, customer_id, order_code, customer_request, status)
    VALUES (10, 10, 12, 'SCHEMA-ORDER', 'Preserve this repair', 'custom_review');
INSERT INTO service_requests (id, user_id, full_name, phone, vehicle, service)
    VALUES (10, 12, 'Fixture Customer', '0901234567', 'Fixture vehicle', 'Inspection');
INSERT INTO repair_order_items (id, repair_order_id, item_name, unit_price, quantity, subtotal)
    VALUES (10, 10, 'Saved labor', 250, 2, 500);
SQL);
    $business = schema_business_rows($pdo);
    $pdo->exec($upgrade);
    schema_workshop_check($pdo);
    schema_check(schema_business_rows($pdo) === $business, 'Workshop upgrades preserve existing identities, vehicles, bookings and billing rows.');
    $status = $pdo->query("SHOW COLUMNS FROM repair_orders LIKE 'status'")->fetch();
    schema_check(str_contains($status['Type'], "'custom_review'"), 'Adding cancellation preserves custom repair statuses.');
    $upgraded = schema_definitions($pdo);
    $pdo->exec($upgrade);
    schema_check(schema_definitions($pdo) === $upgraded && schema_business_rows($pdo) === $business,
        'Repeating an upgrade preserves schema and business data.');

    // Existing columns must not prevent repair of missing indexes and constraints.
    $pdo->exec(<<<'SQL'
ALTER TABLE service_requests
    DROP FOREIGN KEY fk_service_requests_preferred_technician,
    DROP INDEX idx_service_requests_preferred_technician;
ALTER TABLE repair_order_items DROP INDEX uq_repair_item_part_request;
SQL);
    $pdo->exec($upgrade);
    schema_workshop_check($pdo);
    schema_check(schema_definitions($pdo) === $upgraded && schema_business_rows($pdo) === $business,
        'Rerunning a partial upgrade restores missing indexes and foreign keys without changing saved data.');

    echo "PASS: $checks database schema checks\n";
} finally {
    $pdo->exec("DROP DATABASE IF EXISTS `$database`");
}
