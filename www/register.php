<?php
/**
 * Redline Motorworks - Customer Portal Registration
 */

require_once __DIR__ . '/includes/auth.php';
start_session();

// Nếu đã đăng nhập thì chuyển thẳng sang trang dashboard
if (current_user()) {
    header('Location: dashboard.php');
    exit;
}

$error = '';
$full_name = '';
$email = '';
$phone = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $full_name        = trim($_POST['full_name'] ?? '');
    $email            = strtolower(trim($_POST['email'] ?? ''));
    $phone            = trim($_POST['phone'] ?? '');
    $password         = (string) ($_POST['password'] ?? '');
    $confirm_password = (string) ($_POST['confirm_password'] ?? '');

    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $error = 'Your session expired. Reload the page and try again.';
    } elseif ($full_name === '' || $email === '' || $password === '') {
        $error = 'Please fill in all required fields (Full name, Email, Password).';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Enter a valid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } else {
        try {
            // Kiểm tra xem email đã tồn tại trong cơ sở dữ liệu chưa
            $stmt = db()->prepare('SELECT id FROM portal_users WHERE email = :email LIMIT 1');
            $stmt->execute(['email' => $email]);
            if ($stmt->fetch()) {
                $error = 'An account with this email address already exists.';
            } else {
                // Thêm tài khoản mới vào cơ sở dữ liệu với vai trò customer
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = db()->prepare(
                    'INSERT INTO portal_users (full_name, email, phone, password_hash, role, is_active) 
                     VALUES (:full_name, :email, :phone, :hash, "customer", 1)'
                );
                $stmt->execute([
                    'full_name' => $full_name,
                    'email'     => $email,
                    'phone'     => $phone !== '' ? $phone : null,
                    'hash'      => $hash,
                ]);

                $new_id = (int) db()->lastInsertId();

                // Tự động đăng nhập sau khi đăng ký thành công
                login_user([
                    'id'        => $new_id,
                    'full_name' => $full_name,
                    'email'     => $email,
                    'role'      => 'customer'
                ]);

                header('Location: dashboard.php');
                exit;
            }
        } catch (PDOException $ex) {
            error_log('Register DB error: ' . $ex->getMessage());
            $error = 'Could not create account right now. Try again in a moment.';
        }
    }
}

/* --- Ảnh nền bên trái (sử dụng lại logic kiểm tra ảnh từ login.php) --- */
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
    <title>Create Account - Redline Customer Portal</title>
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
            <h1 class="visual-title">Join our<br>garage.</h1>
            <p class="visual-text">
                Create your account to manage vehicles, track service history, and review estimates online.
            </p>
        </div>

        <div class="visual-bottom">
            <span>Independent automotive specialists</span>
            <span>Portland, Oregon</span>
        </div>
    </section>

    <!-- ===== CỘT PHẢI: FORM ĐĂNG KÝ ===== -->
    <section class="login-panel">
        <div class="panel-main">
            <p class="panel-eyebrow">New customer access</p>
            <h2 class="panel-title">Create account.</h2>
            <p class="panel-sub">Fill out the information below to register your garage account.</p>

            <form class="login-form" method="post" action="register.php" novalidate>
                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">

                <?php if ($error): ?>
                    <div class="form-error" role="alert"><?php echo e($error); ?></div>
                <?php endif; ?>

                <div class="field">
                    <label class="field-label" for="full_name">Full name *</label>
                    <input class="field-input" type="text" id="full_name" name="full_name"
                           value="<?php echo e($full_name); ?>" placeholder="John Doe" required autofocus>
                </div>

                <div class="field">
                    <label class="field-label" for="email">Email address *</label>
                    <input class="field-input" type="email" id="email" name="email"
                           value="<?php echo e($email); ?>" placeholder="you@example.com"
                           autocomplete="username" required>
                </div>

                <div class="field">
                    <label class="field-label" for="phone">Phone number (optional)</label>
                    <input class="field-input" type="tel" id="phone" name="phone"
                           value="<?php echo e($phone); ?>" placeholder="(555) 019-8871"
                           autocomplete="tel">
                </div>

                <div class="field">
                    <label class="field-label" for="password">Password *</label>
                    <input class="field-input" type="password" id="password" name="password"
                           placeholder="At least 8 characters" autocomplete="new-password" required>
                </div>

                <div class="field">
                    <label class="field-label" for="confirm_password">Confirm password *</label>
                    <input class="field-input" type="password" id="confirm_password" name="confirm_password"
                           placeholder="Re-enter password" autocomplete="new-password" required>
                </div>

                <button type="submit" class="btn-signin" style="margin-top: 12px;">
                    <span>Create your account</span>
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M7 17 17 7M8 7h9v9"/></svg>
                </button>
            </form>

            <div class="first-time">
                <h3>Already registered?</h3>
                <p>If you already have an account, sign in to view your active services.</p>
                <a href="login.php" class="request-access">
                    Sign in to your garage
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

</body>
</html>