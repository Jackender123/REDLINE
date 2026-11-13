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
$rememberCookies = [];
function check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    ++$checks;
}
function request(string $path, ?array $data = null, string $session = 'customer', array $files = []): array {
    global $port, $sessionCookies, $rememberCookies;
    $cookie = $sessionCookies[$session] ?? 'sync' . $session;
    $remember = isset($rememberCookies[$session]) ? '; redline_remember=' . $rememberCookies[$session] : '';
    $contentType = 'application/x-www-form-urlencoded';
    $content = $data === null ? '' : http_build_query($data);
    if ($files) {
        $boundary = 'redline-' . bin2hex(random_bytes(12));
        $contentType = 'multipart/form-data; boundary=' . $boundary;
        $content = '';
        foreach ($data ?? [] as $name => $value) {
            $content .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
        }
        foreach ($files as $name => $file) {
            $content .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"; filename=\"{$file['name']}\"\r\nContent-Type: {$file['type']}\r\n\r\n{$file['content']}\r\n";
        }
        $content .= "--$boundary--\r\n";
    }
    $context = stream_context_create(['http' => [
        'method' => $data === null ? 'GET' : 'POST', 'ignore_errors' => true, 'follow_location' => 0,
        'header' => "Cookie: PHPSESSID=$cookie$remember\r\nContent-Type: $contentType\r\nConnection: close\r\n",
        'content' => $content, 'timeout' => 10,
    ]]);
    $stream = fopen("http://127.0.0.1:$port/$path", 'r', false, $context);
    if (!$stream) throw new RuntimeException('HTTP request failed: ' . $path);
    $headers = stream_get_meta_data($stream)['wrapper_data'];
    foreach ($headers as $header) {
        if (preg_match('/^Set-Cookie: PHPSESSID=([^;]+)/i', $header, $cookieMatch)) $sessionCookies[$session] = $cookieMatch[1];
        if (preg_match('/^Set-Cookie: redline_remember=([^;]+)/i', $header, $cookieMatch)) $rememberCookies[$session] = $cookieMatch[1];
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
    $sql = file_get_contents($root . '/www/database/schema.sql');
    $sql = preg_replace('/CREATE DATABASE IF NOT EXISTS `redlinev1`.*?;/s', '', $sql);
    $sql = str_replace('USE `redlinev1`;', "USE `$database`;", $sql);
    $pdo->exec($sql);
    check($pdo->query('SELECT name FROM roles WHERE id = 4')->fetchColumn() === 'Customers', 'Fresh SQL seeds role 4 as Customers.');
    check($pdo->query('SELECT name FROM roles WHERE id = 5')->fetchColumn() === 'Cashier', 'Fresh SQL seeds Cashier separately as role 5.');
    // The setup SQL may contain schema only. Seed this test's own identities.
    if ((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0) {
        $pdo->exec("INSERT INTO roles (id, name) VALUES (1, 'Admin'), (2, 'Receptionist'), (3, 'Technician'), (4, 'Customers'), (5, 'Cashier') ON DUPLICATE KEY UPDATE name = VALUES(name)");
        $pdo->exec("INSERT INTO users (id, role_id, username, password_hash, full_name, email, phone, is_customer) VALUES
            (1, 1, 'admin', 'test', 'Test Administrator', 'admin@gara.com', '0901111111', 0),
            (4, 2, 'staffcustomer', 'test', 'Staff Customer', 'staffcustomer@example.test', '0902222222', 1),
            (7, 4, 'role4customer', 'test', 'Test Customer', 'customer@example.test', '0903333333', 1)");
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
    foreach (['config/config.php', 'config/constants.php', 'includes/functions.php', 'includes/session.php', 'customer/index.php', 'admin/appointments/list.php', 'employees/index.php', 'admin/invoices/list.php', 'admin/orders/create.php', 'admin/orders/detail.php', 'auth/login.php', 'auth/register.php', 'admin/employees/list.php', 'admin/customers/list.php', 'includes/auth.php', 'includes/customer_vehicle_sync.php', 'includes/customer_service_history.php', 'includes/service_catalog.php', 'assets/css/dashboard.css', 'api/orders/create.php', 'api/orders/items.php', 'api/orders/approve.php', 'api/orders/list.php', 'api/orders/detail.php', 'api/auth/login.php', 'api/auth/me.php', 'api/customers/list.php', 'api/customers/create.php', 'includes/api_functions.php', 'includes/auth_check.php', 'config/database.php'] as $file) {
        $dest = $fixture . '/' . $file;
        if (!is_dir(dirname($dest))) mkdir(dirname($dest), 0755, true);
        $content = file_get_contents($root . '/www/' . $file);
        if ($file === 'config/database.php') $content = str_replace("const DB_NAME = 'redlinev1';", "const DB_NAME = '$database';", $content);
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
    [$status, $html] = request('customer/index.php?page=profile');
    check($status === 200 && !str_contains($html, 'Fatal error'), 'Profile renders and schema upgrades succeed (HTTP ' . $status . '): ' . substr(strip_tags($html), 0, 1500));
    $csrf = token($html);
    $profile = ['csrf' => $csrf, 'action' => 'update_profile', 'full_name' => 'Sync Customer', 'phone' => '+84 901 234 567', 'email' => 'sync@example.test', 'zalo' => '0901234567', 'contact_method' => 'zalo'];
    check(request('customer/index.php?page=profile', $profile)[0] === 302, 'Profile save redirects.');
    $customer = $pdo->query('SELECT * FROM users WHERE id = 7')->fetch();
    check($customer && $customer['full_name'] === 'Sync Customer' && $customer['contact_method'] === 'zalo', 'Profile details reach the workshop customer.');
    $customerId = (int) $customer['id'];
    $vehicle = ['csrf' => $csrf, 'action' => 'add_vehicle', 'make' => 'Test Make', 'model' => 'Sync EV', 'plate' => 'SYNC-100', 'year' => '2024', 'mileage' => '35000', 'color' => 'White', 'vin' => 'VIN123456789012345', 'engine_type' => 'ev', 'battery_capacity' => '75.5', 'battery_soh' => '97.5'];
    $photoBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jxLkAAAAASUVORK5CYII=', true);
    check(request('customer/index.php?page=vehicles', $vehicle, 'customer', ['photo' => ['name' => 'vehicle.png', 'type' => 'image/png', 'content' => $photoBytes]])[0] === 302, 'Garage vehicle save with a photo redirects.');
    $v = $pdo->query("SELECT * FROM portal_vehicles WHERE plate = 'SYNC-100'")->fetch();
    check($v && $v['workshop_vehicle_id'], 'Garage vehicle receives a stable workshop link.');
    $vid = (int) $v['id'];
    $workshopId = (int) $v['workshop_vehicle_id'];
    $photoPath = (string) $v['photo'];
    check(str_starts_with($photoPath, 'uploads/vehicles/') && is_file($fixture . '/' . $photoPath), 'New vehicle photos are stored in the consolidated uploads directory.');
    [$photoStatus, $savedPhoto] = request($photoPath);
    check($photoStatus === 200 && $savedPhoto === $photoBytes, 'The canonical uploaded photo URL serves the saved image.');
    [, $garageWithPhoto] = request('customer/index.php?page=vehicles');
    check(str_contains($garageWithPhoto, 'src="/' . $photoPath . '"'), 'Nested garage pages render canonical uploaded photo URLs.');
    // Existing database rows retain their old paths; no live database migration is needed.
    $legacyPhotoPath = 'public/' . $photoPath;
    $pdo->prepare('UPDATE portal_vehicles SET photo = ? WHERE id = ?')->execute([$legacyPhotoPath, $vid]);
    [, $garageWithLegacyPhoto] = request('customer/index.php?page=vehicles');
    check(str_contains($garageWithLegacyPhoto, 'src="/' . $photoPath . '"') && !str_contains($garageWithLegacyPhoto, 'src="/public/uploads/'), 'Legacy stored photo paths render from the consolidated uploads directory.');
    check($pdo->query("SELECT photo FROM portal_vehicles WHERE id = $vid")->fetchColumn() === $legacyPhotoPath, 'Rendering a legacy photo leaves its saved database value unchanged.');
    $removePhoto = array_replace($vehicle, ['action' => 'update_vehicle', 'id' => $vid, 'remove_photo' => '1']);
    check(request('customer/index.php?page=vehicles', $removePhoto)[0] === 302, 'A saved legacy photo can be removed through the customer form.');
    clearstatcache(true, $fixture . '/' . $photoPath);
    check(!is_file($fixture . '/' . $photoPath), 'Removing a legacy photo deletes the canonical file.');
    check($pdo->query("SELECT photo FROM portal_vehicles WHERE id = $vid")->fetchColumn() === null, 'Removing a legacy photo clears its saved path.');
    $w = $pdo->query("SELECT * FROM vehicles WHERE id = $workshopId")->fetch();
    check($w['brand'] === 'Test Make' && $w['vin'] === $vehicle['vin'] && (int) $w['odometer'] === 35000 && $w['engine_type'] === 'ev', 'Garage details reach the workshop vehicle.');
    check((float) $pdo->query("SELECT battery_capacity FROM vehicle_ev_details WHERE vehicle_id = $workshopId")->fetchColumn() === 75.5, 'EV battery details sync.');
    $profile['phone'] = '0909999999';
    request('customer/index.php?page=profile', $profile);
    check((int) $pdo->query('SELECT id FROM users WHERE id = 7')->fetchColumn() === $customerId, 'Changing the profile phone keeps the same customer ID.');
    $vehicle['action'] = 'update_vehicle'; $vehicle['id'] = $vid; $vehicle['plate'] = 'SYNC-101';
    request('customer/index.php?page=vehicles', $vehicle);
    check($pdo->query("SELECT license_plate FROM vehicles WHERE id = $workshopId")->fetchColumn() === 'SYNC-101', 'Changing a garage plate updates the existing workshop vehicle.');

    $intakeUrl = "admin/orders/create.php?portal_user_id=7&portal_vehicle_id=$vid";
    [$status, $html] = request($intakeUrl, null, 'staff');
    check($status === 200 && str_contains($html, 'value="Sync EV"') && str_contains($html, 'value="0909999999"') && str_contains($html, 'value="35000"'), 'Intake prefills the current profile and garage vehicle.');
    $staffCsrf = token($html);
    $intake = ['csrf' => $staffCsrf, 'portal_user_id' => 7, 'portal_vehicle_id' => $vid, 'customer_name' => 'Reception Updated', 'customer_phone' => '0908888888', 'customer_email' => 'updated@example.test', 'customer_zalo' => '0901234567', 'contact_method' => 'email', 'license_plate' => 'SYNC-102', 'vehicle_brand' => 'Test Make', 'vehicle_model' => 'Updated EV', 'vehicle_year' => '2025', 'vehicle_color' => 'Blue', 'km_in' => '36000', 'vin_number' => 'VIN987654321012345', 'engine_type' => 'hybrid', 'battery_capacity' => '20', 'battery_soh' => '95', 'fuel_level' => '1/2', 'service_type' => 'OIL & FILTER CHANGE', 'customer_request' => 'Sync integration test', 'symptoms' => 'Test notes'];
    $api = 'api/orders/create.php';
    [$status, $body, $headers] = request($api, $intake, 'staff');
    check($status === 302, 'Intake saves successfully: ' . $body);
    $createdOrder = $pdo->query('SELECT id, order_code FROM repair_orders ORDER BY id DESC LIMIT 1')->fetch();
    check(in_array('Location: ../../admin/orders/detail.php?id=' . $createdOrder['id'], $headers, true), 'Successful intake redirects to the order that was created.');
    [$detailStatus, $detailHtml] = request('admin/orders/detail.php?id=' . $createdOrder['id'], null, 'staff');
    check($detailStatus === 200 && str_contains($detailHtml, $createdOrder['order_code']) && !str_contains($detailHtml, 'Database Connection Error'), 'The redirected order details page displays the newly created order.');
    preg_match('/<input\b[^>]*name="item_name"[^>]*>/', $detailHtml, $itemInput);
    check(str_contains($itemInput[0] ?? '', 'value="OIL &amp; FILTER CHANGE"'), 'Item description prefills the service selected during intake.');
    check(!preg_match('/\b(?:readonly|disabled)\b/', $itemInput[0]), 'Staff can edit the suggested description before adding an item.');
    check((int) $pdo->query('SELECT COUNT(*) FROM repair_order_items WHERE repair_order_id = ' . (int) $createdOrder['id'])->fetchColumn() === 0, 'Opening the quote does not automatically add charges before staff review.');
    preg_match('/<input\b[^>]*name="unit_price"[^>]*>/', $detailHtml, $priceInput);
    check(str_contains($priceInput[0] ?? '', 'value="185500"'), 'A matching workshop service fills its actual stored VND price.');
    check(str_contains($detailHtml, 'Test Oil Filter') && str_contains($detailHtml, 'data-price="75250"'), 'Inventory choices expose their stored selling prices.');
    $pdo->prepare('UPDATE repair_orders SET service_type = ? WHERE id = ?')->execute(['PERFORMANCE: STAGE 1 - DAILY RESPONSE', $createdOrder['id']]);
    [, $performanceHtml] = request('admin/orders/detail.php?id=' . $createdOrder['id'], null, 'staff');
    preg_match('/<input\b[^>]*name="item_name"[^>]*>/', $performanceHtml, $itemInput);
    check(str_contains($itemInput[0] ?? '', 'value="Stage 1 - Daily Response (ECU Tune &amp; Intake)"'), 'Performance selections use the shared customer-facing service label.');
    $pdo->prepare('UPDATE repair_orders SET service_type = ? WHERE id = ?')->execute(['Custom "alignment" & <check>', $createdOrder['id']]);
    [, $customHtml] = request('admin/orders/detail.php?id=' . $createdOrder['id'], null, 'staff');
    preg_match('/<input\b[^>]*name="item_name"[^>]*>/', $customHtml, $itemInput);
    check(str_contains($itemInput[0] ?? '', 'value="Custom &quot;alignment&quot; &amp; &lt;check&gt;"'), 'Custom service descriptions are safely escaped inside the input.');
    preg_match('/<input\b[^>]*name="unit_price"[^>]*>/', $customHtml, $priceInput);
    check(str_contains($priceInput[0] ?? '', 'value=""'), 'A service without a linked workshop price requires a staff quote.');
    $pdo->prepare('UPDATE repair_orders SET service_type = ? WHERE id = ?')->execute([$intake['service_type'], $createdOrder['id']]);
    $itemApi = 'api/orders/items.php';
    [$itemStatus, , $itemHeaders] = request($itemApi, ['action' => 'add', 'csrf' => $staffCsrf, 'repair_order_id' => $createdOrder['id'], 'item_type' => 'labor', 'item_name' => 'Oil & filter change with extra labor', 'unit_price' => 150000, 'quantity' => 2], 'staff');
    check($itemStatus === 302 && in_array('Location: ../../admin/orders/detail.php?id=' . $createdOrder['id'], $itemHeaders, true), 'Saving an edited description returns to the existing details page.');
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
    [, $quoteHtml] = request('admin/orders/detail.php?id=' . $createdOrder['id'], null, 'staff');
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
    [$status, $html] = request('customer/index.php?page=vehicles');
    check($status === 200 && str_contains($html, 'Updated EV') && str_contains($html, 'Test notes'), 'Garage displays synced details and workshop service history.');
    request('customer/index.php?page=vehicles', ['csrf' => $csrf, 'action' => 'delete_vehicle', 'id' => $vid]);
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
    [$status, $html] = request("admin/orders/create.php?appointment_id=$appointmentId", null, 'staff');
    check($status === 200 && str_contains($html, 'value="Reception Updated"') && str_contains($html, 'value="Updated EV"') && !str_contains($html, 'value="Old booking name"'), 'Appointment intake uses current profile and garage details.');
    check(str_contains($html, 'name="service_request_id" value="' . $appointmentId . '"') && !str_contains($html, 'Warning:'), 'Intake renders the canonical website request link without PHP warnings.');
    check(str_contains($html, 'name="portal_user_id" value="7"') && str_contains($html, 'name="portal_vehicle_id" value="' . $vid . '"'), 'Intake submits the linked customer and vehicle IDs.');
    [$status, $reviewHtml] = request('admin/orders/detail.php?service_request_id=' . $appointmentId, null, 'staff');
    check($status === 200 && str_contains($reviewHtml, 'Review Appointment #' . $appointmentId) && str_contains($reviewHtml, 'value="Updated EV"'), 'Appointment opens its prefilled review form on the detail page.');
    check((int) $pdo->query("SELECT COUNT(*) FROM repair_orders WHERE service_request_id = $appointmentId")->fetchColumn() === 0, 'Viewing an appointment does not create a repair order.');
    $booked = $intake; $booked['service_request_id'] = $appointmentId;
    $booked['service_type'] = 'ENGINE DIAGNOSTIC SCAN';
    [$status, $body, $headers] = request($api, $booked, 'staff');
    check($status === 302, 'Appointment intake saves: ' . $body);
    $bookedOrderId = $pdo->query("SELECT id FROM repair_orders WHERE service_request_id = $appointmentId")->fetchColumn();
    check(in_array('Location: ../../admin/orders/detail.php?id=' . $bookedOrderId, $headers, true), 'Website request activation opens its linked repair order.');
    [, $bookedHtml] = request('admin/orders/detail.php?id=' . $bookedOrderId, null, 'staff');
    preg_match('/<input\b[^>]*name="item_name"[^>]*>/', $bookedHtml, $itemInput);
    check(str_contains($itemInput[0] ?? '', 'value="ENGINE DIAGNOSTIC SCAN"'), 'The customer booking service reaches the repair-order item description.');
    check($pdo->query("SELECT status FROM service_requests WHERE id = $appointmentId")->fetchColumn() === 'processed', 'Appointment intake marks the booking processed.');
    // An administrator must explicitly release the order to an active technician.
    $pdo->exec("INSERT INTO users (id, role_id, username, password_hash, full_name, email, is_customer) VALUES
        (20, 3, 'approvaltech', 'test', 'Approval Technician', 'approvaltech@example.test', 0),
        (21, 3, 'othertech', 'test', 'Other Technician', 'othertech@example.test', 0),
        (22, 3, 'inactivetech', 'test', 'Inactive Technician', 'inactive@example.test', 0)");
    $pdo->exec('UPDATE users SET is_active = 0 WHERE id = 22');
    file_put_contents($fixture . '/employee_session.php', '<?php session_start(); $id = (int) ($_GET["id"] ?? 20); $_SESSION = ["_identity_schema" => 2, "user_id" => $id, "username" => "fixture", "role" => $id === 4 ? "Receptionist" : "Technician", "csrf" => "approval-test-token"]; echo "ok";');
    request('employee_session.php?id=20', null, 'tech');
    request('employee_session.php?id=21', null, 'othertech');
    request('employee_session.php?id=4', null, 'receptionist');
    $orderCode = $pdo->query('SELECT order_code FROM repair_orders WHERE id = ' . (int) $bookedOrderId)->fetchColumn();
    $approvalApi = 'api/orders/approve.php';
    $approval = ['csrf' => $staffCsrf, 'repair_order_id' => $bookedOrderId, 'employee_id' => 20];
    [, $inbox] = request('admin/appointments/list.php', null, 'staff');
    check(str_contains($inbox, '"status":"awaiting_approval"') && str_contains($inbox, '../orders/detail.php?service_request_id='), 'Inbox links to appointment review and distinguishes orders awaiting approval.');
    [, $linkedHtml] = request('admin/orders/detail.php?service_request_id=' . $appointmentId, null, 'staff');
    check(str_contains($linkedHtml, 'Original appointment #' . $appointmentId) && str_contains($linkedHtml, 'Old booking name') && str_contains($linkedHtml, 'Approve &amp; send to employee'), 'Saved review opens the existing order, preserves the original booking and offers admin approval.');
    [, $employeeHtml] = request('employees/index.php?view=jobs', null, 'tech');
    check(!str_contains($employeeHtml, $orderCode), 'Unapproved order is absent from the employee workspace.');
    [, $list] = request('api/orders/list.php', null, 'tech');
    check(!str_contains($list, $orderCode), 'Unapproved order is absent from the technician API.');
    check(request($approvalApi, null, 'staff')[0] === 405, 'Approval rejects GET.');
    check(request($approvalApi, $approval, 'customer')[0] === 403, 'Customers cannot approve orders.');
    foreach (['tech', 'receptionist'] as $roleSession) {
        check(request($approvalApi, array_replace($approval, ['csrf' => 'approval-test-token']), $roleSession)[0] === 403, 'Only Admin can approve, even with a valid token: ' . $roleSession);
    }
    check(request($approvalApi, array_replace($approval, ['csrf' => 'invalid']), 'staff')[0] === 403, 'Approval requires CSRF.');
    $statusChange = ['csrf' => $staffCsrf, 'action' => 'update_status', 'repair_order_id' => $bookedOrderId, 'status' => 'diagnosing'];
    check(request($itemApi, $statusChange, 'staff')[0] === 409, 'Status changes cannot bypass approval.');
    foreach ([4, 22, 999999] as $invalidEmployee) {
        check(request($approvalApi, array_replace($approval, ['employee_id' => $invalidEmployee]), 'staff')[0] === 303, 'Invalid employee returns to the review page.');
        check(!$pdo->query('SELECT assigned_to FROM repair_orders WHERE id = ' . (int) $bookedOrderId)->fetchColumn(), 'Invalid or inactive employees cannot receive orders.');
    }
    $pdo->exec("UPDATE service_requests SET status = 'cancelled' WHERE id = $appointmentId");
    request($approvalApi, $approval, 'staff');
    check(!$pdo->query('SELECT assigned_to FROM repair_orders WHERE id = ' . (int) $bookedOrderId)->fetchColumn(), 'Cancelled appointments cannot be approved.');
    $pdo->exec("UPDATE service_requests SET status = 'processed' WHERE id = $appointmentId");
    $pdo->exec('UPDATE repair_orders SET status = \'completed\' WHERE id = ' . (int) $bookedOrderId);
    request($approvalApi, $approval, 'staff');
    check(!$pdo->query('SELECT assigned_to FROM repair_orders WHERE id = ' . (int) $bookedOrderId)->fetchColumn(), 'Completed orders cannot be dispatched.');
    $pdo->exec('UPDATE repair_orders SET status = \'received\' WHERE id = ' . (int) $bookedOrderId);
    check(request($approvalApi, $approval, 'staff')[0] === 303, 'Admin approval redirects to the order.');
    $approved = $pdo->query('SELECT assigned_to, technician_id, status FROM repair_orders WHERE id = ' . (int) $bookedOrderId)->fetch();
    check((int) $approved['assigned_to'] === 20 && (int) $approved['technician_id'] === 20 && $approved['status'] === 'received', 'Approval assigns the selected technician and preserves the work status.');
    $history = $pdo->query('SELECT * FROM order_status_history WHERE order_id = ' . (int) $bookedOrderId)->fetchAll();
    check(count($history) === 1 && (int) $history[0]['created_by'] === 1 && str_contains($history[0]['note'], 'Approval Technician'), 'Approval records who released the order and its recipient.');
    request($approvalApi, array_replace($approval, ['employee_id' => 21]), 'staff');
    check((int) $pdo->query('SELECT assigned_to FROM repair_orders WHERE id = ' . (int) $bookedOrderId)->fetchColumn() === 20 && (int) $pdo->query('SELECT COUNT(*) FROM order_status_history WHERE order_id = ' . (int) $bookedOrderId)->fetchColumn() === 1, 'Repeated approval cannot reassign an order or duplicate its history.');
    [, $employeeHtml] = request('employees/index.php?view=jobs', null, 'tech');
    check(str_contains($employeeHtml, $orderCode), 'Approved order appears in the selected employee workspace.');
    [, $otherHtml] = request('employees/index.php?view=jobs', null, 'othertech');
    check(!str_contains($otherHtml, $orderCode), 'Another technician cannot see the approved order.');
    [, $employeeDetail] = request('api/orders/detail.php?id=' . $bookedOrderId, null, 'tech');
    check((json_decode($employeeDetail, true)['status'] ?? '') === 'success', 'Selected technician can open approved order details.');
    [, $otherDetail] = request('api/orders/detail.php?id=' . $bookedOrderId, null, 'othertech');
    check((json_decode($otherDetail, true)['status'] ?? '') === 'error', 'Another technician cannot open the approved order by ID.');
    [, $approvedHtml] = request('admin/orders/detail.php?id=' . $bookedOrderId, null, 'staff');
    check(str_contains($approvedHtml, 'Sent to employee') && str_contains($approvedHtml, 'Approved by Test Administrator') && !str_contains($approvedHtml, 'id="approval-employee"'), 'Detail page shows the recipient and approval audit after dispatch.');
    check(request($itemApi, $statusChange, 'staff')[0] === 302, 'Work can advance after admin approval.');

    // Follow the real technician update through the customer overview and its live refresh.
    $otherOrderStatuses = $pdo->query('SELECT id, status FROM repair_orders WHERE id <> ' . (int) $bookedOrderId)->fetchAll();
    $pdo->exec("UPDATE repair_orders SET status = 'delivered' WHERE id <> " . (int) $bookedOrderId);
    request('session.php?id=4', null, 'otherCustomer');
    check(request('customer/index.php?repair_progress=1', null, 'guest')[0] === 401, 'Live repair progress requires customer authentication.');
    foreach (['received' => 1, 'diagnosing' => 2, 'waiting_parts' => 3, 'repairing' => 3, 'completed' => 5] as $jobStatus => $stage) {
        $note = 'Customer progress ' . $jobStatus . ' <check> & verify';
        [$status, $employeeHtml] = request('employees/index.php?view=jobs', [
            'csrf' => 'approval-test-token', 'action' => 'update_job_status',
            'order_id' => $bookedOrderId, 'job_status' => $jobStatus, 'progress_note' => $note,
        ], 'tech');
        check($status === 200 && str_contains($employeeHtml, 'Job status updated.'), 'Technician saves ' . $jobStatus . ' through the employee page.');
        [$status, $body] = request('customer/index.php?repair_progress=1');
        $progress = json_decode($body, true);
        $card = $progress['html'] ?? '';
        check($status === 200 && str_contains($card, 'data-order-id="' . $bookedOrderId . '"') && str_contains($card, 'data-status="' . $jobStatus . '"'), 'Customer live progress reads the technician\'s saved ' . $jobStatus . ' status.');
        check(str_contains($card, htmlspecialchars($note, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) && !str_contains($card, '<check>'), 'Customer progress shows and escapes the latest technician note.');
        check(str_contains($card, 'Approval Technician') && str_contains($card, 'SYNC-102') && !str_contains($card, '4:30 PM'), 'Progress uses the real technician and vehicle without a demo completion time.');
        check(substr_count($card, 'class="step done') === $stage && substr_count($card, 'aria-current="step"') === 1, 'Customer stepper matches employee stage ' . $stage . '.');
        check(($progress['active_count'] ?? 0) === 1 && str_contains($progress['orders_html'] ?? '', $orderCode), 'Live repair list and active count include the workshop order.');
        [, $overview] = request('customer/index.php');
        check(str_contains($overview, $card) && str_contains($overview, 'setInterval(refreshRepairProgress, 15000)'), 'Initial overview and background refresh use the same progress card.');
        [, $otherBody] = request('customer/index.php?repair_progress=1&user_id=7&order_id=' . $bookedOrderId, null, 'otherCustomer');
        $otherProgress = json_decode($otherBody, true);
        check(!str_contains($otherBody, $orderCode) && str_contains($otherProgress['html'] ?? '', 'No current repair orders'), 'Another customer cannot obtain the order through a forged refresh URL.');
        if ($jobStatus === 'repairing') {
            check(request($itemApi, array_replace($statusChange, ['status' => 'inspecting']), 'staff')[0] === 302, 'Admin can advance the approved order to inspection.');
            [, $inspectionBody] = request('customer/index.php?repair_progress=1');
            $inspectionCard = json_decode($inspectionBody, true)['html'] ?? '';
            check(str_contains($inspectionCard, 'data-status="inspecting"') && substr_count($inspectionCard, 'class="step done') === 4, 'Inspection advances the customer stepper to stage four.');
            check(!str_contains($inspectionCard, htmlspecialchars($note, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')), 'A new status without a note does not repeat an old technician message.');
        }
    }
    [, $readyBody] = request('customer/index.php?repair_progress=1');
    check(str_contains(json_decode($readyBody, true)['html'] ?? '', 'Ready for pickup'), 'Repair completion remains visible as ready for pickup until delivery.');
    $pdo->exec("UPDATE repair_orders SET status = 'delivered', updated_at = NOW() WHERE id = " . (int) $bookedOrderId);
    [, $deliveredBody] = request('customer/index.php?repair_progress=1');
    $deliveredProgress = json_decode($deliveredBody, true);
    check(($deliveredProgress['active_count'] ?? -1) === 0 && str_contains($deliveredProgress['html'] ?? '', 'No current repair orders'), 'Delivered orders clear the current progress card and active count.');
    foreach ($otherOrderStatuses as $previousOrder) {
        $pdo->prepare('UPDATE repair_orders SET status = ? WHERE id = ?')->execute([$previousOrder['status'], $previousOrder['id']]);
    }
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
    check((int) $pdo->query("SELECT COUNT(*) FROM users WHERE phone = '0904444444' AND role_id = 4 AND password_hash IS NULL AND is_customer = 1")->fetchColumn() === 1, 'Walk-in intake creates one role 4 customer without login credentials.');

    // A returning walk-in can select their saved profile without supplying an email.
    $walkinId = (int) $pdo->query("SELECT id FROM users WHERE phone = '0904444444'")->fetchColumn();
    [$status, $html] = request("admin/orders/create.php?portal_user_id=$walkinId", null, 'staff');
    preg_match('/<input\b[^>]*name="customer_email"[^>]*>/', $html, $emailInput);
    check($status === 200 && !empty($emailInput[0]) && !preg_match('/\brequired\b/', $emailInput[0]), 'Saved phone-contact customers are not blocked by a required email input.');
    $returningWalkin = $walkin;
    $returningWalkin['csrf'] = token($html);
    $returningWalkin['portal_user_id'] = $walkinId;
    check(request($api, $returningWalkin, 'staff')[0] === 302, 'A returning saved customer can create an order without an email.');
    check((int) $pdo->query("SELECT COUNT(*) FROM users WHERE phone = '0904444444'")->fetchColumn() === 1, 'Returning intake reuses the saved customer.');

    // Legacy records link by normalized contact/plate and retain workshop-only metadata.
    $pdo->exec("INSERT INTO users (role_id, full_name, email, phone, password_hash) VALUES (NULL, 'Legacy Customer', 'legacy@example.test', '090 555 5555', 'unused')");
    $legacyUserId = (int) $pdo->lastInsertId();
    $legacyCustomerId = $legacyUserId;
    $pdo->exec("INSERT INTO vehicles (customer_id, license_plate, brand, model, vin, year, color, odometer, engine_type, is_ev) VALUES ($legacyCustomerId, 'LEGACY100', 'Legacy', 'EV', 'LEGACY-VIN', 2021, 'Silver', 42000, 'ev', 1)");
    $legacyWorkshopId = (int) $pdo->lastInsertId();
    $pdo->exec("INSERT INTO vehicle_ev_details (vehicle_id, battery_capacity, battery_soh) VALUES ($legacyWorkshopId, 80, 90)");
    request("session.php?id=$legacyUserId", null, 'legacy');
    [, $html] = request('customer/index.php?page=vehicles', null, 'legacy');
    $legacyVehicle = ['csrf' => token($html), 'action' => 'add_vehicle', 'make' => 'Legacy', 'model' => 'EV', 'plate' => 'LEGACY-100'];
    request('customer/index.php?page=vehicles', $legacyVehicle, 'legacy');
    $v = $pdo->query("SELECT * FROM portal_vehicles WHERE user_id = $legacyUserId")->fetch();
    check($v && (int) $v['workshop_vehicle_id'] === $legacyWorkshopId, 'Legacy normalized phone and plate link to existing workshop records.');
    check($v['vin'] === 'LEGACY-VIN' && (int) $v['year'] === 2021 && $v['color'] === 'Silver' && (int) $v['mileage'] === 42000, 'Legacy linking retains VIN, year, color and mileage.');
    check($v['engine_type'] === 'ev' && (float) $v['battery_capacity'] === 80.0 && (float) $v['battery_soh'] === 90.0, 'Legacy linking retains powertrain and battery data.');

    // Cross-customer plate collisions must not update either customer or vehicle.
    $vehicle['plate'] = 'LEGACY100'; $vehicle['model'] = 'Collision rollback';
    request('customer/index.php?page=vehicles', $vehicle);
    check($pdo->query("SELECT model FROM portal_vehicles WHERE id = $vid")->fetchColumn() === 'Updated EV', 'A plate collision rolls back the garage update.');

    // Exercise real logins against the shared password field and role boundaries.
    $setPassword = $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $setPassword->execute([password_hash('Customer-Test-123', PASSWORD_DEFAULT), 7]);
    $setPassword->execute([password_hash('Staff-Test-123', PASSWORD_DEFAULT), 1]);
    $setPassword->execute([password_hash('Legacy-Test-123', PASSWORD_DEFAULT), $legacyUserId]);
    [, $html] = request('auth/login.php', null, 'loginLegacyCustomer');
    check(request('auth/login.php', ['csrf' => token($html), 'email' => 'legacy@example.test', 'password' => 'Legacy-Test-123'], 'loginLegacyCustomer')[0] === 302, 'Legacy customers with a NULL role can still sign in by email.');
    [$status, $legacyProfile] = request('customer/index.php?page=profile', null, 'loginLegacyCustomer');
    check($status === 200 && str_contains($legacyProfile, 'legacy@example.test'), 'Legacy NULL-role login opens the customer dashboard.');
    [, $html] = request('auth/login.php', null, 'loginCustomer');
    [$status, $body] = request('auth/login.php', ['csrf' => token($html), 'email' => 'updated@example.test', 'password' => 'Customer-Test-123', 'remember' => 1], 'loginCustomer');
    check($status === 302, 'Role 4 customer email login reads users.password_hash: ' . $body);
    check(request('customer/index.php?page=profile', null, 'loginCustomer')[0] === 200, 'Customer login opens the shared profile.');
    check((int) $pdo->query('SELECT COUNT(*) FROM remember_tokens WHERE user_id = 7')->fetchColumn() === 1, 'Remember token references the unified customer ID.');
    check(!empty($rememberCookies['loginCustomer']), 'Customer login sends a remember cookie.');
    $rememberCookies['restoredCustomer'] = $rememberCookies['loginCustomer'];
    [$status, $restoredProfile] = request('customer/index.php?page=profile', null, 'restoredCustomer');
    check($status === 200 && str_contains($restoredProfile, 'updated@example.test'), 'A remember cookie restores a role 4 customer without an existing session.');
    check($rememberCookies['restoredCustomer'] !== $rememberCookies['loginCustomer'] && (int) $pdo->query('SELECT COUNT(*) FROM remember_tokens WHERE user_id = 7')->fetchColumn() === 1, 'Restoring a role 4 customer rotates the remember token.');
    $customerApi = 'api/customers/';
    check(request($customerApi . 'list.php', null, 'loginCustomer')[0] === 401, 'A customer cannot access staff APIs.');
    check(request('api/auth/login.php', ['username' => 'role4customer', 'password' => 'Customer-Test-123'], 'apiCustomer')[0] === 401, 'A role 4 customer with a username cannot log in through the staff API.');
    check(request($customerApi . 'list.php', null, 'apiCustomer')[0] === 401, 'Rejected customer staff login does not create a staff session.');
    [, $html] = request('auth/login.php', null, 'loginStaff');
    check(request('auth/login.php', ['csrf' => token($html), 'email' => 'admin@gara.com', 'password' => 'Staff-Test-123'], 'loginStaff')[0] === 302, 'Staff email login reads the same password field.');
    [$status, $body] = request('api/auth/me.php', null, 'loginStaff');
    check($status === 200 && (json_decode($body, true)['data']['role'] ?? '') === 'Admin', 'Staff email login retains the Admin role.');
    check(request('api/auth/login.php', ['username' => 'admin', 'password' => 'Staff-Test-123'], 'apiStaff')[0] === 200, 'Staff username API login uses users.password_hash.');
    $pdo->exec('UPDATE users SET is_active = 0 WHERE id = 1');
    check(request($customerApi . 'list.php', null, 'apiStaff')[0] === 401, 'A disabled staff account loses access to staff APIs.');
    $pdo->exec('UPDATE users SET is_active = 1 WHERE id = 1');
    [, $body] = request($customerApi . 'list.php', null, 'staff');
    $list = json_decode($body, true)['data']['customers'] ?? [];
    check(count(array_filter($list, static fn($c) => (int) $c['id'] === 1)) === 0, 'Customer lists exclude staff-only users.');
    check(count(array_filter($list, static fn($c) => (int) $c['id'] === 4)) === 1, 'Staff with a customer profile appear once in customer lists.');
    [, $html] = request('auth/register.php', null, 'newCustomer');
    check(request('auth/register.php', ['csrf' => token($html), 'full_name' => 'New Registration', 'email' => 'new@example.test', 'phone' => '0906666666', 'password' => 'New-Customer-123', 'confirm_password' => 'New-Customer-123'], 'newCustomer')[0] === 302, 'Registration creates a customer in users.');
    $newCustomer = $pdo->query("SELECT * FROM users WHERE email = 'new@example.test'")->fetch();
    check($newCustomer && (int) $newCustomer['role_id'] === 4 && (int) $newCustomer['is_customer'] === 1 && password_verify('New-Customer-123', $newCustomer['password_hash']), 'Registration saves one role 4 customer identity and its password hash.');
    check(request('customer/index.php?page=profile', null, 'newCustomer')[0] === 200, 'New registration can use the customer dashboard immediately.');
    [, $html] = request('admin/employees/list.php', null, 'staff');
    check(str_contains($html, 'staffcustomer@example.test') && !str_contains($html, 'updated@example.test') && !str_contains($html, 'new@example.test'), 'Staff lists retain dual-profile staff and exclude role 4 customers.');
    request('admin/employees/list.php', ['csrf' => token($html), 'action' => 'toggle_status', 'user_id' => 7, 'new_status' => 0], 'staff');
    check((int) $pdo->query('SELECT is_active FROM users WHERE id = 7')->fetchColumn() === 1, 'Staff status actions cannot disable a role 4 customer.');
    check(request('admin/employees/list.php', ['csrf' => token($html), 'action' => 'add_staff', 'full_name' => 'Test Technician', 'username' => 'testtech', 'email' => 'testtech@example.test', 'phone' => '0907777777', 'role_name' => 'Technician', 'password' => 'New-Staff-123'], 'staff')[0] === 200, 'Staff creation uses the unified table.');
    $newStaff = $pdo->query("SELECT * FROM users WHERE username = 'testtech'")->fetch();
    check($newStaff && (int) $newStaff['is_customer'] === 0 && password_verify('New-Staff-123', $newStaff['password_hash']), 'New staff are distinguished from customers and have working credentials.');
    request('admin/employees/list.php', ['csrf' => token($html), 'action' => 'add_staff', 'full_name' => 'Test Cashier', 'username' => 'testcashier', 'email' => 'testcashier@example.test', 'phone' => '0907788888', 'role_name' => 'Cashier', 'password' => 'New-Cashier-123'], 'staff');
    $cashier = $pdo->query("SELECT * FROM users WHERE username = 'testcashier'")->fetch();
    check($cashier && (int) $cashier['role_id'] === 5 && (int) $cashier['is_customer'] === 0, 'Cashier accounts use role 5 and retain staff membership.');
    [$status, $body] = request('api/auth/login.php', ['username' => 'testcashier', 'password' => 'New-Cashier-123'], 'cashier');
    check($status === 200 && (json_decode($body, true)['data']['role'] ?? '') === 'Cashier' && request('employees/index.php', null, 'cashier')[0] === 200, 'Cashiers can still sign in and open their workspace.');
    [, $html] = request('admin/customers/list.php', null, 'staff');
    request('admin/customers/list.php', ['csrf' => token($html), 'action' => 'add_customer', 'full_name' => 'Desk Customer', 'phone' => '0907799999', 'email' => 'desk@example.test', 'address' => 'Desk address'], 'staff');
    check((int) $pdo->query("SELECT COUNT(*) FROM users WHERE email = 'desk@example.test' AND role_id = 4 AND is_customer = 1")->fetchColumn() === 1, 'The customer management form creates role 4 customers.');
    check(request($customerApi . 'create.php', ['full_name' => 'API Customer', 'phone' => '0907700000', 'email' => 'api-customer@example.test'], 'staff')[0] === 201, 'Staff can create a customer through the API.');
    check((int) $pdo->query("SELECT COUNT(*) FROM users WHERE email = 'api-customer@example.test' AND role_id = 4 AND is_customer = 1")->fetchColumn() === 1, 'The customer API creates role 4 customers.');

    // Invoicing must load against the unified users schema and retain the order handoff.
    [$status, $customerInvoices] = request('customer/index.php?page=invoices');
    check($status === 200 && str_contains($customerInvoices, 'Nothing to show yet.') && str_contains($customerInvoices, '0 unpaid invoices') && !str_contains($customerInvoices, 'INVOICE #HD-25112'), 'Customers without invoices see empty records and real zero balances.');
    check(request('admin/invoices/list.php', null, 'customer')[0] === 302, 'Customers cannot open the staff invoice page.');
    check(request('admin/invoices/list.php', null, 'tech')[0] === 302, 'Technicians are directed to their employee workspace.');
    [, $invoicePage] = request('admin/invoices/list.php', null, 'staff');
    check(!str_contains($invoicePage, 'Unable to load invoices.') && !str_contains($invoicePage, 'Unable to load completed repair orders.'), 'Both invoice queries load against users without merge markers or legacy customer tables.');
    $invoiceCsrf = token($invoicePage);
    $invoiceData = ['csrf' => $invoiceCsrf, 'action' => 'create_invoice', 'order_id' => $createdOrder['id'], 'discount' => 10000, 'payment_method' => 'transfer', 'payment_status' => 'unpaid'];
    [, $invoicePage] = request('admin/invoices/list.php', $invoiceData, 'staff');
    check(str_contains($invoicePage, 'Only completed repair orders can be invoiced.'), 'Incomplete work cannot be invoiced.');
    $pdo->prepare("UPDATE repair_orders SET status = 'completed' WHERE id = ?")->execute([$createdOrder['id']]);
    [, $invoicePage] = request('admin/invoices/list.php?create_from_ro=' . $createdOrder['id'], null, 'staff');
    check(str_contains($invoicePage, '<option value="' . $createdOrder['id'] . '" selected>') && !str_contains($invoicePage, 'Unable to load'), 'Issue Invoice opens its completed repair order with the correct selection.');
    check(str_contains($invoicePage, 'Reception Updated') && str_contains($invoicePage, 'SYNC-102'), 'Completed order selection includes the shared customer and vehicle.');
    [, $invalidInvoicePage] = request('admin/invoices/list.php', array_replace($invoiceData, ['csrf' => 'invalid']), 'staff');
    check(str_contains($invalidInvoicePage, 'Your session has expired.') && (int) $pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn() === 0, 'Invalid invoice CSRF leaves the database unchanged.');
    [, $invalidInvoicePage] = request('admin/invoices/list.php', array_replace($invoiceData, ['discount' => 999999999]), 'staff');
    check(str_contains($invalidInvoicePage, 'Discount cannot exceed') && (int) $pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn() === 0, 'Invoice discount cannot exceed the work-order subtotal.');
    [$status, $invoicePage] = request('admin/invoices/list.php', $invoiceData, 'staff');
    $invoice = $pdo->query('SELECT * FROM invoices ORDER BY id DESC LIMIT 1')->fetch();
    check($status === 200 && $invoice && (int) $invoice['order_id'] === (int) $createdOrder['id'], 'A completed order creates an invoice through admin/invoices/list.php.');
    check((float) $invoice['total_services'] === 485500.0 && (float) $invoice['grand_total'] === 475500.0 && $invoice['payment_status'] === 'unpaid', 'Invoice totals use the saved repair items and reviewed discount.');
    preg_match('/const rawInvoices = (.*);/', $invoicePage, $invoiceJson);
    $invoiceRows = json_decode($invoiceJson[1] ?? '', true);
    check(count($invoiceRows ?? []) === 1 && $invoiceRows[0]['customer_name'] === 'Reception Updated' && $invoiceRows[0]['license_plate'] === 'SYNC-102', 'Created invoice appears once with linked customer and vehicle details.');
    [$status, $customerInvoices] = request('customer/index.php?page=invoices');
    check($status === 200 && str_contains($customerInvoices, $invoice['invoice_code']) && str_contains($customerInvoices, 'SYNC-102') && str_contains($customerInvoices, 'VND 475,500') && str_contains($customerInvoices, '1 unpaid invoice'), 'The admin-created invoice reaches the customer dashboard with its saved vehicle, total and unpaid balance.');
    check(str_contains($customerInvoices, 'Discount: VND 10,000') && !str_contains($customerInvoices, 'id="pay-now"'), 'Payment details use the saved invoice and cannot simulate a payment in the browser.');
    [, $otherInvoices] = request('customer/index.php?page=invoices&invoice=' . $invoice['id'], null, 'newCustomer');
    check(!str_contains($otherInvoices, $invoice['invoice_code']) && str_contains($otherInvoices, 'Nothing to show yet.'), 'Another customer cannot see the invoice, even by requesting its ID.');
    $pdo->prepare('UPDATE repair_orders SET customer_id = NULL WHERE id = ?')->execute([$createdOrder['id']]);
    [, $customerInvoices] = request('customer/index.php?page=invoices');
    check(str_contains($customerInvoices, $invoice['invoice_code']), 'Legacy orders without an explicit customer use the workshop vehicle owner.');
    $pdo->prepare('UPDATE repair_orders SET customer_id = ? WHERE id = ?')->execute([$newCustomer['id'], $createdOrder['id']]);
    [, $customerInvoices] = request('customer/index.php?page=invoices');
    check(!str_contains($customerInvoices, $invoice['invoice_code']), 'An explicit order customer takes precedence over the vehicle owner.');
    $pdo->prepare('UPDATE repair_orders SET customer_id = ? WHERE id = ?')->execute([$customerId, $createdOrder['id']]);
    [, $duplicateInvoicePage] = request('admin/invoices/list.php', $invoiceData, 'staff');
    check(str_contains($duplicateInvoicePage, 'An invoice already exists') && (int) $pdo->query('SELECT COUNT(*) FROM invoices')->fetchColumn() === 1, 'A repeated invoice submission cannot create a duplicate.');
    [, $invoicePage] = request('admin/invoices/list.php?create_from_ro=' . $createdOrder['id'], null, 'staff');
    check(!str_contains($invoicePage, '<option value="' . $createdOrder['id'] . '" selected>'), 'An invoiced repair order is removed from completed-order selection.');
    $paymentData = ['csrf' => $invoiceCsrf, 'action' => 'update_payment', 'invoice_id' => $invoice['id'], 'previous_status' => 'unpaid', 'payment_status' => 'paid', 'payment_method' => 'card'];
    [, $invoicePage] = request('admin/invoices/list.php', $paymentData, 'staff');
    $paidInvoice = $pdo->query('SELECT payment_status, payment_method FROM invoices WHERE id = ' . (int) $invoice['id'])->fetch();
    check($paidInvoice['payment_status'] === 'paid' && $paidInvoice['payment_method'] === 'card', 'Payment changes save to the correct invoice.');
    $pdo->prepare("UPDATE repair_orders SET status = 'delivered' WHERE id = ?")->execute([$createdOrder['id']]);
    [, $customerInvoices] = request('customer/index.php?page=invoices&invoice=' . $invoice['id']);
    check(str_contains($customerInvoices, $invoice['invoice_code']) && str_contains($customerInvoices, '1 paid invoice') && str_contains($customerInvoices, '0 unpaid invoices') && str_contains($customerInvoices, 'Payment method: Card'), 'Staff payment updates reach the customer invoice and balances after the vehicle is delivered.');
    [, $stalePaymentPage] = request('admin/invoices/list.php', array_replace($paymentData, ['payment_status' => 'unpaid']), 'staff');
    check(str_contains($stalePaymentPage, 'updated by another user') && $pdo->query('SELECT payment_status FROM invoices WHERE id = ' . (int) $invoice['id'])->fetchColumn() === 'paid', 'A stale payment form cannot overwrite a newer payment status.');

    // A saved customer ID disambiguates existing shared phone numbers.
    $insertSharedCustomer = $pdo->prepare('INSERT INTO users (role_id, full_name, email, phone, is_customer, is_active) VALUES (4, ?, ?, ?, 1, 1)');
    $insertSharedCustomer->execute(['Shared Phone First', 'shared-first@example.test', '0912345678']);
    $sharedFirstId = (int) $pdo->lastInsertId();
    $insertSharedCustomer->execute(['Shared Phone Second', 'shared-second@example.test', '091 234 5678']);
    $sharedSecondId = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO vehicles (customer_id, license_plate, brand, model) VALUES (?, ?, ?, ?)')
        ->execute([$sharedSecondId, 'SHARED-200', 'Other', 'Untouched']);
    $sharedOtherVehicleId = (int) $pdo->lastInsertId();
    $sharedOtherBefore = $pdo->query("SELECT * FROM users WHERE id = $sharedSecondId")->fetch();
    $sharedOtherVehicleBefore = $pdo->query("SELECT * FROM vehicles WHERE id = $sharedOtherVehicleId")->fetch();
    $sharedUserCount = (int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    $sharedIntake = array_replace($intake, [
        'portal_user_id' => $sharedFirstId, 'portal_vehicle_id' => 0,
        'customer_name' => 'Shared Phone First Updated', 'customer_phone' => '091-234-5678',
        'customer_email' => 'shared-first@example.test', 'customer_zalo' => '', 'contact_method' => 'phone',
        'license_plate' => 'SHARED-100', 'vehicle_brand' => 'Shared', 'vehicle_model' => 'First Car',
    ]);
    [$status, $body, $headers] = request($api, $sharedIntake, 'staff');
    check($status === 302, 'Selecting an existing customer permits their unchanged shared phone number (HTTP ' . $status . '): ' . $body);
    $sharedOrder = $pdo->query("SELECT * FROM repair_orders WHERE customer_id = $sharedFirstId ORDER BY id DESC LIMIT 1")->fetch();
    check($sharedOrder && (int) $pdo->query('SELECT customer_id FROM vehicles WHERE id = ' . (int) $sharedOrder['vehicle_id'])->fetchColumn() === $sharedFirstId, 'Shared-phone intake keeps both order and vehicle linked to the selected customer.');
    check($pdo->query("SELECT * FROM users WHERE id = $sharedSecondId")->fetch() === $sharedOtherBefore
        && $pdo->query("SELECT * FROM vehicles WHERE id = $sharedOtherVehicleId")->fetch() === $sharedOtherVehicleBefore,
        'Selecting one shared-phone profile leaves the other customer and vehicle unchanged.');
    check((int) $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === $sharedUserCount, 'Shared-phone intake reuses the selected identity without creating or merging users.');

    $identitySnapshot = static function () use ($pdo): array {
        $rows = [];
        foreach (['users', 'vehicles', 'portal_vehicles', 'repair_orders', 'service_requests'] as $table) {
            $rows[$table] = $pdo->query("SELECT * FROM `$table` ORDER BY id")->fetchAll();
        }
        return $rows;
    };
    $unselectedShared = array_replace($sharedIntake, ['portal_user_id' => 0, 'license_plate' => 'SHARED-300']);
    $beforeRejectedIntake = $identitySnapshot();
    [$status, $body] = request($api, $unselectedShared, 'staff');
    check($status === 409 && str_contains($body, 'Use a saved customer profile'), 'An ambiguous phone-only intake asks staff to select a saved customer.');
    check($identitySnapshot() === $beforeRejectedIntake, 'Ambiguous phone-only intake leaves customer, vehicle, appointment and order records unchanged.');

    // Inactive profiles retain their identity; they must not be silently merged or ignored.
    $pdo->prepare('UPDATE users SET is_active = 0 WHERE id = ?')->execute([$sharedSecondId]);
    check(request($api, $sharedIntake, 'staff')[0] === 302, 'An inactive duplicate does not block the explicitly selected active customer.');
    $beforeRejectedIntake = $identitySnapshot();
    check(request($api, $unselectedShared, 'staff')[0] === 409, 'An inactive duplicate still prevents ambiguous phone-only matching.');
    check($identitySnapshot() === $beforeRejectedIntake, 'Rejected inactive-profile ambiguity preserves both profiles and ownership links.');

    $insertSharedCustomer->execute(['Different Phone Owner', 'different-phone@example.test', '0923456789']);
    $beforeRejectedIntake = $identitySnapshot();
    [$status] = request($api, array_replace($sharedIntake, ['customer_phone' => '092 345 6789', 'customer_name' => 'Must roll back']), 'staff');
    check($status === 409, 'Selecting a customer does not permit changing their phone to another existing customer number.');
    check($identitySnapshot() === $beforeRejectedIntake, 'A conflicting phone change rolls back contact, vehicle and order updates.');

    $beforeRejectedIntake = $identitySnapshot();
    check(request($api, array_replace($sharedIntake, ['portal_vehicle_id' => $vid]), 'staff')[0] >= 400, 'Shared-phone intake still rejects another customer garage vehicle.');
    check($identitySnapshot() === $beforeRejectedIntake, 'A tampered garage owner leaves all customer and vehicle data unchanged.');

    $pdo->prepare('INSERT INTO service_requests (user_id, full_name, phone, license_plate, vehicle, service_type, service) VALUES (?, ?, ?, ?, ?, ?, ?)')
        ->execute([$sharedFirstId, 'Shared Phone First Updated', '0912345678', 'SHARED-101', 'Shared Appointment Car', 'ENGINE DIAGNOSTIC SCAN', 'Check engine']);
    $sharedAppointmentId = (int) $pdo->lastInsertId();
    $sharedAppointmentIntake = array_replace($sharedIntake, ['portal_user_id' => 0, 'service_request_id' => $sharedAppointmentId, 'license_plate' => 'SHARED-101']);
    $beforeRejectedIntake = $identitySnapshot();
    check(request($api, array_replace($sharedAppointmentIntake, ['portal_user_id' => $sharedSecondId]), 'staff')[0] >= 400, 'A shared phone does not bypass the appointment customer identity check.');
    check($identitySnapshot() === $beforeRejectedIntake, 'A tampered appointment customer leaves the appointment and ownership links unchanged.');
    [$status, $body] = request($api, $sharedAppointmentIntake, 'staff');
    check($status === 302, 'An appointment supplies an authoritative customer identity despite shared phone numbers: ' . $body);
    $sharedAppointmentOrder = $pdo->query("SELECT * FROM repair_orders WHERE service_request_id = $sharedAppointmentId")->fetch();
    check($sharedAppointmentOrder && (int) $sharedAppointmentOrder['customer_id'] === $sharedFirstId
        && (int) $pdo->query('SELECT customer_id FROM vehicles WHERE id = ' . (int) $sharedAppointmentOrder['vehicle_id'])->fetchColumn() === $sharedFirstId,
        'Shared-phone appointment intake links its order and vehicle to the appointment owner.');
    check((int) $pdo->query("SELECT is_active FROM users WHERE id = $sharedSecondId")->fetchColumn() === 0
        && $pdo->query("SELECT * FROM vehicles WHERE id = $sharedOtherVehicleId")->fetch() === $sharedOtherVehicleBefore,
        'Successful appointment intake does not reactivate or reassign the duplicate profile.');

    // The shared helper also serves existing customer profile and garage operations.
    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute(['fixture-login', $sharedFirstId]);
    request('session.php?id=' . $sharedFirstId, null, 'sharedCustomer');
    [, $sharedProfileHtml] = request('customer/index.php?page=profile', null, 'sharedCustomer');
    $sharedProfile = ['csrf' => token($sharedProfileHtml), 'action' => 'update_profile', 'full_name' => 'Shared Phone First Updated',
        'email' => 'shared-first@example.test', 'phone' => '091.234.5678', 'zalo' => '', 'contact_method' => 'phone'];
    request('customer/index.php?page=profile', $sharedProfile, 'sharedCustomer');
    check($pdo->query("SELECT phone FROM users WHERE id = $sharedFirstId")->fetchColumn() === '091.234.5678', 'A profile can keep its existing shared number while changing its display format.');
    [, $sharedGarageHtml] = request('customer/index.php?page=vehicles', null, 'sharedCustomer');
    request('customer/index.php?page=vehicles', ['csrf' => token($sharedGarageHtml), 'action' => 'add_vehicle', 'make' => 'Shared', 'model' => 'Garage Car', 'plate' => 'SHARED-102'], 'sharedCustomer');
    $sharedGarageVehicle = $pdo->query("SELECT * FROM portal_vehicles WHERE user_id = $sharedFirstId AND plate = 'SHARED-102'")->fetch();
    check($sharedGarageVehicle && (int) $pdo->query('SELECT customer_id FROM vehicles WHERE id = ' . (int) $sharedGarageVehicle['workshop_vehicle_id'])->fetchColumn() === $sharedFirstId,
        'A customer garage operation uses the authenticated identity despite a shared phone number.');

    // Read history for legacy garage rows without requiring a save or rewriting workshop ownership.
    require_once $root . '/www/includes/customer_vehicle_sync.php';
    require_once $root . '/www/includes/customer_service_history.php';
    $insertHistoryCustomer = $pdo->prepare('INSERT INTO users (role_id, full_name, email, password_hash, is_customer) VALUES (4, ?, ?, ?, 1)');
    $insertHistoryCustomer->execute(['History Customer', 'history@example.test', 'fixture-login']);
    $historyCustomerId = (int) $pdo->lastInsertId();
    $insertHistoryCustomer->execute(['Other History Customer', 'other-history@example.test', 'fixture-login']);
    $otherHistoryCustomerId = (int) $pdo->lastInsertId();
    $historyWorkshop = static function (int $ownerId, string $plate) use ($pdo): int {
        $pdo->prepare('INSERT INTO vehicles (customer_id, license_plate, brand, model) VALUES (?, ?, ?, ?)')
            ->execute([$ownerId, $plate, 'History', 'Workshop Car']);
        return (int) $pdo->lastInsertId();
    };
    $historyGarage = static function (int $ownerId, string $plate, ?int $workshopId = null) use ($pdo): int {
        $pdo->prepare('INSERT INTO portal_vehicles (user_id, make, model, plate, workshop_vehicle_id) VALUES (?, ?, ?, ?, ?)')
            ->execute([$ownerId, 'History', $plate, $plate, $workshopId]);
        return (int) $pdo->lastInsertId();
    };
    $historyOrder = static function (int $workshopId, ?int $ownerId, string $code, string $title, string $status = 'delivered') use ($pdo): int {
        $pdo->prepare('INSERT INTO repair_orders (vehicle_id, customer_id, order_code, service_type, status, customer_request, symptoms, estimated_cost, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute([$workshopId, $ownerId, $code, $title, $status, 'History regression', 'Visible & <safe> notes', 123456, '2026-11-01 10:00:00']);
        return (int) $pdo->lastInsertId();
    };
    $stableWorkshopId = $historyWorkshop($historyCustomerId, 'HISTORY100');
    $stableGarageId = $historyGarage($historyCustomerId, 'HISTORY-NEW100', $stableWorkshopId);
    $legacyHistoryWorkshopId = $historyWorkshop($historyCustomerId, 'HISTORY200');
    $legacyHistoryGarageId = $historyGarage($historyCustomerId, 'HISTORY-200');
    $emptyHistoryGarageId = $historyGarage($historyCustomerId, 'HISTORY-EMPTY');
    $historyOrder($stableWorkshopId, $historyCustomerId, 'HIST-DELIVERED', 'Delivered stable repair');
    $historyOrder($stableWorkshopId, $historyCustomerId, 'HIST-COMPLETED', 'Completed stable repair', 'completed');
    $historyOrder($legacyHistoryWorkshopId, null, 'HIST-LEGACY', 'Legacy <diagnostic>');
    $historyOrder($legacyHistoryWorkshopId, $otherHistoryCustomerId, 'HIST-PRIVATE', 'Other customer private repair');
    $pdo->prepare('INSERT INTO service_orders (vehicle_id, title, status, estimate, notes, updated_at) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$stableGarageId, 'Older garage repair', 'completed', 76543, 'Garage history notes', '2026-10-01 10:00:00']);
    $foreignHistoryWorkshopId = $historyWorkshop($otherHistoryCustomerId, 'HISTORY300');
    $foreignPlateGarageId = $historyGarage($historyCustomerId, 'HISTORY-300');
    $foreignLinkedGarageId = $historyGarage($historyCustomerId, 'HISTORY-WRONG', $foreignHistoryWorkshopId);
    $historyOrder($foreignHistoryWorkshopId, $otherHistoryCustomerId, 'HIST-FOREIGN', 'Foreign vehicle private repair');
    $ambiguousGarageWorkshopId = $historyWorkshop($historyCustomerId, 'HISTORY400');
    $ambiguousGarageA = $historyGarage($historyCustomerId, 'HISTORY-400');
    $ambiguousGarageB = $historyGarage($historyCustomerId, 'HISTORY400');
    $historyOrder($ambiguousGarageWorkshopId, $historyCustomerId, 'HIST-AMB-G', 'Ambiguous garage repair');
    $ambiguousWorkshopA = $historyWorkshop($historyCustomerId, 'HISTORY500');
    $historyWorkshop($historyCustomerId, 'HISTORY-500');
    $ambiguousWorkshopGarageId = $historyGarage($historyCustomerId, 'HISTORY 500');
    $historyOrder($ambiguousWorkshopA, $historyCustomerId, 'HIST-AMB-W', 'Ambiguous workshop repair');
    $claimedWorkshopId = $historyWorkshop($historyCustomerId, 'HISTORY600');
    $historyGarage($otherHistoryCustomerId, 'HISTORY-CLAIM', $claimedWorkshopId);
    $claimedGarageId = $historyGarage($historyCustomerId, 'HISTORY-600');
    $historyOrder($claimedWorkshopId, $historyCustomerId, 'HIST-CLAIMED', 'Claimed workshop repair');
    $claimedStableGarageId = $historyGarage($historyCustomerId, 'HISTORY100');

    $historyBeforeRead = $identitySnapshot();
    $historyRows = rl_customer_service_history($pdo, $historyCustomerId);
    check(array_column($historyRows[$stableGarageId], 'title') === ['Completed stable repair', 'Delivered stable repair', 'Older garage repair'], 'Garage and workshop histories merge newest first with stable descending IDs for matching dates.');
    check(array_column($historyRows[$stableGarageId], 'status') === ['completed', 'delivered', 'completed'], 'Completed and delivered workshop visits remain in history.');
    check(count($historyRows[$legacyHistoryGarageId]) === 1 && $historyRows[$legacyHistoryGarageId][0]['order_code'] === 'HIST-LEGACY', 'A unique normalized legacy plate finds its own workshop order while excluding an explicit different order customer.');
    check((int) $historyRows[$legacyHistoryGarageId][0]['vehicle_id'] === $legacyHistoryGarageId, 'Workshop history maps back to the selected garage vehicle ID.');
    foreach ([$emptyHistoryGarageId, $foreignPlateGarageId, $foreignLinkedGarageId, $ambiguousGarageA, $ambiguousGarageB, $ambiguousWorkshopGarageId, $claimedGarageId, $claimedStableGarageId] as $emptyGarageId) {
        check($historyRows[$emptyGarageId] === [], 'Missing, foreign, ambiguous or already claimed workshop links expose no repair history: ' . $emptyGarageId);
    }
    check(rl_customer_service_history($pdo, $historyCustomerId) === $historyRows, 'Repeated history reads preserve deterministic ordering.');
    check($identitySnapshot() === $historyBeforeRead, 'Reading legacy history creates no vehicles and changes no saved links or ownership.');
    request('session.php?id=' . $historyCustomerId, null, 'historyCustomer');
    [$status, $selectedHistoryHtml] = request('customer/index.php?page=vehicles&vehicle=' . $legacyHistoryGarageId, null, 'historyCustomer');
    preg_match('/<section\b[^>]*id="history"[\s\S]*?<\/section>/', $selectedHistoryHtml, $selectedHistoryPanel);
    check($status === 200 && str_contains($selectedHistoryHtml, '?page=vehicles&amp;vehicle=' . $legacyHistoryGarageId . '#history'), 'Each service history link preserves the selected garage vehicle in its URL.');
    check(str_contains($selectedHistoryPanel[0] ?? '', 'Legacy &lt;diagnostic&gt;') && str_contains($selectedHistoryPanel[0] ?? '', 'Visible &amp; &lt;safe&gt; notes') && !str_contains($selectedHistoryPanel[0] ?? '', 'stable repair'), 'Selecting another garage vehicle displays only its escaped service history.');
    check(!str_contains($selectedHistoryHtml, 'Other customer private repair') && !str_contains($selectedHistoryHtml, 'Foreign vehicle private repair'), 'Customer HTML never exposes another customer\'s history.');
    [, $emptyHistoryHtml] = request('customer/index.php?page=vehicles&vehicle=' . $emptyHistoryGarageId, null, 'historyCustomer');
    preg_match('/<section\b[^>]*id="history"[\s\S]*?<\/section>/', $emptyHistoryHtml, $emptyHistoryPanel);
    check(str_contains($emptyHistoryPanel[0] ?? '', 'No service has been recorded') && !str_contains($emptyHistoryPanel[0] ?? '', 'Legacy &lt;diagnostic&gt;'), 'A vehicle without service records displays its own empty history state.');
    request('session.php?id=' . $otherHistoryCustomerId, null, 'otherHistoryCustomer');
    [, $foreignHistoryHtml] = request('customer/index.php?page=vehicles&vehicle=' . $legacyHistoryGarageId, null, 'otherHistoryCustomer');
    check(!str_contains($foreignHistoryHtml, 'Legacy &lt;diagnostic&gt;') && !str_contains($foreignHistoryHtml, 'Completed stable repair'), 'Forging another customer\'s garage vehicle ID does not reveal their history.');

    echo "PASS: $checks integration checks\n";
    if (in_array('--serve', $argv, true)) {
        echo "Fixture: http://127.0.0.1:$port/admin/orders/create.php?portal_user_id=7&portal_vehicle_id=$vid (staff cookie: PHPSESSID=syncstaff)\n";
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
