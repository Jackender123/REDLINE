<?php
// www/customers.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/admin_vehicle_portal_sync.php';
if (function_exists('requireAuth')) {
    requireAuth();
}

$staff = function_exists('current_staff') ? current_staff() : null;
$userRole = $_SESSION['role'] ?? ($staff['role_name'] ?? 'Administrator');
if (!$staff || !in_array($staff['role_name'] ?? '', ['Admin', 'Receptionist'], true)) {
    header('Location: ../../employees/index.php');
    exit;
}
$userName = $_SESSION['full_name'] ?? ($staff['full_name'] ?? 'Administrator');
$userDisplayRole = $userRole === 'Admin' ? 'Administrator' : ($userRole ?: 'Staff');
$pdo = db();

$noticeMsg = '';
$errorMsg = '';
$csrfToken = csrf_token();
// Customer Support moved here when the redundant Operations Center page was retired.
// Keep the existing customer portal ticket/reply workflow; only Admin can write replies.
$supportFlash = $_SESSION['redline_support_flash'] ?? '';
unset($_SESSION['redline_support_flash']);
$supportError = '';
$supportTickets = [];
$supportReplies = [];
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'update_ticket') {
    if (($staff['role_name'] ?? '') !== 'Admin') {
        http_response_code(403);
        $supportError = 'Only Admin can update support requests.';
    } elseif (!csrf_valid($_POST['csrf'] ?? null)) {
        http_response_code(403);
        $supportError = 'Session expired. Reload the page and try again.';
    } else {
        $ticketId = filter_var($_POST['ticket_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $status = (string)($_POST['status'] ?? '');
        $reply = trim((string)($_POST['reply'] ?? ''));
        if (!$ticketId || !in_array($status, ['open', 'in_progress', 'resolved'], true)
            || mb_strlen($reply) > 3000 || ($reply !== '' && mb_strlen($reply) < 2)) {
            http_response_code(422);
            $supportError = 'Check the ticket status and reply length.';
        } else {
            try {
                $pdo->beginTransaction();
                $lock = $pdo->prepare('SELECT id FROM support_tickets WHERE id = ? FOR UPDATE');
                $lock->execute([$ticketId]);
                if (!$lock->fetchColumn()) throw new RuntimeException('Support request does not exist.');
                if ($reply !== '') {
                    $pdo->prepare('INSERT INTO support_ticket_replies (ticket_id, author_id, message) VALUES (?, ?, ?)')
                        ->execute([$ticketId, (int)$staff['id'], $reply]);
                }
                $pdo->prepare("UPDATE support_tickets SET status = ?, handled_by = ?, resolved_at = CASE WHEN ? = 'resolved' THEN COALESCE(resolved_at, NOW()) ELSE NULL END WHERE id = ?")
                    ->execute([$status, (int)$staff['id'], $status, $ticketId]);
                $pdo->commit();
                $_SESSION['redline_support_flash'] = 'Support request #' . $ticketId . ' updated.';
                header('Location: list.php#customer-support', true, 303);
                exit;
            } catch (Throwable $ex) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('Customer Support: ' . $ex->getMessage());
                $supportError = 'Unable to save support request. Verify database migration and retry.';
            }
        }
    }
}
if (($staff['role_name'] ?? '') === 'Admin') {
    try {
        $supportTickets = $pdo->query("SELECT t.*, u.full_name AS customer_name, u.email, u.phone,
            staff.full_name AS handler_name FROM support_tickets t JOIN users u ON u.id=t.customer_id
            LEFT JOIN users staff ON staff.id=t.handled_by
            ORDER BY (t.status='open') DESC, (t.status='in_progress') DESC, t.updated_at DESC LIMIT 100")
            ->fetchAll(PDO::FETCH_ASSOC);
        if ($supportTickets) {
            $ids = array_map(static fn($t) => (int)$t['id'], $supportTickets);
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $history = $pdo->prepare("SELECT r.ticket_id, r.message, r.created_at, u.full_name
                FROM support_ticket_replies r JOIN users u ON u.id=r.author_id
                WHERE r.ticket_id IN ($placeholders) ORDER BY r.id DESC");
            $history->execute($ids);
            foreach ($history->fetchAll(PDO::FETCH_ASSOC) as $item) {
                $ticketKey = (int)$item['ticket_id'];
                if (count($supportReplies[$ticketKey] ?? []) < 20) $supportReplies[$ticketKey][] = $item;
            }
        }
    } catch (Throwable $ex) {
        error_log('Customer Support list: '.$ex->getMessage());
        $supportError = $supportError ?: 'Could not load support requests. Check the operations upgrade migration.';
    }
}
$showModal = false;
$values = ['full_name'=>'','phone'=>'','email'=>'','address'=>''];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_customer') {
    $showModal = true;
    foreach ($values as $k => $_) $values[$k] = trim((string)($_POST[$k] ?? ''));
    extract($values, EXTR_SKIP);
    $password = (string)($_POST['password'] ?? '');
    $confirmPassword = (string)($_POST['confirm_password'] ?? '');
    if (!csrf_valid($_POST['csrf'] ?? null)) $errorMsg = 'Session expired. Please reload the page.';
    elseif ($full_name === '' || $phone === '' || $email === '') $errorMsg = 'Name, phone and email are required.';
    elseif (mb_strlen($full_name)>100 || mb_strlen($phone)>30 || mb_strlen($email)>150 || mb_strlen($address)>255) $errorMsg = 'Input exceeds maximum length.';
    elseif (!preg_match('/^[0-9+().\s-]{7,30}$/', $phone)) $errorMsg = 'Invalid phone number.';
    elseif (!filter_var($email,FILTER_VALIDATE_EMAIL)) $errorMsg = 'Invalid email address.';
    elseif (strlen($password) < 8) $errorMsg = 'Password must be at least 8 characters.';
    elseif (strlen($password) > 1024) $errorMsg = 'Password is too long.';
    elseif ($password !== $confirmPassword) $errorMsg = 'Passwords do not match.';
    else {
        try {
            $stmt = $pdo->prepare("INSERT INTO users (full_name, phone, email, address, password_hash, role_id, is_customer, is_active, created_at) VALUES (?, ?, ?, ?, ?, (SELECT id FROM roles WHERE name IN ('Customer','Customers') LIMIT 1), 1, 1, NOW())");
            $stmt->execute([$full_name, $phone, strtolower($email), $address, password_hash($password, PASSWORD_DEFAULT)]);
            $noticeMsg = 'Customer <strong>'.htmlspecialchars($full_name,ENT_QUOTES,'UTF-8').'</strong> added successfully!';
            $showModal = false;
        } catch (PDOException $e) {
            error_log('Customer insert: '.$e->getMessage());
            $errorMsg = 'Could not save customer. Please check the data and try again.';
        }
    }
}
// The vehicle form now lives on this page. Keep the same CSRF/transaction/photo sync.
$showVehicleModal = false;
$oldVehicle = [];
$focusCustomerId = filter_input(INPUT_GET, 'customer_id', FILTER_VALIDATE_INT) ?: 0;
if (isset($_SESSION['redline_vehicle_created'])) {
    $noticeMsg = 'Vehicle <strong>' . htmlspecialchars((string)$_SESSION['redline_vehicle_created'], ENT_QUOTES, 'UTF-8') . '</strong> added successfully!';
    unset($_SESSION['redline_vehicle_created']);
}
// --- 1. ADD VEHICLE: validate input and save atomically ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_vehicle') {
    $showVehicleModal = true;
    foreach (['customer_id', 'license_plate', 'brand', 'model', 'year', 'engine_type', 'vin', 'battery_kwh'] as $key) {
        $oldVehicle[$key] = trim((string)($_POST[$key] ?? ''));
    }
    $customerId = (int)$oldVehicle['customer_id'];
    $plate = strtoupper(preg_replace('/\s+/u', '', $oldVehicle['license_plate']));
    $brand = $oldVehicle['brand'];
    $model = $oldVehicle['model'];
    $yearRaw = $oldVehicle['year'];
    $vehicleYear = $yearRaw === '' ? null : (int)$yearRaw;
    $engineType = $oldVehicle['engine_type'];
    $vin = strtoupper(preg_replace('/\s+/u', '', $oldVehicle['vin']));
    $batteryRaw = $oldVehicle['battery_kwh'];
    $batteryKwh = $batteryRaw === '' ? 0.0 : (float)$batteryRaw;

    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $errorMsg = 'Your session has expired. Please refresh the page.';
    } elseif ($customerId <= 0 || $plate === '') {
        $errorMsg = 'Select the vehicle owner and enter a license plate.';
    } elseif (!in_array($engineType, ['gasoline', 'diesel', 'hybrid', 'ev'], true)) {
        $errorMsg = 'Invalid engine type.';
    } elseif (mb_strlen($plate) > 20 || mb_strlen($brand) > 100 || mb_strlen($model) > 100 || mb_strlen($vin) > 17) {
        $errorMsg = 'License plate, vehicle make, model, or VIN exceeds the maximum length.';
    } elseif (!preg_match('/^[A-Z0-9.-]+$/', $plate)) {
        $errorMsg = 'License plates may contain only letters, numbers, periods, and hyphens.';
    } elseif ($yearRaw !== '' && (!ctype_digit($yearRaw) || $vehicleYear < 1886 || $vehicleYear > (int)date('Y') + 1)) {
        $errorMsg = 'Invalid model year.';
    } elseif ($vin !== '' && !preg_match('/^[A-HJ-NPR-Z0-9]{17}$/', $vin)) {
        $errorMsg = 'VIN must contain 17 valid characters (excluding I, O, Q), or be left blank.';
    } elseif ($batteryRaw !== '' && (!is_numeric($batteryRaw) || $batteryKwh < 0 || $batteryKwh > 1000)) {
        $errorMsg = 'Invalid battery capacity.';
    } else {
        $savedVehiclePhoto = null;
        try {
            // Save the optional image before the transaction; remove it if DB save fails.
            $savedVehiclePhoto = rl_save_staff_vehicle_photo($_FILES['vehicle_photo'] ?? []);
            $pdo->beginTransaction();
            $checkCustomer = $pdo->prepare('SELECT id FROM users WHERE id = ? AND is_customer = 1 AND is_active = 1 LIMIT 1');
            $checkCustomer->execute([$customerId]);
            if (!$checkCustomer->fetchColumn()) {
                throw new RuntimeException('The selected customer does not exist.');
            }
            $checkPlate = $pdo->prepare('SELECT id FROM vehicles WHERE license_plate = ? LIMIT 1');
            $checkPlate->execute([$plate]);
            if ($checkPlate->fetchColumn()) {
                throw new RuntimeException('This license plate is already registered.');
            }
            $insert = $pdo->prepare('INSERT INTO vehicles (customer_id, license_plate, brand, model, year, engine_type, is_ev, vin, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())');
            $insert->execute([$customerId, $plate, $brand, $model, $vehicleYear, $engineType, $engineType === 'ev' ? 1 : 0, $vin]);
            $vehicleId = (int)$pdo->lastInsertId();
            if (in_array($engineType, ['hybrid', 'ev'], true) && $batteryKwh > 0) {
                $pdo->prepare('INSERT INTO vehicle_ev_details (vehicle_id, battery_capacity) VALUES (?, ?)')
                    ->execute([$vehicleId, $batteryKwh]);
            }
            $portalVehicleId = rl_link_workshop_vehicle($pdo, $vehicleId);
            if ($savedVehiclePhoto !== null) {
                $pdo->prepare('UPDATE portal_vehicles SET photo = ?, updated_at = NOW() WHERE id = ? AND user_id = ?')
                    ->execute([$savedVehiclePhoto, $portalVehicleId, $customerId]);
            }
            $pdo->commit();
            // POST/Redirect/GET: refresh cannot register the same vehicle again.
            $_SESSION['redline_vehicle_created'] = $plate;
            header('Location: list.php?customer_id=' . $customerId . '#customer-' . $customerId, true, 303);
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if ($savedVehiclePhoto !== null) {
                @unlink(dirname(__DIR__, 2) . '/' . $savedVehiclePhoto);
            }
            if ($e instanceof RuntimeException) {
                $errorMsg = $e->getMessage();
            } elseif ($e instanceof PDOException && $e->getCode() === '23000') {
                $errorMsg = 'Vehicle or license plate already exists, or the supplied data is invalid.';
            } else {
                error_log('Vehicle creation failed: ' . $e->getMessage());
                $errorMsg = 'Unable to save the vehicle. Please try again.';
            }
        }
    }
}


