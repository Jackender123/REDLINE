-- Customer may choose a technician when requesting service; NULL = Admin assigns.
-- Run once after taking a database backup.
ALTER TABLE service_requests
    ADD COLUMN preferred_technician_id INT UNSIGNED NULL DEFAULT NULL
    AFTER user_id,
    ADD INDEX idx_service_requests_preferred_technician (preferred_technician_id),
    ADD CONSTRAINT fk_service_requests_preferred_technician
      FOREIGN KEY (preferred_technician_id) REFERENCES users(id)
      ON DELETE SET NULL ON UPDATE CASCADE;
