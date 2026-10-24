-- ==========================================================================
-- REDLINE MOTORWORKS - DATABASE DUY NHẤT (portal khách hàng + quản lý gara)
-- Chạy trong HeidiSQL / phpMyAdmin: dán toàn bộ file -> chạy
-- Thay thế cho 2 file cũ: 1792645000313_database.sql và gara_management.sql
--
-- Tài khoản demo:
--   ADMIN (vào gara-management):  admin@gara.com      / Admin@2026
--   Khách hàng (portal):          demo@redline.test   / Redline@2025
-- (ĐỔI MẬT KHẨU và XÓA tài khoản demo khi đưa lên server thật)
--
-- Lưu ý: bảng khách đăng ký ở portal được đặt tên portal_users / portal_vehicles
-- để không trùng với bảng users / vehicles của hệ thống quản lý gara.
-- ==========================================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "+00:00";
SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS `redlinev1`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `redlinev1`;

-- ==========================================================================
-- PHẦN A - CUSTOMER PORTAL
-- ==========================================================================

CREATE TABLE IF NOT EXISTS `portal_users` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `full_name`     VARCHAR(120) NOT NULL,
    `email`         VARCHAR(190) NOT NULL,
    `phone`         VARCHAR(30)  DEFAULT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `role`          ENUM('customer','staff','admin') NOT NULL DEFAULT 'customer',
    `is_active`     TINYINT(1)   NOT NULL DEFAULT 1,
    `last_login_at` DATETIME     DEFAULT NULL,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_portal_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `remember_tokens` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`        INT UNSIGNED NOT NULL,
    `selector`       CHAR(24)     NOT NULL,
    `validator_hash` CHAR(64)     NOT NULL,
    `expires_at`     DATETIME     NOT NULL,
    `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_selector` (`selector`),
    KEY `idx_remember_user` (`user_id`),
    CONSTRAINT `fk_remember_user` FOREIGN KEY (`user_id`)
        REFERENCES `portal_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email`        VARCHAR(190) NOT NULL,
    `ip_address`   VARCHAR(45)  NOT NULL,
    `attempted_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_attempt_email` (`email`, `attempted_at`),
    KEY `idx_attempt_ip` (`ip_address`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `portal_vehicles` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`    INT UNSIGNED NOT NULL,
    `make`       VARCHAR(60)  NOT NULL,
    `model`      VARCHAR(80)  NOT NULL,
    `year`       SMALLINT UNSIGNED DEFAULT NULL,
    `plate`      VARCHAR(20)  DEFAULT NULL,
    `vin`        VARCHAR(17)  DEFAULT NULL,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_vehicle_user` (`user_id`),
    CONSTRAINT `fk_portal_vehicle_user` FOREIGN KEY (`user_id`)
        REFERENCES `portal_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `service_orders` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `vehicle_id`  INT UNSIGNED NOT NULL,
    `title`       VARCHAR(160) NOT NULL,
    `status`      ENUM('estimate','approved','in_progress','ready','completed','cancelled')
                  NOT NULL DEFAULT 'estimate',
    `estimate`    DECIMAL(10,2) DEFAULT NULL,
    `notes`       TEXT          DEFAULT NULL,
    `created_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_order_vehicle` (`vehicle_id`),
    KEY `idx_order_status` (`status`),
    CONSTRAINT `fk_order_vehicle` FOREIGN KEY (`vehicle_id`)
        REFERENCES `portal_vehicles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `portal_users` (`full_name`, `email`, `phone`, `password_hash`, `role`)
VALUES ('Demo Customer', 'demo@redline.test', '(555) 019-8871',
        '$2y$10$UBF.WjEAO5eiU0rcN2Mh2OXzGDDNcXOQ27gHp2XLdgH68ieIQcdwy', 'customer')
ON DUPLICATE KEY UPDATE `email` = `email`;

INSERT INTO `portal_vehicles` (`user_id`, `make`, `model`, `year`, `plate`)
SELECT u.id, 'BMW', 'M3 Competition', 2022, 'RDLN-340'
FROM `portal_users` u
WHERE u.email = 'demo@redline.test'
  AND NOT EXISTS (SELECT 1 FROM `portal_vehicles` v WHERE v.user_id = u.id);

INSERT INTO `service_orders` (`vehicle_id`, `title`, `status`, `estimate`, `notes`)
SELECT v.id, 'Stage 2 ECU tune + downpipe install', 'in_progress', 3450.00,
       'Dyno session scheduled after install.'
FROM `portal_vehicles` v
JOIN `portal_users` u ON u.id = v.user_id
WHERE u.email = 'demo@redline.test'
  AND NOT EXISTS (SELECT 1 FROM `service_orders` s WHERE s.vehicle_id = v.id);

-- ==========================================================================
-- PHẦN B - GARA MANAGEMENT (quản trị: admin / lễ tân / thợ)
-- ==========================================================================

--
-- Table structure for table `appointments`
--

CREATE TABLE `appointments` (
  `id` int(10) UNSIGNED NOT NULL,
  `customer_id` int(10) UNSIGNED NOT NULL,
  `vehicle_id` int(10) UNSIGNED NOT NULL,
  `appointment_date` date NOT NULL,
  `time_slot` varchar(20) NOT NULL COMMENT 'Khung giờ (VD: 08:00-09:00)',
  `note` varchar(255) DEFAULT NULL,
  `status` enum('pending','confirmed','arrived','canceled') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `customers`
--

CREATE TABLE `customers` (
  `id` int(10) UNSIGNED NOT NULL,
  `full_name` varchar(100) NOT NULL,
  `phone` varchar(15) NOT NULL COMMENT 'Dùng tra cứu tiến độ công khai',
  `email` varchar(100) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = hoạt động, 0 = xóa mềm',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `customers`
--

INSERT INTO `customers` (`id`, `full_name`, `phone`, `email`, `address`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'Nguyễn Văn Cường', '0988777666', '', '', 1, '2026-10-20 22:07:28', '2026-10-20 22:07:28');

-- --------------------------------------------------------

--
-- Table structure for table `inventory_transactions`
--

CREATE TABLE `inventory_transactions` (
  `id` int(10) UNSIGNED NOT NULL,
  `part_id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL COMMENT 'Người thực hiện nhập/xuất',
  `type` enum('import','export') NOT NULL,
  `quantity` int(10) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `note` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `invoices`
