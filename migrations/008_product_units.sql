-- Migration: Product Units of Measure
-- Each product already has a base `unit` (e.g. "Pieces") on the products
-- table. This adds a per-product list of EXTRA units you've used before
-- (Box, Carton, Bag, Dozen, whatever you type) with a remembered "last used"
-- conversion size — always editable at transaction time, since pack sizes
-- can vary shipment to shipment.

CREATE TABLE IF NOT EXISTS product_units (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    unit_name VARCHAR(50) NOT NULL,
    last_pack_size INT NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_product_unit (product_id, unit_name),
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Sales need the same pack_label/pack_size fields Purchases already got,
-- so a sale can be rung up "3 Cartons" instead of forcing base units.
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sale_items' AND COLUMN_NAME = 'pack_label');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE sale_items ADD COLUMN pack_label VARCHAR(50) NULL DEFAULT NULL', 'SELECT "sale_items.pack_label already exists, skipping"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sale_items' AND COLUMN_NAME = 'pack_size');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE sale_items ADD COLUMN pack_size INT NOT NULL DEFAULT 1', 'SELECT "sale_items.pack_size already exists, skipping"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;