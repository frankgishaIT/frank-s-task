-- 1) Products now record their stock unit (Pieces or Boxes) at the product level,
--    instead of letting it be chosen freely at sale time (which could cause
--    stock deduction to be counted in the wrong unit).
ALTER TABLE products
    ADD COLUMN unit ENUM('Pieces', 'Boxes') NOT NULL DEFAULT 'Pieces' AFTER quantity;

-- 2) Positions: one Department can have many Positions (job titles),
--    matching "Positions" under User & HR Management in the spec.
CREATE TABLE IF NOT EXISTS positions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    department_id INT NOT NULL,
    title VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_positions_department FOREIGN KEY (department_id) REFERENCES departments(id),
    UNIQUE KEY uniq_position_per_department (department_id, title)
);

-- 3) Each employee can now be assigned a specific Position within their Department.
ALTER TABLE users
    ADD COLUMN position_id INT NULL AFTER department_id,
    ADD CONSTRAINT fk_users_position FOREIGN KEY (position_id) REFERENCES positions(id);
