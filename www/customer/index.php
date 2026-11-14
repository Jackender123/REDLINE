<?php
/**
 * Redline — Customer Portal (index.php)
 *
 * Features added, based on "Customer Service Registration Form" (docx):
 *   PROFILE  (section 1 – Customer Information)
 *     - full name, phone (required), email, preferred contact method (Phone / Zalo / Email)
 *     - real save with validation, change password (verifies current password), notification
 *       switches saved to the database
 *   MY VEHICLES (section 2 – Vehicle Information; section 5 – optional photos)
 *     - add / edit / delete vehicle: plate, make, model, year, mileage, colour (optional), photo (optional)
 *     - shared vehicle details, optional VIN and powertrain sync with workshop intake
 *     - duplicate plate check, delete blocked while a repair order is active
 *     - service history is read from the database per vehicle
 *
 * Missing DB columns are created automatically once per session (see rl_ensureSchema()).
 * The MySQL user therefore needs ALTER privilege the first time, or run the SQL from the notes manually.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/customer_vehicle_sync.php';
require_once __DIR__ . '/../includes/customer_service_history.php';
require_once __DIR__ . '/../includes/service_catalog.php';
require_once __DIR__ . '/../includes/customer_fabrication.php';

if (isset($_GET['repair_progress']) && !current_user()) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    exit(json_encode(['error' => 'Please sign in again.']));
}
$user = require_login();
header('Cache-Control: no-store');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

/* ---------- Generic helpers (guarded to avoid "Cannot redeclare" errors) ---------- */

if (!function_exists('e')) {
    function e($v): string
    {
        return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('formatVND')) {
    function formatVND(int $n): string
    {
        return 'VND ' . number_format($n, 0, '.', ',');
    }
}

function rl_flash(string $type, string $msg): void
{
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function rl_redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function rl_fail(string $msg, string $to): void
{
    rl_flash('error', $msg);
    rl_redirect($to);
}

function rl_csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['_csrf'];
}

function rl_csrf_ok(): bool
{
    return isset($_POST['csrf']) && hash_equals(rl_csrf_token(), (string) $_POST['csrf']);
}

function rl_csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . e(rl_csrf_token()) . '">';
}

function rl_agoText(?string $dt): string
{
    if (!$dt) {
        return 'Never changed';
    }
    $d = (int) floor((time() - (strtotime($dt) ?: time())) / 86400);
    if ($d < 1)  return 'Updated today';
    if ($d < 30) return 'Updated ' . $d . ' day' . ($d > 1 ? 's' : '') . ' ago';
    $m = intdiv($d, 30);
    if ($m < 12) return 'Updated ' . $m . ' month' . ($m > 1 ? 's' : '') . ' ago';
    $y = intdiv($d, 365);
    return 'Updated ' . $y . ' year' . ($y > 1 ? 's' : '') . ' ago';
}

/* ---------- Schema upgrade (runs once per session, only adds what is missing) ---------- */

function rl_ensureSchema(): void
{
    if (!empty($_SESSION['_schema_v3'])) {
        return;
    }
    $need = [
        'users' => [
            'contact_method'      => "ENUM('phone','zalo','email') NOT NULL DEFAULT 'phone'",
            'notify_repair'       => 'TINYINT(1) NOT NULL DEFAULT 1',
            'notify_reminder'     => 'TINYINT(1) NOT NULL DEFAULT 1',
            'notify_offers'       => 'TINYINT(1) NOT NULL DEFAULT 0',
            'zalo'                => 'VARCHAR(30) NULL',
            'password_changed_at' => 'DATETIME NULL',
        ],
        'portal_vehicles' => [
            'mileage'    => 'INT UNSIGNED NULL',
            'color'      => 'VARCHAR(40) NULL',
            'photo'      => 'VARCHAR(255) NULL',
            'updated_at' => 'DATETIME NULL',
        ],
    ];
    try {
        $q = db()->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t');
        foreach ($need as $table => $cols) {
            $q->execute(['t' => $table]);
            $have = array_map('strtolower', $q->fetchAll(PDO::FETCH_COLUMN));
            foreach ($cols as $col => $def) {
                if (!in_array($col, $have, true)) {
                    db()->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
                }
            }
        }
        $_SESSION['_schema_v3'] = true;
    } catch (Throwable $ex) {
        error_log('ensureSchema: ' . $ex->getMessage());
    }
}

rl_ensureSchema();
rl_sync_ensure_schema(db());

/* ---------- Vehicle helpers ---------- */

/** Validate the "Vehicle Information" fields (docx section 2). Returns [data|null, error|null]. */
function rl_vehicleInput(array $p): array
{
    $make     = trim((string) ($p['make'] ?? ''));
    $model    = trim((string) ($p['model'] ?? ''));
    $plate    = strtoupper(preg_replace('/\s+/', ' ', trim((string) ($p['plate'] ?? ''))));
    $yearRaw  = trim((string) ($p['year'] ?? ''));
    $kmRaw    = preg_replace('/[\s.,]/', '', (string) ($p['mileage'] ?? ''));
    $color    = trim((string) ($p['color'] ?? ''));
    $vin = strtoupper(trim((string) ($p['vin'] ?? '')));
    $engineType = (string) ($p['engine_type'] ?? 'gasoline');
    $capacity = trim((string) ($p['battery_capacity'] ?? ''));
    $soh = trim((string) ($p['battery_soh'] ?? ''));
    if (strlen($vin) > 50 || !in_array($engineType, ['gasoline', 'diesel', 'hybrid', 'ev'], true)) {
        return [null, 'Please check the VIN and powertrain.'];
    }
    if (($capacity !== '' && (!is_numeric($capacity) || (float) $capacity < 0 || (float) $capacity > 9999.99))
        || ($soh !== '' && (!is_numeric($soh) || (float) $soh < 0 || (float) $soh > 100))) {
        return [null, 'Please enter a valid battery capacity and battery health (0–100%).'];
    }

    if ($make === '' || $model === '' || $plate === '') {
        return [null, 'Please enter at least the make, model and license plate.'];
    }
    if (!preg_match('/^[0-9A-Z][0-9A-Z .\-]{2,18}[0-9A-Z]$/', $plate)) {
        return [null, 'The license plate looks invalid. Example: 51G-886.28'];
    }
    $year = null;
    if ($yearRaw !== '') {
        if (!ctype_digit($yearRaw) || (int) $yearRaw < 1950 || (int) $yearRaw > (int) date('Y') + 1) {
            return [null, 'Year of manufacture must be between 1950 and ' . ((int) date('Y') + 1) . '.'];
        }
        $year = (int) $yearRaw;
    }
    $mileage = null;
    if ($kmRaw !== '') {
        if (!ctype_digit($kmRaw) || strlen($kmRaw) > 7) {
            return [null, 'Current mileage must be a number (km).'];
        }
        $mileage = (int) $kmRaw;
    }
    return [[
        'make'    => mb_substr($make, 0, 60),
        'model'   => mb_substr($model, 0, 80),
        'year'    => $year,
        'plate'   => mb_substr($plate, 0, 20),
        'mileage' => $mileage,
        'color'   => $color !== '' ? mb_substr($color, 0, 40) : null,
        'vin' => $vin !== '' ? $vin : null,
        'engine_type' => $engineType,
        'battery_capacity' => in_array($engineType, ['ev', 'hybrid'], true) && $capacity !== '' ? $capacity : null,
        'battery_soh' => in_array($engineType, ['ev', 'hybrid'], true) && $soh !== '' ? $soh : null,
    ], null];
}

/** Optional vehicle photo (docx section 5). Returns [relative path|null, error|null]. */
function rl_saveVehiclePhoto(array $f): array
{
    $err = $f['error'] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    if ($err !== UPLOAD_ERR_OK) {
        return [null, 'The photo could not be uploaded. Please try again.'];
    }
    if (($f['size'] ?? 0) > 5 * 1024 * 1024) {
        return [null, 'The photo must be 5 MB or smaller.'];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
    if (!$ext || !@getimagesize($f['tmp_name'])) {
        return [null, 'Please upload a JPG, PNG or WebP image.'];
    }
    $dir = APP_ROOT . '/uploads/vehicles';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        return [null, 'The upload folder is not writable.'];
    }
    $name = bin2hex(random_bytes(10)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
        return [null, 'The photo could not be saved.'];
    }
    return ['uploads/vehicles/' . $name, null];
}

function rl_deleteVehiclePhoto(?string $path): void
{
    $path = normalize_upload_path((string) $path);
    if (str_starts_with($path, 'uploads/vehicles/')) {
        $file = APP_ROOT . '/' . $path;
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

/* ---------- POST handlers ---------- */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');

    /* JSON endpoint: notification switches */
    if ($action === 'save_notify') {
        header('Content-Type: application/json');
        $cols = ['repair' => 'notify_repair', 'reminder' => 'notify_reminder', 'offers' => 'notify_offers'];
        $key  = (string) ($_POST['key'] ?? '');
        if (!rl_csrf_ok() || !isset($cols[$key])) {
            http_response_code(400);
            echo json_encode(['ok' => false]);
            exit;
        }
        $upd = db()->prepare('UPDATE users SET ' . $cols[$key] . ' = :v WHERE id = :id');
        $upd->execute(['v' => ($_POST['value'] ?? '0') === '1' ? 1 : 0, 'id' => $user['id']]);
        echo json_encode(['ok' => true]);
        exit;
    }

    $back = in_array($action, ['update_profile', 'change_password'], true) ? '?page=profile' : '?page=vehicles';
    if (!rl_csrf_ok()) {
        rl_fail('Your session expired. Please try again.', $back);
    }

    try {
    switch ($action) {

        case 'add_vehicle':
        case 'update_vehicle':
            [$data, $error] = rl_vehicleInput($_POST);
            if ($error) {
                rl_fail($error, $back);
            }

            $vid = (int) ($_POST['id'] ?? 0);
            $old = null;
            if ($action === 'update_vehicle') {
                $s = db()->prepare('SELECT id, photo FROM portal_vehicles WHERE id = :id AND user_id = :uid');
                $s->execute(['id' => $vid, 'uid' => $user['id']]);
                $old = $s->fetch();
                if (!$old) {
                    rl_fail('Vehicle not found.', $back);
                }
            }

            $dup = db()->prepare('SELECT id FROM portal_vehicles WHERE user_id = :uid AND plate = :plate AND id <> :id LIMIT 1');
            $dup->execute(['uid' => $user['id'], 'plate' => $data['plate'], 'id' => $vid]);
            if ($dup->fetch()) {
                rl_fail('A vehicle with the license plate ' . $data['plate'] . ' is already in your garage.', $back);
            }

            $photoStarted = microtime(true);
            [$photo, $photoErr] = rl_saveVehiclePhoto($_FILES['photo'] ?? []);
            $photoMs = (microtime(true) - $photoStarted) * 1000;
            if ($photoErr) {
                rl_fail($photoErr, $back);
            }

            $databaseStarted = microtime(true);
            db()->beginTransaction();
            $customerId = rl_sync_customer(db(), (int) $user['id']);
            if ($action === 'add_vehicle') {
                $ins = db()->prepare(
                    'INSERT INTO portal_vehicles (user_id, make, model, year, plate, mileage, color, vin, engine_type, battery_capacity, battery_soh, photo, updated_at)
                     VALUES (:uid, :make, :model, :year, :plate, :mileage, :color, :vin, :engine_type, :battery_capacity, :battery_soh, :photo, NOW())'
                );
                $ins->execute($data + ['uid' => $user['id'], 'photo' => $photo]);
                $vid = (int) db()->lastInsertId();
                rl_flash('success', 'Vehicle added successfully.');
            } else {
                $keep = $old['photo'] ?? null;
                if ($photo) {
                    $keep = $photo;
                } elseif (!empty($_POST['remove_photo'])) {
                    $keep = null;
                }
                $upd = db()->prepare(
                    'UPDATE portal_vehicles
                        SET make = :make, model = :model, year = :year, plate = :plate,
                            mileage = :mileage, color = :color, vin = :vin, engine_type = :engine_type,
                            battery_capacity = :battery_capacity, battery_soh = :battery_soh, photo = :photo, updated_at = NOW()
                      WHERE id = :id AND user_id = :uid'
                );
                $upd->execute($data + ['photo' => $keep, 'id' => $vid, 'uid' => $user['id']]);
                rl_flash('success', 'Vehicle updated.');
            }
            rl_sync_vehicle(db(), $vid, (int) $user['id'], $customerId);
            db()->commit();
            $databaseMs = (microtime(true) - $databaseStarted) * 1000;
            if ($old && ($photo || !empty($_POST['remove_photo']))) rl_deleteVehiclePhoto($old['photo'] ?? null);
            $requestMs = (microtime(true) - ($_SERVER['REQUEST_TIME_FLOAT'] ?? $photoStarted)) * 1000;
            header(sprintf('Server-Timing: vehicle_photo;dur=%.1f, vehicle_database;dur=%.1f, vehicle_request;dur=%.1f', $photoMs, $databaseMs, $requestMs));
            if ($requestMs >= 2000) {
                error_log(sprintf('Slow vehicle save: request=%.1fms photo=%.1fms database=%.1fms', $requestMs, $photoMs, $databaseMs));
            }
            rl_redirect('?page=vehicles');

        case 'delete_vehicle':
            $vid = (int) ($_POST['id'] ?? 0);
            $s = db()->prepare('SELECT id, photo FROM portal_vehicles WHERE id = :id AND user_id = :uid');
            $s->execute(['id' => $vid, 'uid' => $user['id']]);
            $veh = $s->fetch();
            if (!$veh) {
                rl_fail('Vehicle not found.', $back);
            }
            $c = db()->prepare("SELECT COUNT(*) FROM service_orders WHERE vehicle_id = :v AND status NOT IN ('completed','cancelled')");
            $c->execute(['v' => $vid]);
            if ((int) $c->fetchColumn() > 0) {
                rl_fail('This vehicle has an active repair order, so it cannot be removed right now.', $back);
            }
            $c = db()->prepare("SELECT COUNT(*) FROM repair_orders r JOIN portal_vehicles v ON v.workshop_vehicle_id = r.vehicle_id WHERE v.id = ? AND v.user_id = ? AND r.status <> 'delivered'");
            $c->execute([$vid, $user['id']]);
            if ((int) $c->fetchColumn() > 0) {
                rl_fail('This vehicle has an active workshop repair order, so it cannot be removed right now.', $back);
            }
            db()->prepare('DELETE FROM portal_vehicles WHERE id = :id AND user_id = :uid')
                ->execute(['id' => $vid, 'uid' => $user['id']]);
            rl_deleteVehiclePhoto($veh['photo'] ?? null);
            rl_flash('success', 'Vehicle removed from your garage.');
            rl_redirect('?page=vehicles');

        case 'update_profile':
            $name   = mb_substr(trim((string) ($_POST['full_name'] ?? '')), 0, 120);
            $phone  = trim((string) ($_POST['phone'] ?? ''));
            $email  = trim((string) ($_POST['email'] ?? ''));
            $zalo   = trim((string) ($_POST['zalo'] ?? ''));
            $method = (string) ($_POST['contact_method'] ?? 'phone');
            $digits = preg_replace('/\D/', '', $phone);

            if ($name === '') {
                rl_fail('Please enter your full name.', $back);
            }
            if (!preg_match('/^\+?[0-9][0-9 .\-()]{5,19}$/', $phone) || strlen($digits) < 8 || strlen($digits) > 15) {
                rl_fail('Please enter a valid phone number (8–15 digits).', $back);
            }
            $zdigits = preg_replace('/\D/', '', $zalo);
            if ($zalo !== '' && (!preg_match('/^\+?[0-9][0-9 .\-()]{5,19}$/', $zalo) || strlen($zdigits) < 8 || strlen($zdigits) > 15)) {
                rl_fail('Please enter a valid Zalo number (8–15 digits).', $back);
            }
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
                rl_fail('Please enter a valid email address.', $back);
            }
            if (!in_array($method, ['phone', 'zalo', 'email'], true)) {
                $method = 'phone';
            }
            if ($method === 'zalo' && $zalo === '') {
                rl_fail('Please enter your Zalo number to use Zalo as your preferred contact method.', $back);
            }
            $dup = db()->prepare('SELECT id FROM users WHERE email = :e AND id <> :id LIMIT 1');
            $dup->execute(['e' => $email, 'id' => $user['id']]);
            if ($dup->fetch()) {
                rl_fail('That email address is already used by another account.', $back);
            }
            db()->beginTransaction();
            $s = db()->prepare('SELECT * FROM users WHERE id = ?');
            $s->execute([$user['id']]);
            $previous = $s->fetch(PDO::FETCH_ASSOC);
            db()->prepare('UPDATE users SET full_name = :n, phone = :p, zalo = :z, email = :e, contact_method = :m WHERE id = :id')
                ->execute(['n' => $name, 'p' => $phone, 'z' => $zalo !== '' ? $zalo : null, 'e' => $email, 'm' => $method, 'id' => $user['id']]);
            rl_sync_customer(db(), (int) $user['id'], $previous ?: null);
            db()->commit();

            if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
                foreach (['name' => $name, 'full_name' => $name, 'email' => $email, 'phone' => $phone] as $k => $v) {
                    if (array_key_exists($k, $_SESSION['user'])) {
                        $_SESSION['user'][$k] = $v;
                    }
                }
            }
            rl_flash('success', 'Your contact information has been saved.');
            rl_redirect('?page=profile');

        case 'change_password':
            $cur = (string) ($_POST['current_password'] ?? '');
            $new = (string) ($_POST['new_password'] ?? '');
            $cfm = (string) ($_POST['confirm_password'] ?? '');

            $s = db()->prepare('SELECT password_hash FROM users WHERE id = :id');
            $s->execute(['id' => $user['id']]);
            $hash = (string) $s->fetchColumn();

            if (!password_verify($cur, $hash)) {
                rl_fail('Your current password is incorrect.', $back);
            }
            if (strlen($new) < 8) {
                rl_fail('The new password must be at least 8 characters.', $back);
            }
            if ($new !== $cfm) {
                rl_fail('The new password and its confirmation do not match.', $back);
            }
            if (password_verify($new, $hash)) {
                rl_fail('Please choose a password different from the current one.', $back);
            }
            db()->prepare('UPDATE users SET password_hash = :h, password_changed_at = NOW() WHERE id = :id')
                ->execute(['h' => password_hash($new, PASSWORD_DEFAULT), 'id' => $user['id']]);
            try {
                db()->prepare('DELETE FROM remember_tokens WHERE user_id = :id')->execute(['id' => $user['id']]);
            } catch (Throwable $ex) {
                /* table optional */
            }
            session_regenerate_id(true);
            rl_flash('success', 'Your password has been changed.');
            rl_redirect('?page=profile');
    }
    } catch (Throwable $ex) {
        if (db()->inTransaction()) db()->rollBack();
        if (!empty($photo)) rl_deleteVehiclePhoto($photo);
        error_log('Customer/vehicle save: ' . $ex->getMessage());
        rl_fail($ex instanceof PDOException ? 'The details could not be saved. Please check for duplicate contact details or license plates.' : $ex->getMessage(), $back);
    }
    rl_redirect($back);
}

