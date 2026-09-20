-- Migration: Units Master Catalog
-- Replaces the free-text unit_name on product_units with a proper shared
-- `units` table (Pieces, Boxes, Cartons, etc.), reused across every
-- product instead of each product typing its own disconnected text.
-- Safe to run even if migration 008's product_units already exists and is
-- still empty (no real transactions have been recorded against it yet).

CREATE TABLE IF NOT EXISTS units (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO units (name) VALUES
('Pieces'), ('Boxes'), ('Cartons'), ('Packets'), ('Packs'), ('Bundles'),
('Dozens'), ('Pairs'), ('Rolls'), ('Reams'), ('Bags'), ('Sacks'),
('Kilograms'), ('Grams'), ('Liters'), ('Meters'), ('Centimeters');

-- Drop and recreate product_units with a proper FK to the units catalog
-- instead of a free-text unit_name column. Safe: this table was only just
-- introduced and has no real transaction history depending on its old shape.
DROP TABLE IF EXISTS product_units;

CREATE TABLE product_units (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    unit_id INT NOT NULL,
    conversion_rate INT NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_product_unit (product_id, unit_id),
    FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    FOREIGN KEY (unit_id) REFERENCES units(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;