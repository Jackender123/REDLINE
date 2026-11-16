-- REDLINE schema for new installations. Import once into an empty database.
-- Existing installations use migrations/ in the order documented in README.md.

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET NAMES utf8 */;
/*!50503 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;

CREATE DATABASE IF NOT EXISTS `redlinev1` /*!40100 DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci */ /*!80016 DEFAULT ENCRYPTION='N' */;
USE `redlinev1`;

CREATE TABLE IF NOT EXISTS `appointments` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` int unsigned NOT NULL,
  `vehicle_id` int unsigned NOT NULL,
  `appointment_date` date NOT NULL,
  `time_slot` varchar(20) NOT NULL COMMENT 'Khung giờ (VD: 08:00-09:00)',
  `note` varchar(255) DEFAULT NULL,
  `status` enum('pending','confirmed','arrived','canceled') NOT NULL DEFAULT 'pending',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_appointments_date_slot` (`appointment_date`,`time_slot`),
  KEY `idx_appointments_customer` (`customer_id`),
  KEY `idx_appointments_vehicle` (`vehicle_id`),
  CONSTRAINT `fk_appointments_customer` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_appointments_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `cash_transactions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `transaction_code` varchar(40) NOT NULL,
  `invoice_id` int NOT NULL,
  `transaction_type` enum('payment','refund') NOT NULL DEFAULT 'payment',
  `amount` decimal(15,2) NOT NULL,
  `payment_method` enum('cash','transfer','qr','card') NOT NULL,
  `status` enum('completed','voided') NOT NULL DEFAULT 'completed',
  `note` varchar(255) DEFAULT NULL,
  `created_by` int unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cash_transactions_code` (`transaction_code`),
  KEY `idx_cash_transactions_invoice_date` (`invoice_id`,`created_at`),
  KEY `idx_cash_transactions_date_method` (`created_at`,`payment_method`,`status`),
  KEY `idx_cash_transactions_user` (`created_by`),
  CONSTRAINT `fk_cash_transactions_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_cash_transactions_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `catalog_brands` (
  `id` int NOT NULL,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `country` varchar(255) DEFAULT NULL,
  `logo_url` varchar(255) DEFAULT NULL,
  `description` text,
  `is_featured` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `name` (`name`),
  UNIQUE KEY `slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `catalog_categories` (
  `id` int NOT NULL,
  `parent_id` int DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `icon` varchar(255) DEFAULT NULL,
  `level` int NOT NULL DEFAULT '1',
  `sort_order` int NOT NULL DEFAULT '0',
  `image_url` varchar(255) DEFAULT NULL,
  `description` text,
  PRIMARY KEY (`id`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_categories_parent` (`parent_id`),
  CONSTRAINT `catalog_categories_ibfk_1` FOREIGN KEY (`parent_id`) REFERENCES `catalog_categories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `catalog_products` (
  `id` int NOT NULL,
  `sku` varchar(255) NOT NULL,
  `part_number` varchar(255) DEFAULT NULL,
  `name` varchar(255) NOT NULL,
  `slug` varchar(255) NOT NULL,
  `category_id` int NOT NULL,
  `brand_id` int NOT NULL,
  `price` int NOT NULL,
  `sale_price` int DEFAULT NULL,
  `currency` varchar(255) NOT NULL DEFAULT 'VND',
  `stock` int NOT NULL DEFAULT '0',
  `rating` decimal(10,2) DEFAULT NULL,
  `review_count` int NOT NULL DEFAULT '0',
  `sold_count` int NOT NULL DEFAULT '0',
  `warranty_months` int NOT NULL DEFAULT '0',
  `origin` varchar(255) DEFAULT NULL,
  `condition` varchar(255) DEFAULT NULL,
  `is_universal` tinyint(1) NOT NULL DEFAULT '0',
  `is_featured` tinyint(1) NOT NULL DEFAULT '0',
  `weight_kg` decimal(10,2) DEFAULT NULL,
  `description` text,
  `specs` json DEFAULT NULL,
  `images` json DEFAULT NULL,
  `created_at` date DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sku` (`sku`),
  UNIQUE KEY `slug` (`slug`),
  KEY `idx_products_category` (`category_id`),
  KEY `idx_products_brand` (`brand_id`),
  KEY `idx_products_price` (`price`),
  CONSTRAINT `catalog_products_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `catalog_categories` (`id`),
  CONSTRAINT `catalog_products_ibfk_2` FOREIGN KEY (`brand_id`) REFERENCES `catalog_brands` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `inventory_transactions` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `part_id` int unsigned NOT NULL,
  `user_id` int unsigned NOT NULL COMMENT 'Người thực hiện nhập/xuất',
  `type` enum('import','export') NOT NULL,
  `quantity` int unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `note` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_inventory_part_time` (`part_id`,`created_at`),
  KEY `idx_inventory_user` (`user_id`),
  CONSTRAINT `fk_inventory_part` FOREIGN KEY (`part_id`) REFERENCES `parts` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_inventory_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `invoices` (
  `id` int NOT NULL AUTO_INCREMENT,
  `invoice_code` varchar(50) NOT NULL,
  `order_id` int NOT NULL,
  `total_services` decimal(12,2) NOT NULL DEFAULT '0.00',
  `total_parts` decimal(12,2) NOT NULL DEFAULT '0.00',
  `discount` decimal(12,2) NOT NULL DEFAULT '0.00',
  `grand_total` decimal(12,2) NOT NULL DEFAULT '0.00',
  `payment_status` enum('unpaid','partial','paid','refunded','cancelled') DEFAULT 'unpaid',
  `payment_method` enum('cash','transfer','qr','card') DEFAULT 'cash',
  `created_by` int DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `invoice_code` (`invoice_code`),
  KEY `idx_invoices_order_id` (`order_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `invoice_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` int NOT NULL,
  `item_type` enum('service','part') NOT NULL DEFAULT 'service',
  `description` varchar(255) NOT NULL,
  `quantity` int unsigned NOT NULL DEFAULT '1',
  `unit_price` decimal(15,2) NOT NULL DEFAULT '0.00',
  `subtotal` decimal(15,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`id`),
  KEY `idx_invoice_items_invoice` (`invoice_id`),
  CONSTRAINT `fk_invoice_items_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(190) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `attempted_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_attempt_email` (`email`,`attempted_at`),
  KEY `idx_attempt_ip` (`ip_address`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `notifications` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL COMMENT 'Người nhận thông báo',
  `order_id` int unsigned DEFAULT NULL,
  `content` varchar(255) NOT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_notifications_user` (`user_id`,`is_read`),
  KEY `idx_notifications_order` (`order_id`),
  CONSTRAINT `fk_notifications_order` FOREIGN KEY (`order_id`) REFERENCES `repair_orders` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `order_parts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int unsigned NOT NULL,
  `part_id` int unsigned NOT NULL,
  `quantity` int unsigned NOT NULL DEFAULT '1',
  `price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `unit_price` decimal(12,0) NOT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_parts_order` (`order_id`),
  KEY `idx_order_parts_part` (`part_id`),
  CONSTRAINT `fk_order_parts_order` FOREIGN KEY (`order_id`) REFERENCES `repair_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_order_parts_part` FOREIGN KEY (`part_id`) REFERENCES `parts` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `order_services` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int unsigned NOT NULL,
  `service_id` int DEFAULT NULL,
  `quantity` smallint unsigned NOT NULL DEFAULT '1',
  `price` decimal(12,2) NOT NULL DEFAULT '0.00',
  `unit_price` decimal(12,0) NOT NULL,
  `note` varchar(255) DEFAULT NULL,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_services_order` (`order_id`),
  KEY `idx_order_services_service` (`service_id`),
  CONSTRAINT `fk_order_services_order` FOREIGN KEY (`order_id`) REFERENCES `repair_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `order_status_history` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int unsigned NOT NULL,
  `created_by` int unsigned NOT NULL COMMENT 'user_id người thực hiện',
  `from_status` varchar(30) DEFAULT NULL,
  `to_status` varchar(30) NOT NULL,
  `note` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_history_order_time` (`order_id`,`created_at`),
  KEY `idx_history_user` (`created_by`),
  CONSTRAINT `fk_history_order` FOREIGN KEY (`order_id`) REFERENCES `repair_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_history_user` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `parts` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `category_id` int unsigned NOT NULL,
  `part_code` varchar(30) NOT NULL,
  `name` varchar(150) NOT NULL,
  `unit` varchar(20) NOT NULL DEFAULT 'cái',
  `import_price` decimal(12,0) NOT NULL DEFAULT '0',
  `selling_price` decimal(12,0) NOT NULL DEFAULT '0',
  `stock_quantity` int NOT NULL DEFAULT '0',
  `min_stock_alert` int NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1 = hoạt động, 0 = xóa mềm',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_parts_code` (`part_code`),
  KEY `idx_parts_category` (`category_id`),
  CONSTRAINT `fk_parts_category` FOREIGN KEY (`category_id`) REFERENCES `part_categories` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `part_categories` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `target_engine` enum('gasoline','ev','common') NOT NULL DEFAULT 'common',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_part_categories_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `part_requests` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `request_code` varchar(40) NOT NULL,
  `order_id` int unsigned NOT NULL,
  `part_id` int unsigned NOT NULL,
  `requested_by` int unsigned NOT NULL,
  `quantity` int unsigned NOT NULL,
  `status` enum('pending','approved','rejected','fulfilled') NOT NULL DEFAULT 'pending',
  `note` varchar(500) DEFAULT NULL,
  `decided_by` int unsigned DEFAULT NULL,
  `requested_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_part_requests_code` (`request_code`),
  KEY `idx_part_requests_order` (`order_id`,`requested_at`),
  KEY `idx_part_requests_status` (`status`,`requested_at`),
  KEY `idx_part_requests_part` (`part_id`),
  KEY `idx_part_requests_user` (`requested_by`),
  KEY `idx_part_requests_decider` (`decided_by`),
  CONSTRAINT `fk_part_requests_decider` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_part_requests_order` FOREIGN KEY (`order_id`) REFERENCES `repair_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_part_requests_part` FOREIGN KEY (`part_id`) REFERENCES `parts` (`id`),
  CONSTRAINT `fk_part_requests_user` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `portal_vehicles` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `make` varchar(60) NOT NULL,
  `model` varchar(80) NOT NULL,
  `year` smallint unsigned DEFAULT NULL,
  `plate` varchar(20) DEFAULT NULL,
  `vin` varchar(50) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `mileage` int unsigned DEFAULT NULL,
  `color` varchar(40) DEFAULT NULL,
  `photo` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT NULL,
  `workshop_vehicle_id` int unsigned DEFAULT NULL,
  `engine_type` enum('gasoline','diesel','hybrid','ev') NOT NULL DEFAULT 'gasoline',
  `battery_capacity` decimal(6,2) DEFAULT NULL,
  `battery_soh` decimal(5,2) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_portal_vehicles_workshop_vehicle` (`workshop_vehicle_id`),
  KEY `idx_vehicle_user` (`user_id`),
  CONSTRAINT `fk_portal_vehicle_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `quotes` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `total_amount` decimal(12,0) NOT NULL DEFAULT '0',
  `status` enum('draft','sent','approved','rejected') NOT NULL DEFAULT 'draft',
  PRIMARY KEY (`id`),
  KEY `idx_quotes_order` (`order_id`),
  CONSTRAINT `fk_quotes_order` FOREIGN KEY (`order_id`) REFERENCES `repair_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `quote_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `quote_id` int unsigned NOT NULL,
  `item_type` enum('service','part') NOT NULL,
  `description` varchar(255) NOT NULL,
  `quantity` int unsigned NOT NULL DEFAULT '1',
  `unit_price` decimal(12,0) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_quote_items_quote` (`quote_id`),
  CONSTRAINT `fk_quote_items_quote` FOREIGN KEY (`quote_id`) REFERENCES `quotes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `reception_images` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int unsigned NOT NULL,
  `image_path` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_reception_images_order` (`order_id`),
  CONSTRAINT `fk_reception_images_order` FOREIGN KEY (`order_id`) REFERENCES `repair_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `remember_tokens` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned NOT NULL,
  `selector` char(24) NOT NULL,
  `validator_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_selector` (`selector`),
  KEY `idx_remember_user` (`user_id`),
  CONSTRAINT `fk_remember_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS repair_order_cost_reviews (
  order_id INT UNSIGNED NOT NULL PRIMARY KEY,
  reviewed_by INT UNSIGNED NOT NULL,
  review_type ENUM('standard','zero') NOT NULL,
  reason VARCHAR(500) NOT NULL DEFAULT '',
  items_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  reviewed_total DECIMAL(15,2) NOT NULL,
  reviewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_cost_reviews_reviewer (reviewed_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `repair_orders` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_code` varchar(20) NOT NULL COMMENT 'Mã phiếu công khai (VD: RO20261020-001)',
  `vehicle_id` int unsigned NOT NULL,
  `customer_id` int unsigned DEFAULT NULL,
  `km_in` int DEFAULT '0',
  `symptoms` text,
  `note` text,
  `appointment_id` int unsigned DEFAULT NULL,
  `receptionist_id` int DEFAULT NULL,
  `technician_id` int DEFAULT NULL,
  `assigned_to` int unsigned DEFAULT NULL COMMENT 'Thợ phụ trách',
  `received_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `odometer` int unsigned DEFAULT NULL,
  `customer_request` text NOT NULL COMMENT 'Yêu cầu sửa chữa của khách',
  `initial_condition` text,
  `fuel_level` varchar(20) DEFAULT NULL,
  `intake_notes` text,
  `service_type` varchar(50) DEFAULT 'repair',
  `expected_delivery` datetime DEFAULT NULL,
  `ev_notes` text COMMENT 'Ghi chú pin/sạc cho xe điện',
  `estimated_cost` decimal(12,0) NOT NULL DEFAULT '0',
<<<<<<< Updated upstream
  `status` enum('received','diagnosing','repairing','waiting_parts','inspecting','completed','delivered','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'received',
=======
  `status` enum('received','diagnosing','repairing','waiting_parts','inspecting','completed','delivered','cancelled') NOT NULL DEFAULT 'received',
>>>>>>> Stashed changes
  `total_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `discount_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `final_amount` decimal(15,2) NOT NULL DEFAULT '0.00',
  `created_by` int DEFAULT NULL,
  `is_stock_deducted` tinyint(1) NOT NULL DEFAULT '0' COMMENT '1 = đã trừ kho vật tư khi duyệt báo giá',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `service_request_id` int unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_orders_order_code` (`order_code`),
  UNIQUE KEY `uq_orders_appointment` (`appointment_id`),
  UNIQUE KEY `uq_orders_service_request` (`service_request_id`),
  KEY `idx_orders_vehicle` (`vehicle_id`),
  KEY `idx_orders_receptionist` (`receptionist_id`),
  KEY `idx_orders_assigned` (`assigned_to`),
  KEY `idx_orders_status` (`status`),
  KEY `fk_orders_customer` (`customer_id`),
  CONSTRAINT `fk_orders_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_orders_assigned` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_orders_customer` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_orders_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `repair_order_items` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `repair_order_id` int unsigned NOT NULL,
<<<<<<< Updated upstream
  `part_request_id` bigint unsigned DEFAULT NULL COMMENT 'Unique fulfilled part request',
  `item_type` enum('labor','part') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'labor',
  `item_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
=======
  `item_type` enum('labor','part') NOT NULL DEFAULT 'labor',
  `item_name` varchar(255) NOT NULL,
>>>>>>> Stashed changes
  `unit_price` decimal(15,2) NOT NULL DEFAULT '0.00',
  `quantity` int NOT NULL DEFAULT '1',
  `subtotal` decimal(15,2) NOT NULL DEFAULT '0.00',
  `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
  `part_request_id` bigint unsigned DEFAULT NULL COMMENT 'Unique fulfilled part_requests.id; NULL for historical/manual entries',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_repair_item_part_request` (`part_request_id`),
  KEY `fk_repair_order_items_order` (`repair_order_id`),
  UNIQUE KEY `uq_repair_item_part_request` (`part_request_id`),
  CONSTRAINT `fk_repair_order_items_order` FOREIGN KEY (`repair_order_id`) REFERENCES `repair_orders` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `roles` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL COMMENT 'Admin | Receptionist | Technician | Customers | Cashier',
  `description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Required application roles for a new installation.
INSERT INTO `roles` (`id`, `name`) VALUES
  (1, 'Admin'),
  (2, 'Receptionist'),
  (3, 'Technician'),
  (4, 'Customers'),
  (5, 'Cashier');

CREATE TABLE IF NOT EXISTS `services` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(150) NOT NULL,
  `target_engine` enum('gasoline','ev','common') NOT NULL DEFAULT 'common',
  `price` decimal(12,0) NOT NULL DEFAULT '0',
  `is_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1 = đang áp dụng, 0 = ngừng áp dụng',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_services_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `service_orders` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `vehicle_id` int unsigned NOT NULL,
  `title` varchar(160) NOT NULL,
  `status` enum('estimate','approved','in_progress','ready','completed','cancelled') NOT NULL DEFAULT 'estimate',
  `estimate` decimal(10,2) DEFAULT NULL,
  `notes` text,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_order_vehicle` (`vehicle_id`),
  KEY `idx_order_status` (`status`),
  CONSTRAINT `fk_order_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `portal_vehicles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `service_requests` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int unsigned DEFAULT NULL,
  `preferred_technician_id` int unsigned DEFAULT NULL,
<<<<<<< Updated upstream
  `full_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `phone` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `license_plate` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `vehicle` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `vin_number` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `service_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `service` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `message` text COLLATE utf8mb4_unicode_ci,
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` enum('pending','processed','cancelled') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
=======
  `full_name` varchar(120) NOT NULL,
  `phone` varchar(30) NOT NULL,
  `license_plate` varchar(20) DEFAULT NULL,
  `vehicle` varchar(160) NOT NULL,
  `vin_number` varchar(50) DEFAULT NULL,
  `service_type` varchar(50) DEFAULT NULL,
  `service` varchar(100) NOT NULL,
  `message` text,
  `ip_address` varchar(45) DEFAULT NULL,
  `status` enum('pending','processed','cancelled') NOT NULL DEFAULT 'pending',
>>>>>>> Stashed changes
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `email` varchar(190) DEFAULT NULL,
  `zalo` varchar(30) DEFAULT NULL,
  `contact_method` enum('phone','zalo','email') NOT NULL DEFAULT 'phone',
  `make` varchar(60) DEFAULT NULL,
  `model` varchar(80) DEFAULT NULL,
  `year` smallint unsigned DEFAULT NULL,
  `mileage` int unsigned DEFAULT NULL,
  `color` varchar(50) DEFAULT NULL,
  `fuel_level` varchar(20) DEFAULT NULL,
  `photo_path` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_service_req_user` (`user_id`),
  KEY `idx_service_requests_preferred_technician` (`preferred_technician_id`),
  CONSTRAINT `fk_service_requests_preferred_technician` FOREIGN KEY (`preferred_technician_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  KEY `idx_service_req_status` (`status`),
  KEY `idx_service_requests_preferred_technician` (`preferred_technician_id`),
  CONSTRAINT `fk_service_requests_preferred_technician` FOREIGN KEY (`preferred_technician_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_service_requests_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `settings` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text,
  `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `technical_inspections` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int unsigned NOT NULL,
  `updated_by` int unsigned DEFAULT NULL,
  `technical_note` text,
  `customer_complaints` text,
  `inspection_needed` text,
  `repairs_performed` text,
  `engine_result` text,
  `brakes_result` text,
  `electrical_result` text,
  `ac_result` text,
  `hybrid_battery_status` varchar(255) DEFAULT NULL,
  `hybrid_voltage` decimal(8,2) DEFAULT NULL,
  `hybrid_temperature` decimal(5,2) DEFAULT NULL,
  `hybrid_cooling_result` text,
  `inverter_result` text,
  `electric_motor_result` text,
  `dtc_codes` text,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_inspection_order` (`order_id`),
  KEY `idx_technical_inspections_updated_by` (`updated_by`),
  CONSTRAINT `fk_technical_inspections_order` FOREIGN KEY (`order_id`) REFERENCES `repair_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_technical_inspections_user` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS technician_pay_rates (
  technician_id INT UNSIGNED PRIMARY KEY,
  day_rate DECIMAL(13,2) NOT NULL DEFAULT 0,
  completed_order_bonus DECIMAL(13,2) NOT NULL DEFAULT 0,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_tech_pay_user FOREIGN KEY (technician_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_tech_pay_admin FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS technician_shifts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  technician_id INT UNSIGNED NOT NULL,
  work_date DATE NOT NULL,
  shift_type ENUM('morning','afternoon','full') NOT NULL,
  note VARCHAR(500) NULL,
  status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  attendance ENUM('unconfirmed','present','absent') NOT NULL DEFAULT 'unconfirmed',
  reviewed_by INT UNSIGNED NULL,
  reviewed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_technician_date (technician_id, work_date),
  KEY idx_shift_status (status, work_date),
  CONSTRAINT fk_tech_shift_user FOREIGN KEY (technician_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_tech_shift_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `role_id` int unsigned DEFAULT NULL,
  `username` varchar(50) DEFAULT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `full_name` varchar(150) NOT NULL,
  `email` varchar(190) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `notes` text,
  `is_customer` tinyint(1) NOT NULL DEFAULT '1',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  `zalo` varchar(30) DEFAULT NULL,
  `contact_method` enum('phone','zalo','email') NOT NULL DEFAULT 'phone',
  `notify_repair` tinyint(1) NOT NULL DEFAULT '1',
  `notify_reminder` tinyint(1) NOT NULL DEFAULT '1',
  `notify_offers` tinyint(1) NOT NULL DEFAULT '0',
  `password_changed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_phone` (`phone`),
  KEY `idx_users_customer_name` (`is_customer`,`full_name`),
  KEY `idx_users_role` (`role_id`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vehicles` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` int unsigned NOT NULL,
  `license_plate` varchar(20) NOT NULL,
  `brand` varchar(60) NOT NULL,
  `model` varchar(100) DEFAULT NULL,
  `vin` varchar(50) DEFAULT NULL,
  `year` smallint unsigned DEFAULT NULL COMMENT 'Năm sản xuất',
  `color` varchar(50) DEFAULT NULL,
  `vin_number` varchar(100) DEFAULT NULL,
  `engine_number` varchar(100) DEFAULT NULL,
  `is_ev` tinyint(1) DEFAULT '0',
  `engine_type` enum('gasoline','diesel','hybrid','ev') NOT NULL DEFAULT 'gasoline',
  `odometer` int unsigned NOT NULL DEFAULT '0' COMMENT 'Số km hiện tại',
  `is_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT '1 = hoạt động, 0 = xóa mềm',
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_vehicles_license_plate` (`license_plate`),
  KEY `idx_vehicles_customer` (`customer_id`),
  CONSTRAINT `fk_vehicles_customer` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `vehicle_ev_details` (
  `vehicle_id` int unsigned NOT NULL,
  `battery_capacity` decimal(6,2) DEFAULT NULL COMMENT 'Dung lượng pin (kWh)',
  `battery_soh` decimal(5,2) DEFAULT NULL COMMENT 'Tình trạng sức khỏe pin (%)',
  `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`vehicle_id`),
  CONSTRAINT `fk_ev_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `work_order_images` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `order_id` int unsigned NOT NULL,
  `uploaded_by` int unsigned NOT NULL,
  `image_type` enum('before','after') NOT NULL,
  `image_path` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_work_order_images_order_type` (`order_id`,`image_type`),
  KEY `idx_work_order_images_user` (`uploaded_by`),
  CONSTRAINT `fk_work_order_images_order` FOREIGN KEY (`order_id`) REFERENCES `repair_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_work_order_images_user` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

<<<<<<< Updated upstream
-- Data exporting was unselected.

-- REDLINE: execute once after backing up the database (MySQL 8 / MariaDB)
CREATE TABLE IF NOT EXISTS technician_shifts (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 technician_id INT UNSIGNED NOT NULL,
 work_date DATE NOT NULL,
 shift_type ENUM('morning','afternoon','full') NOT NULL,
 note VARCHAR(500) NULL,
 status ENUM('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
 attendance ENUM('unconfirmed','present','absent') NOT NULL DEFAULT 'unconfirmed',
 reviewed_by INT UNSIGNED NULL,
 reviewed_at DATETIME NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY idx_technician_date (technician_id, work_date),
 KEY idx_shift_status (status,work_date),
 CONSTRAINT fk_tech_shift_user FOREIGN KEY (technician_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_tech_shift_reviewer FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS technician_pay_rates (
 technician_id INT UNSIGNED PRIMARY KEY,
 day_rate DECIMAL(13,2) NOT NULL DEFAULT 0,
 completed_order_bonus DECIMAL(13,2) NOT NULL DEFAULT 0,
 updated_by INT UNSIGNED NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 CONSTRAINT fk_tech_pay_user FOREIGN KEY (technician_id) REFERENCES users(id) ON DELETE CASCADE,
 CONSTRAINT fk_tech_pay_admin FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- Run ONCE after exporting a backup of redlinev1; requires MySQL 8+.
CREATE TABLE IF NOT EXISTS repair_order_cost_reviews (
    order_id INT UNSIGNED NOT NULL PRIMARY KEY,
    reviewed_by INT UNSIGNED NOT NULL,
    review_type ENUM('standard','zero') NOT NULL,
    reason VARCHAR(500) NOT NULL DEFAULT '',
    items_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reviewed_total DECIMAL(15,2) NOT NULL,
    reviewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cost_reviews_reviewer (reviewed_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Fresh-install role seed; never overwrite existing roles.
INSERT INTO roles (id, name) VALUES (1,'Admin'),(2,'Receptionist'),(3,'Technician'),(4,'Customers'),(5,'Cashier');
=======
>>>>>>> Stashed changes
/*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE, 'system') */;
/*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE, '') */;
/*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS, 1) */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES, 1) */;