$customers = [];
try {
    $sql = "SELECT c.*,
      (SELECT COUNT(*) FROM vehicles v WHERE v.customer_id=c.id) total_vehicles,
      (SELECT COUNT(*) FROM repair_orders ro WHERE ro.customer_id=c.id OR EXISTS
        (SELECT 1 FROM vehicles v WHERE v.id=ro.vehicle_id AND v.customer_id=c.id)) total_orders,
      (SELECT COUNT(*) FROM repair_orders ro WHERE ro.status NOT IN ('completed','delivered')
        AND (ro.customer_id=c.id OR EXISTS
        (SELECT 1 FROM vehicles v WHERE v.id=ro.vehicle_id AND v.customer_id=c.id))) active_orders,
      (SELECT COALESCE(SUM(i.grand_total),0) FROM invoices i
        JOIN repair_orders ro ON ro.id=i.order_id WHERE i.payment_status='paid'
        AND (ro.customer_id=c.id OR EXISTS
        (SELECT 1 FROM vehicles v WHERE v.id=ro.vehicle_id AND v.customer_id=c.id))) total_spent
      FROM users c WHERE c.is_customer = 1 ORDER BY c.id DESC";
    $customers=$pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Customers list: '.$e->getMessage());
    $errorMsg = $errorMsg ?: 'Could not load customer list.';
}