/* ---------- Flash (shown once) ---------- */

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

function renderFlash(): void
{
    global $flash;
    if (!$flash) {
        return;
    }
    $ok = $flash['type'] === 'success';
    ?>
    <div class="flash <?= $ok ? 'success' : 'error' ?>" role="status">
      <?= icon($ok ? 'check' : 'x', 18) ?>
      <span><?= e($flash['msg']) ?></span>
    </div>
    <?php
}

/* ---------- Icons + UI helpers ---------- */

$ICONS = [
    'grid'     => '<rect x="3" y="3" width="7" height="7" rx="2"/><rect x="14" y="3" width="7" height="7" rx="2"/><rect x="3" y="14" width="7" height="7" rx="2"/><rect x="14" y="14" width="7" height="7" rx="2"/>',
    'car'      => '<path d="M5 17h14M6 17v2M18 17v2M4 13l2-5h12l2 5v4H4v-4Z"/><circle cx="7.5" cy="14" r=".8"/><circle cx="16.5" cy="14" r=".8"/>',
    'calendar' => '<rect x="3" y="5" width="18" height="16" rx="3"/><path d="M16 3v4M8 3v4M3 10h18"/>',
    'wrench'   => '<path d="M14.7 6.3a4 4 0 0 0-5-5L12 3.6 9.6 6 7.3 3.7a4 4 0 0 0 5 5l-8.6 8.6a2.1 2.1 0 1 0 3 3l8.6-8.6a4 4 0 0 0 5-5L18 9l-2.4-2.4 2.3-2.3a4 4 0 0 0-3.2 2Z"/>',
    'receipt'  => '<path d="M5 3h14v19l-3-2-2 2-2-2-2 2-2-2-3 2V3Zm4 5h6M9 12h6M9 16h4"/>',
    'sparkles' => '<path d="m12 3 1.2 3.8L17 8l-3.8 1.2L12 13l-1.2-3.8L7 8l3.8-1.2L12 3Z"/><path d="m5 14 .8 2.2L8 17l-2.2.8L5 20l-.8-2.2L2 17l2.2-.8L5 14Zm14-2 .7 2.3 2.3.7-2.3.7L19 18l-.7-2.3-2.3-.7 2.3-.7L19 12Z"/>',
    'user'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
    'help'     => '<circle cx="12" cy="12" r="9"/><path d="M9.8 9a2.3 2.3 0 1 1 3.6 1.9c-.9.6-1.4 1-1.4 2.1M12 17h.01"/>',
    'search'   => '<circle cx="11" cy="11" r="7"/><path d="m20 20-4-4"/>',
    'bell'     => '<path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9Z"/><path d="M10 21h4"/>',
    'chevron'  => '<path d="m9 18 6-6-6-6"/>',
    'plus'     => '<path d="M12 5v14M5 12h14"/>',
    'arrow'    => '<path d="M5 12h14m-6-6 6 6-6 6"/>',
    'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    'wallet'   => '<path d="M3 7h16a2 2 0 0 1 2 2v10H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h13"/><path d="M16 12h5"/>',
    'gauge'    => '<path d="M4.9 19a9 9 0 1 1 14.2 0"/><path d="m12 15 4-5"/><circle cx="12" cy="15" r="1"/>',
    'menu'     => '<path d="M4 7h16M4 12h16M4 17h16"/>',
    'x'        => '<path d="m6 6 12 12M18 6 6 18"/>',
    'download' => '<path d="M12 3v12m-5-5 5 5 5-5"/><path d="M5 21h14"/>',
    'check'    => '<path d="m5 12 4 4L19 6"/>',
    'map'      => '<path d="m3 6 6-3 6 3 6-3v15l-6 3-6-3-6 3V6Z"/><path d="M9 3v15m6-12v15"/>',
    'phone'    => '<path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 2 .7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2.1Z"/>',
    'star'     => '<path d="m12 2 3.1 6.3 6.9 1-5 4.8 1.2 6.9-6.2-3.3L5.8 21 7 14.1l-5-4.8 6.9-1L12 2Z"/>',
    'shield'   => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/>',
    'message'  => '<path d="M21 15a4 4 0 0 1-4 4H8l-5 3V7a4 4 0 0 1 4-4h10a4 4 0 0 1 4 4v8Z"/>',
    'mail'     => '<rect x="3" y="5" width="18" height="14" rx="3"/><path d="m3 7 9 6 9-6"/>',
    'trash'    => '<path d="M4 7h16M10 11v6M14 11v6M6 7l1 13h10l1-13M9 7V4h6v3"/>',
    'edit'     => '<path d="M4 20h4L19 9a2.8 2.8 0 0 0-4-4L4 16v4Z"/><path d="m14 6 4 4"/>',
    'logout'   => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/>',
];

if (!function_exists('icon')) {
    function icon(string $name, int $size = 20): string
    {
        global $ICONS;
        return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" fill="none" stroke="currentColor" '
            . 'stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
            . ($ICONS[$name] ?? '') . '</svg>';
    }
}

if (!function_exists('badge')) {
    function badge(string $text, string $tone = 'cyan'): string
    {
        return '<span class="badge ' . e($tone) . '"><i></i>' . e($text) . '</span>';
    }
}

if (!function_exists('btn')) {
    function btn(string $label, string $variant = 'primary', ?string $iconName = null, string $attrs = '', string $type = 'button'): string
    {
        return '<button type="' . e($type) . '" class="button ' . e($variant) . '" ' . $attrs . '>'
            . ($iconName ? icon($iconName, 17) : '')
            . '<span>' . e($label) . '</span></button>';
    }
}

if (!function_exists('pageHeader')) {
    function pageHeader(string $eyebrow, string $title, string $description, string $action = ''): void
    {
        ?>
        <header class="page-header">
          <div>
            <p class="eyebrow"><?= e($eyebrow) ?></p>
            <h1><?= e($title) ?></h1>
            <p class="page-description"><?= e($description) ?></p>
          </div>
          <?= $action ?>
        </header>
        <?php
    }
}

if (!function_exists('navUrl')) {
    function navUrl(string $id): string
    {
        return '?page=' . urlencode($id);
    }
}

/* ---------- Real data from the database ---------- */

$stmt = db()->prepare('SELECT * FROM users WHERE id = :id');
$stmt->execute(['id' => $user['id']]);
$me = $stmt->fetch() ?: [];

$stmt = db()->prepare('SELECT * FROM portal_vehicles WHERE user_id = :uid ORDER BY id DESC');
$stmt->execute(['uid' => $user['id']]);
$dbVehicles = $stmt->fetchAll();

$stmt = db()->prepare(
    "SELECT s.id, s.title, s.status, s.estimate, s.updated_at, v.make, v.model
     FROM service_orders s
     JOIN portal_vehicles v ON v.id = s.vehicle_id
     WHERE v.user_id = :uid AND s.status NOT IN ('completed','cancelled')
     ORDER BY s.updated_at DESC"
);
$stmt->execute(['uid' => $user['id']]);
$dbOrders = $stmt->fetchAll();

