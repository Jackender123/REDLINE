<?php
// invoice_print.php - Printable Service Invoice & VietQR Dynamic Transfer Code REDLINE Motorsport ERP
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/src/includes/auth.php';
if (function_exists('requireAuth')) {
    requireAuth();
}

$userRole = $staff['role_name'] ?? '';
if (!$staff || !in_array($userRole, ['Admin', 'Receptionist', 'Cashier'], true)) {
    header('Location: login.php');
    exit;
}
$pdo = db();
$invId = (int)($_GET['id'] ?? 0);
$roId  = (int)($_GET['order_id'] ?? 0);

if ($invId <= 0 && $roId <= 0) {
    die("No valid invoice information found.");
}

// Query Invoice, Repair Order, Customer & Vehicle details
$invoice = null;
$items   = [];

try {
    if ($invId > 0) {
        $stmt = $pdo->prepare("
            SELECT 
                i.*,
                ro.order_code, ro.km_in, ro.created_at AS order_date,
                c.full_name AS customer_name, c.phone AS customer_phone, c.address AS customer_address,
                v.license_plate, v.brand AS vehicle_brand, v.model AS vehicle_model, v.vin AS vin_number,
                u.full_name AS staff_name
            FROM invoices i
            LEFT JOIN repair_orders ro ON i.order_id = ro.id
            LEFT JOIN vehicles v ON ro.vehicle_id = v.id
            LEFT JOIN customers c ON (v.customer_id = c.id OR ro.customer_id = c.id)
            LEFT JOIN users u ON i.created_by = u.id
            WHERE i.id = ?
            LIMIT 1
        ");
        $stmt->execute([$invId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        $stmt = $pdo->prepare("
            SELECT 
                i.*,
                ro.order_code, ro.km_in, ro.created_at AS order_date,
                c.full_name AS customer_name, c.phone AS customer_phone, c.address AS customer_address,
                v.license_plate, v.brand AS vehicle_brand, v.model AS vehicle_model, v.vin AS vin_number,
                u.full_name AS staff_name
            FROM invoices i
            LEFT JOIN repair_orders ro ON i.order_id = ro.id
            LEFT JOIN vehicles v ON ro.vehicle_id = v.id
            LEFT JOIN customers c ON (v.customer_id = c.id OR ro.customer_id = c.id)
            LEFT JOIN users u ON i.created_by = u.id
            WHERE i.order_id = ?
            LIMIT 1
        ");
        $stmt->execute([$roId]);
        $invoice = $stmt->fetch(PDO::FETCH_ASSOC);
    }

    if ($invoice) {
        $stmtItems = $pdo->prepare(
            "SELECT description AS item_name,
                    CASE WHEN item_type = 'service' THEN 'labor' ELSE 'part' END AS item_type,
                    unit_price, quantity, subtotal
             FROM invoice_items WHERE invoice_id = ? ORDER BY id"
        );
        $stmtItems->execute([$invoice['id']]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
        if (!$items) {
            // Older invoices use the repair order's original line items.
        $stmtItems = $pdo->prepare("
            SELECT * FROM repair_order_items 
            WHERE repair_order_id = ? 
            ORDER BY item_type ASC, id ASC
        ");
        $stmtItems->execute([$invoice['order_id']]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
        }

        $paymentStmt = $pdo->prepare(
            "SELECT
                COALESCE(SUM(CASE WHEN transaction_type = 'payment' THEN amount ELSE -amount END), 0) AS net_paid,
                COUNT(*) AS transaction_count
             FROM cash_transactions
             WHERE invoice_id = ? AND status = 'completed'"
        );
        $paymentStmt->execute([$invoice['id']]);
        $paymentData = $paymentStmt->fetch(PDO::FETCH_ASSOC);
        $amountPaid = (float)$paymentData['net_paid'];
        if ((int)$paymentData['transaction_count'] === 0 && $invoice['payment_status'] === 'paid') {
            $amountPaid = (float)$invoice['grand_total'];
        }
        $amountPaid = max(0, min((float)$invoice['grand_total'], $amountPaid));
        $amountDue = max(0, (float)$invoice['grand_total'] - $amountPaid);
    }
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

if (!$invoice) {
    die("Invoice does not exist or has not been initialized from repair order.");
}

// Only generate transfer QR codes from explicitly saved bank settings.
$bankSettingsStmt = $pdo->query(
    "SELECT setting_key, setting_value FROM settings
     WHERE setting_key IN ('bank_id', 'bank_account_no', 'bank_account_name')"
);
$bankSettings = $bankSettingsStmt->fetchAll(PDO::FETCH_KEY_PAIR);
$bankId = trim((string)($bankSettings['bank_id'] ?? ''));
$accountNo = trim((string)($bankSettings['bank_account_no'] ?? ''));
$accountName = trim((string)($bankSettings['bank_account_name'] ?? ''));
$qrConfigured = preg_match('/^[A-Z0-9-]{2,20}$/', $bankId)
    && preg_match('/^[0-9]{6,20}$/', $accountNo)
    && $accountName !== '';
$qrAmount = (int)$amountDue;
$qrMemo        = urlencode($invoice['invoice_code']);
$qrAccountName = urlencode($accountName);

$vietQrUrl = $qrConfigured
    ? 'https://img.vietqr.io/image/' . rawurlencode($bankId) . '-' . rawurlencode($accountNo)
        . '-compact2.png?amount=' . $qrAmount . '&addInfo=' . $qrMemo . '&accountName=' . $qrAccountName
    : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Invoice - <?= htmlspecialchars($invoice['invoice_code']) ?></title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Oswald:wght@600;700&family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Space Grotesk', sans-serif; }
        .font-display { font-family: 'Oswald', sans-serif; }
        @media print {
            .no-print { display: none !important; }
            body { background: #ffffff !important; color: #000000 !important; }
            .print-container { border: none !important; box-shadow: none !important; padding: 0 !important; width: 100% !important; max-width: 100% !important; }
            .page-break { page-break-after: always; }
        }
    </style>
</head>
<body class="bg-slate-100 text-slate-800 min-h-screen py-8 px-4 flex flex-col items-center justify-start">

    <!-- FIXED TOP BAR BUTTONS (HIDDEN IN PRINT) -->
    <div class="no-print w-full max-w-4xl mb-6 flex items-center justify-between bg-white border border-slate-200 p-4 rounded-xl shadow-sm">
        <div class="flex items-center gap-3">
            <a href="<?= $userRole === 'Cashier' ? 'employees.php?view=invoices' : 'invoices.php' ?>" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-lg transition flex items-center gap-1.5">
                &larr; Back to Invoices List
            </a>
            <span class="text-xs font-mono text-slate-500 font-semibold">Payment Status: 
                <strong class="<?= $invoice['payment_status'] === 'paid' ? 'text-emerald-700' : 'text-rose-700' ?> uppercase font-bold">
                    <?= htmlspecialchars(ucfirst($invoice['payment_status'])) ?>
                </strong>
            </span>
        </div>
        <div class="flex items-center gap-3">
            <button onclick="window.print()" class="px-5 py-2 bg-[#D90429] hover:bg-[#B90321] text-white font-display text-sm uppercase tracking-wider font-bold rounded-lg shadow-md flex items-center gap-2 transition">
                🖨️ Print Invoice (Ctrl + P)
            </button>
        </div>
    </div>

    <!-- PRINT CONTAINER DOCUMENT -->
    <div class="print-container bg-white text-slate-900 w-full max-w-4xl p-8 rounded-xl shadow-md border border-slate-200">
        
        <!-- HEADER GARAGE & INVOICE TITLE -->
        <div class="flex flex-col sm:flex-row sm:items-start justify-between border-b-2 border-slate-900 pb-6 gap-4">
            <div>
                <div class="flex items-center gap-1 font-display text-3xl font-bold tracking-wider text-slate-900">
                    <span class="text-[#D90429]">///</span> REDLINE MOTORSPORT
                </div>
                <p class="text-xs font-bold text-slate-600 uppercase tracking-widest mt-0.5">Professional Automotive Repair &amp; Performance Center</p>
                <div class="text-xs text-slate-600 mt-2 space-y-0.5 font-medium">
                    <p>📍 Address: 123 3/2 Street, Ninh Kieu Dist., Can Tho City</p>
                    <p>📞 Tech Hotline: 0901.234.567 | Customer Support: 0908.888.999</p>
                    <p>🌐 Website: www.redlinemotorsport.vn</p>
                </div>
            </div>

            <div class="text-right">
                <h1 class="font-display text-3xl font-bold text-slate-900 uppercase tracking-tight">SERVICE INVOICE</h1>
                <p class="font-mono text-sm font-bold text-[#D90429] mt-1">Invoice #: <?= htmlspecialchars($invoice['invoice_code']) ?></p>
                <p class="text-xs text-slate-500 font-mono mt-0.5 font-semibold">Order #: <?= htmlspecialchars($invoice['order_code']) ?></p>
                <p class="text-xs text-slate-500 font-mono mt-0.5 font-semibold">Date: <?= date('d/m/Y H:i', strtotime($invoice['created_at'])) ?></p>
            </div>
        </div>

        <!-- CUSTOMER & VEHICLE DETAILS -->
        <div class="grid grid-cols-2 gap-6 my-6 p-4 bg-slate-50 rounded-lg border border-slate-200 text-xs">
            <div class="space-y-1.5">
                <h3 class="font-bold text-slate-900 uppercase font-mono border-b border-slate-300 pb-1">CUSTOMER INFORMATION</h3>
                <p><span class="text-slate-500">Customer:</span> <strong class="text-slate-900 text-sm"><?= htmlspecialchars($invoice['customer_name'] ?: 'Walk-in Customer') ?></strong></p>
                <p><span class="text-slate-500">Phone:</span> <span class="font-mono font-bold text-slate-800"><?= htmlspecialchars($invoice['customer_phone'] ?: 'N/A') ?></span></p>
                <p><span class="text-slate-500">Address:</span> <?= htmlspecialchars($invoice['customer_address'] ?: 'N/A') ?></p>
            </div>

            <div class="space-y-1.5">
                <h3 class="font-bold text-slate-900 uppercase font-mono border-b border-slate-300 pb-1">VEHICLE INFORMATION</h3>
                <p><span class="text-slate-500">License Plate:</span> <strong class="font-mono text-sm bg-amber-100 border border-amber-300 px-2 py-0.5 rounded text-amber-900 tracking-wider"><?= htmlspecialchars($invoice['license_plate'] ?: 'N/A') ?></strong></p>
                <p><span class="text-slate-500">Model:</span> <strong class="text-slate-800"><?= htmlspecialchars(($invoice['vehicle_brand'] ? $invoice['vehicle_brand'] . ' ' : '') . $invoice['vehicle_model']) ?></strong></p>
                <p><span class="text-slate-500">Odometer:</span> <span class="font-mono font-bold text-slate-800"><?= number_format($invoice['km_in'] ?? 0) ?> km</span></p>
            </div>
        </div>

        <!-- SERVICE LABOR & SPARE PARTS TABLE -->
        <div class="my-6 overflow-hidden rounded-lg border border-slate-300">
            <table class="w-full text-left text-xs border-collapse">
                <thead>
                    <tr class="bg-slate-100 text-slate-700 font-bold font-mono uppercase border-b border-slate-300 text-[11px]">
                        <th class="py-2.5 px-3 w-10 text-center">NO.</th>
                        <th class="py-2.5 px-3">SERVICE / SPARE PART DESCRIPTION</th>
                        <th class="py-2.5 px-3 text-center w-24">CATEGORY</th>
                        <th class="py-2.5 px-3 text-right w-28">UNIT PRICE (VND)</th>
                        <th class="py-2.5 px-3 text-center w-16">QTY</th>
                        <th class="py-2.5 px-3 text-right w-32">SUBTOTAL (VND)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    <?php if (empty($items)): ?>
                        <tr>
                            <td colspan="6" class="py-6 text-center text-slate-500 italic font-mono">No detailed item entries found.</td>
                        </tr>
                    <?php else: ?>
                        <?php $stt = 1; foreach ($items as $it): ?>
                            <tr>
                                <td class="py-2.5 px-3 text-center font-mono text-slate-500"><?= $stt++ ?></td>
                                <td class="py-2.5 px-3 font-bold text-slate-900"><?= htmlspecialchars($it['item_name']) ?></td>
                                <td class="py-2.5 px-3 text-center font-mono text-[10px]">
                                    <?php if ($it['item_type'] === 'labor'): ?>
                                        <span class="bg-sky-50 text-sky-700 border border-sky-200 px-1.5 py-0.5 rounded font-bold">Labor</span>
                                    <?php else: ?>
                                        <span class="bg-amber-50 text-amber-700 border border-amber-200 px-1.5 py-0.5 rounded font-bold">Part</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-2.5 px-3 text-right font-mono text-slate-700 font-semibold"><?= number_format($it['unit_price'], 0, ',', '.') ?></td>
                                <td class="py-2.5 px-3 text-center font-mono font-bold text-slate-900"><?= $it['quantity'] ?></td>
                                <td class="py-2.5 px-3 text-right font-mono font-bold text-slate-900"><?= number_format($it['subtotal'], 0, ',', '.') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- TOTAL CALCULATIONS & VIETQR TRANSFER CODE -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 my-6 items-start">
            <!-- VietQR Code -->
            <div class="p-4 bg-slate-50 border border-slate-200 rounded-lg flex items-center gap-4">
                <?php if ($amountDue > 0 && $qrConfigured): ?>
                    <img src="<?= htmlspecialchars($vietQrUrl, ENT_QUOTES, 'UTF-8') ?>" alt="VietQR Transfer Code" class="w-32 h-32 object-contain rounded border border-slate-300 bg-white shadow-sm shrink-0">
                    <div class="text-xs space-y-1 font-mono">
                        <p class="font-bold text-slate-900 uppercase font-sans">💳 BANK TRANSFER PAYMENT</p>
                        <p><span class="text-slate-500">Bank:</span> <strong><?= htmlspecialchars($bankId, ENT_QUOTES, 'UTF-8') ?></strong></p>
                        <p><span class="text-slate-500">Account No:</span> <strong class="text-rose-600 text-sm"><?= htmlspecialchars($accountNo, ENT_QUOTES, 'UTF-8') ?></strong></p>
                        <p><span class="text-slate-500">Holder:</span> <strong><?= htmlspecialchars($accountName, ENT_QUOTES, 'UTF-8') ?></strong></p>
                        <p class="text-[10px] text-slate-500 italic mt-1 font-sans">* QR amount shows the remaining balance.</p>
                    </div>
                <?php elseif ($amountDue > 0): ?>
                    <p class="text-sm text-slate-600">QR transfer is unavailable until valid bank details are saved in Settings.</p>
                <?php else: ?>
                    <p class="text-sm font-semibold text-emerald-700">Invoice balance is fully paid.</p>
                <?php endif; ?>
            </div>

            <!-- Grand Totals Table -->
            <div class="space-y-2 text-xs font-mono">
                <div class="flex justify-between py-1 border-b border-slate-200">
                    <span class="text-slate-600 font-semibold">Total Labor Services:</span>
                    <span class="font-bold text-slate-800"><?= number_format($invoice['total_services'], 0, ',', '.') ?> VND</span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-200">
                    <span class="text-slate-600 font-semibold">Total Parts &amp; Materials:</span>
                    <span class="font-bold text-slate-800"><?= number_format($invoice['total_parts'], 0, ',', '.') ?> VND</span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-200">
                    <span class="text-slate-600 font-semibold">Discount / Deduction:</span>
                    <span class="font-bold text-rose-600">- <?= number_format($invoice['discount'], 0, ',', '.') ?> VND</span>
                </div>
                <div class="flex justify-between py-2 border-b-2 border-slate-900 text-sm">
                    <span class="font-bold text-slate-900 font-sans uppercase">GRAND TOTAL PAYMENT:</span>
                    <span class="font-bold text-[#D90429] text-base"><?= number_format($invoice['grand_total'], 0, ',', '.') ?> VND</span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-200">
                    <span class="text-slate-600 font-semibold">Paid:</span>
                    <span class="font-bold text-emerald-700"><?= number_format($amountPaid, 0, ',', '.') ?> VND</span>
                </div>
                <div class="flex justify-between py-1 border-b border-slate-200">
                    <span class="text-slate-600 font-semibold">Balance due:</span>
                    <span class="font-bold text-rose-700"><?= number_format($amountDue, 0, ',', '.') ?> VND</span>
                </div>
                <p class="text-[11px] text-slate-500 text-right italic font-sans font-semibold">
                    (Payment Method: <strong><?= htmlspecialchars($invoice['payment_method']) ?></strong>)
                </p>
            </div>
        </div>

        <!-- WARRANTY TERMS & FOOTER SIGNATURES -->
        <div class="border-t border-slate-300 pt-4 mt-6 text-[11px] text-slate-600 space-y-1">
            <p class="font-bold text-slate-900 uppercase font-mono">📝 WARRANTY &amp; VEHICLE TRACKING TERMS:</p>
            <p>- You can track your real-time repair status at: <strong class="text-[#D90429]">www.redlinemotorsport.vn/track.php</strong> (Enter License Plate or Order Code).</p>
            <p>- Genuine spare parts are warrantied for 6 months or 10,000 km (whichever comes first).</p>
            <p>- Please check your vehicle, belongings, and replaced parts thoroughly before leaving the garage.</p>
        </div>

        <div class="grid grid-cols-2 gap-6 mt-8 pt-4 text-center text-xs">
            <div>
                <p class="font-bold text-slate-900 uppercase font-display tracking-wider">CUSTOMER SIGNATURE</p>
                <p class="text-[10px] text-slate-500 italic">(Sign &amp; write full name)</p>
                <div class="h-16"></div>
                <p class="font-bold text-slate-800"><?= htmlspecialchars($invoice['customer_name'] ?: '') ?></p>
            </div>
            <div>
                <p class="font-bold text-[#D90429] uppercase font-display tracking-wider">REDLINE MOTORSPORT REPRESENTATIVE</p>
                <p class="text-[10px] text-slate-500 italic">(Service Advisor / Workshop Manager)</p>
                <div class="h-16"></div>
                <p class="font-bold text-slate-800"><?= htmlspecialchars($invoice['staff_name'] ?: 'Administrator') ?></p>
            </div>
        </div>

    </div>

</body>
</html>