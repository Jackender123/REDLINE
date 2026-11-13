-- REDLINE redlinev1 - BẢN ĐỒNG BỘ (file 1 làm nền dữ liệu + ràng buộc FK/ENUM chặt chẽ từ file 2)
-- Lưu ý: file này DROP TABLE rồi tạo lại -> chỉ import khi muốn dựng lại toàn bộ DB.

/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;

CREATE DATABASE IF NOT EXISTS `redlinev1` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `redlinev1`;

-- ===================== appointments =====================
DROP TABLE IF EXISTS `appointments`;
CREATE TABLE `appointments` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` int(10) unsigned NOT NULL,
  `vehicle_id` int(10) unsigned NOT NULL,
  `appointment_date` date NOT NULL,
  `time_slot` varchar(20) NOT NULL COMMENT 'Khung giờ (VD: 08:00-09:00)',
  `note` varchar(255) DEFAULT NULL,
  `status` enum('pending','confirmed','arrived','canceled') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_appointments_date_slot` (`appointment_date`,`time_slot`),
  KEY `idx_appointments_customer` (`customer_id`),
  KEY `idx_appointments_vehicle` (`vehicle_id`),
  CONSTRAINT `fk_appointments_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_appointments_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== cash_transactions (cấu trúc file 2 + FK) =====================
DROP TABLE IF EXISTS `cash_transactions`;
CREATE TABLE `cash_transactions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `transaction_code` varchar(40) NOT NULL,
  `invoice_id` int(11) NOT NULL,
  `transaction_type` enum('payment','refund') NOT NULL DEFAULT 'payment',
  `amount` decimal(15,2) NOT NULL,
  `payment_method` enum('cash','transfer','qr','card') NOT NULL,
  `status` enum('completed','voided') NOT NULL DEFAULT 'completed',
  `note` varchar(255) DEFAULT NULL,
  `created_by` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cash_transactions_code` (`transaction_code`),
  KEY `idx_cash_transactions_invoice_date` (`invoice_id`,`created_at`),
  KEY `idx_cash_transactions_date_method` (`created_at`,`payment_method`,`status`),
  KEY `idx_cash_transactions_user` (`created_by`),
  CONSTRAINT `fk_cash_transactions_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cash_transactions_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== customers =====================
DROP TABLE IF EXISTS `customers`;
CREATE TABLE `customers` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `full_name` varchar(150) NOT NULL,
  `phone` varchar(30) NOT NULL COMMENT 'Dùng tra cứu tiến độ công khai',
  `email` varchar(190) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = hoạt động, 0 = xóa mềm',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `notes` text DEFAULT NULL,
  `portal_user_id` int(10) unsigned DEFAULT NULL,
  `zalo` varchar(30) DEFAULT NULL,
  `contact_method` enum('phone','zalo','email') NOT NULL DEFAULT 'phone',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_customers_phone` (`phone`),
  UNIQUE KEY `uq_customers_portal_user` (`portal_user_id`),
  KEY `idx_customers_full_name` (`full_name`)
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `customers` VALUES
(1,'Nguyễn Văn Cường','0988777666','','',1,'2026-10-20 22:07:28','2026-10-20 22:07:28',NULL,NULL,NULL,'phone'),
(8,'Nguyễn Văn Cường','0123710123',NULL,NULL,1,'2026-10-29 19:42:40','2026-10-29 19:42:40',NULL,NULL,NULL,'phone'),
(16,'Pham Van Khoi','8327497',NULL,NULL,1,'2026-10-31 22:03:57','2026-10-31 22:03:57',NULL,NULL,NULL,'phone');

-- ===================== inventory_transactions =====================
DROP TABLE IF EXISTS `inventory_transactions`;
CREATE TABLE `inventory_transactions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `part_id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned NOT NULL COMMENT 'Người thực hiện nhập/xuất',
  `type` enum('import','export') NOT NULL,
  `quantity` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `note` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_inventory_part_time` (`part_id`,`created_at`),
  KEY `idx_inventory_user` (`user_id`),
  CONSTRAINT `fk_inventory_part` FOREIGN KEY (`part_id`) REFERENCES `parts` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_inventory_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== invoice_items (cấu trúc file 1 + FK file 2) =====================
DROP TABLE IF EXISTS `invoice_items`;
CREATE TABLE `invoice_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` int(11) NOT NULL,
  `item_type` enum('service','part') NOT NULL DEFAULT 'service',
  `description` varchar(255) NOT NULL,
  `quantity` int(10) unsigned NOT NULL DEFAULT 1,
  `unit_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `subtotal` decimal(15,2) NOT NULL DEFAULT 0.00,
  PRIMARY KEY (`id`),
  KEY `idx_invoice_items_invoice` (`invoice_id`),
  CONSTRAINT `fk_invoice_items_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== invoices =====================
