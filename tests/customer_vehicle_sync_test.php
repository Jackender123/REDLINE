<?php
/** Integration test: creates and removes an isolated MySQL database and HTTP fixture. */
declare(strict_types=1);

$root = dirname(__DIR__);
$database = 'redline_sync_test_' . bin2hex(random_bytes(5));
$fixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $database;
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$server = null;
$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    ++$checks;
}
function request(string $path, ?array $data = null, string $session = 'customer'): array {
    global $port;
    $context = stream_context_create(['http' => [
        'method' => $data === null ? 'GET' : 'POST', 'ignore_errors' => true, 'follow_location' => 0,
        'header' => "Cookie: PHPSESSID=sync$session\r\nContent-Type: application/x-www-form-urlencoded\r\nConnection: close\r\n",
        'content' => $data === null ? '' : http_build_query($data), 'timeout' => 10,
    ]]);
    $stream = fopen("http://127.0.0.1:$port/$path", 'r', false, $context);
    if (!$stream) throw new RuntimeException('HTTP request failed: ' . $path);
    $headers = stream_get_meta_data($stream)['wrapper_data'];
    $body = stream_get_contents($stream);
    fclose($stream);
    preg_match('/\s(\d{3})\s/', $headers[0] ?? '', $m);
    return [(int) ($m[1] ?? 0), (string) $body, $headers];
}
function token(string $html): string {
    preg_match('/name="csrf" value="([^"]+)"/', $html, $m);
    if (empty($m[1])) throw new RuntimeException('Missing CSRF token: ' . substr($html, 0, 500));
    return $m[1];
}
try {
    $pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$database`");
    $pdo->exec(file_get_contents($root . '/www/database/redlinev1.sql'));
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach (['technical_inspections', 'work_order_images', 'part_requests', 'invoice_items', 'cash_transactions'] as $table) {
        check(in_array($table, $tables, true), 'Single SQL import includes ' . $table . '.');
    }
    $columns = $pdo->query('SHOW COLUMNS FROM customers')->fetchAll(PDO::FETCH_COLUMN);
    check(in_array('portal_user_id', $columns, true) && in_array('contact_method', $columns, true), 'Single SQL import includes customer sync fields.');
    $columns = $pdo->query('SHOW COLUMNS FROM portal_vehicles')->fetchAll(PDO::FETCH_COLUMN);
    check(in_array('workshop_vehicle_id', $columns, true) && in_array('battery_soh', $columns, true), 'Single SQL import includes garage sync fields.');
    $columns = $pdo->query('SHOW COLUMNS FROM service_requests')->fetchAll(PDO::FETCH_COLUMN);
    check(count(array_intersect(['email', 'zalo', 'contact_method', 'make', 'model', 'year', 'mileage', 'color', 'fuel_level', 'photo_path'], $columns)) === 10, 'Single SQL import includes every booking form field.');
    $columns = $pdo->query('SHOW COLUMNS FROM repair_orders')->fetchAll(PDO::FETCH_COLUMN);
    check(in_array('service_request_id', $columns, true), 'Single SQL import includes the website booking link.');
    foreach (['dashboard.php', 'repair_orders_create.php', 'src/includes/auth.php', 'src/includes/customer_vehicle_sync.php', 'src/modules/gara-management/api/orders/create.php', 'src/config/db.php'] as $file) {
        $dest = $fixture . '/' . $file;
        if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);
        $content = file_get_contents($root . '/www/' . $file);
        if ($file === 'src/config/db.php') $content = str_replace("const DB_NAME = 'redlinev1';", "const DB_NAME = '$database';", $content);
        file_put_contents($dest, $content);
    }
    file_put_contents($fixture . '/session.php', '<?php session_start(); $_SESSION = []; if (($_GET["role"] ?? "") === "staff") { $_SESSION["user_id"]=1; $_SESSION["username"]="admin"; $_SESSION["role"]="Admin"; } else { $_SESSION["portal_user_id"] = (int) ($_GET["id"] ?? 1); } echo "ok";');
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    $server = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-d', "session.save_path=$fixture", '-S', "127.0.0.1:$port", '-t', $fixture], [['pipe', 'r'], ['file', $fixture . '/server.log', 'a'], ['file', $fixture . '/server.log', 'a']], $pipes);
    if (!is_resource($server)) throw new RuntimeException('Could not start test HTTP server.');
    for ($attempt = 0; $attempt < 50; ++$attempt) {
        $socket = @fsockopen('127.0.0.1', $port);
        if ($socket) { fclose($socket); break; }
        usleep(100000);
    }
    request('session.php');
    request('session.php?role=staff', null, 'staff');
    [$status, $html] = request('dashboard.php?page=profile');
    check($status === 200 && !str_contains($html, 'Fatal error'), 'Profile renders and schema upgrades succeed.');
    $csrf = token($html);
    $profile = ['csrf' => $csrf, 'action' => 'update_profile', 'full_name' => 'Sync Customer', 'phone' => '+84 901 234 567', 'email' => 'sync@example.test', 'zalo' => '0901234567', 'contact_method' => 'zalo'];
    check(request('dashboard.php?page=profile', $profile)[0] === 302, 'Profile save redirects.');
    $customer = $pdo->query('SELECT * FROM customers WHERE portal_user_id = 1')->fetch();
    check($customer && $customer['full_name'] === 'Sync Customer' && $customer['contact_method'] === 'zalo', 'Profile details reach the workshop customer.');
    $customerId = (int) $customer['id'];
    $vehicle = ['csrf' => $csrf, 'action' => 'add_vehicle', 'make' => 'Test Make', 'model' => 'Sync EV', 'plate' => 'SYNC-100', 'year' => '2024', 'mileage' => '35000', 'color' => 'White', 'vin' => 'VIN123456789012345', 'engine_type' => 'ev', 'battery_capacity' => '75.5', 'battery_soh' => '97.5'];
    check(request('dashboard.php?page=vehicles', $vehicle)[0] === 302, 'Garage vehicle save redirects.');
    $v = $pdo->query("SELECT * FROM portal_vehicles WHERE plate = 'SYNC-100'")->fetch();
    check($v && $v['workshop_vehicle_id'], 'Garage vehicle receives a stable workshop link.');
    $vid = (int) $v['id'];
    $workshopId = (int) $v['workshop_vehicle_id'];
    $w = $pdo->query("SELECT * FROM vehicles WHERE id = $workshopId")->fetch();
    check($w['brand'] === 'Test Make' && $w['vin'] === $vehicle['vin'] && (int) $w['odometer'] === 35000 && $w['engine_type'] === 'ev', 'Garage details reach the workshop vehicle.');
    check((float) $pdo->query("SELECT battery_capacity FROM vehicle_ev_details WHERE vehicle_id = $workshopId")->fetchColumn() === 75.5, 'EV battery details sync.');
    $profile['phone'] = '0909999999';
    request('dashboard.php?page=profile', $profile);
    check((int) $pdo->query('SELECT id FROM customers WHERE portal_user_id = 1')->fetchColumn() === $customerId, 'Changing the profile phone keeps the same customer ID.');
    $vehicle['action'] = 'update_vehicle'; $vehicle['id'] = $vid; $vehicle['plate'] = 'SYNC-101';
    request('dashboard.php?page=vehicles', $vehicle);
    check($pdo->query("SELECT license_plate FROM vehicles WHERE id = $workshopId")->fetchColumn() === 'SYNC-101', 'Changing a garage plate updates the existing workshop vehicle.');

    $intakeUrl = "repair_orders_create.php?portal_user_id=1&portal_vehicle_id=$vid";
    [$status, $html] = request($intakeUrl, null, 'staff');
    check($status === 200 && str_contains($html, 'value="Sync EV"') && str_contains($html, 'value="0909999999"') && str_contains($html, 'value="35000"'), 'Intake prefills the current profile and garage vehicle.');
    $staffCsrf = token($html);
    $intake = ['csrf' => $staffCsrf, 'portal_user_id' => 1, 'portal_vehicle_id' => $vid, 'customer_name' => 'Reception Updated', 'customer_phone' => '0908888888', 'customer_email' => 'updated@example.test', 'customer_zalo' => '0901234567', 'contact_method' => 'email', 'license_plate' => 'SYNC-102', 'vehicle_brand' => 'Test Make', 'vehicle_model' => 'Updated EV', 'vehicle_year' => '2025', 'vehicle_color' => 'Blue', 'km_in' => '36000', 'vin_number' => 'VIN987654321012345', 'engine_type' => 'hybrid', 'battery_capacity' => '20', 'battery_soh' => '95', 'fuel_level' => '1/2', 'service_type' => 'OIL & FILTER CHANGE', 'customer_request' => 'Sync integration test', 'symptoms' => 'Test notes'];
    $api = 'src/modules/gara-management/api/orders/create.php';
    [$status, $body] = request($api, $intake, 'staff');
    check($status === 302, 'Intake saves successfully: ' . $body);
    $u = $pdo->query('SELECT * FROM portal_users WHERE id = 1')->fetch();
    $v = $pdo->query("SELECT * FROM portal_vehicles WHERE id = $vid")->fetch();
    check($u['full_name'] === 'Reception Updated' && $u['phone'] === '0908888888' && $u['email'] === 'updated@example.test' && $u['contact_method'] === 'email', 'Intake contact changes return to the profile.');
    check($v['model'] === 'Updated EV' && $v['color'] === 'Blue' && (int) $v['mileage'] === 36000 && $v['engine_type'] === 'hybrid', 'Intake changes return to the garage.');
    check((int) $v['workshop_vehicle_id'] === $workshopId && (int) $pdo->query("SELECT customer_id FROM vehicles WHERE id = $workshopId")->fetchColumn() === $customerId, 'Intake preserves both stable links.');
    check((float) $pdo->query("SELECT battery_capacity FROM vehicle_ev_details WHERE vehicle_id = $workshopId")->fetchColumn() === 20.0, 'Hybrid battery details sync without being deleted.');
    [$status, $html] = request('dashboard.php?page=vehicles');
    check($status === 200 && str_contains($html, 'Updated EV') && str_contains($html, 'Test notes'), 'Garage displays synced details and workshop service history.');
    request('dashboard.php?page=vehicles', ['csrf' => $csrf, 'action' => 'delete_vehicle', 'id' => $vid]);
    check((int) $pdo->query("SELECT COUNT(*) FROM portal_vehicles WHERE id = $vid")->fetchColumn() === 1, 'Garage deletion is blocked by an active workshop repair.');

    $pdo->exec("INSERT INTO portal_vehicles (user_id, make, model, plate) VALUES (3, 'Other', 'Owner', 'OTHER-100')");
    $foreignVid = (int) $pdo->lastInsertId();
    $bad = $intake; $bad['portal_vehicle_id'] = $foreignVid; $bad['customer_name'] = 'Should roll back';
    check(request($api, $bad, 'staff')[0] === 500, 'A vehicle belonging to another account is rejected.');
    check($pdo->query('SELECT full_name FROM portal_users WHERE id = 1')->fetchColumn() === 'Reception Updated', 'Failed intake rolls back profile changes.');
    check(request($api, $intake, 'customer')[0] === 302, 'Customer session cannot use the staff intake endpoint.');
    $bad = $intake; $bad['csrf'] = 'invalid';
    check(request($api, $bad, 'staff')[0] === 403, 'Invalid intake CSRF is rejected.');
    $bad = $intake; $bad['km_in'] = '-1';
    check(request($api, $bad, 'staff')[0] === 422, 'Invalid mileage is rejected.');

    $intake['km_in'] = '';
    check(request($api, $intake, 'staff')[0] === 302, 'An omitted odometer is accepted.');
    check((int) $pdo->query("SELECT odometer FROM vehicles WHERE id = $workshopId")->fetchColumn() === 36000, 'An omitted odometer preserves the saved mileage.');
    $intake['engine_type'] = 'diesel';
    check(request($api, $intake, 'staff')[0] === 302, 'Diesel intake succeeds.');
    check((int) $pdo->query("SELECT COUNT(*) FROM vehicle_ev_details WHERE vehicle_id = $workshopId")->fetchColumn() === 0, 'Switching away from EV/hybrid removes stale battery details.');

    // Existing appointments keep their service request but load current shared details.
    $pdo->prepare('INSERT INTO service_requests (user_id, full_name, phone, license_plate, vehicle, service_type, service) VALUES (1, ?, ?, ?, ?, ?, ?)')
        ->execute(['Old booking name', '0900000000', 'SYNC-102', 'Old booking model', 'ENGINE DIAGNOSTIC SCAN', 'Check engine']);
    $appointmentId = (int) $pdo->lastInsertId();
    [$status, $html] = request("repair_orders_create.php?appointment_id=$appointmentId", null, 'staff');
    check($status === 200 && str_contains($html, 'value="Reception Updated"') && str_contains($html, 'value="Updated EV"') && !str_contains($html, 'value="Old booking name"'), 'Appointment intake uses current profile and garage details.');
    $booked = $intake; $booked['appointment_id'] = $appointmentId;
    [$status, $body] = request($api, $booked, 'staff');
    check($status === 302, 'Appointment intake saves: ' . $body);
    check($pdo->query("SELECT status FROM service_requests WHERE id = $appointmentId")->fetchColumn() === 'processed', 'Appointment intake marks the booking processed.');
    $booked['customer_name'] = 'Duplicate booking rollback';
    check(request($api, $booked, 'staff')[0] === 500, 'Duplicate appointment intake is rejected.');
    check($pdo->query('SELECT full_name FROM portal_users WHERE id = 1')->fetchColumn() === 'Reception Updated', 'Duplicate appointment rejection rolls back profile changes.');
    $booked['portal_user_id'] = 3;
    check(request($api, $booked, 'staff')[0] === 500, 'An appointment cannot be reassigned through hidden customer fields.');

    // Keep walk-ins working, without creating a customer login account.
    $walkin = $intake;
    $walkin['portal_user_id'] = 0; $walkin['portal_vehicle_id'] = 0;
    $walkin['customer_name'] = 'Walk In'; $walkin['customer_phone'] = '0904444444';
    $walkin['customer_email'] = ''; $walkin['customer_zalo'] = ''; $walkin['contact_method'] = 'phone';
    $walkin['license_plate'] = 'WALK-100'; $walkin['km_in'] = '1000';
    check(request($api, $walkin, 'staff')[0] === 302, 'Walk-in intake remains supported.');
    check((int) $pdo->query("SELECT COUNT(*) FROM customers WHERE phone = '0904444444' AND portal_user_id IS NULL")->fetchColumn() === 1, 'Walk-in intake keeps a standalone workshop customer.');

    // Legacy records link by normalized contact/plate and retain workshop-only metadata.
    $pdo->exec("INSERT INTO portal_users (full_name, email, phone, password_hash) VALUES ('Legacy Customer', 'legacy@example.test', '090 555 5555', 'unused')");
    $legacyUserId = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO customers (full_name, phone, email) VALUES ('Legacy Workshop', '0905555555', 'legacy@example.test')");
    $legacyCustomerId = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO vehicles (customer_id, license_plate, brand, model, vin, year, color, odometer, engine_type, is_ev) VALUES ($legacyCustomerId, 'LEGACY100', 'Legacy', 'EV', 'LEGACY-VIN', 2021, 'Silver', 42000, 'ev', 1)");
    $legacyWorkshopId = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO vehicle_ev_details (vehicle_id, battery_capacity, battery_soh) VALUES ($legacyWorkshopId, 80, 90)");
    request("session.php?id=$legacyUserId", null, 'legacy');
    [, $html] = request('dashboard.php?page=vehicles', null, 'legacy');
    $legacyVehicle = ['csrf' => token($html), 'action' => 'add_vehicle', 'make' => 'Legacy', 'model' => 'EV', 'plate' => 'LEGACY-100'];
    request('dashboard.php?page=vehicles', $legacyVehicle, 'legacy');
    $v = $pdo->query("SELECT * FROM portal_vehicles WHERE user_id = $legacyUserId")->fetch();
    check($v && (int) $v['workshop_vehicle_id'] === $legacyWorkshopId, 'Legacy normalized phone and plate link to existing workshop records.');
    check($v['vin'] === 'LEGACY-VIN' && (int) $v['year'] === 2021 && $v['color'] === 'Silver' && (int) $v['mileage'] === 42000, 'Legacy linking retains VIN, year, color and mileage.');
    check($v['engine_type'] === 'ev' && (float) $v['battery_capacity'] === 80.0 && (float) $v['battery_soh'] === 90.0, 'Legacy linking retains powertrain and battery data.');

    // Cross-customer plate collisions must not update either customer or vehicle.
    $vehicle['plate'] = 'LEGACY100'; $vehicle['model'] = 'Collision rollback';
    request('dashboard.php?page=vehicles', $vehicle);
    check($pdo->query("SELECT model FROM portal_vehicles WHERE id = $vid")->fetchColumn() === 'Updated EV', 'A plate collision rolls back the garage update.');

    echo "PASS: $checks integration checks\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    $pdo->exec("DROP DATABASE IF EXISTS `$database`");
    // Only remove the uniquely named fixture created above.
    if (is_dir($fixture) && str_starts_with(basename($fixture), 'redline_sync_test_')) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $file) { $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir($fixture);
    }
}
