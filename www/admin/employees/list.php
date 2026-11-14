<?php
// www/staff.php - Staff & Technicians Management REDLINE Motorsport ERP
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/auth.php';
if (function_exists('requireAuth')) {
    requireAuth();
}

$staff = function_exists('current_staff') ? current_staff() : null;
$role = $_SESSION['role'] ?? ($staff['role_name'] ?? '');
if (!$staff || $role !== 'Admin') {
    header('Location: ../../employees/index.php');
    exit;
}
$userRole = $_SESSION['role'] ?? ($staff['role_name'] ?? 'Administrator');
$userName = $_SESSION['full_name'] ?? ($staff['full_name'] ?? 'Administrator');
$userDisplayRole = $userRole === 'Admin' ? 'Administrator' : ($userRole ?: 'Staff');
$pdo = db();
$staffRolePlaceholders = implode(', ', array_fill(0, count(STAFF_PORTAL_ROLES), '?'));

$noticeMsg = '';
$errorMsg  = '';
$csrfToken = csrf_token();

// --- 1. ADD NEW STAFF ACCOUNT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_staff') {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $errorMsg = 'Your session has expired. Please reload the page and try again.';
    } else {
        $fullName = trim((string)($_POST['full_name'] ?? ''));
        $username = trim((string)($_POST['username'] ?? ''));
        $email    = strtolower(trim((string)($_POST['email'] ?? '')));
        $phone    = trim((string)($_POST['phone'] ?? ''));
        $roleName = trim((string)($_POST['role_name'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $allowedStaffRoles = ['Technician'];

        if ($fullName === '' || $username === '' || $email === '' || $phone === '' || $password === '') {
            $errorMsg = 'Please complete all required fields.';
        } elseif (mb_strlen($fullName) > 100 || mb_strlen($username) > 50 || mb_strlen($email) > 100 || mb_strlen($phone) > 15) {
            $errorMsg = 'One or more fields exceed the maximum allowed length.';
        } elseif (!preg_match('/^[A-Za-z0-9._-]+$/', $username)) {
            $errorMsg = 'Username may contain only letters, numbers, periods, underscores, and hyphens.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errorMsg = 'Please enter a valid login email address.';
        } elseif (strlen($password) < 8) {
            $errorMsg = 'The initial password must be at least 8 characters long.';
        } elseif (!in_array($roleName, $allowedStaffRoles, true)) {
            $errorMsg = 'Please select a valid staff role.';
        } else {
            try {
                $stmtRole = $pdo->prepare('SELECT id FROM roles WHERE name = :name LIMIT 1');
                $stmtRole->execute(['name' => $roleName]);
                $roleId = $stmtRole->fetchColumn();
                if (!$roleId || (int) $roleId === CUSTOMER_ROLE_ID) {
                    throw new RuntimeException('The selected role is not configured in the database.');
                }

                $stmtIns = $pdo->prepare(
                    'INSERT INTO users (role_id, username, password_hash, full_name, email, phone, is_active, is_customer)
                     VALUES (:role_id, :username, :password, :full_name, :email, :phone, 1, 0)'
                );
                $stmtIns->execute([
                    'role_id' => $roleId,
                    'username' => $username,
                    'password' => password_hash($password, PASSWORD_DEFAULT),
                    'full_name' => $fullName,
                    'email' => $email,
                    'phone' => $phone,
                ]);
                $noticeMsg = 'Staff account for <strong>' . htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8')
                    . '</strong> created successfully. Login username: <strong>'
                    . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . '</strong>.';
            } catch (PDOException $e) {
                if ($e->getCode() === '23000') {
                    $errorMsg = 'That username or email is already in use.';
                } else {
                    error_log('Staff account creation failed: ' . $e->getMessage());
                    $errorMsg = 'Could not create the staff account due to a database error. Please try again.';
                }
            } catch (RuntimeException $e) {
                error_log('Staff role configuration error: ' . $e->getMessage());
                $errorMsg = 'The selected staff role is not available. Please contact an administrator.';
            }
        }
    }
}

// --- 2. TOGGLE STAFF ACTIVE STATUS ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_status') {
    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $errorMsg = 'Your session has expired. Please reload the page and try again.';
    } else {
        $userId    = (int)($_POST['user_id'] ?? 0);
        $newStatus = (int)($_POST['new_status'] ?? -1);
        if ($userId > 0 && in_array($newStatus, [0, 1], true)) {
            $stmtToggle = $pdo->prepare("UPDATE users u JOIN roles r ON r.id = u.role_id
                SET u.is_active = ? WHERE u.id = ? AND u.role_id <> ? AND r.name IN ($staffRolePlaceholders)");
            $stmtToggle->execute([$newStatus, $userId, CUSTOMER_ROLE_ID, ...STAFF_PORTAL_ROLES]);
            $noticeMsg = 'Staff member status updated successfully!';
        } else {
            $errorMsg = 'Invalid staff status request.';
        }
    }
}

// --- 3. FETCH STAFF LIST & ASSIGNED REPAIR ORDERS STATS ---
$staffMembers = [];
try {
    $sql = "
        SELECT 
            u.id, u.full_name, u.username, u.email, u.phone, u.is_active,
            r.name AS final_role,
            COUNT(ro.id) AS total_assigned_orders,
            SUM(CASE WHEN ro.status NOT IN ('completed', 'delivered') THEN 1 ELSE 0 END) AS active_assigned_orders
        FROM users u
        JOIN roles r ON u.role_id = r.id
        LEFT JOIN repair_orders ro ON ro.assigned_to = u.id
        WHERE u.role_id <> ? AND r.name IN ($staffRolePlaceholders)
        GROUP BY u.id
        ORDER BY u.id DESC
    ";
    $stmtStaff = $pdo->prepare($sql);
    $stmtStaff->execute([CUSTOMER_ROLE_ID, ...STAFF_PORTAL_ROLES]);
    $staffMembers = $stmtStaff->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Staff list query failed: ' . $e->getMessage());
    $staffMembers = [];
    $errorMsg = $errorMsg ?: 'Could not load the staff list. Please try again later.';
}

// Avatar Initials
$initials = 'AD';
if (!empty($userName)) {
    $words = preg_split('/\s+/', trim($userName));
    $lastTwo = array_slice($words, -2);
    $initials = '';
    foreach ($lastTwo as $w) { $initials .= mb_strtoupper(mb_substr($w, 0, 1)); }
}

/* ---------------------------------------------------------------- sidebar nav */
$nav_main = [
    ['../index.php', 'Overview',      '<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>', false],
    ['../orders/list.php',                     'Repair orders', '<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>', false],
    ['../technicians/assignments.php', 'Technicians', '<circle cx="12" cy="7" r="4"/><path d="M4 21v-2a8 8 0 0116 0v2"/>', false],
    ['../customers/list.php',                         'Customers',     '<path d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>', false],
    ['../vehicles/list.php',                          'Vehicles',      '<path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/>', false],
    ['../appointments/list.php',                      'Appointments',  '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>', false],
    ['../invoices/list.php',                          'Invoices',      '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>', false],
    ['../products/list.php',                         'Inventory',     '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/>', false],
];
$nav_system = [
    ['list.php',    'Staff &amp; Team', '<path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>', true],
    ['../settings/general.php', 'Settings',        '<path d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><circle cx="12" cy="12" r="3"/>', false],
];
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Staff &amp; Technicians | REDLINE Motorsport ERP</title>

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

    <link rel="stylesheet" href="../../assets/css/staff.css?v=<?= substr(hash_file('sha256', __DIR__ . '/../../assets/css/staff.css'), 0, 12) ?>"></head>
<body class="text-white font-sans antialiased h-screen overflow-hidden flex selection:bg-red selection:text-white">
<a href="../technician_management.php" style="position:fixed;right:18px;bottom:18px;z-index:9999;padding:13px 18px;background:#df344e;color:#fff;border-radius:12px;box-shadow:0 10px 24px #0006;font-weight:700;text-decoration:none">Technician schedules &amp; payroll</a>


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
                            <a href="<?php echo $href; ?>" class="nav-item">
                                <svg class="w-[18px] h-[18px] shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><?php echo $icon; ?></svg>
                                <span class="flex-1"><?php echo $label; ?></span>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div>
                    <div class="text-[11px] font-semibold tracking-[.18em] text-white/75 px-3 mb-2">SYSTEM</div>
                    <div class="space-y-1">
                        <?php foreach ($nav_system as [$href, $label, $icon, $active]): ?>
                            <a href="<?php echo $href; ?>" class="nav-item<?php echo $active ? ' active' : ''; ?>"<?php echo $active ? ' aria-current="page"' : ''; ?>>
                                <svg class="w-[18px] h-[18px] shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><?php echo $icon; ?></svg>
                                <span class="flex-1"><?php echo $label; ?></span>
                                <?php if ($active): ?>
                                    <span class="bg-red/80 text-white text-[10px] font-bold px-2 py-0.5 rounded-full"><?= count($staffMembers) ?></span>
                                <?php endif; ?>
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

        <!-- Topbar Search & Mobile Toggle -->
        <div class="flex items-center justify-between gap-4">
            <div class="flex items-center gap-3 w-full max-w-xl">
                <button onclick="toggleMobileSidebar()" class="md:hidden text-white hover:text-red p-2 glass-dark transition">
                    <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
                </button>
                <div class="relative w-full">
                    <span class="absolute inset-y-0 left-0 flex items-center pl-4 pointer-events-none text-white/60">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </span>
                    <input type="search" id="topbar-search" class="glass-dark w-full pl-11 pr-20 py-2.5 text-sm placeholder-white/55 focus:outline-none focus:border-white/50" placeholder="Search staff name, email, phone… Ctrl K" autocomplete="off">
                    <kbd class="absolute inset-y-0 right-0 flex items-center pr-4 pointer-events-none text-[11px] text-white/50 font-sans">Ctrl K</kbd>
                </div>
            </div>
        </div>

        <!-- NOTIFICATION MESSAGES -->
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

        <?php if (!empty($errorMsg)): ?>
            <div class="glass p-4 border-rose-400/40 bg-rose-500/10 text-rose-200 text-xs font-semibold flex items-center justify-between shadow-lg">
                <div class="flex items-center gap-3">
                    <div class="w-7 h-7 rounded-full bg-rose-400/20 border border-rose-300/40 flex items-center justify-center shrink-0">
                        <svg class="w-4 h-4 text-rose-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <span><?= htmlspecialchars($errorMsg) ?></span>
                </div>
                <button onclick="this.parentElement.remove()" class="text-rose-300 hover:text-white font-mono px-2 py-1 rounded bg-white/10 transition">&times;</button>
            </div>
        <?php endif; ?>

        <!-- PAGE HEADER -->
        <div class="flex flex-col sm:flex-row sm:items-end justify-between gap-4">
            <div>
                <p class="text-xs font-semibold tracking-[.18em] text-white/60 mb-1"><?php echo strtoupper(date('l')) . ' · ' . strtoupper(date('j F Y')); ?></p>
                <h1 class="font-display text-5xl font-bold tracking-tight text-white uppercase leading-none">Staff &amp; Technicians<span class="text-red">.</span></h1>
                <p class="text-sm text-white/70 mt-2">Manage staff members, technicians, service advisors, and system access permissions.</p>
            </div>
            <button type="button" id="toggle-staff-form" aria-controls="staff-create-panel"
                    aria-expanded="<?= ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_staff' && $errorMsg !== '') ? 'true' : 'false' ?>"
                    class="shrink-0 inline-flex items-center justify-center gap-2 bg-red hover:bg-red-hover text-white font-display text-base uppercase font-semibold py-3 px-5 rounded-xl shadow-lg shadow-red/30 transition">
                <span id="staff-form-toggle-icon" aria-hidden="true">+</span>
                <span id="staff-form-toggle-label">Create Employee</span>
            </button>
        </div>

        <!-- CREATE EMPLOYEE MODAL -->
        <div id="staff-create-panel" class="staff-modal fixed inset-0 z-[100] flex items-center justify-center px-4 py-4" role="dialog" aria-modal="true" aria-labelledby="staff-modal-title" tabindex="-1" <?= ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_staff' && $errorMsg !== '') ? '' : 'hidden' ?>>
            <div class="staff-modal-card">
                <button type="button" id="close-staff-form" aria-label="Close form" class="absolute right-5 top-4 text-white/65 hover:text-red text-2xl leading-none transition">&times;</button>
                <h3 id="staff-modal-title" class="font-display text-[28px] leading-tight font-bold uppercase tracking-wide text-white pr-8">Create Employee<span class="text-red">.</span></h3>
                <p class="text-xs text-white/60 mt-1">Register a new REDLINE staff account</p>
                <div class="modal-divider"></div>
                <form action="list.php" method="POST" class="staff-create-form">
                    <input type="hidden" name="action" value="add_staff">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                    <div>
                        <label for="staff-full-name" class="label">Full Name (*)</label>
                        <input id="staff-full-name" type="text" name="full_name" required maxlength="100" placeholder="e.g. John Doe" value="<?= htmlspecialchars((string)(($_POST['action'] ?? '') === 'add_staff' && $errorMsg !== '' ? ($_POST['full_name'] ?? '') : ''), ENT_QUOTES, 'UTF-8') ?>" class="staff-modal-input">
                    </div>
                    <div>
                        <label for="staff-phone" class="label">Phone Number (*)</label>
                        <input id="staff-phone" type="tel" name="phone" required maxlength="15" placeholder="555-019-8871" value="<?= htmlspecialchars((string)(($_POST['action'] ?? '') === 'add_staff' && $errorMsg !== '' ? ($_POST['phone'] ?? '') : ''), ENT_QUOTES, 'UTF-8') ?>" class="staff-modal-input">
                    </div>
                    <div>
                        <label for="staff-email" class="label">Login Email (*)</label>
                        <input id="staff-email" type="email" name="email" required maxlength="100" placeholder="tech@redline.com" value="<?= htmlspecialchars((string)(($_POST['action'] ?? '') === 'add_staff' && $errorMsg !== '' ? ($_POST['email'] ?? '') : ''), ENT_QUOTES, 'UTF-8') ?>" class="staff-modal-input">
                    </div>
                    <div>
                        <label for="staff-username" class="label">Username (*)</label>
                        <input id="staff-username" type="text" name="username" required maxlength="50" pattern="[A-Za-z0-9._-]+" autocomplete="username" placeholder="john.doe" value="<?= htmlspecialchars((string)(($_POST['action'] ?? '') === 'add_staff' && $errorMsg !== '' ? ($_POST['username'] ?? '') : ''), ENT_QUOTES, 'UTF-8') ?>" class="staff-modal-input">
                    </div>
                    <div>
                        <label for="staff-role" class="label">Role / Position (*)</label>
                        <select id="staff-role" name="role_name" required class="staff-modal-input">
                            <?php $selectedRole = ($_POST['action'] ?? '') === 'add_staff' && $errorMsg !== '' ? ($_POST['role_name'] ?? 'Technician') : 'Technician'; ?>
                            <option value="Technician" <?= $selectedRole === 'Technician' ? 'selected' : '' ?>>Technician</option>
                        </select>
                    </div>
                    <div>
                        <label for="staff-password" class="label">Initial Password (*)</label>
                        <input id="staff-password" type="password" name="password" required minlength="8" autocomplete="new-password" placeholder="At least 8 characters" class="staff-modal-input">
                    </div>
                    <button type="submit" class="w-full bg-red hover:bg-red-hover text-white font-display text-base uppercase font-bold py-2.5 px-6 rounded-xl shadow-lg shadow-red/25 transition">+ Create Staff Account</button>
                </form>
            </div>
        </div>

        <!-- STAFF MEMBERS TABLE -->
        <section class="glass overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left border-collapse text-[13px]">
                    <thead>
                        <tr class="label border-b border-white/15 bg-white/5">
                            <th class="py-3.5 px-6 font-semibold">Staff Member</th>
                            <th class="py-3.5 px-4 font-semibold">Contact &amp; Login</th>
                            <th class="py-3.5 px-4 font-semibold">Role &amp; Position</th>
                            <th class="py-3.5 px-4 font-semibold text-center">Active Jobs</th>
                            <th class="py-3.5 px-4 font-semibold text-center">Total Assigned Orders</th>
                            <th class="py-3.5 px-4 font-semibold text-center">Status</th>
                            <th class="py-3.5 px-6 font-semibold text-center">Action</th>
                        </tr>
                    </thead>
                    <tbody id="staff-table-body" class="divide-y divide-white/10">
                        <?php foreach ($staffMembers as $u): ?>
                            <?php
                                $uInitials = '';
                                foreach (array_slice(preg_split('/\s+/', trim($u['full_name'])), -2) as $w) {
                                    $uInitials .= mb_strtoupper(mb_substr($w, 0, 1));
                                }
                                $isActive = (int)($u['is_active'] ?? 1);
                            ?>
                            <tr class="hover:bg-white/10 transition border-t border-white/15 staff-row">
                                <td class="py-3.5 px-6">
                                    <div class="flex items-center gap-3">
                                        <div class="w-9 h-9 rounded-full bg-white/15 border border-white/20 flex items-center justify-center font-bold text-red font-mono text-xs shadow-sm shrink-0">
                                            <?= htmlspecialchars($uInitials, ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                        <div class="font-semibold text-white text-sm staff-name"><?= htmlspecialchars($u['full_name']) ?></div>
                                    </div>
                                </td>

                                <td class="py-3.5 px-4">
                                    <div class="font-mono text-red font-semibold staff-contact"><?= htmlspecialchars($u['phone'] ?: 'N/A') ?></div>
                                    <div class="text-[11px] text-slate-300 font-mono staff-contact"><?= htmlspecialchars($u['email'] ?: '--') ?></div>
                                    <div class="text-[11px] text-slate-300 font-mono staff-username">Login: <?= htmlspecialchars($u['username']) ?></div>
                                </td>

                                <td class="py-3.5 px-4">
                                    <span class="glass-dark px-2.5 py-1 rounded text-xs font-mono font-semibold">
                                        <?= htmlspecialchars($u['final_role'] ?: 'Technician') ?>
                                    </span>
                                </td>

                                <td class="py-3.5 px-4 text-center font-mono font-semibold text-amber-300">
                                    <?= (int)($u['active_assigned_orders'] ?? 0) ?> cars <?php if (($u['final_role'] ?? '') === 'Technician'): ?><a class="block text-xs underline text-white mt-1" href="../technicians/assignments.php?technician_id=<?= (int)$u['id'] ?>">View assignments</a><?php endif; ?>
                                </td>

                                <td class="py-3.5 px-4 text-center font-mono text-white font-semibold">
                                    <?= (int)($u['total_assigned_orders'] ?? 0) ?> orders
                                </td>

                                <td class="py-3.5 px-4 text-center">
                                    <?php if ($isActive === 1): ?>
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[11px] font-semibold border bg-emerald-400/20 text-emerald-200 border-emerald-300/40 uppercase">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-300"></span>
                                            Active
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-[11px] font-semibold border bg-rose-400/20 text-rose-200 border-rose-300/40 uppercase">
                                            <span class="w-1.5 h-1.5 rounded-full bg-rose-400"></span>
                                            Suspended
                                        </span>
                                    <?php endif; ?>
                                </td>

                                <td class="py-3.5 px-6 text-center">
                                    <form action="list.php" method="POST" class="inline">
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <input type="hidden" name="new_status" value="<?= $isActive === 1 ? 0 : 1 ?>">
                                        
                                        <?php if ($isActive === 1): ?>
                                            <button type="submit" class="glass-dark hover:bg-rose-500/20 text-rose-200 border-rose-300/40 text-[11px] font-semibold px-3 py-1 rounded-lg uppercase font-mono transition">
                                                Lock
                                            </button>
                                        <?php else: ?>
                                            <button type="submit" class="glass-dark hover:bg-emerald-500/20 text-emerald-200 border-emerald-300/40 text-[11px] font-semibold px-3 py-1 rounded-lg uppercase font-mono transition">
                                                Activate
                                            </button>
                                        <?php endif; ?>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($staffMembers)): ?>
                            <tr><td colspan="7" class="py-8 text-center text-slate-300 font-mono text-xs">No staff members found.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

    </main>

    <script>
        function toggleMobileSidebar() {
            const sidebar = document.getElementById('main-sidebar');
            const backdrop = document.getElementById('mobile-sidebar-backdrop');
            sidebar.classList.toggle('-translate-x-full');
            backdrop.classList.toggle('hidden');
        }

        document.addEventListener('DOMContentLoaded', function () {
            const staffToggle = document.getElementById('toggle-staff-form');
            const staffPanel = document.getElementById('staff-create-panel');
            const closeStaffForm = document.getElementById('close-staff-form');
            const staffModalCard = staffPanel?.querySelector('.staff-modal-card');
            let previouslyFocused = null;
            const mainContent = document.querySelector('main');

            function openStaffForm() {
                previouslyFocused = document.activeElement;
                staffPanel.hidden = false;
                if (mainContent) mainContent.style.overflowY = 'hidden';
                staffToggle.setAttribute('aria-expanded', 'true');
                staffPanel.querySelector('input[name="full_name"]')?.focus();
            }
            function hideStaffForm() {
                staffPanel.hidden = true;
                if (mainContent) mainContent.style.overflowY = '';
                staffToggle.setAttribute('aria-expanded', 'false');
                (previouslyFocused || staffToggle)?.focus();
            }
            staffToggle?.addEventListener('click', openStaffForm);
            closeStaffForm?.addEventListener('click', hideStaffForm);
            staffPanel?.addEventListener('click', function (event) {
                if (event.target === staffPanel) hideStaffForm();
            });
            document.addEventListener('keydown', function (event) {
                if (event.key === 'Escape' && staffPanel && !staffPanel.hidden) hideStaffForm();
                if (event.key === 'Tab' && staffPanel && !staffPanel.hidden) {
                    const focusable = Array.from(staffModalCard.querySelectorAll('button, input, select, textarea, a[href]')).filter(el => !el.disabled);
                    if (!focusable.length) return;
                    const first = focusable[0];
                    const last = focusable[focusable.length - 1];
                    if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
                    else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
                }
            });
            if (staffPanel && !staffPanel.hidden) {
                if (mainContent) mainContent.style.overflowY = 'hidden';
                staffPanel.querySelector('input[name="full_name"]')?.focus();
            }

            const searchInput = document.getElementById('topbar-search');

            // Shortcut Ctrl + K for search
            document.addEventListener('keydown', function (e) {
                if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') {
                    e.preventDefault();
                    if (searchInput) {
                        searchInput.focus();
                        searchInput.select();
                    }
                }
            });

            // Real-time search filter for staff table
            if (searchInput) {
                searchInput.addEventListener('input', function () {
                    const query = this.value.toLowerCase().trim();
                    const rows = document.querySelectorAll('.staff-row');

                    rows.forEach(function (row) {
                        const name = row.querySelector('.staff-name')?.textContent.toLowerCase() || '';
                        const contact = row.querySelector('.staff-contact')?.textContent.toLowerCase() || '';
                        const username = row.querySelector('.staff-username')?.textContent.toLowerCase() || '';

                        if (name.includes(query) || contact.includes(query) || username.includes(query)) {
                            row.style.display = '';
                        } else {
                            row.style.display = 'none';
                        }
                    });
                });
            }
        });
    </script>
</body>
</html>
