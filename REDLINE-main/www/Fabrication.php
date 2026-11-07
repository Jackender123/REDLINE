<?php
/**
 * Redline Motorworks - Custom Fabrication Page (Full Upgraded - Index Theme Match)
 * File Location: www/Fabrication.php
 */

require_once __DIR__ . '/src/includes/auth.php';
start_session();

$phone = '(555) 014-1986';

$stats = [
    ['12+',  'YEARS FABRICATING'],
    ['400+', 'CUSTOM PROJECTS'],
    ['100%', 'BUILT IN-HOUSE'],
    ['01',   'VISION AT A TIME'],
];

$caps = [
    ['01', 'CUSTOM EXHAUST SYSTEMS', 'From axle-back systems to full titanium builds, every route is measured, formed, and TIG welded for flow and fit.', 'welder.jpg', 'Welder grinding a steel beam in the workshop'],
    ['02', 'ROLL CAGE FABRICATION',  'Purpose-built safety structures designed around your chassis, driving position, and intended motorsport regulations.', 'roll-cage-shop.jpg', 'Bare car shell inside the fabrication shop'],
    ['03', 'HEADERS & METALWORK',    'Equal-length headers, charge piping, brackets, tanks, and one-off components built to solve complex packaging.', 'metalwork-sparks.jpg', 'Sparks flying while cutting a steel tube'],
    ['04', 'WIDEBODY CONVERSIONS',   'Precision body kit installation, panel shaping, fitment, and finishing for a factory-level final result.', 'audi-widebody.jpg', 'Widebody Audi with headlights on in a dark garage'],
];

$craft = [
    ['TIG', 'WELDED BY HAND'],
    ['CAD', 'DESIGNED TO FIT'],
    ['304', 'STAINLESS STANDARD'],
];

$projects = [
    ['01 / WIDEBODY CONVERSION', 'AUDI RS PERFORMANCE BUILD', 'audi-widebody.jpg',   'center', 'Audi RS widebody build'],
    ['02 / METALWORK',           'ONE-OFF COMPONENTS',        'metalwork-sparks.jpg', 'center', 'Sparks from a grinder on raw steel'],
    ['03 / EXHAUST',             'STAINLESS CAT-BACK SYSTEM', 'garage-exhaust.jpg',   'bottom', 'Sedan parked in a blue-lit garage'],
];

$steps = [
    ['01', 'TELL US THE VISION', 'Share your car, goals, references, and budget.'],
    ['02', 'PLAN THE BUILD',     'We define the scope, materials, timeline, and quote.'],
    ['03', 'FABRICATE & FIT',    'Everything is built, test-fitted, and documented in-house.'],
    ['04', 'DRIVE IT HOME',      'Final inspection, handover, and the road ahead.'],
];

$service_options = array_merge(array_column($caps, 1), ['OTHER / NOT SURE']);

$user   = current_user();
$staff  = $user ? null : current_staff();

if (!function_exists('e')) {
    function e($val) {
        return htmlspecialchars((string)$val, ENT_QUOTES, 'UTF-8');
    }
}

$errors = [];
$old = ['name' => $user['full_name'] ?? '', 'phone' => '', 'vehicle' => '', 'service' => '', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($old as $k => $_) {
        $old[$k] = trim((string) ($_POST[$k] ?? ''));
    }

    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $errors[] = 'Your session expired. Please try again.';
    }
    if (!empty($_POST['website'])) {
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
                'vehicle' => $old['vehicle'], 'service' => 'FABRICATION: ' . $old['service'],
                'msg' => $old['message'] !== '' ? $old['message'] : null, 'ip' => client_ip(),
            ]);
            $_SESSION['booking_ok'] = true;
            header('Location: Fabrication.php#book');
            exit;
        } catch (PDOException $ex) {
            $errors[] = 'We could not save your request. Please call us instead.';
        }
    }
}
$booked = !empty($_SESSION['booking_ok']);
unset($_SESSION['booking_ok']);

