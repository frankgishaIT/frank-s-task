ALTER TABLE sales
    ADD COLUMN cancelled_by INT NULL,
    ADD COLUMN cancelled_at DATETIME NULL,
    ADD COLUMN cancel_reason VARCHAR(255) NULL,
    ADD CONSTRAINT fk_sales_cancelled_by FOREIGN KEY (cancelled_by) REFERENCES users(id);
    ALTER TABLE sales
    ADD COLUMN cancel_requested_by INT NULL,
    ADD COLUMN cancel_requested_at DATETIME NULL,
    ADD COLUMN cancel_request_reason VARCHAR(255) NULL,
    ADD CONSTRAINT fk_sales_cancel_requested_by FOREIGN KEY (cancel_requested_by) REFERENCES users(id);