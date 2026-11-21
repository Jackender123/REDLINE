<?php
// list.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/inventory.php';
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
$errorMsg  = '';
$csrfToken = csrf_token();

/** Record all physical stock movements in the existing audit table. */
function rl_stock_record(PDO $pdo, int $partId, int $userId, string $direction, int $quantity, string $note): void
{
    if ($partId <= 0 || $userId <= 0 || $quantity <= 0 || !in_array($direction, ['import', 'export'], true)) {
        throw new InvalidArgumentException('Invalid stock movement.');
    }
    $stmt = $pdo->prepare('INSERT INTO inventory_transactions (part_id, user_id, type, quantity, note) VALUES (:part_id, :user_id, :type, :qty, :note)');
    $stmt->execute(['part_id' => $partId, 'user_id' => $userId, 'type' => $direction, 'qty' => $quantity, 'note' => mb_substr($note, 0, 255)]);
}



if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_valid($_POST['csrf'] ?? null)) {
    $errorMsg = 'Your session is invalid. Please refresh the page.';
}

// --- 1. ADD NEW SPARE PART TO INVENTORY ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errorMsg === '' && ($_POST['action'] ?? '') === 'add_part') {
    $code     = trim($_POST['part_code'] ?? '');
    $name     = trim($_POST['name'] ?? '');
    $catId    = (int)($_POST['category_id'] ?? 1);
    $unit     = trim($_POST['unit'] ?? 'pcs');
    $importP  = (float)($_POST['import_price'] ?? 0);
    $sellP    = (float)($_POST['selling_price'] ?? 0);
    $qty      = (int)($_POST['stock_quantity'] ?? 0);
    $minAlert = (int)($_POST['min_stock_alert'] ?? 5);

    if ($code === '' || $name === '' || $catId <= 0 || $importP < 0 || $sellP < 0 || $qty < 0 || $minAlert < 0) {
        $errorMsg = 'Invalid part information.';
    } else {
        try {
            $stmtIns = $pdo->prepare("
                INSERT INTO parts (category_id, part_code, name, unit, import_price, selling_price, stock_quantity, min_stock_alert, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)
            ");
            $pdo->beginTransaction();
            $stmtIns->execute([$catId, $code, $name, $unit, $importP, $sellP, $qty, $minAlert]);
            if ($qty > 0) {
                rl_stock_record($pdo, (int)$pdo->lastInsertId(), (int)$staff['id'], 'import', $qty, 'Opening stock: ' . $code);
            }
            $pdo->commit();
            $noticeMsg = "Part <strong>" . htmlspecialchars($name) . "</strong> added to inventory successfully!";
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('Inventory add part failed: ' . $e->getMessage());
            $errorMsg = $e->getCode() === '23000'
                ? 'This part code already exists.'
                : 'Unable to add the part due to a server error.';
        }
    }
}

// --- 2. IMPORT STOCK WITH AUDIT TRAIL ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errorMsg === '' && ($_POST['action'] ?? '') === 'restock') {
    $partId = filter_var($_POST['part_id'] ?? null, FILTER_VALIDATE_INT);
    $addQty = filter_var($_POST['add_quantity'] ?? null, FILTER_VALIDATE_INT);
    $note = trim((string)($_POST['stock_note'] ?? 'Quick restock'));
    if (!$partId || !$addQty || $addQty < 1 || $addQty > 1000000 || mb_strlen($note) > 255) {
        $errorMsg = 'Invalid restock quantity or part.';
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('UPDATE parts SET stock_quantity = stock_quantity + :qty WHERE id = :id AND is_active = 1 AND stock_quantity <= 2147483647 - :limit_qty');
            $stmt->execute(['qty' => $addQty, 'id' => $partId, 'limit_qty' => $addQty]);
            if ($stmt->rowCount() !== 1) throw new RuntimeException('Part not found or quantity exceeds the allowed limit.');
            rl_stock_record($pdo, $partId, (int)$staff['id'], 'import', $addQty, $note !== '' ? $note : 'Quick restock');
            $pdo->commit();
            $noticeMsg = 'Stock added and transaction history recorded.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            if (!($e instanceof RuntimeException)) error_log('Inventory restock failed: ' . $e->getMessage());
            $errorMsg = $e instanceof RuntimeException ? $e->getMessage() : 'Unable to restock the part.';
        }
    }
}

// --- 2B. STOCK CORRECTION (ADMIN ONLY) ---
// Correction export is NOT a repair-order issue; parts for jobs must use approved requests.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errorMsg === '' && ($_POST['action'] ?? '') === 'stock_adjust') {
    if (($staff['role_name'] ?? '') !== 'Admin') {
        $errorMsg = 'Only administrators may adjust stock.';
    } else {
        $partId = filter_var($_POST['part_id'] ?? null, FILTER_VALIDATE_INT);
        $qty = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_INT);
        $direction = (string)($_POST['direction'] ?? '');
        $reason = trim((string)($_POST['reason'] ?? ''));
        if (!$partId || !$qty || $qty < 1 || $qty > 1000000 || !in_array($direction, ['import', 'export'], true)
            || mb_strlen($reason) < 5 || mb_strlen($reason) > 200) {
            $errorMsg = 'Select a part, enter a valid quantity, and provide a reason (5–200 characters).';
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare('SELECT id, stock_quantity FROM parts WHERE id = :id AND is_active = 1 FOR UPDATE');
                $stmt->execute(['id' => $partId]);
                $part = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$part) throw new RuntimeException('Part not found.');
                $newStock = (int)$part['stock_quantity'] + ($direction === 'import' ? $qty : -$qty);
                if ($newStock < 0 || $newStock > 2147483647) throw new RuntimeException('Insufficient stock or quantity exceeds the allowed limit.');
                $pdo->prepare('UPDATE parts SET stock_quantity = :stock WHERE id = :id')
                    ->execute(['stock' => $newStock, 'id' => $partId]);
                rl_stock_record($pdo, $partId, (int)$staff['id'], $direction, $qty, 'Inventory correction: ' . $reason);
                $pdo->commit();
                $noticeMsg = 'Stock adjusted and audit log recorded.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                if (!($e instanceof RuntimeException)) error_log('Stock correction failed: ' . $e->getMessage());
                $errorMsg = $e instanceof RuntimeException ? $e->getMessage() : 'Unable to adjust stock.';
            }
        }
    }
}

