-- REDLINE secure Visa/card checkout - import ONCE into your current `redlinev1` MySQL database.
-- This works whether or not the previous PayPal migration was installed.
-- Keep payment_method='card'; invoice amounts stay in USD, and historical cash/PayPal records are untouched.
-- BACK UP THE DATABASE FIRST.
CREATE TABLE IF NOT EXISTS `visa_checkout_attempts` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `invoice_id` INT NOT NULL,
  `customer_id` INT UNSIGNED NOT NULL,
  `request_key` CHAR(32) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `stripe_session_id` VARCHAR(255) CHARACTER SET ascii COLLATE ascii_general_ci DEFAULT NULL,
  `stripe_payment_intent_id` VARCHAR(255) CHARACTER SET ascii COLLATE ascii_general_ci DEFAULT NULL,
  `session_url` VARCHAR(2048) DEFAULT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `status` ENUM('creating','created','completed','failed','needs_review') NOT NULL DEFAULT 'creating',
  `expires_at` DATETIME NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_visa_request` (`request_key`),
  UNIQUE KEY `uq_visa_stripe_session` (`stripe_session_id`),
  UNIQUE KEY `uq_visa_stripe_intent` (`stripe_payment_intent_id`),
  KEY `idx_visa_invoice_state` (`invoice_id`,`status`,`expires_at`),
  KEY `idx_visa_customer` (`customer_id`),
  CONSTRAINT `fk_visa_invoice` FOREIGN KEY (`invoice_id`) REFERENCES `invoices` (`id`),
  CONSTRAINT `fk_visa_customer` FOREIGN KEY (`customer_id`) REFERENCES `users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
