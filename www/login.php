<?php
/**
 * Redline Motorworks - Customer Portal & Staff Login
 * Vị trí tệp: www/src/modules/login.php
 */

// Lùi 1 cấp từ www/src/modules/login.php về www/src/includes/auth.php
require_once __DIR__ . '/src/includes/auth.php';
start_session();

// Nếu đã đăng nhập thì chuyển hướng ngay theo đường dẫn tuyệt đối
if (current_staff()) {
    header('Location: /REDLINE/www/src/modules/gara-management/index.php');
    exit;
}
if (current_user()) {
    header('Location: /REDLINE/www/src/modules/dashboard.php');
    exit;
}

$error = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = strtolower(trim($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $remember = !empty($_POST['remember']);
    $ip       = client_ip();

    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $error = 'Phiên làm việc đã hết hạn. Vui lòng tải lại trang và thử lại.';
    } elseif ($email === '' || $password === '') {
        $error = 'Vui lòng nhập đầy đủ Email và Mật khẩu.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Định dạng Email không hợp lệ.';
    } else {
        try {
            if (is_locked_out($email, $ip)) {
                $error = 'Thử sai quá nhiều lần. Vui lòng thử lại sau ' . LOCKOUT_MINUTES . ' phút.';
            } else {
                // 1) Kiểm tra tài khoản khách hàng
                $stmt = db()->prepare('SELECT id, full_name, email, password_hash, role FROM portal_users WHERE email = :email AND is_active = 1');
                $stmt->execute(['email' => $email]);
                $user = $stmt->fetch();

                // 2) Kiểm tra tài khoản quản trị (bảng users)
                $staff = $user ? null : find_staff_by_email($email);
                $account = $user ?: $staff;

                $hash = $account['password_hash'] ?? '$2y$10$usesomesillystringforsaleuseless.ThisIsNotARealHash0000000';
                $ok   = password_verify($password, $hash);

                if ($account && $ok) {
                    clear_failed_attempts($email, $ip);

                    if ($staff) {
                        login_staff($staff);
                        header('Location: /REDLINE/www/src/modules/gara-management/index.php');
                        exit;
                    }

                    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                        db()->prepare('UPDATE portal_users SET password_hash = :h WHERE id = :id')
                            ->execute(['h' => password_hash($password, PASSWORD_DEFAULT), 'id' => $user['id']]);
                    }
                    login_user($user);
                    if ($remember) {
                        create_remember_token((int) $user['id']);
                    }
                    header('Location: /REDLINE/www/src/modules/dashboard.php');
                    exit;
                }

                record_failed_attempt($email, $ip);
                $error = 'Email hoặc mật khẩu không chính xác.';
            }
        } catch (PDOException $ex) {
            error_log('Login DB error: ' . $ex->getMessage());
            $error = 'Không thể kết nối cơ sở dữ liệu lúc này. Vui lòng thử lại sau.';
        }
    }
}

