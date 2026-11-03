<?php
/**
 * Redline - Services & Repair page
 * Vị trí tệp: www/services.php
 */
require_once __DIR__ . '/src/includes/auth.php';
start_session();

$user  = current_user();
$staff =$user ? null : current_staff();

$phone = '(555) 014-1986';

// --- NỘI DUNG DỊCH VỤ & BẢNG GIÁ ---
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

// Danh sách loại dịch vụ
$service_type_options = [
    'OIL & FILTER CHANGE',
    'ENGINE DIAGNOSTIC SCAN',
    'AIR FILTER REPLACEMENT',
    'BRAKE PAD REPLACEMENT',
    'TRANSMISSION SERVICE',
    'OTHER / NOT SURE'
];

$fuel_options = ['1/4', '1/2', '3/4', 'Full'];

// --- XỬ LÝ FORM ĐẶT LỊCH ---
$errors = [];$old = [
    'name'          => $user['full_name'] ?? '',
    'phone'         => '',
    'license_plate' => '',
    'vehicle'       => '',
    'vin_number'    => '',
    'fuel_level'    => '1/2',
    'service_type'  => '',
    'message'       => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($old as $k =>$_) {
        $old[$k] = trim((string) ($_POST[$k] ?? ''));
    }

    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    }
    if (!empty($_POST['website'])) {$errors[] = 'Could not send your request.';
    }
    if (mb_strlen($old['name']) < 2 || mb_strlen($old['name']) > 120) {$errors[] = 'Please enter your full name.';
    }
    $digits = preg_replace('/\D+/', '',$old['phone']);
    if (strlen($digits) < 7 || strlen($digits) > 15) {$errors[] = 'Please enter a valid phone number.';
    }
    if (mb_strlen($old['license_plate']) < 2 || mb_strlen($old['license_plate']) > 20) {$errors[] = 'Please enter a valid license plate (Biển số xe).';
    }
    if (mb_strlen($old['vehicle']) < 2 || mb_strlen($old['vehicle']) > 160) {$errors[] = 'Please enter your vehicle model (Camry, M3, etc.).';
    }
    if (!in_array($old['service_type'], $service_type_options, true)) {$errors[] = 'Please select a Service Type.';
    }
    if (!in_array($old['fuel_level'], $fuel_options, true)) {$old['fuel_level'] = '1/2';
    }
    if (mb_strlen($old['message']) > 1000) {$errors[] = 'Description is too long (max 1000 characters).';
    }

    if (!$errors) {
        try {
            db()->prepare(
                'INSERT INTO service_requests (user_id, full_name, phone, license_plate, vehicle, vin_number, fuel_level, service_type, service, message, ip_address)
                 VALUES (:uid, :name, :phone, :lp, :vehicle, :vin, :fuel, :stype, :service, :msg, :ip)'
            )->execute([
                'uid'     => $user['id'] ?? null,
                'name'    => $old['name'],
                'phone'   => $old['phone'],
                'lp'      => strtoupper($old['license_plate']),
                'vehicle' => $old['vehicle'],
                'vin'     => strtoupper($old['vin_number']),
                'fuel'    => $old['fuel_level'],
                'stype'   => $old['service_type'],
                'service' => $old['service_type'],
                'msg'     => $old['message'] !== '' ?$old['message'] : null,
                'ip'      => client_ip(),
            ]);
        } catch (PDOException $ex) {
            try {
                $vehicleCombined = $old['vehicle'] . ' [' . strtoupper($old['license_plate']) . ']';
                if (!empty($old['vin_number'])) {
                    $vehicleCombined .= ' VIN:' . strtoupper($old['vin_number']);
                }
                $vehicleCombined .= ' [Fuel:' .$old['fuel_level'] . ']';

                db()->prepare(
                    'INSERT INTO service_requests (user_id, full_name, phone, vehicle, service, message, ip_address)
                     VALUES (:uid, :name, :phone, :vehicle, :service, :msg, :ip)'
                )->execute([
                    'uid'     => $user['id'] ?? null,
                    'name'    => $old['name'],
                    'phone'   => $old['phone'],
                    'vehicle' => $vehicleCombined,
                    'service' => $old['service_type'],
                    'msg'     => $old['message'] !== '' ?$old['message'] : null,
                    'ip'      => client_ip(),
                ]);
            } catch (PDOException $ex2) {$errors[] = 'We could not save your request. Please call us instead.';
            }
        }

        if (!$errors) {$_SESSION['booking_ok'] = true;
            header('Location: services.php#book');
            exit;
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
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="public/assets/services.css?v=<?php echo file_exists(__DIR__ . '/public/assets/services.css') ? filemtime(__DIR__ . '/public/assets/services.css') : time(); ?>">
</head>
<body>

<header class="sv-header">
    <div class="sv-wrap sv-hrow">
        <a href="index.php" class="sv-hlogo">
            <svg width="26" height="26" viewBox="0 0 26 26" fill="#ee5a28"><path d="M3 24 7 5h3L6 24zM11 24l4-14h3l-4 14zM19 24l2.5-9H25l-2.5 9z"/></svg>REDLINE
        </a>
        <nav class="sv-nav">
            <a href="services.php" class="on">SERVICE &amp; REPAIR</a>
            <a href="performance.php">PERFORMANCE</a>
            <a href="Fabrication.php">FABRICATION</a>
        </nav>
        <a href="#book" class="sv-hbtn">START A BUILD <span>&rarr;</span></a>
    </div>
</header>

<section id="services" class="sv-hero">
    <div class="sv-wrap">
        <div class="sv-crumb"><a href="index.php">HOME</a> / <b>SERVICES</b></div>
        <div class="sv-head">
            <div>
                <span class="sv-eyebrow">WHAT WE DO</span>
                <h1 class="sv-title">SERVICE, WITHOUT<br>THE GUESSWORK.</h1>
            </div>
            <p class="sv-lead">Every visit starts with listening. Every repair ends with a clear explanation of the work completed.</p>
        </div>

        <div class="sv-cards">
            <?php foreach ($services as [$num, $title,$desc, $meta,$icon]): ?>
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

<section id="pricing" class="sv-section sv-light">
    <div class="sv-wrap">
        <div class="sv-head">
            <div>
                <span class="sv-eyebrow">REFERENCE PRICING</span>
                <h2 class="sv-title">KNOW BEFORE<br>WE GO.</h2>
            </div>
            <p class="sv-lead">Clear starting prices and realistic timelines. We always confirm your final estimate before work begins.</p>
        </div>

        <div class="sv-table">
            <div class="sv-row sv-row-head"><span>SERVICE</span><span>ESTIMATED TIME</span><span>FROM</span></div>
            <?php foreach ($pricing as $i => [$name, $time,$price]): ?>
                <div class="sv-row">
                    <span class="sv-row-name"><b><?php echo str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT); ?></b><?php echo e($name); ?></span>
                    <span class="sv-row-time"><?php echo e($time); ?></span>
                    <span class="sv-row-price"><?php echo e($price); ?></span>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section id="promise" class="sv-promise sv-dark">
    <div class="sv-promise-text">
        <span class="sv-eyebrow">OUR PROMISE</span>
        <h2 class="sv-title">GOOD WORK.<br>CLEAR WORDS.</h2>
        <p class="sv-lead">We don’t hide behind jargon. You’ll know what your vehicle needs, why it needs it and what it will cost before a wrench turns.</p>
        <div class="sv-stats">
            <div><strong>12 mo.</strong><span>PARTS &amp; LABOR WARRANTY</span></div>
            <div><strong>0</strong><span>SURPRISE CHARGES</span></div>
        </div>
    </div>
    <div class="sv-promise-img"><img src="public/image/mechanic-hands.jpg" alt="Mechanic’s hands working on an engine with a wrench" loading="lazy"></div>
</section>

<!-- BOOK A REPAIR -->
<section id="book" class="sv-section sv-book">
    <div class="sv-wrap sv-book-grid">
        <div>
            <span class="sv-eyebrow">READY WHEN YOU ARE</span>
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

            <!-- HÀNG 1: TÊN & SỐ ĐIỆN THOẠI -->
            <div class="sv-field"><label for="f-name">YOUR NAME (*)</label>
                <input id="f-name" name="name" type="text" placeholder="Full name" value="<?php echo e($old['name']); ?>" required></div>

            <div class="sv-field"><label for="f-phone">PHONE NUMBER (*)</label>
                <input id="f-phone" name="phone" type="tel" placeholder="(555) 000-0000" value="<?php echo e($old['phone']); ?>" required></div>

            <!-- HÀNG 2: BIỂN SỐ XE & MẪU XE -->
            <div class="sv-field"><label for="f-license">LICENSE PLATE (*)</label>
                <input id="f-license" name="license_plate" type="text" placeholder="e.g. 65A-04953" value="<?php echo e($old['license_plate']); ?>" style="text-transform: uppercase;" required></div>

            <div class="sv-field"><label for="f-vehicle">VEHICLE MODEL (*)</label>
                <input id="f-vehicle" name="vehicle" type="text" placeholder="e.g. Camry 2.5Q" value="<?php echo e($old['vehicle']); ?>" required></div>

            <!-- HÀNG 3: SỐ VIN & MỨC NHIÊN LIỆU -->
            <div class="sv-field"><label for="f-vin">VIN NUMBER (OPTIONAL)</label>
                <input id="f-vin" name="vin_number" type="text" placeholder="e.g. RL4-98234729" value="<?php echo e($old['vin_number']); ?>" style="text-transform: uppercase;"></div>

            <div class="sv-field"><label for="f-fuel">CURRENT FUEL LEVEL</label>
                <select id="f-fuel" name="fuel_level">
                    <?php foreach ($fuel_options as$fOpt): ?>
                        <option value="<?php echo e($fOpt); ?>" <?php echo $old['fuel_level'] ===$fOpt ? 'selected' : ''; ?>><?php echo e($fOpt); ?></option>
                    <?php endforeach; ?>
                </select></div>

            <!-- HÀNG 4: LOẠI DỊCH VỤ -->
            <div class="sv-field sv-full"><label for="f-stype">SERVICE TYPE (*)</label>
                <select id="f-stype" name="service_type" required>
                    <option value="">Select a service</option>
                    <?php foreach ($service_type_options as$opt): ?>
                        <option value="<?php echo e($opt); ?>" <?php echo $old['service_type'] ===$opt ? 'selected' : ''; ?>><?php echo e($opt); ?></option>
                    <?php endforeach; ?>
                </select></div>

            <!-- HÀNG 5: TRIỆU CHỨNG / TÌNH TRẠNG XE -->
            <div class="sv-field sv-full"><label for="f-msg">TELL US WHAT’S GOING ON / SYMPTOMS</label>
                <textarea id="f-msg" name="message" rows="3" maxlength="1000" placeholder="Describe any sounds, warning lights, or technical concerns"><?php echo e($old['message']); ?></textarea></div>

            <button type="submit" class="sv-submit sv-full">REQUEST APPOINTMENT <span>&rarr;</span></button>
        </form>
    </div>
</section>

<footer class="sv-footer">
    <div class="sv-wrap sv-frow">
        <a href="index.php" class="sv-hlogo"><svg width="22" height="22" viewBox="0 0 26 26" fill="#ee5a28"><path d="M3 24 7 5h3L6 24zM11 24l4-14h3l-4 14zM19 24l2.5-9H25l-2.5 9z"/></svg>REDLINE</a>
        <span>Precision service. Honest advice.</span>
        <nav><a href="#services">Services</a><a href="#pricing">Pricing</a><a href="#book">Book</a></nav>
        <span>© <?php echo date('Y'); ?> REDLINE AUTOMOTIVE</span>
    </div>
</footer>

</body>
</html>