-- Part A: Products get a fixed unit of measure (Pieces or Boxes), set once
-- when the product is created, instead of being chosen at every sale.
ALTER TABLE products
    ADD COLUMN unit ENUM('Pieces', 'Boxes') NOT NULL DEFAULT 'Pieces' AFTER quantity;

-- Part B: Positions — one Department has many Positions
-- (e.g. "Sales" department -> "Sales Officer", "Cashier").
CREATE TABLE IF NOT EXISTS positions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    department_id INT NOT NULL,
    title VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_positions_department FOREIGN KEY (department_id) REFERENCES departments(id)
);

-- Each employee may optionally hold a Position (their job title),
-- separate from their system access Role (Admin/Manager/Employee).
ALTER TABLE users
    ADD COLUMN position_id INT NULL AFTER department_id,
    ADD CONSTRAINT fk_users_position FOREIGN KEY (position_id) REFERENCES positions(id);
