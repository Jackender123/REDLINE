<?php
/** Admin-only, read-only deployment and repair order assignment diagnostics. */
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
header('Cache-Control: no-store, private');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
header('X-REDLINE-Assignment-Build: 20261119-a3');
$admin = current_staff();
if (!$admin || ($admin['role_name'] ?? '') !== 'Admin') {
    http_response_code(403);
    exit('Admin login is required to inspect assignments.');
}
function rl_diag_e(mixed $value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function rl_diag_exists(PDO $pdo, string $table, string $column): bool {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}
function rl_diag_file_hash(string $file): string {
    return is_file($file) ? substr((string)hash_file('sha256', $file), 0, 12) : 'MISSING';
}
$input = trim((string)($_GET['order'] ?? 'RO-639BF94065A9'));
$input = mb_substr($input, 0, 50);
$checks = [];
$order = null;
$sessions = [];
$events = [];
$dbLabel = '';
$problem = '';
try {
    $pdo = db();
    $dbLabel = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    foreach ([
        ['repair_order_work_sessions','id'],
        ['repair_order_items','work_session_id'],
        ['part_requests','work_session_id'],
    ] as [$table, $column]) {
        $ok = rl_diag_exists($pdo, $table, $column);
        $checks[] = [$table . '.' . $column, $ok, $ok ? 'Ready' : 'Missing migration — import 20261119_technician_work_sessions.sql'];
    }
    if ($input !== '') {
        $select = "SELECT ro.id, ro.order_code, ro.status, ro.assigned_to, ro.technician_id, ro.service_request_id,
            u.full_name AS assigned_name, u.is_active AS assigned_active, r.name AS assigned_role
            FROM repair_orders ro LEFT JOIN users u ON u.id = ro.assigned_to
            LEFT JOIN roles r ON r.id = u.role_id WHERE ro.order_code = ?";
        $stmt = $pdo->prepare($select);
        $stmt->execute([$input]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$order && ctype_digit($input)) {
            $stmt = $pdo->prepare(str_replace('WHERE ro.order_code = ?', 'WHERE ro.id = ?', $select));
            $stmt->execute([(int)$input]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        if ($order && $checks[0][1]) {
            $stmt = $pdo->prepare('SELECT s.id, s.technician_id, u.full_name, s.started_at, s.completed_at, s.completion_type
                FROM repair_order_work_sessions s LEFT JOIN users u ON u.id = s.technician_id
                WHERE s.repair_order_id = ? ORDER BY s.id DESC LIMIT 10');
            $stmt->execute([(int)$order['id']]);
            $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
        if ($order) {
            $stmt = $pdo->prepare('SELECT h.created_at, h.to_status, h.note FROM order_status_history h
                WHERE h.order_id = ? ORDER BY h.id DESC LIMIT 5');
            $stmt->execute([(int)$order['id']]);
            $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (Throwable $e) {
    error_log('Admin assignment diagnostic failed: ' . $e->getMessage());
    $problem = 'Database check could not finish. Consult the hosting PHP error log.';
}
$files = [
    'api/orders/approve.php' => __DIR__ . '/../../api/orders/approve.php',
    'admin/orders/detail.php' => __DIR__ . '/../orders/detail.php',
    'admin/appointments/list.php' => __DIR__ . '/list.php',
    'assets/js/admin-appointments-unified.js' => __DIR__ . '/../../assets/js/admin-appointments-unified.js',
    'employees/index.php' => __DIR__ . '/../../employees/index.php',
];
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>REDLINE · Admin Assignment Check</title>
<style>
body{font:15px system-ui,sans-serif;max-width:1000px;margin:32px auto;padding:0 20px;color:#e2e8f0;background:#0f172a}
h1{font-size:1.7rem}h2{margin-top:32px;font-size:1.2rem}
section{background:#17243a;border:1px solid #445166;border-radius:14px;padding:20px;margin:18px 0}
table{width:100%;border-collapse:collapse;overflow-wrap:anywhere}td,th{text-align:left;vertical-align:top;padding:10px;border-bottom:1px solid #344257}
label{display:block;margin:10px 0}input{padding:10px;background:#0f172a;color:white;border:1px solid #64748b;border-radius:8px;min-width:230px}
button{padding:10px 15px;color:white;background:#dc304e;border:0;border-radius:8px;cursor:pointer}
.good{color:#6ee7b7}.bad{color:#fda4af}.muted{color:#94a3b8}.mono{font-family:ui-monospace,monospace}a{color:#93c5fd}code{color:#fcd34d}
</style></head><body>
<h1>Repair Assignment · Deployment Check</h1>
<p>Build <strong class="mono">20261119-a3</strong> · Admin-only, read-only · Live database <strong><?= rl_diag_e($dbLabel ?: '(unavailable)') ?></strong></p>
<form method="get"><label for="order">Repair order code or database ID</label>
<input id="order" name="order" value="<?= rl_diag_e($input) ?>" maxlength="50" required>
<button type="submit">Check order</button></form>
<?php if ($problem): ?><section class="bad" role="alert"><?= rl_diag_e($problem) ?></section><?php endif; ?>
<section><h2>1. Database migration</h2><table><thead><tr><th>Required field</th><th>Result</th><th>Explanation</th></tr></thead><tbody>
<?php foreach ($checks as [$field,$ok,$detail]): ?><tr><td class="mono"><?= rl_diag_e($field) ?></td>
<td class="<?= $ok ? 'good':'bad' ?>"><?= $ok ? 'OK':'MISSING' ?></td><td><?= rl_diag_e($detail) ?></td></tr><?php endforeach; ?>
<?php if (!$checks): ?><tr><td colspan="3">Database migration check unavailable.</td></tr><?php endif; ?>
</tbody></table></section>
<section><h2>2. Exact repair order</h2>
<?php if ($order): ?>
<table>
<tr><th>Order</th><td class="mono"><?= rl_diag_e($order['order_code']) ?> (#<?= (int)$order['id'] ?>)</td></tr>
<tr><th>Status stored in database</th><td><?= rl_diag_e($order['status']) ?></td></tr>
<tr><th>Assigned technician (source of truth)</th><td class="<?= $order['assigned_to'] ? 'good':'bad' ?>">
<?= $order['assigned_to'] ? rl_diag_e($order['assigned_name'] ?: 'Account missing') . ' (#' . (int)$order['assigned_to'] . ')' : 'UNASSIGNED (NULL)' ?></td></tr>
<tr><th>Technician account</th><td><?= $order['assigned_to'] ? rl_diag_e($order['assigned_role'] ?: 'No role') . ' · ' . ((int)$order['assigned_active'] ? 'Active' : 'Inactive') : 'Not selected yet' ?></td></tr>
<tr><th>Legacy technician_id</th><td><?= (int)$order['technician_id'] ?: 'NULL / 0' ?></td></tr>
<tr><th>Booking ID</th><td><?= (int)$order['service_request_id'] ?: 'None' ?></td></tr>
</table>
<?php if (!$order['assigned_to']): ?>
<p class="bad"><strong>The database did not record a technician.</strong> Open Repair Order Detail, select an active Technician and click Approve &amp; Assign Technician. If the form displays an error, fix that cause first. Do not manually edit assigned_to.</p>
<?php elseif ($order['assigned_role'] !== 'Technician' || !(int)$order['assigned_active']): ?>
<p class="bad">The assigned account is not an active Technician; it will not have normal technician workspace access.</p>
<?php else: ?><p class="good">The database contains an active technician. Log in as this specific account and open <code>/employees/index.php?view=jobs</code>.</p><?php endif; ?>
<?php else: ?><p class="bad">No repair order matches this code in the database used by this site. Confirm the order code or hosting database connection.</p><?php endif; ?>
</section>
<section><h2>3. Work sessions</h2>
<?php if ($sessions): ?><table><tr><th>Session</th><th>Technician</th><th>Started</th><th>Ended</th></tr>
<?php foreach ($sessions as $s): ?><tr><td>#<?= (int)$s['id'] ?></td><td><?= rl_diag_e($s['full_name']) ?> (#<?= (int)$s['technician_id'] ?>)</td>
<td><?= rl_diag_e($s['started_at']) ?></td><td><?= rl_diag_e($s['completed_at'] ?? 'Active') ?></td></tr><?php endforeach; ?></table>
<?php else: ?><p class="muted">No work sessions returned. An approved new assignment should have an active session.</p><?php endif; ?></section>
<section><h2>4. Assignment history</h2>
<?php if ($events): ?><table><tr><th>Time</th><th>Status</th><th>Action</th></tr>
<?php foreach ($events as $event): ?><tr><td><?= rl_diag_e($event['created_at']) ?></td>
<td><?= rl_diag_e($event['to_status']) ?></td><td><?= rl_diag_e(mb_substr((string)$event['note'], 0, 350)) ?></td></tr><?php endforeach; ?></table>
<?php else: ?><p class="muted">No status events returned.</p><?php endif; ?></section>
<section><h2>5. Live server files</h2><p class="muted">These are the actual files loaded on the hosting server, not files on your computer. Compare after uploading the package.</p>
<table><tr><th>File</th><th>SHA-256 prefix</th><th>Modified time</th></tr>
<?php foreach ($files as $name=>$path): ?><tr><td class="mono"><?= rl_diag_e($name) ?></td><td class="mono"><?= rl_diag_e(rl_diag_file_hash($path)) ?></td>
<td><?= is_file($path) ? rl_diag_e(date('Y-m-d H:i:s', (int)filemtime($path))) : 'MISSING' ?></td></tr><?php endforeach; ?></table></section>
<p><a href="list.php">Return to Appointments</a></p>
</body></html>