DROP TABLE IF EXISTS `invoices`;
CREATE TABLE `invoices` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
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
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_code` (`invoice_code`),
  KEY `idx_invoices_order_id` (`order_id`)
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `invoices` VALUES
(1,'INV-20261020-417',2,250000.00,0.00,0.00,250000.00,'paid','transfer',1,'2026-10-20 23:05:36','2026-11-09 23:32:17');

-- ===================== login_attempts =====================
DROP TABLE IF EXISTS `login_attempts`;
CREATE TABLE `login_attempts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(190) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `attempted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_attempt_email` (`email`,`attempted_at`),
  KEY `idx_attempt_ip` (`ip_address`,`attempted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== notifications =====================
DROP TABLE IF EXISTS `notifications`;
CREATE TABLE `notifications` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL COMMENT 'Người nhận thông báo',
  `order_id` int(10) unsigned DEFAULT NULL,
  `content` varchar(255) NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT 0,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_notifications_user` (`user_id`,`is_read`),
  KEY `idx_notifications_order` (`order_id`),
  CONSTRAINT `fk_notifications_order` FOREIGN KEY (`order_id`) REFERENCES `repair_orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== order_parts =====================
DROP TABLE IF EXISTS `order_parts`;
CREATE TABLE `order_parts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int(10) unsigned NOT NULL,
  `part_id` int(10) unsigned NOT NULL,
  `quantity` int(10) unsigned NOT NULL DEFAULT 1,
  `price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `unit_price` decimal(12,0) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_order_parts_order` (`order_id`),
  KEY `idx_order_parts_part` (`part_id`),
  CONSTRAINT `fk_order_parts_order` FOREIGN KEY (`order_id`) REFERENCES `repair_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_order_parts_part` FOREIGN KEY (`part_id`) REFERENCES `parts` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== order_services =====================
DROP TABLE IF EXISTS `order_services`;
CREATE TABLE `order_services` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int(10) unsigned NOT NULL,
  `service_id` int(11) DEFAULT NULL,
  `quantity` smallint(5) unsigned NOT NULL DEFAULT 1,
  `price` decimal(12,2) NOT NULL DEFAULT 0.00,
  `unit_price` decimal(12,0) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_order_services_order` (`order_id`),
  KEY `idx_order_services_service` (`service_id`),
  CONSTRAINT `fk_order_services_order` FOREIGN KEY (`order_id`) REFERENCES `repair_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `order_services` VALUES
(1,2,NULL,1,250000.00,0,NULL,'2026-10-20 22:51:35');

-- ===================== order_status_history =====================
DROP TABLE IF EXISTS `order_status_history`;
CREATE TABLE `order_status_history` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int(10) unsigned NOT NULL,
  `created_by` int(10) unsigned NOT NULL COMMENT 'user_id người thực hiện',
  `from_status` varchar(30) DEFAULT NULL,
  `to_status` varchar(30) NOT NULL,
  `note` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_history_order_time` (`order_id`,`created_at`),
  KEY `idx_history_user` (`created_by`),
  CONSTRAINT `fk_history_order` FOREIGN KEY (`order_id`) REFERENCES `repair_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_history_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== part_categories =====================
DROP TABLE IF EXISTS `part_categories`;
CREATE TABLE `part_categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `target_engine` enum('gasoline','ev','common') NOT NULL DEFAULT 'common',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_part_categories_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `part_categories` VALUES
(1,'Engine Oil','gasoline'),
(2,'Oil Filters','gasoline'),
(3,'Spark Plugs','gasoline'),
(4,'Brake Pads','common'),
(5,'Pin cao áp','ev'),
(6,'Charging Modules','ev'),
(7,'High Voltage Cables','ev');

-- ===================== part_requests (cấu trúc file 2 + request_code 40) =====================
DROP TABLE IF EXISTS `part_requests`;
CREATE TABLE `part_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `request_code` varchar(40) NOT NULL,
  `order_id` int(10) unsigned NOT NULL,
  `part_id` int(10) unsigned NOT NULL,
  `requested_by` int(10) unsigned NOT NULL,
  `quantity` int(10) unsigned NOT NULL,
  `status` enum('pending','approved','rejected','fulfilled') NOT NULL DEFAULT 'pending',
  `note` varchar(500) DEFAULT NULL,
  `decided_by` int(10) unsigned DEFAULT NULL,
  `requested_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_part_requests_code` (`request_code`),
  KEY `idx_part_requests_order` (`order_id`,`requested_at`),
  KEY `idx_part_requests_status` (`status`,`requested_at`),
  KEY `idx_part_requests_part` (`part_id`),
  KEY `idx_part_requests_user` (`requested_by`),
  KEY `idx_part_requests_decider` (`decided_by`),
  CONSTRAINT `fk_part_requests_order` FOREIGN KEY (`order_id`) REFERENCES `repair_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_part_requests_part` FOREIGN KEY (`part_id`) REFERENCES `parts` (`id`),
  CONSTRAINT `fk_part_requests_user` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_part_requests_decider` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== parts =====================
DROP TABLE IF EXISTS `parts`;
CREATE TABLE `parts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `category_id` int(10) unsigned NOT NULL,
  `part_code` varchar(30) NOT NULL,
  `name` varchar(150) NOT NULL,
  `unit` varchar(20) NOT NULL DEFAULT 'cái',
  `import_price` decimal(12,0) NOT NULL DEFAULT 0,
  `selling_price` decimal(12,0) NOT NULL DEFAULT 0,
  `stock_quantity` int(11) NOT NULL DEFAULT 0,
  `min_stock_alert` int(11) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = hoạt động, 0 = xóa mềm',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_parts_code` (`part_code`),
  KEY `idx_parts_category` (`category_id`),
  CONSTRAINT `fk_parts_category` FOREIGN KEY (`category_id`) REFERENCES `part_categories` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `parts` VALUES
(1,1,'DN-5W30-4L','Fully Synthetic Engine Oil 5W-30 (4L)','canister',650000,850000,20,5,1),
(2,2,'LD-TOY-01','Toyota Engine Oil Filter','pcs',70000,120000,30,8,1),
(3,3,'BG-NGK-01','NGK Iridium Spark Plug','pcs',90000,150000,40,10,1),
(4,4,'MP-TRUOC-01','Front Brake Pads (Set)','set',450000,700000,12,4,1),
(5,6,'MS-OBC-7K','Onboard Charger Module 7kW','pcs',12000000,15500000,2,1,1),
(6,7,'DC-CA-01','High Voltage Cable 1m','pcs',1500000,2300000,11,2,1);

-- ===================== portal_users =====================
DROP TABLE IF EXISTS `portal_users`;
CREATE TABLE `portal_users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
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
  `password_changed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_portal_users_email` (`email`)
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `portal_users` VALUES
(1,'Demo Customer','demo@redline.test','(555) 019-8871','$2y$10$UBF.WjEAO5eiU0rcN2Mh2OXzGDDNcXOQ27gHp2XLdgH68ieIQcdwy','customer',1,NULL,'2026-10-24 14:24:14','phone',1,1,0,NULL,NULL),
(2,'Johny Sins','cacdai18cm@gmail.com','12356564','$2y$10$rvRoxJpPPxn1U.PopAQEtuK0Ndt2Hj65x0eLEqZi5w2fxXt2oma0y','admin',1,'2026-10-24 21:33:50','2026-10-24 21:25:51','phone',1,1,0,NULL,NULL),
(3,'Johny Sins','jonnydarkbao@gmail.com','+8469330561','$2y$10$BVd9DnWF1cq3DBuNARkw7ODJLs6esHqbqmZCU65g18w01ztLOpYHW','customer',1,'2026-11-09 21:22:44','2026-11-09 17:57:13','phone',1,1,0,NULL,NULL);

-- ===================== portal_vehicles =====================
DROP TABLE IF EXISTS `portal_vehicles`;
CREATE TABLE `portal_vehicles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `make` varchar(60) NOT NULL,
  `model` varchar(80) NOT NULL,
  `year` smallint(5) unsigned DEFAULT NULL,
  `plate` varchar(20) DEFAULT NULL,
  `vin` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `mileage` int(10) unsigned DEFAULT NULL,
  `color` varchar(40) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `workshop_vehicle_id` int(10) unsigned DEFAULT NULL,
  `engine_type` enum('gasoline','diesel','hybrid','ev') NOT NULL DEFAULT 'gasoline',
  `battery_capacity` decimal(6,2) DEFAULT NULL,
  `battery_soh` decimal(5,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_portal_vehicles_workshop_vehicle` (`workshop_vehicle_id`),
  KEY `idx_vehicle_user` (`user_id`),
  CONSTRAINT `fk_portal_vehicle_user` FOREIGN KEY (`user_id`) REFERENCES `portal_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `portal_vehicles` VALUES
(1,1,'BMW','M3 Competition',2022,'RDLN-340',NULL,'2026-10-24 14:24:14',NULL,NULL,NULL,NULL,NULL,'gasoline',NULL,NULL);

-- ===================== quotes / quote_items =====================
DROP TABLE IF EXISTS `quote_items`;
DROP TABLE IF EXISTS `quotes`;
CREATE TABLE `quotes` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int(10) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `total_amount` decimal(12,0) NOT NULL DEFAULT 0,
  `status` enum('draft','sent','approved','rejected') NOT NULL DEFAULT 'draft',
  PRIMARY KEY (`id`),
  KEY `idx_quotes_order` (`order_id`),
  CONSTRAINT `fk_quotes_order` FOREIGN KEY (`order_id`) REFERENCES `repair_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `quote_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `quote_id` int(10) unsigned NOT NULL,
  `item_type` enum('service','part') NOT NULL,
  `description` varchar(255) NOT NULL,
  `quantity` int(10) unsigned NOT NULL DEFAULT 1,
  `unit_price` decimal(12,0) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_quote_items_quote` (`quote_id`),
  CONSTRAINT `fk_quote_items_quote` FOREIGN KEY (`quote_id`) REFERENCES `quotes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== reception_images =====================
DROP TABLE IF EXISTS `reception_images`;
CREATE TABLE `reception_images` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int(10) unsigned NOT NULL,
  `image_path` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_reception_images_order` (`order_id`),
  CONSTRAINT `fk_reception_images_order` FOREIGN KEY (`order_id`) REFERENCES `repair_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `reception_images` VALUES
(1,16,'public/uploads/reception/d1c14a21e7292ea21ee75090528429b6.jpg','2026-11-10 19:32:53'),
(2,17,'public/uploads/reception/559245b238e917c1126cc284085b7719.jpg','2026-11-10 19:33:54');

-- ===================== remember_tokens =====================
DROP TABLE IF EXISTS `remember_tokens`;
CREATE TABLE `remember_tokens` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `selector` char(24) NOT NULL,
  `validator_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_selector` (`selector`),
  KEY `idx_remember_user` (`user_id`),
  CONSTRAINT `fk_remember_user` FOREIGN KEY (`user_id`) REFERENCES `portal_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== repair_order_items =====================
DROP TABLE IF EXISTS `repair_order_items`;
CREATE TABLE `repair_order_items` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `repair_order_id` int(10) unsigned NOT NULL,
  `item_type` enum('labor','part') NOT NULL DEFAULT 'labor',
  `item_name` varchar(255) NOT NULL,
  `unit_price` decimal(15,2) NOT NULL DEFAULT 0.00,
  `quantity` int(11) NOT NULL DEFAULT 1,
  `subtotal` decimal(15,2) NOT NULL DEFAULT 0.00,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `fk_repair_order_items_order` (`repair_order_id`),
  CONSTRAINT `fk_repair_order_items_order` FOREIGN KEY (`repair_order_id`) REFERENCES `repair_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `repair_order_items` VALUES
(1,14,'labor','ád',8000.00,1,8000.00,'2026-11-01 20:33:50'),
(2,15,'labor','ád',8000.00,1,8000.00,'2026-11-04 00:00:40');

-- ===================== repair_orders =====================
DROP TABLE IF EXISTS `repair_orders`;
CREATE TABLE `repair_orders` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `order_code` varchar(20) NOT NULL COMMENT 'Mã phiếu công khai (VD: RO20261020-001)',
  `vehicle_id` int(10) unsigned NOT NULL,
  `customer_id` int(11) DEFAULT NULL,
  `km_in` int(11) DEFAULT 0,
  `symptoms` text DEFAULT NULL,
  `note` text DEFAULT NULL,
  `appointment_id` int(10) unsigned DEFAULT NULL,
  `receptionist_id` int(11) DEFAULT NULL,
  `technician_id` int(11) DEFAULT NULL,
  `assigned_to` int(10) unsigned DEFAULT NULL COMMENT 'Thợ phụ trách',
  `received_at` datetime NOT NULL DEFAULT current_timestamp(),
  `odometer` int(10) unsigned DEFAULT NULL,
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
  `created_at` datetime DEFAULT current_timestamp(),
  `service_request_id` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_orders_order_code` (`order_code`),
  UNIQUE KEY `uq_orders_appointment` (`appointment_id`),
  UNIQUE KEY `uq_orders_service_request` (`service_request_id`),
  KEY `idx_orders_vehicle` (`vehicle_id`),
  KEY `idx_orders_receptionist` (`receptionist_id`),
  KEY `idx_orders_assigned` (`assigned_to`),
  KEY `idx_orders_status` (`status`),
  CONSTRAINT `fk_orders_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_orders_assigned` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_orders_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `repair_orders` VALUES
(2,'RO-20261020-849',1,NULL,35000,'Thay dầu máy và kiểm tra hệ thống phanh','Khách hẹn 17h quay lại lấy',NULL,1,NULL,NULL,'2026-10-20 22:36:36',NULL,'',NULL,NULL,NULL,'repair',NULL,NULL,0,'completed',0.00,0.00,0.00,1,0,'2026-10-20 23:05:36','2026-10-20 22:36:36',NULL),
(3,'RO-20261020-844',1,NULL,35000,'Thay dầu máy và kiểm tra hệ thống phanh','Khách hẹn 17h quay lại lấy',NULL,1,NULL,NULL,'2026-10-20 22:38:40',NULL,'',NULL,NULL,NULL,'repair',NULL,NULL,0,'received',0.00,0.00,0.00,1,0,'2026-10-20 22:38:40','2026-10-20 22:38:40',NULL),
(4,'RO-20261029-713',8,NULL,120000,'',NULL,NULL,1,NULL,NULL,'2026-10-29 19:42:40',120000,'','Xăng/Dầu: 50% | Dịch vụ: repair',NULL,NULL,'repair',NULL,NULL,0,'received',0.00,0.00,0.00,1,0,'2026-10-29 19:42:40','2026-10-29 19:42:40',NULL),
(5,'RO-20261029-506',8,NULL,120000,'',NULL,NULL,1,NULL,NULL,'2026-10-29 19:52:26',120000,'','Nhiên liệu: 50%',NULL,NULL,'repair',NULL,NULL,0,'received',0.00,0.00,0.00,1,0,'2026-10-29 19:52:26','2026-10-29 19:52:26',NULL),
(6,'RO-20261029-599',8,NULL,51231,'',NULL,NULL,1,NULL,NULL,'2026-10-29 20:47:47',51231,'','','50%',NULL,'repair','2046-11-11 17:00:00',NULL,0,'received',0.00,0.00,0.00,1,0,'2026-10-29 20:47:47','2026-10-29 20:47:47',NULL),
(7,'RO-20261030-826',8,NULL,0,'',NULL,NULL,1,NULL,NULL,'2026-10-30 11:18:57',0,'','','50%',NULL,'OIL & FILTER CHANGE',NULL,NULL,0,'received',0.00,0.00,0.00,1,0,'2026-10-30 11:18:57','2026-10-30 11:18:57',NULL),
(8,'RO-C345D1',8,16,0,'',NULL,NULL,1,NULL,NULL,'2026-10-31 22:03:57',NULL,'ENGINE DIAGNOSTIC SCAN',NULL,'1/2','','ENGINE DIAGNOSTIC SCAN',NULL,NULL,0,'received',0.00,0.00,0.00,NULL,0,'2026-10-31 16:03:57','2026-10-31 16:03:57',NULL),
(9,'RO-1BFEB9',8,16,0,'',NULL,NULL,1,NULL,NULL,'2026-10-31 22:04:14',NULL,'TRANSMISSION SERVICE',NULL,'1/2','','TRANSMISSION SERVICE',NULL,NULL,0,'received',0.00,0.00,0.00,NULL,0,'2026-10-31 16:04:14','2026-10-31 16:04:14',NULL),
(10,'RO-BBEFCF',8,16,0,'',NULL,NULL,1,NULL,NULL,'2026-10-31 22:06:43',NULL,'ENGINE DIAGNOSTIC SCAN',NULL,'3/4','','ENGINE DIAGNOSTIC SCAN',NULL,NULL,0,'received',0.00,0.00,0.00,NULL,0,'2026-10-31 16:06:43','2026-10-31 16:06:43',NULL),
(11,'RO-A2BF98',8,16,0,'',NULL,NULL,1,NULL,NULL,'2026-10-31 22:10:41',NULL,'TRANSMISSION SERVICE',NULL,'1/2','','TRANSMISSION SERVICE',NULL,NULL,0,'received',0.00,0.00,0.00,NULL,0,'2026-10-31 16:10:41','2026-10-31 16:10:41',NULL),
(12,'RO-CCD337',8,16,0,'',NULL,NULL,1,NULL,NULL,'2026-10-31 22:14:08',NULL,'BRAKE PAD REPLACEMENT',NULL,'1/2','','BRAKE PAD REPLACEMENT',NULL,NULL,0,'received',0.00,0.00,0.00,NULL,0,'2026-10-31 16:14:08','2026-10-31 16:14:08',NULL),
(13,'RO-9EA173',8,16,0,'',NULL,NULL,1,NULL,NULL,'2026-11-01 19:36:32',NULL,'ENGINE DIAGNOSTIC SCAN',NULL,'1/2','','ENGINE DIAGNOSTIC SCAN',NULL,NULL,0,'received',0.00,0.00,0.00,NULL,0,'2026-11-01 13:36:32','2026-11-01 13:36:32',NULL),
(14,'RO-CB1C60',8,16,0,'',NULL,NULL,1,NULL,NULL,'2026-11-01 19:44:24',NULL,'AIR FILTER REPLACEMENT',NULL,'1/2','','AIR FILTER REPLACEMENT',NULL,NULL,0,'received',8000.00,0.00,8000.00,NULL,0,'2026-11-01 20:33:50','2026-11-01 13:44:24',NULL),
(15,'RO-D008AA',8,16,0,'',NULL,NULL,1,NULL,NULL,'2026-11-03 21:10:17',NULL,'ENGINE DIAGNOSTIC SCAN',NULL,'1/2','','ENGINE DIAGNOSTIC SCAN',NULL,NULL,0,'received',8000.00,0.00,8000.00,NULL,0,'2026-11-04 00:00:40','2026-11-03 15:10:17',NULL),
(16,'RO-C28AD1',8,8,0,'',NULL,NULL,1,NULL,NULL,'2026-11-10 19:32:53',0,'TRANSMISSION SERVICE',NULL,'1/2','','ENGINE DIAGNOSTIC SCAN',NULL,NULL,0,'received',0.00,0.00,0.00,NULL,0,'2026-11-10 13:32:53','2026-11-10 13:32:53',NULL),
(17,'RO-53E9F0',8,8,0,'',NULL,NULL,1,NULL,NULL,'2026-11-10 19:33:54',0,'TRANSMISSION SERVICE',NULL,'1/2','','OIL & FILTER CHANGE',NULL,NULL,0,'received',0.00,0.00,0.00,NULL,0,'2026-11-10 13:33:54','2026-11-10 13:33:54',NULL);

-- ===================== roles =====================
DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL COMMENT 'Admin | Receptionist | Technician | Cashier',
  `description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `roles` VALUES
(1,'Admin','Toàn quyền quản trị, xem báo cáo, quản lý nhân viên và kho'),
(2,'Receptionist','Lễ tân: tiếp nhận xe, lập phiếu, duyệt báo giá, xuất hóa đơn'),
(3,'Technician','Thợ kỹ thuật: cập nhật tiến độ, đề xuất vật tư dịch vụ'),
(4,'Cashier','Thu ngân: lập hóa đơn, thu tiền, in biên nhận và xem báo cáo doanh thu');

-- ===================== service_orders =====================
DROP TABLE IF EXISTS `service_orders`;
CREATE TABLE `service_orders` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `vehicle_id` int(10) unsigned NOT NULL,
  `title` varchar(160) NOT NULL,
  `status` enum('estimate','approved','in_progress','ready','completed','cancelled') NOT NULL DEFAULT 'estimate',
  `estimate` decimal(10,2) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_order_vehicle` (`vehicle_id`),
  KEY `idx_order_status` (`status`),
  CONSTRAINT `fk_order_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `portal_vehicles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `service_orders` VALUES
(1,1,'Stage 2 ECU tune + downpipe install','in_progress',3450.00,'Dyno session scheduled after install.','2026-10-24 14:24:14','2026-10-24 14:24:14');

-- ===================== service_requests =====================
DROP TABLE IF EXISTS `service_requests`;
CREATE TABLE `service_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned DEFAULT NULL,
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
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `email` varchar(190) DEFAULT NULL,
  `zalo` varchar(30) DEFAULT NULL,
  `contact_method` enum('phone','zalo','email') NOT NULL DEFAULT 'phone',
  `make` varchar(60) DEFAULT NULL,
  `model` varchar(80) DEFAULT NULL,
  `year` smallint(5) unsigned DEFAULT NULL,
  `mileage` int(10) unsigned DEFAULT NULL,
  `color` varchar(50) DEFAULT NULL,
  `fuel_level` varchar(20) DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_service_req_user` (`user_id`),
  KEY `idx_service_req_status` (`status`)
) ENGINE=InnoDB AUTO_INCREMENT=14 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `service_requests` VALUES
(1,NULL,'Phạm Khôi','8327497',NULL,'Camry',NULL,NULL,'OIL & FILTER CHANGE',NULL,'::1','pending','2026-10-29 20:49:31',NULL,NULL,'phone',NULL,NULL,NULL,NULL,NULL,NULL,NULL),
(2,NULL,'Phạm Khôi','8340239',NULL,'Camry',NULL,NULL,'ENGINE DIAGNOSTIC SCAN',NULL,'::1','pending','2026-10-30 11:17:35',NULL,NULL,'phone',NULL,NULL,NULL,NULL,NULL,NULL,NULL),
(3,NULL,'Phạm Khôi','8340239',NULL,'Camry',NULL,NULL,'ENGINE DIAGNOSTIC SCAN',NULL,'::1','pending','2026-10-30 11:17:35',NULL,NULL,'phone',NULL,NULL,NULL,NULL,NULL,NULL,NULL),
(4,NULL,'Pham Van Khoi','8327497',NULL,'Camry',NULL,NULL,'ENGINE DIAGNOSTIC SCAN',NULL,'::1','pending','2026-10-31 21:05:25',NULL,NULL,'phone',NULL,NULL,NULL,NULL,NULL,NULL,NULL),
(5,NULL,'Pham Van Khoi','8327497',NULL,'Camry',NULL,NULL,'AIR FILTER REPLACEMENT',NULL,'::1','processed','2026-10-31 21:39:32',NULL,NULL,'phone',NULL,NULL,NULL,NULL,NULL,NULL,NULL),
(6,NULL,'Pham Van Khoi','8327497',NULL,'Camry [65A-04953]',NULL,NULL,'TRANSMISSION SERVICE',NULL,'::1','processed','2026-10-31 21:44:43',NULL,NULL,'phone',NULL,NULL,NULL,NULL,NULL,NULL,NULL),
(7,NULL,'Pham Van Khoi','8327497','65A-04953','Camry','RL2-ư3242','TRANSMISSION SERVICE','TRANSMISSION SERVICE',NULL,'::1','processed','2026-10-31 21:55:54',NULL,NULL,'phone',NULL,NULL,NULL,NULL,NULL,NULL,NULL),
(8,NULL,'Pham Van Khoi','8327497','65A-04953','Camry','RL2-ư3242','ENGINE DIAGNOSTIC SCAN','ENGINE DIAGNOSTIC SCAN',NULL,'::1','processed','2026-10-31 21:59:53',NULL,NULL,'phone',NULL,NULL,NULL,NULL,NULL,NULL,NULL),
(9,NULL,'Pham Van Khoi','8327497',NULL,'Camry [65A-04953] VIN:RL2-ư3242 [Fuel:3/4]',NULL,NULL,'ENGINE DIAGNOSTIC SCAN',NULL,'::1','processed','2026-10-31 22:06:33',NULL,NULL,'phone',NULL,NULL,NULL,NULL,NULL,NULL,NULL),
(10,NULL,'Pham Van Khoi','8327497',NULL,'Camry [65A-04953] VIN:RL2-ư3242 [Fuel:1/2]',NULL,NULL,'BRAKE PAD REPLACEMENT',NULL,'::1','processed','2026-10-31 22:14:00',NULL,NULL,'phone',NULL,NULL,NULL,NULL,NULL,NULL,NULL),
(11,NULL,'Pham Van Khoi','8327497',NULL,'Camry [65A-04953] VIN:RL2-ư3242 [Fuel:1/2]',NULL,NULL,'ENGINE DIAGNOSTIC SCAN',NULL,'::1','processed','2026-10-31 22:19:39',NULL,NULL,'phone',NULL,NULL,NULL,NULL,NULL,NULL,NULL),
(12,NULL,'Pham Van Khoi','8327497',NULL,'Camry [65A-04953] VIN:RL2-ư3242 [Fuel:1/2]',NULL,NULL,'ENGINE DIAGNOSTIC SCAN',NULL,'::1','processed','2026-11-03 21:09:28',NULL,NULL,'phone',NULL,NULL,NULL,NULL,NULL,NULL,NULL),
(13,NULL,'Pham Van Khoi','0372975331',NULL,'Camry [65A-04953] VIN:RL2-2342 [Fuel:3/4]',NULL,NULL,'ENGINE DIAGNOSTIC SCAN',NULL,'::1','pending','2026-11-03 23:38:56',NULL,NULL,'phone',NULL,NULL,NULL,NULL,NULL,NULL,NULL);

-- ===================== services =====================
DROP TABLE IF EXISTS `services`;
CREATE TABLE `services` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `target_engine` enum('gasoline','ev','common') NOT NULL DEFAULT 'common',
  `price` decimal(12,0) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = đang áp dụng, 0 = ngừng áp dụng',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_services_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `services` VALUES
(1,'Chẩn đoán lỗi bằng máy','common',200000,1),
(2,'Bảo dưỡng định kỳ','common',500000,1),
(3,'Thay dầu nhớt động cơ','gasoline',150000,1),
(4,'Thay bugi','gasoline',200000,1),
(5,'Thay má phanh','common',300000,1),
(6,'Kiểm tra pin cao áp','ev',400000,1),
(7,'Kiểm tra hệ thống sạc','ev',300000,1),
(8,'Thay module sạc','ev',800000,1);

-- ===================== settings =====================
DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `settings` VALUES
('bank_account_name','REDLINE','2026-11-09 23:17:14'),
('bank_account_no','0901234567','2026-11-01 19:58:42'),
('bank_id','VCB','2026-11-09 23:17:14'),
('garage_address','123 Đường 3/2, Q. Ninh Kiều, TP. Cần Thơ','2026-11-01 19:58:42'),
('garage_hotline','0901.234.567','2026-11-01 19:58:42'),
('garage_name','REDLINE','2026-11-09 23:17:14'),
('garage_terms','- Phụ tùng chính hãng được bảo hành 06 tháng hoặc 10.000 km.\r\n- Quý khách vui lòng kiểm tra kỹ phương tiện và tài sản cá nhân trước khi rời xưởng.','2026-11-01 19:58:42'),
('garage_website','pharma.id.vn','2026-11-09 23:17:14'),
('min_stock_default','5','2026-11-01 19:58:42');

-- ===================== technical_inspections (file 1 + FK file 2) =====================
DROP TABLE IF EXISTS `technical_inspections`;
CREATE TABLE `technical_inspections` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int(10) unsigned NOT NULL,
  `updated_by` int(10) unsigned DEFAULT NULL,
  `technical_note` text DEFAULT NULL,
  `customer_complaints` text DEFAULT NULL,
  `inspection_needed` text DEFAULT NULL,
  `repairs_performed` text DEFAULT NULL,
  `engine_result` text DEFAULT NULL,
  `brakes_result` text DEFAULT NULL,
  `electrical_result` text DEFAULT NULL,
  `ac_result` text DEFAULT NULL,
  `hybrid_battery_status` varchar(255) DEFAULT NULL,
  `hybrid_voltage` decimal(8,2) DEFAULT NULL,
  `hybrid_temperature` decimal(5,2) DEFAULT NULL,
  `hybrid_cooling_result` text DEFAULT NULL,
  `inverter_result` text DEFAULT NULL,
  `electric_motor_result` text DEFAULT NULL,
  `dtc_codes` text DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inspection_order` (`order_id`),
  KEY `idx_technical_inspections_updated_by` (`updated_by`),
  CONSTRAINT `fk_technical_inspections_order` FOREIGN KEY (`order_id`) REFERENCES `repair_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_technical_inspections_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== users =====================
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `role_id` int(10) unsigned NOT NULL,
  `username` varchar(50) NOT NULL,
  `password` varchar(255) NOT NULL COMMENT 'Mật khẩu đã mã hóa password_hash()',
  `full_name` varchar(100) NOT NULL,
  `email` varchar(100) DEFAULT NULL,
  `phone` varchar(15) DEFAULT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = hoạt động, 0 = bị khóa/xóa mềm',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_role` (`role_id`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `users` VALUES
(1,1,'admin','$2y$10$dRchgEo43b8D/1TuvuTcp.ypG8hEY90w93f4i0ZIBAMDaqEj58TDm','Quản Trị Viên','admin@gara.com','0901111111',1,'2026-10-20 21:53:52','2026-10-20 21:53:52'),
(2,2,'letan01','$2y$10$FAC2T0FvAV6IDLdt2..bmeOp9R47WulX2cjhHBTp910RS6soZEiDq','Lễ Tân Nguyễn Văn A','letan@gara.com','0902222222',1,'2026-10-20 21:53:52','2026-10-20 21:53:52'),
(3,3,'tho01','$2y$10$FAC2T0FvAV6IDLdt2..bmeOp9R47WulX2cjhHBTp910RS6soZEiDq','Thợ Kỹ Thuật Trần Văn B','tho01@gara.com','0903333333',1,'2026-10-20 21:53:52','2026-10-20 21:53:52'),
(4,2,'johnysins','$2y$10$TF/2bMcccVPJ0TdEvQCqeuSMMVo5Aag5XnLfa11i4FPdKmdmvmkCO','Johny Sins','jonnydarkbao@gmail.com','34234',1,'2026-11-10 19:53:38','2026-11-10 19:53:59'),
(6,3,'khoip8500','$2y$10$Ow8Bg4IhJW.3F5qs8dP0o.c8HXhLVbYhosClFq.0WltoE/2OEVQ.q','khoisdznhattg','khoip8500@gmail.com','1232562',1,'2026-11-10 19:56:34','2026-11-10 19:56:34');

-- ===================== vehicle_ev_details =====================
DROP TABLE IF EXISTS `vehicle_ev_details`;
CREATE TABLE `vehicle_ev_details` (
  `vehicle_id` int(10) unsigned NOT NULL,
  `battery_capacity` decimal(6,2) DEFAULT NULL COMMENT 'Dung lượng pin (kWh)',
  `battery_soh` decimal(5,2) DEFAULT NULL COMMENT 'Tình trạng sức khỏe pin (%)',
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`vehicle_id`),
  CONSTRAINT `fk_ev_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ===================== vehicles =====================
DROP TABLE IF EXISTS `vehicles`;
CREATE TABLE `vehicles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` int(10) unsigned NOT NULL,
  `license_plate` varchar(20) NOT NULL,
  `brand` varchar(60) NOT NULL,
  `model` varchar(100) DEFAULT NULL,
  `vin` varchar(50) DEFAULT NULL,
  `year` smallint(5) unsigned DEFAULT NULL COMMENT 'Năm sản xuất',
  `color` varchar(50) DEFAULT NULL,
  `vin_number` varchar(100) DEFAULT NULL,
  `engine_number` varchar(100) DEFAULT NULL,
  `is_ev` tinyint(1) DEFAULT 0,
  `engine_type` enum('gasoline','diesel','hybrid','ev') NOT NULL DEFAULT 'gasoline',
  `odometer` int(10) unsigned NOT NULL DEFAULT 0 COMMENT 'Số km hiện tại',
  `is_active` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = hoạt động, 0 = xóa mềm',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vehicles_license_plate` (`license_plate`),
  KEY `idx_vehicles_customer` (`customer_id`),
  CONSTRAINT `fk_vehicles_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `vehicles` VALUES
(1,1,'65A-123.45','','Camry',NULL,NULL,'Trắng','','',0,'gasoline',0,1,'2026-10-20 22:15:14','2026-10-20 22:15:14'),
(8,8,'65A-04953','Toyota','Camry','RL2-2342',NULL,NULL,'RL2-Ư3242',NULL,0,'gasoline',0,1,'2026-10-29 19:42:40','2026-11-10 13:33:54');

-- ===================== work_order_images (file 1 + FK file 2) =====================
DROP TABLE IF EXISTS `work_order_images`;
CREATE TABLE `work_order_images` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int(10) unsigned NOT NULL,
  `uploaded_by` int(10) unsigned NOT NULL,
  `image_type` enum('before','after') NOT NULL,
  `image_path` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_work_order_images_order_type` (`order_id`,`image_type`),
  KEY `idx_work_order_images_user` (`uploaded_by`),
  CONSTRAINT `fk_work_order_images_order` FOREIGN KEY (`order_id`) REFERENCES `repair_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_work_order_images_user` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/*!40014 SET FOREIGN_KEY_CHECKS=1 */;