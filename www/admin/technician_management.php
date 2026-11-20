<?php
declare(strict_types=1);

// Respond to XMLHttpRequest before rendering any page content. The probe is
// intentionally authenticated and contains no private data or credentials.
const REDLINE_SHIFTS_AJAX_BUILD = '20261120-json-v2';
$tmIsPostAjax = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && (
    strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
    || str_contains(strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json')
    || (string) ($_POST['shift_ajax'] ?? '') === '1'
);
$tmIsHealthProbe = ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET'
    && (string) ($_GET['ajax_health'] ?? '') === '1';
$tmWantsJson = $tmIsPostAjax || $tmIsHealthProbe;
$tmInitialOutputLevel = ob_get_level();
if ($tmWantsJson) {
    // Do not allow warnings / incidental output from includes to corrupt JSON.
    ob_start();
    header('X-REDLINE-Shifts-Build: ' . REDLINE_SHIFTS_AJAX_BUILD);
}

function tm_json_reply(array $payload, int $status = 200): never
{
    global $tmInitialOutputLevel;
    while (ob_get_level() > $tmInitialOutputLevel) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('X-REDLINE-Shifts-Build: ' . REDLINE_SHIFTS_AJAX_BUILD);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

if ($tmWantsJson) {
    // Even unexpected PHP fatals should not masquerade as a successful HTML page.
    register_shutdown_function(static function () use ($tmInitialOutputLevel): void {
        $err = error_get_last();
        if (!$err || !in_array((int) ($err['type'] ?? 0),
            [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            return;
        }
        error_log('[REDLINE shifts AJAX fatal] ' . ($err['message'] ?? 'Unknown error'));
        while (ob_get_level() > $tmInitialOutputLevel) {
            ob_end_clean();
        }
        if (headers_sent()) {
            return;
        }
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => false, 'code' => 'SERVER_ERROR',
            'error' => 'PHP server error. Check hosting PHP error log.'], JSON_UNESCAPED_UNICODE);
    });
}

try {
    require_once __DIR__ . '/../includes/auth.php';
    $staff = current_staff();
    if (!$staff || ($staff['role_name'] ?? '') !== 'Admin') {
        if ($tmWantsJson) {
            tm_json_reply([
                'ok' => false,
                'code' => $staff ? 'FORBIDDEN' : 'LOGIN_REQUIRED',
                'error' => $staff
                    ? 'This account does not have Admin permission.'
                    : 'Your login session has expired. Sign in again before approving shifts.'
            ], $staff ? 403 : 401);
        }
        header('Location: ../auth/login.php');
        exit;
    }
    $pdo = db();
    $csrf = csrf_token();
} catch (Throwable $e) {
    error_log('[REDLINE shifts bootstrap] ' . $e->getMessage());
    if ($tmWantsJson) {
        tm_json_reply(['ok' => false, 'code' => 'SERVER_ERROR',
            'error' => 'The server could not load the shift module. Check the PHP error log and database configuration.'], 500);
    }
    throw $e;
}
if ($tmIsHealthProbe) {
    tm_json_reply(['ok' => true, 'build' => REDLINE_SHIFTS_AJAX_BUILD]);
}
if ($tmIsPostAjax && !in_array((string) ($_POST['action'] ?? ''),
    ['create_approved_shift', 'approve', 'reject', 'present', 'absent'], true)) {
    tm_json_reply(['ok' => false, 'code' => 'INVALID_ACTION',
        'error' => 'Unsupported AJAX shift action.'], 400);
}
$msg = '';
$error = '';
function tm_e($s):string{return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function tm_render_shift_rows(array $shifts, string $csrf): string {
 ob_start();
 foreach ($shifts as $r) {
  $actions = $r['status']==='pending'
   ? ['approve'=>'Approve','reject'=>'Reject']
   : ($r['status']==='approved' && $r['work_date']<=date('Y-m-d') ? ['present'=>'Present','absent'=>'Absent'] : []);
  ?>
  <tr data-shift-id="<?=(int)$r['id']?>">
   <td><?=tm_e($r['full_name'])?></td>
   <td><?=tm_e(date('d/m/Y',strtotime($r['work_date'])))?></td>
   <td><?=tm_e(['morning'=>'Morning','afternoon'=>'Afternoon','full'=>'Full day'][$r['shift_type']]??$r['shift_type'])?></td>
   <td><span class="tc-chip <?=tm_e($r['status'])?>"><?=tm_e($r['status'])?></span></td>
   <td><span class="tc-chip <?=tm_e($r['attendance'])?>"><?=tm_e($r['attendance'])?></span></td>
   <td class="tc-actions">
    <?php foreach($actions as $action=>$label): ?>
     <form method="post" data-ajax-shift>
      <input type="hidden" name="csrf" value="<?=tm_e($csrf)?>">
      <input type="hidden" name="action" value="<?=tm_e($action)?>">
      <input type="hidden" name="id" value="<?=(int)$r['id']?>">
      <button type="submit" class="tc-btn <?=in_array($action,['reject','absent'],true)?'secondary':''?>"><?=tm_e($label)?></button>
     </form>
    <?php endforeach; ?>
   </td>
  </tr>
  <?php
 }
 if (!$shifts) { echo '<tr><td colspan="6">No shift requests yet.</td></tr>'; }
 return (string)ob_get_clean();
}
try {
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!csrf_valid($_POST['csrf']??null))throw new RuntimeException('Your session has expired.');
  $action=(string)($_POST['action']??'');
  if($action==='create_approved_shift'){
   $techId=filter_var($_POST['technician_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
   $workDate=trim((string)($_POST['work_date']??''));
   $shiftType=(string)($_POST['shift_type']??'');
   $day=DateTimeImmutable::createFromFormat('!Y-m-d',$workDate);
   $today=new DateTimeImmutable('today');
   if(!$techId || !$day || $day->format('Y-m-d')!==$workDate || $day<$today || $day>$today->modify('+90 days') || !in_array($shiftType,['morning','afternoon','full'],true)){
    throw new RuntimeException('Choose an active technician, a valid shift and a date within the next 90 days.');
   }
   $pdo->beginTransaction();
   // Lock the technician row so simultaneous admin requests cannot approve conflicting shifts.
   $check=$pdo->prepare("SELECT u.id FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.is_active=1 AND r.name='Technician' FOR UPDATE");
   $check->execute([$techId]);
   if(!$check->fetchColumn())throw new RuntimeException('Select an active technician.');
   $conflicts=$pdo->prepare("SELECT id FROM technician_shifts WHERE technician_id=? AND work_date=? AND status='approved' AND (shift_type='full' OR ?='full' OR shift_type=?) LIMIT 1");
   $conflicts->execute([$techId,$workDate,$shiftType,$shiftType]);
   if($conflicts->fetchColumn())throw new RuntimeException('An overlapping approved shift already exists for this technician and date.');
   $insert=$pdo->prepare("INSERT INTO technician_shifts(technician_id,work_date,shift_type,note,status,reviewed_by,reviewed_at) VALUES(?,?,?,'Registered and approved by administrator','approved',?,NOW())");
   $insert->execute([$techId,$workDate,$shiftType,(int)$staff['id']]);
   $pdo->commit();
   $msg='Approved technician shift registered. Return to the repair order and refresh its assignment status.';
  } elseif(in_array($action,['approve','reject','present','absent'],true)){

   $id=(int)($_POST['id']??0);
   $pdo->beginTransaction();
   $stmt=$pdo->prepare('SELECT * FROM technician_shifts WHERE id=? FOR UPDATE');$stmt->execute([$id]);$shift=$stmt->fetch();
   if(!$shift)throw new RuntimeException('Shift request not found.');
   if($action==='approve'){
    $guard=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$guard->execute([$shift['technician_id']]);
    if($shift['status']!=='pending')throw new RuntimeException('Only pending shifts can be approved.');
    $q=$pdo->prepare("SELECT COUNT(*) FROM technician_shifts WHERE technician_id=? AND work_date=? AND id<>? AND status='approved' AND (shift_type='full' OR ?='full' OR shift_type=?)");$q->execute([$shift['technician_id'],$shift['work_date'],$id,$shift['shift_type'],$shift['shift_type']]);
    if((int)$q->fetchColumn()>0)throw new RuntimeException('An overlapping approved shift already exists.');
    $pdo->prepare("UPDATE technician_shifts SET status='approved',reviewed_by=?,reviewed_at=NOW() WHERE id=?")->execute([(int)$staff['id'],$id]);
   } elseif($action==='reject'){
    if($shift['status']!=='pending')throw new RuntimeException('Only pending shifts can be rejected.');
    $pdo->prepare("UPDATE technician_shifts SET status='rejected',reviewed_by=?,reviewed_at=NOW() WHERE id=?")->execute([(int)$staff['id'],$id]);
   } else {
    if($shift['status']!=='approved' || $shift['work_date']>date('Y-m-d'))throw new RuntimeException('Attendance requires an approved shift on or before today.');
    $pdo->prepare('UPDATE technician_shifts SET attendance=? WHERE id=?')->execute([$action,$id]);
   }
   $pdo->commit();$msg='Shift updated successfully.';
  } elseif($action==='rate'){
   $technician=(int)($_POST['technician_id']??0);
   $day=filter_var($_POST['day_rate']??null,FILTER_VALIDATE_FLOAT);$bonus=filter_var($_POST['completed_order_bonus']??null,FILTER_VALIDATE_FLOAT);
   if($day===false||$bonus===false||$day<0||$bonus<0||$day>100000000||$bonus>100000000)throw new RuntimeException('Invalid pay rate.');
   $check=$pdo->prepare("SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND r.name='Technician' AND u.is_active=1");$check->execute([$technician]);
   if(!$check->fetchColumn())throw new RuntimeException('Invalid technician account.');
   $stmt=$pdo->prepare('INSERT INTO technician_pay_rates(technician_id,day_rate,completed_order_bonus,updated_by) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE day_rate=VALUES(day_rate), completed_order_bonus=VALUES(completed_order_bonus), updated_by=VALUES(updated_by)');
   $stmt->execute([$technician,$day,$bonus,$staff['id']]);$msg='Estimated pay rates updated.';
  }
 }
} catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=$e instanceof RuntimeException?$e->getMessage():'Database error. Check the technician_upgrade.sql migration.';}
$shiftAjax = $tmIsPostAjax;
// Always reload the current data, including after a rejected POST.
$technicians=[];$shifts=[];
try {
 $technicians=$pdo->query("SELECT u.id,u.full_name,r.day_rate,r.completed_order_bonus FROM users u JOIN roles role ON role.id=u.role_id LEFT JOIN technician_pay_rates r ON r.technician_id=u.id WHERE role.name='Technician' AND u.is_active=1 ORDER BY u.full_name")->fetchAll();
 $shifts=$pdo->query('SELECT s.*,u.full_name FROM technician_shifts s JOIN users u ON u.id=s.technician_id ORDER BY FIELD(s.status,\'pending\',\'approved\',\'rejected\',\'cancelled\'),s.work_date DESC,s.id DESC LIMIT 200')->fetchAll();
} catch(Throwable $e){
 if($pdo->inTransaction()) $pdo->rollBack();
 error_log('Shift data load failed: '.$e->getMessage());
 $error=trim($error.' Unable to load technician or shift data. Please refresh the page.');
}
if ($shiftAjax) {
 if ($error !== '') {
  tm_json_reply([
   'ok' => false,
   'code' => str_contains($error, 'session has expired') ? 'CSRF_EXPIRED' : 'SHIFT_ERROR',
   'error' => $error,
  ], str_contains($error, 'session has expired') ? 403 : 422);
 }
 tm_json_reply([
  'ok' => true,
  'build' => REDLINE_SHIFTS_AJAX_BUILD,
  'message' => $msg,
  'rows_html' => tm_render_shift_rows($shifts, $csrf),
  'stats' => [
   'pending' => count(array_filter($shifts, static fn($s): bool => $s['status'] === 'pending')),
   'approved_today' => count(array_filter($shifts, static fn($s): bool => $s['status'] === 'approved' && $s['work_date'] === date('Y-m-d'))),
  ],
 ]);
}
?>
<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Shifts &amp; Payroll · REDLINE</title><link href="https://fonts.googleapis.com/css2?family=Barlow:wght@400;500;600;700&family=Barlow+Condensed:wght@700&display=swap" rel="stylesheet"><link rel="stylesheet" href="../assets/css/admin_unified_sidebar.css?v=<?= (int)filemtime(__DIR__.'/../assets/css/admin_unified_sidebar.css') ?>"><link rel="stylesheet" href="../assets/css/admin_shifts_payroll.css?v=<?=filemtime(__DIR__.'/../assets/css/admin_shifts_payroll.css')?>"></head>
<body class="tc-body rlu-payroll-theme">
<?php $adminActive = 'technician_management.php'; require __DIR__ . '/../includes/admin_unified_sidebar.php'; ?>
<main class="tc-main">
<div class="tc-toolbar" aria-label="Workforce navigation"><span class="tc-toolbar-title">WORKFORCE OPERATIONS</span><a class="tc-btn secondary" href="technicians/assignments.php">← Technicians</a></div>
<header class="tc-head"><div><p class="tc-eyebrow">REDLINE / ADMIN</p><h1>SHIFTS &amp; PAYROLL<span>.</span></h1><p class="tc-muted">Manage technician availability, attendance and estimated pay.</p></div></header>
<div class="rl-stats" aria-label="Shift overview"><div><small>ACTIVE TECHNICIANS</small><strong><?=count($technicians)?></strong></div><div><small>PENDING IN DISPLAYED RECORDS</small><strong id="shifts-pending-count"><?=count(array_filter($shifts,static function($s){return $s['status']==='pending';}))?></strong></div><div><small>APPROVED TODAY</small><strong id="shifts-approved-today-count"><?=count(array_filter($shifts,static function($s){return $s['status']==='approved' && $s['work_date']===date('Y-m-d');}))?></strong></div></div>
<?php if($msg):?><p class="tc-success"><?=tm_e($msg)?></p><?php endif;?><?php if($error):?><p class="tc-error"><?=tm_e($error)?></p><?php endif;?>
<p id="shift-ajax-feedback" class="tc-success" role="status" aria-live="polite" hidden style="position:fixed;right:20px;bottom:20px;z-index:9999;max-width:min(440px,calc(100vw - 40px));box-shadow:0 12px 32px rgba(0,0,0,.45)"></p>
<section class="tc-panel"><h2>Pay Rates</h2><p class="tc-muted">Estimated pay is based on confirmed attendance and completed-order bonuses. Rate changes affect past estimates; this is not a finalized payroll statement.</p><div class="tc-tablewrap"><table class="tc-table"><thead><tr><th>Technician</th><th>Daily rate (VND)</th><th>Order bonus (VND)</th><th></th></tr></thead><tbody><?php foreach($technicians as $t):?><tr><td><?=tm_e($t['full_name'])?></td><td><input style="max-width:150px;background:#081a2c;color:white;padding:9px;border:1px solid #53667d;border-radius:9px" type="number" name="day_rate" form="rate-<?=(int)$t['id']?>" min="0" max="100000000" step="1000" required value="<?=tm_e($t['day_rate']??0)?>"></td><td><input style="max-width:150px;background:#081a2c;color:white;padding:9px;border:1px solid #53667d;border-radius:9px" type="number" name="completed_order_bonus" form="rate-<?=(int)$t['id']?>" min="0" max="100000000" step="1000" required value="<?=tm_e($t['completed_order_bonus']??0)?>"></td><td><form id="rate-<?=(int)$t['id']?>" method="post"><input type="hidden" name="csrf" value="<?=tm_e($csrf)?>"><input type="hidden" name="action" value="rate"><input type="hidden" name="technician_id" value="<?=(int)$t['id']?>"><button class="tc-btn">Save</button></form></td></tr><?php endforeach;?></tbody></table></div></section>
<section class="tc-panel" id="register-shift">
<h2>Register Approved Shift</h2><p class="tc-muted">Admin can register a verified shift for a technician. This removes the shift warning on Repair Orders for that date. Existing approved shifts cannot overlap.</p>
<form method="post" data-ajax-register style="display:flex;flex-wrap:wrap;gap:12px;align-items:end">
<input type="hidden" name="csrf" value="<?=tm_e($csrf)?>"><input type="hidden" name="action" value="create_approved_shift">
<label>Technician<br><select name="technician_id" required style="background:#081a2c;color:#fff;padding:10px;border:1px solid #53667d;border-radius:9px"><option value="">Select technician</option><?php foreach($technicians as $tech):?><option value="<?=(int)$tech['id']?>" <?= (int)($_GET['technician_id']??0)===(int)$tech['id']?'selected':'' ?>><?=tm_e($tech['full_name'])?></option><?php endforeach;?></select></label>
<label>Work date<br><input type="date" name="work_date" value="<?=tm_e(date('Y-m-d'))?>" min="<?=tm_e(date('Y-m-d'))?>" max="<?=tm_e(date('Y-m-d',strtotime('+90 days')) )?>" required style="background:#081a2c;color:#fff;padding:10px;border:1px solid #53667d;border-radius:9px"></label>
<label>Shift<br><select name="shift_type" required style="background:#081a2c;color:#fff;padding:10px;border:1px solid #53667d;border-radius:9px"><option value="full">Full day</option><option value="morning">Morning</option><option value="afternoon">Afternoon</option></select></label>
<button class="tc-btn" type="submit">Register Approved Shift</button>
</form></section>
<section class="tc-panel"><h2>Shift Registrations &amp; Attendance</h2><div class="tc-tablewrap"><table class="tc-table" id="shift-registrations"><thead><tr><th>Technician</th><th>Date</th><th>Shift</th><th>Status</th><th>Attendance</th><th>Actions</th></tr></thead><tbody><?=tm_render_shift_rows($shifts,$csrf)?></tbody></table></div></section>
</main><script src="../assets/js/admin_shifts_ajax.js?v=<?=filemtime(__DIR__.'/../assets/js/admin_shifts_ajax.js')?>" defer></script></body></html>
