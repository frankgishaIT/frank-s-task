-- Run once. Adds the reorder level used by the Low Stock Items report.
-- 0 = no reorder level set (item is not monitored).
ALTER TABLE products ADD COLUMN reorder_level INT NOT NULL DEFAULT 0 AFTER quantity;
