<?php
/**
 * Redline - Services & Repair page
 * Sections: What we do / Reference pricing / Our promise / Book a repair
 */
require_once __DIR__ . '/../includes/auth.php';
start_session();

$user  = current_user();
$staff = $user ? null : current_staff();

$phone = '(555) 014-1986';

// --- NỘI DUNG (sửa tại đây) ---
$services = [
    ['01', 'ROUTINE MAINTENANCE', 'Oil and filter changes, air filter replacement, fluid inspection, tire rotation and factory-scheduled servicing.', 'FROM $89 · 45-90 MIN',
        '<path d="M4 12h9l3 3h3v5H7l-3-3z"/><path d="M8 12V8h4"/><path d="M19 15v-2"/>'],
    ['02', 'ADVANCED DIAGNOSTICS', 'Professional scan equipment and hands-on testing to find the cause—not just clear the warning light.', 'FROM $129 · 60-120 MIN',
        '<rect x="3" y="3" width="18" height="14" rx="1"/><path d="M8 21h8M12 17v4"/><path d="M6 10h3l1.5-3 3 6L15 10h3"/>'],
    ['03', 'SYSTEM REPAIRS', 'Engine, transmission and brake repairs completed by experienced technicians with quality parts.', 'ESTIMATE AFTER INSPECTION',
        '<path d="M14 4l6 6-9 9-6-6z"/><path d="M11 7l6 6M8 10l6 6"/>'],
];

$pricing = [
    ['OIL & FILTER CHANGE',   '45–60 min', '$89'],
    ['ENGINE DIAGNOSTIC SCAN', '60–90 min', '$129'],
    ['AIR FILTER REPLACEMENT', '30 min',     '$49'],
    ['BRAKE PAD REPLACEMENT', '2–3 hours',  '$289'],
    ['TRANSMISSION SERVICE', '3–4 hours',  '$349'],
];
$service_options = array_merge(array_column($pricing, 0), ['OTHER / NOT SURE']);