--

CREATE TABLE `invoices` (
  `id` int(11) NOT NULL,
  `invoice_code` varchar(50) NOT NULL,
  `order_id` int(11) NOT NULL,
  `total_services` decimal(12,2) NOT NULL DEFAULT 0.00,
  `total_parts` decimal(12,2) NOT NULL DEFAULT 0.00,
  `discount` decimal(12,2) NOT NULL DEFAULT 0.00,
  `grand_total` decimal(12,2) NOT NULL DEFAULT 0.00,
  `payment_status` enum('unpaid','paid','cancelled') DEFAULT 'unpaid',
  `payment_method` enum('cash','transfer','card') DEFAULT 'cash',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `invoices`
--

INSERT INTO `invoices` (`id`, `invoice_code`, `order_id`, `total_services`, `total_parts`, `discount`, `grand_total`, `payment_status`, `payment_method`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'INV-20261020-417', 2, 250000.00, 0.00, 0.00, 250000.00, 'paid', 'cash', 1, '2026-10-20 23:05:36', '2026-10-20 23:05:36');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL COMMENT 'Người nhận thông báo',
  `order_id` int(10) UNSIGNED DEFAULT NULL,
  `content` varchar(255) NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `order_parts`
--

CREATE TABLE `order_parts` (
  `id` int(10) UNSIGNED NOT NULL,
  `order_id` int(10) UNSIGNED NOT NULL,
  `part_id` int(10) UNSIGNED NOT NULL,
  `quantity` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `unit_price` decimal(12,0) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `order_services`
--

CREATE TABLE `order_services` (
  `id` int(10) UNSIGNED NOT NULL,
  `order_id` int(10) UNSIGNED NOT NULL,
  `service_id` int(11) DEFAULT NULL,
  `quantity` smallint(5) UNSIGNED NOT NULL DEFAULT 1,
  `price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `unit_price` decimal(12,0) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `order_services`
--

INSERT INTO `order_services` (`id`, `order_id`, `service_id`, `quantity`, `price`, `unit_price`, `note`, `created_at`) VALUES
(1, 2, NULL, 1, 250000.00, 0, NULL, '2026-10-20 22:51:35');

-- --------------------------------------------------------

--
-- Table structure for table `order_status_history`
--

CREATE TABLE `order_status_history` (
  `id` int(10) UNSIGNED NOT NULL,
  `order_id` int(10) UNSIGNED NOT NULL,
  `created_by` int(10) UNSIGNED NOT NULL COMMENT 'user_id người thực hiện',
  `from_status` varchar(30) DEFAULT NULL,
  `to_status` varchar(30) NOT NULL,
  `note` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `parts`
--

CREATE TABLE `parts` (
  `id` int(10) UNSIGNED NOT NULL,
  `category_id` int(10) UNSIGNED NOT NULL,
  `part_code` varchar(30) NOT NULL,
  `name` varchar(150) NOT NULL,
  `unit` varchar(20) NOT NULL DEFAULT 'cái',
  `import_price` decimal(12,0) NOT NULL DEFAULT 0,
  `selling_price` decimal(12,0) NOT NULL DEFAULT 0,
  `stock_quantity` int(11) NOT NULL DEFAULT 0,
  `min_stock_alert` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = hoạt động, 0 = xóa mềm'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `parts`
--

INSERT INTO `parts` (`id`, `category_id`, `part_code`, `name`, `unit`, `import_price`, `selling_price`, `stock_quantity`, `min_stock_alert`, `is_active`) VALUES
(1, 1, 'DN-5W30-4L', 'Dầu nhớt tổng hợp 5W-30 (4 lít)', 'can', 650000, 850000, 20, 5, 1),
(2, 2, 'LD-TOY-01', 'Lọc dầu động cơ Toyota', 'cái', 70000, 120000, 30, 8, 1),
(3, 3, 'BG-NGK-01', 'Bugi Iridium NGK', 'cái', 90000, 150000, 40, 10, 1),
(4, 4, 'MP-TRUOC-01', 'Má phanh trước (bộ)', 'bộ', 450000, 700000, 12, 4, 1),
(5, 6, 'MS-OBC-7K', 'Module sạc onboard 7kW', 'cái', 12000000, 15500000, 2, 1, 1),
(6, 7, 'DC-CA-01', 'Dây cáp cao áp 1m', 'sợi', 1500000, 2300000, 6, 2, 1);

-- --------------------------------------------------------

--
-- Table structure for table `part_categories`
--

CREATE TABLE `part_categories` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(100) NOT NULL,
  `target_engine` enum('gasoline','ev','common') NOT NULL DEFAULT 'common'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `part_categories`
--

INSERT INTO `part_categories` (`id`, `name`, `target_engine`) VALUES
(1, 'Dầu nhớt', 'gasoline'),
(2, 'Lọc dầu', 'gasoline'),
(3, 'Bugi', 'gasoline'),
(4, 'Má phanh', 'common'),
(5, 'Pin cao áp', 'ev'),
(6, 'Module sạc', 'ev'),
(7, 'Dây cáp cao áp', 'ev');

-- --------------------------------------------------------

--
-- Table structure for table `quotes`
--

CREATE TABLE `quotes` (
  `id` int(10) UNSIGNED NOT NULL,
  `order_id` int(10) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `total_amount` decimal(12,0) NOT NULL DEFAULT 0,
  `status` enum('draft','sent','approved','rejected') NOT NULL DEFAULT 'draft'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `quote_items`
--

CREATE TABLE `quote_items` (
  `id` int(10) UNSIGNED NOT NULL,
  `quote_id` int(10) UNSIGNED NOT NULL,
  `item_type` enum('service','part') NOT NULL,
  `description` varchar(255) NOT NULL,
  `quantity` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `unit_price` decimal(12,0) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `reception_images`
--

CREATE TABLE `reception_images` (
  `id` int(10) UNSIGNED NOT NULL,
  `order_id` int(10) UNSIGNED NOT NULL,
  `image_path` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `repair_orders`
--

CREATE TABLE `repair_orders` (
  `id` int(10) UNSIGNED NOT NULL,
  `order_code` varchar(20) NOT NULL COMMENT 'Mã phiếu công khai (VD: RO20261020-001)',
  `vehicle_id` int(10) UNSIGNED NOT NULL,
  `km_in` int(11) DEFAULT 0,
  `symptoms` text DEFAULT NULL,
  `note` text DEFAULT NULL,
  `appointment_id` int(10) UNSIGNED DEFAULT NULL,
  `receptionist_id` int(11) DEFAULT NULL,
  `technician_id` int(11) DEFAULT NULL,
  `assigned_to` int(10) UNSIGNED DEFAULT NULL COMMENT 'Thợ phụ trách',
  `received_at` datetime NOT NULL DEFAULT current_timestamp(),
  `odometer` int(10) UNSIGNED DEFAULT NULL,
  `customer_request` text NOT NULL COMMENT 'Yêu cầu sửa chữa của khách',
  `initial_condition` text DEFAULT NULL,
  `ev_notes` text DEFAULT NULL COMMENT 'Ghi chú pin/sạc cho xe điện',
  `estimated_cost` decimal(12,0) NOT NULL DEFAULT 0,
  `status` enum('received','diagnosing','repairing','inspecting','completed','delivered') NOT NULL DEFAULT 'received',
  `created_by` int(11) DEFAULT NULL,
  `is_stock_deducted` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = đã trừ kho vật tư khi duyệt báo giá',
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `repair_orders`
--

INSERT INTO `repair_orders` (`id`, `order_code`, `vehicle_id`, `km_in`, `symptoms`, `note`, `appointment_id`, `receptionist_id`, `technician_id`, `assigned_to`, `received_at`, `odometer`, `customer_request`, `initial_condition`, `ev_notes`, `estimated_cost`, `status`, `created_by`, `is_stock_deducted`, `updated_at`, `created_at`) VALUES
(2, 'RO-20261020-849', 1, 35000, 'Thay dầu máy và kiểm tra hệ thống phanh', 'Khách hẹn 17h quay lại lấy', NULL, 1, NULL, NULL, '2026-10-20 22:36:36', NULL, '', NULL, NULL, 0, 'completed', 1, 0, '2026-10-20 23:05:36', '2026-10-20 22:36:36'),
(3, 'RO-20261020-844', 1, 35000, 'Thay dầu máy và kiểm tra hệ thống phanh', 'Khách hẹn 17h quay lại lấy', NULL, 1, NULL, NULL, '2026-10-20 22:38:40', NULL, '', NULL, NULL, 0, 'received', 1, 0, '2026-10-20 22:38:40', '2026-10-20 22:38:40');

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(50) NOT NULL COMMENT 'Admin | Receptionist | Technician',
  `description` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `name`, `description`) VALUES
(1, 'Admin', 'Toàn quyền quản trị, xem báo cáo, quản lý nhân viên và kho'),
(2, 'Receptionist', 'Lễ tân: tiếp nhận xe, lập phiếu, duyệt báo giá, xuất hóa đơn'),
(3, 'Technician', 'Thợ kỹ thuật: cập nhật tiến độ, đề xuất vật tư dịch vụ');

-- --------------------------------------------------------

--
-- Table structure for table `services`
--

CREATE TABLE `services` (
  `id` int(10) UNSIGNED NOT NULL,
  `name` varchar(150) NOT NULL,
  `target_engine` enum('gasoline','ev','common') NOT NULL DEFAULT 'common',
  `price` decimal(12,0) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = đang áp dụng, 0 = ngừng áp dụng'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `services`
--

INSERT INTO `services` (`id`, `name`, `target_engine`, `price`, `is_active`) VALUES
(1, 'Chẩn đoán lỗi bằng máy', 'common', 200000, 1),
(2, 'Bảo dưỡng định kỳ', 'common', 500000, 1),
(3, 'Thay dầu nhớt động cơ', 'gasoline', 150000, 1),
(4, 'Thay bugi', 'gasoline', 200000, 1),
(5, 'Thay má phanh', 'common', 300000, 1),
(6, 'Kiểm tra pin cao áp', 'ev', 400000, 1),
(7, 'Kiểm tra hệ thống sạc', 'ev', 300000, 1),
(8, 'Thay module sạc', 'ev', 800000, 1);

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` int(10) UNSIGNED NOT NULL,
  `role_id` int(10) UNSIGNED NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL COMMENT 'Mật khẩu đã mã hóa password_hash()',
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(15) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = hoạt động, 0 = bị khóa/xóa mềm',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `role_id`, `username`, `password`, `full_name`, `email`, `phone`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 1, 'admin', '$2y$10$dRchgEo43b8D/1TuvuTcp.ypG8hEY90w93f4i0ZIBAMDaqEj58TDm', 'Quản Trị Viên', 'admin@gara.com', '0901111111', 1, '2026-10-20 21:53:52', '2026-10-20 21:53:52'),
(2, 2, 'letan01', '$2y$10$FAC2T0FvAV6IDLdt2..bmeOp9R47WulX2cjhHBTp910RS6soZEiDq', 'Lễ Tân Nguyễn Văn A', 'letan@gara.com', '0902222222', 1, '2026-10-20 21:53:52', '2026-10-20 21:53:52'),
(3, 3, 'tho01', '$2y$10$FAC2T0FvAV6IDLdt2..bmeOp9R47WulX2cjhHBTp910RS6soZEiDq', 'Thợ Kỹ Thuật Trần Văn B', 'tho01@gara.com', '0903333333', 1, '2026-10-20 21:53:52', '2026-10-20 21:53:52');

-- --------------------------------------------------------

--
-- Table structure for table `vehicles`
--

CREATE TABLE `vehicles` (
  `id` int(10) UNSIGNED NOT NULL,
  `customer_id` int(10) UNSIGNED NOT NULL,
  `license_plate` varchar(15) NOT NULL,
  `brand` varchar(50) NOT NULL,
  `model` varchar(100) DEFAULT NULL,
  `year` smallint(5) UNSIGNED DEFAULT NULL COMMENT 'Năm sản xuất',
  `color` varchar(50) DEFAULT NULL,
  `vin_number` varchar(100) DEFAULT NULL,
  `engine_number` varchar(100) DEFAULT NULL,
  `is_ev` tinyint(1) DEFAULT 0,
  `engine_type` enum('gasoline','ev','hybrid') NOT NULL DEFAULT 'gasoline',
  `odometer` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Số km hiện tại',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = hoạt động, 0 = xóa mềm',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `vehicles`
--

INSERT INTO `vehicles` (`id`, `customer_id`, `license_plate`, `brand`, `model`, `year`, `color`, `vin_number`, `engine_number`, `is_ev`, `engine_type`, `odometer`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 1, '65A-123.45', '', 'Camry', NULL, 'Trắng', '', '', 0, 'gasoline', 0, 1, '2026-10-20 22:15:14', '2026-10-20 22:15:14');

-- --------------------------------------------------------

--
-- Table structure for table `vehicle_ev_details`
--

CREATE TABLE `vehicle_ev_details` (
  `vehicle_id` int(10) UNSIGNED NOT NULL,
  `battery_capacity` decimal(6,2) DEFAULT NULL COMMENT 'Dung lượng pin (kWh)',
  `battery_soh` decimal(5,2) DEFAULT NULL COMMENT 'Tình trạng sức khỏe pin (%)',
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `appointments`
--
ALTER TABLE `appointments`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_appointments_date_slot` (`appointment_date`,`time_slot`),
  ADD KEY `idx_appointments_customer` (`customer_id`),
  ADD KEY `idx_appointments_vehicle` (`vehicle_id`);

--
-- Indexes for table `customers`
--
ALTER TABLE `customers`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_customers_phone` (`phone`),
  ADD KEY `idx_customers_full_name` (`full_name`);

--
-- Indexes for table `inventory_transactions`
--
ALTER TABLE `inventory_transactions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_inventory_part_time` (`part_id`,`created_at`),
  ADD KEY `idx_inventory_user` (`user_id`);

--
-- Indexes for table `invoices`
--
ALTER TABLE `invoices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `invoice_code` (`invoice_code`),
  ADD KEY `idx_invoices_order_id` (`order_id`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notifications_user` (`user_id`,`is_read`),
  ADD KEY `idx_notifications_order` (`order_id`);

--
-- Indexes for table `order_parts`
--
ALTER TABLE `order_parts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_order_parts_order` (`order_id`),
  ADD KEY `idx_order_parts_part` (`part_id`);

--
-- Indexes for table `order_services`
--
ALTER TABLE `order_services`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_order_services_order` (`order_id`),
  ADD KEY `idx_order_services_service` (`service_id`);

--
-- Indexes for table `order_status_history`
--
ALTER TABLE `order_status_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_history_order_time` (`order_id`,`created_at`),
  ADD KEY `idx_history_user` (`created_by`);

--
-- Indexes for table `parts`
--
ALTER TABLE `parts`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_parts_code` (`part_code`),
  ADD KEY `idx_parts_category` (`category_id`);

--
-- Indexes for table `part_categories`
--
ALTER TABLE `part_categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_part_categories_name` (`name`);

--
-- Indexes for table `quotes`
--
ALTER TABLE `quotes`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_quotes_order` (`order_id`);

--
-- Indexes for table `quote_items`
--
ALTER TABLE `quote_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_quote_items_quote` (`quote_id`);

--
-- Indexes for table `reception_images`
--
ALTER TABLE `reception_images`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_reception_images_order` (`order_id`);

--
-- Indexes for table `repair_orders`
--
ALTER TABLE `repair_orders`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_orders_order_code` (`order_code`),
  ADD UNIQUE KEY `uq_orders_appointment` (`appointment_id`),
  ADD KEY `idx_orders_vehicle` (`vehicle_id`),
  ADD KEY `idx_orders_receptionist` (`receptionist_id`),
  ADD KEY `idx_orders_assigned` (`assigned_to`),
  ADD KEY `idx_orders_status` (`status`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_roles_name` (`name`);

--
-- Indexes for table `services`
--
ALTER TABLE `services`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_services_name` (`name`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_users_username` (`username`),
  ADD UNIQUE KEY `uq_users_email` (`email`),
  ADD KEY `idx_users_role` (`role_id`);

--
-- Indexes for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_vehicles_license_plate` (`license_plate`),
  ADD KEY `idx_vehicles_customer` (`customer_id`);

--
-- Indexes for table `vehicle_ev_details`
--
ALTER TABLE `vehicle_ev_details`
  ADD PRIMARY KEY (`vehicle_id`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `appointments`
--
ALTER TABLE `appointments`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `customers`
--
ALTER TABLE `customers`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `inventory_transactions`
--
ALTER TABLE `inventory_transactions`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `invoices`
--
ALTER TABLE `invoices`
  MODIFY `id` int(11) NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `order_parts`
--
ALTER TABLE `order_parts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `order_services`
--
ALTER TABLE `order_services`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `order_status_history`
--
ALTER TABLE `order_status_history`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `parts`
--
ALTER TABLE `parts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `part_categories`
--
ALTER TABLE `part_categories`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `quotes`
--
ALTER TABLE `quotes`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `quote_items`
--
ALTER TABLE `quote_items`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `reception_images`
--
ALTER TABLE `reception_images`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `repair_orders`
--
ALTER TABLE `repair_orders`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `services`
--
ALTER TABLE `services`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `vehicles`
--
ALTER TABLE `vehicles`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `appointments`
--
ALTER TABLE `appointments`
  ADD CONSTRAINT `fk_appointments_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_appointments_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `inventory_transactions`
--
ALTER TABLE `inventory_transactions`
  ADD CONSTRAINT `fk_inventory_part` FOREIGN KEY (`part_id`) REFERENCES `parts` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_inventory_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notifications_order` FOREIGN KEY (`order_id`) REFERENCES `repair_orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `order_parts`
--
ALTER TABLE `order_parts`
  ADD CONSTRAINT `fk_order_parts_order` FOREIGN KEY (`order_id`) REFERENCES `repair_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_order_parts_part` FOREIGN KEY (`part_id`) REFERENCES `parts` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `order_services`
--
ALTER TABLE `order_services`
  ADD CONSTRAINT `fk_order_services_order` FOREIGN KEY (`order_id`) REFERENCES `repair_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `order_status_history`
--
ALTER TABLE `order_status_history`
  ADD CONSTRAINT `fk_history_order` FOREIGN KEY (`order_id`) REFERENCES `repair_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_history_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `parts`
--
ALTER TABLE `parts`
  ADD CONSTRAINT `fk_parts_category` FOREIGN KEY (`category_id`) REFERENCES `part_categories` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `quotes`
--
ALTER TABLE `quotes`
  ADD CONSTRAINT `fk_quotes_order` FOREIGN KEY (`order_id`) REFERENCES `repair_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `quote_items`
--
ALTER TABLE `quote_items`
  ADD CONSTRAINT `fk_quote_items_quote` FOREIGN KEY (`quote_id`) REFERENCES `quotes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `reception_images`
--
ALTER TABLE `reception_images`
  ADD CONSTRAINT `fk_reception_images_order` FOREIGN KEY (`order_id`) REFERENCES `repair_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `repair_orders`
--
ALTER TABLE `repair_orders`
  ADD CONSTRAINT `fk_orders_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_orders_assigned` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_orders_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `vehicles`
--
ALTER TABLE `vehicles`
  ADD CONSTRAINT `fk_vehicles_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `vehicle_ev_details`
--
ALTER TABLE `vehicle_ev_details`
  ADD CONSTRAINT `fk_ev_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;
redlinev1