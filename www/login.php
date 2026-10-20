<?php
/**
 * Redline Motorworks - Customer Portal Login
 */

require_once __DIR__ . '/includes/auth.php';
start_session();

// Đã đăng nhập thì vào thẳng garage
if (current_user()) {
    header('Location: dashboard.php');
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
        $error = 'Your session expired. Reload the page and try again.';
    } elseif ($email === '' || $password === '') {
        $error = 'Enter your email address and password.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } else {
        try {
            if (is_locked_out($email, $ip)) {
                $error = 'Too many failed attempts. Try again in ' . LOCKOUT_MINUTES . ' minutes or call the shop.';
            } else {
                $stmt = db()->prepare('SELECT id, full_name, email, password_hash, role FROM users WHERE email = :email AND is_active = 1');
                $stmt->execute(['email' => $email]);
                $user = $stmt->fetch();

                // Luôn chạy password_verify để thời gian phản hồi không lộ email nào tồn tại
                $hash = $user['password_hash'] ?? '$2y$10$usesomesillystringforsaleuseless.ThisIsNotARealHash0000000';
                $ok   = password_verify($password, $hash);

                if ($user && $ok) {
                    clear_failed_attempts($email, $ip);
                    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
                        db()->prepare('UPDATE users SET password_hash = :h WHERE id = :id')
                            ->execute(['h' => password_hash($password, PASSWORD_DEFAULT), 'id' => $user['id']]);
                    }
                    login_user($user);
                    if ($remember) {
                        create_remember_token((int) $user['id']);
                    }
                    header('Location: dashboard.php');
                    exit;
                }

                record_failed_attempt($email, $ip);
                $error = 'Email or password is incorrect.';
            }
        } catch (PDOException $ex) {
            error_log('Login DB error: ' . $ex->getMessage());
            $error = 'We could not reach the portal right now. Try again in a moment.';
        }
    }
}

/* --- Ảnh nền bên trái: đặt file image/login-bg.jpg (hoặc .png/.webp/.avif) --- */
$bg_image = null;
$img_dir  = __DIR__ . '/image/';
if (is_dir($img_dir)) {
    foreach (scandir($img_dir) as $file) {
        if (strcasecmp(pathinfo($file, PATHINFO_FILENAME), 'login-bg') === 0
            && preg_match('/\.(jpe?g|png|webp|avif)$/i', $file)) {
            $bg_image = 'image/' . rawurlencode($file);
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
    <link rel="stylesheet" href="login.css?v=<?php echo filemtime(__DIR__ . '/login.css'); ?>">
</head>
<body class="login-page">

<main class="login-layout">

    <!-- ===== CỘT TRÁI: ẢNH + THÔNG ĐIỆP ===== -->
    <section class="login-visual<?php echo $bg_image ? '' : ' no-image'; ?>"
        <?php if ($bg_image): ?>style="--login-bg: url('<?php echo e($bg_image); ?>');"<?php endif; ?>>

        <div class="visual-top">
            <a href="index.php" class="login-logo" aria-label="Redline home">
                <span class="logo-slashes">///</span>REDLINE
            </a>
            <p class="need-help">Need help? <a href="tel:5550198871">(555) 019-8871</a></p>
        </div>

        <?php if (!$bg_image): ?>
            <p class="bg-placeholder">[ image/login-bg.jpg ]</p>
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
                <p>Create a new account to manage your vehicles, review estimates, and track active service online.</p>                <a href="index.php#contact" class="request-access">
                <a href="register.php" class="request-access"    
                    Create an account
                    <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M7 17 17 7M8 7h9v9"/></svg>                </a>
                </a>
        </div>

        <div class="panel-footer">
            <span>&copy; <?php echo date('Y'); ?> Redline Garage</span>
            <a href="mailto:contact@redlinemotorworks.com">Contact support</a>
        </div>
    </section>

</main>

<script>
    // Nút SHOW / HIDE mật khẩu
    (function () {
        var btn = document.getElementById('togglePassword');
        var input = document.getElementById('password');
        btn.addEventListener('click', function () {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            btn.textContent = show ? 'Hide' : 'Show';
            btn.setAttribute('aria-pressed', show ? 'true' : 'false');
        });
    })();
</script>
</body>
</html>