$site_info = [
    'name' => 'REDLINE',
    'phone' => '+1 (555) 839-2001',
    'address' => '1042 Performance Way, Los Angeles, CA 90210'
];
/* --- Tự động lấy ảnh nền Fabrication-bg --- */
$bg_image = null;
$img_dir  = __DIR__ . '/public/image/';
if (is_dir($img_dir)) {
    foreach (scandir($img_dir) as $file) {
        if (strcasecmp(pathinfo($file, PATHINFO_FILENAME), 'Fabrication-bg') === 0
            && preg_match('/\.(jpe?g|png|webp|avif)$/i', $file)) {
            $bg_image = 'public/image/' . rawurlencode($file);
            break;
        }
    }
}
if (!$bg_image) {
    $bg_image = 'public/image/Fabrication-bg.jpg';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Custom Fabrication - REDLINE MOTORWORKS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@500;600;700&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="public/assets/style.css?v=<?php echo file_exists(__DIR__ . '/public/assets/style.css') ? filemtime(__DIR__ . '/public/assets/style.css') : time(); ?>">
    <link rel="stylesheet" href="public/assets/fabrication.css?v=<?php echo file_exists(__DIR__ . '/public/assets/fabrication.css') ? filemtime(__DIR__ . '/public/assets/fabrication.css') : time(); ?>">
</head>
<body>

    <!-- SECTION: HEADER / NAVIGATION (ĐỒNG BỘ INDEX.PHP) -->
    <header class="site-header">
        <div class="header-container">
            <a href="index.php" class="brand-logo">
                <span class="logo-slashes">///</span> REDLINE
            </a>

            <nav class="main-nav">
                <a href="services.php">SERVICES</a>
                <a href="performance.php">PERFORMANCE</a>
                <a href="Fabrication.php" class="active">FABRICATION</a>
                <a href="index.php#work">OUR WORK</a>
                <a href="index.php#faq">FAQ</a>
            </nav>

            <div class="header-actions">
                <a href="track.php" class="btn-ghost-track">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="9 11 12 14 22 4"></polyline><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path></svg>
                    <span>PROGRESS</span>
                </a>

                <button class="btn-figma-primary" onclick="openBookingModal()">
                    BOOK AN APPOINTMENT
                </button>

                <?php if($staff ||$user): ?>
                    <!-- Hiển thị Avatar Dashboard khi ĐÃ đăng nhập -->
                    <a href="<?php echo $staff ? 'src/modules/gara-management/index.php' : 'dashboard.php'; ?>" class="user-avatar-btn" title="Management Dashboard">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path><circle cx="12" cy="7" r="4"></circle></svg>
                    </a>
                <?php else: ?>
                    <!-- Hiển thị nút LOGIN khi CHƯA đăng nhập -->
                    <a href="login.php" class="btn-ghost-track" title="Log In">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"></path><polyline points="10 17 15 12 10 7"></polyline><line x1="15" y1="12" x2="3" y2="12"></line></svg>
                        <span>LOGIN</span>
                    </a>
                <?php endif; ?>
            </div>

            <button class="mobile-menu-toggle" aria-label="Toggle Menu">
                <span></span><span></span><span></span>
            </button>
        </div>
    </header>
<!-- SECTION: HERO HEADER -->
<section class="pf-hero fb-hero" style="<?php echo $bg_image ? "background-image: linear-gradient(rgba(255, 255, 255, 0.55), rgba(255, 255, 255, 0.65)), url('{$bg_image}'); background-size: cover; background-position: center;" : ''; ?>">
    <div class="pf-wrap pf-hero-in">
        <div class="pf-crumb"><a href="index.php">HOME</a> / <a href="services.php">SERVICES</a> / <a href="performance.php">PERFORMANCE</a> / <b>FABRICATION</b></div>
        <span class="pf-eyebrow">/// CUSTOM FABRICATION / BUILT IN-HOUSE</span>
        <h1 class="pf-h">BUILT TO FIT.<br>BUILT TO <em>LAST.</em></h1>
        <p class="pf-lead">One-off metalwork and vehicle modifications engineered with purpose, fabricated by hand, and finished without compromise.</p>
        <div class="pf-cta">
            <a href="#book" class="btn-figma-primary" style="padding: 14px 28px;">BOOK A CONSULTATION &rarr;</a>
            <a href="#capabilities" class="btn-figma-outline" style="padding: 14px 28px;">EXPLORE CAPABILITIES &darr;</a>
        </div>

        <div class="fb-strip">
            <?php foreach ($stats as [$v,$l]): ?>
                <div><strong><?php echo e($v); ?></strong><small><?php echo e($l); ?></small></div>
            <?php endforeach; ?>
        </div>
    </div>
</section>

    <!-- SECTION: WHAT WE BUILD -->
    <section id="capabilities" class="pf-sec">
        <div class="pf-wrap">
            <div class="pf-head">
                <div>
                    <span class="pf-eyebrow">/// WHAT WE BUILD</span>
                    <h2 class="pf-h2">NO CATALOG. NO COMPROMISE.</h2>
                </div>
                <p class="pf-note">The right part doesn’t always exist off-the-shelf. That’s where we come in. Every component is designed around your vehicle, your goals, and your driving style.</p>
            </div>
            
            <div class="fb-caps">
                <?php foreach ($caps as [$n, $t,$d, $img,$alt]): ?>
                <article>
                    <figure>
                        <img src="public/image/<?php echo e($img); ?>" alt="<?php echo e($alt); ?>" loading="lazy">
                        <i><?php echo e($n); ?></i>
                    </figure>
                    <h3><?php echo e($t); ?></h3>
                    <p><?php echo e($d); ?></p>
                    <a href="#book" class="fb-link" data-service="<?php echo e($t); ?>">DISCUSS THIS SERVICE &rarr;</a>
                </article>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- SECTION: BUILD COMPARISON -->
    <section class="pf-sec pf-sec3">
        <div class="pf-wrap">
            <div class="pf-head">
                <div>
                    <span class="pf-eyebrow">/// BUILD COMPARISON</span>
                    <h2 class="pf-h2">PLAN YOUR NEXT MOVE.</h2>
                </div>
                <p class="pf-note">Compare typical investment, visual impact, and functional gain across popular fabrication upgrades before mapping out your project.</p>
            </div>
            <div class="pf-tabs" id="cmpTabs"></div>
            <div class="pf-stats c3" id="cmpStats"></div>
            <div class="pf-chart" id="cmpChart"></div>
            <p class="pf-foot">Scores serve as directional planning guides based on a typical road-focused installation.</p>
        </div>
    </section>

    <!-- SECTION: CRAFT DETAILS -->
    <section class="fb-craft">
        <div class="fb-craft-img">
            <img src="public/image/welder.jpg" alt="Fabricator grinding a weld seam" loading="lazy">
        </div>
        <div class="fb-craft-txt">
            <span class="pf-eyebrow" style="color:#FF4D6D !important;">/// MADE, NOT ORDERED</span>
            <h2>CRAFT IS IN THE DETAILS.</h2>
            <p class="pf-note">Clean welds are only the visible part. We obsess over routing, clearances, serviceability, material selection, and structural integrity.</p>
            
            <dl class="fb-facts">
                <?php foreach ($craft as [$v,$l]): ?>
                <div>
                    <dt><?php echo e($v); ?></dt>
                    <dd><?php echo e($l); ?></dd>
                </div>
                <?php endforeach; ?>
            </dl>
            <a href="#book" class="btn-figma-primary" style="padding: 14px 28px;">TALK TO A FABRICATOR &rarr;</a>
        </div>
    </section>

    <!-- SECTION: SELECTED PROJECTS -->
    <section id="work" class="pf-sec">
        <div class="pf-wrap">
            <div class="pf-head">
                <div>
                    <span class="pf-eyebrow">/// SELECTED PROJECTS</span>
                    <h2 class="pf-h2">THE WORK SPEAKS.</h2>
                </div>
                <a href="#book" class="fb-link">START YOUR PROJECT &rarr;</a>
            </div>
            
            <div class="fb-projects">
                <?php foreach ($projects as $i => [$tag, $title,$img, $pos,$alt]): ?>
                <a href="#book" class="fb-proj fb-proj-<?php echo $i + 1; ?>">
                    <img src="public/image/<?php echo e($img); ?>" alt="<?php echo e($alt); ?>" style="object-position:<?php echo e($pos); ?>" loading="lazy">
                    <span>
                        <small><?php echo e($tag); ?></small>
                        <b><?php echo e($title); ?></b>
                    </span>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </section>

    <!-- SECTION: HOW IT WORKS -->
    <section id="process" class="fb-process">
        <div class="pf-wrap">
            <div class="pf-head">
                <div>
                    <span class="pf-eyebrow">/// HOW IT WORKS</span>
                    <h2 class="pf-h2">FROM IDEA TO IGNITION.</h2>
                </div>
                <p class="pf-note">Custom doesn’t mean complicated. Our process keeps you fully informed from the initial consultation to the final shakedown.</p>
            </div>
            
            <ol class="fb-steps">
                <?php foreach ($steps as [$n, $t,$d]): ?>
                <li>
                    <i><?php echo e($n); ?></i>
                    <h3><?php echo e($t); ?></h3>
                    <p><?php echo e($d); ?></p>
                </li>
                <?php endforeach; ?>
            </ol>
        </div>
    </section>

    <!-- SECTION: BOOK A CONSULTATION FORM -->
    <section id="book" class="fb-book">
        <div class="pf-wrap fb-book-grid">
            <div>
                <span class="pf-eyebrow">/// READY WHEN YOU ARE</span>
                <h2 class="fb-book-h">BOOK A<br>CONSULTATION.</h2>
                <p class="fb-book-lead">Tell us about your vehicle and what your build goals are. Our service advisor will contact you to confirm a time.</p>
                
                <div class="fb-call">
                    <small>PREFER TO CALL?</small>
                    <a href="tel:<?php echo e(preg_replace('/[^\d+]/', '', $phone)); ?>"><?php echo e($phone); ?></a>
                </div>
            </div>

            <div class="fb-form">
                <form method="post" action="Fabrication.php#book" style="grid-column: span 2;" novalidate>
                    <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
                    <input type="text" name="website" class="fb-hp" tabindex="-1" autocomplete="off" aria-hidden="true">

                    <div class="form-grid-2col">
                        <?php if ($booked): ?>
                            <div class="fb-alert fb-ok">Request received. Our service advisor will contact you shortly to confirm a time.</div>
                        <?php endif; ?>
                        <?php if ($errors): ?>
                            <div class="fb-alert fb-err">
                                <?php foreach ($errors as $m) { echo '<div>' . e($m) . '</div>'; } ?>
                            </div>
                        <?php endif; ?>

                        <div class="fb-field">
                            <label for="f-name">YOUR NAME (*)</label>
                            <input id="f-name" name="name" type="text" placeholder="Full name" value="<?php echo e($old['name']); ?>" required>
                        </div>
                        <div class="fb-field">
                            <label for="f-phone">PHONE NUMBER (*)</label>
                            <input id="f-phone" name="phone" type="tel" placeholder="(555) 000-0000" value="<?php echo e($old['phone']); ?>" required>
                        </div>

                        <div class="fb-field">
                            <label for="f-vehicle">VEHICLE (*)</label>
                            <input id="f-vehicle" name="vehicle" type="text" placeholder="Year, make &amp; model" value="<?php echo e($old['vehicle']); ?>" required>
                        </div>
                        <div class="fb-field">
                            <label for="f-service">SERVICE NEEDED (*)</label>
                            <select id="f-service" name="service" required>
                                <option value="">Select an upgrade</option>
                                <?php foreach ($service_options as$opt): ?>
                                    <option value="<?php echo e($opt); ?>"<?php echo $old['service'] ===$opt ? ' selected' : ''; ?>><?php echo e($opt); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="fb-field fb-full">
                            <label for="f-msg">TELL US ABOUT YOUR GOALS</label>
                            <textarea id="f-msg" name="message" rows="3" maxlength="1000" placeholder="Describe any specific parts, goals, or concerns..."><?php echo e($old['message']); ?></textarea>
                        </div>

                        <div class="fb-full">
                            <button type="submit" class="btn-figma-primary" style="width: 100%; padding: 16px; font-size: 14px;">
                                REQUEST APPOINTMENT &rarr;
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </section>

    <!-- SECTION: FOOTER -->
    <footer class="site-footer">
        <div class="container">
            <div class="footer-grid" style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr; gap: 40px;">
                <div class="footer-brand">
                    <a href="index.php" class="brand-logo">
                        <span class="logo-slashes">///</span> <?php echo $site_info['name']; ?>
                    </a>
                    <p style="margin-top: 16px; font-size: 14px; line-height: 1.6;">
                        <?php echo $site_info['address']; ?><br>
                        <?php echo $site_info['phone']; ?>
                    </p>
                </div>

                <div class="footer-links">
                    <h4>SERVICES</h4>
                    <ul>
                        <li><a href="services.php">Factory Service</a></li>
                        <li><a href="performance.php">Engine & ECU Tuning</a></li>
                        <li><a href="Fabrication.php">Custom Exhaust</a></li>
                        <li><a href="track.php">Track Repair Status</a></li>
                    </ul>
                </div>

                <div class="footer-links">
                    <h4>COMPANY</h4>
                    <ul>
                        <li><a href="index.php#about">About Us</a></li>
                        <li><a href="index.php#work">Featured Builds</a></li>
                        <li><a href="index.php#faq">FAQ</a></li>
                    </ul>
                </div>

                <div class="footer-links">
                    <h4>HOURS</h4>
                    <p style="font-size: 14px; line-height: 1.6;">
                        Mon - Fri: 8:00 AM - 6:00 PM<br>
                        Saturday: 9:00 AM - 3:00 PM<br>
                        Sunday: Closed
                    </p>
                </div>
            </div>

            <div class="footer-bottom">
                <p>&copy; <?php echo date('Y'); ?> REDLINE MOTORWORKS. ALL RIGHTS RESERVED.</p>
                <div>
                    <a href="track.php" style="color: var(--text-muted); text-decoration: none; margin-left: 20px;">TRACK REPAIR</a>
                    <a href="#" style="color: var(--text-muted); text-decoration: none; margin-left: 20px;">PRIVACY POLICY</a>
                </div>
            </div>
        </div>
    </footer>

    <!-- SERVICE CONSULTATION MODAL POPUP -->
    <div id="bookingModal" class="modal-overlay">
        <div class="modal-card">
            <button class="modal-close" onclick="closeBookingModal()">&times;</button>
            <h3>BOOK A FABRICATION CONSULTATION</h3>
            <form action="Fabrication.php#book" method="POST">
                <div class="fb-field">
                    <input type="text" name="name" placeholder="Full Name *" required>
                </div>
                <div class="fb-field">
                    <input type="tel" name="phone" placeholder="Phone Number *" required>
                </div>
                <div class="fb-field">
                    <select name="service" required>
                        <option value="" disabled selected>-- Select Fabrication Service --</option>
                        <?php foreach ($service_options as$opt): ?>
                            <option value="<?php echo e($opt); ?>"><?php echo e($opt); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fb-field">
                    <textarea name="message" placeholder="Vehicle details & specific fabrication requirements..."></textarea>
                </div>
                <button type="submit" class="btn-figma-primary" style="width: 100%; padding: 14px;">SUBMIT BOOKING REQUEST</button>
            </form>
        </div>
    </div>

    <!-- JAVASCRIPT FOR DYNAMIC TABS & CHARTS -->
    <script>
        const $ = id => document.getElementById(id);
        const money = n => '$' + n.toLocaleString('en-US');

        const CMP = [
          {k:'WHEELS', s:'Wheels & tyres', c:2200, v:9, f:9, g:'w'}, 
          {k:'COILOVERS', s:'Coilovers', c:1400, v:8, f:8, g:'w'},
          {k:'AIR RIDE', s:'Air suspension', c:4200, v:10, f:5, g:'w'}, 
          {k:'AERO', s:'Aero kit', c:900, v:8, f:6, g:'a'},
          {k:'CARBON', s:'Carbon hood', c:1800, v:7, f:6, g:'ab'}, 
          {k:'WIDEBODY', s:'Widebody kit', c:5500, v:10, f:7, g:'ab'},
          {k:'BRACING', s:'Chassis braces', c:450, v:3, f:7, g:'b'}
        ];

        const sum = (a, f) => a.reduce((t, i) => t + f(i), 0);
        const avg = (a, f) => sum(a, f) / a.length;

        function tabs(el, list, cb) {
          el.innerHTML = list.map((t, i) => `<button type="button" class="${i ? '' : 'on'}" data-i="${i}"><b>${t[1]}</b></button>`).join('');
          el.onclick = e => {
            const b = e.target.closest('button'); if (!b) return;
            el.querySelectorAll('button').forEach(x => x.classList.toggle('on', x === b));
            cb(list[b.dataset.i][0]);
          };
          cb(list[0][0]);
        }

        function stats(el, cells) {
          el.innerHTML = cells.map(c => `<div><small>${c[0]}</small><strong>${c[1]}</strong></div>`).join('');
        }

        function chart(el, items, series, lab) {
          const max = series.map(s => s.fixed || Math.max(1, ...items.map(s.val)));
          el.innerHTML = `<div class="legend">${series.map(s => `<span><i class="${s.cls}"></i>${s.name}</span>`).join('')}</div>
            <div class="scroll"><div class="plot" style="--n:${items.length}">${items.map(it => `<div class="gr"><div class="lab">${lab(it)}</div>
            <div class="bars">${series.map((s, i) => `<b class="${s.cls}" data-h="${Math.max(s.val(it) / max[i] * 100, 1.5)}"></b>`).join('')}</div>
            <div class="nm">${it.k}</div><div class="sub">${it.s}</div></div>`).join('')}</div></div>`;
          requestAnimationFrame(() => requestAnimationFrame(() => el.querySelectorAll('.bars b').forEach(b => b.style.height = b.dataset.h + '%')));
        }

        tabs($('cmpTabs'), [['', 'ALL UPGRADES'], ['w', 'WHEEL & STANCE'], ['a', 'AERODYNAMICS'], ['b', 'BODY & CHASSIS']], g => {
          const d = CMP.filter(i => !g || i.g.includes(g));
          stats($('cmpStats'), [['AVERAGE INVESTMENT', money(Math.round(avg(d, i => i.c)))], ['AVERAGE VISUAL IMPACT', avg(d, i => i.v).toFixed(1) + ' / 10'],
            ['AVERAGE FUNCTIONAL GAIN', avg(d, i => i.f).toFixed(1) + ' / 10']]);
          chart($('cmpChart'), d, [{name: 'INVESTMENT', cls: 'a', val: i => i.c}, {name: 'VISUAL IMPACT', cls: 'br', val: i => i.v, fixed: 10}, {name: 'FUNCTIONAL GAIN', cls: 'o', val: i => i.f, fixed: 10}],
            i => `<span>${money(i.c)}</span><span class="o">${i.v}/10</span><span class="g">${i.f}/10</span>`);
        });

        document.querySelectorAll('.fb-link[data-service]').forEach(a => {
          a.addEventListener('click', () => { $('f-service').value = a.dataset.service; });
        });

        function openBookingModal() {
            document.getElementById('bookingModal').classList.add('active');
        }
        function closeBookingModal() {
            document.getElementById('bookingModal').classList.remove('active');
        }

        window.addEventListener('click', (e) => {
            const modal = document.getElementById('bookingModal');
            if (e.target === modal) {
                closeBookingModal();
            }
        });
    </script>
</body>
</html>