// Technicians update repair_orders, linked to the customer's garage by vehicle ID.
$stmt = db()->prepare("SELECT r.id, r.order_code, r.status, r.assigned_to,
        r.service_type AS title, r.estimated_cost AS estimate, r.updated_at, r.expected_delivery,
        v.make, v.model, v.plate, v.year, v.photo,
        technician.full_name AS technician_name, h.note AS progress_note
    FROM repair_orders r
    JOIN portal_vehicles v ON v.workshop_vehicle_id = r.vehicle_id
    LEFT JOIN users technician ON technician.id = r.assigned_to
    LEFT JOIN order_status_history h ON h.id = (
        SELECT MAX(latest.id) FROM order_status_history latest
        WHERE latest.order_id = r.id AND latest.to_status = r.status
          AND (latest.from_status IS NULL OR latest.from_status <> latest.to_status)
    )
    WHERE v.user_id = :uid AND r.status <> 'delivered'
    ORDER BY (r.status = 'completed'), r.updated_at DESC, r.id DESC");
$stmt->execute(['uid' => $user['id']]);
$dbWorkshopOrders = $stmt->fetchAll();
$currentRepair = $dbWorkshopOrders[0] ?? null;
$dbOrders = array_merge($dbWorkshopOrders, $dbOrders);

$status_labels = [
    'estimate'    => 'Estimate ready',
    'approved'    => 'Approved',
    'in_progress' => 'In progress',
    'ready'       => 'Ready for pickup',
    'completed'   => 'Completed',
    'cancelled'   => 'Cancelled',
    'received' => 'Received',
    'diagnosing' => 'Diagnosing',
    'repairing' => 'Repairing',
    'waiting_parts' => 'Waiting for parts',
    'inspecting' => 'Inspecting',
    'delivered' => 'Delivered',
];
$status_tones = [
    'estimate'    => 'cyan',
    'approved'    => 'cyan',
    'in_progress' => 'amber',
    'ready'       => 'green',
    'received' => 'cyan',
    'diagnosing' => 'cyan',
    'repairing' => 'amber',
    'waiting_parts' => 'amber',
    'inspecting' => 'cyan',
    'completed' => 'green',
];

/* ---------- Current user ---------- */

$userName  = trim((string) ($me['full_name'] ?? $user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'Customer'));
$userName  = $userName !== '' ? $userName : 'Customer';
$userEmail = (string) ($me['email'] ?? $user['email'] ?? '');
$userPhone = (string) ($me['phone'] ?? $user['phone'] ?? '');
$firstName = explode(' ', $userName)[0];
$initials  = '';
foreach (array_slice(preg_split('/\s+/', $userName) ?: [], 0, 2) as $part) {
    $initials .= mb_strtoupper(mb_substr($part, 0, 1));
}
$initials = $initials ?: 'U';

$profile = [
    'name'     => $userName,
    'email'    => $userEmail,
    'phone'    => $userPhone,
    'zalo'     => (string) ($me['zalo'] ?? ''),
    'initials' => $initials,
    'method'   => (string) ($me['contact_method'] ?? 'phone'),
    'notify'   => [
        'repair'   => (int) ($me['notify_repair'] ?? 1) === 1,
        'reminder' => (int) ($me['notify_reminder'] ?? 1) === 1,
        'offers'   => (int) ($me['notify_offers'] ?? 0) === 1,
    ],
    'pwText'   => rl_agoText($me['password_changed_at'] ?? null),
];

/* ---------- Data mapped into the shapes the renderers expect ---------- */

$vehicles = array_map(function ($v) {
    $photo = normalize_upload_path((string) ($v['photo'] ?? ''));
    return [
        'id'        => (int) $v['id'],
        'make'      => (string) ($v['make'] ?? ''),
        'modelName' => (string) ($v['model'] ?? ''),
        'model'     => trim(($v['make'] ?? '') . ' ' . ($v['model'] ?? '')),
        'year'      => (string) ($v['year'] ?? ''),
        'plate'     => (string) ($v['plate'] ?? ''),
        'color'     => (string) ($v['color'] ?? ''),
        'vin' => (string) ($v['vin'] ?? ''),
        'engine_type' => $v['engine_type'],
        'battery_capacity' => $v['battery_capacity'],
        'battery_soh' => $v['battery_soh'],
        'kmRaw'     => $v['mileage'] !== null && $v['mileage'] !== '' ? (int) $v['mileage'] : '',
        'km'        => isset($v['mileage']) && $v['mileage'] !== null ? number_format((int) $v['mileage']) . ' km' : '—',
        'photo'     => str_starts_with($photo, 'uploads/vehicles/') ? $photo : '',
        'service'   => '—',
        'primary'   => false,
    ];
}, $dbVehicles);
if ($vehicles) {
    $vehicles[0]['primary'] = true;
}

$selectedVehicleId = (int) ($_GET['vehicle'] ?? 0);
if (!in_array($selectedVehicleId, array_column($vehicles, 'id'), true)) {
    $selectedVehicleId = $vehicles ? $vehicles[0]['id'] : 0;
}

$orders = array_map(function ($o) use ($status_labels, $status_tones) {
    $ts = strtotime((string) ($o['updated_at'] ?? '')) ?: time();
    return [
        !empty($o['order_code']) ? '#' . $o['order_code'] : '#RO-' . $o['id'],
        trim(($o['make'] ?? '') . ' ' . ($o['model'] ?? '')),
        $o['status'] === 'completed' && !empty($o['order_code']) ? 'Ready for pickup' : ($status_labels[$o['status']] ?? (string) $o['status']),
        date('M j, Y', $ts),
        (int) ($o['estimate'] ?? 0),
        $status_tones[$o['status']] ?? 'cyan',
        trim((string)($o['technician_name'] ?? '')) ?: 'Awaiting assignment',
        (string)($o['expected_delivery'] ?? ''),
        (string)($o['updated_at'] ?? ''),
    ];
}, $dbOrders);

// The same renderer supplies the initial page and background refreshes.
if (isset($_GET['repair_progress'])) {
    header('Content-Type: application/json; charset=utf-8');
    ob_start();
    renderCurrentRepair($currentRepair);
    $progressHtml = ob_get_clean();
    ob_start();
    renderTable('orders', $orders);
    $ordersHtml = ob_get_clean();
    echo json_encode(['html' => $progressHtml, 'orders_html' => $ordersHtml, 'active_count' => count($orders)], JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// Use the same invoice records and customer ownership as the staff invoice list.
// An explicit order customer takes precedence over the vehicle's current owner.
$stmt = db()->prepare('SELECT i.*, ro.order_code, v.brand, v.model, v.license_plate
    FROM invoices i
    JOIN repair_orders ro ON ro.id = i.order_id
    LEFT JOIN vehicles v ON v.id = ro.vehicle_id
    WHERE COALESCE(ro.customer_id, v.customer_id) = :uid
    ORDER BY i.id DESC');
$stmt->execute(['uid' => $user['id']]);
$invoices = $stmt->fetchAll();

$nav = [
    ['overview',     'Overview',            'grid'],
    ['vehicles',     'My Vehicles',         'car'],
    ['appointments', 'Appointments',        'calendar'],
    ['orders',       'Repair Orders',       'wrench'],
    ['invoices',     'Invoices',            'receipt'],
    ['fabrication',  'Custom Fabrication',  'sparkles'],
    ['profile',      'Profile',             'user'],
    ['support',      'Support',             'help'],
];

/* ---------- Routing ---------- */

$ids  = array_column($nav, 0);
$page = $_GET['page'] ?? 'overview';
if (!in_array($page, $ids, true)) {
    $page = 'overview';
}
$currentLabel = $nav[array_search($page, $ids, true)][1];

/* Load complete service history only when the garage is being viewed. */
$historyByVehicle = $page === 'vehicles' ? rl_customer_service_history(db(), (int) $user['id']) : [];
$fabrication = $page === 'fabrication' ? rl_customer_fabrication(db(), (int) $user['id']) : [];

/* ---------- Page renderers ---------- */

function renderCurrentRepair(?array $repair): void
{
    global $status_labels, $status_tones;
    $status = $repair['status'] ?? '';
    $steps = ['Received', 'Diagnosis', 'Repair', 'Inspection', 'Ready for Pickup'];
    $stage = ['received' => 1, 'diagnosing' => 2, 'repairing' => 3, 'waiting_parts' => 3, 'inspecting' => 4, 'completed' => 5][$status] ?? 1;
    $label = $repair ? ($status === 'completed' ? 'Ready for pickup' : ($status_labels[$status] ?? $status)) : '';
    if ($repair && empty($repair['assigned_to']) && $status === 'received') $label = 'Awaiting admin approval';
    $photo = normalize_upload_path((string) ($repair['photo'] ?? ''));
    ?>
    <section class="glass repair-card" data-order-id="<?= (int) ($repair['id'] ?? 0) ?>" data-status="<?= e($status) ?>">
      <div class="panel-heading">
        <div>
          <p class="eyebrow"><?= $repair ? 'ORDER ' . e($repair['order_code']) : 'WORKSHOP' ?></p>
          <h2>Current Repair Progress</h2>
        </div>
        <?= $repair ? badge($label, $status_tones[$status] ?? 'cyan') : '' ?>
      </div>
      <?php if (!$repair): ?>
        <p>No current repair orders. Your vehicle's progress will appear here after workshop intake.</p>
      <?php else: ?>
        <div class="repair-vehicle">
          <?php if (str_starts_with($photo, 'uploads/vehicles/')): ?>
            <img src="<?= e(app_url($photo)) ?>" alt="<?= e(trim($repair['make'] . ' ' . $repair['model'])) ?>">
          <?php else: ?><span class="stat-icon cyan"><?= icon('car', 28) ?></span><?php endif; ?>
          <div>
            <strong><?= e(trim($repair['make'] . ' ' . $repair['model'])) ?></strong>
            <span><?= e($repair['plate'] ?? '') ?><?= !empty($repair['year']) ? ' · ' . e((string) $repair['year']) : '' ?></span>
          </div>
          <a class="round-button" href="?page=orders" aria-label="View repair orders"><?= icon('arrow') ?></a>
        </div>
        <div class="stepper" aria-label="Repair progress: <?= e($label) ?>">
          <?php foreach ($steps as $i => $step): ?>
            <div class="step <?= $i < $stage ? 'done' : '' ?> <?= $i === $stage - 1 ? 'current' : '' ?>"<?= $i === $stage - 1 ? ' aria-current="step"' : '' ?>>
              <div class="step-dot"><?= $i < $stage - 1 ? icon('check', 13) : $i + 1 ?></div>
              <span><?= e($step) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="repair-team-card" style="display:flex;align-items:center;gap:12px;padding:12px 14px;margin-top:14px;border:1px solid rgba(255,255,255,.22);border-radius:14px;background:rgba(255,255,255,.07)">
          <span class="stat-icon cyan" aria-hidden="true"><?= icon('user', 21) ?></span>
          <div style="flex:1;min-width:0"><span style="display:block;font-size:11px;opacity:.75;text-transform:uppercase;letter-spacing:.09em">Assigned Technician</span><strong style="display:block;margin-top:3px"><?= !empty($repair['technician_name']) ? e($repair['technician_name']) : 'Pending assignment' ?></strong></div>
          <?= badge($label, $status_tones[$status] ?? 'cyan') ?>
        </div>
        <div class="repair-update">
          <div class="live-dot"></div>
          <div>
            <strong><?= e($label) ?></strong>
            <p><?= !empty($repair['technician_name']) ? 'Technician: ' . e($repair['technician_name']) : 'Awaiting technician assignment' ?></p>
            <p><?= !empty($repair['expected_delivery']) ? 'Expected completion: ' . e($repair['expected_delivery']) : 'Completion time has not been confirmed.' ?></p>
          </div>
          <span>Updated <?= e($repair['updated_at'] ?? '') ?></span>
        </div>
      <?php endif; ?>
    </section>
    <?php
}

function renderOverview(string $firstName, array $orders, ?array $currentRepair): void
{
    global $status_labels, $user, $invoices;
    $nextVisit = null;
    $appointmentError = false;
    $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Bangkok'));
    try {
        $requests = db()->prepare("SELECT sr.message FROM service_requests sr
            LEFT JOIN repair_orders ro ON ro.service_request_id = sr.id
            WHERE sr.user_id = :uid AND sr.status IN ('pending','processed')
              AND (ro.id IS NULL OR ro.status = 'received')");
        $requests->execute(['uid' => $user['id']]);
        foreach ($requests->fetchAll(PDO::FETCH_ASSOC) as $request) {
            if (!preg_match('/Preferred appointment: (\d{4}-\d{2}-\d{2}) at (\d{2}:\d{2})\./', (string)$request['message'], $match)) continue;
            $visit = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $match[1] . ' ' . $match[2], $now->getTimezone());
            if ($visit && $visit->format('Y-m-d H:i') === $match[1] . ' ' . $match[2] && $visit >= $now && (!$nextVisit || $visit < $nextVisit)) $nextVisit = $visit;
        }
    } catch (PDOException $ex) {
        error_log('Customer overview appointment query failed: ' . $ex->getMessage());
        $appointmentError = true;
    }
    $outstanding = 0;
    $unpaidCount = 0;
    $balanceError = false;
    $openInvoices = array_filter($invoices, static fn(array $invoice): bool => in_array($invoice['payment_status'] ?? '', ['unpaid', 'partial'], true));
    try {
        $payments = [];
        if ($openInvoices) {
            $ids = array_column($openInvoices, 'id');
            $ledger = db()->prepare("SELECT invoice_id, SUM(CASE WHEN transaction_type = 'payment' THEN amount ELSE -amount END) AS net_paid
                FROM cash_transactions WHERE status = 'completed' AND invoice_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ') GROUP BY invoice_id');
            $ledger->execute($ids);
            foreach ($ledger->fetchAll(PDO::FETCH_ASSOC) as $payment) $payments[(int)$payment['invoice_id']] = (float)$payment['net_paid'];
        }
        foreach ($openInvoices as $invoice) {
            $total = max(0, (int)round((float)$invoice['grand_total'] * 100));
            $paid = max(0, (int)round(($payments[(int)$invoice['id']] ?? 0) * 100));
            $remaining = max(0, $total - $paid);
            if ($remaining > 0) { $outstanding += $remaining; ++$unpaidCount; }
        }
    } catch (PDOException $ex) {
        error_log('Customer overview invoice query failed: ' . $ex->getMessage());
        $balanceError = true;
    }
    $stats = [
        ['calendar', 'Upcoming Appointment', $appointmentError ? 'Unavailable' : ($nextVisit ? $nextVisit->format('H:i') : 'None scheduled'), $appointmentError ? 'Refresh to try again' : ($nextVisit ? 'Requested · ' . $nextVisit->format('D, M j, Y') : 'Book your next workshop visit'), 'orange', 'appointments'],
        ['wrench', 'Active Repair Orders', str_pad((string) count($orders), 2, '0', STR_PAD_LEFT), count($orders) ? 'View your current repairs' : 'No active repair orders', 'cyan', 'orders'],
        ['wallet', 'Unpaid Invoices', $balanceError ? 'Unavailable' : formatVND((int)round($outstanding / 100)), $balanceError ? 'Refresh to try again' : ($unpaidCount ? $unpaidCount . ' invoice' . ($unpaidCount === 1 ? '' : 's') . ' with a balance' : 'No outstanding balance'), 'pink', 'invoices'],
        ['gauge', 'Next Maintenance', 'Not scheduled', 'Ask the workshop for a service plan', 'green', 'vehicles'],
    ];
    $notes = [];
    if ($nextVisit) $notes[] = ['calendar', 'Appointment request', 'Preferred visit: ' . $nextVisit->format('M j, Y \a\t H:i') . '. Awaiting workshop confirmation.', 'Requested time', 'violet'];
    if (!$balanceError && $unpaidCount) $notes[] = ['receipt', 'Invoice balance', formatVND((int)round($outstanding / 100)) . ' outstanding. View invoices for details.', 'Current balance', 'cyan'];
    if ($currentRepair) {
        array_unshift($notes, ['wrench', 'Repair progress',
            trim((string) ($currentRepair['progress_note'] ?? '')) ?: ($status_labels[$currentRepair['status']] ?? $currentRepair['status']),
            'Updated ' . $currentRepair['updated_at'], 'orange']);
    }

    $hour     = (int) date('G');
    $greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

    pageHeader(date('l, F j'), $greeting . ', ' . $firstName . '.', 'Everything about your vehicles is under control.',
        btn('Book Appointment', 'primary', 'plus', 'data-modal="booking"'));
    ?>
    <div class="stats-grid">
      <?php foreach ($stats as [$ic, $label, $value, $meta, $tone, $destination]): ?>
        <a class="glass stat-card overview-stat-link" href="<?= e(navUrl($destination)) ?>">
          <div class="stat-icon <?= e($tone) ?>"><?= icon($ic) ?></div>
          <p><?= e($label) ?></p>
          <strong<?= $label === 'Active Repair Orders' ? ' id="active-repair-count"' : '' ?>><?= e($value) ?></strong>
          <small><?= e($meta) ?></small>
        </a>
      <?php endforeach; ?>
    </div>

    <div class="overview-grid">
      <div id="current-repair-progress" aria-live="polite"><?php renderCurrentRepair($currentRepair); ?></div>

      <section class="glass notifications">
        <div class="panel-heading">
          <div>
            <p class="eyebrow">UPDATES</p>
            <h2>Recent Notifications</h2>
          </div>
        </div>
        <?php if (!$notes): ?><p class="overview-no-updates">No recent workshop updates. Your requests and repair updates will appear here.</p><?php endif; ?>
        <?php foreach ($notes as [$ic, $title, $text, $time, $tone]): ?>
          <div class="notification-item">
            <div class="notice-icon <?= e($tone) ?>"><?= icon($ic, 18) ?></div>
            <div>
              <strong><?= e($title) ?></strong>
              <p><?= e($text) ?></p>
              <span><?= e($time) ?></span>
            </div>
          </div>
        <?php endforeach; ?>
        <a class="view-all" href="?page=appointments">View appointments <?= icon('arrow', 16) ?></a>
      </section>
    </div>

    <div class="quick-section">
      <div>
        <p class="eyebrow">SHORTCUTS</p>
        <h2>Quick Actions</h2>
      </div>
      <div class="quick-actions">
        <button type="button" data-modal="booking">
          <span><?= icon('calendar') ?></span>
          <div><strong>Book Appointment</strong><small>Choose a service and time</small></div>
          <?= icon('chevron', 17) ?>
        </button>
        <button type="button" data-go="vehicles">
          <span><?= icon('car') ?></span>
          <div><strong>Add Vehicle</strong><small>Manage your vehicles</small></div>
          <?= icon('chevron', 17) ?>
        </button>
        <button type="button" data-go="invoices">
          <span><?= icon('wallet') ?></span>
          <div><strong>Pay Invoice</strong><small>Fast and secure</small></div>
          <?= icon('chevron', 17) ?>
        </button>
      </div>
    </div>
    <?php
}

function renderVehicles(array $vehicles, array $historyByVehicle, int $selectedId): void
{
    global $status_labels;
    $serviceLabels = rl_service_catalog();

    $count = count($vehicles);
    pageHeader('YOUR GARAGE', 'My Vehicles',
        $count
            ? 'Manage vehicle information and track complete service histories. ' . $count . ' vehicle' . ($count > 1 ? 's' : '') . ' saved.'
            : 'Save your vehicles once to book services faster.',
        btn('Add Vehicle', 'primary', 'plus', 'data-modal="vehicle"'));

    renderFlash();

    $selected = null;
    foreach ($vehicles as $v) {
        if ($v['id'] === $selectedId) {
            $selected = $v;
        }
    }
    ?>
    <div class="vehicle-grid">
      <?php foreach ($vehicles as $v):
          $payload = [
              'id' => $v['id'], 'make' => $v['make'], 'model' => $v['modelName'], 'year' => $v['year'],
              'plate' => $v['plate'], 'mileage' => $v['kmRaw'], 'color' => $v['color'], 'photo' => $v['photo'] !== '' ? app_url($v['photo']) : '',
              'vin' => $v['vin'], 'engine_type' => $v['engine_type'], 'battery_capacity' => $v['battery_capacity'], 'battery_soh' => $v['battery_soh'],
          ];
      ?>
        <section class="glass vehicle-card <?= $v['id'] === $selectedId ? 'selected' : '' ?>">
          <div class="vehicle-image">
            <img src="<?= e(app_url($v['photo'] !== '' ? $v['photo'] : 'assets/images/BMW-M3.jpg')) ?>" alt="<?= e($v['model']) ?>">
            <?= $v['primary'] ? badge('Primary Vehicle', 'cyan') : '' ?>
            <?php if ($v['year'] !== ''): ?><span class="vehicle-year"><?= e($v['year']) ?></span><?php endif; ?>
          </div>
          <div class="vehicle-content">
            <div class="vehicle-title">
              <div>
                <p><?= e(($v['year'] !== '' ? $v['year'] : 'VEHICLE') . ' · ' . mb_strtoupper($v['make'])) ?></p>
                <h2><?= e($v['model']) ?></h2>
              </div>
              <div class="card-menu">
                <button type="button" class="menu-trigger" aria-label="Options" aria-haspopup="true" aria-expanded="false">•••</button>
                <div class="menu-pop" hidden>
                  <button type="button" data-edit-vehicle="<?= e(json_encode($payload)) ?>"><?= icon('edit', 16) ?>Edit</button>
                  <button type="button" class="danger" data-delete-vehicle="<?= (int) $v['id'] ?>" data-name="<?= e($v['model'] . ' · ' . $v['plate']) ?>"><?= icon('trash', 16) ?>Delete</button>
                </div>
              </div>
            </div>
            <div class="vehicle-specs">
              <div><span>License Plate</span><strong><?= e($v['plate']) ?></strong></div>
              <div><span>Mileage</span><strong><?= e($v['km']) ?></strong></div>
              <div><span>Color</span><strong><?= e($v['color'] !== '' ? $v['color'] : '—') ?></strong></div>
              <div><span>VIN</span><strong><?= e($v['vin'] ?: '—') ?></strong></div>
              <div><span>Powertrain</span><strong><?= e(strtoupper($v['engine_type'])) ?></strong></div>
              <div><span>Next Service</span><strong><?= e($v['service']) ?></strong></div>
            </div>
            <div class="card-footer">
              <a class="history-link" href="?page=vehicles&amp;vehicle=<?= (int) $v['id'] ?>#history" aria-controls="history">View service history</a>
              <a class="round-button" href="?page=vehicles&amp;vehicle=<?= (int) $v['id'] ?>#history" aria-label="Service history for <?= e($v['model'] . ' · ' . $v['plate']) ?>" aria-controls="history"><?= icon('arrow', 17) ?></a>
            </div>
          </div>
        </section>
      <?php endforeach; ?>
      <button type="button" class="add-vehicle-card" data-modal="vehicle">
        <span><?= icon('plus', 26) ?></span>
        <strong>Add New Vehicle</strong>
        <small>Save vehicle details for faster booking</small>
      </button>
    </div>

    <?php if ($selected):
        $history = $historyByVehicle[$selected['id']] ?? [];
    ?>
    <section class="glass timeline-panel" id="history" tabindex="-1" aria-labelledby="history-title">
      <div class="panel-heading">
        <div>
          <p class="eyebrow"><?= e($selected['model']) ?> · <?= e($selected['plate']) ?></p>
          <h2 id="history-title">Service History</h2>
        </div>
        <?= $history ? badge(count($history) . ' record' . (count($history) > 1 ? 's' : ''), 'green') : badge('No records yet', 'amber') ?>
      </div>
      <?php if (!$history): ?>
        <div class="timeline-empty">
          <?= icon('wrench', 22) ?>
          <p>No service has been recorded for this vehicle yet. It will appear here after your first visit.</p>
        </div>
      <?php endif; ?>
      <?php foreach ($history as $item):
          $done = in_array($item['status'], ['completed', 'delivered'], true);
          $cancelled = $item['status'] === 'cancelled';
          $when = date('M j, Y', strtotime((string) $item['updated_at']) ?: time());
          $note = trim((string) ($item['notes'] ?? ''));
      ?>
        <div class="timeline-row">
          <div class="timeline-mark <?= $cancelled ? 'cancelled' : (!$done ? 'active' : '') ?>"><?= icon($cancelled ? 'x' : ($done ? 'check' : 'wrench'), 15) ?></div>
          <div class="timeline-date"><?= e($when) ?></div>
          <div class="timeline-info">
            <?php if (!empty($item['order_code'])): ?><small class="timeline-reference"><?= e('#' . $item['order_code']) ?></small><?php endif; ?>
            <strong><?= e($serviceLabels[$item['title']] ?? $item['title']) ?></strong>
            <span><?= e($status_labels[$item['status']] ?? $item['status']) ?><?= $note !== '' ? ' · ' . e($note) : '' ?></span>
          </div>
          <strong><?= $item['estimate'] !== null ? e(formatVND((int) $item['estimate'])) : '—' ?></strong>
        </div>
      <?php endforeach; ?>
    </section>
    <?php endif; ?>
    <?php
}

function appointmentCard(array $appointment): string
{
    $visit = $appointment['requested_at'];
    $search = implode(' ', [$appointment['reference'], $appointment['service'], $appointment['vehicle'], $appointment['plate'], $appointment['status_label']]);
    ob_start(); ?>
    <article class="appointments-request-card" id="appointment-<?= (int) $appointment['id'] ?>" data-appointment-card data-appointment-search="<?= e($search) ?>">
      <div class="appointments-request-date">
        <strong><?= $visit ? e($visit->format('d')) : '—' ?></strong>
        <span><?= $visit ? e($visit->format('M Y')) : 'Date pending' ?></span>
      </div>
      <div class="appointments-request-main">
        <div class="appointments-request-heading">
          <p class="reference"><?= e($appointment['reference']) ?></p>
          <?= badge($appointment['status_label'], $appointment['tone']) ?>
        </div>
        <h3><?= e($appointment['service']) ?></h3>
        <p class="appointments-vehicle"><?= icon('car', 17) ?><span><?= e($appointment['vehicle']) ?><?= $appointment['plate'] !== '' ? ' · ' . e($appointment['plate']) : '' ?></span></p>
        <div class="appointments-request-meta">
          <span><?= icon('clock', 15) ?><?= $visit ? 'Requested ' . e($visit->format('D, j M Y · H:i')) . ' (UTC+7)' : 'Preferred date has not been provided' ?></span>
        </div>
        <footer class="appointments-request-footer">
          <p><?= e($appointment['note']) ?></p>
          <details class="appointments-request-details">
            <summary>Request details <?= icon('chevron', 14) ?></summary>
            <dl>
              <div><dt>Reference</dt><dd><?= e($appointment['reference']) ?></dd></div>
              <div><dt>Submitted</dt><dd><?= e($appointment['created_at'] ?: 'Not recorded') ?></dd></div>
              <div><dt>Requested time</dt><dd><?= $visit ? e($visit->format('j M Y, H:i')) . ' (UTC+7)' : 'Not provided' ?></dd></div>
              <div><dt>Request status</dt><dd><?= e($appointment['status_label']) ?></dd></div>
              <div><dt>Technician requested</dt><dd><?= e($appointment['preferred_technician_name'] ?: 'No preference — admin assignment') ?></dd></div>
              <?php if ($appointment['assigned_technician_name']): ?><div><dt>Technician assigned</dt><dd><?= e($appointment['assigned_technician_name']) ?></dd></div><?php endif; ?>
              <?php if ($appointment['order_id']): ?><div><dt>Repair order</dt><dd><?= e($appointment['order_code'] ?: '#' . $appointment['order_id']) ?></dd></div><?php endif; ?>
            </dl>
          </details>
          <?php if ($appointment['order_id']): ?><a class="button secondary" href="?page=orders">View repair progress <?= icon('arrow', 15) ?></a><?php endif; ?>
        </footer>
      </div>
    </article>
    <?php
    return ob_get_clean();
}

function renderAppointments(): void
{
    global $user;
    require_once __DIR__ . '/../includes/customer_appointments.php';
    pageHeader('YOUR SCHEDULE', 'Appointments', 'Your next visit, past requests, and vehicle care. All in one place.',
        btn('Book a visit', 'primary', 'plus', 'data-modal="booking"'));
    try {
        $appointments = rl_customer_appointments(db(), (int) $user['id']);
    } catch (Throwable $ex) {
        error_log('Customer appointments query failed: ' . $ex->getMessage());
        ?>
        <section class="glass appointments-empty" role="alert">
          <?= icon('calendar', 32) ?><h2>Appointments are temporarily unavailable</h2>
          <p>We could not load your saved requests. Please try again.</p>
          <a class="button secondary" href="?page=appointments">Refresh appointments <?= icon('arrow', 16) ?></a>
        </section>
        <?php return;
    }
    $tabs = ['upcoming' => 'Upcoming', 'past' => 'Past', 'cancelled' => 'Cancelled'];
    $activeTab = $_GET['tab'] ?? 'upcoming';
    if (!is_string($activeTab) || !isset($tabs[$activeTab])) $activeTab = 'upcoming';
    $next = $appointments['next'];
    $emptyCopy = [
        'upcoming' => ['No upcoming requests', 'Book your next workshop visit and your request will appear here.'],
        'past' => ['Your visit history starts here', 'Requests for past dates and completed workshop visits will appear here.'],
        'cancelled' => ['No cancelled requests', 'Any requests cancelled by the workshop will appear here.'],
    ];
    ?>
    <section class="customer-appointments" aria-label="Your appointments">
      <div class="appointments-metrics">
        <?php foreach ([
            ['calendar', 'Upcoming requests', $appointments['counts']['upcoming'], 'Your next workshop visits'],
            ['clock', 'Pending requests', $appointments['pending_count'], 'Awaiting workshop review'],
            ['check', 'Past requests', $appointments['counts']['past'], 'Your vehicle care history'],
        ] as [$metricIcon, $label, $value, $description]): ?>
          <div class="glass appointments-metric"><span class="metric-icon"><?= icon($metricIcon, 22) ?></span><div><p><?= e($label) ?></p><strong><?= number_format($value) ?></strong><small><?= e($description) ?></small></div></div>
        <?php endforeach; ?>
      </div>
      <div class="appointments-overview">
        <section class="glass appointments-next" aria-labelledby="next-appointment-title">
          <header class="appointments-section-heading"><div><p class="eyebrow">LOOKING AHEAD</p><h2 id="next-appointment-title">Your next requested visit</h2></div><span class="appointments-live-label"><?= icon('calendar', 14) ?> Vehicle care</span></header>
          <?php if ($next): $visit = $next['requested_at']; ?>
            <div class="appointments-next-content">
              <div class="appointments-date-tile"><strong><?= e($visit->format('d')) ?></strong><span><?= e($visit->format('M Y')) ?></span><small><?= e($visit->format('l')) ?></small></div>
              <div class="appointments-next-copy">
                <?= badge($next['status_label'], $next['tone']) ?><h3><?= e($next['service']) ?></h3>
                <p><?= e($next['vehicle']) ?><?= $next['plate'] !== '' ? ' · ' . e($next['plate']) : '' ?></p>
                <div class="appointments-schedule"><span><?= icon('clock', 16) ?> Requested <?= e($visit->format('H:i')) ?> (UTC+7)</span><span><?= icon('calendar', 16) ?> <?= e($visit->format('D, j M Y')) ?></span></div>
              </div>
            </div>
            <footer class="appointments-next-footer"><p>Your preferred time is subject to workshop confirmation.</p><a class="button secondary" href="?page=appointments&amp;tab=upcoming#appointment-<?= (int) $next['id'] ?>">View request <?= icon('arrow', 16) ?></a></footer>
          <?php else: ?>
            <div class="appointments-next-empty"><?= icon('calendar', 36) ?><h3>Make time for your next drive.</h3><p>Choose a service, your vehicle, and a preferred time. We’ll take it from there.</p></div>
          <?php endif; ?>
        </section>
        <aside class="glass appointments-guide" aria-labelledby="visit-guide-title">
          <p class="eyebrow">A SMOOTHER VISIT</p><h2 id="visit-guide-title">Before you arrive</h2>
          <ol>
            <li><?= icon('phone', 18) ?><div><strong>Wait for confirmation</strong><p>The workshop will contact you about your requested time.</p></div></li>
            <li><?= icon('car', 18) ?><div><strong>Check your vehicle details</strong><p>Keep your license plate and mileage up to date.</p></div></li>
            <li><?= icon('wrench', 18) ?><div><strong>Tell us what you need</strong><p>Bring any service history and describe what you’ve noticed.</p></div></li>
          </ol>
          <p class="appointments-guide-note">For changes, contact the workshop and quote your request reference.</p>
          <a class="appointments-text-link" href="?page=profile">Check your contact details <?= icon('arrow', 16) ?></a>
        </aside>
      </div>
      <section class="glass appointments-history" aria-labelledby="appointment-history-title" data-appointments>
        <header class="appointments-history-header"><div><p class="eyebrow">YOUR WORKSHOP RECORD</p><h2 id="appointment-history-title">Appointment requests</h2><p><?= number_format(count($appointments['items'])) ?> saved <?= count($appointments['items']) === 1 ? 'request' : 'requests' ?> · Preferred times shown in UTC+7</p></div>
          <label class="appointments-list-search"><?= icon('search', 18) ?><input type="search" id="customer-appointment-search" aria-label="Search your appointment requests" placeholder="Search service, vehicle, or reference" autocomplete="off"></label>
        </header>
        <div class="appointments-toolbar">
          <div class="appointments-tabs" role="tablist" aria-label="Appointment history">
            <?php foreach ($tabs as $key => $label): ?>
              <a href="?page=appointments&amp;tab=<?= e($key) ?>" role="tab" id="appointment-tab-<?= e($key) ?>" aria-controls="appointment-panel-<?= e($key) ?>" aria-selected="<?= $key === $activeTab ? 'true' : 'false' ?>" tabindex="<?= $key === $activeTab ? '0' : '-1' ?>" data-appointment-tab="<?= e($key) ?>"><?= e($label) ?><span><?= $appointments['counts'][$key] ?></span></a>
            <?php endforeach; ?>
          </div>
          <button type="button" class="appointments-search-clear" data-appointment-clear hidden>Clear search <?= icon('x', 14) ?></button>
        </div>
        <p class="appointments-result-summary" role="status" aria-live="polite" data-appointment-results><?= $appointments['counts'][$activeTab] ?> <?= strtolower($tabs[$activeTab]) ?> <?= $appointments['counts'][$activeTab] === 1 ? 'request' : 'requests' ?></p>
        <?php foreach ($tabs as $key => $label): ?>
          <div class="appointments-pane" id="appointment-panel-<?= e($key) ?>" role="tabpanel" aria-labelledby="appointment-tab-<?= e($key) ?>" tabindex="0" data-appointment-panel="<?= e($key) ?>" <?= $key === $activeTab ? '' : 'hidden' ?>>
            <div class="appointments-request-list">
              <?php foreach ($appointments['items'] as $appointment): if ($appointment['group'] === $key) echo appointmentCard($appointment); endforeach; ?>
            </div>
            <?php if (!$appointments['counts'][$key]): ?>
              <div class="appointments-empty"><?= icon($key === 'cancelled' ? 'x' : 'calendar', 30) ?><h3><?= e($emptyCopy[$key][0]) ?></h3><p><?= e($emptyCopy[$key][1]) ?></p><?php if ($key === 'upcoming'): ?><?= btn('Book a visit', 'secondary', 'plus', 'data-modal="booking"') ?><?php endif; ?></div>
            <?php else: ?>
              <div class="appointments-empty appointments-no-results" data-appointment-no-results hidden><?= icon('search', 30) ?><h3>No matching requests</h3><p>Try a service name, vehicle, license plate, or request reference.</p></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </section>
    </section>
    <?php
}

function renderTable(string $kind, array $data): void
{
    $headers = $kind === 'orders'
        ? ['Order Number', 'Vehicle', 'Status', 'Assigned Technician', 'Updated', 'Estimate', 'Details']
        : ['Invoices', 'Issue Date', 'Vehicle', 'Status', 'Total', ''];
    ?>
    <section class="glass table-panel">
      <div class="table-wrap">
        <table>
          <thead>
            <tr><?php foreach ($headers as $h): ?><th><?= e($h) ?></th><?php endforeach; ?></tr>
          </thead>
          <tbody>
            <?php if (!$data): ?>
              <tr><td colspan="<?= $kind === 'orders' ? 7 : 6 ?>">Nothing to show yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($data as $r): ?>
              <tr>
                <?php if ($kind === 'orders'): ?>
                  <td><strong><?= e($r[0]) ?></strong></td>
                  <td><?= e($r[1]) ?></td>
                  <td><?= badge($r[2], $r[5]) ?></td>
                  <td><strong><?= e($r[6] ?? 'Awaiting assignment') ?></strong></td>
                  <td><?= e($r[3]) ?></td>
                  <td><strong><?= e(formatVND($r[4])) ?></strong></td>
                <?php else: ?>
                  <td><strong><?= e($r[0]) ?></strong></td>
                  <td><?= e($r[1]) ?></td>
                  <td><?= e($r[2]) ?></td>
                  <td><?= badge($r[3], $r[5]) ?></td>
                  <td><strong><?= e(formatVND($r[4])) ?></strong></td>
                <?php endif; ?>
                <td><?php if ($kind === 'invoices'): ?>
                  <a class="round-button" href="?page=invoices&amp;invoice=<?= (int) $r[6] ?>#invoice-detail" aria-label="View invoice <?= e($r[0]) ?>"><?= icon('chevron', 16) ?></a>
                <?php else: ?>
                  <details class="customer-order-details" style="min-width:180px">
                    <summary style="cursor:pointer;white-space:nowrap;font-weight:600">View updates</summary>
                    <div style="padding:9px 0;font-size:12px;line-height:1.6">
                      <div><strong>Technician:</strong> <?= e($r[6] ?? 'Awaiting assignment') ?></div>
                      <div><strong>Current status:</strong> <?= e($r[2]) ?></div>
                      <div><strong>Last updated:</strong> <?= e($r[8] ?? $r[3]) ?></div>
                      <div><strong>Expected completion:</strong> <?= e(!empty($r[7]) ? $r[7] : 'Not confirmed') ?></div>
                    </div>
                  </details>
                <?php endif; ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
    <?php
}

function renderOrders(array $orders, ?array $currentRepair): void
{
    pageHeader('WORK TRACKING', 'Repair Orders', 'Track progress, costs, and technician updates.');
    ?>
    <div id="repair-orders-table"><?php renderTable('orders', $orders); ?></div>
    <div id="current-repair-progress" aria-live="polite"><?php renderCurrentRepair($currentRepair); ?></div>
    <?php
}

function renderInvoices(array $invoices): void
{
    $paid = 0;
    $outstanding = 0;
    $paidCount = 0;
    $unpaidCount = 0;
    $rows = [];
    $selected = $invoices[0] ?? null;
    $selectedId = (int) ($_GET['invoice'] ?? 0);
    foreach ($invoices as $invoice) {
        $status = $invoice['payment_status'];
        $total = (int) round((float) $invoice['grand_total']);
        if ($status === 'paid') {
            $paid += $total;
            ++$paidCount;
        } elseif ($status === 'unpaid') {
            $outstanding += $total;
            ++$unpaidCount;
        }
        if ((int) $invoice['id'] === $selectedId) $selected = $invoice;
        $issued = strtotime((string) $invoice['created_at']);
        $rows[] = [
            $invoice['invoice_code'],
            $issued ? date('M j, Y', $issued) : '',
            trim(($invoice['brand'] ?? '') . ' ' . ($invoice['model'] ?? '') . ' ' . ($invoice['license_plate'] ?? '')),
            ucfirst($status), $total,
            ['paid' => 'green', 'unpaid' => 'red', 'cancelled' => 'amber'][$status] ?? 'cyan',
            (int) $invoice['id'],
        ];
    }
    pageHeader('PAYMENTS', 'Invoices', 'View invoices and payment details from the workshop.');
    ?>
    <div class="invoice-summary">
      <section class="glass">
        <span>Total Paid</span>
        <strong><?= e(formatVND($paid)) ?></strong>
        <small><?= $paidCount ?> paid invoice<?= $paidCount === 1 ? '' : 's' ?></small>
      </section>
      <section class="glass">
        <span>Outstanding Balance</span>
        <strong id="balance"><?= e(formatVND($outstanding)) ?></strong>
        <small id="balance-note"><?= $unpaidCount ?> unpaid invoice<?= $unpaidCount === 1 ? '' : 's' ?></small>
      </section>
    </div>
    <?php renderTable('invoices', $rows); ?>
    <?php if ($selected): ?>
    <section class="glass invoice-detail" id="invoice-detail">
      <div>
        <p class="eyebrow">INVOICE <?= e($selected['invoice_code']) ?></p>
        <h2>Payment Details</h2>
        <p><?= e($selected['order_code']) ?> · <?= e($selected['license_plate']) ?></p>
        <p>Services: <?= e(formatVND((int) round((float) $selected['total_services']))) ?> · Parts: <?= e(formatVND((int) round((float) $selected['total_parts']))) ?></p>
        <p>Discount: <?= e(formatVND((int) round((float) $selected['discount']))) ?> · Total: <?= e(formatVND((int) round((float) $selected['grand_total']))) ?></p>
        <p>Payment method: <?= e(ucfirst((string) $selected['payment_method'])) ?></p>
        <?php if ($selected['payment_status'] === 'unpaid'): ?><p>Please contact the workshop to arrange payment.</p><?php endif; ?>
      </div>
      <div class="invoice-actions">
        <?= badge(ucfirst($selected['payment_status']), ['paid' => 'green', 'unpaid' => 'red'][$selected['payment_status']] ?? 'amber') ?>
      </div>
    </section>
    <?php endif; ?>
    <?php
}

function renderFabrication(array $fabrication): void
{
    $counts = $fabrication['counts'];
    $items = $fabrication['items'];
    $featured = $fabrication['featured'];
    $milestones = ['Request submitted', 'Workshop assessment', 'Build in progress', 'Final inspection', 'Completed / delivered'];
    $newProjectUrl = '../pages/fabrication.php#book';
    pageHeader('REDLINE CUSTOM WORKS', 'Custom Fabrication',
        'Your custom work, from the initial request through the workshop handover.',
        '<a class="button primary" href="' . e($newProjectUrl) . '">' . icon('plus', 17) . '<span>Request Custom Work</span></a>');
    ?>
    <div class="fab-stats" aria-label="Fabrication project summary">
      <?php foreach ([['All projects', 'total'], ['In progress', 'active'], ['Completed', 'completed'], ['Cancelled', 'cancelled']] as [$label, $key]): ?>
        <div class="glass fab-stat"><span><?= e($label) ?></span><strong><?= (int) $counts[$key] ?></strong></div>
      <?php endforeach; ?>
    </div>

    <?php if ($featured): ?>
      <section class="glass fabrication-hero fab-featured" aria-labelledby="fab-featured-heading">
        <img src="../assets/images/Fabrication-bg.jpg" alt="Fabrication workshop" loading="lazy">
        <div class="fab-overlay">
          <div class="fab-hero-top"><span class="fab-kicker">LATEST <?= $featured['group'] === 'active' ? 'ACTIVE' : 'RECORDED' ?> PROJECT</span><?= badge($featured['status_label'], $featured['tone']) ?></div>
          <p><?= e($featured['reference']) ?><?= $featured['order_code'] !== '' ? ' · ' . e($featured['order_code']) : '' ?></p>
          <h2 id="fab-featured-heading"><?= e($featured['service']) ?></h2>
          <span><?= e($featured['vehicle']) ?><?= $featured['plate'] !== '' ? ' · ' . e($featured['plate']) : '' ?></span>
          <p class="fab-featured-note"><?= e($featured['note']) ?></p>
          <div class="fab-actions">
            <a class="button primary" href="#fabrication-projects">View Project Details <?= icon('arrow', 16) ?></a>
            <?php if ($featured['order_id'] !== null): ?><a class="button secondary" href="?page=orders">Repair Order <?= icon('arrow', 16) ?></a><?php endif; ?>
          </div>
        </div>
      </section>
      <section class="glass fab-workflow" aria-label="Featured project workflow">
        <div class="fab-section-title"><div><p class="eyebrow">PROJECT JOURNEY</p><h2>Workshop milestones</h2></div><span>Based on recorded workshop status</span></div>
        <div class="fab-milestones">
          <?php foreach ($milestones as $index => $title):
            $number = $index + 1;
            $state = $featured['group'] === 'cancelled' ? 'stopped' : ($featured['stage'] > $number ? 'done' : ($featured['stage'] === $number ? 'current' : 'future'));
          ?>
            <div class="fab-milestone <?= e($state) ?>">
              <span class="fab-milestone-number"><?= $state === 'done' ? icon('check', 16) : sprintf('%02d', $number) ?></span>
              <strong><?= e($title) ?></strong>
              <small><?= $state === 'done' ? 'Recorded' : ($state === 'current' ? 'Current stage' : ($state === 'stopped' ? 'Cancelled' : 'Not yet confirmed')) ?></small>
            </div>
          <?php endforeach; ?>
        </div>
        <?php if ($featured['stage'] === 0 && $featured['group'] !== 'cancelled'): ?><p class="fab-workflow-note">The workshop has not confirmed a production milestone yet. A submitted request is not an approved design, quote or build schedule.</p><?php endif; ?>
      </section>
    <?php else: ?>
      <section class="glass fab-empty">
        <div class="fab-empty-symbol"><?= icon('sparkles', 32) ?></div>
        <p class="eyebrow">YOUR WORKSHOP STORY STARTS HERE</p>
        <h2>No custom projects yet</h2>
        <p>Explore exhaust systems, roll cages, metalwork and widebody conversions. Submit your idea and the team will review the scope with you.</p>
        <a class="button primary" href="<?= e($newProjectUrl) ?>">Explore Fabrication <?= icon('arrow', 16) ?></a>
      </section>
    <?php endif; ?>

    <?php if ($items): ?>
      <section class="glass fab-projects" id="fabrication-projects" aria-labelledby="fab-projects-heading">
        <div class="fab-section-title fab-list-heading"><div><p class="eyebrow">YOUR REQUESTS</p><h2 id="fab-projects-heading">Project history</h2><p>Updates reflect your requests and linked workshop orders.</p></div><span id="fab-visible-count" aria-live="polite"><?= count($items) ?> projects</span></div>
        <div class="fab-toolbar">
          <label class="fab-search"><span class="sr-only">Search fabrication projects</span><?= icon('search', 18) ?><input id="fab-project-search" type="search" placeholder="Search project, vehicle or reference…" autocomplete="off"></label>
          <label class="fab-filter-label">Status <select id="fab-project-status"><option value="all">All projects</option><option value="active">Active</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option></select></label>
        </div>
        <div class="fab-project-list" id="fab-project-list">
          <?php foreach ($items as $item): ?>
            <article class="fab-project" data-fab-group="<?= e($item['group']) ?>" data-fab-search="<?= e(strtolower(implode(' ', [$item['reference'], $item['order_code'], $item['service'], $item['vehicle'], $item['plate'], $item['status_label']]))) ?>">
              <div class="fab-project-head"><div><span class="fab-ref"><?= e($item['reference']) ?> · <?= e(date('M j, Y', strtotime($item['created_at']) ?: time())) ?></span><h3><?= e($item['service']) ?></h3><p><?= e($item['vehicle']) ?><?= $item['plate'] !== '' ? ' · ' . e($item['plate']) : '' ?></p></div><?= badge($item['status_label'], $item['tone']) ?></div>
              <p class="fab-project-note"><?= e($item['note']) ?></p>
              <div class="fab-project-footer">
                <span><?= $item['order_id'] !== null ? 'Workshop order: ' . e($item['order_code'] !== '' ? $item['order_code'] : '#RO-' . $item['order_id']) : 'Workshop order not yet created' ?></span>
                <div><button type="button" class="fab-detail-toggle" aria-expanded="false" aria-controls="fab-detail-<?= (int) $item['id'] ?>">Details <?= icon('chevron', 15) ?></button><?php if ($item['order_id'] !== null): ?><a class="fab-order-link" href="?page=orders">View repair orders <?= icon('arrow', 15) ?></a><?php endif; ?></div>
              </div>
              <div class="fab-project-details" id="fab-detail-<?= (int) $item['id'] ?>" hidden>
                <dl><div><dt>Request reference</dt><dd><?= e($item['reference']) ?></dd></div><div><dt>Submitted</dt><dd><?= e($item['created_at']) ?></dd></div><div><dt>Request status</dt><dd><?= e(ucfirst($item['request_status'])) ?></dd></div><div><dt>Workshop status</dt><dd><?= e($item['order_status'] !== '' ? str_replace('_', ' ', ucfirst($item['order_status'])) : 'Not linked yet') ?></dd></div></dl>
                <p>To discuss approvals, drawings, quotations or delivery dates, contact the workshop directly. They are not yet tracked as separate project records here.</p>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
        <p class="fab-no-results" id="fab-no-results" hidden>No projects match your search or filter.</p>
      </section>
    <?php endif; ?>
    <section class="glass fab-help"><div><p class="eyebrow">NEED A HAND?</p><h2>Planning another custom build?</h2><p>Tell the team what you have in mind. Your request is reviewed before scope, pricing and timing are agreed.</p></div><a class="button secondary" href="<?= e($newProjectUrl) ?>">Start a new request <?= icon('arrow', 16) ?></a></section>
    <script>
    (() => {
      const search = document.getElementById('fab-project-search');
      const status = document.getElementById('fab-project-status');
      const cards = Array.from(document.querySelectorAll('#fab-project-list .fab-project'));
      const count = document.getElementById('fab-visible-count');
      const empty = document.getElementById('fab-no-results');
      const refresh = () => {
        const term = (search?.value || '').trim().toLocaleLowerCase();
        const group = status?.value || 'all';
        let shown = 0;
        cards.forEach(card => {
          const visible = (group === 'all' || card.dataset.fabGroup === group) && (card.dataset.fabSearch || '').includes(term);
          card.hidden = !visible;
          if (visible) shown++;
        });
        if (count) count.textContent = shown + (shown === 1 ? ' project' : ' projects');
        if (empty) empty.hidden = shown !== 0;
      };
      search?.addEventListener('input', refresh);
      status?.addEventListener('change', refresh);
      document.querySelectorAll('.fab-detail-toggle').forEach(toggle => {
        toggle.addEventListener('click', () => {
          const details = document.getElementById(toggle.getAttribute('aria-controls'));
          if (!details) return;
          details.hidden = !details.hidden;
          toggle.setAttribute('aria-expanded', String(!details.hidden));
        });
      });
    })();
    </script>
    <?php
}

function renderProfile(array $profile, bool $support = false): void
{
    $toggles = [
        ['repair',   'Repair Updates',       'Track progress in real time',      $profile['notify']['repair']],
        ['reminder', 'Appointment reminder', '24 hours before your appointment', $profile['notify']['reminder']],
        ['offers',   'Offers & News',        'Receive member-only offers',       $profile['notify']['offers']],
    ];
    $methods = [
        'phone' => ['Phone', 'phone'],
        'zalo'  => ['Zalo',  'message'],
        'email' => ['Email', 'mail'],
    ];
    pageHeader(
        $support ? 'WE ARE HERE TO HELP' : 'ACCOUNT',
        $support ? 'Customer Support' : 'Personal Profile',
        $support ? 'Submit a request or contact the Redline team directly.'
                 : 'Manage your contact details, security, and notification preferences.'
    );
    renderFlash();
    ?>
    <div class="profile-grid">
      <section class="glass profile-form">
        <div class="panel-heading">
          <div>
            <p class="eyebrow"><?= $support ? 'SUBMIT A REQUEST' : 'PERSONAL INFORMATION' ?></p>
            <h2><?= $support ? 'How can we help?' : 'Contact Information' ?></h2>
          </div>
          <?= $support ? '' : '<div class="avatar">' . e($profile['initials']) . '</div>' ?>
        </div>
        <?php if ($support): ?>
          <label>Topic
            <select>
              <option selected>Technical Support</option>
              <option>Billing</option>
              <option>Appointments</option>
            </select>
          </label>
          <label>Message
            <textarea rows="5" placeholder="Describe the issue you are experiencing..."></textarea>
          </label>
          <?= btn('Submit Request', 'primary', 'message') ?>
        <?php else: ?>
          <form method="post" action="?page=profile" id="profile-form">
            <?= rl_csrf_field() ?>
            <input type="hidden" name="action" value="update_profile">
            <div class="form-grid">
              <label><span class="lbl">Full Name <b class="req">*</b></span>
                <input name="full_name" value="<?= e($profile['name']) ?>" maxlength="120" required autocomplete="name">
              </label>
              <label><span class="lbl">Phone Number <b class="req">*</b></span>
                <input name="phone" type="tel" value="<?= e($profile['phone']) ?>" maxlength="20" placeholder="0901 234 567" required autocomplete="off">
              </label>
              <label><span class="lbl">Zalo Number <span class="opt">(optional)</span></span>
                <input name="zalo" type="tel" value="<?= e($profile['zalo']) ?>" maxlength="20" placeholder="Same as phone or another number" autocomplete="off">
              </label>
              <label><span class="lbl">Email <b class="req">*</b></span>
                <input name="email" type="email" value="<?= e($profile['email']) ?>" maxlength="190" required autocomplete="email">
              </label>
            </div>

            <fieldset class="contact-methods">
              <legend>How should the workshop contact you?</legend>
              <?php foreach ($methods as $key => [$label, $ic]): ?>
                <label class="method">
                  <input type="radio" name="contact_method" value="<?= e($key) ?>" <?= $profile['method'] === $key ? 'checked' : '' ?>>
                  <span><?= icon($ic, 18) ?><b><?= e($label) ?></b></span>
                </label>
              <?php endforeach; ?>
            </fieldset>
            <p class="form-hint">Choose one of the contact details above. The workshop will use it to confirm your appointments. Submitting a request does not mean the workshop has confirmed the schedule or approved any costs.</p>

            <div class="form-actions"><?= btn('Save Changes', 'primary', 'check', '', 'submit') ?></div>
          </form>
        <?php endif; ?>
      </section>

      <?php if ($support): ?>
        <section class="glass shop-info">
          <p class="eyebrow">REDLINE DISTRICT 7</p>
          <h2>Workshop Information</h2>
          <div class="info-row">
            <?= icon('map') ?>
            <div>
              <strong>128 Nguyen Huu Tho Street</strong>
              <span>Tan Hung Ward, District 7, Ho Chi Minh City</span>
            </div>
          </div>
          <div class="info-row">
            <?= icon('phone') ?>
            <div>
              <strong>1900 2628</strong>
              <span>Monday – Saturday · 8:00 AM – 6:00 PM</span>
            </div>
          </div>
          <div class="rating">
            <div><strong>4.9</strong><span>Service Rating</span></div>
            <div><?php for ($n = 0; $n < 5; $n++) { echo icon('star', 18); } ?></div>
          </div>
        </section>
      <?php else: ?>
        <div class="settings-stack">
          <section class="glass security">
            <div class="setting-title">
              <span><?= icon('shield') ?></span>
              <div><strong>Password &amp; Security</strong><small><?= e($profile['pwText']) ?></small></div>
            </div>
            <?= btn('Change Password', 'secondary', null, 'data-modal="password"') ?>
          </section>
          <section class="glass notification-settings">
            <p class="eyebrow">PREFERENCES</p>
            <h2>Notifications</h2>
            <?php foreach ($toggles as [$key, $t, $desc, $on]): ?>
              <div class="toggle-row">
                <div><strong><?= e($t) ?></strong><span><?= e($desc) ?></span></div>
                <button type="button" class="switch <?= $on ? 'on' : '' ?>" data-notify="<?= e($key) ?>" role="switch" aria-checked="<?= $on ? 'true' : 'false' ?>" aria-label="<?= e($t) ?>"><i></i></button>
              </div>
            <?php endforeach; ?>
          </section>
        </div>
      <?php endif; ?>
    </div>
    <?php
}

/* ---------- Modals (shared, driven by JS) ---------- */

function renderModals(array $vehicles): void
{
    $serviceGroups = rl_service_groups();
    $availableTechnicians = [];
    try {
        $availableTechnicians = db()->query("SELECT u.id, u.full_name FROM users u
            INNER JOIN roles r ON r.id = u.role_id
            WHERE r.name = 'Technician' AND u.is_active = 1
            ORDER BY u.full_name ASC, u.id ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $ex) {
        error_log('Customer booking technician list failed: ' . $ex->getMessage());
    }
    $stepTitles = ['Choose a Service', 'Choose a Vehicle', 'Choose a Date & Time'];
    ?>
    <!-- Booking modal -->
    <div class="modal-backdrop" id="modal-booking" hidden>
      <div class="modal glass">
        <button type="button" class="modal-close" data-close aria-label="Close"><?= icon('x') ?></button>
        <div class="success-state" hidden>
          <div><?= icon('check', 32) ?></div>
          <p class="eyebrow">COMPLETE</p>
          <h2>Appointment request submitted</h2>
          <p>Your request has been sent to the workshop. We will contact you to confirm the schedule.</p>
          <a class="button primary" href="?page=appointments">View my appointments <?= icon('arrow', 16) ?></a>
        </div>
        <div class="modal-body">
          <p class="eyebrow" id="booking-eyebrow">BOOKING · STEP 1/3</p>
          <h2 id="booking-title"><?= e($stepTitles[0]) ?></h2>
          <p class="modal-intro">It takes less than two minutes to complete.</p>
          <div class="modal-progress"><i class="active"></i><i></i><i></i></div>
          <form id="booking-form">
            <div class="modal-pane" data-step="1">
              <label>Service
                <select id="booking-service" name="service" required>
                  <?php foreach ($serviceGroups as $group => $options): ?>
                    <optgroup label="<?= e($group) ?>">
                      <?php foreach ($options as $value => $label): ?>
                        <option value="<?= e($value) ?>"><?= e($label) ?></option>
                      <?php endforeach; ?>
                    </optgroup>
                  <?php endforeach; ?>
                </select>
              </label>
            </div>
            <div class="modal-pane choice-grid vehicles-choice" data-step="2" hidden>
              <?php if (!$vehicles): ?>
                <p class="modal-intro">You have no vehicles yet. <a href="?page=vehicles" style="color:#ffad70">Add one</a> first.</p>
              <?php endif; ?>
              <?php foreach ($vehicles as $i => $v): ?>
                <button type="button" class="<?= $i === 0 ? 'selected' : '' ?>" data-choice data-vehicle-id="<?= (int) $v['id'] ?>">
                  <?= icon('car') ?>
                  <span><strong><?= e($v['model']) ?></strong><small><?= e($v['plate']) ?></small></span>
                  <?= $i === 0 ? icon('check', 16) : '' ?>
                </button>
              <?php endforeach; ?>
            </div>
            <div class="modal-pane" data-step="3" hidden>
              <label class="mini-calendar">Preferred appointment date
                <input type="date" id="booking-date" name="date" min="<?= e(date('Y-m-d')) ?>" value="<?= e(date('Y-m-d')) ?>" required>
              </label>
              <div class="time-slots">
                <?php foreach (['09:00' => '9:00 AM', '10:30' => '10:30 AM', '14:30' => '2:30 PM', '16:00' => '4:00 PM'] as $value => $t): ?>
                  <button type="button" class="<?= $value === '14:30' ? 'selected' : '' ?>" data-slot="<?= e($value) ?>"><?= e($t) ?></button>
                <?php endforeach; ?>
              </div>
              <label style="display:block;margin-top:18px">Preferred technician (optional)
                <select id="booking-technician" name="preferred_technician_id" aria-describedby="booking-technician-help">
                  <option value="">No preference — let the administrator assign</option>
                  <?php foreach ($availableTechnicians as $technician): ?>
                    <option value="<?= (int)$technician['id'] ?>"><?= e($technician['full_name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </label>
              <p class="modal-intro" id="booking-technician-help" style="margin-top:8px;font-size:12px">You can choose an available technician. If you have no preference, the administrator will assign one. Your choice is confirmed when the repair order is approved.</p>
            </div>
            <p class="form-error" id="booking-error" role="alert" hidden></p>
            <div class="modal-actions">
              <?= btn('Back', 'secondary', null, 'id="booking-back" hidden') ?>
              <?= btn('Continue', 'primary', null, 'id="booking-next"') ?>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Add / edit vehicle modal (docx section 2 "Vehicle Information" + optional photo from section 5) -->
    <div class="modal-backdrop" id="modal-vehicle" hidden>
      <div class="modal glass">
        <button type="button" class="modal-close" data-close aria-label="Close"><?= icon('x') ?></button>
        <div class="modal-body">
          <p class="eyebrow">YOUR GARAGE</p>
          <h2 id="vehicle-modal-title">Add New Vehicle</h2>
          <p class="modal-intro">Enter the basic details so we can provide better vehicle care.</p>
          <form id="vehicle-form" method="post" action="?page=vehicles" enctype="multipart/form-data">
            <?= rl_csrf_field() ?>
            <input type="hidden" name="action" value="add_vehicle" id="vehicle-action">
            <input type="hidden" name="id" value="" id="vehicle-id">
            <div class="form-grid">
              <label><span class="lbl">Make <b class="req">*</b></span>
                <input name="make" placeholder="Example: BMW" maxlength="60" required>
              </label>
              <label><span class="lbl">Model <b class="req">*</b></span>
                <input name="model" placeholder="Example: 330i" maxlength="80" required>
              </label>
              <label><span class="lbl">License Plate <b class="req">*</b></span>
                <input name="plate" placeholder="51G-000.00" maxlength="20" required style="text-transform:uppercase">
              </label>
              <label><span class="lbl">Year of Manufacture</span>
                <input name="year" inputmode="numeric" pattern="[0-9]{4}" maxlength="4" placeholder="2022">
              </label>
              <label><span class="lbl">Current Mileage (km)</span>
                <input name="mileage" inputmode="numeric" maxlength="9" placeholder="Example: 35000">
              </label>
              <label><span class="lbl">Vehicle Color <span class="opt">(optional)</span></span>
                <input name="color" placeholder="Example: White" maxlength="40">
              </label>
              <label><span class="lbl">VIN <span class="opt">(optional)</span></span>
                <input name="vin" maxlength="50" style="text-transform:uppercase">
              </label>
              <label><span class="lbl">Powertrain</span>
                <select name="engine_type">
                  <option value="gasoline">Gasoline</option><option value="diesel">Diesel</option>
                  <option value="hybrid">Hybrid</option><option value="ev">Electric (EV)</option>
                </select>
              </label>
              <label data-garage-battery hidden><span class="lbl">Battery Capacity (kWh)</span>
                <input name="battery_capacity" type="number" min="0" max="9999.99" step="0.01">
              </label>
              <label data-garage-battery hidden><span class="lbl">Battery Health / SoH (%)</span>
                <input name="battery_soh" type="number" min="0" max="100" step="0.01">
              </label>
              <label class="wide"><span class="lbl">Vehicle Photo <span class="opt">(optional · JPG, PNG or WebP, max 5 MB)</span></span>
                <input name="photo" type="file" accept="image/jpeg,image/png,image/webp" id="vehicle-photo">
              </label>
              <div class="wide photo-current" id="photo-current" hidden>
                <img src="" alt="Current vehicle photo">
                <label class="check"><input type="checkbox" name="remove_photo" value="1"> Remove current photo</label>
              </div>
            </div>
            <p class="form-hint">These details are shared with the workshop. VIN and battery details are optional and can be checked by staff at intake.</p>
            <p class="form-hint" id="vehicle-save-status" role="status" aria-live="polite" hidden></p>
            <div class="modal-actions">
              <?= btn('Confirm', 'primary', null, 'id="vehicle-submit"', 'submit') ?>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Delete vehicle modal -->
    <div class="modal-backdrop" id="modal-delete" hidden>
      <div class="modal glass narrow">
        <button type="button" class="modal-close" data-close aria-label="Close"><?= icon('x') ?></button>
        <div class="modal-body">
          <p class="eyebrow">YOUR GARAGE</p>
          <h2>Remove this vehicle?</h2>
          <p class="modal-intro"><strong id="delete-name"></strong> will be removed from your garage, along with its saved service history. This cannot be undone.</p>
          <form method="post" action="?page=vehicles">
            <?= rl_csrf_field() ?>
            <input type="hidden" name="action" value="delete_vehicle">
            <input type="hidden" name="id" value="" id="delete-id">
            <div class="modal-actions">
              <?= btn('Cancel', 'secondary', null, 'data-close') ?>
              <?= btn('Delete', 'danger', 'trash', '', 'submit') ?>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Change password modal -->
    <div class="modal-backdrop" id="modal-password" hidden>
      <div class="modal glass narrow">
        <button type="button" class="modal-close" data-close aria-label="Close"><?= icon('x') ?></button>
        <div class="modal-body">
          <p class="eyebrow">PASSWORD &amp; SECURITY</p>
          <h2>Change Password</h2>
          <p class="modal-intro">Use at least 8 characters. You will stay signed in on this device.</p>
          <form method="post" action="?page=profile" id="password-form">
            <?= rl_csrf_field() ?>
            <input type="hidden" name="action" value="change_password">
            <div class="form-grid single">
              <label><span class="lbl">Current password</span>
                <input name="current_password" type="password" autocomplete="current-password" required>
              </label>
              <label><span class="lbl">New password</span>
                <input name="new_password" type="password" minlength="8" autocomplete="new-password" required>
              </label>
              <label><span class="lbl">Confirm new password</span>
                <input name="confirm_password" type="password" minlength="8" autocomplete="new-password" required>
              </label>
            </div>
            <p class="field-error" id="password-error" hidden></p>
            <div class="modal-actions">
              <?= btn('Cancel', 'secondary', null, 'data-close') ?>
              <?= btn('Update Password', 'primary', null, '', 'submit') ?>
            </div>
          </form>
        </div>
      </div>
    </div>
    <?php
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Redline · <?= e($currentLabel) ?></title>
  <link rel="stylesheet" href="../assets/css/dashboard.css?v=<?= @filemtime(__DIR__ . '/../assets/css/dashboard.css') ?: time() ?>">
  <?php if ($page === 'fabrication'): ?>
    <link rel="stylesheet" href="../assets/css/customer-fabrication.css?v=<?= @filemtime(__DIR__ . '/../assets/css/customer-fabrication.css') ?: time() ?>">
  <?php endif; ?>
  <?php if ($page === 'appointments'): ?>
    <link rel="stylesheet" href="../assets/css/customer-appointments.css?v=<?= @filemtime(__DIR__ . '/../assets/css/customer-appointments.css') ?: time() ?>">
  <?php endif; ?>
</head>
<body<?= $page === 'appointments' ? ' class="customer-appointments-page"' : '' ?>>
<div class="app-shell">

  <aside class="sidebar glass" id="sidebar">
    <div class="brand">
      <a href="../index.php" aria-label="Redline home">
        <img class="brand-logo" src="../assets/images/redline-logo.png" alt="Redline">
      </a>
      <button type="button" class="mobile-close" id="menu-close" aria-label="Close menu"><?= icon('x') ?></button>
    </div>
    <nav>
      <?php foreach (array_slice($nav, 0, 6) as [$id, $label, $ic]): ?>
        <a href="<?= navUrl($id) ?>" class="<?= $page === $id ? 'active' : '' ?>">
          <?= icon($ic) ?><span><?= e($label) ?></span><?= $page === $id ? '<i></i>' : '' ?>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="nav-divider"></div>
    <nav>
      <?php foreach (array_slice($nav, 6) as [$id, $label, $ic]): ?>
        <a href="<?= navUrl($id) ?>" class="<?= $page === $id ? 'active' : '' ?>">
          <?= icon($ic) ?><span><?= e($label) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
    <div class="membership">
      <?= icon('star') ?>
      <div>
        <span>MEMBER</span>
        <strong>Redline Signature</strong>
        <small>1,240 reward points</small>
      </div>
    </div>
    <footer>
      <span>REDLINE</span>
      <small>Customer Portal · v2.4</small>
    </footer>
  </aside>
  <button type="button" class="mobile-scrim" id="scrim" hidden aria-label="Close menu"></button>

  <div class="workspace">
    <header class="topbar glass">
      <button type="button" class="menu-button" id="menu-open" aria-label="Open menu"><?= icon('menu') ?></button>
      <div class="mobile-title"><?= e($currentLabel) ?></div>
      <label class="search">
        <?= icon('search', 18) ?>
        <?php if ($page === 'appointments'): ?>
          <input type="search" id="customer-appointment-topbar-search" aria-label="Search your appointment requests" placeholder="Search your appointment requests…" autocomplete="off">
        <?php else: ?><input placeholder="Search vehicles, invoices, repair orders..."><?php endif; ?>
      </label>
      <div class="top-actions">
        <button type="button" class="icon-button" aria-label="Notifications"><?= icon('bell') ?><i></i></button>
        <div class="profile-menu" id="profile-menu">
          <button type="button" class="profile-trigger" id="profile-trigger" aria-haspopup="true" aria-expanded="false">
            <div class="avatar"><?= e($initials) ?></div>
            <div><strong><?= e($userName) ?></strong><span>Signature Member</span></div>
            <?= icon('chevron', 16) ?>
          </button>
          <div class="profile-dropdown" id="profile-dropdown" hidden>
            <a href="<?= navUrl('profile') ?>" class="dropdown-item">
              <?= icon('user') ?>
              <div><strong>My Profile</strong><span>Account and preferences</span></div>
            </a>
            <div class="dropdown-divider"></div>
            <a href="../auth/logout.php" class="dropdown-item danger">
              <?= icon('logout') ?>
              <div><strong>Log Out</strong><span>Sign out of this device</span></div>
            </a>
          </div>
        </div>
      </div>
    </header>

    <main>
      <?php
      switch ($page) {
          case 'vehicles':     renderVehicles($vehicles, $historyByVehicle, $selectedVehicleId); break;
          case 'appointments': renderAppointments(); break;
          case 'orders':       renderOrders($orders, $currentRepair); break;
          case 'invoices':     renderInvoices($invoices); break;
          case 'fabrication':  renderFabrication($fabrication); break;
          case 'profile':      renderProfile($profile); break;
          case 'support':      renderProfile($profile, true); break;
          default:             renderOverview($firstName, $orders, $currentRepair);
      }
      ?>
    </main>

    <nav class="mobile-nav glass">
      <?php foreach (array_slice($nav, 0, 5) as [$id, $label, $ic]): ?>
        <a href="<?= navUrl($id) ?>" class="<?= $page === $id ? 'active' : '' ?>">
          <?= icon($ic) ?><span><?= e(explode(' ', $label)[0]) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>
  </div>

  <?php renderModals($vehicles); ?>
</div>

<script>
(function () {
  var CSRF = <?= json_encode(rl_csrf_token()) ?>;
  var $  = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  /* Keep technician progress current without reloading forms or the whole page. */
  var progressPanel = $('#current-repair-progress');
  if (progressPanel) {
    var refreshingProgress = false;
    var lastProgressHtml = progressPanel.innerHTML;
    var ordersPanel = $('#repair-orders-table');
    var lastOrdersHtml = ordersPanel ? ordersPanel.innerHTML : '';
    function refreshRepairProgress() {
      if (document.hidden || refreshingProgress) return;
      refreshingProgress = true;
      fetch('index.php?repair_progress=1', { credentials: 'same-origin', cache: 'no-store' })
        .then(function (response) {
          if (!response.ok || response.redirected) throw new Error('Progress unavailable');
          return response.json();
        })
        .then(function (data) {
          if (typeof data.html === 'string' && data.html !== lastProgressHtml) {
            progressPanel.innerHTML = data.html;
            lastProgressHtml = data.html;
          }
          if (ordersPanel && typeof data.orders_html === 'string' && data.orders_html !== lastOrdersHtml) {
            ordersPanel.innerHTML = data.orders_html;
            lastOrdersHtml = data.orders_html;
          }
          var count = $('#active-repair-count');
          if (count) count.textContent = String(data.active_count).padStart(2, '0');
        })
        .catch(function () { /* Keep the last saved progress if the connection is lost. */ })
        .finally(function () { refreshingProgress = false; });
    }
    setInterval(refreshRepairProgress, 15000);
    document.addEventListener('visibilitychange', refreshRepairProgress);
    window.addEventListener('focus', refreshRepairProgress);
  }

  /* Mobile menu */
  var sidebar = $('#sidebar'), scrim = $('#scrim');
  function setMenu(open) { sidebar.classList.toggle('open', open); scrim.hidden = !open; }
  $('#menu-open').addEventListener('click', function () { setMenu(true); });
  $('#menu-close').addEventListener('click', function () { setMenu(false); });
  scrim.addEventListener('click', function () { setMenu(false); });

  /* Profile dropdown */
  var pm = $('#profile-menu'), pt = $('#profile-trigger'), pd = $('#profile-dropdown');
  function setProfile(open) {
    pd.hidden = !open;
    pm.classList.toggle('open', open);
    pt.setAttribute('aria-expanded', open);
  }
  pt.addEventListener('click', function (e) { e.stopPropagation(); setProfile(pd.hidden); });
  document.addEventListener('click', function (e) { if (!pm.contains(e.target)) setProfile(false); });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') setProfile(false); });

  /* Page shortcuts */
  $$('[data-go]').forEach(function (b) {
    b.addEventListener('click', function () { location.href = '?page=' + b.getAttribute('data-go'); });
  });

  /* Notification switches: saved to the database */
  $$('.switch[data-notify]').forEach(function (s) {
    s.addEventListener('click', function () {
      var on = !s.classList.contains('on');
      s.classList.toggle('on', on);
      s.setAttribute('aria-checked', on ? 'true' : 'false');
      var fd = new FormData();
      fd.append('action', 'save_notify');
      fd.append('csrf', CSRF);
      fd.append('key', s.getAttribute('data-notify'));
      fd.append('value', on ? '1' : '0');
      fetch('?page=profile', { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (j) { if (!j.ok) throw new Error('fail'); })
        .catch(function () {
          s.classList.toggle('on', !on);
          s.setAttribute('aria-checked', !on ? 'true' : 'false');
          alert('Could not save your preference. Please try again.');
        });
    });
  });

  /* Modals */
  var titles = ['Choose a Service', 'Choose a Vehicle', 'Choose a Date & Time'];
  var step = 1;
  var bookingSaved = false;
  function resetBooking(m) {
    if (bookingSubmitting) return;
    var ss = $('.success-state', m);
    if (ss) ss.hidden = true;
    $('.modal-body', m).hidden = false;
    $('#booking-error').hidden = true;
    var technicianSelect = $('#booking-technician');
    if (technicianSelect) technicianSelect.value = '';
    step = 1; renderStep();
  }

  /* Vehicle form: add mode (data = null) or edit mode */
  var vForm = $('#vehicle-form');
  var vehicleSubmitting = false;

  /* Shrink large photos before upload; retain the original if conversion is unavailable. */
  async function prepareVehiclePhoto(input) {
    var file = input.files[0];
    if (!file || file.size <= 350 * 1024 || typeof DataTransfer === 'undefined') return;
    if (['image/jpeg', 'image/png', 'image/webp'].indexOf(file.type) === -1) return;
    var url = URL.createObjectURL(file);
    try {
      var photo = new Image();
      await new Promise(function (resolve, reject) {
        photo.onload = resolve;
        photo.onerror = reject;
        photo.src = url;
      });
      var scale = Math.min(1, 1600 / Math.max(photo.naturalWidth, photo.naturalHeight));
      var canvas = document.createElement('canvas');
      canvas.width = Math.max(1, Math.round(photo.naturalWidth * scale));
      canvas.height = Math.max(1, Math.round(photo.naturalHeight * scale));
      canvas.getContext('2d').drawImage(photo, 0, 0, canvas.width, canvas.height);
      // WebP retains transparency for PNG uploads.
      var type = file.type === 'image/jpeg' ? 'image/jpeg' : 'image/webp';
      var blob = await new Promise(function (resolve) { canvas.toBlob(resolve, type, 0.82); });
      if (blob && blob.size < file.size) {
        var extension = { 'image/jpeg': '.jpg', 'image/webp': '.webp', 'image/png': '.png' }[blob.type];
        if (!extension) return;
        var files = new DataTransfer();
        files.items.add(new File([blob], file.name.replace(/\.[^.]+$/, '') + extension, { type: blob.type }));
        input.files = files.files;
      }
    } catch (error) {
      // The server still validates the original upload if the browser cannot optimize it.
    } finally {
      URL.revokeObjectURL(url);
    }
  }

  vForm.addEventListener('submit', async function (event) {
    event.preventDefault();
    if (vehicleSubmitting) return;
    vehicleSubmitting = true;
    var button = $('#vehicle-submit'), status = $('#vehicle-save-status');
    button.disabled = true;
    vForm.setAttribute('aria-busy', 'true');
    status.hidden = false;
    status.textContent = vForm.elements.photo.files.length ? 'Preparing photo and saving vehicle…' : 'Saving vehicle…';
    $('span', button).textContent = 'Saving…';
    await prepareVehiclePhoto(vForm.elements.photo);
    status.textContent = 'Saving vehicle…';
    HTMLFormElement.prototype.submit.call(vForm);
  });
  window.addEventListener('pageshow', function () {
    vehicleSubmitting = false;
    $('#vehicle-submit').disabled = false;
    $('#vehicle-submit span').textContent = $('#vehicle-action').value === 'update_vehicle' ? 'Save Changes' : 'Confirm';
    $('#vehicle-save-status').hidden = true;
    vForm.removeAttribute('aria-busy');
  });

  function syncGarageBattery() {
    var enabled = ['ev', 'hybrid'].indexOf(vForm.elements.engine_type.value) !== -1;
    $$('[data-garage-battery]', vForm).forEach(function (field) { field.hidden = !enabled; });
  }
  vForm.elements.engine_type.addEventListener('change', syncGarageBattery);
  function prepareVehicleForm(data) {
    vForm.reset();
    var editing = !!data;
    $('#vehicle-action').value = editing ? 'update_vehicle' : 'add_vehicle';
    $('#vehicle-id').value = editing ? data.id : '';
    $('#vehicle-modal-title').textContent = editing ? 'Edit Vehicle' : 'Add New Vehicle';
    $('#vehicle-submit span').textContent = editing ? 'Save Changes' : 'Confirm';
    var box = $('#photo-current');
    box.hidden = !(editing && data.photo);
    if (editing) {
      ['make', 'model', 'plate', 'year', 'mileage', 'color', 'vin', 'engine_type', 'battery_capacity', 'battery_soh'].forEach(function (k) {
        vForm.elements[k].value = data[k] === null || data[k] === undefined ? '' : data[k];
      });
      if (data.photo) $('img', box).src = data.photo;
    }
    syncGarageBattery();
  }

  function showModal(name) { $('#modal-' + name).hidden = false; }
  function openModal(name) {
    var m = $('#modal-' + name);
    if (name === 'booking') resetBooking(m);
    if (name === 'vehicle') prepareVehicleForm(null);
    if (name === 'password') { $('#password-form').reset(); $('#password-error').hidden = true; }
    m.hidden = false;
  }
  function closeModals() {
    $$('.modal-backdrop').forEach(function (m) { m.hidden = true; });
    if (bookingSaved && document.body.classList.contains('customer-appointments-page')) location.href = '?page=appointments';
  }

  $$('[data-modal]').forEach(function (b) {
    b.addEventListener('click', function () { openModal(b.getAttribute('data-modal')); });
  });
  $$('.modal-backdrop').forEach(function (m) {
    m.addEventListener('mousedown', function (e) { if (e.target === m) closeModals(); });
    $$('[data-close]', m).forEach(function (b) { b.addEventListener('click', closeModals); });
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModals(); });

  /* Vehicle card menu (Edit / Delete) */
  function closeMenus() {
    $$('.menu-pop').forEach(function (p) { p.hidden = true; });
    $$('.menu-trigger').forEach(function (t) { t.setAttribute('aria-expanded', 'false'); });
  }
  $$('.menu-trigger').forEach(function (t) {
    t.addEventListener('click', function (e) {
      e.stopPropagation();
      var pop = t.nextElementSibling, willOpen = pop.hidden;
      closeMenus();
      pop.hidden = !willOpen;
      t.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
    });
  });
  document.addEventListener('click', closeMenus);

  $$('[data-edit-vehicle]').forEach(function (b) {
    b.addEventListener('click', function () {
      closeMenus();
      prepareVehicleForm(JSON.parse(b.getAttribute('data-edit-vehicle')));
      showModal('vehicle');
    });
  });
  $$('[data-delete-vehicle]').forEach(function (b) {
    b.addEventListener('click', function () {
      closeMenus();
      $('#delete-id').value = b.getAttribute('data-delete-vehicle');
      $('#delete-name').textContent = b.getAttribute('data-name');
      showModal('delete');
    });
  });

  function renderStep() {
    $$('#booking-form .modal-pane').forEach(function (p) { p.hidden = p.getAttribute('data-step') != step; });
    $$('#modal-booking .modal-progress i').forEach(function (i, idx) { i.classList.toggle('active', idx < step); });
    $('#booking-eyebrow').textContent = 'BOOKING · STEP ' + step + '/3';
    $('#booking-title').textContent = titles[step - 1];
    $('#booking-back').hidden = step === 1;
    $('#booking-next span').textContent = step < 3 ? 'Continue' : 'Confirm';
  }
  var bookingSubmitting = false;
  async function advanceBooking() {
    if (bookingSubmitting) return;
    var error = $('#booking-error');
    error.hidden = true;
    var vehicle = $('#booking-form [data-vehicle-id].selected');
    if (step === 2 && !vehicle) {
      error.textContent = 'Add a vehicle to your garage before booking.'; error.hidden = false; return;
    }
    if (step < 3) { step++; renderStep(); return; }
    var date = $('#booking-date');
    if (!date.reportValidity()) return;
    var service = $('#booking-service');
    var slot = $('#booking-form [data-slot].selected');
    if (!vehicle || !service.value || !slot) {
      error.textContent = 'Choose a service, vehicle and time.'; error.hidden = false; return;
    }
    var data = new FormData();
    data.append('csrf', CSRF);
    data.append('vehicle_id', vehicle.getAttribute('data-vehicle-id'));
    data.append('service', service.value);
    data.append('date', date.value);
    data.append('time', slot.getAttribute('data-slot'));
    data.append('preferred_technician_id', $('#booking-technician').value);
    bookingSubmitting = true;
    $('#booking-next').disabled = true;
    $('#booking-back').disabled = true;
    $('#booking-next span').textContent = 'Sending...';
    try {
      var response = await fetch('../api/appointments/book.php', {
        method: 'POST', body: data, credentials: 'same-origin'
      });
      var result = await response.json();
      if (!response.ok || !result.ok) throw new Error(result.message || 'Could not save your request. Please try again.');
      bookingSaved = true;
      var m = $('#modal-booking'); $('.modal-body', m).hidden = true; $('.success-state', m).hidden = false;
    } catch (ex) {
      error.textContent = ex.message || 'Could not save your request. Please try again.'; error.hidden = false;
    } finally {
      bookingSubmitting = false;
      $('#booking-next').disabled = false;
      $('#booking-back').disabled = false;
      renderStep();
    }
  }
  $('#booking-next').addEventListener('click', advanceBooking);
  $('#booking-form').addEventListener('submit', function (event) {
    event.preventDefault(); advanceBooking();
  });
  $('#booking-back').addEventListener('click', function () { if (step > 1) { step--; renderStep(); } });

  /* Selectable choices, days and time slots */
  $$('#booking-form .choice-grid').forEach(function (grid) {
    $$('[data-choice]', grid).forEach(function (c) {
      c.addEventListener('click', function () {
        $$('[data-choice]', grid).forEach(function (x) { x.classList.toggle('selected', x === c); });
      });
    });
  });
  $$('[data-slot]').forEach(function (s) {
    s.addEventListener('click', function () {
      $$('[data-slot]').forEach(function (x) { x.classList.toggle('selected', x === s); });
    });
  });
  /* Vehicle form: digits only in year and mileage, photo size check */
  $$('#vehicle-form input[name="year"], #vehicle-form input[name="mileage"]').forEach(function (inp) {
    inp.addEventListener('input', function () { inp.value = inp.value.replace(/[^\d]/g, ''); });
  });
  $('#vehicle-photo').addEventListener('change', function () {
    var f = this.files[0];
    if (f && f.size > 5 * 1024 * 1024) { alert('The photo must be 5 MB or smaller.'); this.value = ''; }
  });

  /* Profile: keep phone to digits and common separators */
  $$('#profile-form input[name="phone"], #profile-form input[name="zalo"]').forEach(function (inp) {
    inp.addEventListener('input', function () { inp.value = inp.value.replace(/[^\d+\s.\-()]/g, ''); });
  });

  /* Password form: client-side match check */
  var pf = $('#password-form');
  pf.addEventListener('submit', function (e) {
    var err = $('#password-error'), msg = '';
    if (pf.new_password.value.length < 8) msg = 'The new password must be at least 8 characters.';
    else if (pf.new_password.value !== pf.confirm_password.value) msg = 'The new password and its confirmation do not match.';
    if (msg) { e.preventDefault(); err.textContent = msg; err.hidden = false; }
  });

  /* Flash message: fade out after a few seconds */
  var flash = $('.flash');
  if (flash) setTimeout(function () { flash.style.display = 'none'; }, 6000);

  /* Scroll to service history when a vehicle was chosen */
  function showServiceHistory() {
    if (location.hash !== '#history') return;
    var h = $('#history');
    if (h) {
      h.focus({ preventScroll: true });
      h.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
  }
  showServiceHistory();
  window.addEventListener('hashchange', showServiceHistory);
})();
</script>
<?php if ($page === 'appointments'): ?>
  <script src="../assets/js/customer-appointments.js?v=<?= @filemtime(__DIR__ . '/../assets/js/customer-appointments.js') ?: time() ?>"></script>
<?php endif; ?>
</body>
</html>
 