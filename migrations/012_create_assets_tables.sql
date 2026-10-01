-- Asset Management module.
-- Depreciation is Straight-Line only for now:
--   Daily Depreciation = (Acquisition Value - Residual Value) / Useful Life in Days
-- depreciation_method is an ENUM so more methods can be added later without
-- a schema change beyond adding to the list.

CREATE TABLE IF NOT EXISTS assets (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_code VARCHAR(30) NOT NULL UNIQUE,        -- e.g. AST-000001, generated after insert
    asset_name VARCHAR(150) NOT NULL,
    asset_type VARCHAR(100) NOT NULL,
    acquisition_date DATE NOT NULL,
    acquisition_value DECIMAL(14,2) NOT NULL,
    residual_value DECIMAL(14,2) NOT NULL DEFAULT 0,   -- value the asset should never depreciate below
    useful_life_days INT NOT NULL,                     -- straight-line divisor
    depreciation_method ENUM('Straight-Line') NOT NULL DEFAULT 'Straight-Line',
    current_value DECIMAL(14,2) NOT NULL,              -- kept in sync by includes/asset_helpers.php
    responsible_department_id INT NULL,
    status ENUM('Active','Under Maintenance','Disposed','Lost') NOT NULL DEFAULT 'Active',
    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (responsible_department_id) REFERENCES departments(id) ON DELETE SET NULL,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- Append-only daily valuation history. One row per calendar day the asset
-- was depreciating; never edited or deleted once written. Once an asset
-- reaches its residual value, no further rows are added (it just stays
-- there), to avoid the table filling with identical rows forever.
CREATE TABLE IF NOT EXISTS asset_valuation_history (
    id INT AUTO_INCREMENT PRIMARY KEY,
    asset_id INT NOT NULL,
    valuation_date DATE NOT NULL,
    daily_depreciation DECIMAL(14,2) NOT NULL DEFAULT 0,
    value DECIMAL(14,2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_asset_day (asset_id, valuation_date),
    FOREIGN KEY (asset_id) REFERENCES assets(id) ON DELETE CASCADE
);