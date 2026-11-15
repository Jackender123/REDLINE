-- REDLINE Phase 2C: link issued stock to the repair order billing line.
-- Run ONCE in HeidiSQL, select database redlinev1 first. Back up the DB.
-- Existing historical rows are left unlinked and need manual reconciliation.
ALTER TABLE repair_order_items
    ADD COLUMN part_request_id BIGINT UNSIGNED NULL DEFAULT NULL
      COMMENT 'Unique fulfilled part_requests.id; NULL for historical/manual entries',
    ADD UNIQUE KEY uq_repair_item_part_request (part_request_id);
