<?php
/**
 * Redline - Custom Fabrication page (nằm trong folder Fabrication)
 * Header/Footer/Typography dùng chung với Performance_Upgardes/performance.css
 * Sections: Hero / What we build / Build comparison / Craft / Selected projects / How it works / Book
 */
require_once __DIR__ . '/../includes/auth.php';
start_session();

$phone = '(555) 014-1986';

// --- NỘI DUNG (sửa tại đây) ---
$stats = [
    ['12+',  'YEARS FABRICATING'],
    ['400+', 'CUSTOM PROJECTS'],
    ['100%', 'BUILT IN-HOUSE'],
    ['01',   'VISION AT A TIME'],
];

// số, tiêu đề, mô tả, ảnh
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

// nhãn, tiêu đề, ảnh, vị trí ảnh
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

// --- XỬ LÝ FORM ĐẶT LỊCH (lưu chung bảng service_requests với trang Services) ---
$user   = current_user();
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
                'vehicle' => $old['vehicle'], 'service' => 'FABRICATION: ' . $old['service'],
                'msg' => $old['message'] !== '' ? $old['message'] : null, 'ip' => client_ip(),
            ]);
            $_SESSION['booking_ok'] = true;
            header('Location: fabrication.php#book');
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
    <title>Custom Fabrication - Redline</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@500;600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <!-- Nền chung (header, nút, tabs, chart, footer) lấy từ trang Performance để header luôn đồng nhất -->
    <link rel="stylesheet" href="../Performance_Upgardes/performance.css?v=<?php echo filemtime(__DIR__ . '/../Performance_Upgardes/performance.css'); ?>">
    <link rel="stylesheet" href="fabrication.css?v=<?php echo filemtime(__DIR__ . '/fabrication.css'); ?>">
</head>
<body>

<header class="pf-header">
    <div class="pf-wrap pf-hrow">
        <a href="../index.php" class="pf-logo">
            <svg width="26" height="26" viewBox="0 0 26 26" fill="#ee5a28"><path d="M3 24 7 5h3L6 24zM11 24l4-14h3l-4 14zM19 24l2.5-9H25l-2.5 9z"/></svg>REDLINE
        </a>
        <nav class="pf-nav">
            <a href="../ServicesAndRepair/services.php">SERVICE &amp; REPAIR</a>
            <a href="../Performance_Upgardes/performance.php">PERFORMANCE</a>
            <a href="fabrication.php" class="on">FABRICATION</a>
        </nav>
        <a href="#book" class="pf-btn">START A BUILD <span>&rarr;</span></a>
    </div>
</header>

<!-- HERO -->
<section class="pf-hero fb-hero">
    <div class="pf-hero-img"><img src="img/hero-taillight.jpg" alt="Close-up of a custom-built car's tail light"></div>
    <div class="pf-wrap pf-hero-in">
        <div class="pf-crumb"><a href="../index.php">HOME</a> / <a href="../ServicesAndRepair/services.php">SERVICES</a> / <a href="../Performance_Upgardes/performance.php">PERFORMANCE</a> / <b>FABRICATION</b></div>
        <span class="pf-eyebrow">CUSTOM FABRICATION / BUILT IN-HOUSE</span>
        <h1 class="pf-h">BUILT TO FIT.<br>BUILT TO <em>LAST.</em></h1>
        <p class="pf-lead">One-off metalwork and vehicle modifications engineered with purpose, fabricated by hand, and finished without compromise.</p>
        <div class="pf-cta">
            <a href="#book" class="pf-btn pf-btn-lg">BOOK A CONSULTATION <span>&rarr;</span></a>
            <a href="#capabilities" class="pf-btn pf-btn-lg pf-ghost">EXPLORE CAPABILITIES <span>&darr;</span></a>
        </div>
    </div>
    <div class="pf-wrap"><div class="fb-strip">
        <?php foreach ($stats as [$v, $l]): ?>
        <div><strong><?php echo e($v); ?></strong><small><?php echo e($l); ?></small></div>
        <?php endforeach; ?>
    </div></div>
</section>

