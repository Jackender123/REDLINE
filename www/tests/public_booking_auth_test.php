<?php
/** Public booking and admin inbox checks using an isolated database and HTTP fixture. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

$root = dirname(__DIR__);
$database = 'redline_public_booking_test_' . bin2hex(random_bytes(5));
$fixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $database;
$pdo = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$server = null;
$checks = 0;
$cookies = [];
function public_booking_check(bool $ok, string $message): void {
    global $checks;
    if (!$ok) throw new RuntimeException($message);
    ++$checks;
}
function public_booking_request(string $path, ?array $data = null, string $session = 'guest', bool $withPhoto = false): array {
    global $port, $cookies;
    $contentType = 'application/x-www-form-urlencoded';
    $content = $data === null ? '' : http_build_query($data);
    if ($withPhoto) {
        $boundary = 'public-booking-' . bin2hex(random_bytes(8));
        $contentType = 'multipart/form-data; boundary=' . $boundary;
        $content = '';
        foreach ($data ?? [] as $name => $value) $content .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
        $photo = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jxLkAAAAASUVORK5CYII=', true);
        $content .= "--$boundary\r\nContent-Disposition: form-data; name=\"vehicle_photo\"; filename=\"car.png\"\r\nContent-Type: image/png\r\n\r\n$photo\r\n--$boundary--\r\n";
    }
    $context = stream_context_create(['http' => [
        'method' => $data === null ? 'GET' : 'POST', 'ignore_errors' => true, 'follow_location' => 0,
        'header' => 'Cookie: PHPSESSID=' . ($cookies[$session] ?? 'publicbooking-' . $session) . "\r\nContent-Type: $contentType\r\nConnection: close\r\n",
        'content' => $content, 'timeout' => 10,
    ]]);
    $stream = fopen("http://127.0.0.1:$port$path", 'r', false, $context);
    if (!$stream) throw new RuntimeException('HTTP request failed: ' . $path);
    $headers = stream_get_meta_data($stream)['wrapper_data'];
    $location = '';
    foreach ($headers as $header) {
        if (preg_match('/^Set-Cookie: PHPSESSID=([^;]+)/i', $header, $match)) $cookies[$session] = $match[1];
        if (str_starts_with($header, 'Location: ')) $location = substr($header, 10);
    }
    $body = (string) stream_get_contents($stream);
    fclose($stream);
    preg_match('/\s(\d{3})\s/', $headers[0] ?? '', $status);
    return [(int) ($status[1] ?? 0), $body, $location];
}
function public_booking_field(string $html, string $name): string {
    preg_match('/<input\b[^>]*name="' . preg_quote($name, '/') . '"[^>]*value="([^"]*)"/', $html, $match);
    if (!isset($match[1])) throw new RuntimeException('Missing form field: ' . $name);
    return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
}
function public_booking_options(string $html, string $name): array {
    preg_match('/<select\b[^>]*name="' . preg_quote($name, '/') . '"[^>]*>(.*?)<\/select>/s', $html, $select);
    if (!isset($select[1])) throw new RuntimeException('Missing service dropdown: ' . $name);
    preg_match_all('/<option\b[^>]*value="([^"]+)"/', $select[1], $options);
    return array_map(fn($value) => html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'), $options[1]);
}

try {
    $pdo->exec("CREATE DATABASE `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$database`");
    $sql = file_get_contents($root . '/database/schema.sql');
    $sql = preg_replace('/CREATE DATABASE IF NOT EXISTS `redlinev1`.*?;/s', '', $sql);
    $pdo->exec(str_replace('USE `redlinev1`;', "USE `$database`;", $sql));
    $pdo->exec("INSERT INTO roles (id, name) VALUES (1, 'Admin'), (4, 'Customers') ON DUPLICATE KEY UPDATE name = VALUES(name)");
    $insertUser = $pdo->prepare('INSERT INTO users (id, role_id, username, full_name, email, phone, password_hash, is_customer, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)');
    $insertUser->execute([1, 1, 'bookingadmin', 'Booking Admin', 'admin@example.test', '0901111111', password_hash('Staff-Password-123', PASSWORD_DEFAULT), 0]);
    $insertUser->execute([2, 4, null, 'Booking Customer', 'customer@example.test', '0902222222', password_hash('Customer-Password-123', PASSWORD_DEFAULT), 1]);
    $insertUser->execute([3, 4, null, 'Other Customer', 'other@example.test', '0903333333', password_hash('Other-Password-123', PASSWORD_DEFAULT), 1]);
    $pdo->exec("INSERT INTO portal_vehicles (id, user_id, make, model, plate, year, mileage, color) VALUES
        (10, 2, 'Mazda', 'Customer Garage Car', 'OWN-VEHICLE-10', 2024, 12000, 'Blue'),
        (11, 3, 'Ferrari', 'Other Garage Car', 'OTHER-VEHICLE-11', 2025, 1000, 'Red')");
    // Imported future timestamps must not bury a newly submitted request.
    $futureRequest = $pdo->prepare("INSERT INTO service_requests
        (full_name, phone, vehicle, service, status, created_at)
        VALUES (?, '0904444444', 'Imported Vehicle', 'Imported request', 'pending', '2099-01-01 12:00:00')");
    for ($index = 0; $index < 10; ++$index) $futureRequest->execute(['Future Imported ' . $index]);

    $files = ['config/config.php', 'config/constants.php', 'config/database.php', 'includes/functions.php', 'includes/session.php', 'includes/auth.php', 'includes/service_catalog.php', 'includes/booking_prefill.php', 'includes/repair_tracking.php', 'index.php', 'assets/css/style.css', 'assets/css/home-booking.css', 'assets/js/booking-prefill.js', 'pages/services.php', 'pages/performance.php', 'pages/fabrication.php', 'auth/login.php', 'auth/register.php', 'admin/index.php', 'includes/admin_unified_sidebar.php', 'assets/css/admin_unified_sidebar.css', 'admin/appointments/list.php', 'assets/css/appointments.css', 'assets/css/command-center.utilities.css', 'assets/css/index_management.css'];
    foreach (['', '/nested'] as $prefix) {
        foreach ($files as $file) {
            $destination = $fixture . $prefix . '/' . $file;
            if (!is_dir(dirname($destination))) mkdir(dirname($destination), 0755, true);
            $content = file_get_contents($root . '/' . $file);
            if ($file === 'config/database.php') {
                $content = str_replace("define('DB_NAME', rl_db_env('NAME', 'redlinev1'));", "define('DB_NAME', '$database');", $content, $replaced);
                if ($replaced !== 1) throw new RuntimeException('Cannot isolate fixture database.');
            }
            file_put_contents($destination, $content);
        }
        file_put_contents($fixture . $prefix . '/session.php', '<?php session_start(); $_SESSION = ["_identity_schema" => 2]; if (($_GET["role"] ?? "") === "customer") { $_SESSION["portal_user_id"] = 2; } elseif (($_GET["role"] ?? "") === "staff") { $_SESSION += ["user_id" => 1, "username" => "bookingadmin", "role" => "Admin", "is_active" => 1]; } echo "ok";');
    }
    file_put_contents($fixture . '/db_probe.php', '<?php require __DIR__ . "/config/database.php"; echo db()->query("SELECT DATABASE()")->fetchColumn();');
    foreach (['HOST'=>'127.0.0.1', 'PORT'=>'3306', 'USER'=>'root', 'PASS'=>'', 'NAME'=>$database] as $key=>$value) {
        putenv('REDLINE_DB_' . $key . '=' . $value);
    }
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
    [$probeStatus, $probeDatabase] = public_booking_request('/db_probe.php');
    public_booking_check($probeStatus === 200 && trim($probeDatabase) === $database, 'HTTP fixture uses only the isolated database.');
    foreach (['', '/nested'] as $prefix) {
        $scope = $prefix === '' ? 'root' : 'nested';
        foreach (['guest', 'customer', 'staff'] as $role) public_booking_request($prefix . '/session.php?role=' . $role, null, "$scope-$role");
        foreach (['guest', 'customer'] as $role) {
            foreach (['/admin/appointments/list.php?refresh=1', '/admin/index.php?refresh=appointments'] as $refreshPath) {
                [$status, $body] = public_booking_request($prefix . $refreshPath, null, "$scope-$role");
                $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                public_booking_check(in_array($status, [401, 403], true) && ($response['ok'] ?? null) === false,
                    "$scope admin refresh rejects $role without exposing requests");
            }
        }
        // Legacy public progress search must not exist (customer dashboard tracking is retained).
        [$status] = public_booking_request($prefix . '/pages/track.php', null, "$scope-guest");
        public_booking_check($status === 404, "$scope removed track.php is not accessible");

        // Homepage consultation is no longer a fake alert; it requires authentication
        // and inserts the same full vehicle/contact/service fields as service pages.
        [$status, $guestHome] = public_booking_request($prefix . '/index.php', null, "$scope-guest");
        $homeLoginUrl = $prefix . '/auth/login.php?next=' . rawurlencode('index.php#book');
        public_booking_check($status === 200 && !str_contains($guestHome, 'name="home_booking"')
            && str_contains($guestHome, $homeLoginUrl) && !str_contains($guestHome, 'pages/track.php'),
            "$scope homepage guests see no consultation form and are sent to sign in");
        $homePayload = [
            'home_booking' => '1', 'csrf' => 'guest-invalid', 'website' => '',
            'name' => 'Homepage Customer', 'phone' => '0902222222',
            'email' => 'customer@example.test', 'contact_method' => 'phone',
            'make' => 'Mazda', 'model' => 'MX-5', 'license_plate' => 'HOME-BOOK',
            'year' => '2024', 'mileage' => '33000', 'color' => 'Blue',
            'vin_number' => 'VINHOME12345', 'fuel_level' => '1/2',
            'service_type' => 'PERFORMANCE: STAGE 1 - DAILY RESPONSE',
            'message' => 'Full consultation booking from homepage',
        ];
        $before = (int) $pdo->query('SELECT COUNT(*) FROM service_requests')->fetchColumn();
        [$status, , $location] = public_booking_request($prefix . '/index.php', $homePayload, "$scope-guest");
        public_booking_check($status === 303 && $location === $homeLoginUrl
            && (int) $pdo->query('SELECT COUNT(*) FROM service_requests')->fetchColumn() === $before,
            "$scope guests cannot submit homepage consultations");
        [$status, $customerHome] = public_booking_request($prefix . '/index.php', null, "$scope-customer");
        public_booking_check($status === 200 && str_contains($customerHome, 'name="home_booking"')
            && str_contains($customerHome, 'OWN-VEHICLE-10') && !str_contains($customerHome, 'OTHER-VEHICLE-11')
            && str_contains($customerHome, 'name="vehicle_photo"') && str_contains($customerHome, 'name="contact_method"'),
            "$scope customer homepage has full and private booking fields");
        [$status, $staffHome] = public_booking_request($prefix . '/index.php', null, "$scope-staff");
        public_booking_check($status === 200 && str_contains($staffHome, 'name="home_booking"'),
            "$scope signed-in staff can open the consultation form");
        [$status, $invalidHome] = public_booking_request($prefix . '/index.php', array_replace($homePayload, ['csrf' => 'invalid']), "$scope-customer");
        public_booking_check($status === 200 && str_contains($invalidHome, 'Your session expired.')
            && (int) $pdo->query('SELECT COUNT(*) FROM service_requests')->fetchColumn() === $before,
            "$scope invalid homepage CSRF is rejected");
        $homePayload['csrf'] = public_booking_field($customerHome, 'csrf');
        [$status, , $location] = public_booking_request($prefix . '/index.php', $homePayload, "$scope-customer");
        $savedHome = $pdo->query('SELECT * FROM service_requests ORDER BY id DESC LIMIT 1')->fetch();
        public_booking_check($status === 303 && $location === $prefix . '/index.php#book'
            && (int) $pdo->query('SELECT COUNT(*) FROM service_requests')->fetchColumn() === $before + 1
            && (int) $savedHome['user_id'] === 2 && $savedHome['status'] === 'pending'
            && $savedHome['service_type'] === $homePayload['service_type']
            && $savedHome['license_plate'] === $homePayload['license_plate']
            && $savedHome['message'] === $homePayload['message'],
            "$scope signed-in customer consultation saves a real pending request");
        [$status, $successHome] = public_booking_request($prefix . '/index.php', null, "$scope-customer");
        public_booking_check($status === 200 && str_contains($successHome, 'Your request has been saved.'),
            "$scope homepage confirms a real saved booking");
        [$status, $homeLogin] = public_booking_request($homeLoginUrl, null, "$scope-guest");
        public_booking_check($status === 200 && public_booking_field($homeLogin, 'next') === 'index.php#book',
            "$scope homepage login preserves the return to consultation modal");
        foreach (['services', 'performance', 'fabrication'] as $page) {
            $path = $prefix . '/pages/' . $page . '.php';
            $returnPath = 'pages/' . $page . '.php#book';
            $loginUrl = $prefix . '/auth/login.php?next=' . rawurlencode($returnPath);
            [$status, $guestHtml] = public_booking_request($path, null, "$scope-guest");
            public_booking_check($status === 200 && !str_contains($guestHtml, 'Fatal error'), "$scope/$page remains public to guests");
            public_booking_check(str_contains(html_entity_decode($guestHtml, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'href="' . $loginUrl . '"'), "$scope/$page booking action sends guests to login with a return target");
            public_booking_check(!preg_match('/href="(?:#book|(?:services|performance|fabrication)\.php#book)"/', $guestHtml), "$scope/$page guest booking CTAs do not bypass login");
            public_booking_check(!preg_match('/<form\b[^>]*method="post"/i', $guestHtml), "$scope/$page guests see a login prompt instead of a booking form");
            $payload = ['csrf' => 'forged-guest-token', 'website' => '', 'name' => 'Booking Customer', 'phone' => '0902222222', 'email' => 'customer@example.test', 'contact_method' => 'phone', 'make' => 'Test Make', 'model' => 'Booking Car', 'license_plate' => 'BOOK-100', 'year' => '2024', 'mileage' => '10000', 'color' => 'White', 'vin_number' => 'VINBOOKING12345', 'fuel_level' => '1/2', 'message' => 'Public booking authentication regression', 'service_type' => 'OIL & FILTER CHANGE', 'service' => $page === 'performance' ? 'STAGE 1 - DAILY RESPONSE' : 'CUSTOM EXHAUST SYSTEMS'];
            $before = (int) $pdo->query('SELECT COUNT(*) FROM service_requests')->fetchColumn();
            [$status, , $location] = public_booking_request($path, $payload, "$scope-guest", true);
            public_booking_check($status === 303 && $location === $loginUrl, "$scope/$page guest POST redirects to login before processing");
            public_booking_check((int) $pdo->query('SELECT COUNT(*) FROM service_requests')->fetchColumn() === $before && !is_dir($fixture . $prefix . '/uploads/requests'), "$scope/$page guest POST cannot save a booking or photo");
            foreach (['customer', 'staff'] as $role) {
                [$status, $html] = public_booking_request($path, null, "$scope-$role");
                public_booking_check($status === 200 && preg_match('/<form\b[^>]*method="post"/i', $html) && !str_contains($html, 'Fatal error'), "$scope/$page authenticated $role can open the existing form");
                if ($role === 'customer') $customerHtml = $html;
            }
            if ($page === 'fabrication') {
                public_booking_check(str_contains($customerHtml, 'OWN-VEHICLE-10') && !str_contains($customerHtml, 'OTHER-VEHICLE-11') && !str_contains($customerHtml, '68A118223'),
                    "$scope Fabrication picker uses only the signed-in customer's actual garage");
                public_booking_check(!str_contains($html, 'f-saved-vehicle'), "$scope Fabrication staff form has no customer garage picker");
            }
            [$status, $invalidHtml] = public_booking_request($path, array_replace($payload, ['csrf' => 'invalid']), "$scope-customer");
            public_booking_check($status === 200 && str_contains($invalidHtml, 'Your session expired.') && (int) $pdo->query('SELECT COUNT(*) FROM service_requests')->fetchColumn() === $before, "$scope/$page authenticated booking still checks CSRF");
            $serviceField = $page === 'services' ? 'service_type' : 'service';
            $serviceOptions = public_booking_options($customerHtml, $serviceField);
            public_booking_check(count($serviceOptions) >= 5, "$scope/$page renders its complete service dropdown");
            foreach ($serviceOptions as $optionIndex => $serviceValue) {
                $before = (int) $pdo->query('SELECT COUNT(*) FROM service_requests')->fetchColumn();
                $message = "$scope/$page option $optionIndex — inspect brakes & \"exhaust\" <details>";
                $payload['csrf'] = public_booking_field($customerHtml, 'csrf');
                $payload[$serviceField] = $serviceValue;
                $payload['message'] = $message;
                $payload['license_plate'] = strtoupper($scope) . '-' . strtoupper(substr($page, 0, 3)) . '-' . $optionIndex;
                [$status, $body, $location] = public_booking_request($path, $payload, "$scope-customer");
                $saved = $pdo->query('SELECT * FROM service_requests ORDER BY id DESC LIMIT 1')->fetch();
                $serviceLabel = ($page === 'services' ? '' : strtoupper($page) . ': ') . $serviceValue;
                public_booking_check($status === 302 && $location === $page . '.php#book'
                    && (int) $pdo->query('SELECT COUNT(*) FROM service_requests')->fetchColumn() === $before + 1
                    && (int) ($saved['user_id'] ?? 0) === 2 && $saved['status'] === 'pending'
                    && $saved['service_type'] === $serviceLabel && $saved['service'] === $serviceLabel
                    && $saved['message'] === $message && $saved['license_plate'] === $payload['license_plate'],
                    "$scope/$page option $optionIndex saves a pending request with its exact details: " . substr(strip_tags($body), 0, 300));
                [$status, $inboxBody] = public_booking_request($prefix . '/admin/appointments/list.php?refresh=1', null, "$scope-staff");
                $inbox = json_decode($inboxBody, true, 512, JSON_THROW_ON_ERROR);
                $first = $inbox['appointments'][0] ?? [];
                public_booking_check($status === 200 && ($inbox['ok'] ?? false) && (int) ($first['id'] ?? 0) === (int) $saved['id']
                    && ($first['full_name'] ?? '') === $payload['name'] && ($first['phone'] ?? '') === $payload['phone']
                    && ($first['vehicle_model'] ?? '') === 'Test Make Booking Car 2024'
                    && ($first['license_plate'] ?? '') === $payload['license_plate']
                    && ($first['service_type'] ?? '') === $serviceLabel && ($first['message'] ?? '') === $message
                    && ($first['status'] ?? '') === 'pending',
                    "$scope/$page option $optionIndex appears first in the live admin inbox with its exact details");
                [$status, $overviewBody] = public_booking_request($prefix . '/admin/index.php?refresh=appointments', null, "$scope-staff");
                $overview = json_decode($overviewBody, true, 512, JSON_THROW_ON_ERROR);
                $pendingTotal = (int) $pdo->query("SELECT COUNT(*) FROM service_requests WHERE status = 'pending'")->fetchColumn();
                public_booking_check($status === 200 && ($overview['ok'] ?? false)
                    && (int) ($overview['pending_total'] ?? 0) === $pendingTotal && $pendingTotal > 8
                    && count($overview['pending_requests'] ?? []) === 8
                    && (int) ($overview['pending_requests'][0]['id'] ?? 0) === (int) $saved['id'],
                    "$scope/$page option $optionIndex reaches the overview's latest requests and complete pending total");
            }
            [$status, $inboxHtml] = public_booking_request($prefix . '/admin/appointments/list.php', null, "$scope-staff");
            preg_match('/(?:const|let) apptData = (\[.*?\]);/s', $inboxHtml, $inboxData);
            $initialAppointments = json_decode($inboxData[1] ?? 'null', true, 512, JSON_THROW_ON_ERROR);
            public_booking_check($status === 200 && (int) ($initialAppointments[0]['id'] ?? 0) === (int) $saved['id'],
                "$scope/$page request also appears first when opening the admin inbox");
            [$status, $overviewHtml] = public_booking_request($prefix . '/admin/index.php', null, "$scope-staff");
            public_booking_check($status === 200 && str_contains($overviewHtml, 'service_request_id=' . $saved['id'])
                && str_contains($overviewHtml, $payload['license_plate']),
                "$scope/$page request is visible in the rendered admin overview despite imported future timestamps");

            $loginSession = "$scope-login-$page";
            [, $loginHtml] = public_booking_request($loginUrl, null, $loginSession);
            public_booking_check(public_booking_field($loginHtml, 'next') === $returnPath, "$scope/$page login form carries its validated return target");
            [$status, , $location] = public_booking_request($prefix . '/auth/login.php', ['csrf' => public_booking_field($loginHtml, 'csrf'), 'next' => public_booking_field($loginHtml, 'next'), 'email' => 'customer@example.test', 'password' => 'Customer-Password-123'], $loginSession);
            public_booking_check($status === 302 && $location === $prefix . '/' . $returnPath, "$scope/$page successful login returns to its booking section");
            [$status, , $location] = public_booking_request($loginUrl, null, "$scope-customer");
            public_booking_check($status === 302 && $location === $prefix . '/' . $returnPath, "$scope/$page already-authenticated customer returns directly to booking");
            [$status, , $location] = public_booking_request($loginUrl, null, "$scope-staff");
            public_booking_check($status === 302 && $location === $prefix . '/' . $returnPath, "$scope/$page authenticated staff do not enter a login loop");
        }
        foreach (['https://evil.example/', '//evil.example/', '/pages/services.php#book', 'index.php#book#unexpected', 'pages/../admin/index.php', "pages/services.php#book\r\nLocation: https://evil.example/", ['bad' => 'value']] as $invalidNext) {
            [$status, , $location] = public_booking_request($prefix . '/auth/login.php?' . http_build_query(['next' => $invalidNext]), null, "$scope-customer");
            public_booking_check($status === 302 && in_array($location, ['../index.php', $prefix . '/index.php'], true), "$scope rejects an unsafe or unsupported login return target");
        }
        $loginUrl = $prefix . '/auth/login.php?next=' . rawurlencode('pages/services.php#book');
        [, $loginHtml] = public_booking_request($loginUrl, null, "$scope-failed-login");
        [$status, $failedLogin] = public_booking_request($prefix . '/auth/login.php', ['csrf' => public_booking_field($loginHtml, 'csrf'), 'next' => 'pages/services.php#book', 'email' => 'customer@example.test', 'password' => 'Incorrect-Password'], "$scope-failed-login");
        public_booking_check($status === 200 && public_booking_field($failedLogin, 'next') === 'pages/services.php#book', "$scope failed login retains the booking return target");
        [, $staffLoginHtml] = public_booking_request($loginUrl, null, "$scope-staff-login");
        [$status, , $location] = public_booking_request($prefix . '/auth/login.php', ['csrf' => public_booking_field($staffLoginHtml, 'csrf'), 'next' => 'pages/services.php#book', 'email' => 'admin@example.test', 'password' => 'Staff-Password-123'], "$scope-staff-login");
        public_booking_check($status === 302 && $location === $prefix . '/pages/services.php#book', "$scope successful staff login honors the booking return target");
    }
    $pdo->exec('RENAME TABLE service_requests TO unavailable_service_requests');
    try {
        foreach (['/admin/appointments/list.php?refresh=1' => 'error', '/admin/index.php?refresh=appointments' => 'message'] as $path => $errorField) {
            [$status, $body] = public_booking_request($path, null, 'root-staff');
            $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
            public_booking_check($status === 503 && ($response['ok'] ?? null) === false && !empty($response[$errorField]),
                'An unavailable request table returns an explicit admin refresh error.');
        }
    } finally {
        $pdo->exec('RENAME TABLE unavailable_service_requests TO service_requests');
    }
    echo "PASS: $checks public booking and admin inbox checks\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    $pdo->exec("DROP DATABASE IF EXISTS `$database`");
    if (is_dir($fixture) && str_starts_with(basename($fixture), 'redline_public_booking_test_')) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($fixture);
    }
}
