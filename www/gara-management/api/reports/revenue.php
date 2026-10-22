<?php
// api/reports/revenue.php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/auth_check.php';

// Giới hạn quyền xem báo cáo tài chính cho Admin và Staff
requireAuth(['Admin', 'Staff']);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    sendJsonResponse('error', 405, 'Phương thức không được hỗ trợ!', null, 'ERR_METHOD_NOT_ALLOWED');
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
            sendJsonResponse('error', 400, 'Vui lòng cung cấp từ ngày (from_date) và đến ngày (to_date)!', null, 'ERR_VALIDATION_FAILED');
        }
        break;

    default:
        $fromDate = date('Y-m-01');
        $toDate   = date('Y-m-t');
        $period   = 'this_month';
        break;
}

$pdo = getDBConnection();

// 1. Thống kê tổng quan doanh thu & số lượng xe
$stmtSummary = $pdo->prepare("
    SELECT 
        COUNT(DISTINCT i.id) AS total_invoices,
        COUNT(DISTINCT ro.id) AS total_completed_orders,
        COUNT(DISTINCT ro.vehicle_id) AS total_vehicles_repaired,
        IFNULL(SUM(i.total_services), 0) AS total_services_revenue,
        IFNULL(SUM(i.total_parts), 0) AS total_parts_revenue,
        IFNULL(SUM(i.discount), 0) AS total_discount,
        IFNULL(SUM(i.grand_total), 0) AS net_revenue
    FROM invoices i
    JOIN repair_orders ro ON i.order_id = ro.id
    WHERE i.payment_status = 'paid'
      AND DATE(i.created_at) BETWEEN ? AND ?
");
$stmtSummary->execute([$fromDate, $toDate]);
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
    WHERE i.payment_status = 'paid'
      AND DATE(i.created_at) BETWEEN ? AND ?
    GROUP BY DATE(i.created_at)
    ORDER BY report_date ASC
");
$stmtDaily->execute([$fromDate, $toDate]);
$dailyBreakdown = $stmtDaily->fetchAll();

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
    'net_revenue'             => (float)$summary['net_revenue']
];

sendJsonResponse('success', 200, 'Lấy báo cáo doanh thu thành công!', [
    'summary'         => $summaryData,
    'daily_breakdown' => $dailyBreakdown
]);