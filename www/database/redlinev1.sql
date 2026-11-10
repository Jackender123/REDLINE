-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 10, 2026 at 02:28 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `redlinev1`
--

-- --------------------------------------------------------

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
(1, 'Nguyễn Văn Cường', '0988777666', '', '', 1, '2026-10-20 22:07:28', '2026-10-20 22:07:28'),
(8, 'Nguyễn Văn Cường', '0123710123', NULL, NULL, 1, '2026-10-29 19:42:40', '2026-10-29 19:42:40'),
(16, 'Pham Van Khoi', '8327497', NULL, NULL, 1, '2026-10-31 22:03:57', '2026-10-31 22:03:57');

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
  `payment_status` enum('unpaid','partial','paid','refunded','cancelled') DEFAULT 'unpaid',
  `payment_method` enum('cash','transfer','qr','card') DEFAULT 'cash',
  `created_by` int(11) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `invoices`
--

INSERT INTO `invoices` (`id`, `invoice_code`, `order_id`, `total_services`, `total_parts`, `discount`, `grand_total`, `payment_status`, `payment_method`, `created_by`, `created_at`, `updated_at`) VALUES
(1, 'INV-20261020-417', 2, 250000.00, 0.00, 0.00, 250000.00, 'paid', 'transfer', 1, '2026-10-20 23:05:36', '2026-11-09 23:32:17');

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` int(10) UNSIGNED NOT NULL,
  `email` varchar(190) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `attempted_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
(1, 1, 'DN-5W30-4L', 'Fully Synthetic Engine Oil 5W-30 (4L)', 'canister', 650000, 850000, 20, 5, 1),
(2, 2, 'LD-TOY-01', 'Toyota Engine Oil Filter', 'pcs', 70000, 120000, 30, 8, 1),
(3, 3, 'BG-NGK-01', 'NGK Iridium Spark Plug', 'pcs', 90000, 150000, 40, 10, 1),
(4, 4, 'MP-TRUOC-01', 'Front Brake Pads (Set)', 'set', 450000, 700000, 12, 4, 1),
(5, 6, 'MS-OBC-7K', 'Onboard Charger Module 7kW', 'pcs', 12000000, 15500000, 2, 1, 1),
(6, 7, 'DC-CA-01', 'High Voltage Cable 1m', 'pcs', 1500000, 2300000, 11, 2, 1);

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
(1, 'Engine Oil', 'gasoline'),
(2, 'Oil Filters', 'gasoline'),
(3, 'Spark Plugs', 'gasoline'),
(4, 'Brake Pads', 'common'),
(5, 'Pin cao áp', 'ev'),
(6, 'Charging Modules', 'ev'),
(7, 'High Voltage Cables', 'ev');

-- --------------------------------------------------------

--
-- Table structure for table `portal_users`
--

