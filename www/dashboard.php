<?php
/**
 * Redline — Customer Portal (dashboard.php)
 * Fixed version:
 *  - helper functions are guarded with function_exists() so they no longer clash with src/includes/auth.php
 *  - real DB data is kept in $dbVehicles / $dbOrders and mapped into the shapes the renderers expect
 *  - user name / email / phone come from the logged-in user instead of being hardcoded
 * Navigate with ?page=overview|vehicles|appointments|orders|invoices|fabrication|profile|support
 */

require_once __DIR__ . '/src/includes/auth.php';

$user = require_login();

/* ---------- Helpers (guarded to avoid "Cannot redeclare" errors) ---------- */

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

$stmt = db()->prepare('SELECT id, make, model, year, plate FROM portal_vehicles WHERE user_id = :uid ORDER BY id DESC');
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

$status_labels = [
    'estimate'    => 'Estimate ready',
    'approved'    => 'Approved',
    'in_progress' => 'In progress',
    'ready'       => 'Ready for pickup',
];
$status_tones = [
    'estimate'    => 'cyan',
    'approved'    => 'cyan',
    'in_progress' => 'amber',
    'ready'       => 'green',
];

/* ---------- Current user (adjust keys to match your users table) ---------- */

$userName  = trim((string) ($user['name'] ?? $user['full_name'] ?? $user['username'] ?? 'Customer'));
$userEmail = (string) ($user['email'] ?? '');
$userPhone = (string) ($user['phone'] ?? '');
$firstName = explode(' ', $userName)[0];
$initials  = '';
foreach (array_slice(preg_split('/\s+/', $userName) ?: [], 0, 2) as $part) {
    $initials .= mb_strtoupper(mb_substr($part, 0, 1));
}
$initials = $initials ?: 'U';

$profile = ['name' => $userName, 'email' => $userEmail, 'phone' => $userPhone, 'initials' => $initials];

/* ---------- Data mapped into the shapes the renderers expect ---------- */

$vehicles = array_map(function ($v) {
    return [
        'model'   => trim(($v['make'] ?? '') . ' ' . ($v['model'] ?? '')),
        'year'    => (string) ($v['year'] ?? ''),
        'plate'   => (string) ($v['plate'] ?? ''),
        'vin'     => (string) ($v['vin'] ?? ''),
        'km'      => '—',
        'service' => '—',
        'primary' => false,
    ];
}, $dbVehicles);
if ($vehicles) {
    $vehicles[0]['primary'] = true;
}

$orders = array_map(function ($o) use ($status_labels, $status_tones) {
    $ts = strtotime((string) ($o['updated_at'] ?? '')) ?: time();
    return [
        '#RO-' . $o['id'],
        trim(($o['make'] ?? '') . ' ' . ($o['model'] ?? '')),
        $status_labels[$o['status']] ?? (string) $o['status'],
        date('M j, Y', $ts),
        (int) ($o['estimate'] ?? 0),
        $status_tones[$o['status']] ?? 'cyan',
    ];
}, $dbOrders);

/* Invoices have no DB table yet, so these stay as demo data. */
$invoices = [
    ['#HD-25112', 'Nov 24, 2025', 'BMW 330i M Sport',     'Unpaid', 12850000, 'red'],
    ['#HD-25091', 'Aug 12, 2025', 'Ford Ranger Wildtrak', 'Paid',   4260000,  'green'],
    ['#HD-25072', 'Mar 3, 2025',  'BMW 330i M Sport',     'Paid',   1850000,  'green'],
];

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

/* ---------- Page renderers ---------- */

