-- REDLINE operational upgrade · 18 November 2026
-- Backup database first. Import after the existing schema and workshop updates.
-- Safe to run again: only CREATE TABLE IF NOT EXISTS statements.
-- No email delivery or bank integration is configured by this migration.
SET NAMES utf8mb4;

INSERT INTO roles (name, description)
SELECT 'Receptionist', 'Workshop intake and appointment management'
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE name='Receptionist');
INSERT INTO roles (name, description)
SELECT 'Cashier', 'Invoices and verified payment collection'
WHERE NOT EXISTS (SELECT 1 FROM roles WHERE name='Cashier');


CREATE TABLE IF NOT EXISTS support_tickets (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_id INT UNSIGNED NOT NULL,
  topic ENUM('technical','billing','appointments','other') NOT NULL,
  message TEXT NOT NULL,
  status ENUM('open','in_progress','resolved') NOT NULL DEFAULT 'open',
  handled_by INT UNSIGNED NULL,
  resolved_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_support_customer (customer_id, created_at),
  KEY idx_support_status (status, created_at),
  FOREIGN KEY (customer_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (handled_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS support_ticket_replies (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ticket_id INT UNSIGNED NOT NULL,
  author_id INT UNSIGNED NOT NULL,
  message TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_support_replies_ticket (ticket_id, created_at),
  FOREIGN KEY (ticket_id) REFERENCES support_tickets(id) ON DELETE CASCADE,
  FOREIGN KEY (author_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Proposed date/time is separate from the confirmed appointments table.
-- It is only a request: the workshop must still confirm resource availability.
CREATE TABLE IF NOT EXISTS booking_preferences (
  service_request_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  portal_vehicle_id INT UNSIGNED NULL,
  preferred_date DATE NOT NULL,
  preferred_time TIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (service_request_id),
  KEY idx_booking_customer_vehicle_slot (user_id, portal_vehicle_id, preferred_date, preferred_time),
  KEY idx_booking_proposed_schedule (preferred_date, preferred_time),
  FOREIGN KEY (service_request_id) REFERENCES service_requests(id) ON DELETE CASCADE,
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  FOREIGN KEY (portal_vehicle_id) REFERENCES portal_vehicles(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
