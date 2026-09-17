-- Migration: link manual Restock entries to an RM Supplier party too,
-- matching how Purchase Orders already do it.

SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchases' AND COLUMN_NAME = 'supplier_party_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE purchases ADD COLUMN supplier_party_id INT NULL DEFAULT NULL',
    'SELECT "purchases.supplier_party_id already exists, skipping"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;