// Customer dropdowns contain both workshop and customer-portal registrations.
// A linked portal row is not a second vehicle; matching owner/plate is also deduplicated.
$vehiclesByCustomer = [];
$registeredVehicleCount = 0;
$customersList = [];
$vehicleLoadError = '';
foreach ($customers as $c) {
    if ((int)($c['is_active'] ?? 0) === 1) {
        $customersList[] = ['id' => (int)$c['id'], 'full_name' => $c['full_name'], 'phone' => $c['phone'] ?? ''];
    }
}
try {
    $garageVehicles = $pdo->query("SELECT v.*, ev.battery_capacity,
         (SELECT COUNT(*) FROM repair_orders ro WHERE ro.vehicle_id = v.id) AS total_repairs
         FROM vehicles v LEFT JOIN vehicle_ev_details ev ON ev.vehicle_id = v.id
         ORDER BY v.id DESC")->fetchAll(PDO::FETCH_ASSOC);
    $portalVehicles = $pdo->query("SELECT pv.* FROM portal_vehicles pv ORDER BY pv.id DESC")
        ->fetchAll(PDO::FETCH_ASSOC);
    $seenVehicleKeys = [];
    $knownWorkshopIds = [];
    $makePlateKey = static function (int $owner, string $plate): string {
        $normalized = preg_replace('/[^A-Z0-9]/', '', strtoupper($plate));
        return $normalized !== '' ? $owner . ':' . $normalized : '';
    };
    foreach ($garageVehicles as $v) {
        $owner = (int)$v['customer_id'];
        $knownWorkshopIds[(int)$v['id']] = true;
        $key = $makePlateKey($owner, (string)$v['license_plate']);
        if ($key !== '') $seenVehicleKeys[$key] = true;
        $vehiclesByCustomer[$owner][] = [
            'plate' => (string)$v['license_plate'], 'make' => (string)($v['brand'] ?? ''),
            'model' => (string)($v['model'] ?? ''), 'engine_type' => (string)($v['engine_type'] ?? 'gasoline'),
            'battery_capacity' => $v['battery_capacity'] ?? null,
            'year' => $v['year'] ?? null, 'vin' => ($v['vin'] ?: ($v['vin_number'] ?? '')),
            'total_repairs' => (int)$v['total_repairs'], 'source' => 'workshop',
        ];
    }
    foreach ($portalVehicles as $v) {
        $owner = (int)$v['user_id'];
        $linked = (int)($v['workshop_vehicle_id'] ?? 0);
        $key = $makePlateKey($owner, (string)($v['plate'] ?? ''));
        if (($linked > 0 && isset($knownWorkshopIds[$linked])) || ($key !== '' && isset($seenVehicleKeys[$key]))) {
            continue;
        }
        if ($key !== '') $seenVehicleKeys[$key] = true;
        $vehiclesByCustomer[$owner][] = [
            'plate' => (string)($v['plate'] ?? ''), 'make' => (string)($v['make'] ?? ''),
            'model' => (string)($v['model'] ?? ''), 'engine_type' => (string)($v['engine_type'] ?? 'gasoline'),
            'battery_capacity' => $v['battery_capacity'] ?? null,
            'year' => $v['year'] ?? null, 'vin' => $v['vin'] ?? '',
            'total_repairs' => 0, 'source' => 'portal',
        ];
    }
    foreach ($customers as &$c) {
        $c['total_vehicles'] = count($vehiclesByCustomer[(int)$c['id']] ?? []);
        $registeredVehicleCount += $c['total_vehicles'];
    }
    unset($c);
} catch (Throwable $e) {
    error_log('Customers vehicles dropdown: ' . $e->getMessage());
    $vehicleLoadError = 'Could not load vehicle records. Please check the database connection.';
    // Preserve original customer stats if the vehicle query is unavailable.
}

$initials = 'AD';
if (!empty($userName)) {
    $words = preg_split('/\s+/', trim($userName));
    $lastTwo = array_slice($words, -2);
    $initials = '';
    foreach ($lastTwo as $w) { 
        $initials .= mb_strtoupper(mb_substr($w, 0, 1)); 
    }
}

/* ---------------------------------------------------------------- sidebar nav */
$nav_main = [
    ['../index.php', 'Overview',      '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>', false],

    ['../technicians/assignments.php', 'Technicians', '<circle cx="12" cy="7" r="4"/><path d="M4 21v-2a8 8 0 0116 0v2"/>', false],
    ['list.php',                         'Customers',     '<path d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>', true],
    ['../appointments/list.php',                      'Appointments',  '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>', false],
    ['../invoices/list.php',                          'Invoices',      '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>', false],
    ['../products/list.php',                         'Inventory',     '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/>', false],
];
$nav_system = [
    ['../technician_management.php', 'Shifts &amp; Payroll', '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 11h18"/>', false],
    ['../settings/general.php', 'Settings',        '<path d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><circle cx="12" cy="12" r="3"/>', false],
];
if ($userRole === 'Receptionist') {
    $nav_main = array_values(array_filter($nav_main, static fn(array $item): bool =>
        in_array($item[1], ['Customers', 'Appointments', 'Inventory'], true)));
    $nav_system = [];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Customers | REDLINE Motorsport ERP</title>

    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        ink:   '#0F2A44',
                        muted: '#5D7287',
                        red:   { DEFAULT: '#EF4057', hover: '#D92F46' },
                        steel: '#4A86B0',
                        navy:  '#0B2E4E'
                    },
                    fontFamily: {
                        display: ['"Barlow Condensed"', 'sans-serif'],
                        sans:    ['Barlow', 'sans-serif']
                    }
                }
            }
        }
    </script>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700&family=Barlow:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../../assets/css/customers.css?v=<?= substr(hash_file('sha256', __DIR__ . '/../../assets/css/customers.css'), 0, 12) ?>"></head>
