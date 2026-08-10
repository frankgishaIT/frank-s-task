-- Splits Products into Items (physical, stocked) and Services (no stock),
-- and drops the mandatory Category requirement from the product form.
-- Run this AFTER positions_and_product_units.sql.

ALTER TABLE products
    MODIFY COLUMN category_id INT NULL,
    ADD COLUMN item_type ENUM('Item', 'Service') NOT NULL DEFAULT 'Item' AFTER product_code;
