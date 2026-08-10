-- Turns "products" into a single catalog of Items and Services.
-- Category is no longer required. Items keep stock/buying price/unit;
-- Services only need a name, description, and price.
-- Run this AFTER positions_and_product_units.sql.

ALTER TABLE products
    ADD COLUMN item_type ENUM('Item', 'Service') NOT NULL DEFAULT 'Item' AFTER id,
    MODIFY COLUMN category_id INT NULL,
    MODIFY COLUMN product_code VARCHAR(100) NULL,
    MODIFY COLUMN buying_price DECIMAL(12,2) NULL,
    MODIFY COLUMN quantity INT NULL,
    MODIFY COLUMN unit ENUM('Pieces', 'Boxes') NULL;

-- Sale line items now reference the same catalog for both Items and
-- Services (previously services were free-typed instead of selected).
-- product_id stays nullable so any earlier free-typed service sales aren't broken.
UPDATE sale_items SET item_type = 'Item' WHERE item_type = 'Product';
ALTER TABLE sale_items
    MODIFY COLUMN item_type ENUM('Item', 'Service') NOT NULL DEFAULT 'Item';
