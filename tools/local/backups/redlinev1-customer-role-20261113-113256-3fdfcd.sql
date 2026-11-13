-- REDLINE backup before consolidating users
SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS=0;
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
  CONSTRAINT `fk_appointments_customer` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_appointments_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `login_attempts`;
CREATE TABLE `login_attempts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(190) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `attempted_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_attempt_email` (`email`,`attempted_at`),
  KEY `idx_attempt_ip` (`ip_address`,`attempted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=9 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `part_categories`;
CREATE TABLE `part_categories` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `target_engine` enum('gasoline','ev','common') NOT NULL DEFAULT 'common',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_part_categories_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=8 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
  CONSTRAINT `fk_part_requests_decider` FOREIGN KEY (`decided_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_part_requests_order` FOREIGN KEY (`order_id`) REFERENCES `repair_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_part_requests_part` FOREIGN KEY (`part_id`) REFERENCES `parts` (`id`),
  CONSTRAINT `fk_part_requests_user` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
  CONSTRAINT `fk_portal_vehicle_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `quote_items`;
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
  CONSTRAINT `fk_remember_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
) ENGINE=InnoDB AUTO_INCREMENT=7 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `repair_orders`;
CREATE TABLE `repair_orders` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `order_code` varchar(20) NOT NULL COMMENT 'Mã phiếu công khai (VD: RO20261020-001)',
  `vehicle_id` int(10) unsigned NOT NULL,
  `customer_id` int(10) unsigned DEFAULT NULL,
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
  KEY `fk_orders_customer` (`customer_id`),
  CONSTRAINT `fk_orders_appointment` FOREIGN KEY (`appointment_id`) REFERENCES `appointments` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_orders_assigned` FOREIGN KEY (`assigned_to`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `fk_orders_customer` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,
  CONSTRAINT `fk_orders_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=21 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `roles`;
CREATE TABLE `roles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(50) NOT NULL COMMENT 'Admin | Receptionist | Technician | Cashier',
  `description` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_name` (`name`)
) ENGINE=InnoDB AUTO_INCREMENT=5 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
  KEY `idx_service_req_status` (`status`),
  CONSTRAINT `fk_service_requests_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=17 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
DROP TABLE IF EXISTS `settings`;
CREATE TABLE `settings` (
  `setting_key` varchar(50) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `role_id` int(10) unsigned DEFAULT NULL,
  `username` varchar(50) DEFAULT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `full_name` varchar(150) NOT NULL,
  `email` varchar(190) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `address` varchar(255) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `is_customer` tinyint(1) NOT NULL DEFAULT 1,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `zalo` varchar(30) DEFAULT NULL,
  `contact_method` enum('phone','zalo','email') NOT NULL DEFAULT 'phone',
  `notify_repair` tinyint(1) NOT NULL DEFAULT 1,
  `notify_reminder` tinyint(1) NOT NULL DEFAULT 1,
  `notify_offers` tinyint(1) NOT NULL DEFAULT 0,
  `password_changed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_username` (`username`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_phone` (`phone`),
  KEY `idx_users_customer_name` (`is_customer`,`full_name`),
  KEY `idx_users_role` (`role_id`),
  CONSTRAINT `fk_users_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=18 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
DROP TABLE IF EXISTS `vehicle_ev_details`;
CREATE TABLE `vehicle_ev_details` (
  `vehicle_id` int(10) unsigned NOT NULL,
  `battery_capacity` decimal(6,2) DEFAULT NULL COMMENT 'Dung lượng pin (kWh)',
  `battery_soh` decimal(5,2) DEFAULT NULL COMMENT 'Tình trạng sức khỏe pin (%)',
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`vehicle_id`),
  CONSTRAINT `fk_ev_vehicle` FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
  CONSTRAINT `fk_vehicles_customer` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=12 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
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
SET FOREIGN_KEY_CHECKS=1;
