<?php
/** Public booking authentication checks using an isolated database and HTTP fixture. */
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

    $files = ['config/config.php', 'config/constants.php', 'config/database.php', 'includes/functions.php', 'includes/session.php', 'includes/auth.php', 'includes/service_catalog.php', 'includes/booking_prefill.php', 'pages/services.php', 'pages/performance.php', 'pages/fabrication.php', 'auth/login.php', 'auth/register.php'];
    foreach (['', '/nested'] as $prefix) {
        foreach ($files as $file) {
            $destination = $fixture . $prefix . '/' . $file;
            if (!is_dir(dirname($destination))) mkdir(dirname($destination), 0755, true);
            $content = file_get_contents($root . '/' . $file);
            if ($file === 'config/database.php') $content = str_replace("const DB_NAME = 'redlinev1';", "const DB_NAME = '$database';", $content);
            file_put_contents($destination, $content);
        }
        file_put_contents($fixture . $prefix . '/session.php', '<?php session_start(); $_SESSION = ["_identity_schema" => 2, "csrf" => "public-booking-token"]; if (($_GET["role"] ?? "") === "customer") { $_SESSION["portal_user_id"] = 2; } elseif (($_GET["role"] ?? "") === "staff") { $_SESSION += ["user_id" => 1, "username" => "bookingadmin", "role" => "Admin", "is_active" => 1]; } echo "ok";');
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
    foreach (['', '/nested'] as $prefix) {
        $scope = $prefix === '' ? 'root' : 'nested';
        foreach (['guest', 'customer', 'staff'] as $role) public_booking_request($prefix . '/session.php?role=' . $role, null, "$scope-$role");
        foreach (['services', 'performance', 'fabrication'] as $page) {
            $path = $prefix . '/pages/' . $page . '.php';
            $returnPath = 'pages/' . $page . '.php#book';
            $loginUrl = $prefix . '/auth/login.php?next=' . rawurlencode($returnPath);
            [$status, $guestHtml] = public_booking_request($path, null, "$scope-guest");
            public_booking_check($status === 200 && !str_contains($guestHtml, 'Fatal error'), "$scope/$page remains public to guests");
            public_booking_check(str_contains(html_entity_decode($guestHtml, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 'href="' . $loginUrl . '"'), "$scope/$page booking action sends guests to login with a return target");
            public_booking_check(!preg_match('/href="(?:#book|(?:services|performance|fabrication)\.php#book)"/', $guestHtml), "$scope/$page guest booking CTAs do not bypass login");
            public_booking_check(!preg_match('/<form\b[^>]*method="post"/i', $guestHtml), "$scope/$page guests see a login prompt instead of a booking form");
            $payload = ['csrf' => 'public-booking-token', 'website' => '', 'name' => 'Booking Customer', 'phone' => '0902222222', 'email' => 'customer@example.test', 'contact_method' => 'phone', 'make' => 'Test Make', 'model' => 'Booking Car', 'license_plate' => 'BOOK-100', 'year' => '2024', 'mileage' => '10000', 'color' => 'White', 'vin_number' => 'VINBOOKING12345', 'fuel_level' => '1/2', 'message' => 'Public booking authentication regression', 'service_type' => 'OIL & FILTER CHANGE', 'service' => $page === 'performance' ? 'STAGE 1 - DAILY RESPONSE' : 'CUSTOM EXHAUST SYSTEMS'];
            $before = (int) $pdo->query('SELECT COUNT(*) FROM service_requests')->fetchColumn();
            [$status, , $location] = public_booking_request($path, $payload, "$scope-guest", true);
            public_booking_check($status === 303 && $location === $loginUrl, "$scope/$page guest POST redirects to login before processing");
            public_booking_check((int) $pdo->query('SELECT COUNT(*) FROM service_requests')->fetchColumn() === $before && !is_dir($fixture . $prefix . '/uploads/requests'), "$scope/$page guest POST cannot save a booking or photo");
            foreach (['customer', 'staff'] as $role) {
                [$status, $html] = public_booking_request($path, null, "$scope-$role");
                public_booking_check($status === 200 && preg_match('/<form\b[^>]*method="post"/i', $html) && !str_contains($html, 'Fatal error'), "$scope/$page authenticated $role can open the existing form");
            }
            [$status, $invalidHtml] = public_booking_request($path, array_replace($payload, ['csrf' => 'invalid']), "$scope-customer");
            public_booking_check($status === 200 && str_contains($invalidHtml, 'Your session expired.') && (int) $pdo->query('SELECT COUNT(*) FROM service_requests')->fetchColumn() === $before, "$scope/$page authenticated booking still checks CSRF");
            [$status, $body] = public_booking_request($path, $payload, "$scope-customer");
            $saved = $pdo->query('SELECT user_id, service_type FROM service_requests ORDER BY id DESC LIMIT 1')->fetch();
            public_booking_check($status === 302 && (int) $pdo->query('SELECT COUNT(*) FROM service_requests')->fetchColumn() === $before + 1 && (int) ($saved['user_id'] ?? 0) === 2, "$scope/$page authenticated booking saves its customer identity: " . substr(strip_tags($body), 0, 300));

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
        foreach (['https://evil.example/', '//evil.example/', '/pages/services.php#book', 'pages/../admin/index.php', "pages/services.php#book\r\nLocation: https://evil.example/", ['bad' => 'value']] as $invalidNext) {
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
    echo "PASS: $checks public booking authentication checks\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    $pdo->exec("DROP DATABASE IF EXISTS `$database`");
    if (is_dir($fixture) && str_starts_with(basename($fixture), 'redline_public_booking_test_')) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($fixture, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($iterator as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($fixture);
    }
}
