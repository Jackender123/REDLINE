<?php
require_once __DIR__ . '/../../includes/auth.php';

$staff = current_staff();
$role = $staff['role_name'] ?? '';
if (!$staff || !in_array($role, ['Admin', 'Cashier'], true)) {
    header('Location: ../../auth/login.php');
    exit;
}

$transactionId = (int)($_GET['id'] ?? 0);
if ($transactionId <= 0) {
    http_response_code(400);
    exit('Invalid transaction code.');
}

$pdo = db();
$stmt = $pdo->prepare(
    "SELECT ct.*, i.invoice_code, i.grand_total, i.order_id,
            ro.order_code, c.full_name AS customer_name, c.phone AS customer_phone,
            v.license_plate, v.brand, v.model, u.full_name AS cashier_name, tech.full_name AS technician_name
     FROM cash_transactions ct
     JOIN invoices i ON i.id = ct.invoice_id
     JOIN repair_orders ro ON ro.id = i.order_id
     JOIN vehicles v ON v.id = ro.vehicle_id
     LEFT JOIN users c ON c.id = COALESCE(ro.customer_id, v.customer_id)
     JOIN users u ON u.id = ct.created_by
     LEFT JOIN users tech ON tech.id = ro.assigned_to
     WHERE ct.id = :id AND ct.status = 'completed'
     LIMIT 1"
);
$stmt->execute(['id' => $transactionId]);
$transaction = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$transaction) {
    http_response_code(404);
    exit('No valid transaction was found.');
}

$balanceStmt = $pdo->prepare(
    "SELECT COALESCE(SUM(CASE WHEN transaction_type = 'payment' THEN amount ELSE -amount END), 0)
     FROM cash_transactions
     WHERE invoice_id = :invoice_id AND status = 'completed'
       AND (created_at < :created_at OR (created_at = :same_created_at AND id <= :id))"
);
$balanceStmt->execute([
    'invoice_id' => $transaction['invoice_id'],
    'created_at' => $transaction['created_at'],
    'same_created_at' => $transaction['created_at'],
    'id' => $transaction['id'],
]);
$paidAfter = max(0, min((float)$transaction['grand_total'], (float)$balanceStmt->fetchColumn()));
$dueAfter = max(0, (float)$transaction['grand_total'] - $paidAfter);
$methodLabels = ['cash' => 'Cash', 'transfer' => 'Bank transfer', 'qr' => 'QR', 'card' => 'Bank card / online card', 'paypal' => 'PayPal (online)'];
function receipt_e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt <?= receipt_e($transaction['transaction_code']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        @media print { .no-print { display:none !important } body { background:#fff !important; padding:0 !important } }
    </style>
</head>
<body class="min-h-screen bg-slate-100 p-4 text-slate-800 sm:p-8">
    <div class="no-print mx-auto mb-4 flex max-w-2xl justify-between">
        <a href="../../employees/index.php?view=invoice&amp;id=<?= (int)$transaction['invoice_id'] ?>" class="rounded-lg bg-white px-4 py-2 text-sm font-bold">← Back to invoice</a>
        <button onclick="window.print()" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-bold text-white">Print receipt</button>
    </div>
    <main class="mx-auto max-w-2xl rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:p-10">
        <div class="border-b-2 border-slate-800 pb-5 text-center">
            <p class="text-xl font-black tracking-widest"><span class="text-red-600">///</span> REDLINE MOTORSPORT</p>
            <h1 class="mt-4 text-2xl font-extrabold uppercase"><?= $transaction['transaction_type'] === 'refund' ? 'Refund receipt' : 'Payment receipt' ?></h1>
            <p class="mt-2 text-sm text-slate-500"><?= receipt_e($transaction['transaction_code']) ?></p>
        </div>
        <dl class="mt-6 grid grid-cols-2 gap-x-5 gap-y-4 text-sm">
            <div><dt class="text-xs text-slate-500">Invoice number</dt><dd class="mt-1 font-bold"><?= receipt_e($transaction['invoice_code']) ?></dd></div>
            <div><dt class="text-xs text-slate-500">Repair order number</dt><dd class="mt-1 font-bold"><?= receipt_e($transaction['order_code']) ?></dd></div>
            <div><dt class="text-xs text-slate-500">Customer</dt><dd class="mt-1 font-bold"><?= receipt_e($transaction['customer_name'] ?: '—') ?></dd><dd class="text-xs"><?= receipt_e($transaction['customer_phone'] ?? '') ?></dd></div>
            <div><dt class="text-xs text-slate-500">Vehicle</dt><dd class="mt-1 font-bold"><?= receipt_e($transaction['license_plate']) ?></dd><dd class="text-xs"><?= receipt_e(trim($transaction['brand'] . ' ' . $transaction['model'])) ?></dd></div>
            <div><dt class="text-xs text-slate-500">Date and time</dt><dd class="mt-1 font-bold"><?= receipt_e(date('d/m/Y H:i:s', strtotime($transaction['created_at']))) ?></dd></div>
            <div><dt class="text-xs text-slate-500">Assigned technician</dt><dd class="mt-1 font-bold"><?= receipt_e($transaction['technician_name'] ?: 'Not assigned') ?></dd></div>
            <div><dt class="text-xs text-slate-500">Staff member</dt><dd class="mt-1 font-bold"><?= receipt_e($transaction['cashier_name']) ?></dd></div>
            <div><dt class="text-xs text-slate-500">Payment method</dt><dd class="mt-1 font-bold"><?= receipt_e($methodLabels[$transaction['payment_method']] ?? $transaction['payment_method']) ?></dd></div>
            <div><dt class="text-xs text-slate-500">Transaction type</dt><dd class="mt-1 font-bold"><?= $transaction['transaction_type'] === 'refund' ? 'Refund' : 'Payment' ?></dd></div>
        </dl>
        <div class="mt-7 border-y border-dashed border-slate-300 py-5">
            <div class="flex justify-between gap-4 text-lg">
                <span class="font-bold"><?= $transaction['transaction_type'] === 'refund' ? 'Refund amount' : 'Amount received' ?></span>
                <span class="font-black <?= $transaction['transaction_type'] === 'refund' ? 'text-rose-700' : 'text-emerald-700' ?>"><?= $transaction['transaction_type'] === 'refund' ? '−' : '' ?><?= rl_usd((float)$transaction['amount']) ?></span>
            </div>
            <div class="mt-4 flex justify-between text-sm"><span>Invoice total</span><span class="font-semibold"><?= rl_usd((float)$transaction['grand_total']) ?></span></div>
            <div class="mt-2 flex justify-between text-sm"><span>Total collected after transaction</span><span class="font-semibold"><?= rl_usd($paidAfter) ?></span></div>
            <div class="mt-2 flex justify-between text-sm"><span>Outstanding balance</span><span class="font-semibold"><?= rl_usd($dueAfter) ?></span></div>
        </div>
        <?php if (!empty($transaction['note'])): ?><p class="mt-4 text-sm"><span class="text-slate-500">Notes:</span> <?= receipt_e($transaction['note']) ?></p><?php endif; ?>
        <p class="mt-8 text-center text-xs text-slate-500">Thank you for choosing REDLINE MOTORSPORT.</p>
    </main>
</body>
</html>
