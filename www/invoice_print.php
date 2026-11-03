<?php
// invoice_print.php - Trang In Hóa Đơn & Mã VietQR Chuyển Khoản REDLINE Motorsport ERP
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/src/includes/auth.php';
if (function_exists('requireAuth')) {
    requireAuth();
}

$pdo = db();
$invId = (int)($_GET['id'] ?? 0);
$roId  = (int)($_GET['order_id'] ?? 0);

if ($invId <= 0 && $roId <= 0) {
    die("Không tìm thấy thông tin hóa đơn hợp lệ.");
}

// Truy vấn thông tin Hóa đơn, Phiếu sửa chữa, Khách hàng & Xe
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
        // Truy vấn các hạng mục tiền công và phụ tùng từ repair_order_items
        $stmtItems = $pdo->prepare("
            SELECT * FROM repair_order_items 
            WHERE repair_order_id = ? 
            ORDER BY item_type ASC, id ASC
        ");
        $stmtItems->execute([$invoice['order_id']]);
        $items = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    die("Lỗi truy vấn CSDL: " . $e->getMessage());
}

if (!$invoice) {
    die("Hóa đơn không tồn tại hoặc chưa được khởi tạo từ phiếu sửa chữa.");
}

// Cấu hình thông tin Ngân hàng phục vụ tạo QR Thanh toán động (VietQR)
$bankId       = "MB"; // Mã ngân hàng: MB, VCB, TCB, ACB, ICB, ...
$accountNo    = "0901234567"; // Số tài khoản gara
$accountName  = "REDLINE MOTORSPORT GARA"; // Tên chủ tài khoản
$qrAmount     = (int)$invoice['grand_total'];
$qrMemo       = urlencode($invoice['invoice_code']);
$qrAccountName = urlencode($accountName);

