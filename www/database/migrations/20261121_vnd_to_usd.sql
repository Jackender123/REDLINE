-- REDLINE -- ONE-TIME VND -> USD FINANCIAL DATA MIGRATION
-- STOP: Export a full MySQL backup and put the workshop into maintenance mode first.
-- This conversion changes historic amounts in-place; never run it on a DB that already stores USD.
-- Choose an agreed conversion rate with your accountant before running this script.
-- The example below is NOT a live/exchange-market quote; replace it if necessary.
-- 1 USD = 25,000 VND (EXAMPLE ONLY)
-- MySQL 8+ / compatible MariaDB. Import through phpMyAdmin SQL tab or mysql CLI.

DROP PROCEDURE IF EXISTS redline_one_time_vnd_to_usd;
DELIMITER $$
CREATE PROCEDURE redline_one_time_vnd_to_usd(IN vnd_per_usd DECIMAL(16,4))
BEGIN
    DECLARE previous_currency VARCHAR(40) DEFAULT NULL;
    DECLARE existing_marker INT DEFAULT 0;
    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    IF vnd_per_usd IS NULL OR vnd_per_usd <= 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Enter a positive VND-per-USD conversion rate';
    END IF;
    SELECT COUNT(*), MAX(setting_value) INTO existing_marker, previous_currency
    FROM settings WHERE setting_key='currency_code';
    IF existing_marker > 0 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Currency marker already exists. Check the database; conversion will not be repeated.';
    END IF;

    -- Currency columns holding catalog amounts as integers must allow USD cents.
    -- DDL in MySQL commits implicitly; the VND->USD amount updates below are transactional.
    ALTER TABLE parts
        MODIFY import_price DECIMAL(14,2) NOT NULL DEFAULT 0,
        MODIFY selling_price DECIMAL(14,2) NOT NULL DEFAULT 0,
        MODIFY catalog_price DECIMAL(14,2) NULL,
        MODIFY sale_price DECIMAL(14,2) NULL;
    ALTER TABLE services MODIFY price DECIMAL(14,2) NOT NULL DEFAULT 0;
    ALTER TABLE repair_orders MODIFY estimated_cost DECIMAL(14,2) NOT NULL DEFAULT 0;

    START TRANSACTION;
      UPDATE parts SET
          import_price=ROUND(import_price/vnd_per_usd,2),
          selling_price=ROUND(selling_price/vnd_per_usd,2),
          catalog_price=IF(catalog_price IS NULL,NULL,ROUND(catalog_price/vnd_per_usd,2)),
          sale_price=IF(sale_price IS NULL,NULL,ROUND(sale_price/vnd_per_usd,2)),
          currency='USD';
      UPDATE services SET price=ROUND(price/vnd_per_usd,2);
      UPDATE repair_orders SET
          estimated_cost=ROUND(estimated_cost/vnd_per_usd,2),
          total_amount=ROUND(total_amount/vnd_per_usd,2),
          discount_amount=ROUND(discount_amount/vnd_per_usd,2),
          final_amount=ROUND(final_amount/vnd_per_usd,2);
      UPDATE repair_order_items SET
          unit_price=ROUND(unit_price/vnd_per_usd,2),
          subtotal=ROUND(subtotal/vnd_per_usd,2);
      UPDATE invoices SET
          total_services=ROUND(total_services/vnd_per_usd,2),
          total_parts=ROUND(total_parts/vnd_per_usd,2),
          discount=ROUND(discount/vnd_per_usd,2),
          grand_total=ROUND(grand_total/vnd_per_usd,2);
      UPDATE invoice_items SET
          unit_price=ROUND(unit_price/vnd_per_usd,2),
          subtotal=ROUND(subtotal/vnd_per_usd,2);
      UPDATE cash_transactions SET amount=ROUND(amount/vnd_per_usd,2);
      UPDATE technician_pay_rates SET
          day_rate=ROUND(day_rate/vnd_per_usd,2),
          completed_order_bonus=ROUND(completed_order_bonus/vnd_per_usd,2);
      UPDATE repair_order_cost_reviews SET reviewed_total=ROUND(reviewed_total/vnd_per_usd,2);

      -- Marker written in same transaction as the monetary updates.
      INSERT INTO settings(setting_key,setting_value) VALUES ('currency_code','USD');
      INSERT INTO settings(setting_key,setting_value) VALUES ('currency_fx_vnd_per_usd',CAST(vnd_per_usd AS CHAR));
      INSERT INTO settings(setting_key,setting_value) VALUES ('currency_converted_at',NOW());
    COMMIT;
END$$
DELIMITER ;

-- EDIT THIS NUMBER BEFORE RUNNING:
CALL redline_one_time_vnd_to_usd(25000.0000);
DROP PROCEDURE IF EXISTS redline_one_time_vnd_to_usd;

-- Verify:  SELECT setting_key, setting_value FROM settings WHERE setting_key LIKE 'currency%';
-- Inspect sample invoices and balances. Due to 2-decimal rounding, payment/status adjustments
-- may be needed for old transactions; reconcile with the original backup before going live.
-- Prior repair-cost approvals may require reapproval: line-item hashes intentionally change.