CREATE TABLE `portal_users` (
  `id` int(10) UNSIGNED NOT NULL,
  `full_name` varchar(120) NOT NULL,
  `email` varchar(190) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role` enum('customer','staff','admin') NOT NULL DEFAULT 'customer',
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `contact_method` enum('phone','zalo','email') NOT NULL DEFAULT 'phone',
  `notify_repair` tinyint(1) NOT NULL DEFAULT 1,
  `notify_reminder` tinyint(1) NOT NULL DEFAULT 1,
  `notify_offers` tinyint(1) NOT NULL DEFAULT 0,
  `zalo` varchar(30) DEFAULT NULL,
  `password_changed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `portal_users`
--

INSERT INTO `portal_users` (`id`, `full_name`, `email`, `phone`, `password_hash`, `role`, `is_active`, `last_login_at`, `created_at`, `contact_method`, `notify_repair`, `notify_reminder`, `notify_offers`, `zalo`, `password_changed_at`) VALUES
(1, 'Demo Customer', 'demo@redline.test', '(555) 019-8871', '$2y$10$UBF.WjEAO5eiU0rcN2Mh2OXzGDDNcXOQ27gHp2XLdgH68ieIQcdwy', 'customer', 1, NULL, '2026-10-24 14:24:14', 'phone', 1, 1, 0, NULL, NULL),
(2, 'Johny Sins', 'cacdai18cm@gmail.com', '12356564', '$2y$10$rvRoxJpPPxn1U.PopAQEtuK0Ndt2Hj65x0eLEqZi5w2fxXt2oma0y', 'admin', 1, '2026-10-24 21:33:50', '2026-10-24 21:25:51', 'phone', 1, 1, 0, NULL, NULL),
(3, 'Johny Sins', 'jonnydarkbao@gmail.com', '+8469330561', '$2y$10$BVd9DnWF1cq3DBuNARkw7ODJLs6esHqbqmZCU65g18w01ztLOpYHW', 'customer', 1, '2026-11-09 21:22:44', '2026-11-09 17:57:13', 'phone', 1, 1, 0, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `portal_vehicles`
--

CREATE TABLE `portal_vehicles` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `make` varchar(60) NOT NULL,
  `model` varchar(80) NOT NULL,
  `year` smallint(5) UNSIGNED DEFAULT NULL,
  `plate` varchar(20) DEFAULT NULL,
  `vin` varchar(17) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `mileage` int(10) UNSIGNED DEFAULT NULL,
  `color` varchar(40) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `portal_vehicles`
--

INSERT INTO `portal_vehicles` (`id`, `user_id`, `make`, `model`, `year`, `plate`, `vin`, `created_at`, `mileage`, `color`, `photo`, `updated_at`) VALUES
(1, 1, 'BMW', 'M3 Competition', 2022, 'RDLN-340', NULL, '2026-10-24 14:24:14', NULL, NULL, NULL, NULL);

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

--
-- Dumping data for table `reception_images`
--

INSERT INTO `reception_images` (`id`, `order_id`, `image_path`, `created_at`) VALUES
(1, 16, 'public/uploads/reception/d1c14a21e7292ea21ee75090528429b6.jpg', '2026-11-10 19:32:53'),
(2, 17, 'public/uploads/reception/559245b238e917c1126cc284085b7719.jpg', '2026-11-10 19:33:54');

-- --------------------------------------------------------

--
-- Table structure for table `remember_tokens`
--

CREATE TABLE `remember_tokens` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED NOT NULL,
  `selector` char(24) NOT NULL,
  `validator_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
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
  `customer_id` int(11) DEFAULT NULL,
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
  `fuel_level` varchar(20) DEFAULT NULL,
  `intake_notes` text DEFAULT NULL,
  `service_type` varchar(50) DEFAULT 'repair',
  `expected_delivery` datetime DEFAULT NULL,
  `ev_notes` text DEFAULT NULL COMMENT 'Ghi chú pin/sạc cho xe điện',
  `estimated_cost` decimal(12,0) NOT NULL DEFAULT 0,
  `status` enum('received','diagnosing','repairing','waiting_parts','inspecting','completed','delivered') NOT NULL DEFAULT 'received',
  `total_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `discount_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `final_amount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `created_by` int(11) DEFAULT NULL,
  `is_stock_deducted` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1 = đã trừ kho vật tư khi duyệt báo giá',
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `repair_orders`
--

INSERT INTO `repair_orders` (`id`, `order_code`, `vehicle_id`, `customer_id`, `km_in`, `symptoms`, `note`, `appointment_id`, `receptionist_id`, `technician_id`, `assigned_to`, `received_at`, `odometer`, `customer_request`, `initial_condition`, `fuel_level`, `intake_notes`, `service_type`, `expected_delivery`, `ev_notes`, `estimated_cost`, `status`, `total_amount`, `discount_amount`, `final_amount`, `created_by`, `is_stock_deducted`, `updated_at`, `created_at`) VALUES
(2, 'RO-20261020-849', 1, NULL, 35000, 'Thay dầu máy và kiểm tra hệ thống phanh', 'Khách hẹn 17h quay lại lấy', NULL, 1, NULL, NULL, '2026-10-20 22:36:36', NULL, '', NULL, NULL, NULL, 'repair', NULL, NULL, 0, 'completed', 0.00, 0.00, 0.00, 1, 0, '2026-10-20 23:05:36', '2026-10-20 22:36:36'),
(3, 'RO-20261020-844', 1, NULL, 35000, 'Thay dầu máy và kiểm tra hệ thống phanh', 'Khách hẹn 17h quay lại lấy', NULL, 1, NULL, NULL, '2026-10-20 22:38:40', NULL, '', NULL, NULL, NULL, 'repair', NULL, NULL, 0, 'received', 0.00, 0.00, 0.00, 1, 0, '2026-10-20 22:38:40', '2026-10-20 22:38:40'),
(4, 'RO-20261029-713', 8, NULL, 120000, '', NULL, NULL, 1, NULL, NULL, '2026-10-29 19:42:40', 120000, '', 'Xăng/Dầu: 50% | Dịch vụ: repair', NULL, NULL, 'repair', NULL, NULL, 0, 'received', 0.00, 0.00, 0.00, 1, 0, '2026-10-29 19:42:40', '2026-10-29 19:42:40'),
(5, 'RO-20261029-506', 8, NULL, 120000, '', NULL, NULL, 1, NULL, NULL, '2026-10-29 19:52:26', 120000, '', 'Nhiên liệu: 50%', NULL, NULL, 'repair', NULL, NULL, 0, 'received', 0.00, 0.00, 0.00, 1, 0, '2026-10-29 19:52:26', '2026-10-29 19:52:26'),
(6, 'RO-20261029-599', 8, NULL, 51231, '', NULL, NULL, 1, NULL, NULL, '2026-10-29 20:47:47', 51231, '', '', '50%', NULL, 'repair', '2046-11-11 17:00:00', NULL, 0, 'received', 0.00, 0.00, 0.00, 1, 0, '2026-10-29 20:47:47', '2026-10-29 20:47:47'),
(7, 'RO-20261030-826', 8, NULL, 0, '', NULL, NULL, 1, NULL, NULL, '2026-10-30 11:18:57', 0, '', '', '50%', NULL, 'OIL & FILTER CHANGE', NULL, NULL, 0, 'received', 0.00, 0.00, 0.00, 1, 0, '2026-10-30 11:18:57', '2026-10-30 11:18:57'),
(8, 'RO-C345D1', 8, 16, 0, '', NULL, NULL, 1, NULL, NULL, '2026-10-31 22:03:57', NULL, 'ENGINE DIAGNOSTIC SCAN', NULL, '1/2', '', 'ENGINE DIAGNOSTIC SCAN', NULL, NULL, 0, 'received', 0.00, 0.00, 0.00, NULL, 0, '2026-10-31 16:03:57', '2026-10-31 16:03:57'),
(9, 'RO-1BFEB9', 8, 16, 0, '', NULL, NULL, 1, NULL, NULL, '2026-10-31 22:04:14', NULL, 'TRANSMISSION SERVICE', NULL, '1/2', '', 'TRANSMISSION SERVICE', NULL, NULL, 0, 'received', 0.00, 0.00, 0.00, NULL, 0, '2026-10-31 16:04:14', '2026-10-31 16:04:14'),
(10, 'RO-BBEFCF', 8, 16, 0, '', NULL, NULL, 1, NULL, NULL, '2026-10-31 22:06:43', NULL, 'ENGINE DIAGNOSTIC SCAN', NULL, '3/4', '', 'ENGINE DIAGNOSTIC SCAN', NULL, NULL, 0, 'received', 0.00, 0.00, 0.00, NULL, 0, '2026-10-31 16:06:43', '2026-10-31 16:06:43'),
(11, 'RO-A2BF98', 8, 16, 0, '', NULL, NULL, 1, NULL, NULL, '2026-10-31 22:10:41', NULL, 'TRANSMISSION SERVICE', NULL, '1/2', '', 'TRANSMISSION SERVICE', NULL, NULL, 0, 'received', 0.00, 0.00, 0.00, NULL, 0, '2026-10-31 16:10:41', '2026-10-31 16:10:41'),
(12, 'RO-CCD337', 8, 16, 0, '', NULL, NULL, 1, NULL, NULL, '2026-10-31 22:14:08', NULL, 'BRAKE PAD REPLACEMENT', NULL, '1/2', '', 'BRAKE PAD REPLACEMENT', NULL, NULL, 0, 'received', 0.00, 0.00, 0.00, NULL, 0, '2026-10-31 16:14:08', '2026-10-31 16:14:08'),
(13, 'RO-9EA173', 8, 16, 0, '', NULL, NULL, 1, NULL, NULL, '2026-11-01 19:36:32', NULL, 'ENGINE DIAGNOSTIC SCAN', NULL, '1/2', '', 'ENGINE DIAGNOSTIC SCAN', NULL, NULL, 0, 'received', 0.00, 0.00, 0.00, NULL, 0, '2026-11-01 13:36:32', '2026-11-01 13:36:32'),
(14, 'RO-CB1C60', 8, 16, 0, '', NULL, NULL, 1, NULL, NULL, '2026-11-01 19:44:24', NULL, 'AIR FILTER REPLACEMENT', NULL, '1/2', '', 'AIR FILTER REPLACEMENT', NULL, NULL, 0, 'received', 8000.00, 0.00, 8000.00, NULL, 0, '2026-11-01 20:33:50', '2026-11-01 13:44:24'),
(15, 'RO-D008AA', 8, 16, 0, '', NULL, NULL, 1, NULL, NULL, '2026-11-03 21:10:17', NULL, 'ENGINE DIAGNOSTIC SCAN', NULL, '1/2', '', 'ENGINE DIAGNOSTIC SCAN', NULL, NULL, 0, 'received', 8000.00, 0.00, 8000.00, NULL, 0, '2026-11-04 00:00:40', '2026-11-03 15:10:17'),
(16, 'RO-C28AD1', 8, 8, 0, '', NULL, NULL, 1, NULL, NULL, '2026-11-10 19:32:53', 0, 'TRANSMISSION SERVICE', NULL, '1/2', '', 'ENGINE DIAGNOSTIC SCAN', NULL, NULL, 0, 'received', 0.00, 0.00, 0.00, NULL, 0, '2026-11-10 13:32:53', '2026-11-10 13:32:53'),
(17, 'RO-53E9F0', 8, 8, 0, '', NULL, NULL, 1, NULL, NULL, '2026-11-10 19:33:54', 0, 'TRANSMISSION SERVICE', NULL, '1/2', '', 'OIL & FILTER CHANGE', NULL, NULL, 0, 'received', 0.00, 0.00, 0.00, NULL, 0, '2026-11-10 13:33:54', '2026-11-10 13:33:54');

-- --------------------------------------------------------

--
-- Table structure for table `repair_order_items`
--

CREATE TABLE `repair_order_items` (
  `id` int(10) UNSIGNED NOT NULL,
  `repair_order_id` int(10) UNSIGNED NOT NULL,
  `item_type` enum('labor','part') NOT NULL DEFAULT 'labor',
  `item_name` varchar(255) NOT NULL,
  `unit_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `subtotal` decimal(15,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `repair_order_items`
--

INSERT INTO `repair_order_items` (`id`, `repair_order_id`, `item_type`, `item_name`, `unit_price`, `quantity`, `subtotal`, `created_at`) VALUES
(1, 14, 'labor', 'ád', 8000.00, 1, 8000.00, '2026-11-01 20:33:50'),
(2, 15, 'labor', 'ád', 8000.00, 1, 8000.00, '2026-11-04 00:00:40');

-- --------------------------------------------------------

--
-- Table structure for table `technical_inspections`
--

CREATE TABLE `technical_inspections` (
  `id` int(10) UNSIGNED NOT NULL,
  `order_id` int(10) UNSIGNED NOT NULL,
  `updated_by` int(10) UNSIGNED DEFAULT NULL,
  `technical_note` text DEFAULT NULL,
  `customer_complaints` text DEFAULT NULL,
  `inspection_needed` text DEFAULT NULL,
  `repairs_performed` text DEFAULT NULL,
  `engine_result` text DEFAULT NULL,
  `brakes_result` text DEFAULT NULL,
  `electrical_result` text DEFAULT NULL,
  `ac_result` text DEFAULT NULL,
  `hybrid_battery_status` text DEFAULT NULL,
  `hybrid_voltage` decimal(8,2) DEFAULT NULL,
  `hybrid_temperature` decimal(5,2) DEFAULT NULL,
  `hybrid_cooling_result` text DEFAULT NULL,
  `inverter_result` text DEFAULT NULL,
  `electric_motor_result` text DEFAULT NULL,
  `dtc_codes` text DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `part_requests`
--

CREATE TABLE `part_requests` (
  `id` int(10) UNSIGNED NOT NULL,
  `request_code` varchar(30) NOT NULL,
  `order_id` int(10) UNSIGNED NOT NULL,
  `part_id` int(10) UNSIGNED NOT NULL,
  `requested_by` int(10) UNSIGNED NOT NULL,
  `quantity` int(10) UNSIGNED NOT NULL,
  `note` text DEFAULT NULL,
  `status` enum('pending','approved','rejected','fulfilled') NOT NULL DEFAULT 'pending',
  `decided_by` int(10) UNSIGNED DEFAULT NULL,
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

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
(3, 'Technician', 'Thợ kỹ thuật: cập nhật tiến độ, đề xuất vật tư dịch vụ'),
(4, 'Cashier', 'Thu ngân: lập hóa đơn, thu tiền, in biên nhận và xem báo cáo doanh thu');

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
-- Table structure for table `service_orders`
--

CREATE TABLE `service_orders` (
  `id` int(10) UNSIGNED NOT NULL,
  `vehicle_id` int(10) UNSIGNED NOT NULL,
  `title` varchar(160) NOT NULL,
  `status` enum('estimate','approved','in_progress','ready','completed','cancelled') NOT NULL DEFAULT 'estimate',
  `estimate` decimal(10,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `service_orders`
--

INSERT INTO `service_orders` (`id`, `vehicle_id`, `title`, `status`, `estimate`, `notes`, `created_at`, `updated_at`) VALUES
(1, 1, 'Stage 2 ECU tune + downpipe install', 'in_progress', 3450.00, 'Dyno session scheduled after install.', '2026-10-24 14:24:14', '2026-10-24 14:24:14');

-- --------------------------------------------------------

--
-- Table structure for table `service_requests`
--

CREATE TABLE `service_requests` (
  `id` int(10) UNSIGNED NOT NULL,
  `user_id` int(10) UNSIGNED DEFAULT NULL,
  `full_name` varchar(120) NOT NULL,
  `phone` varchar(30) NOT NULL,
  `license_plate` varchar(20) DEFAULT NULL,
  `vehicle` varchar(160) NOT NULL,
  `vin_number` varchar(50) DEFAULT NULL,
  `service_type` varchar(50) DEFAULT NULL,
  `service` varchar(100) NOT NULL,
  `message` text DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `status` enum('pending','processed','cancelled') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `service_requests`
--

INSERT INTO `service_requests` (`id`, `user_id`, `full_name`, `phone`, `license_plate`, `vehicle`, `vin_number`, `service_type`, `service`, `message`, `ip_address`, `status`, `created_at`) VALUES
(1, NULL, 'Phạm Khôi', '8327497', NULL, 'Camry', NULL, NULL, 'OIL & FILTER CHANGE', NULL, '::1', 'pending', '2026-10-29 20:49:31'),
(2, NULL, 'Phạm Khôi', '8340239', NULL, 'Camry', NULL, NULL, 'ENGINE DIAGNOSTIC SCAN', NULL, '::1', 'pending', '2026-10-30 11:17:35'),
(3, NULL, 'Phạm Khôi', '8340239', NULL, 'Camry', NULL, NULL, 'ENGINE DIAGNOSTIC SCAN', NULL, '::1', 'pending', '2026-10-30 11:17:35'),
(4, NULL, 'Pham Van Khoi', '8327497', NULL, 'Camry', NULL, NULL, 'ENGINE DIAGNOSTIC SCAN', NULL, '::1', 'pending', '2026-10-31 21:05:25'),
(5, NULL, 'Pham Van Khoi', '8327497', NULL, 'Camry', NULL, NULL, 'AIR FILTER REPLACEMENT', NULL, '::1', '', '2026-10-31 21:39:32'),
(6, NULL, 'Pham Van Khoi', '8327497', NULL, 'Camry [65A-04953]', NULL, NULL, 'TRANSMISSION SERVICE', NULL, '::1', '', '2026-10-31 21:44:43'),
(7, NULL, 'Pham Van Khoi', '8327497', '65A-04953', 'Camry', 'RL2-ư3242', 'TRANSMISSION SERVICE', 'TRANSMISSION SERVICE', NULL, '::1', '', '2026-10-31 21:55:54'),
(8, NULL, 'Pham Van Khoi', '8327497', '65A-04953', 'Camry', 'RL2-ư3242', 'ENGINE DIAGNOSTIC SCAN', 'ENGINE DIAGNOSTIC SCAN', NULL, '::1', '', '2026-10-31 21:59:53'),
(9, NULL, 'Pham Van Khoi', '8327497', NULL, 'Camry [65A-04953] VIN:RL2-ư3242 [Fuel:3/4]', NULL, NULL, 'ENGINE DIAGNOSTIC SCAN', NULL, '::1', '', '2026-10-31 22:06:33'),
(10, NULL, 'Pham Van Khoi', '8327497', NULL, 'Camry [65A-04953] VIN:RL2-ư3242 [Fuel:1/2]', NULL, NULL, 'BRAKE PAD REPLACEMENT', NULL, '::1', '', '2026-10-31 22:14:00'),
(11, NULL, 'Pham Van Khoi', '8327497', NULL, 'Camry [65A-04953] VIN:RL2-ư3242 [Fuel:1/2]', NULL, NULL, 'ENGINE DIAGNOSTIC SCAN', NULL, '::1', '', '2026-10-31 22:19:39'),
(12, NULL, 'Pham Van Khoi', '8327497', NULL, 'Camry [65A-04953] VIN:RL2-ư3242 [Fuel:1/2]', NULL, NULL, 'ENGINE DIAGNOSTIC SCAN', NULL, '::1', '', '2026-11-03 21:09:28'),
(13, NULL, 'Pham Van Khoi', '0372975331', NULL, 'Camry [65A-04953] VIN:RL2-2342 [Fuel:3/4]', NULL, NULL, 'ENGINE DIAGNOSTIC SCAN', NULL, '::1', 'pending', '2026-11-03 23:38:56');

-- --------------------------------------------------------

--
-- Table structure for table `settings`
--

CREATE TABLE `settings` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

--
-- Dumping data for table `settings`
--

INSERT INTO `settings` (`setting_key`, `setting_value`, `updated_at`) VALUES
('bank_account_name', 'REDLINE', '2026-11-09 23:17:14'),
('bank_account_no', '0901234567', '2026-11-01 19:58:42'),
('bank_id', 'VCB', '2026-11-09 23:17:14'),
('garage_address', '123 Đường 3/2, Q. Ninh Kiều, TP. Cần Thơ', '2026-11-01 19:58:42'),
('garage_hotline', '0901.234.567', '2026-11-01 19:58:42'),
('garage_name', 'REDLINE', '2026-11-09 23:17:14'),
('garage_terms', '- Phụ tùng chính hãng được bảo hành 06 tháng hoặc 10.000 km.\r\n- Quý khách vui lòng kiểm tra kỹ phương tiện và tài sản cá nhân trước khi rời xưởng.', '2026-11-01 19:58:42'),
('garage_website', 'pharma.id.vn', '2026-11-09 23:17:14'),
('min_stock_default', '5', '2026-11-01 19:58:42');

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
(3, 3, 'tho01', '$2y$10$FAC2T0FvAV6IDLdt2..bmeOp9R47WulX2cjhHBTp910RS6soZEiDq', 'Thợ Kỹ Thuật Trần Văn B', 'tho01@gara.com', '0903333333', 1, '2026-10-20 21:53:52', '2026-10-20 21:53:52'),
(4, 2, 'johnysins', '$2y$10$TF/2bMcccVPJ0TdEvQCqeuSMMVo5Aag5XnLfa11i4FPdKmdmvmkCO', 'Johny Sins', 'jonnydarkbao@gmail.com', '34234', 1, '2026-11-10 19:53:38', '2026-11-10 19:53:59'),
(6, 3, 'khoip8500', '$2y$10$Ow8Bg4IhJW.3F5qs8dP0o.c8HXhLVbYhosClFq.0WltoE/2OEVQ.q', 'khoisdznhattg', 'khoip8500@gmail.com', '1232562', 1, '2026-11-10 19:56:34', '2026-11-10 19:56:34');

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
  `vin` varchar(50) DEFAULT NULL,
  `year` smallint(5) UNSIGNED DEFAULT NULL COMMENT 'Năm sản xuất',
  `color` varchar(50) DEFAULT NULL,
  `vin_number` varchar(100) DEFAULT NULL,
  `engine_number` varchar(100) DEFAULT NULL,
  `is_ev` tinyint(1) DEFAULT 0,
  `engine_type` enum('gasoline','diesel','hybrid','ev') NOT NULL DEFAULT 'gasoline',
  `odometer` int(10) UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Số km hiện tại',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = hoạt động, 0 = xóa mềm',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `vehicles`
--

INSERT INTO `vehicles` (`id`, `customer_id`, `license_plate`, `brand`, `model`, `vin`, `year`, `color`, `vin_number`, `engine_number`, `is_ev`, `engine_type`, `odometer`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 1, '65A-123.45', '', 'Camry', NULL, NULL, 'Trắng', '', '', 0, 'gasoline', 0, 1, '2026-10-20 22:15:14', '2026-10-20 22:15:14'),
(8, 8, '65A-04953', 'Toyota', 'Camry', 'RL2-2342', NULL, NULL, 'RL2-Ư3242', NULL, 0, 'gasoline', 0, 1, '2026-10-29 19:42:40', '2026-11-10 13:33:54');

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
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_attempt_email` (`email`,`attempted_at`),
  ADD KEY `idx_attempt_ip` (`ip_address`,`attempted_at`);

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
-- Indexes for table `portal_users`
--
ALTER TABLE `portal_users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_portal_users_email` (`email`);

--
-- Indexes for table `portal_vehicles`
--
ALTER TABLE `portal_vehicles`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_vehicle_user` (`user_id`);

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
-- Indexes for table `remember_tokens`
--
ALTER TABLE `remember_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_selector` (`selector`),
  ADD KEY `idx_remember_user` (`user_id`);

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
-- Indexes for table `repair_order_items`
--
ALTER TABLE `repair_order_items`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_repair_order_items_order` (`repair_order_id`);

--
-- Indexes for table `technical_inspections`
--
ALTER TABLE `technical_inspections`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_technical_inspections_order` (`order_id`),
  ADD KEY `idx_technical_inspections_updated_by` (`updated_by`);

--
-- Indexes for table `part_requests`
--
ALTER TABLE `part_requests`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_part_requests_code` (`request_code`),
  ADD KEY `idx_part_requests_order` (`order_id`),
  ADD KEY `idx_part_requests_part` (`part_id`),
  ADD KEY `idx_part_requests_requester` (`requested_by`),
  ADD KEY `idx_part_requests_decider` (`decided_by`),
  ADD KEY `idx_part_requests_status_requested` (`status`, `requested_at`);

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
-- Indexes for table `service_orders`
--
ALTER TABLE `service_orders`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_order_vehicle` (`vehicle_id`),
  ADD KEY `idx_order_status` (`status`);

--
-- Indexes for table `service_requests`
--
ALTER TABLE `service_requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_service_req_user` (`user_id`),
  ADD KEY `idx_service_req_status` (`status`);

--
-- Indexes for table `settings`
--
ALTER TABLE `settings`
  ADD PRIMARY KEY (`setting_key`);

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
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=17;

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
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

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
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `part_categories`
--
ALTER TABLE `part_categories`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT for table `portal_users`
--
ALTER TABLE `portal_users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=4;

--
-- AUTO_INCREMENT for table `portal_vehicles`
--
ALTER TABLE `portal_vehicles`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

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
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `remember_tokens`
--
ALTER TABLE `remember_tokens`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `repair_orders`
--
ALTER TABLE `repair_orders`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=18;

--
-- AUTO_INCREMENT for table `repair_order_items`
--
ALTER TABLE `repair_order_items`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `technical_inspections`
--
ALTER TABLE `technical_inspections`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `part_requests`
--
ALTER TABLE `part_requests`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `services`
--
ALTER TABLE `services`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

--
-- AUTO_INCREMENT for table `service_orders`
--
ALTER TABLE `service_orders`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=2;

--
-- AUTO_INCREMENT for table `service_requests`
--
ALTER TABLE `service_requests`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=14;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- AUTO_INCREMENT for table `vehicles`
--
ALTER TABLE `vehicles`
  MODIFY `id` int(10) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=9;

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
-- Constraints for table `portal_vehicles`
--
ALTER TABLE `portal_vehicles`
  ADD CONSTRAINT `fk_portal_vehicle_user` FOREIGN KEY (`user_id`) REFERENCES `portal_users` (`id`) ON DELETE CASCADE;

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
-- Constraints for table `remember_tokens`
--
ALTER TABLE `remember_tokens`
  ADD CONSTRAINT `fk_remember_user` FOREIGN KEY (`user_id`) REFERENCES `portal_users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `repair_orders`
--
ALTER TABLE `repair_orders`
  ADD CONSTRAINT `fk_orders_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_orders_assigned` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_orders_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON UPDATE CASCADE;

--
-- Constraints for table `repair_order_items`
--
ALTER TABLE `repair_order_items`
  ADD CONSTRAINT `fk_repair_order_items_order` FOREIGN KEY (`repair_order_id`) REFERENCES `repair_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Constraints for table `technical_inspections`
--
ALTER TABLE `technical_inspections`
  ADD CONSTRAINT `fk_technical_inspections_order` FOREIGN KEY (`order_id`) REFERENCES `repair_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_technical_inspections_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `part_requests`
--
ALTER TABLE `part_requests`
  ADD CONSTRAINT `fk_part_requests_order` FOREIGN KEY (`order_id`) REFERENCES `repair_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_part_requests_part` FOREIGN KEY (`part_id`) REFERENCES `parts` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_part_requests_requester` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  ADD CONSTRAINT `fk_part_requests_decider` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;

--
-- Constraints for table `service_orders`
--
ALTER TABLE `service_orders`
  ADD CONSTRAINT `fk_order_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `portal_vehicles` (`id`) ON DELETE CASCADE;

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
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
