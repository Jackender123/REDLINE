-- Run ONCE after exporting a backup of redlinev1; requires MySQL 8+.
CREATE TABLE IF NOT EXISTS repair_order_cost_reviews (
    order_id INT UNSIGNED NOT NULL PRIMARY KEY,
    reviewed_by INT UNSIGNED NOT NULL,
    review_type ENUM('standard','zero') NOT NULL,
    reason VARCHAR(500) NOT NULL DEFAULT '',
    items_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    reviewed_total DECIMAL(15,2) NOT NULL,
    reviewed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_cost_reviews_reviewer (reviewed_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