// Kiểm tra ảnh nền từ www/public/image/
$bg_image = null;
$img_dir  = dirname(__DIR__, 2) . '/public/image/';
if (is_dir($img_dir)) {
    foreach (scandir($img_dir) as $file) {
        if (strcasecmp(pathinfo($file, PATHINFO_FILENAME), 'login-bg') === 0
            && preg_match('/\.(jpe?g|png|webp|avif)$/i', $file)) {
            $bg_image = '/REDLINE/www/public/image/' . rawurlencode($file);
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sign in - Redline Customer Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/REDLINE/www/public/assets/login.css?v=<?php echo file_exists(dirname(__DIR__, 2) . '/public/assets/login.css') ? filemtime(dirname(__DIR__, 2) . '/public/assets/login.css') : time(); ?>">
    <link rel="stylesheet" href="/REDLINE/www/public/assets/style.css">
</head>
<body class="login-page">

<main class="login-layout">

    <!-- ===== CỘT TRÁI: ẢNH + THÔNG ĐIỆP ===== -->
    <section class="login-visual<?php echo $bg_image ? '' : ' no-image'; ?>"
        <?php if ($bg_image): ?>style="--login-bg: url('<?php echo e($bg_image); ?>');"<?php endif; ?>>

        <div class="visual-top">
            <a href="/REDLINE/www/index.php" class="login-logo" aria-label="Redline home">
                <span class="logo-slashes">///</span>REDLINE
            </a>
            <p class="need-help">Need help? <a href="tel:5550198871">(555) 019-8871</a></p>
        </div>

        <?php if (!$bg_image): ?>
            <p class="bg-placeholder">[ public/image/login-bg.jpg ]</p>
        <?php endif; ?>

        <div class="visual-copy">
            <p class="visual-eyebrow"><span>/</span> Customer portal</p>
            <h1 class="visual-title">Your garage.<br>One login.</h1>
            <p class="visual-text">
                Review estimates, approve work, track service progress, and access your vehicle history&mdash;all in one place.
            </p>
        </div>

        <div class="visual-bottom">
            <span>Independent automotive specialists</span>
            <span>Portland, Oregon</span>
        </div>
    </section>

    <!-- ===== CỘT PHẢI: FORM ===== -->
    <section class="login-panel">
        <div class="panel-main">
            <p class="panel-eyebrow">Secure customer access</p>
            <h2 class="panel-title">Welcome back.</h2>
            <p class="panel-sub">Sign in to manage your vehicles and active service.</p>

            <form class="login-form" method="post" action="login.php" novalidate>
                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">

                <?php if ($error): ?>
                    <div class="form-error" role="alert"><?php echo e($error); ?></div>
                <?php endif; ?>

                <div class="field">
                    <label class="field-label" for="email">Email address</label>
                    <input class="field-input" type="email" id="email" name="email"
                           value="<?php echo e($email); ?>" placeholder="you@example.com"
                           autocomplete="username" required <?php echo $email === '' ? 'autofocus' : ''; ?>>
                </div>

                <div class="field">
                    <div class="field-row">
                        <label class="field-label" for="password">Password</label>
                        <button type="button" class="show-toggle" id="togglePassword"
                                aria-controls="password" aria-pressed="false">Show</button>
                    </div>
                    <input class="field-input" type="password" id="password" name="password"
                           placeholder="Enter your password" autocomplete="current-password"
                           required <?php echo $email !== '' ? 'autofocus' : ''; ?>>
                </div>

                <div class="form-options">
                    <label class="check">
                        <input type="checkbox" name="remember" value="1">
                        <span class="check-box" aria-hidden="true"></span>
                        <span>Keep me signed in</span>
                    </label>
                    <a href="mailto:contact@redlinemotorworks.com?subject=Password%20reset" class="link-underline">Forgot password?</a>
                </div>

                <button type="submit" class="btn-signin">
                    <span>Sign in to your garage</span>
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M7 17 17 7M8 7h9v9"/></svg>
                </button>
            </form>

            <div class="first-time">
                <h3>First time here?</h3>
                <p>Create a new account to manage your vehicles, review estimates, and track active service online.</p>
                <a href="register.php" class="request-access">
                    Create an account
                    <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M7 17 17 7M8 7h9v9"/></svg>
                </a>
            </div>
        </div>

        <div class="panel-footer">
            <span>&copy; <?php echo date('Y'); ?> Redline Garage</span>
            <a href="mailto:contact@redlinemotorworks.com">Contact support</a>
        </div>
    </section>

</main>

<script>
    (function () {
        var btn = document.getElementById('togglePassword');
        var input = document.getElementById('password');
        if (btn && input) {
            btn.addEventListener('click', function () {
                var show = input.type === 'password';
                input.type = show ? 'text' : 'password';
                btn.textContent = show ? 'Hide' : 'Show';
                btn.setAttribute('aria-pressed', show ? 'true' : 'false');
            });
        }
    })();
</script>
</body>
</html>