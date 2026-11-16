-- Workshop upgrades for existing installations after the users and role migrations.
-- Select the target database and take a backup before running. Safe to rerun.
SET NAMES utf8mb4;

-- Customer technician preference. Check each object to support partial upgrades.
SET @redline_upgrade_sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_requests'
      AND COLUMN_NAME = 'preferred_technician_id'),
  'DO 0',
  'ALTER TABLE service_requests ADD COLUMN preferred_technician_id INT UNSIGNED NULL DEFAULT NULL AFTER user_id'
);
PREPARE redline_upgrade FROM @redline_upgrade_sql;
EXECUTE redline_upgrade;
DEALLOCATE PREPARE redline_upgrade;

SET @redline_upgrade_sql = IF(
  EXISTS(SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_requests'
      AND COLUMN_NAME = 'preferred_technician_id' AND SEQ_IN_INDEX = 1),
  'DO 0',
  'ALTER TABLE service_requests ADD INDEX idx_service_requests_preferred_technician (preferred_technician_id)'
);
PREPARE redline_upgrade FROM @redline_upgrade_sql;
EXECUTE redline_upgrade;
DEALLOCATE PREPARE redline_upgrade;

SET @redline_upgrade_sql = IF(
  EXISTS(SELECT 1 FROM information_schema.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'service_requests'
      AND COLUMN_NAME = 'preferred_technician_id'
      AND REFERENCED_TABLE_NAME = 'users' AND REFERENCED_COLUMN_NAME = 'id'),
  'DO 0',
  'ALTER TABLE service_requests ADD CONSTRAINT fk_service_requests_preferred_technician FOREIGN KEY (preferred_technician_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE CASCADE'
);
PREPARE redline_upgrade FROM @redline_upgrade_sql;
EXECUTE redline_upgrade;
DEALLOCATE PREPARE redline_upgrade;

-- Link fulfilled stock requests to billing. Historical/manual entries stay NULL.
SET @redline_upgrade_sql = IF(
  EXISTS(SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'repair_order_items'
      AND COLUMN_NAME = 'part_request_id'),
  'DO 0',
  'ALTER TABLE repair_order_items ADD COLUMN part_request_id BIGINT UNSIGNED NULL DEFAULT NULL COMMENT ''Unique fulfilled part_requests.id; NULL for historical/manual entries'''
);
PREPARE redline_upgrade FROM @redline_upgrade_sql;
EXECUTE redline_upgrade;
DEALLOCATE PREPARE redline_upgrade;

SET @redline_upgrade_sql = IF(
  EXISTS(SELECT 1 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'repair_order_items'
      AND INDEX_NAME = 'uq_repair_item_part_request'),
  'DO 0',
  'ALTER TABLE repair_order_items ADD UNIQUE KEY uq_repair_item_part_request (part_request_id)'
);
PREPARE redline_upgrade FROM @redline_upgrade_sql;
EXECUTE redline_upgrade;
DEALLOCATE PREPARE redline_upgrade;

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

-- Append the cancellation status while retaining custom enum values.
SELECT COLUMN_TYPE INTO @redline_status_type
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'repair_orders' AND COLUMN_NAME = 'status';
SET @redline_upgrade_sql = IF(
  LOCATE('''cancelled''', @redline_status_type) > 0,
  'DO 0',
  CONCAT('ALTER TABLE repair_orders MODIFY COLUMN status ',
    LEFT(@redline_status_type, CHAR_LENGTH(@redline_status_type) - 1),
    ',''cancelled'') NOT NULL DEFAULT ''received''')
);
PREPARE redline_upgrade FROM @redline_upgrade_sql;
EXECUTE redline_upgrade;
DEALLOCATE PREPARE redline_upgrade;

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

CREATE TABLE IF NOT EXISTS technician_pay_rates (
  technician_id INT UNSIGNED PRIMARY KEY,
  day_rate DECIMAL(13,2) NOT NULL DEFAULT 0,
  completed_order_bonus DECIMAL(13,2) NOT NULL DEFAULT 0,
  updated_by INT UNSIGNED NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_tech_pay_user FOREIGN KEY (technician_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_tech_pay_admin FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @redline_upgrade_sql = NULL, @redline_status_type = NULL;
