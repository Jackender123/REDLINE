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