// --- 2C. EDIT PRODUCT METADATA (ADMIN ONLY) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $errorMsg === '' && ($_POST['action'] ?? '') === 'update_part') {
    if (($staff['role_name'] ?? '') !== 'Admin') {
        $errorMsg = 'Only administrators may edit part details.';
    } else {
        $partId = filter_var($_POST['part_id'] ?? null, FILTER_VALIDATE_INT);
        $name = trim((string)($_POST['name'] ?? ''));
        $catId = filter_var($_POST['category_id'] ?? null, FILTER_VALIDATE_INT);
        $unit = trim((string)($_POST['unit'] ?? ''));
        $sell = filter_var($_POST['selling_price'] ?? null, FILTER_VALIDATE_FLOAT);
        $import = filter_var($_POST['import_price'] ?? null, FILTER_VALIDATE_FLOAT);
        $minStock = filter_var($_POST['min_stock_alert'] ?? null, FILTER_VALIDATE_INT);
        if (!$partId || !$catId || $name === '' || mb_strlen($name) > 150 || $unit === '' || mb_strlen($unit) > 20
            || $sell === false || $import === false || !is_finite((float)$sell) || !is_finite((float)$import)
            || $sell < 0 || $import < 0 || $sell > 999999999999 || $import > 999999999999
            || $minStock === false || $minStock < 0 || $minStock > 2147483647) {
            $errorMsg = 'Invalid part details.';
        } else {
            try {
                $stmt = $pdo->prepare('UPDATE parts SET name=:name, category_id=:category, unit=:unit, import_price=:import_price, selling_price=:selling_price, min_stock_alert=:minimum WHERE id=:id AND is_active=1');
                $stmt->execute(['name'=>$name,'category'=>$catId,'unit'=>$unit,'import_price'=>$import,'selling_price'=>$sell,'minimum'=>$minStock,'id'=>$partId]);
                $noticeMsg = 'Part details saved; stock was not changed.';
            } catch (Throwable $e) {
                error_log('Product metadata update failed: ' . $e->getMessage());
                $errorMsg = 'Unable to save part details.';
            }
        }
    }
}

// AJAX submits use the same existing stock handlers above. Return a structured result
// before rendering HTML; the browser refreshes its tables from a read-only GET.
// Normal form POST (JavaScript disabled) continues to render the original page.
$isInventoryAjaxPost = $_SERVER['REQUEST_METHOD'] === 'POST'
    && strtolower(trim((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''))) === 'xmlhttprequest';
