CREATE TABLE sale_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    sale_id INT NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    payment_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    recorded_by INT NOT NULL,
    notes VARCHAR(255) NULL,
    FOREIGN KEY (sale_id) REFERENCES sales(id)
);