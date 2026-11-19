<?php
/** Shared Admin navigation for pages hosted directly under /admin/. */
if (!isset($staff) || !is_array($staff) || ($staff['role_name'] ?? '') !== 'Admin') {
    http_response_code(403);
    exit('Administrator access required.');
}
$rluActive = (string)($adminActive ?? '');
$rluNav = [
    'WORKSPACE' => [
        ['index.php','Overview','grid'],
        ['technicians/assignments.php','Technicians','users'],
        ['customers/list.php','Customers','users-round'],
        ['appointments/list.php','Appointments','calendar'],
        ['invoices/list.php','Invoices','receipt'],
        ['products/list.php','Inventory','box'],
    ],
    'SYSTEM' => [
        ['technician_management.php','Shifts & Payroll','clock'],
        ['settings/general.php','Settings','settings'],
    ],
];
$rluIcons = [
    'grid'=>'<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>',
    'activity'=>'<path d="M3 7h18M3 12h18M3 17h18"/>',
    'users'=>'<circle cx="12" cy="7" r="4"/><path d="M4 21v-2a8 8 0 0 1 16 0v2"/>',
    'users-round'=>'<path d="M16 20v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 20v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/>',
    'car'=>'<path d="M5 17h14M3 17V9l3-5h12l3 5v8M3 10h18"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/>',
    'calendar'=>'<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',
    'receipt'=>'<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8"/>',
    'box'=>'<path d="m12 2 9 5-9 5-9-5 9-5zM3 7v10l9 5 9-5V7M12 12v10"/>',
    'staff'=>'<path d="M17 20h5v-2a3 3 0 0 0-5-3M17 20H7M7 20H2v-2a3 3 0 0 1 5-3M12 14a5 5 0 1 0 0-10 5 5 0 0 0 0 10z"/>',
    'clock'=>'<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 11h18"/>',
    'settings'=>'<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.93 4.93l2.12 2.12M16.95 16.95l2.12 2.12M2 12h3M19 12h3M4.93 19.07l2.12-2.12M16.95 7.05l2.12-2.12"/>',
];
$rluEscape = static function($v): string { return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
$rluName = trim((string)($staff['full_name'] ?? 'Admin'));
$rluInitial = function_exists('mb_substr') ? mb_strtoupper(mb_substr($rluName, 0, 1)) : strtoupper(substr($rluName, 0, 1));
?>
<button type="button" class="rlu-mobile-toggle" id="rlu-mobile-toggle" aria-controls="rlu-sidebar" aria-expanded="false" aria-label="Toggle admin menu">☰ Menu</button>
<div class="rlu-overlay" id="rlu-overlay" aria-hidden="true"></div>
<aside class="rlu-sidebar" id="rlu-sidebar" aria-label="Admin navigation">
 <a class="rlu-brand" href="index.php"><span class="rlu-logo" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94z"/></svg></span><span class="rlu-brand-text">REDLINE<small>GARA MANAGEMENT</small></span></a>
 <nav class="rlu-navigation" aria-label="Main menu">
 <?php foreach ($rluNav as $rluGroup => $rluLinks): ?>
  <div class="rlu-nav-group"><div class="rlu-nav-heading"><?= $rluEscape($rluGroup) ?></div>
  <?php foreach ($rluLinks as [$rluUrl,$rluLabel,$rluIcon]): ?>
   <a class="rlu-nav-item<?= $rluActive === $rluUrl ? ' active' : '' ?>" href="<?= $rluEscape($rluUrl) ?>"<?= $rluActive === $rluUrl ? ' aria-current="page"' : '' ?>><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><?= $rluIcons[$rluIcon] ?></svg><span><?= $rluEscape($rluLabel) ?></span></a>
  <?php endforeach; ?></div>
 <?php endforeach; ?>
 </nav>
 <div class="rlu-user"><span class="rlu-avatar"><?= $rluEscape($rluInitial) ?></span><span class="rlu-user-text"><strong><?= $rluEscape($rluName) ?></strong><small>Administrator</small></span><a href="../auth/logout.php" aria-label="Sign out" title="Sign out"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 16l4-4m0 0-4-4m4 4H7m6 4v1a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3V7a3 3 0 0 1 3-3h4a3 3 0 0 1 3 3v1"/></svg></a></div>
</aside>
<script>
(function () {
  const trigger=document.getElementById('rlu-mobile-toggle');
  const sidebar=document.getElementById('rlu-sidebar');
  const overlay=document.getElementById('rlu-overlay');
  if (!trigger || !sidebar || !overlay) return;
  function setOpen(open){sidebar.classList.toggle('rlu-open',open);overlay.classList.toggle('rlu-show',open);trigger.setAttribute('aria-expanded',open?'true':'false');}
  trigger.addEventListener('click',()=>setOpen(!sidebar.classList.contains('rlu-open')));
  overlay.addEventListener('click',()=>setOpen(false));
  document.addEventListener('keydown',(e)=>{if(e.key==='Escape')setOpen(false);});
})();
</script>
