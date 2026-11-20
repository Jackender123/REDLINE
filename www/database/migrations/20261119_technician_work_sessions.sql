-- REDLINE 2026-11-19: retain technician work and charges across handovers.
-- Back up the existing database, select it, then import this additive migration.
-- Safe to rerun, including after a partial upgrade. No existing cost lines change.
-- Legacy assignments are initialized by an authorized locked mutation in PHP.

CREATE TABLE IF NOT EXISTS repair_order_work_sessions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  repair_order_id INT UNSIGNED NOT NULL,
  technician_id INT UNSIGNED NOT NULL,
  assigned_by INT UNSIGNED NULL,
  started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at DATETIME NULL,
  completion_type ENUM('handover','completed','reassigned') NULL,
  completion_note TEXT NULL,
  PRIMARY KEY (id),
  KEY idx_work_sessions_order (repair_order_id, id),
  KEY idx_work_sessions_technician (technician_id),
  KEY idx_work_sessions_assigner (assigned_by),
  CONSTRAINT fk_work_sessions_order FOREIGN KEY (repair_order_id) REFERENCES repair_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_work_sessions_technician FOREIGN KEY (technician_id) REFERENCES users(id),
  CONSTRAINT fk_work_sessions_assigner FOREIGN KEY (assigned_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET @redline_work_session_ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'repair_order_items' AND COLUMN_NAME = 'work_session_id') = 0,
    'ALTER TABLE repair_order_items ADD COLUMN work_session_id INT UNSIGNED NULL', 'SELECT 1');
PREPARE redline_work_session_stmt FROM @redline_work_session_ddl;
EXECUTE redline_work_session_stmt;
DEALLOCATE PREPARE redline_work_session_stmt;

SET @redline_work_session_ddl := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'repair_order_items' AND INDEX_NAME = 'idx_repair_items_work_session') = 0,
    'ALTER TABLE repair_order_items ADD KEY idx_repair_items_work_session (work_session_id)', 'SELECT 1');
PREPARE redline_work_session_stmt FROM @redline_work_session_ddl;
EXECUTE redline_work_session_stmt;
DEALLOCATE PREPARE redline_work_session_stmt;

SET @redline_work_session_ddl := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'repair_order_items' AND CONSTRAINT_NAME = 'fk_repair_items_work_session') = 0,
    'ALTER TABLE repair_order_items ADD CONSTRAINT fk_repair_items_work_session FOREIGN KEY (work_session_id) REFERENCES repair_order_work_sessions(id)', 'SELECT 1');
PREPARE redline_work_session_stmt FROM @redline_work_session_ddl;
EXECUTE redline_work_session_stmt;
DEALLOCATE PREPARE redline_work_session_stmt;

SET @redline_work_session_ddl := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'part_requests' AND COLUMN_NAME = 'work_session_id') = 0,
    'ALTER TABLE part_requests ADD COLUMN work_session_id INT UNSIGNED NULL', 'SELECT 1');
PREPARE redline_work_session_stmt FROM @redline_work_session_ddl;
EXECUTE redline_work_session_stmt;
DEALLOCATE PREPARE redline_work_session_stmt;

SET @redline_work_session_ddl := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'part_requests' AND INDEX_NAME = 'idx_part_requests_work_session') = 0,
    'ALTER TABLE part_requests ADD KEY idx_part_requests_work_session (work_session_id)', 'SELECT 1');
PREPARE redline_work_session_stmt FROM @redline_work_session_ddl;
EXECUTE redline_work_session_stmt;
DEALLOCATE PREPARE redline_work_session_stmt;

SET @redline_work_session_ddl := IF(
    (SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'part_requests' AND CONSTRAINT_NAME = 'fk_part_requests_work_session') = 0,
    'ALTER TABLE part_requests ADD CONSTRAINT fk_part_requests_work_session FOREIGN KEY (work_session_id) REFERENCES repair_order_work_sessions(id)', 'SELECT 1');
PREPARE redline_work_session_stmt FROM @redline_work_session_ddl;
EXECUTE redline_work_session_stmt;
DEALLOCATE PREPARE redline_work_session_stmt;

