<?php
/** Booking HTTP regression checks against an isolated MySQL database. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
require_once $root . '/includes/service_catalog.php';
$database = 'redline_booking_test_' . bin2hex(random_bytes(5));
$fixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $database;
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$server = null;
$checks = 0;
function booking_check(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    ++$checks;
}
function booking_request(string $path, ?array $data = null, string $session = 'customer'): array {
    global $port;
    $context = stream_context_create(['http' => [
        'method' => $data === null ? 'GET' : 'POST', 'ignore_errors' => true, 'follow_location' => 0,
        'header' => "Cookie: PHPSESSID=booking$session\r\nContent-Type: application/x-www-form-urlencoded\r\nConnection: close\r\n",
        'content' => $data === null ? '' : http_build_query($data), 'timeout' => 10,
    ]]);
    $stream = fopen("http://127.0.0.1:$port/$path", 'r', false, $context);
    if (!$stream) throw new RuntimeException('HTTP request failed: ' . $path);
    $headers = stream_get_meta_data($stream)['wrapper_data'];
    $body = stream_get_contents($stream);
    fclose($stream);
    preg_match('/\s(\d{3})\s/', $headers[0], $m);
    return [(int) ($m[1] ?? 0), $body];
}
try {
    $pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$database`");
    // Only the fields needed by authentication and booking, including the older
    // service_requests schema that has no optional website detail columns.
    $pdo->exec(<<<'SQL'
CREATE TABLE roles (id INT PRIMARY KEY, name VARCHAR(30));
INSERT INTO roles VALUES (1, 'Admin'), (4, 'Customers'), (5, 'Cashier');
CREATE TABLE users (
    id INT PRIMARY KEY, role_id INT NULL, username VARCHAR(50), password_hash VARCHAR(255),
    full_name VARCHAR(150), email VARCHAR(190), phone VARCHAR(30), is_active TINYINT DEFAULT 1,
    is_customer TINYINT DEFAULT 1, zalo VARCHAR(30), contact_method VARCHAR(10) DEFAULT 'phone'
);
INSERT INTO users (id, role_id, username, password_hash, full_name, email, phone, is_customer) VALUES
    (1, 1, 'admin', 'test', 'Booking Admin', 'admin@example.test', '0901111111', 0),
    (2, 4, NULL, 'test', 'Booking Customer', 'booking@example.test', '0902222222', 1),
    (3, 4, NULL, 'test', 'Other Customer', 'other@example.test', '0903333333', 1);
CREATE TABLE portal_vehicles (
    id INT PRIMARY KEY, user_id INT, make VARCHAR(60), model VARCHAR(80), year SMALLINT,
    plate VARCHAR(20), vin VARCHAR(50), mileage INT, color VARCHAR(40), photo VARCHAR(255)
);
INSERT INTO portal_vehicles VALUES
    (10, 2, 'Test Make', 'Booking Car', 2024, 'BOOK-100', 'VINBOOK12345', 35000, 'White', NULL),
    (11, 3, 'Other Make', 'Other Car', 2023, 'OTHER-100', NULL, 2000, 'Black', NULL);
CREATE TABLE vehicles (id INT PRIMARY KEY, customer_id INT, brand VARCHAR(60), model VARCHAR(80), license_plate VARCHAR(20));
CREATE TABLE service_orders (id INT PRIMARY KEY, vehicle_id INT, title VARCHAR(100), status VARCHAR(30), estimate INT, notes TEXT, updated_at DATETIME);
CREATE TABLE repair_orders (id INT PRIMARY KEY, order_code VARCHAR(20), customer_id INT, vehicle_id INT, service_request_id INT UNIQUE, assigned_to INT, service_type VARCHAR(50), status VARCHAR(30), estimated_cost INT, symptoms TEXT, expected_delivery DATETIME, updated_at DATETIME);
CREATE TABLE invoices (id INT PRIMARY KEY, order_id INT);
CREATE TABLE order_status_history (id INT PRIMARY KEY, order_id INT, from_status VARCHAR(30), to_status VARCHAR(30), note TEXT);
CREATE TABLE service_requests (
    id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, full_name VARCHAR(120), phone VARCHAR(30),
    license_plate VARCHAR(20), vehicle VARCHAR(160), vin_number VARCHAR(50), service_type VARCHAR(50),
    service VARCHAR(100) NOT NULL, message TEXT, ip_address VARCHAR(45),
    status ENUM('pending','processed','cancelled') DEFAULT 'pending', created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);
SQL);
    foreach (['config/config.php', 'config/constants.php', 'includes/functions.php', 'includes/session.php', 'customer/index.php', 'admin/appointments/list.php', 'admin/orders/create.php', 'assets/css/dashboard.css', 'includes/auth.php', 'includes/customer_vehicle_sync.php', 'includes/customer_service_history.php', 'includes/service_catalog.php', 'config/database.php', 'api/appointments/book.php'] as $file) {
        $dest = $fixture . '/' . $file;
        if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);
        $source = file_get_contents($root . '/' . $file);
        if ($file === 'config/database.php') $source = str_replace("const DB_NAME = 'redlinev1';", "const DB_NAME = '$database';", $source);
        file_put_contents($dest, $source);
    }
    file_put_contents($fixture . '/session.php', '<?php session_start(); $_SESSION = ["_identity_schema" => 2, "_csrf" => "booking-test-token"]; if (isset($_GET["staff"])) { $_SESSION += ["user_id" => 1, "username" => "admin", "role" => "Admin"]; } else { $_SESSION["portal_user_id"] = 2; } echo "ok";');
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    $server = proc_open([PHP_BINARY, '-c', php_ini_loaded_file(), '-d', 'display_errors=1', '-d', "session.save_path=$fixture", '-S', "127.0.0.1:$port", '-t', $fixture], [['pipe', 'r'], ['file', $fixture . '/server.log', 'a'], ['file', $fixture . '/server.log', 'a']], $pipes);
    if (!is_resource($server)) throw new RuntimeException('Could not start test server.');
    for ($attempt = 0; $attempt < 50; ++$attempt) {
        $socket = @fsockopen('127.0.0.1', $port);
        if ($socket) { fclose($socket); break; }
        usleep(100000);
    }
    $api = 'api/appointments/book.php';
    $data = ['csrf' => 'booking-test-token', 'service' => 'Scheduled Maintenance', 'vehicle_id' => '10', 'date' => date('Y-m-d', strtotime('+2 days')), 'time' => '14:30'];
    booking_check(booking_request($api)[0] === 405, 'GET cannot create a booking.');
    booking_check(booking_request($api, $data, 'guest')[0] === 401, 'Guest cannot create a booking.');
    booking_request('session.php');
    booking_request('session.php?staff=1', null, 'staff');
    booking_check(booking_request($api, array_replace($data, ['csrf' => 'invalid']))[0] === 403, 'Invalid CSRF is rejected.');
    foreach ([['vehicle_id' => 11], ['vehicle_id' => 999], ['service' => 'Invalid'], ['date' => '2026-02-30'], ['date' => '2000-01-01'], ['time' => '23:00'], ['date' => ['bad']]] as $invalid) {
        booking_check(booking_request($api, array_replace($data, $invalid))[0] === 422, 'Invalid booking input is rejected: ' . json_encode($invalid));
    }
    booking_check((int) $pdo->query('SELECT COUNT(*) FROM service_requests')->fetchColumn() === 0, 'Rejected requests leave the inbox empty.');
    $pdo->exec("UPDATE users SET phone = '' WHERE id = 2");
    booking_check(booking_request($api, $data)[0] === 422, 'Missing contact information is rejected.');
    $pdo->exec("UPDATE users SET phone = '0902222222' WHERE id = 2");
    [$status, $body] = booking_request($api, $data + ['user_id' => 3, 'full_name' => 'Spoofed Name', 'license_plate' => 'SPOOF']);
    $result = json_decode($body, true);
    booking_check($status === 201 && !empty($result['ok']) && !empty($result['id']), 'Successful booking returns a saved ID as JSON.');
    $row = $pdo->query('SELECT * FROM service_requests')->fetch();
    booking_check((int) $row['user_id'] === 2 && $row['full_name'] === 'Booking Customer' && $row['phone'] === '0902222222', 'Booking uses authenticated profile details.');
    booking_check($row['license_plate'] === 'BOOK-100' && $row['vin_number'] === 'VINBOOK12345' && $row['vehicle'] === 'Test Make Booking Car 2024', 'Booking uses the owned garage vehicle.');
    booking_check($row['status'] === 'pending' && $row['service'] === $data['service'] && str_contains($row['message'], $data['date'] . ' at 14:30'), 'Pending booking retains service and preferred schedule.');
    [$status, $html] = booking_request('admin/appointments/list.php', null, 'staff');
    booking_check($status === 200 && str_contains($html, 'Booking Customer') && str_contains($html, 'BOOK-100') && str_contains($html, $data['date']), 'Dashboard booking appears in the real staff appointments inbox.');

    $pdo->exec('ALTER TABLE service_requests ADD email VARCHAR(190), ADD zalo VARCHAR(30), ADD contact_method VARCHAR(10), ADD make VARCHAR(60), ADD model VARCHAR(80), ADD year SMALLINT, ADD mileage INT, ADD color VARCHAR(40), ADD photo_path VARCHAR(255)');
    booking_check(booking_request($api, $data)[0] === 201, 'Booking also supports the expanded website schema.');
    $row = $pdo->query('SELECT * FROM service_requests ORDER BY id DESC LIMIT 1')->fetch();
    booking_check($row['email'] === 'booking@example.test' && $row['make'] === 'Test Make' && (int) $row['mileage'] === 35000 && $row['color'] === 'White', 'Optional contact and vehicle details are preserved.');
    $pdo->exec('UPDATE users SET is_active = 0 WHERE id = 2');
    booking_check(booking_request($api, $data)[0] === 401, 'Disabled customer cannot book.');
    $pdo->exec('UPDATE users SET is_active = 1 WHERE id = 2');
    booking_request('session.php');
    [$status, $html] = booking_request('customer/index.php');
    booking_check($status === 200 && str_contains($html, 'data-vehicle-id="10"') && !str_contains($html, 'Fatal error'), 'Real dashboard renders with the saved booking vehicle: ' . substr(strip_tags($html), 0, 1500));
    foreach (rl_service_catalog() as $value => $label) {
        $encoded = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        booking_check(str_contains($html, 'value="' . $encoded . '"'), 'Dashboard offers ' . $value);
        [$status, $body] = booking_request($api, array_replace($data, ['service' => $value]));
        $id = json_decode($body, true)['id'] ?? 0;
        booking_check($status === 201 && $id > 0, 'Dashboard can book ' . $value);
        $stored = $pdo->query('SELECT service_type FROM service_requests WHERE id = ' . (int) $id)->fetchColumn();
        booking_check($stored === $value, 'Booking preserves the service code for ' . $value);
        [$status, $intake] = booking_request('admin/orders/create.php?appointment_id=' . $id, null, 'staff');
        booking_check($status === 200 && str_contains($intake, '<option value="' . $encoded . '" selected>'), 'Intake preselects ' . $value);
    }
    $pdo->exec("UPDATE service_requests SET service_type = 'Legacy custom service' WHERE id = 1");
    [$status, $intake] = booking_request('admin/orders/create.php?appointment_id=1', null, 'staff');
    booking_check($status === 200 && str_contains($intake, '<option value="Legacy custom service" selected>'), 'Intake preserves services from older appointments.');
    echo "PASS: $checks booking integration checks\n";
    // Optional manual browser QA on the same disposable fixture; press Enter to clean up.
    if (in_array('--serve', $argv, true)) {
        echo "Fixture: http://127.0.0.1:$port (customer cookie: PHPSESSID=bookingcustomer)\n";
        fgets(STDIN);
    }
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    $pdo->exec("DROP DATABASE IF EXISTS `$database`");
    $tempRoot = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR;
    $resolved = realpath($fixture);
    // Delete only this test's verified, uniquely named temporary directory.
    if ($resolved && str_starts_with($resolved, $tempRoot) && basename($resolved) === $database) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir($resolved);
    }
}
