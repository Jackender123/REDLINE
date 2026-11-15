-- Back up database first. REDLINE(9) repair_orders.status enum plus cancelled.
-- Inspect SHOW COLUMNS FROM repair_orders LIKE 'status' first; preserve any additional custom values.
ALTER TABLE repair_orders MODIFY COLUMN status
    ENUM('received','diagnosing','repairing','waiting_parts','inspecting','completed','delivered','cancelled')
    NOT NULL DEFAULT 'received';