<body class="text-white font-sans antialiased h-screen overflow-hidden flex selection:bg-red selection:text-white">

    <!-- MOBILE BACKDROP MENU OVERLAY -->
    <div id="mobile-sidebar-backdrop" onclick="toggleMobileSidebar()" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-40 hidden md:hidden"></div>

    <!-- SIDEBAR -->
    <aside id="main-sidebar" class="sidebar w-64 shrink-0 fixed md:relative z-50 h-[calc(100%-2rem)] my-4 ml-4 py-5 px-4 overflow-y-auto transform -translate-x-full md:translate-x-0 transition-transform duration-300 ease-in-out flex flex-col justify-between">
        <div class="space-y-7">
            <a href="../index.php" class="flex items-center gap-3 px-1 hover:opacity-90 transition">
                <span class="w-10 h-10 rounded-xl bg-red flex items-center justify-center shadow-lg shadow-red/30">
                    <svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94z"/></svg>
                </span>
                <span class="leading-none">
                    <span class="block font-display text-2xl font-bold tracking-wider text-white">REDLINE</span>
                    <span class="block text-[10px] tracking-[.2em] text-white/80 mt-1">GARA MANAGEMENT</span>
                </span>
            </a>

            <nav class="space-y-6">
                <div>
                    <div class="text-[11px] font-semibold tracking-[.18em] text-white/75 px-3 mb-2">WORKSPACE</div>
                    <div class="space-y-1">
                        <?php foreach ($nav_main as [$href, $label, $icon, $active]): ?>
                            <a href="<?php echo $href; ?>" class="nav-item<?php echo $active ? ' active' : ''; ?>"<?php echo $active ? ' aria-current="page"' : ''; ?>>
                                <svg class="w-[18px] h-[18px] shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><?php echo $icon; ?></svg>
                                <span class="flex-1"><?php echo $label; ?></span>
                                <?php if ($active): ?>
                                    <span class="bg-red/80 text-white text-[10px] font-bold px-2 py-0.5 rounded-full"><?= count($customers) ?></span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div>
                    <div class="text-[11px] font-semibold tracking-[.18em] text-white/75 px-3 mb-2">SYSTEM</div>
                    <div class="space-y-1">
                        <?php foreach ($nav_system as [$href, $label, $icon, $active]): ?>
                            <a href="<?php echo $href; ?>" class="nav-item">
                                <svg class="w-[18px] h-[18px] shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><?php echo $icon; ?></svg>
                                <?php echo $label; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>
            </nav>
        </div>

        <!-- User -->
        <div class="pt-4 mt-6 border-t border-white/10 flex items-center justify-between gap-2">
            <div class="flex items-center gap-3 min-w-0">
                <div class="w-9 h-9 rounded-full bg-white/15 border border-white/20 flex items-center justify-center font-semibold text-white text-xs shrink-0">
                    <?= htmlspecialchars($initials) ?>
                </div>
                <div class="min-w-0">
                    <div class="text-[13px] font-semibold text-white truncate"><?= htmlspecialchars($userName) ?></div>
                    <div class="text-[11px] text-white/80 truncate"><?= htmlspecialchars($userDisplayRole) ?></div>
                </div>
            </div>
            <a href="../../auth/logout.php" class="text-white/85 hover:text-white p-2 rounded-lg hover:bg-white/10 transition" title="Logout" aria-label="Logout">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>
            </a>
        </div>
    </aside>

    <!-- CONTENT -->
    <main class="flex-1 min-w-0 h-full overflow-y-auto p-5 lg:p-8 space-y-6">

        <!-- Search + Header top bar -->
        <div class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-3 w-full max-w-xl">
                <button onclick="toggleMobileSidebar()" class="md:hidden text-white hover:text-red p-2 glass-dark transition">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <div class="relative w-full">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-4 pointer-events-none text-white/60">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </span>
                    <input type="search" id="topbar-search" class="glass-dark w-full pl-11 pr-20 py-2.5 text-sm placeholder-white/55 focus:outline-none focus:border-white/50" placeholder="Search customer name, phone number… Ctrl K" autocomplete="off">
                    <kbd class="absolute inset-y-0 right-0 flex items-center pr-4 pointer-events-none text-[11px] text-white/50 font-sans">Ctrl K</kbd>
                </div>
            </div>
            <div class="flex flex-wrap gap-2 justify-end shrink-0">
                <button type="button" id="open-customer-modal" class="glass-dark px-3.5 py-2.5 text-xs font-bold uppercase hover:bg-white/20" aria-haspopup="dialog" aria-controls="customer-modal">+ Create Customer</button>
                <button type="button" id="open-vehicle-modal" class="bg-red hover:bg-red-hover text-white font-display text-base font-semibold uppercase tracking-wider px-5 py-2.5 rounded-xl shadow-lg shadow-red/30 transition" aria-haspopup="dialog" aria-controls="vehicle-modal">+ Register Vehicle</button>
            </div>
        </div>

        <!-- Page header -->
        <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
            <div>
                <p class="text-xs font-semibold tracking-[.18em] text-white/60 mb-1"><?php echo strtoupper(date('l')) . ' · ' . strtoupper(date('j F Y')); ?></p>
                <h1 class="font-display text-5xl font-bold tracking-tight text-white uppercase leading-none">Customers<span class="text-red">.</span></h1>
                <p class="text-sm text-white/70 mt-2">Manage customers and registered vehicles. Click a customer to view their vehicles without leaving this page.</p>
            </div>
            <div class="flex items-center gap-2 self-start sm:self-auto">
                <span class="glass-dark inline-flex items-center gap-2 px-3.5 py-2 text-xs font-semibold">
                    <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                    <?= count($customers) ?> total customers
                </span>
                <span class="glass-dark inline-flex items-center gap-2 px-3.5 py-2 text-xs font-semibold">
                    <?= (int)$registeredVehicleCount ?> registered vehicles
                </span>
                <?php if (($staff['role_name'] ?? '') === 'Admin'): ?>
                  <a href="#customer-support" class="glass-dark inline-flex items-center rounded-lg px-3 py-2 text-xs font-semibold text-white hover:bg-white/15">Customer Support (<?= count(array_filter($supportTickets, static fn($t) => ($t['status'] ?? '') !== 'resolved')) ?>)</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- NOTICE ALERT -->
        <?php if (!empty($noticeMsg)): ?>
            <div class="glass p-4 border-emerald-400/40 bg-emerald-500/10 text-emerald-200 text-xs font-semibold flex items-center justify-between shadow-lg">
                <div class="flex items-center gap-3">
                    <div class="w-7 h-7 rounded-full bg-emerald-400/20 border border-emerald-300/40 flex items-center justify-center shrink-0">
                        <svg class="w-4 h-4 text-emerald-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    </div>
                    <span><?= $noticeMsg ?></span>
                </div>
                <button onclick="this.parentElement.remove()" class="text-emerald-300 hover:text-white font-mono px-2 py-1 rounded bg-white/10 transition">&times;</button>
            </div>
        <?php endif; ?>

        <?php if ($errorMsg): ?>
          <div class="glass p-4 border-rose-400/40 bg-rose-500/10 text-rose-200 text-xs font-semibold" role="alert"><?= htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <!-- Filter the merged customer/vehicle list without changing pages. -->
        <div class="flex flex-wrap items-center gap-3 text-xs text-white/75">
            <label for="customer-engine-filter" class="label">Filter vehicles by engine</label>
            <select id="customer-engine-filter" class="glass-input px-3 py-2 text-sm">
                <option value="all">All engines</option>
                <option value="gasoline">Gasoline</option>
                <option value="diesel">Diesel</option>
                <option value="hybrid">Hybrid</option>
                <option value="ev">Electric (EV)</option>
            </select>
            <span>Choose a customer row to expand their garage.</span>
        </div>
        <?php if ($vehicleLoadError): ?>
          <div role="alert" class="glass p-3 text-rose-200 text-sm"><?= htmlspecialchars($vehicleLoadError, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <!-- CUSTOMERS TABLE -->
        <section class="glass overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-[13px]">
                    <thead>
                        <tr class="label border-b border-white/15 bg-white/5">
                            <th class="py-3.5 px-6 font-semibold">Full Name</th>
                            <th class="py-3.5 px-4 font-semibold">Phone Number</th>
                            <th class="py-3.5 px-4 font-semibold">Email &amp; Address</th>
                            <th class="py-3.5 px-4 font-semibold text-center">Total Vehicles</th>
                            <th class="py-3.5 px-4 font-semibold text-center">Repair Orders</th>
                            <th class="py-3.5 px-4 font-semibold text-center">Active repairs</th>
                            <th class="py-3.5 px-6 font-semibold text-right">Total Spent</th>
                        </tr>
                    </thead>
                    <tbody id="customers-table-body" class="divide-y divide-white/10">
                        <?php if (empty($customers)): ?>
                            <tr>
                                <td colspan="7" class="p-8 text-center text-slate-300 text-xs">No customer information found in the system.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($customers as $c): ?>
                                <tr id="customer-<?= (int)$c['id'] ?>" class="hover:bg-white/10 transition border-t border-white/15 customer-row" data-customer-id="<?= (int)$c['id'] ?>">
                                    <td class="py-3.5 px-6 font-semibold text-white customer-name">
                                        <button type="button" class="customer-row-trigger" data-customer-toggle aria-expanded="false" aria-controls="customer-vehicles-<?= (int)$c['id'] ?>" aria-label="Show registered vehicles for <?= htmlspecialchars($c['full_name'], ENT_QUOTES, 'UTF-8') ?>">
                                            <svg class="customer-row-chevron" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                                            <span><?= htmlspecialchars($c['full_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                        </button>
                                    </td>
                                    <td class="py-3.5 px-4 font-mono font-semibold text-red customer-phone">
                                        <?= htmlspecialchars((string)($c['phone'] ?? '—'), ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td class="py-3.5 px-4">
                                        <div class="font-mono text-white customer-email"><?= htmlspecialchars($c['email'] ?: '--') ?></div>
                                        <div class="text-[11px] text-slate-300 mt-0.5 customer-address"><?= htmlspecialchars($c['address'] ?: '--') ?></div>
                                    </td>
                                    <td class="py-3.5 px-4 text-center">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-mono font-bold bg-amber-400/20 text-amber-200 border border-amber-300/40">
                                            <?= $c['total_vehicles'] ?> veh
                                        </span>
                                    </td>
                                    <td class="py-3.5 px-4 text-center font-mono text-white font-semibold">
                                        <?= $c['total_orders'] ?> orders
                                    </td>
                                    <td class="py-3.5 px-4 text-center font-mono font-semibold text-amber-200"><?= (int)($c['active_orders'] ?? 0) ?> orders</td>
                                    <td class="py-3.5 px-6 text-right font-mono font-semibold text-emerald-300">
                                        <?= (float)$c['total_spent'] > 0 ? htmlspecialchars(money_short_fmt((float)$c['total_spent'])) : '&mdash;' ?>
                                    </td>
                                </tr>
                                <tr id="customer-vehicles-<?= (int)$c['id'] ?>" class="customer-vehicles-detail" hidden>
                                    <td colspan="7" class="p-0">
                                        <div class="customer-garage-panel">
                                            <div class="customer-garage-heading">
                                                <div>
                                                    <h3>Registered Vehicles <span class="text-red">.</span></h3>
                                                    <p><?= htmlspecialchars($c['full_name'], ENT_QUOTES, 'UTF-8') ?> · <?= (int)($c['total_vehicles'] ?? 0) ?> vehicle(s)</p>
                                                </div>
                                                <button type="button" class="customer-add-car" data-customer-register="<?= (int)$c['id'] ?>">+ Register vehicle for this customer</button>
                                            </div>
                                            <?php $customerVehicles = $vehiclesByCustomer[(int)$c['id']] ?? []; ?>
                                            <?php if (!$customerVehicles): ?>
                                                <p class="customer-no-vehicles">No registered vehicles for this customer yet.</p>
                                            <?php else: ?>
                                            <div class="customer-vehicle-table-scroll">
                                              <table class="customer-vehicle-table">
                                                <thead><tr>
                                                    <th>License Plate</th><th>Make &amp; Model</th><th>Engine Type</th><th>Vehicle Owner</th><th>VIN Number</th><th>Repair History</th>
                                                </tr></thead>
                                                <tbody>
                                                <?php foreach ($customerVehicles as $v): ?>
                                                  <tr class="customer-vehicle-entry" data-engine="<?= htmlspecialchars(strtolower((string)$v['engine_type']), ENT_QUOTES, 'UTF-8') ?>">
                                                    <td><span class="car-plate"><?= htmlspecialchars($v['plate'] ?: '—', ENT_QUOTES, 'UTF-8') ?></span></td>
                                                    <td class="font-semibold text-white">
                                                        <?= htmlspecialchars(trim(($v['make'] ?? '') . ' ' . ($v['model'] ?? '')) ?: '—', ENT_QUOTES, 'UTF-8') ?>
                                                        <?php if (!empty($v['year'])): ?><div class="text-[11px] text-white/60"><?= (int)$v['year'] ?></div><?php endif; ?>
                                                    </td>
                                                    <td>
                                                      <?php $engine = strtolower((string)($v['engine_type'] ?? 'gasoline')); ?>
                                                      <?php if ($engine === 'ev'): ?>
                                                        <span class="car-engine car-engine-ev">⚡ EV<?= $v['battery_capacity'] !== null && (float)$v['battery_capacity'] > 0 ? ' · ' . htmlspecialchars((string)(float)$v['battery_capacity'], ENT_QUOTES, 'UTF-8') . ' kWh' : '' ?></span>
                                                      <?php elseif ($engine === 'hybrid'): ?>
                                                        <span class="car-engine car-engine-hybrid">🔋 Hybrid<?= $v['battery_capacity'] !== null && (float)$v['battery_capacity'] > 0 ? ' · ' . htmlspecialchars((string)(float)$v['battery_capacity'], ENT_QUOTES, 'UTF-8') . ' kWh' : '' ?></span>
                                                      <?php else: ?>
                                                        <span class="car-engine">⛽ <?= $engine === 'diesel' ? 'Diesel' : 'Gasoline' ?></span>
                                                      <?php endif; ?>
                                                    </td>
                                                    <td>
                                                        <?= htmlspecialchars($c['full_name'], ENT_QUOTES, 'UTF-8') ?>
                                                        <div class="text-[11px] text-white/60 font-mono"><?= htmlspecialchars((string)($c['phone'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></div>
                                                    </td>
                                                    <td class="font-mono text-[11px] text-white/75"><?= htmlspecialchars((string)($v['vin'] ?: '—'), ENT_QUOTES, 'UTF-8') ?></td>
                                                    <td class="font-mono font-semibold text-emerald-300"><?= (int)$v['total_repairs'] ?> visits<?= $v['source'] === 'portal' ? ' <span class="text-white/50 text-[10px] block">Portal registered</span>' : '' ?></td>
                                                  </tr>
                                                <?php endforeach; ?>
                                                </tbody>
                                              </table>
                                            </div>
                                            <p class="customer-car-no-filter" hidden>No vehicles match this filter.</p>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <div class="customer-pagination flex flex-wrap items-center justify-between gap-3 text-xs text-white/80">
          <span id="customer-results" aria-live="polite"></span>
          <div class="flex items-center gap-2">
            <label for="customer-page-size">Rows:</label><select id="customer-page-size" class="glass-dark px-2 py-1"><option>10</option><option>25</option><option>50</option></select>
            <button type="button" id="customer-prev" class="glass-dark px-3 py-1">Prev</button>
            <span id="customer-page"></span>
            <button type="button" id="customer-next" class="glass-dark px-3 py-1">Next</button>
          </div>
        </div>

        <?php if (($staff['role_name'] ?? '') === 'Admin'): ?>
        <section id="customer-support" class="glass p-5 lg:p-6 space-y-4 scroll-mt-5" aria-labelledby="support-heading">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-xs uppercase tracking-[.18em] text-white/60">Customer Care</p>
                    <h2 id="support-heading" class="font-display text-3xl uppercase font-bold">Customer Support<span class="text-red">.</span></h2>
                    <p class="text-sm text-white/70">Manage customer requests without opening another Admin page. Replies are shown in the customer portal.</p>
                </div>
                <span class="glass-dark px-3 py-2 text-sm font-semibold"><?= count(array_filter($supportTickets, static fn($ticket) => ($ticket['status'] ?? '') !== 'resolved')) ?> open / in progress</span>
            </div>
            <?php if ($supportFlash): ?><p class="rounded-xl border border-emerald-300/40 bg-emerald-500/10 p-3 text-emerald-200 text-sm" role="status"><?= htmlspecialchars($supportFlash, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>
            <?php if ($supportError): ?><p class="rounded-xl border border-rose-300/40 bg-rose-500/10 p-3 text-rose-200 text-sm" role="alert"><?= htmlspecialchars($supportError, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p><?php endif; ?>
            <?php foreach ($supportTickets as $ticket): ?>
                <details class="rounded-xl border border-white/20 bg-white/5 overflow-hidden group">
                    <summary class="cursor-pointer px-4 py-3 flex flex-wrap items-center justify-between gap-2 hover:bg-white/10">
                        <span class="text-sm font-semibold"><span class="text-red">SUP-<?= (int)$ticket['id'] ?></span> · <?= htmlspecialchars($ticket['customer_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
                        <span class="text-white/60 font-normal">· <?= htmlspecialchars(str_replace('_', ' ', (string)$ticket['topic']), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span></span>
                        <span class="text-xs text-white/80"><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string)$ticket['status'])), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> · <?= htmlspecialchars((string)$ticket['created_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></span>
                    </summary>
                    <div class="border-t border-white/15 p-4 space-y-3">
                        <p class="text-xs text-white/70"><?= htmlspecialchars((string)$ticket['email'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> · <?= htmlspecialchars((string)($ticket['phone'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> · Handler: <?= htmlspecialchars((string)($ticket['handler_name'] ?: 'Unassigned'), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                        <p class="text-sm text-white whitespace-pre-wrap break-words"><?= htmlspecialchars((string)$ticket['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                        <?php foreach (($supportReplies[(int)$ticket['id']] ?? []) as $reply): ?>
                            <div class="rounded-lg bg-white/5 border border-white/10 px-3 py-2 text-sm">
                                <p class="text-xs text-white/60 mb-1"><?= htmlspecialchars($reply['full_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?> · <?= htmlspecialchars((string)$reply['created_at'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                                <p class="whitespace-pre-wrap break-words"><?= htmlspecialchars($reply['message'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></p>
                            </div>
                        <?php endforeach; ?>
                        <form method="post" action="list.php#customer-support" class="space-y-3">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">
                            <input type="hidden" name="action" value="update_ticket">
                            <input type="hidden" name="ticket_id" value="<?= (int)$ticket['id'] ?>">
                            <label class="block text-xs font-semibold text-white/80" for="support-reply-<?= (int)$ticket['id'] ?>">Reply (optional)</label>
                            <textarea id="support-reply-<?= (int)$ticket['id'] ?>" name="reply" rows="3" minlength="2" maxlength="3000" class="w-full rounded-lg bg-slate-900/80 border border-white/30 px-3 py-2 text-white text-sm" placeholder="Reply to this customer in their portal"></textarea>
                            <div class="flex flex-wrap items-center gap-3">
                                <label class="text-xs" for="support-status-<?= (int)$ticket['id'] ?>">Status</label>
                                <select id="support-status-<?= (int)$ticket['id'] ?>" name="status" class="rounded-lg bg-slate-900 border border-white/30 px-3 py-2 text-white text-sm">
                                    <?php foreach (['open'=>'Open','in_progress'=>'In progress','resolved'=>'Resolved'] as $value=>$label): ?>
                                      <option value="<?= $value ?>" <?= $ticket['status'] === $value ? 'selected' : '' ?>><?= $label ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="bg-red text-white font-bold text-sm rounded-lg px-4 py-2 hover:opacity-90">Save &amp; reply</button>
                            </div>
                        </form>
                    </div>
                </details>
            <?php endforeach; ?>
            <?php if (!$supportTickets && !$supportError): ?><p class="text-sm text-white/70">No support requests yet.</p><?php endif; ?>
        </section>
        <?php endif; ?>

    </main>

    <!-- Register vehicle inside Customers; existing validated photo upload and sync are preserved. -->
        <div id="vehicle-modal" class="vehicle-modal" role="dialog" aria-modal="true" aria-labelledby="vehicle-modal-title" hidden>
            <div class="vehicle-modal-panel" tabindex="-1">
                <div class="vehicle-modal-header">
                    <div>
                        <h2 id="vehicle-modal-title" class="vehicle-modal-title">Register New <span class="text-red">Vehicle.</span></h2>
                        <p class="vehicle-modal-caption">Add a vehicle to the REDLINE garage registry.</p>
                    </div>
                    <button type="button" class="vehicle-modal-close" data-close-vehicle aria-label="Close form">&times;</button>
                </div>
                <form action="list.php" method="POST" enctype="multipart/form-data" class="vehicle-modal-form" id="vehicle-create-form">
                    <input type="hidden" name="action" value="add_vehicle">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <div>
                        <label class="label" for="new-customer">Vehicle Owner / Customer (*)</label>
                        <select id="new-customer" name="customer_id" required class="glass-input">
                            <option value="">-- Select customer --</option>
                            <?php foreach ($customersList as $cust): ?>
                                <option value="<?= (int)$cust['id'] ?>" <?= (string)($oldVehicle['customer_id'] ?? '') === (string)$cust['id'] ? 'selected' : '' ?>><?= htmlspecialchars($cust['full_name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($cust['phone'], ENT_QUOTES, 'UTF-8') ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="label" for="new-plate">License Plate (*)</label>
                        <input id="new-plate" type="text" name="license_plate" required maxlength="20" placeholder="65A-123.45" value="<?= htmlspecialchars($oldVehicle['license_plate'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="glass-input font-mono uppercase" autocomplete="off">
                    </div>
                    <div class="field-pair">
                        <div>
                            <label class="label" for="new-brand">Make</label>
                            <input id="new-brand" name="brand" maxlength="100" class="glass-input" placeholder="Toyota" value="<?= htmlspecialchars($oldVehicle['brand'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                        <div>
                            <label class="label" for="new-model">Model</label>
                            <input id="new-model" name="model" maxlength="100" class="glass-input" placeholder="Camry" value="<?= htmlspecialchars($oldVehicle['model'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        </div>
                    </div>
                    <div>
                        <label class="label" for="engine_type_select">Engine Type</label>
                        <select name="engine_type" id="engine_type_select" class="glass-input">
                            <?php foreach (['gasoline' => 'Gasoline', 'diesel' => 'Diesel', 'hybrid' => 'Hybrid', 'ev' => 'Electric (EV)'] as $value => $label): ?>
                                <option value="<?= $value ?>" <?= ($oldVehicle['engine_type'] ?? 'gasoline') === $value ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field-pair">
                        <div>
                            <label class="label" for="new-year">Year</label>
                            <input id="new-year" type="number" name="year" min="1886" max="<?= (int)date('Y') + 1 ?>" placeholder="2024" value="<?= htmlspecialchars($oldVehicle['year'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="glass-input">
                        </div>
                        <div>
                            <label class="label" for="new-vin">VIN Number</label>
                            <input id="new-vin" name="vin" maxlength="17" placeholder="17-character VIN" value="<?= htmlspecialchars($oldVehicle['vin'] ?? '', ENT_QUOTES, 'UTF-8') ?>" class="glass-input font-mono uppercase">
                        </div>
                    </div>
                    <div id="ev_battery_box" class="vehicle-battery" hidden>
                        <label class="label" for="new-battery">Battery Capacity (kWh)</label>
                        <input id="new-battery" type="number" name="battery_kwh" min="0" max="1000" step="0.1" value="<?= htmlspecialchars($oldVehicle['battery_kwh'] ?? '0', ENT_QUOTES, 'UTF-8') ?>" class="glass-input">
                    </div>
                    <div>
                        <label class="label" for="admin-vehicle-photo">Vehicle photo (optional, JPG/PNG/WebP, maximum 5 MB)</label>
                        <input id="admin-vehicle-photo" type="file" name="vehicle_photo" accept="image/jpeg,image/png,image/webp" class="glass-input">
                    </div>
                    <div class="vehicle-modal-footer">
                        <button type="button" data-close-vehicle class="glass-dark px-4 py-2.5 text-sm font-semibold hover:bg-white/20">Cancel</button>
                        <button type="submit" class="bg-red hover:bg-red-hover text-white font-display text-sm uppercase font-semibold py-2.5 px-6 rounded-xl shadow-lg shadow-red/30 transition">+ Save Vehicle</button>
                    </div>
                </form>
            </div>
        </div>


    <!-- CREATE CUSTOMER MODAL -->
    <div id="customer-modal" class="customer-modal" role="dialog" aria-modal="true" aria-labelledby="customer-title" <?= $showModal ? '' : 'hidden' ?>>
        <div class="customer-modal-panel" tabindex="-1">
            <div class="customer-modal-header">
                <div>
                    <h2 id="customer-title" class="customer-modal-title">Register New <span class="text-red">Customer.</span></h2>
                    <p class="customer-modal-caption">Add a customer to the REDLINE garage registry.</p>
                </div>
                <button type="button" data-modal-close aria-label="Close form" class="customer-modal-close">&times;</button>
            </div>
            <form action="list.php" method="POST" class="customer-modal-form" id="customer-create-form">
                <input type="hidden" name="action" value="add_customer">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <div>
                    <label class="label" for="customer-full_name">Full Name (*)</label>
                    <input id="customer-full_name" type="text" name="full_name" maxlength="100" autocomplete="name" placeholder="e.g. John Doe" required value="<?= htmlspecialchars($values['full_name'], ENT_QUOTES, 'UTF-8') ?>" class="glass-input">
                </div>
                <div>
                    <label class="label" for="customer-phone">Phone Number (*)</label>
                    <input id="customer-phone" type="tel" name="phone" maxlength="30" autocomplete="tel" placeholder="e.g. 0901234567" required value="<?= htmlspecialchars($values['phone'], ENT_QUOTES, 'UTF-8') ?>" class="glass-input font-mono">
                </div>
                <div>
                    <label class="label" for="customer-email">Email (*)</label>
                    <input id="customer-email" type="email" name="email" maxlength="150" autocomplete="email" placeholder="customer@example.com" required value="<?= htmlspecialchars($values['email'], ENT_QUOTES, 'UTF-8') ?>" class="glass-input">
                </div>
                <div>
                    <label class="label" for="customer-address">Address</label>
                    <input id="customer-address" type="text" name="address" maxlength="255" autocomplete="street-address" placeholder="Street, district, city" value="<?= htmlspecialchars($values['address'], ENT_QUOTES, 'UTF-8') ?>" class="glass-input">
                </div>
                <div>
                    <label class="label" for="customer-password">Password (*)</label>
                    <input id="customer-password" type="password" name="password" minlength="8" maxlength="1024" autocomplete="new-password" placeholder="At least 8 characters" required class="glass-input">
                </div>
                <div>
                    <label class="label" for="customer-confirm-password">Confirm Password (*)</label>
                    <input id="customer-confirm-password" type="password" name="confirm_password" minlength="8" maxlength="1024" autocomplete="new-password" placeholder="Re-enter password" required class="glass-input">
                </div>
                <p class="text-xs text-white/65">Customers sign in with their email address and password. Passwords are stored securely as hashes.</p>
                <div class="customer-modal-footer">
                    <button type="button" data-modal-close class="glass-dark px-4 py-2.5 text-sm font-semibold hover:bg-white/20">Cancel</button>
                    <button type="submit" class="bg-red hover:bg-red-hover text-white font-display text-sm uppercase font-semibold py-2.5 px-6 rounded-xl shadow-lg shadow-red/30 transition">+ Save Customer</button>
                </div>
            </form>
        </div>
    </div>

    <!-- JAVASCRIPT LOGIC -->
    <script>
      function toggleMobileSidebar() {
        document.getElementById('main-sidebar').classList.toggle('-translate-x-full');
        document.getElementById('mobile-sidebar-backdrop').classList.toggle('hidden');
      }
      document.addEventListener('DOMContentLoaded', () => {
        const modal = document.getElementById('customer-modal');
        const opener = document.getElementById('open-customer-modal');
        let previousFocus = document.activeElement;
        function openModal() {
          previousFocus = document.activeElement;
          modal.hidden = false;
          if (opener) opener.setAttribute('aria-expanded', 'true');
          document.body.classList.add('customer-modal-open');
          document.getElementById('customer-full_name').focus();
        }
        function closeModal() {
          modal.hidden = true;
          if (opener) opener.setAttribute('aria-expanded', 'false');
          document.body.classList.remove('customer-modal-open');
          if (previousFocus && typeof previousFocus.focus === 'function') previousFocus.focus();
        }
        if (opener) opener.addEventListener('click', openModal);
        modal.querySelectorAll('[data-modal-close]').forEach(el => el.addEventListener('click',closeModal));
        modal.addEventListener('click',e => {if(e.target===modal) closeModal()});
        document.addEventListener('keydown',e => {
          if(e.key==='Escape' && !modal.hidden) {e.preventDefault();closeModal()}
          if(e.key==='Tab' && !modal.hidden) {
            const items=[...modal.querySelectorAll('button,input:not([type="hidden"]),select,textarea')];
            const first=items[0],last=items[items.length-1];
            if(e.shiftKey && document.activeElement===first) {e.preventDefault();last.focus()}
            if(!e.shiftKey && document.activeElement===last) {e.preventDefault();first.focus()}
          }
        });
        if(!modal.hidden) {document.body.classList.add('customer-modal-open');document.getElementById('customer-full_name').focus()}
        const search=document.getElementById('topbar-search');
        const engineFilter=document.getElementById('customer-engine-filter');
        const sizeEl=document.getElementById('customer-page-size');
        const prev=document.getElementById('customer-prev');
        const next=document.getElementById('customer-next');
        const groups=[...document.querySelectorAll('.customer-row')].map(row=>({
          row, id:row.dataset.customerId,
          button:row.querySelector('[data-customer-toggle]'),
          detail:document.getElementById('customer-vehicles-'+row.dataset.customerId),
          entries:[...document.querySelectorAll('#customer-vehicles-'+row.dataset.customerId+' .customer-vehicle-entry')],
          personText:row.textContent,
        }));
        const normalize=t=>(t||'').toLocaleLowerCase('vi-VN').normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/đ/g,'d');
        let page=1, openedId=null;
        let filteredGroups=[];
        function setOpened(id) {openedId=id;render();}
        groups.forEach(g=>{
          g.button.addEventListener('click',()=>setOpened(openedId===g.id?null:g.id));
          // Make the entire table row respond to a pointer click; keyboard uses the real button.
          g.row.addEventListener('click',e=>{
            if(e.target.closest('button,a,input,select'))return;
            setOpened(openedId===g.id?null:g.id);
          });
        });
        function render(){
          const q=normalize(search.value.trim());
          const engine=engineFilter.value;
          filteredGroups=[];
          for (const g of groups){
            const matchPerson=!q||normalize(g.personText).includes(q);
            let carsShown=0, carQueryMatch=false;
            for (const car of g.entries){
              const hasEngine=engine==='all'||car.dataset.engine===engine;
              const hasQuery=!q||normalize(car.textContent).includes(q);
              const visible=hasEngine && (matchPerson||hasQuery);
              car.style.display=visible?'':'none';
              if(visible)carsShown++;
              if(q && hasEngine && hasQuery)carQueryMatch=true;
            }
            const matched=(engine==='all'||carsShown>0) && (matchPerson||carQueryMatch);
            if(matched)filteredGroups.push(g);
            const noCars=g.detail.querySelector('.customer-car-no-filter');
            if(noCars)noCars.hidden=carsShown!==0;
            g.row.style.display='none';
            g.detail.style.display='none';
          }
          const size=Number(sizeEl.value)||10;
          const pages=Math.max(1,Math.ceil(filteredGroups.length/size));
          page=Math.min(Math.max(1,page),pages);
          for(const g of filteredGroups.slice((page-1)*size,page*size)){
            g.row.style.display='';
            const open=openedId===g.id;
            g.detail.style.display=open?'':'none';
            g.detail.hidden=!open;
            g.row.classList.toggle('customer-expanded',open);
            g.button.setAttribute('aria-expanded',String(open));
          }
          for(const g of groups){
            if(g.row.style.display==='none')g.button.setAttribute('aria-expanded','false');
          }
          document.getElementById('customer-results').textContent=`${filteredGroups.length} customers found`;
          document.getElementById('customer-page').textContent=`${page} / ${pages}`;
          prev.disabled=page<=1;next.disabled=page>=pages;
        }
        search.addEventListener('input',()=>{
          page=1;
          const q=normalize(search.value.trim());
          if(q){
            const found=groups.find(g=>!normalize(g.personText).includes(q)&&g.entries.some(car=>normalize(car.textContent).includes(q)));
            openedId=found?.id??null;
          }else{openedId=null;}
          render();
        });
        engineFilter.addEventListener('change',()=>{
          page=1;
          if(engineFilter.value!=='all'){
            const found=groups.find(g=>g.entries.some(car=>car.dataset.engine===engineFilter.value));
            openedId=found?.id??null;
          }else{openedId=null;}
          render();
        });
        sizeEl.addEventListener('change',()=>{page=1;render()});
        prev.addEventListener('click',()=>{page--;render()});
        next.addEventListener('click',()=>{page++;render()});
        // Existing bookmarked owners / after a new vehicle is registered.
        const focusId=<?= (int)$focusCustomerId ?>;
        if(focusId>0){
          const foundIndex=groups.findIndex(g=>Number(g.id)===focusId);
          if(foundIndex>=0){
            page=Math.floor(foundIndex/(Number(sizeEl.value)||10))+1;
            openedId=String(focusId);
          }
        }
        render();
        if(focusId>0)document.getElementById('customer-'+focusId)?.scrollIntoView({block:'center'});

        // Register directly from a customer dropdown and preselect the owner.
        const vehicleModal=document.getElementById('vehicle-modal');
        const vehicleOpener=document.getElementById('open-vehicle-modal');
        const vehicleOwner=document.getElementById('new-customer');
        const engineSelect=document.getElementById('engine_type_select');
        const batteryBox=document.getElementById('ev_battery_box');
        let vehicleLastFocus=null;
        const toggleBattery=()=>{batteryBox.hidden=!['ev','hybrid'].includes(engineSelect.value)};
        engineSelect.addEventListener('change',toggleBattery);
        toggleBattery();
        function openVehicle(owner){
          vehicleLastFocus=document.activeElement;
          if(owner!==undefined&&owner!==null) vehicleOwner.value=String(owner);
          vehicleModal.hidden=false;
          document.body.classList.add('vehicle-modal-open');
          vehicleOwner.focus();
        }
        function closeVehicle(){
          vehicleModal.hidden=true;
          document.body.classList.remove('vehicle-modal-open');
          (vehicleLastFocus||vehicleOpener)?.focus();
        }
        vehicleOpener.addEventListener('click',()=>openVehicle());
        document.querySelectorAll('[data-customer-register]').forEach(btn=>btn.addEventListener('click',()=>openVehicle(btn.dataset.customerRegister)));
        vehicleModal.querySelectorAll('[data-close-vehicle]').forEach(btn=>btn.addEventListener('click',closeVehicle));
        vehicleModal.addEventListener('click',e=>{if(e.target===vehicleModal)closeVehicle()});
        document.addEventListener('keydown',e=>{
          if(e.key==='Escape'&&!vehicleModal.hidden){e.preventDefault();closeVehicle()}
          if(e.key!=='Tab'||vehicleModal.hidden)return;
          const els=[...vehicleModal.querySelectorAll('button:not([disabled]),input:not([disabled]),select:not([disabled])')].filter(el=>el.offsetParent!==null);
          if(!els.length)return;
          if(e.shiftKey&&document.activeElement===els[0]){e.preventDefault();els.at(-1).focus()}
          else if(!e.shiftKey&&document.activeElement===els.at(-1)){e.preventDefault();els[0].focus()}
        });
        <?php if ($showVehicleModal): ?>openVehicle();<?php endif; ?>
        document.addEventListener('keydown',e=>{
          if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='k'){e.preventDefault();search.focus();search.select()}
        });
        render();
      });
    </script>
</body>
</html>

<?php
function money_short_fmt(float $v): string { return rl_usd_compact($v); }
?>
