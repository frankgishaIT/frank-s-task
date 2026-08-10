-- Adds support for Service line items alongside Product line items in sales,
-- matching "Product Sales" and "Service Delivery" from RM Mart & Spark.
-- Run this AFTER rm_mart.sql.

ALTER TABLE sale_items
    MODIFY COLUMN product_id INT NULL,
    ADD COLUMN item_type ENUM('Product', 'Service') NOT NULL DEFAULT 'Product' AFTER sale_id,
    ADD COLUMN service_name VARCHAR(150) NULL AFTER product_id,
    ADD COLUMN unit ENUM('Pieces', 'Boxes') NULL AFTER quantity;