// --- XỬ LÝ FORM ĐẶT LỊCH ---
$errors = [];
$old = ['name' => $user['full_name'] ?? '', 'phone' => '', 'vehicle' => '', 'service' => '', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($old as $k => $_) {
        $old[$k] = trim((string) ($_POST[$k] ?? ''));
    }

    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    }
    if (!empty($_POST['website'])) { // honeypot chống bot
        $errors[] = 'Could not send your request.';
    }
    if (mb_strlen($old['name']) < 2 || mb_strlen($old['name']) > 120) {
        $errors[] = 'Please enter your name.';
    }
    $digits = preg_replace('/\D+/', '', $old['phone']);
    if (strlen($digits) < 7 || strlen($digits) > 15) {
        $errors[] = 'Please enter a valid phone number.';
    }
    if (mb_strlen($old['vehicle']) < 2 || mb_strlen($old['vehicle']) > 160) {
        $errors[] = 'Please enter your vehicle (year, make & model).';
    }
    if (!in_array($old['service'], $service_options, true)) {
        $errors[] = 'Please select a service.';
    }
    if (mb_strlen($old['message']) > 1000) {
        $errors[] = 'Description is too long (max 1000 characters).';
    }

    if (!$errors) {
        try {
            db()->prepare(
                'INSERT INTO service_requests (user_id, full_name, phone, vehicle, service, message, ip_address)
                 VALUES (:uid, :name, :phone, :vehicle, :service, :msg, :ip)'
            )->execute([
                'uid' => $user['id'] ?? null, 'name' => $old['name'], 'phone' => $old['phone'],
                'vehicle' => $old['vehicle'], 'service' => $old['service'],
                'msg' => $old['message'] !== '' ? $old['message'] : null, 'ip' => client_ip(),
            ]);
            $_SESSION['booking_ok'] = true;
            header('Location: services.php#book');
            exit;
        } catch (PDOException $ex) {
            $errors[] = 'We could not save your request. Please call us instead.';
        }
    }
}
$booked = !empty($_SESSION['booking_ok']);
unset($_SESSION['booking_ok']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Services &amp; Repair - Redline</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="services.css?v=<?php echo filemtime(__DIR__ . '/services.css'); ?>">
</head>
<body>

<header class="sv-header">
    <div class="sv-wrap sv-header-row">
        <a href="../index.php" class="sv-logo"><span>///</span> REDLINE</a>
        <nav class="sv-nav" id="svNav">
            <a href="../ServicesAndRepair/services.php">SERVICE &amp; REPAIR</a>
            <a href="../Performance_Upgardes/performance.php" class="on">PERFORMANCE</a>
            <a href="../index.php#services">FABRICATION</a>
        </nav>
        <div class="sv-actions">
            <?php if ($staff): ?>
                <a href="../<?php echo e(GARA_HOME); ?>" class="sv-btn sv-btn-ghost">GARA MANAGEMENT</a>
            <?php elseif ($user): ?>
                <a href="../dashboard.php" class="sv-btn sv-btn-ghost">GARAGE (<?php echo e(explode(' ', $user['full_name'])[0]); ?>)</a>
            <?php else: ?>
                <a href="../login.php" class="sv-btn sv-btn-ghost">LOGIN</a>
            <?php endif; ?>
            <a href="#book" class="sv-btn sv-btn-primary">BOOK A REPAIR</a>
        </div>
        <button class="sv-burger" id="svBurger" aria-label="Toggle menu"><span></span><span></span><span></span></button>
    </div>
</header>

<!-- WHAT WE DO -->
<section id="services" class="sv-section sv-dark sv-first">
    <div class="sv-wrap">
        <div class="sv-head">
            <div>
                <span class="sv-eyebrow"><i>///</i> WHAT WE DO</span>
                <h1 class="sv-title">SERVICE, WITHOUT<br>THE GUESSWORK.</h1>
            </div>
            <p class="sv-lead">Every visit starts with listening. Every repair ends with a clear explanation of the work completed.</p>
        </div>

        <div class="sv-cards">
            <?php foreach ($services as [$num, $title, $desc, $meta, $icon]): ?>
                <article class="sv-card">
                    <div class="sv-card-top">
                        <svg class="sv-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"><?php echo $icon; ?></svg>
                        <span class="sv-card-num"><?php echo $num; ?></span>
                    </div>
                    <h3><?php echo e($title); ?></h3>
                    <p><?php echo e($desc); ?></p>
                    <div class="sv-card-meta"><?php echo e($meta); ?></div>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- REFERENCE PRICING -->
<section id="pricing" class="sv-section sv-light">
    <div class="sv-wrap">
        <div class="sv-head">
            <div>
                <span class="sv-eyebrow"><i>///</i> REFERENCE PRICING</span>
                <h2 class="sv-title">KNOW BEFORE<br>WE GO.</h2>
            </div>
            <p class="sv-lead">Clear starting prices and realistic timelines. We always confirm your final estimate before work begins.</p>
        </div>

        <div class="sv-table">
            <div class="sv-row sv-row-head"><span>SERVICE</span><span>ESTIMATED TIME</span><span>FROM</span></div>
            <?php foreach ($pricing as $i => [$name, $time, $price]): ?>
                <div class="sv-row">
                    <span class="sv-row-name"><b><?php echo str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT); ?></b><?php echo e($name); ?></span>
                    <span class="sv-row-time"><?php echo e($time); ?></span>
                    <span class="sv-row-price"><?php echo e($price); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- OUR PROMISE -->
<section id="promise" class="sv-promise sv-dark">
    <div class="sv-promise-text">
        <span class="sv-eyebrow"><i>///</i> OUR PROMISE</span>
        <h2 class="sv-title">GOOD WORK.<br>CLEAR WORDS.</h2>
        <p class="sv-lead">We don’t hide behind jargon. You’ll know what your vehicle needs, why it needs it and what it will cost before a wrench turns.</p>
        <div class="sv-stats">
            <div><strong>12 mo.</strong><span>PARTS &amp; LABOR WARRANTY</span></div>
            <div><strong>0</strong><span>SURPRISE CHARGES</span></div>
        </div>
    </div>
    <div class="sv-promise-img"><img src="../image/mechanic-hands.jpg" alt="Mechanic’s hands working on an engine with a wrench" loading="lazy"></div>
