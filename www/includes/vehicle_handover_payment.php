<?php
/** Return the payment eligibility for a completed order. Lock invoices on writes. */
function rl_handover_payment(PDO $pdo, int $orderId, bool $lock = false): array
{
    $stmt = $pdo->prepare("SELECT id, grand_total, payment_status FROM invoices
        WHERE order_id = ? AND (payment_status IS NULL OR payment_status <> 'cancelled')
        ORDER BY id" . ($lock ? ' FOR UPDATE' : ''));
    $stmt->execute([$orderId]);
    $invoices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$invoices) return ['allowed' => false, 'message' => 'Create and fully pay an invoice before handover.'];

    $ledger = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN transaction_type = 'payment' THEN amount ELSE -amount END), 0)
        FROM cash_transactions WHERE invoice_id = ? AND status = 'completed'");
    $remaining = 0;
    $allPaid = true;
    foreach ($invoices as $invoice) {
        $ledger->execute([$invoice['id']]);
        $paid = (int) round((float) $ledger->fetchColumn() * 100);
        $total = (int) round((float) $invoice['grand_total'] * 100);
        $remaining += max(0, $total - $paid);
        if ($invoice['payment_status'] !== 'paid' || $paid < $total) $allPaid = false;
    }
    if ($remaining > 0) {
        return ['allowed' => false, 'message' => 'Handover locked — outstanding balance: ' . number_format($remaining / 100, $remaining % 100 ? 2 : 0, '.', ',') . ' đ.'];
    }
    if (!$allPaid) return ['allowed' => false, 'message' => 'Handover locked — a fully paid invoice is required.'];
    return ['allowed' => true, 'message' => 'Payment verified.'];
}
