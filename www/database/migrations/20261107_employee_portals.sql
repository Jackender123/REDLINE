-- Employee portals: technician diagnostics, cashier payments, and parts requests.

INSERT INTO roles (name, description)
VALUES ('Cashier', 'Thu ngân: lập hóa đơn, thu tiền, in biên nhận và xem báo cáo doanh thu')
ON DUPLICATE KEY UPDATE description = VALUES(description);

ALTER TABLE repair_orders
    MODIFY status ENUM('received', 'diagnosing', 'repairing', 'waiting_parts', 'inspecting', 'completed', 'delivered')
    NOT NULL DEFAULT 'received';

ALTER TABLE vehicles
    MODIFY engine_type ENUM('gasoline', 'diesel', 'hybrid', 'ev')
    NOT NULL DEFAULT 'gasoline';

ALTER TABLE invoices
    MODIFY payment_status ENUM('unpaid', 'partial', 'paid', 'refunded', 'cancelled')
    DEFAULT 'unpaid',
    MODIFY payment_method ENUM('cash', 'transfer', 'qr', 'card')
    DEFAULT 'cash';

CREATE TABLE IF NOT EXISTS technical_inspections (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id INT UNSIGNED NOT NULL,
    updated_by INT UNSIGNED NOT NULL,
    technical_note TEXT DEFAULT NULL,
    customer_complaints TEXT DEFAULT NULL,
    inspection_needed TEXT DEFAULT NULL,
    repairs_performed TEXT DEFAULT NULL,
    engine_result TEXT DEFAULT NULL,
    brakes_result TEXT DEFAULT NULL,
    electrical_result TEXT DEFAULT NULL,
    ac_result TEXT DEFAULT NULL,
    hybrid_battery_status VARCHAR(255) DEFAULT NULL,
    hybrid_voltage DECIMAL(8,2) DEFAULT NULL,
    hybrid_temperature DECIMAL(5,2) DEFAULT NULL,
    hybrid_cooling_result TEXT DEFAULT NULL,
    inverter_result TEXT DEFAULT NULL,
    electric_motor_result TEXT DEFAULT NULL,
    dtc_codes TEXT DEFAULT NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_technical_inspections_order (order_id),
    KEY idx_technical_inspections_updated_by (updated_by),
    CONSTRAINT fk_technical_inspections_order
        FOREIGN KEY (order_id) REFERENCES repair_orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_technical_inspections_user
        FOREIGN KEY (updated_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS work_order_images (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_id INT UNSIGNED NOT NULL,
    uploaded_by INT UNSIGNED NOT NULL,
    image_type ENUM('before', 'after') NOT NULL,
    image_path VARCHAR(255) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_work_order_images_order_type (order_id, image_type),
    CONSTRAINT fk_work_order_images_order
        FOREIGN KEY (order_id) REFERENCES repair_orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_work_order_images_user
        FOREIGN KEY (uploaded_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS part_requests (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    request_code VARCHAR(32) NOT NULL,
    order_id INT UNSIGNED NOT NULL,
    part_id INT UNSIGNED NOT NULL,
    requested_by INT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    status ENUM('pending', 'approved', 'rejected', 'fulfilled') NOT NULL DEFAULT 'pending',
    note VARCHAR(500) DEFAULT NULL,
    decided_by INT UNSIGNED DEFAULT NULL,
    requested_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_part_requests_code (request_code),
    KEY idx_part_requests_order (order_id, requested_at),
    KEY idx_part_requests_status (status, requested_at),
    CONSTRAINT fk_part_requests_order
        FOREIGN KEY (order_id) REFERENCES repair_orders (id) ON DELETE CASCADE,
    CONSTRAINT fk_part_requests_part
        FOREIGN KEY (part_id) REFERENCES parts (id),
    CONSTRAINT fk_part_requests_user
        FOREIGN KEY (requested_by) REFERENCES users (id),
    CONSTRAINT fk_part_requests_decider
        FOREIGN KEY (decided_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS invoice_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    invoice_id INT NOT NULL,
    item_type ENUM('service', 'part') NOT NULL,
    description VARCHAR(255) NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    subtotal DECIMAL(12,2) NOT NULL,
    PRIMARY KEY (id),
    KEY idx_invoice_items_invoice (invoice_id),
    CONSTRAINT fk_invoice_items_invoice
        FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cash_transactions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT,
    transaction_code VARCHAR(40) NOT NULL,
    invoice_id INT NOT NULL,
    transaction_type ENUM('payment', 'refund') NOT NULL DEFAULT 'payment',
    amount DECIMAL(12,2) NOT NULL,
    payment_method ENUM('cash', 'transfer', 'qr', 'card') NOT NULL,
    status ENUM('completed', 'voided') NOT NULL DEFAULT 'completed',
    note VARCHAR(255) DEFAULT NULL,
    created_by INT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cash_transactions_code (transaction_code),
    KEY idx_cash_transactions_invoice_date (invoice_id, created_at),
    KEY idx_cash_transactions_date_method (created_at, payment_method, status),
    CONSTRAINT fk_cash_transactions_invoice
        FOREIGN KEY (invoice_id) REFERENCES invoices (id) ON DELETE CASCADE,
    CONSTRAINT fk_cash_transactions_user
        FOREIGN KEY (created_by) REFERENCES users (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
