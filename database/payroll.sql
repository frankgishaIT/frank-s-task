ALTER TABLE users
    ADD COLUMN monthly_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER phone;

CREATE TABLE payroll (
    id INT(11) NOT NULL AUTO_INCREMENT,
    user_id INT(11) NOT NULL,
    pay_period DATE NOT NULL,
    basic_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    bonus DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    deductions DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    net_salary DECIMAL(12,2) NOT NULL DEFAULT 0.00,
    status ENUM('Draft', 'Paid') NOT NULL DEFAULT 'Draft',
    paid_at DATETIME DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY payroll_user_period (user_id, pay_period),
    CONSTRAINT payroll_user_fk FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
