<?php
/**
 * Customer-only mini cart: a convenient summary of workshop service activity.
 * This is NOT a products checkout cart. No orders are created or modified here.
 */
declare(strict_types=1);
require_once __DIR__ . '/customer_appointments.php';

/**
 * The caller must authenticate the customer before invoking this function.
 * Both queries filter by the authenticated customer ID; nothing is stored in a cookie.
 */
function rl_cart_activity(PDO $pdo, int $customerId): array
{
    $summary = [
        'items' => [],
        'counts' => ['active' => 0, 'pending' => 0, 'assigned' => 0, 'ready' => 0],
        'total' => 0,
    ];
    if ($customerId < 1) return $summary;

    $bookings = rl_customer_appointments($pdo, $customerId);
    $coveredOrders = [];
    foreach ($bookings['items'] as $booking) {
        $group = (string) $booking['group'];
        $onlineAssigned = $group === 'upcoming'
            && (int) ($booking['order_id'] ?? 0) > 0
            && ($booking['order_status'] ?? '') === 'received'
            && ($booking['intake_mode'] ?? '') === 'online_booking';
        if ($group !== 'pending' && !$onlineAssigned) continue;

        $kind = $group === 'pending' ? 'pending' : 'assigned';
        $date = $booking['requested_at'] instanceof DateTimeImmutable
            ? $booking['requested_at']->format('d M Y · H:i') . ' (UTC+7)'
            : 'Preferred time not set';
        $summary['items'][] = [
            'kind' => $kind,
            'title' => (string) $booking['service'],
            'reference' => (string) $booking['reference'],
            'vehicle' => trim((string) $booking['vehicle'] . ($booking['plate'] !== '' ? ' · ' . $booking['plate'] : '')),
            'detail' => $date,
            'status' => $kind === 'pending' ? 'Awaiting admin approval' : 'Technician assigned · visit unconfirmed',
            'url' => '?page=appointments&tab=' . ($kind === 'pending' ? 'pending' : 'upcoming')
                . '#appointment-' . (int) $booking['id'],
        ];
        if ((int) ($booking['order_id'] ?? 0) > 0) {
            $coveredOrders[(int) $booking['order_id']] = true;
        }
    }

    // Orders created directly by the workshop, and bookings that have moved
    // into physical workshop work, are also visible without double-counting.
    $stmt = $pdo->prepare("SELECT r.id, r.order_code, r.status, r.assigned_to, r.intake_mode,
        r.service_type, v.brand, v.model, v.license_plate
        FROM repair_orders r
        JOIN vehicles v ON v.id = r.vehicle_id
        WHERE COALESCE(r.customer_id, v.customer_id) = :customer_id
          AND r.status NOT IN ('delivered', 'cancelled')
        ORDER BY r.updated_at DESC, r.id DESC");
    $stmt->execute(['customer_id' => $customerId]);
    $statusLabels = [
        'received' => 'Vehicle received',
        'diagnosing' => 'Diagnosis in progress',
        'repairing' => 'Repair in progress',
        'waiting_parts' => 'Waiting for parts',
        'inspecting' => 'Final inspection',
        'completed' => 'Ready for pickup',
    ];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $order) {
        $orderId = (int) $order['id'];
        if (isset($coveredOrders[$orderId])) continue;
        $status = (string) $order['status'];
        $onlineBookingReceived = ($order['intake_mode'] ?? '') === 'online_booking' && $status === 'received';
        if ($onlineBookingReceived) {
            $kind = empty($order['assigned_to']) ? 'pending' : 'assigned';
            $label = $kind === 'pending' ? 'Awaiting admin approval' : 'Technician assigned · visit unconfirmed';
        } else {
            $kind = $status === 'completed' ? 'ready' : 'active';
            $label = $statusLabels[$status] ?? 'In workshop';
        }
        $vehicle = trim((string) ($order['brand'] ?? '') . ' ' . (string) ($order['model'] ?? ''));
        $plate = trim((string) ($order['license_plate'] ?? ''));
        $summary['items'][] = [
            'kind' => $kind,
            'title' => trim((string) ($order['service_type'] ?? '')) ?: 'Workshop service',
            'reference' => (string) (($order['order_code'] ?? '') ?: '#RO-' . $orderId),
            'vehicle' => trim($vehicle . ($plate !== '' ? ' · ' . $plate : '')),
            'detail' => $onlineBookingReceived ? 'Preferred visit time not confirmed' : 'Workshop repair order',
            'status' => $label,
            'url' => $onlineBookingReceived ? '?page=appointments' : '?page=orders',
        ];
    }

    $priority = ['active' => 0, 'pending' => 1, 'assigned' => 2, 'ready' => 3];
    usort($summary['items'], static fn(array $a, array $b): int =>
        $priority[$a['kind']] <=> $priority[$b['kind']]);
    foreach ($summary['items'] as $item) ++$summary['counts'][$item['kind']];
    $summary['total'] = count($summary['items']);
    return $summary;
}

