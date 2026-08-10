-- 1) Positions: one Department has many Positions (job titles), per the
--    "User & HR Management" spec. Separate from the Admin/Manager/Employee
--    system access role, which stays as-is for permissions.
CREATE TABLE IF NOT EXISTS positions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    department_id INT NOT NULL,
    title VARCHAR(100) NOT NULL,
    description VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_positions_department FOREIGN KEY (department_id) REFERENCES departments(id)
);

ALTER TABLE users
    ADD COLUMN position_id INT NULL AFTER department_id,
    ADD CONSTRAINT fk_users_position FOREIGN KEY (position_id) REFERENCES positions(id);

-- 2) Product unit of measure: recorded once on the product itself (Pieces or
-- Boxes), instead of being guessed again on every sale line.
ALTER TABLE products
    ADD COLUMN unit ENUM('Pieces', 'Boxes') NOT NULL DEFAULT 'Pieces' AFTER quantity;