</section>

<!-- BOOK A REPAIR -->
<section id="book" class="sv-section sv-book">
    <div class="sv-wrap sv-book-grid">
        <div>
            <span class="sv-eyebrow"><i>///</i> READY WHEN YOU ARE</span>
            <h2 class="sv-title">BOOK A<br>REPAIR.</h2>
            <p class="sv-lead">Tell us about your vehicle and what you’re experiencing. Our service advisor will contact you to confirm a time.</p>
            <div class="sv-call">
                <small>PREFER TO CALL?</small>
                <a href="tel:<?php echo e(preg_replace('/[^\d+]/', '', $phone)); ?>"><?php echo e($phone); ?></a>
            </div>
        </div>

        <form class="sv-form" method="post" action="services.php#book" novalidate>
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="text" name="website" class="sv-hp" tabindex="-1" autocomplete="off" aria-hidden="true">

            <?php if ($booked): ?>
                <div class="sv-alert sv-ok">Request received. Our service advisor will contact you shortly to confirm a time.</div>
            <?php endif; ?>
            <?php if ($errors): ?>
                <div class="sv-alert sv-err"><?php foreach ($errors as $m) { echo '<div>' . e($m) . '</div>'; } ?></div>
            <?php endif; ?>

            <div class="sv-field"><label for="f-name">YOUR NAME</label>
                <input id="f-name" name="name" type="text" placeholder="Full name" value="<?php echo e($old['name']); ?>" required></div>
            <div class="sv-field"><label for="f-phone">PHONE NUMBER</label>
                <input id="f-phone" name="phone" type="tel" placeholder="(555) 000-0000" value="<?php echo e($old['phone']); ?>" required></div>
            <div class="sv-field"><label for="f-vehicle">VEHICLE</label>
                <input id="f-vehicle" name="vehicle" type="text" placeholder="Year, make &amp; model" value="<?php echo e($old['vehicle']); ?>" required></div>
            <div class="sv-field"><label for="f-service">SERVICE NEEDED</label>
                <select id="f-service" name="service" required>
                    <option value="">Select a service</option>
                    <?php foreach ($service_options as $opt): ?>
                        <option value="<?php echo e($opt); ?>"<?php echo $old['service'] === $opt ? ' selected' : ''; ?>><?php echo e($opt); ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="sv-field sv-full"><label for="f-msg">TELL US WHAT’S GOING ON</label>
                <textarea id="f-msg" name="message" rows="3" maxlength="1000" placeholder="Describe any sounds, warning lights, or concerns"><?php echo e($old['message']); ?></textarea></div>

            <button type="submit" class="sv-submit sv-full">REQUEST APPOINTMENT <span>&rarr;</span></button>
        </form>
    </div>
</section>

<footer class="sv-footer">
    <div class="sv-wrap">
        <div class="sv-footer-row">
            <a href="../index.php" class="sv-logo"><span>///</span> REDLINE</a>
            <p>Precision automotive service. Honest advice. Work built to last.</p>
            <div class="sv-hours"><span>MON–FRI 8:00–6:00</span><span>SAT 9:00–2:00</span></div>
        </div>
        <div class="sv-footer-bottom">© <?php echo date('Y'); ?> Redline Automotive. Photography via Unsplash.</div>
    </div>
</footer>

<script>
document.getElementById('svBurger').addEventListener('click', function () {
    document.getElementById('svNav').classList.toggle('is-open');
});
document.querySelectorAll('#svNav a').forEach(function (a) {
    a.addEventListener('click', function () { document.getElementById('svNav').classList.remove('is-open'); });
});
</script>
</body>
</html>