function rl_cart_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** HTML shared by the homepage and customer portal mini-cart panels. */
function rl_cart_panel(?array $activity, string $customerUrl, string $loginUrl): void
{
    $total = (int) ($activity['total'] ?? 0);
    $esc = static fn(string $str): string => rl_cart_escape($str);
    ?>
    <div class="cart-dropdown-heading">
      <strong>My Workshop Activity</strong>
      <span><?= $activity === null ? 'Account' : $total . ' ' . ($total === 1 ? 'item' : 'items') ?></span>
    </div>
    <?php if ($activity === null): ?>
      <div class="cart-dropdown-empty">
        <strong>Sign in to view your activity</strong>
        <p>See the appointments awaiting approval and the repair orders linked to your account.</p>
      </div>
      <div class="cart-dropdown-actions"><a class="cart-dropdown-primary" href="<?= $esc($loginUrl) ?>">Sign in</a></div>
    <?php elseif (!empty($activity['error'])): ?>
      <div class="cart-dropdown-empty"><strong>Activity temporarily unavailable</strong><p>Refresh the page to try again.</p></div>
      <div class="cart-dropdown-actions"><a class="cart-dropdown-secondary" href="<?= $esc($customerUrl) ?>?page=appointments">View appointments</a></div>
    <?php elseif ($total === 0): ?>
      <div class="cart-dropdown-empty">
        <strong>No active requests</strong>
        <p>Your pending appointments and workshop orders will appear here. Completed and cancelled requests remain in your history.</p>
      </div>
      <div class="cart-dropdown-actions"><a class="cart-dropdown-primary" href="<?= $esc($customerUrl) ?>?page=appointments">View appointments</a></div>
    <?php else: ?>
      <div class="cart-dropdown-summary" aria-label="Your request statuses">
        <?php foreach (['active' => 'In workshop', 'pending' => 'Awaiting approval', 'assigned' => 'Assigned', 'ready' => 'Ready'] as $kind => $caption): ?>
          <?php if (!empty($activity['counts'][$kind])): ?>
            <span class="cart-dropdown-summary-tag cart-dropdown-summary-tag--<?= $esc($kind) ?>"><?= (int) $activity['counts'][$kind] ?> <?= $esc($caption) ?></span>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
      <div class="cart-dropdown-items">
        <?php foreach (array_slice($activity['items'], 0, 6) as $item): ?>
          <a class="cart-dropdown-item" href="<?= $esc($customerUrl . $item['url']) ?>">
            <span class="cart-dropdown-item-top"><span class="cart-dropdown-reference"><?= $esc($item['reference']) ?></span><span class="cart-dropdown-status cart-dropdown-status--<?= $esc($item['kind']) ?>"><?= $esc($item['status']) ?></span></span>
            <strong><?= $esc($item['title']) ?></strong>
            <small><?= $esc($item['vehicle']) ?></small>
            <span class="cart-dropdown-time"><?= $esc($item['detail']) ?></span>
          </a>
        <?php endforeach; ?>
        <?php if ($total > 6): ?><p class="cart-dropdown-extra">+<?= $total - 6 ?> more requests in your account</p><?php endif; ?>
      </div>
      <div class="cart-dropdown-actions cart-dropdown-actions--two">
        <a class="cart-dropdown-primary" href="<?= $esc($customerUrl) ?>?page=appointments">Appointments</a>
        <a class="cart-dropdown-secondary" href="<?= $esc($customerUrl) ?>?page=orders">Repair Orders</a>
      </div>
    <?php endif;
}
