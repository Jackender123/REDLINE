<?php
/** Integration test: creates and removes an isolated MySQL database and HTTP fixture. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$root = dirname(__DIR__, 2);
$database = 'redline_sync_test_' . bin2hex(random_bytes(5));
$fixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $database;
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$server = null;
$checks = 0;
$sessionCookies = [];
function check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    ++$checks;
}
function request(string $path, ?array $data = null, string $session = 'customer'): array {
    global $port, $sessionCookies;
    $cookie = $sessionCookies[$session] ?? 'sync' . $session;
    $context = stream_context_create(['http' => [
        'method' => $data === null ? 'GET' : 'POST', 'ignore_errors' => true, 'follow_location' => 0,
        'header' => "Cookie: PHPSESSID=$cookie\r\nContent-Type: application/x-www-form-urlencoded\r\nConnection: close\r\n",
        'content' => $data === null ? '' : http_build_query($data), 'timeout' => 10,
    ]]);
    $stream = fopen("http://127.0.0.1:$port/$path", 'r', false, $context);
    if (!$stream) throw new RuntimeException('HTTP request failed: ' . $path);
    $headers = stream_get_meta_data($stream)['wrapper_data'];
    foreach ($headers as $header) {
        if (preg_match('/^Set-Cookie: PHPSESSID=([^;]+)/i', $header, $cookieMatch)) $sessionCookies[$session] = $cookieMatch[1];
    }
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
    $sql = file_get_contents($root . '/www/database/redlinev1.sql');
    $sql = preg_replace('/CREATE DATABASE IF NOT EXISTS `redlinev1`.*?;/s', '', $sql);
    $sql = str_replace('USE `redlinev1`;', "USE `$database`;", $sql);
    $pdo->exec($sql);
    // The setup SQL may contain schema only. Seed this test's own identities.
    if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
        $pdo->exec("INSERT INTO roles (id, name) VALUES (1, 'Admin'), (2, 'Receptionist'), (3, 'Technician'), (4, 'Cashier')");
        $pdo->exec("INSERT INTO users (id, role_id, username, password_hash, full_name, email, phone, is_customer) VALUES
            (1, 1, 'admin', 'test', 'Test Administrator', 'admin@gara.com', '0901111111', 0),
            (4, 2, 'staffcustomer', 'test', 'Staff Customer', 'staffcustomer@example.test', '0902222222', 1),
            (7, NULL, NULL, 'test', 'Test Customer', 'customer@example.test', '0903333333', 1)");
    }
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $pdo->exec("INSERT INTO services (name, price) VALUES ('OIL & FILTER CHANGE', 185500)");
    $pricedServiceId = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO part_categories (name) VALUES ('Intake test parts')");
    $testCategoryId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO parts (category_id, part_code, name, selling_price, stock_quantity) VALUES (?, ?, ?, ?, ?)')
        ->execute([$testCategoryId, 'SYNC-TEST-FILTER', 'Test Oil Filter', 75250, 10]);
    $pricedPartId = (int) $pdo->lastInsertId();
    foreach (['technical_inspections', 'work_order_images', 'part_requests', 'invoice_items', 'cash_transactions'] as $table) {
        check(in_array($table, $tables, true), 'Single SQL import includes ' . $table . '.');
    }
    check(!in_array('customers', $tables, true) && !in_array('portal_users', $tables, true), 'Fresh installs have only one identity table.');
    $columns = $pdo->query('SHOW COLUMNS FROM users')->fetchAll(PDO::FETCH_COLUMN);
    check(in_array('is_customer', $columns, true) && in_array('contact_method', $columns, true) && in_array('password_hash', $columns, true), 'Users includes customer and login fields.');
    $columns = $pdo->query('SHOW COLUMNS FROM portal_vehicles')->fetchAll(PDO::FETCH_COLUMN);
    check(in_array('workshop_vehicle_id', $columns, true) && in_array('battery_soh', $columns, true), 'Single SQL import includes garage sync fields.');
    $columns = $pdo->query('SHOW COLUMNS FROM service_requests')->fetchAll(PDO::FETCH_COLUMN);
    check(count(array_intersect(['email', 'zalo', 'contact_method', 'make', 'model', 'year', 'mileage', 'color', 'fuel_level', 'photo_path'], $columns)) === 10, 'Single SQL import includes every booking form field.');
    $columns = $pdo->query('SHOW COLUMNS FROM repair_orders')->fetchAll(PDO::FETCH_COLUMN);
    check(in_array('service_request_id', $columns, true), 'Single SQL import includes the website booking link.');
    foreach (['dashboard.php', 'repair_orders_create.php', 'repair_orders_detail.php', 'login.php', 'register.php', 'staff.php', 'customers.php', 'src/includes/auth.php', 'src/includes/customer_vehicle_sync.php', 'src/includes/service_catalog.php', 'src/modules/gara-management/api/orders/create.php', 'src/modules/gara-management/api/orders/items.php', 'src/modules/gara-management/api/auth/login.php', 'src/modules/gara-management/api/auth/me.php', 'src/modules/gara-management/api/customers/list.php', 'src/modules/gara-management/api/customers/create.php', 'src/modules/gara-management/includes/functions.php', 'src/modules/gara-management/includes/auth_check.php', 'src/config/db.php'] as $file) {
        $dest = $fixture . '/' . $file;
        if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);
        $content = file_get_contents($root . '/www/' . $file);
        if ($file === 'src/config/db.php') $content = str_replace("const DB_NAME = 'redlinev1';", "const DB_NAME = '$database';", $content);
        file_put_contents($dest, $content);
    }
    file_put_contents($fixture . '/session.php', '<?php session_start(); $_SESSION = ["_identity_schema" => 2]; if (($_GET["role"] ?? "") === "staff") { $_SESSION["user_id"]=1; $_SESSION["username"]="admin"; $_SESSION["role"]="Admin"; } else { $_SESSION["portal_user_id"] = (int) ($_GET["id"] ?? 7); } echo "ok";');
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
    fclose($socket);
    $server = proc_open([PHP_BINARY, '-c', php_ini_loaded_file(), '-d', 'display_errors=1', '-d', "session.save_path=$fixture", '-S', "127.0.0.1:$port", '-t', $fixture], [['pipe', 'r'], ['file', $fixture . '/server.log', 'a'], ['file', $fixture . '/server.log', 'a']], $pipes);
    if (!is_resource($server)) throw new RuntimeException('Could not start test HTTP server.');
    for ($attempt = 0; $attempt < 50; ++$attempt) {
        $socket = @fsockopen('127.0.0.1', $port);
        if ($socket) { fclose($socket); break; }
        usleep(100000);
    }
    request('session.php');
    request('session.php?role=staff', null, 'staff');
    [$status, $html] = request('dashboard.php?page=profile');
    check($status === 200 && !str_contains($html, 'Fatal error'), 'Profile renders and schema upgrades succeed (HTTP ' . $status . '): ' . substr(strip_tags($html), 0, 1500));
    $csrf = token($html);
    $profile = ['csrf' => $csrf, 'action' => 'update_profile', 'full_name' => 'Sync Customer', 'phone' => '+84 901 234 567', 'email' => 'sync@example.test', 'zalo' => '0901234567', 'contact_method' => 'zalo'];
    check(request('dashboard.php?page=profile', $profile)[0] === 302, 'Profile save redirects.');
    $customer = $pdo->query('SELECT * FROM users WHERE id = 7')->fetch();
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
    check((int) $pdo->query('SELECT id FROM users WHERE id = 7')->fetchColumn() === $customerId, 'Changing the profile phone keeps the same customer ID.');
    $vehicle['action'] = 'update_vehicle'; $vehicle['id'] = $vid; $vehicle['plate'] = 'SYNC-101';
    request('dashboard.php?page=vehicles', $vehicle);
    check($pdo->query("SELECT license_plate FROM vehicles WHERE id = $workshopId")->fetchColumn() === 'SYNC-101', 'Changing a garage plate updates the existing workshop vehicle.');

    $intakeUrl = "repair_orders_create.php?portal_user_id=7&portal_vehicle_id=$vid";
    [$status, $html] = request($intakeUrl, null, 'staff');
    check($status === 200 && str_contains($html, 'value="Sync EV"') && str_contains($html, 'value="0909999999"') && str_contains($html, 'value="35000"'), 'Intake prefills the current profile and garage vehicle.');
    $staffCsrf = token($html);
    $intake = ['csrf' => $staffCsrf, 'portal_user_id' => 7, 'portal_vehicle_id' => $vid, 'customer_name' => 'Reception Updated', 'customer_phone' => '0908888888', 'customer_email' => 'updated@example.test', 'customer_zalo' => '0901234567', 'contact_method' => 'email', 'license_plate' => 'SYNC-102', 'vehicle_brand' => 'Test Make', 'vehicle_model' => 'Updated EV', 'vehicle_year' => '2025', 'vehicle_color' => 'Blue', 'km_in' => '36000', 'vin_number' => 'VIN987654321012345', 'engine_type' => 'hybrid', 'battery_capacity' => '20', 'battery_soh' => '95', 'fuel_level' => '1/2', 'service_type' => 'OIL & FILTER CHANGE', 'customer_request' => 'Sync integration test', 'symptoms' => 'Test notes'];
    $api = 'src/modules/gara-management/api/orders/create.php';
    [$status, $body, $headers] = request($api, $intake, 'staff');
    check($status === 302, 'Intake saves successfully: ' . $body);
    $createdOrder = $pdo->query('SELECT id, order_code FROM repair_orders ORDER BY id DESC LIMIT 1')->fetch();
    check(in_array('Location: ../../../../../repair_orders_detail.php?id=' . $createdOrder['id'], $headers, true), 'Successful intake redirects to the order that was created.');
    [$detailStatus, $detailHtml] = request('repair_orders_detail.php?id=' . $createdOrder['id'], null, 'staff');
    check($detailStatus === 200 && str_contains($detailHtml, $createdOrder['order_code']) && !str_contains($detailHtml, 'Database Connection Error'), 'The redirected order details page displays the newly created order.');
    preg_match('/<input\b[^>]*name="item_name"[^>]*>/', $detailHtml, $itemInput);
    check(str_contains($itemInput[0] ?? '', 'value="OIL &amp; FILTER CHANGE"'), 'Item description prefills the service selected during intake.');
    check(!preg_match('/\b(?:readonly|disabled)\b/', $itemInput[0]), 'Staff can edit the suggested description before adding an item.');
    check((int) $pdo->query('SELECT COUNT(*) FROM repair_order_items WHERE repair_order_id = ' . (int) $createdOrder['id'])->fetchColumn() === 0, 'Opening the quote does not automatically add charges before staff review.');
    preg_match('/<input\b[^>]*name="unit_price"[^>]*>/', $detailHtml, $priceInput);
    check(str_contains($priceInput[0] ?? '', 'value="185500"'), 'A matching workshop service fills its actual stored VND price.');
    check(str_contains($detailHtml, 'Test Oil Filter') && str_contains($detailHtml, 'data-price="75250"'), 'Inventory choices expose their stored selling prices.');
    $pdo->prepare('UPDATE repair_orders SET service_type = ? WHERE id = ?')->execute(['PERFORMANCE: STAGE 1 - DAILY RESPONSE', $createdOrder['id']]);
    [, $performanceHtml] = request('repair_orders_detail.php?id=' . $createdOrder['id'], null, 'staff');
    preg_match('/<input\b[^>]*name="item_name"[^>]*>/', $performanceHtml, $itemInput);
    check(str_contains($itemInput[0] ?? '', 'value="Stage 1 - Daily Response (ECU Tune &amp; Intake)"'), 'Performance selections use the shared customer-facing service label.');
    $pdo->prepare('UPDATE repair_orders SET service_type = ? WHERE id = ?')->execute(['Custom "alignment" & <check>', $createdOrder['id']]);
    [, $customHtml] = request('repair_orders_detail.php?id=' . $createdOrder['id'], null, 'staff');
    preg_match('/<input\b[^>]*name="item_name"[^>]*>/', $customHtml, $itemInput);
    check(str_contains($itemInput[0] ?? '', 'value="Custom &quot;alignment&quot; &amp; &lt;check&gt;"'), 'Custom service descriptions are safely escaped inside the input.');
    preg_match('/<input\b[^>]*name="unit_price"[^>]*>/', $customHtml, $priceInput);
    check(str_contains($priceInput[0] ?? '', 'value=""'), 'A service without a linked workshop price requires a staff quote.');
    $pdo->prepare('UPDATE repair_orders SET service_type = ? WHERE id = ?')->execute([$intake['service_type'], $createdOrder['id']]);
    $itemApi = 'src/modules/gara-management/api/orders/items.php';
    [$itemStatus, , $itemHeaders] = request($itemApi, ['action' => 'add', 'csrf' => $staffCsrf, 'repair_order_id' => $createdOrder['id'], 'item_type' => 'labor', 'item_name' => 'Oil & filter change with extra labor', 'unit_price' => 150000, 'quantity' => 2], 'staff');
    check($itemStatus === 302 && in_array('Location: ../../../../../repair_orders_detail.php?id=' . $createdOrder['id'], $itemHeaders, true), 'Saving an edited description returns to the existing details page.');
    $savedItem = $pdo->query('SELECT item_name, subtotal FROM repair_order_items WHERE repair_order_id = ' . (int) $createdOrder['id'])->fetch();
    check($savedItem['item_name'] === 'Oil & filter change with extra labor' && (float) $savedItem['subtotal'] === 300000.0, 'Staff edits to the suggested service save with the item price and quantity.');
    $catalogItem = ['action' => 'add', 'csrf' => $staffCsrf, 'repair_order_id' => $createdOrder['id'], 'item_type' => 'labor', 'service_id' => $pricedServiceId, 'item_name' => 'Reviewed oil change', 'unit_price' => '', 'quantity' => 1];
    check(request($itemApi, $catalogItem, 'staff')[0] === 302, 'A reviewed workshop service can use its stored price.');
    $partItem = ['action' => 'add', 'csrf' => $staffCsrf, 'repair_order_id' => $createdOrder['id'], 'item_type' => 'part', 'part_id' => $pricedPartId, 'item_name' => 'Ignore posted inventory name', 'unit_price' => '', 'quantity' => 2, 'subtotal' => 1, 'total_amount' => 1];
    check(request($itemApi, $partItem, 'staff')[0] === 302, 'A selected spare part saves its inventory name and price.');
    $savedPart = $pdo->query('SELECT * FROM repair_order_items WHERE repair_order_id = ' . (int) $createdOrder['id'] . " AND item_type = 'part'")->fetch();
    check($savedPart['item_name'] === 'Test Oil Filter' && (float) $savedPart['unit_price'] === 75250.0 && (float) $savedPart['subtotal'] === 150500.0, 'The server calculates part quantity and subtotal from the reviewed price.');
    $quoteTotals = $pdo->query('SELECT total_amount, final_amount FROM repair_orders WHERE id = ' . (int) $createdOrder['id'])->fetch();
    check((float) $quoteTotals['total_amount'] === 636000.0 && (float) $quoteTotals['final_amount'] === 636000.0, 'The saved total includes all labor and spare-part subtotals.');
    [, $quoteHtml] = request('repair_orders_detail.php?id=' . $createdOrder['id'], null, 'staff');
    check(str_contains($quoteHtml, 'data-amount="636000.00"') && str_contains($quoteHtml, '636,000') && str_contains($quoteHtml, '150,500'), 'The full section renders the saved total and line subtotals.');
    foreach ([['unit_price' => '-1'], ['unit_price' => 'invalid'], ['unit_price' => '', 'service_id' => 0], ['quantity' => '1.5'], ['quantity' => 0], ['item_type' => 'invalid'], ['service_id' => 999999]] as $invalidItem) {
        check(request($itemApi, array_replace($catalogItem, $invalidItem), 'staff')[0] === 422, 'Invalid quote fields are rejected: ' . json_encode($invalidItem));
    }
    check(request($itemApi, array_replace($partItem, ['part_id' => 999999]), 'staff')[0] === 422, 'An unknown inventory part cannot silently become a manual item.');
    check(request($itemApi, array_replace($catalogItem, ['csrf' => 'invalid']), 'staff')[0] === 403, 'Item changes require a valid session token.');
    $deleteItem = ['action' => 'delete', 'csrf' => $staffCsrf, 'repair_order_id' => $createdOrder['id'], 'item_id' => $savedPart['id']];
    check(request($itemApi, $deleteItem, 'staff')[0] === 302, 'A reviewed item can be removed from the order.');
    check((float) $pdo->query('SELECT total_amount FROM repair_orders WHERE id = ' . (int) $createdOrder['id'])->fetchColumn() === 485500.0, 'Removing a line recalculates the saved order total.');
    $u = $pdo->query('SELECT * FROM users WHERE id = 7')->fetch();
    $v = $pdo->query("SELECT * FROM portal_vehicles WHERE id = $vid")->fetch();
    check($u['full_name'] === 'Reception Updated' && $u['phone'] === '0908888888' && $u['email'] === 'updated@example.test' && $u['contact_method'] === 'email', 'Intake contact changes return to the profile.');
    check($v['model'] === 'Updated EV' && $v['color'] === 'Blue' && (int) $v['mileage'] === 36000 && $v['engine_type'] === 'hybrid', 'Intake changes return to the garage.');
    check((int) $v['workshop_vehicle_id'] === $workshopId && (int) $pdo->query("SELECT customer_id FROM vehicles WHERE id = $workshopId")->fetchColumn() === $customerId, 'Intake preserves both stable links.');
    check((float) $pdo->query("SELECT battery_capacity FROM vehicle_ev_details WHERE vehicle_id = $workshopId")->fetchColumn() === 20.0, 'Hybrid battery details sync without being deleted.');
    [$status, $html] = request('dashboard.php?page=vehicles');
    check($status === 200 && str_contains($html, 'Updated EV') && str_contains($html, 'Test notes'), 'Garage displays synced details and workshop service history.');
    request('dashboard.php?page=vehicles', ['csrf' => $csrf, 'action' => 'delete_vehicle', 'id' => $vid]);
    check((int) $pdo->query("SELECT COUNT(*) FROM portal_vehicles WHERE id = $vid")->fetchColumn() === 1, 'Garage deletion is blocked by an active workshop repair.');

    $pdo->exec("INSERT INTO portal_vehicles (user_id, make, model, plate) VALUES (4, 'Other', 'Owner', 'OTHER-100')");
    $foreignVid = (int) $pdo->lastInsertId();
    $bad = $intake; $bad['portal_vehicle_id'] = $foreignVid; $bad['customer_name'] = 'Should roll back';
    check(request($api, $bad, 'staff')[0] === 500, 'A vehicle belonging to another account is rejected.');
    check($pdo->query('SELECT full_name FROM users WHERE id = 7')->fetchColumn() === 'Reception Updated', 'Failed intake rolls back profile changes.');
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
    $pdo->prepare('INSERT INTO service_requests (user_id, full_name, phone, license_plate, vehicle, service_type, service) VALUES (7, ?, ?, ?, ?, ?, ?)')
        ->execute(['Old booking name', '0900000000', 'SYNC-102', 'Old booking model', 'ENGINE DIAGNOSTIC SCAN', 'Check engine']);
    $appointmentId = (int) $pdo->lastInsertId();
    [$status, $html] = request("repair_orders_create.php?appointment_id=$appointmentId", null, 'staff');
    check($status === 200 && str_contains($html, 'value="Reception Updated"') && str_contains($html, 'value="Updated EV"') && !str_contains($html, 'value="Old booking name"'), 'Appointment intake uses current profile and garage details.');
    check(str_contains($html, 'name="service_request_id" value="' . $appointmentId . '"') && !str_contains($html, 'Warning:'), 'Intake renders the canonical website request link without PHP warnings.');
    check(str_contains($html, 'name="portal_user_id" value="7"') && str_contains($html, 'name="portal_vehicle_id" value="' . $vid . '"'), 'Intake submits the linked customer and vehicle IDs.');
    $booked = $intake; $booked['service_request_id'] = $appointmentId;
    $booked['service_type'] = 'ENGINE DIAGNOSTIC SCAN';
    [$status, $body, $headers] = request($api, $booked, 'staff');
    check($status === 302, 'Appointment intake saves: ' . $body);
    $bookedOrderId = $pdo->query("SELECT id FROM repair_orders WHERE service_request_id = $appointmentId")->fetchColumn();
    check(in_array('Location: ../../../../../repair_orders_detail.php?id=' . $bookedOrderId, $headers, true), 'Website request activation opens its linked repair order.');
    [, $bookedHtml] = request('repair_orders_detail.php?id=' . $bookedOrderId, null, 'staff');
    preg_match('/<input\b[^>]*name="item_name"[^>]*>/', $bookedHtml, $itemInput);
    check(str_contains($itemInput[0] ?? '', 'value="ENGINE DIAGNOSTIC SCAN"'), 'The customer booking service reaches the repair-order item description.');
    check($pdo->query("SELECT status FROM service_requests WHERE id = $appointmentId")->fetchColumn() === 'processed', 'Appointment intake marks the booking processed.');
    $booked['customer_name'] = 'Duplicate booking rollback';
    check(request($api, $booked, 'staff')[0] === 500, 'Duplicate appointment intake is rejected.');
    check($pdo->query('SELECT full_name FROM users WHERE id = 7')->fetchColumn() === 'Reception Updated', 'Duplicate appointment rejection rolls back profile changes.');
    $legacyBooked = $booked;
    unset($legacyBooked['service_request_id']);
    $legacyBooked['appointment_id'] = $appointmentId;
    check(request($api, $legacyBooked, 'staff')[0] === 500, 'Legacy appointment_id submissions retain duplicate booking protection.');
    $booked['portal_user_id'] = 4;
    check(request($api, $booked, 'staff')[0] === 500, 'An appointment cannot be reassigned through hidden customer fields.');

    // Keep walk-ins working, without creating a customer login account.
    $walkin = $intake;
    $walkin['portal_user_id'] = 0; $walkin['portal_vehicle_id'] = 0;
    $walkin['customer_name'] = 'Walk In'; $walkin['customer_phone'] = '0904444444';
    $walkin['customer_email'] = ''; $walkin['customer_zalo'] = ''; $walkin['contact_method'] = 'phone';
    $walkin['license_plate'] = 'WALK-100'; $walkin['km_in'] = '1000';
    check(request($api, $walkin, 'staff')[0] === 302, 'Walk-in intake remains supported.');
    check((int) $pdo->query("SELECT COUNT(*) FROM users WHERE phone = '0904444444' AND password_hash IS NULL AND is_customer = 1")->fetchColumn() === 1, 'Walk-in intake creates one users record without login credentials.');

    // A returning walk-in can select their saved profile without supplying an email.
    $walkinId = (int) $pdo->query("SELECT id FROM users WHERE phone = '0904444444'")->fetchColumn();
    [$status, $html] = request("repair_orders_create.php?portal_user_id=$walkinId", null, 'staff');
    preg_match('/<input\b[^>]*name="customer_email"[^>]*>/', $html, $emailInput);
    check($status === 200 && !empty($emailInput[0]) && !preg_match('/\brequired\b/', $emailInput[0]), 'Saved phone-contact customers are not blocked by a required email input.');
    $returningWalkin = $walkin;
    $returningWalkin['csrf'] = token($html);
    $returningWalkin['portal_user_id'] = $walkinId;
    check(request($api, $returningWalkin, 'staff')[0] === 302, 'A returning saved customer can create an order without an email.');
    check((int) $pdo->query("SELECT COUNT(*) FROM users WHERE phone = '0904444444'")->fetchColumn() === 1, 'Returning intake reuses the saved customer.');

    // Legacy records link by normalized contact/plate and retain workshop-only metadata.
    $pdo->exec("INSERT INTO users (full_name, email, phone, password_hash) VALUES ('Legacy Customer', 'legacy@example.test', '090 555 5555', 'unused')");
    $legacyUserId = (int) $pdo->lastInsertId();
    $legacyCustomerId = $legacyUserId;
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

    // Exercise real logins against the shared password field and role boundaries.
    $setPassword = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $setPassword->execute([password_hash('Customer-Test-123', PASSWORD_DEFAULT), 7]);
    $setPassword->execute([password_hash('Staff-Test-123', PASSWORD_DEFAULT), 1]);
    [, $html] = request('login.php', null, 'loginCustomer');
    [$status, $body] = request('login.php', ['csrf' => token($html), 'email' => 'updated@example.test', 'password' => 'Customer-Test-123', 'remember' => 1], 'loginCustomer');
    check($status === 302, 'Customer email login reads users.password_hash: ' . $body);
    check(request('dashboard.php?page=profile', null, 'loginCustomer')[0] === 200, 'Customer login opens the shared profile.');
    check((int) $pdo->query('SELECT COUNT(*) FROM remember_tokens WHERE user_id = 7')->fetchColumn() === 1, 'Remember token references the unified customer ID.');
    $customerApi = 'src/modules/gara-management/api/customers/';
    check(request($customerApi . 'list.php', null, 'loginCustomer')[0] === 401, 'A customer cannot access staff APIs.');
    [, $html] = request('login.php', null, 'loginStaff');
    check(request('login.php', ['csrf' => token($html), 'email' => 'admin@gara.com', 'password' => 'Staff-Test-123'], 'loginStaff')[0] === 302, 'Staff email login reads the same password field.');
    [$status, $body] = request('src/modules/gara-management/api/auth/me.php', null, 'loginStaff');
    check($status === 200 && (json_decode($body, true)['data']['role'] ?? '') === 'Admin', 'Staff email login retains the Admin role.');
    check(request('src/modules/gara-management/api/auth/login.php', ['username' => 'admin', 'password' => 'Staff-Test-123'], 'apiStaff')[0] === 200, 'Staff username API login uses users.password_hash.');
    $pdo->exec('UPDATE users SET is_active = 0 WHERE id = 1');
    check(request($customerApi . 'list.php', null, 'apiStaff')[0] === 401, 'A disabled staff account loses access to staff APIs.');
    $pdo->exec('UPDATE users SET is_active = 1 WHERE id = 1');
    [, $body] = request($customerApi . 'list.php', null, 'staff');
    $list = json_decode($body, true)['data']['customers'] ?? [];
    check(count(array_filter($list, static fn($c) => (int) $c['id'] === 1)) === 0, 'Customer lists exclude staff-only users.');
    check(count(array_filter($list, static fn($c) => (int) $c['id'] === 4)) === 1, 'Staff with a customer profile appear once in customer lists.');
    [, $html] = request('register.php', null, 'newCustomer');
    check(request('register.php', ['csrf' => token($html), 'full_name' => 'New Registration', 'email' => 'new@example.test', 'phone' => '0906666666', 'password' => 'New-Customer-123', 'confirm_password' => 'New-Customer-123'], 'newCustomer')[0] === 302, 'Registration creates a customer in users.');
    $newCustomer = $pdo->query("SELECT * FROM users WHERE email = 'new@example.test'")->fetch();
    check($newCustomer && $newCustomer['role_id'] === null && (int) $newCustomer['is_customer'] === 1 && password_verify('New-Customer-123', $newCustomer['password_hash']), 'Registration saves one customer identity and its password hash.');
    check(request('dashboard.php?page=profile', null, 'newCustomer')[0] === 200, 'New registration can use the customer dashboard immediately.');
    [, $html] = request('staff.php', null, 'staff');
    check(request('staff.php', ['csrf' => token($html), 'action' => 'add_staff', 'full_name' => 'Test Technician', 'username' => 'testtech', 'email' => 'testtech@example.test', 'phone' => '0907777777', 'role_name' => 'Technician', 'password' => 'New-Staff-123'], 'staff')[0] === 200, 'Staff creation uses the unified table.');
    $newStaff = $pdo->query("SELECT * FROM users WHERE username = 'testtech'")->fetch();
    check($newStaff && (int) $newStaff['is_customer'] === 0 && password_verify('New-Staff-123', $newStaff['password_hash']), 'New staff are distinguished from customers and have working credentials.');

    echo "PASS: $checks integration checks\n";
    if (in_array('--serve', $argv, true)) {
        echo "Fixture: http://127.0.0.1:$port/repair_orders_create.php?portal_user_id=7&portal_vehicle_id=$vid (staff cookie: PHPSESSID=syncstaff)\n";
        fgets(STDIN);
    }
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