// Link API VietQR tạo mã QR chuẩn Napas247
$vietQrUrl = "https://img.vietqr.io/image/{$bankId}-{$accountNo}-compact2.png?amount={$qrAmount}&addInfo={$qrMemo}&accountName={$qrAccountName}";
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>In Hóa Đơn - <?= htmlspecialchars($invoice['invoice_code']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Oswald:wght@600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Inter', sans-serif; }
        .font-display { font-family: 'Oswald', sans-serif; }
        @media print {
            .no-print { display: none !important; }
            body { background: #ffffff !important; color: #000000 !important; }
            .print-container { border: none !important; shadow: none !important; padding: 0 !important; width: 100% !important; max-width: 100% !important; }
            .page-break { page-break-after: always; }
        }
    </style>
</head>
<body class="bg-gray-900 text-gray-800 min-h-screen py-8 px-4 flex flex-col items-center justify-start">

    <!-- THANH NÚT THAO TÁC CỐ ĐỊNH (ẨN KHI IN) -->
    <div class="no-print w-full max-w-4xl mb-6 flex items-center justify-between bg-gray-800 border border-gray-700 p-4 rounded-xl shadow-xl">
        <div class="flex items-center gap-3">
            <a href="invoices.php" class="px-4 py-2 bg-gray-700 hover:bg-gray-600 text-gray-200 text-xs font-semibold rounded-lg transition flex items-center gap-1.5">
                &larr; Quay lại danh sách
            </a>
            <span class="text-xs font-mono text-gray-400">Trạng thái thanh toán: 
                <strong class="<?= $invoice['payment_status'] === 'paid' ? 'text-emerald-400' : 'text-rose-400' ?> uppercase">
                    <?= $invoice['payment_status'] === 'paid' ? 'Đã thanh toán' : 'Chưa thanh toán (Công nợ)' ?>
                </strong>
            </span>
        </div>
        <div class="flex items-center gap-3">
            <button onclick="window.print()" class="px-5 py-2 bg-[#FF3B00] hover:bg-[#E03400] text-white font-display text-sm uppercase tracking-wider font-bold rounded-lg shadow-lg flex items-center gap-2 transition">
                🖨️ In Hóa Đơn (Ctrl + P)
            </button>
        </div>
    </div>

    <!-- KHU VỰC NỘI DUNG HÓA ĐƠN TRẮNG CHUẨN A4/A5 -->
    <div class="print-container bg-white text-gray-900 w-full max-w-4xl p-8 rounded-xl shadow-2xl border border-gray-200">
        
        <!-- HEADER GARA & TÊU ĐỀ HÓA ĐƠN -->
        <div class="flex flex-col sm:flex-row sm:items-start justify-between border-b-2 border-gray-900 pb-6 gap-4">
            <div>
                <div class="flex items-center gap-1 font-display text-3xl font-bold tracking-wider text-gray-900">
                    <span class="text-[#FF3B00]">///</span> REDLINE MOTORSPORT
                </div>
                <p class="text-xs font-bold text-gray-600 uppercase tracking-widest mt-0.5">Trung Tâm Sửa Chữa &amp; Nâng Cấp Ô Tô Chuyên Nghiệp</p>
                <div class="text-xs text-gray-600 mt-2 space-y-0.5">
                    <p>📍 Địa chỉ: 123 Đường 3/2, Q. Ninh Kiều, TP. Cần Thơ</p>
                    <p>📞 Hotline Kỹ Thuật: 0901.234.567 | Dịch vụ khách hàng: 0908.888.999</p>
                    <p>🌐 Website: www.redlinemotorsport.vn</p>
                </div>
            </div>

            <div class="text-right">
                <h1 class="font-display text-3xl font-bold text-gray-900 uppercase tracking-tight">HÓA ĐƠN DỊCH VỤ</h1>
                <p class="font-mono text-sm font-bold text-[#FF3B00] mt-1">Mã HĐ: <?= htmlspecialchars($invoice['invoice_code']) ?></p>
                <p class="text-xs text-gray-500 font-mono mt-0.5">Mã Phiếu: <?= htmlspecialchars($invoice['order_code']) ?></p>
                <p class="text-xs text-gray-500 font-mono mt-0.5">Ngày lập: <?= date('d/m/Y H:i', strtotime($invoice['created_at'])) ?></p>
            </div>
        </div>

        <!-- THÔNG TIN KHÁCH HÀNG & PHƯƠNG TIỆN -->
        <div class="grid grid-cols-2 gap-6 my-6 p-4 bg-gray-50 rounded-lg border border-gray-200 text-xs">
            <div class="space-y-1.5">
                <h3 class="font-bold text-gray-900 uppercase font-mono border-b border-gray-300 pb-1">THÔNG TIN KHÁCH HÀNG</h3>
                <p><span class="text-gray-500">Khách hàng:</span> <strong class="text-gray-900 text-sm"><?= htmlspecialchars($invoice['customer_name'] ?: 'Khách vảng lai') ?></strong></p>
                <p><span class="text-gray-500">Số điện thoại:</span> <span class="font-mono font-bold text-gray-800"><?= htmlspecialchars($invoice['customer_phone'] ?: 'N/A') ?></span></p>
                <p><span class="text-gray-500">Địa chỉ:</span> <?= htmlspecialchars($invoice['customer_address'] ?: 'N/A') ?></p>
            </div>

            <div class="space-y-1.5">
                <h3 class="font-bold text-gray-900 uppercase font-mono border-b border-gray-300 pb-1">THÔNG TIN PHƯƠNG TIỆN</h3>
                <p><span class="text-gray-500">Biển số xe:</span> <strong class="font-mono text-sm bg-yellow-100 border border-yellow-300 px-2 py-0.5 rounded text-gray-900 tracking-wider"><?= htmlspecialchars($invoice['license_plate'] ?: 'N/A') ?></strong></p>
                <p><span class="text-gray-500">Dòng xe:</span> <strong class="text-gray-800"><?= htmlspecialchars(($invoice['vehicle_brand'] ? $invoice['vehicle_brand'] . ' ' : '') . $invoice['vehicle_model']) ?></strong></p>
                <p><span class="text-gray-500">Số Km vào xưởng:</span> <span class="font-mono font-bold text-gray-800"><?= number_format($invoice['km_in'] ?? 0) ?> km</span></p>
            </div>
        </div>

        <!-- BẢNG CHI TIẾT TIỀN CÔNG DỊCH VỤ & PHỤ TÙNG -->
        <div class="my-6 overflow-hidden rounded-lg border border-gray-300">
            <table class="w-full text-left text-xs border-collapse">
                <thead>
                    <tr class="bg-gray-100 text-gray-700 font-bold font-mono uppercase border-b border-gray-300 text-[11px]">
                        <th class="py-2.5 px-3 w-10 text-center">STT</th>
                        <th class="py-2.5 px-3">NỘI DUNG DỊCH VỤ / PHỤ TÙNG THAY THẾ</th>
                        <th class="py-2.5 px-3 text-center w-24">PHÂN LOẠI</th>
                        <th class="py-2.5 px-3 text-right w-28">ĐƠN GIÁ (VNĐ)</th>
                        <th class="py-2.5 px-3 text-center w-16">SL</th>
                        <th class="py-2.5 px-3 text-right w-32">THÀNH TIỀN (VNĐ)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php if (empty($items)): ?>
                        <tr>
                            <td colspan="6" class="py-6 text-center text-gray-500 italic">Không có chi tiết hạng mục nào.</td>
                        </tr>
                    <?php else: ?>
                        <?php $stt = 1; foreach ($items as $it): ?>
                            <tr>
                                <td class="py-2.5 px-3 text-center font-mono text-gray-500"><?= $stt++ ?></td>
                                <td class="py-2.5 px-3 font-semibold text-gray-900"><?= htmlspecialchars($it['item_name']) ?></td>
                                <td class="py-2.5 px-3 text-center font-mono text-[10px]">
                                    <?php if ($it['item_type'] === 'labor'): ?>
                                        <span class="bg-blue-50 text-blue-700 border border-blue-200 px-1.5 py-0.5 rounded">Tiền công</span>
                                    <?php else: ?>
                                        <span class="bg-amber-50 text-amber-700 border border-amber-200 px-1.5 py-0.5 rounded">Phụ tùng</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-2.5 px-3 text-right font-mono text-gray-700"><?= number_format($it['unit_price'], 0, ',', '.') ?></td>
                                <td class="py-2.5 px-3 text-center font-mono font-bold text-gray-900"><?= $it['quantity'] ?></td>
                                <td class="py-2.5 px-3 text-right font-mono font-bold text-gray-900"><?= number_format($it['subtotal'], 0, ',', '.') ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- TỔNG TIỀN VÀ MÃ VIETQR CHUYỂN KHOẢN -->
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6 my-6 items-start">
            <!-- Mã VietQR Thanh toán -->
            <div class="p-4 bg-gray-50 border border-gray-200 rounded-lg flex items-center gap-4">
                <img src="<?= $vietQrUrl ?>" alt="Mã VietQR Thanh toán" class="w-32 h-32 object-contain rounded border border-gray-300 bg-white shadow-sm shrink-0">
                <div class="text-xs space-y-1 font-mono">
                    <p class="font-bold text-gray-900 uppercase font-sans">💳 THANH TOÁN CHUYỂN KHOẢN</p>
                    <p><span class="text-gray-500">Ngân hàng:</span> <strong><?= $bankId ?></strong></p>
                    <p><span class="text-gray-500">Số tài khoản:</span> <strong class="text-red-600 text-sm"><?= $accountNo ?></strong></p>
                    <p><span class="text-gray-500">Chủ TK:</span> <strong><?= $accountName ?></strong></p>
                    <p class="text-[10px] text-gray-500 italic mt-1">* Quét mã QR trên ứng dụng Ngân hàng để tự động điền số tiền và nội dung.</p>
                </div>
            </div>

            <!-- Bảng tính Tổng thanh toán -->
            <div class="space-y-2 text-xs font-mono">
                <div class="flex justify-between py-1 border-b border-gray-200">
                    <span class="text-gray-600">Tổng tiền công dịch vụ:</span>
                    <span class="font-bold text-gray-800"><?= number_format($invoice['total_services'], 0, ',', '.') ?> VNĐ</span>
                </div>
                <div class="flex justify-between py-1 border-b border-gray-200">
                    <span class="text-gray-600">Tổng tiền phụ tùng vật tư:</span>
                    <span class="font-bold text-gray-800"><?= number_format($invoice['total_parts'], 0, ',', '.') ?> VNĐ</span>
                </div>
                <div class="flex justify-between py-1 border-b border-gray-200">
                    <span class="text-gray-600">Chiết khấu / Giảm giá:</span>
                    <span class="font-bold text-red-600">- <?= number_format($invoice['discount'], 0, ',', '.') ?> VNĐ</span>
                </div>
                <div class="flex justify-between py-2 border-b-2 border-gray-900 text-sm">
                    <span class="font-bold text-gray-900 font-sans">TỔNG CỘNG THANH TOÁN:</span>
                    <span class="font-bold text-[#FF3B00] text-base"><?= number_format($invoice['grand_total'], 0, ',', '.') ?> VNĐ</span>
                </div>
                <p class="text-[11px] text-gray-500 text-right italic font-sans">
                    (Phương thức thanh toán: <strong><?= $invoice['payment_method'] === 'transfer' ? 'Chuyển khoản' : ($invoice['payment_method'] === 'card' ? 'Thẻ / POS' : 'Tiền mặt') ?></strong>)
                </p>
            </div>
        </div>

        <!-- ĐIỀU KHỎAN BẢO HÀNH & CHỮ KÝ -->
        <div class="border-t border-gray-300 pt-4 mt-6 text-[11px] text-gray-600 space-y-1">
            <p class="font-bold text-gray-800 uppercase font-mono">📝 QUY ĐỊNH BẢO HÀNH &amp; CAM KẾT:</p>
            <p>- Phụ tùng chính hãng được bảo hành 06 tháng hoặc 10.000 km (tùy điều kiện nào đến trước).</p>
            <p>- Quý khách vui lòng kiểm tra kỹ phương tiện, đồ dùng cá nhân và phụ tùng thay thế trước khi rời xưởng.</p>
        </div>

        <div class="grid grid-cols-2 gap-6 mt-8 pt-4 text-center text-xs">
            <div>
                <p class="font-bold text-gray-900 uppercase">KHÁCH HÀNG KÝ NHẬN</p>
                <p class="text-[10px] text-gray-500 italic">(Ký, ghi rõ họ tên)</p>
                <div class="h-16"></div>
                <p class="font-bold text-gray-800"><?= htmlspecialchars($invoice['customer_name'] ?: '') ?></p>
            </div>
            <div>
                <p class="font-bold text-[#FF3B00] uppercase">ĐẠI DIỆN REDLINE MOTORSPORT</p>
                <p class="text-[10px] text-gray-500 italic">(Cố vấn dịch vụ / Trưởng xưởng)</p>
                <div class="h-16"></div>
                <p class="font-bold text-gray-800"><?= htmlspecialchars($invoice['staff_name'] ?: 'Quản Trị Viên') ?></p>
            </div>
        </div>

    </div>

</body>
</html>