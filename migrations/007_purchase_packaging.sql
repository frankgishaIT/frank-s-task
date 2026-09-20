-- Migration: Purchase Packaging / Unit Conversion
-- Lets a Purchase Order or Restock line be entered in whatever pack the
-- supplier sells in (Crate, Box, Piece, etc.) while stock is always kept
-- in base units. pack_size = base units per pack; quantity fields on the
-- order/purchase represent number of PACKS; unit_cost represents cost PER
-- PACK. Conversion to base units happens in PHP at receive/restock time.

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_order_items' AND COLUMN_NAME = 'pack_label');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE purchase_order_items ADD COLUMN pack_label VARCHAR(50) NOT NULL DEFAULT ''Piece''', 'SELECT "purchase_order_items.pack_label already exists, skipping"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_order_items' AND COLUMN_NAME = 'pack_size');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE purchase_order_items ADD COLUMN pack_size INT NOT NULL DEFAULT 1', 'SELECT "purchase_order_items.pack_size already exists, skipping"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- purchases: keep `quantity`/`unit_cost` meaning BASE units / cost-per-base-unit
-- (unchanged, so all existing totals and Purchase History math stay correct).
-- Add extra columns purely for traceability of what was actually ordered.
SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchases' AND COLUMN_NAME = 'pack_label');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE purchases ADD COLUMN pack_label VARCHAR(50) NULL DEFAULT NULL', 'SELECT "purchases.pack_label already exists, skipping"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchases' AND COLUMN_NAME = 'pack_size');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE purchases ADD COLUMN pack_size INT NULL DEFAULT 1', 'SELECT "purchases.pack_size already exists, skipping"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchases' AND COLUMN_NAME = 'pack_quantity');
SET @sql = IF(@col_exists = 0, 'ALTER TABLE purchases ADD COLUMN pack_quantity INT NULL DEFAULT NULL', 'SELECT "purchases.pack_quantity already exists, skipping"');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;