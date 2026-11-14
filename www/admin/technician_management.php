<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
$staff=current_staff();if(!$staff || ($staff['role_name']??'')!=='Admin'){header('Location: ../auth/login.php');exit;}
$pdo=db();$csrf=csrf_token();$msg='';$error='';
function tm_e($s):string{return htmlspecialchars((string)$s,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
try {
 if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!csrf_valid($_POST['csrf']??null))throw new RuntimeException('Phiên đăng nhập hết hạn.');
  $action=(string)($_POST['action']??'');
  if(in_array($action,['approve','reject','present','absent'],true)){
   $id=(int)($_POST['id']??0);
   $pdo->beginTransaction();
   $stmt=$pdo->prepare('SELECT * FROM technician_shifts WHERE id=? FOR UPDATE');$stmt->execute([$id]);$shift=$stmt->fetch();
   if(!$shift)throw new RuntimeException('Không tìm thấy đăng ký ca.');
   if($action==='approve'){
    $guard=$pdo->prepare('SELECT id FROM users WHERE id=? FOR UPDATE');$guard->execute([$shift['technician_id']]);
    if($shift['status']!=='pending')throw new RuntimeException('Chỉ duyệt ca đang chờ.');
    $q=$pdo->prepare("SELECT COUNT(*) FROM technician_shifts WHERE technician_id=? AND work_date=? AND id<>? AND status='approved' AND (shift_type='full' OR ?='full' OR shift_type=?)");$q->execute([$shift['technician_id'],$shift['work_date'],$id,$shift['shift_type'],$shift['shift_type']]);
    if((int)$q->fetchColumn()>0)throw new RuntimeException('Trùng lịch đã được duyệt.');
    $pdo->prepare("UPDATE technician_shifts SET status='approved',reviewed_by=?,reviewed_at=NOW() WHERE id=?")->execute([(int)$staff['id'],$id]);
   } elseif($action==='reject'){
    if($shift['status']!=='pending')throw new RuntimeException('Chỉ từ chối ca đang chờ.');
    $pdo->prepare("UPDATE technician_shifts SET status='rejected',reviewed_by=?,reviewed_at=NOW() WHERE id=?")->execute([(int)$staff['id'],$id]);
   } else {
    if($shift['status']!=='approved' || $shift['work_date']>date('Y-m-d'))throw new RuntimeException('Chỉ chấm công cho ca đã duyệt, không ở tương lai.');
    $pdo->prepare('UPDATE technician_shifts SET attendance=? WHERE id=?')->execute([$action,$id]);
   }
   $pdo->commit();$msg='Đã cập nhật ca làm việc.';
  } elseif($action==='rate'){
   $technician=(int)($_POST['technician_id']??0);
   $day=filter_var($_POST['day_rate']??null,FILTER_VALIDATE_FLOAT);$bonus=filter_var($_POST['completed_order_bonus']??null,FILTER_VALIDATE_FLOAT);
   if($day===false||$bonus===false||$day<0||$bonus<0||$day>100000000||$bonus>100000000)throw new RuntimeException('Mức lương không hợp lệ.');
   $check=$pdo->prepare("SELECT COUNT(*) FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND r.name='Technician' AND u.is_active=1");$check->execute([$technician]);
   if(!$check->fetchColumn())throw new RuntimeException('Tài khoản kỹ thuật viên không hợp lệ.');
   $stmt=$pdo->prepare('INSERT INTO technician_pay_rates(technician_id,day_rate,completed_order_bonus,updated_by) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE day_rate=VALUES(day_rate), completed_order_bonus=VALUES(completed_order_bonus), updated_by=VALUES(updated_by)');
   $stmt->execute([$technician,$day,$bonus,$staff['id']]);$msg='Đã cập nhật mức lương tạm tính.';
  }
 }
 $technicians=$pdo->query("SELECT u.id,u.full_name,r.day_rate,r.completed_order_bonus FROM users u JOIN roles role ON role.id=u.role_id LEFT JOIN technician_pay_rates r ON r.technician_id=u.id WHERE role.name='Technician' AND u.is_active=1 ORDER BY u.full_name")->fetchAll();
 $shifts=$pdo->query('SELECT s.*,u.full_name FROM technician_shifts s JOIN users u ON u.id=s.technician_id ORDER BY FIELD(s.status,\'pending\',\'approved\',\'rejected\',\'cancelled\'),s.work_date DESC,s.id DESC LIMIT 200')->fetchAll();
} catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=$e instanceof RuntimeException?$e->getMessage():'Lỗi cơ sở dữ liệu. Hãy chạy file technician_upgrade.sql.';$technicians=$technicians??[];$shifts=$shifts??[];}
?>
<!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Quản lý kỹ thuật viên · REDLINE</title><link href="https://fonts.googleapis.com/css2?family=Barlow:wght@400;500;600;700&family=Barlow+Condensed:wght@700&display=swap" rel="stylesheet"><link rel="stylesheet" href="../assets/css/technician_full.css?v=<?=filemtime(__DIR__.'/../assets/css/technician_full.css')?>"></head>
<body class="tc-body"><main class="tc-main"><div class="tc-head"><div><p class="tc-eyebrow">REDLINE / ADMIN</p><h1>Duyệt lịch & quản lý lương kỹ thuật viên</h1></div><a class="tc-btn secondary" href="employees/list.php">← Nhân viên</a></div>
<?php if($msg):?><p class="tc-success"><?=tm_e($msg)?></p><?php endif;?><?php if($error):?><p class="tc-error"><?=tm_e($error)?></p><?php endif;?>
<section class="tc-panel"><h2>Thiết lập mức lương</h2><p class="tc-muted">Lương tính theo ngày công được xác nhận và thưởng trên mỗi đơn hoàn thành. Đổi mức lương sẽ thay đổi cách tính lại các kỳ trước; đây chưa phải bảng quyết toán.</p><div class="tc-tablewrap"><table class="tc-table"><thead><tr><th>Kỹ thuật viên</th><th>Lương một ngày</th><th>Thưởng mỗi đơn</th><th></th></tr></thead><tbody><?php foreach($technicians as $t):?><tr><td><?=tm_e($t['full_name'])?></td><td><input style="max-width:150px;background:#081a2c;color:white;padding:9px;border:1px solid #53667d;border-radius:9px" type="number" name="day_rate" form="rate-<?=(int)$t['id']?>" min="0" max="100000000" step="1000" required value="<?=tm_e($t['day_rate']??0)?>"></td><td><input style="max-width:150px;background:#081a2c;color:white;padding:9px;border:1px solid #53667d;border-radius:9px" type="number" name="completed_order_bonus" form="rate-<?=(int)$t['id']?>" min="0" max="100000000" step="1000" required value="<?=tm_e($t['completed_order_bonus']??0)?>"></td><td><form id="rate-<?=(int)$t['id']?>" method="post"><input type="hidden" name="csrf" value="<?=tm_e($csrf)?>"><input type="hidden" name="action" value="rate"><input type="hidden" name="technician_id" value="<?=(int)$t['id']?>"><button class="tc-btn">Lưu</button></form></td></tr><?php endforeach;?></tbody></table></div></section>
<section class="tc-panel"><h2>Đăng ký ca & xác nhận chấm công</h2><div class="tc-tablewrap"><table class="tc-table"><thead><tr><th>Nhân viên</th><th>Ngày</th><th>Ca</th><th>Trạng thái</th><th>Chấm công</th><th>Thao tác</th></tr></thead><tbody><?php foreach($shifts as $r):?><tr><td><?=tm_e($r['full_name'])?></td><td><?=tm_e(date('d/m/Y',strtotime($r['work_date'])))?></td><td><?=tm_e(['morning'=>'Sáng','afternoon'=>'Chiều','full'=>'Cả ngày'][$r['shift_type']]??$r['shift_type'])?></td><td><span class="tc-chip <?=tm_e($r['status'])?>"><?=tm_e($r['status'])?></span></td><td><span class="tc-chip <?=tm_e($r['attendance'])?>"><?=tm_e($r['attendance'])?></span></td><td class="tc-actions">
<?php $acts=$r['status']==='pending'?['approve'=>'Duyệt','reject'=>'Từ chối']:($r['status']==='approved'&&$r['work_date']<=date('Y-m-d')?['present'=>'Có mặt','absent'=>'Vắng mặt']:[]);foreach($acts as $action=>$label):?><form method="post"><input type="hidden" name="csrf" value="<?=tm_e($csrf)?>"><input type="hidden" name="action" value="<?=tm_e($action)?>"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><button class="tc-btn <?=in_array($action,['reject','absent'],true)?'secondary':''?>"><?=tm_e($label)?></button></form><?php endforeach; ?>
</td></tr><?php endforeach;?><?php if(!$shifts):?><tr><td colspan="6">Chưa có đăng ký ca.</td></tr><?php endif;?></tbody></table></div></section>
</main></body></html>
