-- RM Mart & Spark: customers, sales, sale line items.
-- Run this AFTER payroll_auto_calc.sql.

CREATE TABLE IF NOT EXISTS customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NULL,
    email VARCHAR(150) NULL,
    address VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS sales (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NULL,                          -- NULL = walk-in customer
    sale_date DATE NOT NULL,
    subtotal DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    discount_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    amount_paid DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    payment_method ENUM('Cash', 'Mobile Money', 'Bank Transfer', 'Credit') NOT NULL DEFAULT 'Cash',
    status ENUM('Pending Discount Approval', 'Paid', 'Partially Paid', 'Credit', 'Cancelled') NOT NULL DEFAULT 'Paid',

    -- Discount approval (Sales Officer -> Manager -> Invoice Released, per the Approval Engine)
    discount_requested_by INT NULL,
    discount_approved_by INT NULL,
    discount_approved_at DATETIME NULL,

    recorded_by INT NULL,
    transaction_id INT NULL,                        -- link to the auto-created Finance transaction
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_sales_customer FOREIGN KEY (customer_id) REFERENCES customers(id),
    CONSTRAINT fk_sales_recorded_by FOREIGN KEY (recorded_by) REFERENCES users(id),
    CONSTRAINT fk_sales_discount_requested_by FOREIGN KEY (discount_requested_by) REFERENCES users(id),
    CONSTRAINT fk_sales_discount_approved_by FOREIGN KEY (discount_approved_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS sale_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    line_total DECIMAL(12,2) NOT NULL,

    CONSTRAINT fk_sale_items_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
    CONSTRAINT fk_sale_items_product FOREIGN KEY (product_id) REFERENCES products(id)
);

-- A default Finance category so sales auto-post into Transactions without
-- the Admin having to remember to create one first.
INSERT INTO categories (category_name, description, is_active)
SELECT 'Sales Revenue', 'Income automatically recorded from RM Mart & Spark sales.', 1
WHERE NOT EXISTS (SELECT 1 FROM categories WHERE category_name = 'Sales Revenue');
