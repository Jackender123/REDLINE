<?php
$root = dirname(__DIR__, 2) . '/www';
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if ($file->getExtension() !== 'php' || str_contains($file->getPathname(), DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR) || str_contains($file->getPathname(), DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR)) continue;
    $code = file_get_contents($file->getPathname());
    $code = preg_replace('/\b(FROM|JOIN|INTO|UPDATE) customers\b/', '$1 users', $code);
    $code = str_replace(['FROM portal_users', 'JOIN portal_users', 'INTO portal_users', 'UPDATE portal_users', "'portal_users' =>"], ['FROM users', 'JOIN users', 'INTO users', 'UPDATE users', "'users' =>"], $code);
    file_put_contents($file->getPathname(), $code);
}
$file = "$root/database/schema.sql";
$sql = file_get_contents($file);
$sql = preg_replace('/-- =+ (customers|portal_users) =+\R.*?(?=-- =+)/s', '', $sql);
$schema = <<<'SQL'
CREATE TABLE `users` (
  `id` int unsigned NOT NULL AUTO_INCREMENT,
  `role_id` int unsigned DEFAULT NULL,
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
$sql = preg_replace('/CREATE TABLE `users` \(.*?\) ENGINE=.*?;/s', $schema, $sql);
$sql = preg_replace('/INSERT INTO `users` VALUES.*?;\R/s', '', $sql);
$sql = str_replace(['REFERENCES `customers`', 'REFERENCES `portal_users`'], ['REFERENCES `users`', 'REFERENCES `users`'], $sql);
// Add constraints for identity references that the old schema left unchecked.
$sql = str_replace('`customer_id` int(11) DEFAULT NULL', '`customer_id` int unsigned DEFAULT NULL', $sql);
$sql = str_replace('CONSTRAINT `fk_orders_vehicle`', 'CONSTRAINT `fk_orders_customer` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`) ON UPDATE CASCADE,' . "\n  " . 'CONSTRAINT `fk_orders_vehicle`', $sql);
$sql = str_replace('KEY `idx_service_req_status` (`status`)', 'KEY `idx_service_req_status` (`status`),' . "\n  " . 'CONSTRAINT `fk_service_requests_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE', $sql);
file_put_contents($file, $sql);
