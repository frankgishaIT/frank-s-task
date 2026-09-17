CREATE TABLE IF NOT EXISTS business_parties (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type ENUM('Supplier', 'Payee', 'Partner') NOT NULL,

    -- Supplier-only
    tin VARCHAR(50) NULL,
    business_name VARCHAR(150) NULL,
    representative_name VARCHAR(150) NULL,

    -- Payee / Partner (person name). Supplier uses business_name instead.
    name VARCHAR(150) NULL,

    phone VARCHAR(20) NOT NULL,
    email VARCHAR(150) NULL,

    -- Payee-only
    payment_account_type ENUM('Bank', 'Phone') NULL,
    payment_account_details VARCHAR(150) NULL,

    province VARCHAR(100) NOT NULL,
    district VARCHAR(100) NOT NULL,
    sector VARCHAR(100) NOT NULL,

    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Purchase Order → RM Supplier
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'purchase_orders' AND COLUMN_NAME = 'supplier_party_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE purchase_orders ADD COLUMN supplier_party_id INT NULL DEFAULT NULL',
    'SELECT "purchase_orders.supplier_party_id already exists, skipping"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- Transaction → RM Payee (Expense) / RM Partner (Income)
SET @col_exists = (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'transactions' AND COLUMN_NAME = 'party_id'
);
SET @sql = IF(@col_exists = 0,
    'ALTER TABLE transactions ADD COLUMN party_id INT NULL DEFAULT NULL',
    'SELECT "transactions.party_id already exists, skipping"'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;