function renderOverview(string $firstName, array $orders): void
{
    $stats = [
        ['calendar', 'Upcoming Appointment', '14:30',      'Thursday, Nov 28',       'orange'],
        ['wrench',   'Active Repair Orders', str_pad((string) count($orders), 2, '0', STR_PAD_LEFT), 'Updated just now', 'cyan'],
        ['wallet',   'Unpaid Invoices',      'VND 8.45M',  'Due Dec 2, 2025',        'pink'],
        ['gauge',    'Next Maintenance',     '1,240 km',   'Estimated January 2026', 'green'],
    ];
    $steps = ['Intake', 'Diagnosis', 'Waiting for Parts', 'In Repair', 'Ready for Pickup'];
    $notes = [
        ['wrench',   'Repair progress',      'The braking system has been removed and inspected.', '12 minutes ago', 'orange'],
        ['receipt',  'New invoice',          'Invoice #HD-25112 is ready.',                        '2 hours ago',    'cyan'],
        ['calendar', 'Appointment reminder', 'Your scheduled maintenance is this Thursday.',      'Yesterday',      'violet'],
    ];

    $hour     = (int) date('G');
    $greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');

    pageHeader(date('l, F j'), $greeting . ', ' . $firstName . '.', 'Everything about your vehicles is under control.',
        btn('Book Appointment', 'primary', 'plus', 'data-modal="booking"'));
    ?>
    <div class="stats-grid">
      <?php foreach ($stats as [$ic, $label, $value, $meta, $tone]): ?>
        <section class="glass stat-card">
          <div class="stat-icon <?= e($tone) ?>"><?= icon($ic) ?></div>
          <p><?= e($label) ?></p>
          <strong><?= e($value) ?></strong>
          <small><?= e($meta) ?></small>
        </section>
      <?php endforeach; ?>
    </div>

    <div class="overview-grid">
      <section class="glass repair-card">
        <div class="panel-heading">
          <div>
            <p class="eyebrow">ORDER #RO-25118</p>
            <h2>Current Repair Progress</h2>
          </div>
          <?= badge('In Repair', 'amber') ?>
        </div>
        <div class="repair-vehicle">
          <img src="assets/garage-sedan.png" alt="BMW 330i in the workshop">
          <div>
            <strong>BMW 330i M Sport</strong>
            <span>51G-886.28 · 2022</span>
          </div>
          <button type="button" aria-label="View details" data-go="orders"><?= icon('arrow') ?></button>
        </div>
        <div class="stepper">
          <?php foreach ($steps as $i => $label): ?>
            <div class="step <?= $i < 4 ? 'done' : '' ?> <?= $i === 3 ? 'current' : '' ?>">
              <div class="step-dot"><?= $i < 3 ? icon('check', 13) : $i + 1 ?></div>
              <span><?= e($label) ?></span>
            </div>
          <?php endforeach; ?>
        </div>
        <div class="repair-update">
          <div class="live-dot"></div>
          <div>
            <strong>The technician is installing the braking system</strong>
            <p>Estimated completion at 4:30 PM today</p>
          </div>
          <span>12 minutes ago</span>
        </div>
      </section>

      <section class="glass notifications">
        <div class="panel-heading">
          <div>
            <p class="eyebrow">UPDATES</p>
            <h2>Recent Notifications</h2>
          </div>
          <button type="button" class="text-button">Mark all as read</button>
        </div>
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
        <button type="button" class="view-all" data-go="appointments">View all notifications <?= icon('arrow', 16) ?></button>
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

function renderVehicles(array $vehicles): void
{
    $history = [
        ['Nov 24, 2025', 'High-performance brake pad replacement', 'Brake disc inspection and DOT 4 fluid replacement', 'VND 12,850,000'],
        ['Aug 12, 2025', '30,000 km scheduled maintenance',        'Engine oil and filter change, 24-point inspection',  'VND 4,260,000'],
        ['Mar 3, 2025',  'Wheel alignment',                         'Wheel balancing and alignment adjustment',           'VND 1,850,000'],
    ];

    pageHeader('YOUR GARAGE', 'My Vehicles', 'Manage vehicle information and track complete service histories.',
        btn('Add Vehicle', 'primary', 'plus', 'data-modal="vehicle"'));
    ?>
    <div class="vehicle-grid">
      <?php foreach ($vehicles as $i => $v): ?>
        <section class="glass vehicle-card">
          <div class="vehicle-image">
            <img src="assets/garage-sedan.png" alt="<?= e($v['model']) ?>"
                 <?= $i ? 'style="filter: hue-rotate(180deg) saturate(.65)"' : '' ?>>
            <?= $v['primary'] ? badge('Primary Vehicle', 'cyan') : '' ?>
            <span class="vehicle-year"><?= e($v['year']) ?></span>
          </div>
          <div class="vehicle-content">
            <div class="vehicle-title">
              <div>
                <p><?= e($v['year']) ?> · SEDAN</p>
                <h2><?= e($v['model']) ?></h2>
              </div>
              <button type="button" aria-label="Options">•••</button>
            </div>
            <div class="vehicle-specs">
              <div><span>License Plate</span><strong><?= e($v['plate']) ?></strong></div>
              <div><span>Mileage</span><strong><?= e($v['km']) ?></strong></div>
              <div><span>VIN</span><strong><?= e($v['vin'] !== '' ? substr($v['vin'], -8) : '—') ?></strong></div>
              <div><span>Next Service</span><strong><?= e($v['service']) ?></strong></div>
            </div>
            <div class="card-footer">
              <button type="button" class="history-link">View service history</button>
              <button type="button" class="round-button"><?= icon('arrow', 17) ?></button>
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

    <?php if ($vehicles): ?>
    <section class="glass timeline-panel">
      <div class="panel-heading">
        <div>
          <p class="eyebrow"><?= e($vehicles[0]['model']) ?> · <?= e($vehicles[0]['plate']) ?></p>
          <h2>Service History</h2>
        </div>
        <?= badge('Complete Records', 'green') ?>
      </div>
      <?php foreach ($history as $i => $item): ?>
        <div class="timeline-row">
          <div class="timeline-mark <?= $i === 0 ? 'active' : '' ?>"><?= icon($i === 0 ? 'wrench' : 'check', 15) ?></div>
          <div class="timeline-date"><?= e($item[0]) ?></div>
          <div class="timeline-info">
            <strong><?= e($item[1]) ?></strong>
            <span><?= e($item[2]) ?></span>
          </div>
          <strong><?= e($item[3]) ?></strong>
        </div>
      <?php endforeach; ?>
    </section>
    <?php endif; ?>
    <?php
}

function appointmentCard(string $day, string $month, string $weekday, bool $muted, string $badgeText, string $badgeTone, string $title, string $car, string $time): string
{
    ob_start(); ?>
    <section class="glass appointment-featured">
      <div class="date-block <?= $muted ? 'muted' : '' ?>">
        <strong><?= e($day) ?></strong>
        <span><?= e($month) ?></span>
        <small><?= e($weekday) ?></small>
      </div>
      <div class="appointment-main">
        <div>
          <?= badge($badgeText, $badgeTone) ?>
          <h2><?= e($title) ?></h2>
          <p><?= icon('car', 16) ?> <?= e($car) ?></p>
        </div>
        <div class="appointment-meta">
          <span><?= icon('clock', 17) ?><?= e($time) ?></span>
          <span><?= icon('map', 17) ?>District 7 Workshop, Ho Chi Minh City</span>
        </div>
      </div>
      <div class="appointment-actions">
        <?= btn('Reschedule', 'secondary') ?>
        <?= btn('Cancel', 'ghost') ?>
      </div>
    </section>
    <?php
    return ob_get_clean();
}

function renderAppointments(): void
{
    $tabs = ['Upcoming' => 2, 'Past' => 8, 'Cancelled' => 1];
    pageHeader('YOUR SCHEDULE', 'Appointments', 'Schedule and manage your vehicle care with ease.',
        btn('Book Appointment', 'primary', 'plus', 'data-modal="booking"'));
    ?>
    <div class="tabs" data-tabs>
      <?php foreach ($tabs as $t => $count): ?>
        <button type="button" class="<?= $t === 'Upcoming' ? 'active' : '' ?>" data-tab="<?= e($t) ?>">
          <?= e($t) ?><span><?= $count ?></span>
        </button>
      <?php endforeach; ?>
    </div>

    <div class="tab-pane" data-pane="Upcoming">
      <div class="appointment-list">
        <?= appointmentCard('28', 'THG 11', 'Thursday', false, 'Confirmed', 'cyan', '40,000 km Scheduled Maintenance', 'BMW 330i M Sport · 51G-886.28', '2:30 PM – 4:00 PM') ?>
        <?= appointmentCard('12', 'THG 12', 'Friday', true, 'Awaiting Confirmation', 'amber', 'Suspension System Inspection', 'Ford Ranger Wildtrak · 51D-592.04', '9:00 AM – 10:30 AM') ?>
      </div>
    </div>
    <section class="glass empty-state tab-pane" data-pane="Past" hidden>
      <div><?= icon('check', 28) ?></div>
      <h2>8 completed appointments</h2>
      <p>Thank you for trusting Redline.</p>
    </section>
    <section class="glass empty-state tab-pane" data-pane="Cancelled" hidden>
      <div><?= icon('x', 28) ?></div>
      <h2>No cancelled appointments</h2>
      <p>Cancelled appointments will appear here.</p>
    </section>
    <?php
}

function renderTable(string $kind, array $data): void
{
    $headers = $kind === 'orders'
        ? ['Order Number', 'Vehicle', 'Status', 'Created', 'Estimate', '']
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
              <tr><td colspan="6">Nothing to show yet.</td></tr>
            <?php endif; ?>
            <?php foreach ($data as $r): ?>
              <tr>
                <?php if ($kind === 'orders'): ?>
                  <td><strong><?= e($r[0]) ?></strong></td>
                  <td><?= e($r[1]) ?></td>
                  <td><?= badge($r[2], $r[5]) ?></td>
                  <td><?= e($r[3]) ?></td>
                  <td><strong><?= e(formatVND($r[4])) ?></strong></td>
                <?php else: ?>
                  <td><strong><?= e($r[0]) ?></strong></td>
                  <td><?= e($r[1]) ?></td>
                  <td><?= e($r[2]) ?></td>
                  <td><?= badge($r[3], $r[5]) ?></td>
                  <td><strong><?= e(formatVND($r[4])) ?></strong></td>
                <?php endif; ?>
                <td><button type="button" class="round-button"><?= icon('chevron', 16) ?></button></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </section>
    <?php
}

function renderOrders(array $orders): void
{
    $items = [
        ['Complete brake system inspection', 'Completed',       'VND 450,000'],
        ['Brembo Ceramic front brake pads',  'Being installed', 'VND 8,200,000'],
        ['Motul DOT 4 brake fluid',          'Approved',        'VND 1,150,000'],
    ];
    pageHeader('WORK TRACKING', 'Repair Orders', 'Track progress, costs, and technician updates.');
    renderTable('orders', $orders);
    if (!$orders) {
        return;
    }
    ?>
    <div class="detail-grid">
      <section class="glass order-detail">
        <div class="panel-heading">
          <div>
            <p class="eyebrow">DETAILS <?= e($orders[0][0]) ?></p>
            <h2>Work Items</h2>
          </div>
          <?= badge($orders[0][2], $orders[0][5]) ?>
        </div>
        <?php foreach ($items as $i => $r): ?>
          <div class="work-row">
            <div class="work-check <?= $i === 0 ? 'done' : '' ?>"><?= $i === 0 ? icon('check', 14) : $i + 1 ?></div>
            <div><strong><?= e($r[0]) ?></strong><span><?= e($r[1]) ?></span></div>
            <strong><?= e($r[2]) ?></strong>
          </div>
        <?php endforeach; ?>
        <div class="cost-summary"><span>Subtotal</span><strong><?= e(formatVND($orders[0][4])) ?></strong></div>
      </section>

      <section class="glass tech-note">
        <div class="panel-heading">
          <div>
            <p class="eyebrow">FROM THE TECHNICIAN</p>
            <h2>Latest Note</h2>
          </div>
          <div class="avatar small">QT</div>
        </div>
        <p>“The front brake pads are approximately 85% worn. We recommend ceramic pads for improved durability and reduced brake dust.”</p>
        <div class="photo-strip">
          <img src="assets/garage-sedan.png" alt="Vehicle at the workshop">
          <div><?= icon('plus') ?><span>2 photos</span></div>
        </div>
        <div class="quote-box">
          <div><span>Additional Quote</span><strong>VND 2,350,000</strong></div>
          <div>
            <?= btn('Approve') ?>
            <?= btn('Decline', 'danger') ?>
          </div>
        </div>
      </section>
    </div>
    <?php
}

function renderInvoices(array $invoices): void
{
    pageHeader('PAYMENTS', 'Invoices', 'Transparent costs with fast, secure payments.');
    ?>
    <div class="invoice-summary">
      <section class="glass">
        <span>Total Paid in 2025</span>
        <strong>VND 18,960,000</strong>
        <small>3 completed invoices</small>
      </section>
      <section class="glass">
        <span>Outstanding Balance</span>
        <strong id="balance">VND 12,850,000</strong>
        <small id="balance-note">1 invoice · Due Dec 2</small>
      </section>
    </div>
    <?php renderTable('invoices', $invoices); ?>
    <section class="glass invoice-detail">
      <div>
        <p class="eyebrow">INVOICE #HD-25112</p>
        <h2>Payment Details</h2>
        <p>BMW 330i M Sport · Issued Nov 24, 2025</p>
      </div>
      <div class="invoice-actions">
        <?= btn('Download PDF', 'secondary', 'download') ?>
        <?= btn('Pay Now', 'primary', 'wallet', 'id="pay-now"') ?>
      </div>
    </section>
    <?php
}

function renderFabrication(): void
{
    $steps = [
        ['01', 'Concept Intake',             'Complete',    'green'],
        ['02', '3D Design',                  'In Progress', 'cyan'],
        ['03', 'Fabrication & Prototyping',  'Upcoming',    'amber'],
        ['04', 'Installation & Delivery',    'Upcoming',    'amber'],
    ];
    pageHeader('REDLINE CUSTOM WORKS', 'Custom Fabrication', 'From concept to completion — follow every step of your custom project.',
        btn('Start a Project', 'primary', 'plus'));
    ?>
    <section class="glass fabrication-hero">
      <img src="assets/garage-sedan.png" alt="Custom body kit design project">
      <div class="fab-overlay">
        <?= badge('In Design', 'cyan') ?>
        <p>PROJECT #CF-25018</p>
        <h2>BMW G20 Aero Package</h2>
        <span>Custom carbon-fiber diffuser design and fabrication</span>
        <div class="progress-label"><strong>Overall Progress</strong><span>68%</span></div>
        <div class="progress"><i style="width: 68%"></i></div>
        <div class="fab-actions">
          <?= btn('Approve Design Proof') ?>
          <?= btn('View Drawings', 'secondary') ?>
        </div>
      </div>
    </section>
    <div class="project-grid">
      <?php foreach ($steps as $p): ?>
        <section class="glass project-step">
          <span><?= e($p[0]) ?></span>
          <?= icon($p[0] === '01' ? 'check' : 'sparkles') ?>
          <h3><?= e($p[1]) ?></h3>
          <?= badge($p[2], $p[3]) ?>
        </section>
      <?php endforeach; ?>
    </div>
    <?php
}

function renderProfile(array $profile, bool $support = false): void
{
    $toggles = [
        ['Repair Updates',         'Track progress in real time',        true],
        ['Appointment reminder',   '24 hours before your appointment',   true],
        ['Offers & News',          'Receive member-only offers',         false],
    ];
    pageHeader(
        $support ? 'WE ARE HERE TO HELP' : 'ACCOUNT',
        $support ? 'Customer Support' : 'Personal Profile',
        $support ? 'Submit a request or contact the Redline team directly.'
                 : 'Manage your contact details, security, and notification preferences.'
    );
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
          <div class="form-grid">
            <label>Full Name<input value="<?= e($profile['name']) ?>"></label>
            <label>Phone Number<input value="<?= e($profile['phone']) ?>"></label>
            <label class="wide">Email<input value="<?= e($profile['email']) ?>"></label>
          </div>
          <?= btn('Save Changes') ?>
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
              <div><strong>Password &amp; Security</strong><small>Updated 3 months ago</small></div>
            </div>
            <?= btn('Change Password', 'secondary') ?>
          </section>
          <section class="glass notification-settings">
            <p class="eyebrow">PREFERENCES</p>
            <h2>Notifications</h2>
            <?php foreach ($toggles as [$t, $desc, $on]): ?>
              <div class="toggle-row">
                <div><strong><?= e($t) ?></strong><span><?= e($desc) ?></span></div>
                <button type="button" class="switch <?= $on ? 'on' : '' ?>" aria-label="<?= e($t) ?>"><i></i></button>
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
    $services = ['Scheduled Maintenance', 'Inspection & Diagnostics', 'Tires & Suspension', 'Electrical & Climate Control'];
    $stepTitles = ['Choose a Service', 'Choose a Vehicle', 'Choose a Date & Time'];
    $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    ?>
    <!-- Booking modal -->
    <div class="modal-backdrop" id="modal-booking" hidden>
      <div class="modal glass">
        <button type="button" class="modal-close" data-close aria-label="Close"><?= icon('x') ?></button>
        <div class="success-state" hidden>
          <div><?= icon('check', 32) ?></div>
          <p class="eyebrow">COMPLETE</p>
          <h2>Appointment request submitted</h2>
          <p>Your information has been saved. We will send a confirmation by email.</p>
          <?= btn('Return to Dashboard', 'primary', null, 'data-close') ?>
        </div>
        <div class="modal-body">
          <p class="eyebrow" id="booking-eyebrow">BOOKING · STEP 1/3</p>
          <h2 id="booking-title"><?= e($stepTitles[0]) ?></h2>
          <p class="modal-intro">It takes less than two minutes to complete.</p>
          <div class="modal-progress"><i class="active"></i><i></i><i></i></div>
          <form id="booking-form">
            <div class="modal-pane choice-grid" data-step="1">
              <?php foreach ($services as $i => $x): ?>
                <button type="button" class="<?= $i === 0 ? 'selected' : '' ?>" data-choice>
                  <?= icon($i === 0 ? 'wrench' : ($i === 2 ? 'car' : 'sparkles')) ?>
                  <span><?= e($x) ?></span>
                  <?= $i === 0 ? icon('check', 16) : '' ?>
                </button>
              <?php endforeach; ?>
            </div>
            <div class="modal-pane choice-grid vehicles-choice" data-step="2" hidden>
              <?php foreach ($vehicles as $i => $v): ?>
                <button type="button" class="<?= $i === 0 ? 'selected' : '' ?>" data-choice>
                  <?= icon('car') ?>
                  <span><strong><?= e($v['model']) ?></strong><small><?= e($v['plate']) ?></small></span>
                  <?= $i === 0 ? icon('check', 16) : '' ?>
                </button>
              <?php endforeach; ?>
            </div>
            <div class="modal-pane" data-step="3" hidden>
              <div class="mini-calendar">
                <div class="calendar-head">
                  <button type="button">‹</button>
                  <strong><?= e(date('F Y')) ?></strong>
                  <button type="button">›</button>
                </div>
                <div class="calendar-grid">
                  <?php foreach ($days as $d): ?><span><?= $d ?></span><?php endforeach; ?>
                  <?php $dim = (int) date('t'); for ($d = 1; $d <= $dim; $d++): ?><span class="<?= $d === (int) date('j') ? 'chosen' : '' ?>" data-day><?= $d ?></span><?php endfor; ?>
                </div>
              </div>
              <div class="time-slots">
                <?php foreach (['9:00 AM', '10:30 AM', '2:30 PM', '4:00 PM'] as $t): ?>
                  <button type="button" class="<?= $t === '2:30 PM' ? 'selected' : '' ?>" data-slot><?= e($t) ?></button>
                <?php endforeach; ?>
              </div>
            </div>
            <div class="modal-actions">
              <?= btn('Back', 'secondary', null, 'id="booking-back" hidden') ?>
              <?= btn('Continue', 'primary', null, 'id="booking-next"') ?>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Add vehicle modal -->
    <div class="modal-backdrop" id="modal-vehicle" hidden>
      <div class="modal glass">
        <button type="button" class="modal-close" data-close aria-label="Close"><?= icon('x') ?></button>
        <div class="success-state" hidden>
          <div><?= icon('check', 32) ?></div>
          <p class="eyebrow">COMPLETE</p>
          <h2>Vehicle added</h2>
          <p>Your information has been saved. We will send a confirmation by email.</p>
          <?= btn('Return to Dashboard', 'primary', null, 'data-close') ?>
        </div>
        <div class="modal-body">
          <p class="eyebrow">YOUR GARAGE</p>
          <h2>Add New Vehicle</h2>
          <p class="modal-intro">Enter the basic details so we can provide better vehicle care.</p>
          <form id="vehicle-form">
            <div class="form-grid">
              <label>Make<input placeholder="Example: BMW"></label>
              <label>Model<input placeholder="Example: 330i"></label>
              <label>Model Year<input placeholder="2022"></label>
              <label>License Plate<input placeholder="51G-000.00"></label>
              <label class="wide">VIN<input placeholder="Enter the 17-character VIN"></label>
            </div>
            <div class="modal-actions">
              <?= btn('Confirm', 'primary', null, '', 'submit') ?>
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
<link rel="stylesheet" href="public/assets/dashboard.css"></head>
<body>
<div class="app-shell">
  <div class="ambient" aria-hidden="true"><i></i><i></i><i></i></div>

  <aside class="sidebar glass" id="sidebar">
    <div class="brand">
      <img class="brand-logo" src="assets/redline-logo.png" alt="Redline">
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
        <input placeholder="Search vehicles, invoices, repair orders...">
        <kbd>⌘ K</kbd>
      </label>
      <div class="top-actions">
        <button type="button" class="icon-button" aria-label="Notifications"><?= icon('bell') ?><i></i></button>
        <div class="profile-menu">
          <div class="avatar"><?= e($initials) ?></div>
          <div><strong><?= e($userName) ?></strong><span>Signature Member</span></div>
          <?= icon('chevron', 16) ?>
        </div>
      </div>
    </header>

    <main>
      <?php
      switch ($page) {
          case 'vehicles':     renderVehicles($vehicles); break;
          case 'appointments': renderAppointments(); break;
          case 'orders':       renderOrders($orders); break;
          case 'invoices':     renderInvoices($invoices); break;
          case 'fabrication':  renderFabrication(); break;
          case 'profile':      renderProfile($profile); break;
          case 'support':      renderProfile($profile, true); break;
          default:             renderOverview($firstName, $orders);
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
  var $  = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  /* Mobile menu */
  var sidebar = $('#sidebar'), scrim = $('#scrim');
  function setMenu(open) { sidebar.classList.toggle('open', open); scrim.hidden = !open; }
  $('#menu-open').addEventListener('click', function () { setMenu(true); });
  $('#menu-close').addEventListener('click', function () { setMenu(false); });
  scrim.addEventListener('click', function () { setMenu(false); });

  /* Page shortcuts */
  $$('[data-go]').forEach(function (b) {
    b.addEventListener('click', function () { location.href = '?page=' + b.getAttribute('data-go'); });
  });

  /* Appointment tabs */
  $$('[data-tabs]').forEach(function (bar) {
    var btns = $$('[data-tab]', bar);
    btns.forEach(function (b) {
      b.addEventListener('click', function () {
        btns.forEach(function (x) { x.classList.toggle('active', x === b); });
        $$('[data-pane]').forEach(function (p) { p.hidden = p.getAttribute('data-pane') !== b.getAttribute('data-tab'); });
      });
    });
  });

  /* Notification switches */
  $$('.switch').forEach(function (s) {
    s.addEventListener('click', function () { s.classList.toggle('on'); });
  });

  /* Pay now */
  var pay = $('#pay-now');
  if (pay) {
    pay.addEventListener('click', function () {
      $('span', pay).textContent = 'Paid';
      $('#balance').textContent = 'VND 0';
      $('#balance-note').textContent = 'No outstanding balance';
    });
  }

  /* Modals */
  var titles = ['Choose a Service', 'Choose a Vehicle', 'Choose a Date & Time'];
  var step = 1;
  function resetModal(m) {
    $('.success-state', m).hidden = true;
    $('.modal-body', m).hidden = false;
    step = 1; renderStep();
  }
  function openModal(name) {
    var m = $('#modal-' + name); resetModal(m); m.hidden = false;
  }
  function closeModals() { $$('.modal-backdrop').forEach(function (m) { m.hidden = true; }); }

  $$('[data-modal]').forEach(function (b) {
    b.addEventListener('click', function () { openModal(b.getAttribute('data-modal')); });
  });
  $$('.modal-backdrop').forEach(function (m) {
    m.addEventListener('mousedown', function (e) { if (e.target === m) closeModals(); });
    $$('[data-close]', m).forEach(function (b) { b.addEventListener('click', closeModals); });
  });
  document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModals(); });

  function renderStep() {
    $$('#booking-form .modal-pane').forEach(function (p) { p.hidden = p.getAttribute('data-step') != step; });
    $$('#modal-booking .modal-progress i').forEach(function (i, idx) { i.classList.toggle('active', idx < step); });
    $('#booking-eyebrow').textContent = 'BOOKING · STEP ' + step + '/3';
    $('#booking-title').textContent = titles[step - 1];
    $('#booking-back').hidden = step === 1;
    $('#booking-next span').textContent = step < 3 ? 'Continue' : 'Confirm';
  }
  $('#booking-next').addEventListener('click', function () {
    if (step < 3) { step++; renderStep(); return; }
    var m = $('#modal-booking'); $('.modal-body', m).hidden = true; $('.success-state', m).hidden = false;
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
  $$('[data-day]').forEach(function (d) {
    d.addEventListener('click', function () {
      $$('[data-day]').forEach(function (x) { x.classList.toggle('chosen', x === d); });
    });
  });

  /* Add vehicle */
  $('#vehicle-form').addEventListener('submit', function (e) {
    e.preventDefault();
    var m = $('#modal-vehicle'); $('.modal-body', m).hidden = true; $('.success-state', m).hidden = false;
  });
})();
</script>
</body>
</html>