if ($isInventoryAjaxPost) {
    $allowedInventoryActions = ['add_part', 'restock', 'stock_adjust', 'update_part'];
    $postedInventoryAction = (string)($_POST['action'] ?? '');
    if ($errorMsg === '' && !in_array($postedInventoryAction, $allowedInventoryActions, true)) {
        $errorMsg = 'Invalid inventory action.';
    }
    $ok = $errorMsg === '';
    http_response_code($ok ? 200 : 422);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-REDLINE-Inventory-Build: 20261120-inventory-ajax-v1');
    echo json_encode([
        'ok' => $ok,
        'action' => $postedInventoryAction,
        'message' => $ok ? trim(strip_tags($noticeMsg)) : $errorMsg,
    ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

// --- 3. QUERY SPARE PARTS & CATEGORIES LIST ---
$parts = [];
$categories = [];
$inventoryLoadError = false;
$inventory = [
    'parts' => [], 'categories' => [], 'brands' => [],
    'filters' => ['q' => '', 'category' => '', 'brand' => '', 'stock' => '', 'page' => 1],
    'total' => 0, 'filtered_total' => 0, 'page' => 1, 'pages' => 1, 'from' => 0, 'to' => 0,
    'stock_counts' => ['all' => 0, 'low' => 0, 'available' => 0],
];
try {
    $inventory = rl_inventory_list($pdo, $_GET);
    $parts = $inventory['parts'];
    $categories = $pdo->query("SELECT * FROM part_categories ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $inventoryLoadError = true;
    error_log('Inventory query failed: ' . $e->getMessage());
}
$filters = $inventory['filters'];
$inventoryUrl = rl_inventory_url($filters);
// Stock movement history and operational KPIs (the existing transaction schema).
$stockMovements = [];
$stockValue = 0.0;
$openRequestCount = 0;
$movementType = (string)($_GET['movement_type'] ?? '');
if (!in_array($movementType, ['', 'import', 'export'], true)) $movementType = '';
try {
    $stockValue = (float)$pdo->query('SELECT COALESCE(SUM(stock_quantity * import_price),0) FROM parts WHERE is_active=1')->fetchColumn();
    $openRequestCount = (int)$pdo->query("SELECT COUNT(*) FROM part_requests WHERE status IN ('pending','approved')")->fetchColumn();
    $historySql = 'SELECT t.id, t.type, t.quantity, t.note, t.created_at, p.part_code, p.name AS part_name, u.full_name AS actor
        FROM inventory_transactions t JOIN parts p ON p.id = t.part_id JOIN users u ON u.id = t.user_id';
    if ($movementType !== '') $historySql .= ' WHERE t.type = :movement_type';
    $historySql .= ' ORDER BY t.id DESC LIMIT 60';
    $historyQuery = $pdo->prepare($historySql);
    if ($movementType !== '') $historyQuery->bindValue(':movement_type', $movementType, PDO::PARAM_STR);
    $historyQuery->execute();
    $stockMovements = $historyQuery->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('Inventory operations summary failed: ' . $e->getMessage());
}

// Compute Avatar Initials
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
    ['../customers/list.php',                         'Customers',     '<path d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>', false],
    ['../appointments/list.php',                      'Appointments',  '<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>', false],
    ['../invoices/list.php',                          'Invoices',      '<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>', false],
    ['list.php',                         'Inventory',     '<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/>', true],
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

$icon = static function (string $name): string {
    $paths = [
        'box' => '<path d="m21 8-9-5-9 5v9l9 5 9-5V8Z"/><path d="m3 8 9 5 9-5M12 13v9M7.5 5.5l9 5"/>',
        'search' => '<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4.5 4.5"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'arrow' => '<path d="M5 12h14m-5-5 5 5-5 5"/>',
        'warning' => '<path d="m10.3 4-8 14a2 2 0 0 0 1.7 3h16a2 2 0 0 0 1.7-3l-8-14a2 2 0 0 0-3.4 0Z"/><path d="M12 9v4m0 4h.01"/>',
        'brand' => '<path d="M20 13 13 20a2 2 0 0 1-3 0L2 12V3h9l9 8a2 2 0 0 1 0 2Z"/><circle cx="7.5" cy="7.5" r="1"/>',
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'logout' => '<path d="M9 5H4v14h5m6-14 7 7-7 7m7-7H9"/>',
        'chevron' => '<path d="m6 9 6 6 6-6"/>',
        'check' => '<path d="m5 12 4 4L19 6"/>',
        'close' => '<path d="m6 6 12 12M18 6 6 18"/>',
        'requests' => '<path d="M9 4H5v17h14V4h-4M9 2h6v5H9zM9 12h6m-6 4h4"/>',
    ];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . ($paths[$name] ?? $paths['box']) . '</svg>';
};
$activeFilters = [];
if ($filters['q'] !== '') $activeFilters['q'] = 'Search: ' . $filters['q'];
foreach ($inventory['categories'] as $category) {
    if ($category['value'] === $filters['category']) $activeFilters['category'] = $category['name'];
}
if ($filters['category'] !== '' && !isset($activeFilters['category'])) $activeFilters['category'] = 'Category filter';
if ($filters['brand'] === 'none') $activeFilters['brand'] = 'No brand assigned';
foreach ($inventory['brands'] as $brand) {
    if ((string)$brand['id'] === $filters['brand']) $activeFilters['brand'] = $brand['name'];
}
if ($filters['brand'] !== '' && !isset($activeFilters['brand'])) $activeFilters['brand'] = 'Brand filter';
if ($filters['stock'] !== '') $activeFilters['stock'] = $filters['stock'] === 'low' ? 'Low & out of stock' : 'Healthy stock';
$inventoryStylesheet = __DIR__ . '/../../assets/css/inventory.css';
$inventoryStyleVersion = is_readable($inventoryStylesheet)
    ? substr((string)hash_file('sha256', $inventoryStylesheet), 0, 12)
    : '20261113';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inventory &amp; Parts | REDLINE</title>
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
    <link rel="stylesheet" href="../../assets/css/inventory.css?v=<?= htmlspecialchars($inventoryStyleVersion, ENT_QUOTES, 'UTF-8') ?>">
    <meta name="redline-inventory-ajax" content="20261120-inventory-ajax-v1">
</head>
<body class="inv-app text-white font-sans antialiased h-screen overflow-hidden flex selection:bg-red selection:text-white">
    <div id="mobile-sidebar-backdrop" class="inv-backdrop" hidden></div>
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
                        <?php foreach ($nav_main as [$href, $label, $navIcon, $active]): ?>
                            <a href="<?php echo $href; ?>" class="nav-item<?php echo $active ? ' active' : ''; ?>"<?php echo $active ? ' aria-current="page"' : ''; ?>>
                                <svg class="w-[18px] h-[18px] shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><?php echo $navIcon; ?></svg>
                                <span class="flex-1"><?php echo $label; ?></span>
                                <?php if ($active): ?>
                                    <span class="bg-red/80 text-white text-[10px] font-bold px-2 py-0.5 rounded-full" id="sidebar-cnt"><?= number_format($inventory['total']) ?></span>
                                <?php endif; ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div>
                    <div class="text-[11px] font-semibold tracking-[.18em] text-white/75 px-3 mb-2">SYSTEM</div>
                    <div class="space-y-1">
                        <?php foreach ($nav_system as [$href, $label, $navIcon, $active]): ?>
                            <a href="<?php echo $href; ?>" class="nav-item">
                                <svg class="w-[18px] h-[18px] shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><?php echo $navIcon; ?></svg>
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


    <main class="inv-main" id="inventory-main">
        <div class="inv-topbar">
            <button type="button" class="inv-mobile-toggle" id="mobile-menu-toggle" aria-label="Open navigation" aria-controls="main-sidebar" aria-expanded="false"><?= $icon('menu') ?></button>
            <form id="inventory-topbar-search" class="inv-topbar-search" action="list.php" method="GET" role="search" aria-label="Inventory search">
                <button type="submit" aria-label="Search inventory"><?= $icon('search') ?></button>
                <input type="search" id="topbar-search" name="q" aria-label="Search parts by name, code, or brand" value="<?= htmlspecialchars($filters['q'], ENT_QUOTES, 'UTF-8') ?>" maxlength="150" placeholder="Search part name, code, or brand…" autocomplete="off">
                <kbd aria-hidden="true">Ctrl K</kbd>
                <?php foreach (['category', 'brand', 'stock'] as $filterName): ?>
                    <input type="hidden" name="<?= $filterName ?>" value="<?= htmlspecialchars($filters[$filterName], ENT_QUOTES, 'UTF-8') ?>">
                <?php endforeach; ?>
            </form>
            <button type="button" id="open-part-modal" class="inv-button inv-button-primary" aria-controls="part-create-modal" aria-expanded="false"><?= $icon('plus') ?> Add new part</button>
        </div>

        <header class="inv-heading">
            <div><p class="inv-eyebrow"><?= htmlspecialchars(strtoupper(date('l')) . ' · ' . strtoupper(date('j F Y')), ENT_QUOTES, 'UTF-8') ?></p><h1>Inventory &amp; parts<span>.</span></h1><p>Find the right part. Keep the workshop moving.</p></div>
            <div class="inv-workspace-status">
                <span class="inv-status-light" aria-hidden="true"></span>
                <span>INVENTORY CONSOLE</span>
            </div>
        </header>

        <?php if ($noticeMsg !== ''): ?>
            <div class="inv-alert is-success" role="status"><?= $icon('check') ?><span><?= $noticeMsg ?></span><button type="button" aria-label="Dismiss notification" data-dismiss-alert><?= $icon('close') ?></button></div>
        <?php endif; ?>
        <?php if ($errorMsg !== ''): ?>
            <div class="inv-alert is-error" role="alert"><?= $icon('warning') ?><span><?= htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8') ?></span><button type="button" aria-label="Dismiss error" data-dismiss-alert><?= $icon('close') ?></button></div>
        <?php endif; ?>

        <!-- INVENTORY KPI CARDS — same structure as invoices finance cards -->
        <div class="inv-overview inv-finance-kpis" aria-label="Inventory overview">
            <a href="list.php" class="inv-overview-item">
                <div class="inv-kpi-top"><span class="inv-kpi-label">TOTAL PARTS</span><span class="inv-kpi-symbol"><?= $icon('box') ?></span></div>
                <div class="inv-kpi-bottom"><strong><?= $inventoryLoadError ? '—' : number_format($inventory['total']) ?></strong><small>Active parts across the workshop</small></div>
            </a>
            <a href="<?= htmlspecialchars(rl_inventory_url($filters, ['stock' => 'low', 'page' => 1]), ENT_QUOTES, 'UTF-8') ?>" class="inv-overview-item inv-kpi-alert">
                <div class="inv-kpi-top"><span class="inv-kpi-label">NEEDS RESTOCK</span><span class="inv-kpi-symbol"><?= $icon('warning') ?></span></div>
                <div class="inv-kpi-bottom"><strong><?= $inventoryLoadError ? '—' : number_format($inventory['stock_counts']['low']) ?></strong><small>Low or out of stock</small></div>
            </a>
            <a href="<?= htmlspecialchars(rl_inventory_url($filters, ['stock' => 'available', 'page' => 1]), ENT_QUOTES, 'UTF-8') ?>" class="inv-overview-item inv-kpi-healthy">
                <div class="inv-kpi-top"><span class="inv-kpi-label">AVAILABLE PARTS</span><span class="inv-kpi-symbol"><?= $icon('check') ?></span></div>
                <div class="inv-kpi-bottom"><strong><?= $inventoryLoadError ? '—' : number_format($inventory['stock_counts']['available']) ?></strong><small>Stock above minimum threshold</small></div>
            </a>
        </div>

        <section class="inv-operations-summary" aria-label="Operational summary">
            <div class="inv-ops-stat"><span>STOCK COST VALUE</span><strong><?= rl_usd($stockValue) ?></strong><small>Active quantities × import price</small></div>
            <?php if (($staff['role_name'] ?? '') === 'Admin'): ?>
            <a href="../technicians/assignments.php?requests=1#technician-requests" class="inv-ops-stat"><span>OPEN PART REQUESTS</span><strong><?= number_format($openRequestCount) ?></strong><small>Manage requests in Technician Workload →</small></a>
            <?php else: ?>
            <div class="inv-ops-stat"><span>OPEN PART REQUESTS</span><strong><?= number_format($openRequestCount) ?></strong><small>Managed by Admin in Technician Workload</small></div>
            <?php endif; ?>
        </section>

        <!-- FILTER TABS + TABLE SEARCH — invoice-style horizontal toolbar -->
        <div class="inv-table-toolbar">
        <nav class="inv-stock-tabs" aria-label="Filter inventory by stock status">
            <a class="inv-stock-tab<?= $filters['stock'] === '' ? ' is-active' : '' ?>" href="<?= htmlspecialchars(rl_inventory_url($filters, ['stock' => '', 'page' => 1]), ENT_QUOTES, 'UTF-8') ?>">ALL <span class="inv-tab-count"><?= number_format($inventory['stock_counts']['all']) ?></span></a>
            <a class="inv-stock-tab<?= $filters['stock'] === 'low' ? ' is-active' : '' ?>" href="<?= htmlspecialchars(rl_inventory_url($filters, ['stock' => 'low', 'page' => 1]), ENT_QUOTES, 'UTF-8') ?>">LOW STOCK <span class="inv-tab-count is-low"><?= number_format($inventory['stock_counts']['low']) ?></span></a>
            <a class="inv-stock-tab<?= $filters['stock'] === 'available' ? ' is-active' : '' ?>" href="<?= htmlspecialchars(rl_inventory_url($filters, ['stock' => 'available', 'page' => 1]), ENT_QUOTES, 'UTF-8') ?>">IN STOCK <span class="inv-tab-count is-healthy"><?= number_format($inventory['stock_counts']['available']) ?></span></a>
        </nav>
        <form class="inv-inline-search" action="list.php" method="GET" role="search" aria-label="Filter parts table">
            <span aria-hidden="true"><?= $icon('search') ?></span>
            <input type="search" name="q" value="<?= htmlspecialchars($filters['q'], ENT_QUOTES, 'UTF-8') ?>" maxlength="150" placeholder="Filter part code, name, brand..." aria-label="Filter part code, name, brand">
            <?php foreach (['category', 'brand', 'stock'] as $filterName): ?>
                <input type="hidden" name="<?= $filterName ?>" value="<?= htmlspecialchars($filters[$filterName], ENT_QUOTES, 'UTF-8') ?>">
            <?php endforeach; ?>
            <button type="submit">Search</button>
        </form>
        </div>

        <section class="inv-finder" aria-labelledby="finder-title">
            <div class="inv-finder-heading"><div><h2 id="finder-title">INVENTORY FILTERS</h2><p>Search by name, part code, or brand.</p></div><span class="inv-shortcut"><kbd>Ctrl</kbd><kbd>K</kbd> to search</span></div>
            <form id="inventory-filters" action="list.php" method="GET">
                <input type="hidden" name="q" value="<?= htmlspecialchars($filters['q'], ENT_QUOTES, 'UTF-8') ?>">
                <div class="inv-filter-row">
                    <div class="inv-filter-field"><label for="filter-category">Category</label><select id="filter-category" name="category">
                        <option value="">All categories</option>
                        <?php foreach ($inventory['categories'] as $category): ?>
                            <option value="<?= htmlspecialchars($category['value'], ENT_QUOTES, 'UTF-8') ?>" <?= $filters['category'] === $category['value'] ? 'selected' : '' ?>><?= htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8') ?> (<?= (int)$category['count'] ?>)</option>
                        <?php endforeach; ?>
                    </select></div>
                    <div class="inv-filter-field"><label for="filter-brand">Brand</label><select id="filter-brand" name="brand">
                        <option value="">All brands</option>
                        <?php foreach ($inventory['brands'] as $brand): ?>
                            <option value="<?= (int)$brand['id'] ?>" <?= $filters['brand'] === (string)$brand['id'] ? 'selected' : '' ?>><?= htmlspecialchars($brand['name'], ENT_QUOTES, 'UTF-8') ?> (<?= (int)$brand['count'] ?>)</option>
                        <?php endforeach; ?>
                        <option value="none" <?= $filters['brand'] === 'none' ? 'selected' : '' ?>>No brand assigned (<?= (int)($inventory['total'] - array_sum(array_column($inventory['brands'], 'count'))) ?>)</option>
                    </select></div>
                    <div class="inv-filter-field"><label for="filter-stock">Stock status</label><select id="filter-stock" name="stock">
                        <option value="">All stock levels</option>
                        <option value="low" <?= $filters['stock'] === 'low' ? 'selected' : '' ?>>Low &amp; out of stock</option>
                        <option value="available" <?= $filters['stock'] === 'available' ? 'selected' : '' ?>>Healthy stock</option>
                    </select></div>
                </div>
                <div class="inv-filter-actions">
                    <button type="submit" class="inv-button inv-button-secondary">Apply filters <?= $icon('arrow') ?></button>
                    <a class="inv-reset-filters" href="list.php">Clear filters</a>
                </div>
            </form>
            <?php if ($activeFilters): ?>
                <div class="inv-active-filters"><span>Filtered by</span>
                    <?php foreach ($activeFilters as $filterKey => $filterLabel): ?>
                        <a class="inv-filter-chip" href="<?= htmlspecialchars(rl_inventory_url($filters, [$filterKey => '', 'page' => 1]), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars('Remove filter: ' . $filterLabel, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($filterLabel, ENT_QUOTES, 'UTF-8') ?><span class="inv-chip-x" aria-hidden="true">×</span></a>
                    <?php endforeach; ?>
                    <a href="list.php" class="inv-clear">Clear all</a>
                </div>
            <?php endif; ?>
        </section>

        <section class="inv-results-panel" aria-labelledby="results-title">
            <div class="inv-results-header">
                <div><h2 id="results-title">PARTS &amp; INVENTORY <span class="inv-result-count"><?= number_format($inventory['filtered_total']) ?></span></h2>
                    <p role="status"><?php if ($inventoryLoadError): ?>Inventory could not be loaded. Please refresh the page.<?php else: ?>Showing <?= (int)$inventory['from'] ?>–<?= (int)$inventory['to'] ?> of <?= number_format($inventory['filtered_total']) ?> parts<?php endif; ?></p>
                </div>
                <span class="inv-results-note"><?= $icon('warning') ?> Low stock shown first</span>
            </div>
            <div class="inv-table-wrap">
                <table class="inv-table">
                    <thead><tr><th scope="col">PART # &amp; NAME</th><th scope="col">BRAND / CATEGORY</th><th scope="col">PRICE</th><th scope="col">AVAILABLE</th><th scope="col">STATUS</th><th scope="col">ACTION</th></tr></thead>
                    <tbody id="inventory-table-body">
                        <?php if (!$parts): ?>
                            <tr><td colspan="6"><div class="inv-empty"><span class="inv-empty-icon"><?= $icon('search') ?></span><strong><?= $inventoryLoadError ? 'Parts are temporarily unavailable' : 'No parts found' ?></strong><p><?= $inventoryLoadError ? 'Refresh the page to try again.' : 'Try a different name or code, or widen your filters.' ?></p><a href="list.php" class="inv-button inv-button-secondary">View all parts <?= $icon('arrow') ?></a></div></td></tr>
                        <?php endif; ?>
                        <?php foreach ($parts as $part):
                            $isLowStock = $part['stock_quantity'] <= $part['min_stock_alert'];
                            $isEmpty = $part['stock_quantity'] <= 0;
                        ?>
                            <tr class="inv-part-row<?= $isLowStock ? ' is-low' : '' ?>">
                                <td class="inv-identity-cell"><div class="inv-part-identity"><span class="inv-part-icon"><?= $icon('box') ?></span><div class="inv-part-copy"><span class="inv-part-name"><?= htmlspecialchars($part['name'], ENT_QUOTES, 'UTF-8') ?></span><span class="inv-part-code"><?= htmlspecialchars($part['part_code'], ENT_QUOTES, 'UTF-8') ?></span><?php if (($staff['role_name'] ?? '') === 'Admin'): ?><details class="inv-edit-part"><summary>Edit details</summary>
<form method="POST" action="<?= htmlspecialchars($inventoryUrl, ENT_QUOTES, 'UTF-8') ?>" class="inv-edit-part-form">
<input type="hidden" name="action" value="update_part"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="part_id" value="<?= (int)$part['id'] ?>">
<label>Name <input name="name" required maxlength="150" value="<?= htmlspecialchars($part['name'], ENT_QUOTES, 'UTF-8') ?>"></label>
<label>Category <select name="category_id" required><?php foreach ($categories as $c): ?><option value="<?= (int)$c['id'] ?>" <?= (int)$part['category_id'] === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></label>
<label>Unit <input name="unit" required maxlength="20" value="<?= htmlspecialchars($part['unit'], ENT_QUOTES, 'UTF-8') ?>"></label>
<label>Import price <input name="import_price" type="number" min="0" max="999999999999" step="0.01" required value="<?= htmlspecialchars((string)$part['import_price'], ENT_QUOTES, 'UTF-8') ?>"></label>
<label>Sell price <input name="selling_price" type="number" min="0" max="999999999999" step="0.01" required value="<?= htmlspecialchars((string)$part['selling_price'], ENT_QUOTES, 'UTF-8') ?>"></label>
<label>Min stock <input name="min_stock_alert" type="number" min="0" required value="<?= (int)$part['min_stock_alert'] ?>"></label>
<button type="submit" class="inv-mini-button">Save details</button>
</form></details><?php endif; ?></div></div></td>
                                <td data-label="Brand / category"><span class="inv-brand-name"><?= htmlspecialchars($part['brand_name'] ?: 'Unassigned brand', ENT_QUOTES, 'UTF-8') ?></span><span class="inv-category-name"><?= htmlspecialchars($part['category_name'] ?: 'Other', ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td data-label="Selling price"><span class="inv-price"><?= rl_usd($part['selling_price']) ?></span><span class="inv-cost">Cost <?= rl_usd($part['import_price']) ?></span></td>
                                <td data-label="Available"><span class="inv-quantity"><strong><?= (int)$part['stock_quantity'] ?></strong> <?= htmlspecialchars($part['unit'], ENT_QUOTES, 'UTF-8') ?><small>Alert at <?= (int)$part['min_stock_alert'] ?></small></span></td>
                                <td data-label="Stock status"><span class="inv-stock-badge <?= $isEmpty ? 'is-empty' : ($isLowStock ? 'is-low' : 'is-healthy') ?>"><span class="inv-dot"></span><?= $isEmpty ? 'Out of stock' : ($isLowStock ? 'Low stock' : 'In stock') ?></span></td>
                                <td class="inv-restock-cell" data-label="Restock"><form action="<?= htmlspecialchars($inventoryUrl, ENT_QUOTES, 'UTF-8') ?>" method="POST" class="inv-restock-form">
                                    <input type="hidden" name="action" value="restock"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>"><input type="hidden" name="part_id" value="<?= (int)$part['id'] ?>">
                                    <input type="hidden" name="stock_note" value="Quick restock from inventory list"><input type="number" name="add_quantity" min="1" max="1000000" value="5" required aria-label="<?= htmlspecialchars('Quantity to restock for ' . $part['part_code'], ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit" aria-label="<?= htmlspecialchars('Restock ' . $part['part_code'], ENT_QUOTES, 'UTF-8') ?>"><?= $icon('plus') ?><span>Restock</span></button>
                                </form></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (!$inventoryLoadError && $inventory['pages'] > 1): ?>
                <nav class="inv-pagination" aria-label="Inventory pages"><span>Page <strong><?= (int)$inventory['page'] ?></strong> of <?= (int)$inventory['pages'] ?> <span class="inv-muted">· 25 parts per page</span></span><div class="inv-page-links">
                    <?php if ($inventory['page'] > 1): ?><a href="<?= htmlspecialchars(rl_inventory_url($filters, ['page' => $inventory['page'] - 1]), ENT_QUOTES, 'UTF-8') ?>" rel="prev">Previous</a><?php endif; ?>
                    <?php
                    $pageNumbers = array_unique(array_merge([1], range(max(1, $inventory['page'] - 2), min($inventory['pages'], $inventory['page'] + 2)), [$inventory['pages']]));
                    $previousPage = 0;
                    foreach ($pageNumbers as $pageNumber):
                    ?>
                        <?php if ($previousPage && $pageNumber - $previousPage > 1): ?><span aria-hidden="true">…</span><?php endif; ?>
                        <a href="<?= htmlspecialchars(rl_inventory_url($filters, ['page' => $pageNumber]), ENT_QUOTES, 'UTF-8') ?>" aria-label="Page <?= (int)$pageNumber ?>" <?= $pageNumber === $inventory['page'] ? 'aria-current="page"' : '' ?>><?= (int)$pageNumber ?></a>
                    <?php $previousPage = $pageNumber; endforeach; ?>
                    <?php if ($inventory['page'] < $inventory['pages']): ?><a href="<?= htmlspecialchars(rl_inventory_url($filters, ['page' => $inventory['page'] + 1]), ENT_QUOTES, 'UTF-8') ?>" rel="next">Next</a><?php endif; ?>
                </div></nav>
            <?php endif; ?>
        </section>

        <section class="inv-workflow-grid" aria-label="Warehouse operations">
            <div class="inv-workflow-card">
                <div class="inv-workflow-head"><div><p class="inv-eyebrow">WAREHOUSE CONTROL</p><h2>Stock movements</h2><small>Track inbound stock, damage and physical corrections.</small></div></div>
                <p class="inv-flow-note">Parts used on repair orders must be issued through approved technician requests. Manual exports below are ONLY for physical corrections, damage or loss.</p>
                <?php if (($staff['role_name'] ?? '') === 'Admin'): ?>
                <form method="POST" action="<?= htmlspecialchars($inventoryUrl, ENT_QUOTES, 'UTF-8') ?>" class="inv-operation-form">
                    <input type="hidden" name="action" value="stock_adjust"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <label>Part
                        <select name="part_id" required><option value="">Choose active part</option><?php foreach ($inventory['parts'] as $item): ?><option value="<?= (int)$item['id'] ?>"><?= htmlspecialchars($item['part_code'] . ' · ' . $item['name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select>
                    </label>
                    <label>Movement
                        <select name="direction" required><option value="import">Extra stock / correction (+)</option><option value="export">Damage / loss (-)</option></select>
                    </label>
                    <label>Quantity <input name="quantity" type="number" min="1" max="1000000" value="1" required></label>
                    <label class="inv-field-wide">Reason (required) <input name="reason" maxlength="200" minlength="5" required placeholder="Example: Damaged during storage / physical count correction"></label>
                    <button type="submit" class="inv-button inv-button-primary">Record movement</button>
                </form>
                <p class="inv-flow-note">To adjust a part not shown in the current page, find it with the search bar first.</p>
                <?php else: ?><p class="inv-flow-note">Only Admin may perform manual corrections.</p><?php endif; ?>
            </div>
            <div class="inv-workflow-card" id="movement-history">
                <div class="inv-workflow-head"><div><p class="inv-eyebrow">AUDIT TRAIL</p><h2>Recent transactions</h2><small>Last 60 transactions with actors, times and reasons.</small></div>
                    <form method="GET" action="list.php#movement-history" class="inv-audit-filter"><select name="movement_type" aria-label="Movement type" data-inventory-audit-filter><option value="">All movements</option><option value="import" <?= $movementType === 'import' ? 'selected' : '' ?>>Inbound</option><option value="export" <?= $movementType === 'export' ? 'selected' : '' ?>>Outbound</option></select></form>
                </div>
                <div class="inv-audit-scroll"><table class="inv-audit-table"><thead><tr><th>When / Who</th><th>Part / Reason</th><th>Qty</th></tr></thead><tbody>
                <?php foreach ($stockMovements as $movement): ?><tr>
                    <td><strong><?= htmlspecialchars(date('d/m/Y H:i', strtotime($movement['created_at'])), ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($movement['actor'], ENT_QUOTES, 'UTF-8') ?></small></td>
                    <td><strong><?= htmlspecialchars($movement['part_code'], ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($movement['part_name'], ENT_QUOTES, 'UTF-8') ?></small><small><?= htmlspecialchars((string)$movement['note'], ENT_QUOTES, 'UTF-8') ?></small></td>
                    <td><span class="inv-movement-badge <?= $movement['type'] === 'import' ? 'is-in' : 'is-out' ?>"><?= $movement['type'] === 'import' ? '+' : '−' ?><?= (int)$movement['quantity'] ?></span></td>
                </tr><?php endforeach; ?>
                <?php if (!$stockMovements): ?><tr><td colspan="3">No transactions for this selection.</td></tr><?php endif; ?>
                </tbody></table></div>
            </div>
        </section>

    </main>

    <?php $partFormHasError = $_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_part' && $errorMsg !== ''; ?>
    <div id="part-create-modal" class="inv-modal" role="dialog" aria-modal="true" aria-labelledby="part-modal-title" tabindex="-1" <?= $partFormHasError ? '' : 'hidden' ?>>
        <div class="inv-modal-card">
            <button type="button" id="close-part-modal" class="inv-modal-close" aria-label="Close form"><?= $icon('close') ?></button>
            <p class="inv-modal-kicker">INVENTORY MANAGEMENT</p><h2 id="part-modal-title">+ Create New Part<span>.</span></h2><p class="inv-modal-intro">Part details, pricing, and opening stock in one place.</p>
            <?php if ($partFormHasError): ?><div class="inv-alert is-error" role="alert"><?= $icon('warning') ?><span><?= htmlspecialchars($errorMsg, ENT_QUOTES, 'UTF-8') ?></span></div><?php endif; ?>
            <form action="<?= htmlspecialchars($inventoryUrl, ENT_QUOTES, 'UTF-8') ?>" method="POST" class="inv-create-form">
                <input type="hidden" name="action" value="add_part"><input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <div class="inv-form-field"><label for="new-part-code">Part code <span>*</span></label><input id="new-part-code" type="text" name="part_code" required maxlength="30" placeholder="e.g. PT-TOY-01" value="<?= htmlspecialchars((string)($partFormHasError ? ($_POST['part_code'] ?? '') : ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="inv-form-field"><label for="new-part-category">Category <span>*</span></label><select id="new-part-category" name="category_id" required><?php foreach ($categories as $category): ?><option value="<?= (int)$category['id'] ?>" <?= $partFormHasError && (int)($_POST['category_id'] ?? 0) === (int)$category['id'] ? 'selected' : '' ?>><?= htmlspecialchars($category['name'], ENT_QUOTES, 'UTF-8') ?></option><?php endforeach; ?></select></div>
                <div class="inv-form-field inv-span-2"><label for="new-part-name">Part / material name <span>*</span></label><input id="new-part-name" type="text" name="name" required maxlength="150" placeholder="e.g. Toyota Camry Oil Filter" value="<?= htmlspecialchars((string)($partFormHasError ? ($_POST['name'] ?? '') : ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="inv-form-field"><label for="new-part-import">Import price (USD) <span>*</span></label><input id="new-part-import" type="number" name="import_price" required min="0" step="0.01" placeholder="100.00" value="<?= htmlspecialchars((string)($partFormHasError ? ($_POST['import_price'] ?? '') : ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="inv-form-field"><label for="new-part-selling">Selling price (USD) <span>*</span></label><input id="new-part-selling" type="number" name="selling_price" required min="0" step="0.01" placeholder="150.00" value="<?= htmlspecialchars((string)($partFormHasError ? ($_POST['selling_price'] ?? '') : ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="inv-form-field"><label for="new-part-quantity">Opening stock <span>*</span></label><div class="inv-quantity-inputs"><input id="new-part-quantity" type="number" name="stock_quantity" required min="0" value="<?= htmlspecialchars((string)($partFormHasError ? ($_POST['stock_quantity'] ?? '10') : '10'), ENT_QUOTES, 'UTF-8') ?>"><input type="text" name="unit" aria-label="Unit" required maxlength="20" value="<?= htmlspecialchars((string)($partFormHasError ? ($_POST['unit'] ?? 'pcs') : 'pcs'), ENT_QUOTES, 'UTF-8') ?>" placeholder="Unit"></div></div>
                <div class="inv-form-field"><label for="new-part-alert">Low-stock alert</label><input id="new-part-alert" type="number" name="min_stock_alert" min="0" value="<?= htmlspecialchars((string)($partFormHasError ? ($_POST['min_stock_alert'] ?? '5') : '5'), ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="inv-form-actions inv-span-2"><button type="button" id="cancel-part-modal" class="inv-button inv-button-secondary">Cancel</button><button type="submit" class="inv-button inv-button-primary"><?= $icon('plus') ?> Add part</button></div>
            </form>
        </div>
    </div>
    <?php $invAjaxScript = __DIR__ . '/../../assets/js/admin_inventory_ajax.js'; ?>
    <script defer src="../../assets/js/admin_inventory_ajax.js?v=<?= is_file($invAjaxScript) ? (int)filemtime($invAjaxScript) : 1 ?>"></script>
    <script>
    document.addEventListener('DOMContentLoaded', () => {
        const sidebar = document.getElementById('main-sidebar');
        const backdrop = document.getElementById('mobile-sidebar-backdrop');
        const menuButton = document.getElementById('mobile-menu-toggle');
        const setMenuOpen = (open) => {
            sidebar.classList.toggle('is-open', open);
            backdrop.hidden = !open;
            menuButton.setAttribute('aria-expanded', String(open));
            menuButton.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
        };
        menuButton.addEventListener('click', () => setMenuOpen(!sidebar.classList.contains('is-open')));
        backdrop.addEventListener('click', () => setMenuOpen(false));
        matchMedia('(min-width: 768px)').addEventListener('change', event => { if (event.matches) setMenuOpen(false); });
        document.querySelectorAll('[data-dismiss-alert]').forEach(button => button.addEventListener('click', () => button.closest('.inv-alert').remove()));

        const partModal = document.getElementById('part-create-modal');
        const modalCard = partModal.querySelector('.inv-modal-card');
        const openPartButton = document.getElementById('open-part-modal');
        const mainContent = document.querySelector('main');
        let lastFocusedElement = openPartButton;
        const setModalOpen = open => {
            if (open && document.activeElement !== document.body && !partModal.contains(document.activeElement)) lastFocusedElement = document.activeElement;
            partModal.hidden = !open;
            mainContent.inert = open;
            sidebar.inert = open;
            openPartButton.setAttribute('aria-expanded', String(open));
            if (open) partModal.querySelector('#new-part-code').focus();
            else (lastFocusedElement?.isConnected ? lastFocusedElement : openPartButton).focus();
        };
        openPartButton.addEventListener('click', () => setModalOpen(true));
        document.getElementById('close-part-modal').addEventListener('click', () => setModalOpen(false));
        document.getElementById('cancel-part-modal').addEventListener('click', () => setModalOpen(false));
        partModal.addEventListener('click', event => { if (event.target === partModal) setModalOpen(false); });
        if (!partModal.hidden) setModalOpen(true);
        const searchInput = document.getElementById('topbar-search');
        const inventorySearchInput = document.getElementById('inventory-search');
        const topbarSearchForm = document.getElementById('inventory-topbar-search');
        const filterForm = document.getElementById('inventory-filters');
        searchInput.addEventListener('input', () => { inventorySearchInput.value = searchInput.value; });
        inventorySearchInput.addEventListener('input', () => { searchInput.value = inventorySearchInput.value; });
        topbarSearchForm.addEventListener('submit', () => {
            ['category', 'brand', 'stock'].forEach(name => {
                topbarSearchForm.elements.namedItem(name).value = filterForm.elements.namedItem(name).value;
            });
        });
        document.addEventListener('keydown', event => {
            if (event.key === 'Escape') {
                if (!partModal.hidden) setModalOpen(false);
                else if (sidebar.classList.contains('is-open')) { setMenuOpen(false); menuButton.focus(); }
            }
            if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k' && partModal.hidden) {
                event.preventDefault(); searchInput.focus(); searchInput.select();
            }
            if (partModal.hidden || event.key !== 'Tab') return;
            const focusable = Array.from(modalCard.querySelectorAll('button, input, select')).filter(element => !element.disabled && element.getClientRects().length);
            if (event.shiftKey && document.activeElement === focusable[0]) { event.preventDefault(); focusable.at(-1).focus(); }
            else if (!event.shiftKey && document.activeElement === focusable.at(-1)) { event.preventDefault(); focusable[0].focus(); }
        });
    });
    </script>
</body>
</html>
