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

/** Validate the cost-review decision before writing an approval record. */
function rl_cost_review_reason_length(string $reason): int
{
    // Do not count UTF-8 bytes as characters in Vietnamese explanations.
    if (function_exists('mb_strlen')) return mb_strlen($reason, 'UTF-8');
    $count = preg_match_all('/./us', $reason);
    if ($count === false) throw new RuntimeException('Review reason must contain valid UTF-8 text.');
    return $count;
}

function rl_cost_review_validate_choice(array $snapshot, string $type, string $reason): void
{
    if (!in_array($type, ['standard', 'zero'], true)) {
        throw new RuntimeException('Select Standard service or Zero-cost / warranty.');
    }
    $length = rl_cost_review_reason_length($reason);
    if ($length > 500) throw new RuntimeException('Review reason must be 500 characters or fewer.');
    if ((int) $snapshot['pending'] > 0) {
        throw new RuntimeException('Resolve pending or approved part requests before approving repair costs.');
    }
    if ($type === 'standard' && ((int)$snapshot['count'] === 0 || (float)$snapshot['total'] <= 0)) {
        throw new RuntimeException('This repair has no billable costs ($0.00). Select Zero-cost / warranty and enter a reason of at least 10 characters, or reopen the repair to add labor/parts.');
    }
    if ($type === 'zero') {
        if (abs((float)$snapshot['total']) > 0.009) {
            throw new RuntimeException('Zero-cost / warranty can only be approved when billable costs equal $0.00. Use Standard service for charged repairs.');
        }
        if ($length < 10) {
            throw new RuntimeException('Explain why this $0.00 repair is free/warranty (at least 10 characters).');
        }
    }
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
        if ($snapshot['total'] != 0.0 || rl_cost_review_reason_length(trim((string)$review['reason'])) < 10) {
            throw new RuntimeException('Zero-cost invoice requires an approved zero-cost reason and zero total.');
        }
    } elseif ($review['review_type'] === 'standard') {
        if ($snapshot['count'] === 0 || $snapshot['total'] <= 0) {
            throw new RuntimeException('Add priced repair items or approve an explicit zero-cost service.');
        }
    } else throw new RuntimeException('Unknown cost review type.');
    return ['review'=>$review, 'snapshot'=>$snapshot];
}