<!-- WHAT WE BUILD -->
<section id="capabilities" class="pf-sec fb-sec">
    <div class="pf-wrap">
        <div class="pf-head">
            <div><span class="pf-eyebrow sm">WHAT WE BUILD</span><h2 class="pf-h2 fb-h2">NO CATALOG.<br>NO COMPROMISE.</h2></div>
            <p class="pf-note">The right part doesn’t always exist. That’s where we come in. Every component is designed around your car, your goals, and how you drive.</p>
        </div>
        <div class="fb-caps">
            <?php foreach ($caps as [$n, $t, $d, $img, $alt]): ?>
            <article>
                <figure><img src="img/<?php echo e($img); ?>" alt="<?php echo e($alt); ?>" loading="lazy"><i><?php echo e($n); ?></i></figure>
                <h3><?php echo e($t); ?></h3>
                <p><?php echo e($d); ?></p>
                <a href="#book" class="fb-link" data-service="<?php echo e($t); ?>">DISCUSS THIS SERVICE <span>&rarr;</span></a>
            </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- BUILD COMPARISON -->
<section class="pf-sec pf-sec3">
    <div class="pf-wrap">
        <div class="pf-head">
            <div><span class="pf-eyebrow sm">BUILD COMPARISON</span><h2 class="pf-h2 fb-h2">PLAN YOUR<br>NEXT MOVE.</h2></div>
            <p class="pf-note">Compare typical investment, visual impact, and functional gain across popular upgrades before mapping out your build.</p>
        </div>
        <div class="pf-tabs" id="cmpTabs"></div>
        <div class="pf-stats c3" id="cmpStats"></div>
        <div class="pf-chart" id="cmpChart"></div>
        <p class="pf-foot">Scores are directional planning guides based on a typical road-focused installation.</p>
    </div>
</section>

<!-- CRAFT -->
<section class="fb-craft">
    <div class="fb-craft-img"><img src="img/welder.jpg" alt="Fabricator grinding a weld seam, sparks flying" loading="lazy"></div>
    <div class="fb-craft-txt">
        <span class="pf-eyebrow sm">MADE, NOT ORDERED</span>
        <h2 class="pf-h2 fb-h2">CRAFT IS IN<br>THE DETAILS.</h2>
        <p class="pf-note">Clean welds are only the visible part. We obsess over routing, clearances, serviceability, material choice, and the way every piece integrates with the car as a whole.</p>
        <dl class="fb-facts">
            <?php foreach ($craft as [$v, $l]): ?>
            <div><dt><?php echo e($v); ?></dt><dd><?php echo e($l); ?></dd></div>
            <?php endforeach; ?>
        </dl>
        <a href="#book" class="pf-btn pf-btn-lg pf-ghost">TALK TO A FABRICATOR <span>&rarr;</span></a>
    </div>
</section>

<!-- SELECTED PROJECTS -->
<section id="work" class="pf-sec fb-sec fb-work">
    <div class="pf-wrap">
        <div class="pf-head fb-work-head">
            <div><span class="pf-eyebrow sm">SELECTED PROJECTS</span><h2 class="pf-h2 fb-h2">THE WORK SPEAKS.</h2></div>
            <a href="#book" class="fb-link">START YOURS <span>&rarr;</span></a>
        </div>
        <div class="fb-projects">
            <?php foreach ($projects as $i => [$tag, $title, $img, $pos, $alt]): ?>
            <a href="#book" class="fb-proj fb-proj-<?php echo $i + 1; ?>">
                <img src="img/<?php echo e($img); ?>" alt="<?php echo e($alt); ?>" style="object-position:<?php echo e($pos); ?>" loading="lazy">
                <span><small><?php echo e($tag); ?></small><b><?php echo e($title); ?></b></span>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- HOW IT WORKS -->
<section id="process" class="fb-process">
    <div class="pf-wrap">
        <div class="pf-head">
            <div><span class="pf-eyebrow sm">HOW IT WORKS</span><h2 class="pf-h2 fb-h2">FROM IDEA<br>TO IGNITION.</h2></div>
            <p class="pf-note">Custom doesn’t mean complicated. Our process keeps you informed from the first conversation to the final shakedown.</p>
        </div>
        <ol class="fb-steps">
            <?php foreach ($steps as [$n, $t, $d]): ?>
            <li><i><?php echo e($n); ?></i><h3><?php echo e($t); ?></h3><p><?php echo e($d); ?></p></li>
            <?php endforeach; ?>
        </ol>
    </div>
</section>

