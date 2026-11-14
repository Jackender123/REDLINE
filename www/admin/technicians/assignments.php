<?php
/** REDLINE Motorsport – Technician Assignments Console (Admin). */
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
$staff = current_staff();
if (!$staff || ($staff['role_name'] ?? '') !== 'Admin') {
    header('Location: ../../auth/login.php');
    exit;
}
$pdo = db();
function ta_e($value): string { return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$userName = (string)($staff['full_name'] ?? 'Administrator');
$initials = '';
foreach (array_slice(preg_split('/\s+/u', trim($userName)) ?: [], -2) as $word) {
    if ($word !== '') $initials .= mb_strtoupper(mb_substr($word, 0, 1));
}
if ($initials === '') $initials = 'AD';
$userDisplayRole = 'Administrator';
$techs = []; $orders = []; $loadError = '';
$selectedTechnician = filter_var($_GET['technician_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: 0;
try {
    $techs = $pdo->query("SELECT u.id, u.full_name, u.is_active, u.phone,
        COUNT(ro.id) AS active_jobs,
        COALESCE(SUM(ro.status = 'repairing'),0) AS repairing_jobs,
        COALESCE(SUM(ro.status = 'waiting_parts'),0) AS waiting_parts_jobs,
        COALESCE(SUM(ro.status = 'diagnosing'),0) AS diagnosing_jobs
      FROM users u
      INNER JOIN roles r ON r.id = u.role_id
      LEFT JOIN repair_orders ro ON ro.assigned_to = u.id
        AND ro.status NOT IN ('completed','delivered')
      WHERE r.name = 'Technician'
      GROUP BY u.id, u.full_name, u.is_active, u.phone
      ORDER BY u.is_active DESC, active_jobs ASC, u.full_name ASC")->fetchAll(PDO::FETCH_ASSOC);
    // Only filter by a technician known to the staff directory.
    $knownIds = array_map('intval', array_column($techs, 'id'));
    if ($selectedTechnician && !in_array($selectedTechnician, $knownIds, true)) $selectedTechnician = 0;
    $sql = "SELECT ro.id, ro.order_code, ro.status, ro.created_at, ro.expected_delivery,
                   ro.assigned_to, v.license_plate, v.brand, v.model,
                   u.full_name AS technician_name
            FROM repair_orders ro
            LEFT JOIN vehicles v ON v.id = ro.vehicle_id
            LEFT JOIN users u ON u.id = ro.assigned_to
            WHERE ro.status NOT IN ('completed','delivered')";
    // Load a bounded, common dataset; all client-side filters operate on this set.
    $sql .= ' ORDER BY (ro.assigned_to IS NULL) DESC, ro.created_at DESC, ro.id DESC LIMIT 500';
    $stmt = $pdo->query($sql);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $exception) {
    error_log('Technician assignments load failed: ' . $exception->getMessage());
    $loadError = 'Unable to load technician assignments. Please refresh and try again.';
}
$activeTechs = count(array_filter($techs, static fn(array $t): bool => (int)$t['is_active'] === 1));
$openJobCount = array_sum(array_map(static fn(array $t): int => (int)$t['active_jobs'], $techs));
$unassignedCount = count(array_filter($orders, static fn(array $o): bool => !$o['assigned_to']));
$iconMain = [
    ['../index.php','Overview','<rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="14" y="14" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/>',false],
    ['../orders/list.php','Repair orders','<rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/>',false],
    ['assignments.php','Technicians','<circle cx="12" cy="7" r="4"/><path d="M4 21v-2a8 8 0 0116 0v2"/>',true],
    ['../customers/list.php','Customers','<path d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"/>',false],
    ['../vehicles/list.php','Vehicles','<path d="M19 17h2c.6 0 1-.4 1-1v-3c0-.9-.7-1.7-1.5-1.9C18.7 10.6 16 10 16 10s-1.3-1.4-2.2-2.3c-.5-.4-1.1-.7-1.8-.7H5c-.6 0-1.1.4-1.4.9l-1.4 2.9A3.7 3.7 0 0 0 2 12v4c0 .6.4 1 1 1h2"/><circle cx="7" cy="17" r="2"/><path d="M9 17h6"/><circle cx="17" cy="17" r="2"/>',false],
    ['../appointments/list.php','Appointments','<rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/>',false],
    ['../invoices/list.php','Invoices','<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/>',false],
    ['../products/list.php','Inventory','<path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/>',false],
];
$iconSystem = [
    ['../employees/list.php','Staff &amp; Team','<path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656-.126-1.283-.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/>'],
    ['../technician_management.php','Shifts &amp; Payroll','<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M8 3v4M16 3v4M3 11h18"/>'],
    ['../settings/general.php','Settings','<path d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-.426 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><circle cx="12" cy="12" r="3"/>'],
];
$cssFile = __DIR__ . '/../../assets/css/technician_assignments.css';
$cssVersion = is_file($cssFile) ? substr(hash_file('sha256',$cssFile),0,12) : '1';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Technicians &amp; Assignments | REDLINE Motorsport ERP</title>
<script src="https://cdn.tailwindcss.com"></script>
<script>tailwind.config={theme:{extend:{colors:{red:{DEFAULT:'#EF4057',hover:'#D92F46'},navy:'#0B2E4E'},fontFamily:{display:['"Barlow Condensed"','sans-serif'],sans:['Barlow','sans-serif']}}}};</script>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700&family=Barlow:wght@400;500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="../../assets/css/technician_assignments.css?v=<?=ta_e($cssVersion)?>">
</head>
<body class="text-white font-sans antialiased h-screen overflow-hidden flex selection:bg-red selection:text-white tech-page">
<div id="mobile-sidebar-backdrop" onclick="toggleMobileSidebar()" class="fixed inset-0 bg-black/60 backdrop-blur-sm z-40 hidden md:hidden"></div>
<aside id="main-sidebar" class="sidebar w-64 shrink-0 fixed md:relative z-50 h-[calc(100%-2rem)] my-4 ml-4 py-5 px-4 overflow-y-auto transform -translate-x-full md:translate-x-0 transition-transform duration-300 ease-in-out flex flex-col justify-between">
<div class="space-y-7">
<a href="../index.php" class="flex items-center gap-3 px-1 hover:opacity-90 transition">
<span class="w-10 h-10 rounded-xl bg-red flex items-center justify-center shadow-lg shadow-red/30"><svg class="w-5 h-5 text-white" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94z"/></svg></span>
<span class="leading-none"><span class="block font-display text-2xl font-bold tracking-wider text-white">REDLINE</span><span class="block text-[10px] tracking-[.2em] text-white/80 mt-1">GARA MANAGEMENT</span></span>
</a>
<nav class="space-y-6">
<div><div class="text-[11px] font-semibold tracking-[.18em] text-white/75 px-3 mb-2">WORKSPACE</div><div class="space-y-1">
<?php foreach($iconMain as [$href,$label,$navSvg,$active]): ?>
<a href="<?=ta_e($href)?>" class="nav-item<?=$active?' active':''?>" <?=$active?'aria-current="page"':''?>><svg class="w-[18px] h-[18px] shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><?=$navSvg?></svg><span class="flex-1"><?=$label?></span><?php if($active): ?><span class="bg-red/80 text-white text-[10px] font-bold px-2 py-0.5 rounded-full"><?=count($techs)?></span><?php endif;?></a>
<?php endforeach; ?></div></div>
<div><div class="text-[11px] font-semibold tracking-[.18em] text-white/75 px-3 mb-2">SYSTEM</div><div class="space-y-1">
<?php foreach($iconSystem as [$href,$label,$navSvg]): ?><a href="<?=ta_e($href)?>" class="nav-item"><svg class="w-[18px] h-[18px] shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><?=$navSvg?></svg><span><?=$label?></span></a><?php endforeach; ?></div></div>
</nav></div>
<div class="pt-4 mt-6 border-t border-white/10 flex items-center justify-between gap-2">
<div class="flex items-center gap-3 min-w-0"><div class="w-9 h-9 rounded-full bg-white/15 border border-white/20 flex items-center justify-center font-semibold text-white text-xs shrink-0"><?=ta_e($initials)?></div><div class="min-w-0"><div class="text-[13px] font-semibold text-white truncate"><?=ta_e($userName)?></div><div class="text-[11px] text-white/80 truncate"><?=ta_e($userDisplayRole)?></div></div></div>
<a href="../../auth/logout.php" class="text-white/85 hover:text-white p-2 rounded-lg hover:bg-white/10 transition" title="Log out" aria-label="Log out"><svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg></a>
</div></aside>
<main class="flex-1 min-w-0 h-full overflow-y-auto p-5 lg:p-8 space-y-6">
<div class="flex items-center justify-between gap-4">
<div class="flex items-center gap-3 w-full max-w-xl"><button type="button" onclick="toggleMobileSidebar()" aria-label="Open menu" class="md:hidden text-white hover:text-red p-2 glass-dark transition"><svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg></button><div class="relative w-full"><span class="absolute inset-y-0 left-0 flex items-center pl-4 pointer-events-none text-white/60"><svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m16 16 5 5"/></svg></span><input id="topbar-search" type="search" class="glass-dark w-full pl-11 pr-20 py-2.5 text-sm placeholder-white/55 focus:outline-none focus:border-white/50" placeholder="Search technicians, repair orders, plates…" autocomplete="off"><kbd class="absolute inset-y-0 right-0 flex items-center pr-4 pointer-events-none text-[11px] text-white/50 font-sans">Ctrl K</kbd></div></div>
<a href="../orders/list.php" class="bg-red hover:bg-red-hover text-white font-display text-base font-semibold uppercase tracking-wider px-5 py-2.5 rounded-xl flex items-center gap-2 shadow-lg shadow-red/30 transition shrink-0"><svg class="w-4 h-4" fill="none" stroke="currentColor" stroke-width="2.4" viewBox="0 0 24 24"><path d="M12 4v16M4 12h16"/></svg>Repair Orders</a>
</div>
<?php if($loadError): ?><div class="glass p-4 text-rose-200 border-rose-400/40" role="alert"><?=ta_e($loadError)?></div><?php endif; ?>
<header class="flex flex-col sm:flex-row sm:items-end justify-between gap-4"><div><p class="text-xs font-semibold tracking-[.18em] text-white/60 mb-1"><?=ta_e(strtoupper(date('l')) . ' · ' . strtoupper(date('j F Y')))?></p><h1 class="font-display text-5xl font-bold tracking-tight text-white uppercase leading-none">Technicians &amp; Assignments<span class="text-red">.</span></h1><p class="text-sm text-white/70 mt-2">Monitor technician workload, view active repair jobs, and manage assignments.</p></div><span class="glass-dark inline-flex items-center gap-2 px-3.5 py-2 text-xs font-semibold self-start sm:self-auto"><span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span><span class="font-mono text-white/80 uppercase tracking-wider font-bold">SERVICE OPERATIONS</span></span></header>
<div class="grid grid-cols-1 sm:grid-cols-3 gap-5">
<div class="glass p-5"><div class="flex justify-between items-start"><span class="label">Active Technicians</span><svg class="w-5 h-5 text-white/60" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="12" cy="7" r="4"/><path d="M4 21v-2a8 8 0 0116 0v2"/></svg></div><div class="mt-4"><span class="font-display text-4xl font-bold"><?=$activeTechs?></span><p class="text-[11px] text-slate-300 mt-1 font-mono"><?=count($techs)?> technicians in directory</p></div></div>
<div class="glass p-5"><div class="flex justify-between items-start"><span class="label text-emerald-300">Assigned Open Jobs</span><svg class="w-5 h-5 text-emerald-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M3 5h18v16H3zM7 3v4M17 3v4M3 10h18"/></svg></div><div class="mt-4"><span class="font-display text-4xl font-bold text-emerald-300"><?=$openJobCount?></span><p class="text-[11px] text-slate-300 mt-1 font-mono">Assigned to technician accounts</p></div></div>
<div class="glass p-5"><div class="flex justify-between items-start"><span class="label text-rose-300">Unassigned Jobs</span><svg class="w-5 h-5 text-rose-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="m12 3 10 18H2zM12 9v5m0 4h.01"/></svg></div><div class="mt-4"><span class="font-display text-4xl font-bold text-rose-300"><?=$unassignedCount?></span><p class="text-[11px] text-slate-300 mt-1 font-mono">In the latest 500 open jobs</p></div></div>
</div>
<section class="glass p-5" aria-labelledby="directory-title"><div class="flex flex-wrap justify-between items-center gap-3 mb-4"><div><h2 id="directory-title" class="card-title">Technician Workload</h2><p class="text-xs text-white/65 mt-1">Select a technician to filter their open repair orders.</p></div><a class="glass-dark px-4 py-2 text-xs font-semibold hover:bg-white/20" href="../employees/list.php">Staff Directory</a></div><div class="tech-cards">
<?php if(!$techs): ?><p class="text-sm text-white/70">No technicians found.</p><?php endif; ?>
<?php foreach($techs as $t): ?>
<a href="?technician_id=<?=(int)$t['id']?>" class="tech-card<?= $selectedTechnician === (int)$t['id'] ? ' selected':'' ?>"><div class="flex items-center gap-3"><span class="tech-avatar"><?=ta_e(mb_strtoupper(mb_substr((string)$t['full_name'],0,1)))?></span><span class="min-w-0 flex-1"><strong class="block text-white truncate text-sm"><?=ta_e($t['full_name'])?></strong><small class="text-white/60 text-xs"><?= (int)$t['is_active'] ? 'Active account':'Inactive account' ?></small></span><span class="tech-job-number"><?=(int)$t['active_jobs']?></span></div><div class="tech-card-foot"><span><?=(int)$t['repairing_jobs']?> repairing</span><span><?=(int)$t['waiting_parts_jobs']?> waiting for parts</span></div></a>
<?php endforeach; ?></div></section>
<section aria-labelledby="jobs-title"><div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4 border-b border-white/15 pb-3"><div class="flex flex-wrap items-center gap-6 font-display uppercase tracking-wider text-sm" role="tablist" aria-label="Assignment status">
<button type="button" data-tab="ALL" class="tech-tab active">All <span id="count-all" class="tech-tab-count">0</span></button>
<button type="button" data-tab="unassigned" class="tech-tab">Unassigned <span id="count-unassigned" class="tech-tab-count">0</span></button>
<button type="button" data-tab="assigned" class="tech-tab">Assigned <span id="count-assigned" class="tech-tab-count">0</span></button></div>
<div class="w-full sm:w-72 relative"><span class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none text-white/50"><svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="6" cy="6" r="5"/><path d="m10 10 4 4"/></svg></span><input type="search" id="table-search" class="glass-input w-full pl-8 pr-3 py-1.5 text-xs placeholder-white/40 font-semibold" placeholder="Filter orders and technicians…"></div></div>
<div class="glass p-4 mt-4 mb-4 tech-filters"><label>Technician<select id="filter-technician" class="glass-input px-3 py-2"><option value="all">All technicians / unassigned</option><option value="unassigned">Unassigned only</option><?php foreach($techs as $t): ?><option value="<?=(int)$t['id']?>" <?= $selectedTechnician === (int)$t['id'] ? 'selected':'' ?>><?=ta_e($t['full_name'])?></option><?php endforeach;?></select></label><label>Repair status<select id="filter-status" class="glass-input px-3 py-2"><option value="all">All active statuses</option><option value="received">Received</option><option value="diagnosing">Diagnosing</option><option value="repairing">Repairing</option><option value="waiting_parts">Waiting for parts</option><option value="inspecting">Inspecting</option></select></label><button type="button" id="clear-filters" class="glass-dark px-4 py-2 text-xs font-semibold">Clear filters</button></div>
<div class="glass overflow-hidden"><div class="flex flex-wrap justify-between items-center gap-3 px-6 py-4 border-b border-white/15"><div><h2 id="jobs-title" class="card-title">Open Repair Orders</h2><p class="text-xs text-white/60 mt-1">Latest 500 open orders · Assign or reassign from order details.</p></div><span id="matching-count" class="text-xs text-white/70 font-mono"></span></div><div class="overflow-x-auto"><table class="tech-table w-full text-left border-collapse text-[13px]"><thead><tr class="label border-b border-white/15 bg-white/5"><th class="py-3.5 px-6">Repair Order &amp; Date</th><th class="py-3.5 px-4">Vehicle &amp; Plate</th><th class="py-3.5 px-4">Technician</th><th class="py-3.5 px-4">Status</th><th class="py-3.5 px-4">Expected Delivery</th><th class="py-3.5 px-6 text-center">Action</th></tr></thead><tbody id="orders-tbody" class="divide-y divide-white/10"></tbody></table></div></div>
<nav class="tech-pagination" aria-label="Repair order pages"><span id="page-info">0 repair orders</span><div class="tech-page-actions"><label for="page-size">Rows per page</label><select id="page-size" class="glass-input px-2 py-1"><option>10</option><option selected>20</option><option>50</option></select><button type="button" id="page-prev" class="glass-dark px-3 py-1.5">Previous</button><span id="page-number">1 / 1</span><button type="button" id="page-next" class="glass-dark px-3 py-1.5">Next</button></div></nav>
</section></main>
<script>
function toggleMobileSidebar(){document.getElementById('main-sidebar').classList.toggle('-translate-x-full');document.getElementById('mobile-sidebar-backdrop').classList.toggle('hidden');}
const rawOrders=<?=json_encode($orders,JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE)?>;
let tab='ALL',page=1;
const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const prettyStatus=s=>({'received':'Received','diagnosing':'Diagnosing','repairing':'Repairing','waiting_parts':'Waiting for parts','inspecting':'Inspecting'})[s]||s;
function render(){
 const phrase=document.getElementById('table-search').value.toLocaleLowerCase().trim();
 const tech=document.getElementById('filter-technician').value, status=document.getElementById('filter-status').value;
 const rows=rawOrders.filter(o=>{
 const a=o.assigned_to!==null && String(o.assigned_to)!=='0';
 if(tab==='assigned'&&!a || tab==='unassigned'&&a)return false;
 if(tech==='unassigned'&&a || tech!=='all'&&tech!=='unassigned'&&String(o.assigned_to)!==tech)return false;
 if(status!=='all'&&o.status!==status)return false;
 return [o.order_code,o.license_plate,o.brand,o.model,o.technician_name].some(v=>String(v??'').toLocaleLowerCase().includes(phrase));
 });
 const size=Number(document.getElementById('page-size').value)||20;
 const pages=Math.max(1,Math.ceil(rows.length/size));page=Math.min(page,pages);
 const start=(page-1)*size;
 document.getElementById('count-all').textContent=rawOrders.length;
 document.getElementById('count-assigned').textContent=rawOrders.filter(o=>o.assigned_to!==null&&String(o.assigned_to)!=='0').length;
 document.getElementById('count-unassigned').textContent=rawOrders.filter(o=>o.assigned_to===null||String(o.assigned_to)==='0').length;
 document.getElementById('matching-count').textContent=rows.length+' matches';
 document.getElementById('page-info').textContent=rows.length?`${start+1}–${Math.min(rows.length,start+size)} of ${rows.length} repair orders`:'0 repair orders';
 document.getElementById('page-number').textContent=`${page} / ${pages}`;
 document.getElementById('page-prev').disabled=page<=1;document.getElementById('page-next').disabled=page>=pages;
 document.getElementById('orders-tbody').innerHTML=rows.length?rows.slice(start,start+size).map(o=>{
 const vehicle=[o.brand,o.model].filter(Boolean).join(' ');
 const date=String(o.created_at||'').slice(0,10);
 const delivery=o.expected_delivery?esc(String(o.expected_delivery).slice(0,16)):'—';
 return `<tr class="hover:bg-white/10 transition border-t border-white/15"><td class="py-3.5 px-6"><span class="font-mono font-bold text-red text-sm">${esc(o.order_code)}</span><div class="text-xs text-slate-300 mt-1">${esc(date)}</div></td><td class="py-3.5 px-4"><span class="block text-white font-semibold">${esc(vehicle||'Vehicle')}</span><span class="text-xs text-slate-300 font-mono">${esc(o.license_plate||'N/A')}</span></td><td class="py-3.5 px-4">${o.technician_name?`<span class="text-white font-semibold">${esc(o.technician_name)}</span>`:'<span class="tech-badge unassigned">Unassigned</span>'}</td><td class="py-3.5 px-4"><span class="tech-badge ${esc(o.status)}">${esc(prettyStatus(o.status))}</span></td><td class="py-3.5 px-4 text-slate-200 text-xs font-mono">${delivery}</td><td class="py-3.5 px-6 text-center"><a href="../orders/detail.php?id=${encodeURIComponent(o.id)}" class="glass-dark inline-flex px-3 py-1.5 rounded-lg text-xs font-semibold hover:bg-white/20">View / Assign</a></td></tr>`;
 }).join(''):'<tr><td colspan="6" class="p-9 text-center text-slate-300">No matching repair orders found.</td></tr>';
}
document.querySelectorAll('[data-tab]').forEach(b=>b.addEventListener('click',()=>{tab=b.dataset.tab;page=1;document.querySelectorAll('[data-tab]').forEach(x=>x.classList.toggle('active',x===b));render();}));
['topbar-search','table-search','filter-technician','filter-status','page-size'].forEach(id=>document.getElementById(id).addEventListener(['filter-technician','filter-status','page-size'].includes(id)?'change':'input',e=>{if(id==='topbar-search')document.getElementById('table-search').value=e.target.value;if(id==='table-search')document.getElementById('topbar-search').value=e.target.value;page=1;render();}));
document.getElementById('clear-filters').addEventListener('click',()=>{document.getElementById('filter-technician').value='all';document.getElementById('filter-status').value='all';document.getElementById('topbar-search').value='';document.getElementById('table-search').value='';tab='ALL';page=1;document.querySelectorAll('[data-tab]').forEach(b=>b.classList.toggle('active',b.dataset.tab==='ALL'));render();});
document.getElementById('page-prev').addEventListener('click',()=>{page--;render();});document.getElementById('page-next').addEventListener('click',()=>{page++;render();});
document.addEventListener('keydown',e=>{if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='k'){e.preventDefault();document.getElementById('topbar-search').focus();}});
render();
</script></body></html>
