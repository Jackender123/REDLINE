<?php
// api/reports/revenue.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/api_functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

// Giới hạn quyền xem báo cáo tài chính cho Admin và Staff
requireAuth(['Admin']);
if (!in_array($_SESSION['role'] ?? '', ['Admin'], true)) {
    sendJsonResponse('error', 403, 'You do not have permission to view revenue reports.', null, 'ERR_FORBIDDEN');
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJsonResponse('error', 405, 'Unsupported request method.', null, 'ERR_METHOD_NOT_ALLOWED');
}

// Xử lý bộ lọc thời gian (today, this_month, this_year, custom)
$period   = sanitizeInput($_GET['period'] ?? 'this_month');
$fromDate = sanitizeInput($_GET['from_date'] ?? '');
$toDate   = sanitizeInput($_GET['to_date'] ?? '');

$currentDate = date('Y-m-d');

switch ($period) {
    case 'today':
        $fromDate = $currentDate;
        $toDate   = $currentDate;
        break;

    case 'this_month':
        $fromDate = date('Y-m-01');
        $toDate   = date('Y-m-t');
        break;

    case 'this_year':
        $fromDate = date('Y-01-01');
        $toDate   = date('Y-12-31');
        break;

    case 'custom':
        if (empty($fromDate) || empty($toDate)) {
            sendJsonResponse('error', 400, 'Provide both start date (from_date) and end date (to_date).', null, 'ERR_VALIDATION_FAILED');
        }
        break;

    default:
        $fromDate = date('Y-m-01');
        $toDate   = date('Y-m-t');
        $period   = 'this_month';
        break;
}

$from = DateTimeImmutable::createFromFormat('!Y-m-d', $fromDate);
$to = DateTimeImmutable::createFromFormat('!Y-m-d', $toDate);
if (!$from || !$to || $from->format('Y-m-d') !== $fromDate || $to->format('Y-m-d') !== $toDate || $from > $to) {
    sendJsonResponse('error', 400, 'Invalid reporting date range.');
}
$end = $to->modify('+1 day')->format('Y-m-d');
$pdo = getDBConnection();

// 1. Thống kê tổng quan doanh thu & quantity xe
$stmtSummary = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT i.id) AS total_invoices,
        COUNT(DISTINCT CASE WHEN ro.status IN ('completed','delivered') THEN ro.id END) AS total_completed_orders,
        COUNT(DISTINCT ro.vehicle_id) AS total_vehicles_repaired,
        IFNULL(SUM(i.total_services), 0) AS total_services_revenue,
        IFNULL(SUM(i.total_parts), 0) AS total_parts_revenue,
        IFNULL(SUM(i.discount), 0) AS total_discount,
        IFNULL(SUM(i.grand_total), 0) AS net_revenue
    FROM invoices i
    JOIN repair_orders ro ON i.order_id = ro.id
    WHERE i.payment_status IN ('unpaid','partial','paid')
      AND i.created_at >= ? AND i.created_at < ?
");
$stmtSummary->execute([$fromDate, $end]);
$summary = $stmtSummary->fetch();

// 2. Chi tiết doanh thu từng ngày trong khoảng thời gian lọc (phục vụ vẽ biểu đồ/bảng)
$stmtDaily = $pdo->prepare("
    SELECT 
        DATE(i.created_at) AS report_date,
        COUNT(DISTINCT i.id) AS invoice_count,
        COUNT(DISTINCT ro.vehicle_id) AS vehicle_count,
        CAST(IFNULL(SUM(i.total_services), 0) AS FLOAT) AS daily_services,
        CAST(IFNULL(SUM(i.total_parts), 0) AS FLOAT) AS daily_parts,
        CAST(IFNULL(SUM(i.discount), 0) AS FLOAT) AS daily_discount,
        CAST(IFNULL(SUM(i.grand_total), 0) AS FLOAT) AS daily_revenue
    FROM invoices i
    JOIN repair_orders ro ON i.order_id = ro.id
    WHERE i.payment_status IN ('unpaid','partial','paid')
      AND i.created_at >= ? AND i.created_at < ?
    GROUP BY DATE(i.created_at)
    ORDER BY report_date ASC
");
$stmtDaily->execute([$fromDate, $end]);
$dailyBreakdown = $stmtDaily->fetchAll();

// Invoice totals are billed amounts; actual receipts are dated by the ledger.
$cash = $pdo->prepare("SELECT DATE(created_at) AS report_date,
    SUM(CASE WHEN transaction_type='payment' THEN amount ELSE 0 END) AS received,
    SUM(CASE WHEN transaction_type='refund' THEN amount ELSE 0 END) AS refunded
    FROM cash_transactions WHERE status='completed' AND created_at >= ? AND created_at < ?
    GROUP BY DATE(created_at) ORDER BY report_date");
$cash->execute([$fromDate, $end]);
$days = [];
foreach ($dailyBreakdown as $row) {
    $row['daily_billed'] = (float)$row['daily_revenue'];
    $row['daily_revenue'] = $row['received'] = $row['refunded'] = 0.0;
    $days[$row['report_date']] = $row;
}
$received = $refunded = 0.0;
foreach ($cash->fetchAll() as $row) {
    $date = $row['report_date'];
    $days[$date] ??= ['report_date'=>$date, 'invoice_count'=>0, 'vehicle_count'=>0, 'daily_services'=>0, 'daily_parts'=>0, 'daily_discount'=>0, 'daily_billed'=>0];
    $days[$date]['received'] = (float)$row['received'];
    $days[$date]['refunded'] = (float)$row['refunded'];
    $days[$date]['daily_revenue'] = round((float)$row['received'] - (float)$row['refunded'], 2);
    $received += (float)$row['received'];
    $refunded += (float)$row['refunded'];
}
ksort($days);
$dailyBreakdown = array_values($days);

// Ép kiểu các tham số tổng quan
$summaryData = [
    'period'                  => $period,
    'from_date'               => $fromDate,
    'to_date'                 => $toDate,
    'total_invoices'          => (int)$summary['total_invoices'],
    'total_completed_orders'  => (int)$summary['total_completed_orders'],
    'total_vehicles_repaired' => (int)$summary['total_vehicles_repaired'],
    'total_services_revenue'  => (float)$summary['total_services_revenue'],
    'total_parts_revenue'     => (float)$summary['total_parts_revenue'],
    'total_discount'          => (float)$summary['total_discount'],
    'billed_total'            => (float)$summary['net_revenue'],
    'received'                => round($received, 2),
    'refunded'                => round($refunded, 2),
    'net_revenue'             => round($received - $refunded, 2)
];

sendJsonResponse('success', 200, 'Revenue report retrieved successfully.', [
    'summary'         => $summaryData,
    'daily_breakdown' => $dailyBreakdown
]);