<!-- BOOK A CONSULTATION -->
<section id="book" class="fb-book">
    <div class="pf-wrap fb-book-grid">
        <div>
            <span class="pf-eyebrow sm">READY WHEN YOU ARE</span>
            <h2 class="pf-h fb-book-h">BOOK A<br>CONSULTATION.</h2>
            <p class="pf-lead fb-book-lead">Tell us about your vehicle and what your build goals are. Our service advisor will contact you to confirm a time.</p>
            <div class="fb-call">
                <small>PREFER TO CALL?</small>
                <a href="tel:<?php echo e(preg_replace('/[^\d+]/', '', $phone)); ?>"><?php echo e($phone); ?></a>
            </div>
        </div>

        <form class="fb-form" method="post" action="fabrication.php#book" novalidate>
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <input type="text" name="website" class="fb-hp" tabindex="-1" autocomplete="off" aria-hidden="true">

            <?php if ($booked): ?>
                <div class="fb-alert fb-ok">Request received. Our service advisor will contact you shortly to confirm a time.</div>
            <?php endif; ?>
            <?php if ($errors): ?>
                <div class="fb-alert fb-err"><?php foreach ($errors as $m) { echo '<div>' . e($m) . '</div>'; } ?></div>
            <?php endif; ?>

            <div class="fb-field"><label for="f-name">YOUR NAME</label>
                <input id="f-name" name="name" type="text" placeholder="Full name" value="<?php echo e($old['name']); ?>" required></div>
            <div class="fb-field"><label for="f-phone">PHONE NUMBER</label>
                <input id="f-phone" name="phone" type="tel" placeholder="(555) 000-0000" value="<?php echo e($old['phone']); ?>" required></div>
            <div class="fb-field"><label for="f-vehicle">VEHICLE</label>
                <input id="f-vehicle" name="vehicle" type="text" placeholder="Year, make &amp; model" value="<?php echo e($old['vehicle']); ?>" required></div>
            <div class="fb-field"><label for="f-service">SERVICE NEEDED</label>
                <select id="f-service" name="service" required>
                    <option value="">Select an upgrade</option>
                    <?php foreach ($service_options as $opt): ?>
                        <option value="<?php echo e($opt); ?>"<?php echo $old['service'] === $opt ? ' selected' : ''; ?>><?php echo e($opt); ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="fb-field fb-full"><label for="f-msg">TELL US ABOUT YOUR GOALS</label>
                <textarea id="f-msg" name="message" rows="3" maxlength="1000" placeholder="Describe any specific parts, goals, or concerns"><?php echo e($old['message']); ?></textarea></div>

            <button type="submit" class="fb-submit fb-full">REQUEST APPOINTMENT <span>&rarr;</span></button>
        </form>
    </div>
</section>

<footer class="pf-footer">
    <div class="pf-wrap pf-frow">
        <a href="../index.php" class="pf-logo"><svg width="22" height="22" viewBox="0 0 26 26" fill="#ee5a28"><path d="M3 24 7 5h3L6 24zM11 24l4-14h3l-4 14zM19 24l2.5-9H25l-2.5 9z"/></svg>REDLINE</a>
        <span>Custom fabrication. No shortcuts.</span>
        <nav><a href="#capabilities">Capabilities</a><a href="#work">Our Work</a><a href="#book">Contact</a></nav>
        <span>© <?php echo date('Y'); ?> REDLINE AUTOMOTIVE</span>
    </div>
</footer>

<script>
const $ = id => document.getElementById(id);
const money = n => '$' + n.toLocaleString('en-US');

// ---- DỮ LIỆU (sửa tại đây) ----
// c = chi phí ($), v = visual /10, f = functional /10, g = nhóm lọc: w=Wheel & stance, a=Aero, b=Body & chassis
const CMP = [
  {k:'WHEELS', s:'Wheels & tyres', c:2200, v:9, f:9, g:'w'}, {k:'COILOVERS', s:'Coilovers', c:1400, v:8, f:8, g:'w'},
  {k:'AIR RIDE', s:'Air suspension', c:4200, v:10, f:5, g:'w'}, {k:'AERO', s:'Aero kit', c:900, v:8, f:6, g:'a'},
  {k:'CARBON', s:'Carbon hood', c:1800, v:7, f:6, g:'ab'}, {k:'WIDEBODY', s:'Widebody kit', c:5500, v:10, f:7, g:'ab'},
  {k:'BRACING', s:'Chassis braces', c:450, v:3, f:7, g:'b'}];

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

// "DISCUSS THIS SERVICE" -> chọn sẵn dịch vụ trong form
document.querySelectorAll('.fb-link[data-service]').forEach(a => {
  a.addEventListener('click', () => { $('f-service').value = a.dataset.service; });
});
</script>
</body>
</html>