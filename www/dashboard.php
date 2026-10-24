<?php
require_once __DIR__ . '/includes/auth.php';

$user = require_login();

$stmt = db()->prepare('SELECT id, make, model, year, plate FROM portal_vehicles WHERE user_id = :uid ORDER BY id DESC');
$stmt->execute(['uid' => $user['id']]);
$vehicles = $stmt->fetchAll();

$stmt = db()->prepare(
    "SELECT s.title, s.status, s.estimate, s.updated_at, v.make, v.model
     FROM service_orders s
     JOIN portal_vehicles v ON v.id = s.vehicle_id
     WHERE v.user_id = :uid AND s.status NOT IN ('completed','cancelled')
     ORDER BY s.updated_at DESC"
);
$stmt->execute(['uid' => $user['id']]);
$orders = $stmt->fetchAll();

$status_labels = [
    'estimate'    => 'Estimate ready',
    'approved'    => 'Approved',
    'in_progress' => 'In progress',
    'ready'       => 'Ready for pickup',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your garage - Redline Customer Portal</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="public/assets/login.css?php echo filemtime(__DIR__ . '/login.css'); ?>">
</head>
<body>

<header class="portal-header">
    <a href="index.php" class="login-logo"><span class="logo-slashes">///</span>REDLINE</a>
    <div class="portal-user">
        <span><?php echo e($user['email']); ?></span>
        <form method="post" action="logout.php">
            <input type="hidden" name="csrf" value="<?php echo e(csrf_token()); ?>">
            <button type="submit" class="show-toggle" style="margin:0">Sign out</button>
        </form>
    </div>
</header>

<main class="portal-main">
    <p class="panel-eyebrow">Customer portal</p>
    <h1>Hello, <?php echo e(explode(' ', $user['full_name'])[0]); ?>.</h1>

    <section class="portal-section">
        <h2>Active service</h2>
        <?php if ($orders): ?>
            <table class="portal-table">
                <thead><tr><th>Vehicle</th><th>Work</th><th>Status</th><th>Estimate</th></tr></thead>
                <tbody>
                <?php foreach ($orders as $o): ?>
                    <tr>
                        <td><?php echo e($o['make'] . ' ' . $o['model']); ?></td>
                        <td><?php echo e($o['title']); ?></td>
                        <td><span class="status-pill is-active"><?php echo e($status_labels[$o['status']] ?? $o['status']); ?></span></td>
                        <td><?php echo $o['estimate'] !== null ? '$' . number_format((float) $o['estimate'], 2) : '&mdash;'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="portal-empty">No active service. Book an appointment and your estimate will appear here.</p>
        <?php endif; ?>
    </section>

    <section class="portal-section">
        <h2>Your vehicles</h2>
        <?php if ($vehicles): ?>
            <table class="portal-table">
                <thead><tr><th>Vehicle</th><th>Year</th><th>Plate</th></tr></thead>
                <tbody>
                <?php foreach ($vehicles as $v): ?>
                    <tr>
                        <td><?php echo e($v['make'] . ' ' . $v['model']); ?></td>
                        <td><?php echo e((string) $v['year']); ?></td>
                        <td><?php echo e($v['plate']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else: ?>
            <p class="portal-empty">No vehicles on file yet.</p>
        <?php endif; ?>
    </section>
</main>

</body>
</html>
