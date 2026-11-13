<?php
/**
 * Redline Motorworks - Forgot Password (UI only)
 * Vị trí tệp: www/forgot-password.php
 */

require_once __DIR__ . '/src/includes/auth.php';
start_session();

// Đã đăng nhập thì về trang chủ
if (current_staff() || current_user()) {
    header('Location: index.php');
    exit;
}

$error   = '';
$success = false;
$email   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));

    if (!csrf_valid($_POST['csrf'] ?? null)) {
        $error = 'Phiên làm việc đã hết hạn. Vui lòng tải lại trang và thử lại.';
    } elseif ($email === '') {
        $error = 'Vui lòng nhập địa chỉ Email.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Định dạng Email không hợp lệ.';
    } else {
        // TODO: tạo token + gửi email đặt lại mật khẩu ở đây.
        // Hiện tại chỉ hiển thị giao diện thông báo thành công.
        $success = true;
    }
}

// Ảnh nền dùng chung với trang login: www/public/image/login-bg.*
$bg_image = null;
$img_dir  = __DIR__ . '/public/image/';
if (is_dir($img_dir)) {
    foreach (scandir($img_dir) as $file) {
        if (strcasecmp(pathinfo($file, PATHINFO_FILENAME), 'login-bg') === 0
            && preg_match('/\.(jpe?g|png|webp|avif)$/i', $file)) {
            $bg_image = 'public/image/' . rawurlencode($file);
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
    <title>Reset password - Redline Customer Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="public/assets/login.css?v=<?php echo file_exists(__DIR__ . '/public/assets/login.css') ? filemtime(__DIR__ . '/public/assets/login.css') : time(); ?>">
    <link rel="stylesheet" href="public/assets/style.css">
    <style>
        /* Khối thông báo thành công (đồng bộ với .form-error của login) */
        .form-success {
            margin-bottom: 22px;
            padding: 14px 18px;
            font-size: 13px;
            line-height: 1.6;
            color: #166534;
            background: #F0FDF4;
            border: 1px solid #86EFAC;
            border-left-width: 4px;
            border-radius: 8px;
        }
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 28px;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.12em;
            text-transform: uppercase;
            color: var(--accent);
        }
        .back-link:hover { color: var(--accent-hover); text-decoration: underline; }
    </style>
</head>
<body class="login-page">

<main class="login-layout">

    <!-- ===== CỘT TRÁI: ẢNH + THÔNG ĐIỆP ===== -->
    <section class="login-visual<?php echo $bg_image ? '' : ' no-image'; ?>"
    <?php if ($bg_image): ?>style="background-image: url('<?php echo e($bg_image); ?>');"<?php endif; ?>>

        <div class="visual-top">
            <a href="index.php" class="login-logo" aria-label="Redline home">
                <span class="logo-slashes">///</span>REDLINE
            </a>
            <p class="need-help">Need help? <a href="tel:5550198871">(555) 019-8871</a></p>
        </div>

        <?php if (!$bg_image): ?>
            <p class="bg-placeholder">[ public/image/login-bg.jpg ]</p>
        <?php endif; ?>

        <div class="visual-copy">
            <p class="visual-eyebrow"><span>/</span> Customer portal</p>
            <h1 class="visual-title">Locked out?<br>Back in gear.</h1>
            <p class="visual-text">
                Enter the email linked to your account and we&rsquo;ll send you a secure link to set a new password.
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
            <p class="panel-eyebrow">Account recovery</p>
            <h2 class="panel-title">Forgot password?</h2>
            <p class="panel-sub">No worries. Enter your email and we&rsquo;ll send reset instructions.</p>

            <form class="login-form" method="post" action="forgot-password.php" novalidate>
                <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">

                <?php if ($error): ?>
                    <div class="form-error" role="alert"><?php echo e($error); ?></div>
                <?php endif; ?>

                <?php if ($success): ?>
                    <div class="form-success" role="status">
                        If an account exists for <strong><?php echo e($email); ?></strong>,
                        you&rsquo;ll receive an email with instructions to reset your password shortly.
                    </div>
                <?php endif; ?>

                <div class="field">
                    <label class="field-label" for="email">Email address</label>
                    <input class="field-input" type="email" id="email" name="email"
                           value="<?php echo e($email); ?>" placeholder="you@example.com"
                           autocomplete="email" required autofocus>
                </div>

                <button type="submit" class="btn-signin">
                    <span>Send reset link</span>
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M7 17 17 7M8 7h9v9"/></svg>
                </button>
            </form>

            <a href="login.php" class="back-link">
                <svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M17 7 7 17M16 17H7V8"/></svg>
                Back to sign in
            </a>

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

</body>
</html>
