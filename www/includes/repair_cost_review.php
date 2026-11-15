<?php
/** Phase 4: deterministic reviewed costs; all entrypoints must call before invoicing. */
declare(strict_types=1);

function rl_cost_snapshot(PDO $pdo, int $orderId): array
{
    // Deterministic item state: any subsequent item edit invalidates a prior review.
    $stmt = $pdo->prepare('SELECT id, item_type, item_name, quantity, unit_price, subtotal FROM repair_order_items WHERE repair_order_id = ? ORDER BY id');
    $stmt->execute([$orderId]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $labor = 0.0; $parts = 0.0;
    foreach ($rows as $row) {
        $amount = round((float)$row['subtotal'], 2);
        if (!is_finite($amount) || $amount < 0 || (int)$row['quantity'] < 1 || (float)$row['unit_price'] < 0) {
            throw new RuntimeException('Repair order has invalid cost lines. Correct them before review.');
        }
        if ($row['item_type'] === 'labor') $labor += $amount;
        elseif ($row['item_type'] === 'part') $parts += $amount;
        else throw new RuntimeException('Repair order contains an unsupported item type.');
    }
    $pending = $pdo->prepare("SELECT COUNT(*) FROM part_requests WHERE order_id = ? AND status IN ('pending', 'approved')");
    $pending->execute([$orderId]);
    // Keep string fields so differences to prices, quantities or names change the snapshot.
    $hash = hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    return ['hash'=>$hash, 'labor'=>round($labor, 2), 'parts'=>round($parts, 2),
            'total'=>round($labor+$parts, 2), 'count'=>count($rows), 'pending'=>(int)$pending->fetchColumn()];
}

function rl_cost_verified(PDO $pdo, int $orderId, bool $lock = false): array
{
    $stmt = $pdo->prepare('SELECT * FROM repair_order_cost_reviews WHERE order_id = ?' . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([$orderId]);
    $review = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$review) throw new RuntimeException('Admin must review repair costs before creating an invoice.');
    $snapshot = rl_cost_snapshot($pdo, $orderId);
    if ($snapshot['pending'] !== 0) throw new RuntimeException('Resolve pending/approved part requests before invoicing.');
    if (!hash_equals((string)$review['items_hash'], $snapshot['hash'])
        || abs((float)$review['reviewed_total'] - $snapshot['total']) > 0.009) {
        throw new RuntimeException('Repair costs changed after approval. Admin must review them again.');
    }
    if ($review['review_type'] === 'zero') {
        if ($snapshot['total'] != 0.0 || mb_strlen(trim((string)$review['reason'])) < 10) {
            throw new RuntimeException('Zero-cost invoice requires an approved zero-cost reason and zero total.');
        }
    } elseif ($review['review_type'] === 'standard') {
        if ($snapshot['count'] === 0 || $snapshot['total'] <= 0) {
            throw new RuntimeException('Add priced repair items or approve an explicit zero-cost service.');
        }
    } else throw new RuntimeException('Unknown cost review type.');
    return ['review'=>$review, 'snapshot'=>$snapshot];
}
