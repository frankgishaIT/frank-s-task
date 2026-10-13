-- Run once in phpMyAdmin (local AND live), AFTER migration_points_72h.sql.
-- Overdue charges on credit invoices: totals on the sale + a full ledger.

ALTER TABLE sales
  ADD COLUMN charges_accrued DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER amount_paid,   -- charges added (minus waivers)
  ADD COLUMN charges_paid DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER charges_accrued,  -- charges paid by the customer
  ADD COLUMN charge_days INT(11) NOT NULL DEFAULT 0 AFTER charges_paid;            -- charge days already applied

CREATE TABLE credit_ledger (
  id INT(11) NOT NULL AUTO_INCREMENT,
  sale_id INT(11) NOT NULL,
  entry_type ENUM('PRINCIPAL','PAYMENT_PRINCIPAL','CHARGE','PAYMENT_CHARGE','WAIVER') NOT NULL,
  amount DECIMAL(12,2) NOT NULL,
  charge_day_no INT(11) DEFAULT NULL,       -- 1, 2, 3 ... for CHARGE rows
  charge_date DATE DEFAULT NULL,            -- the day the charge applies to
  sale_payment_id INT(11) DEFAULT NULL,     -- the payment, for PAYMENT_* rows
  principal_balance DECIMAL(12,2) NOT NULL, -- after this entry
  charges_balance DECIMAL(12,2) NOT NULL,   -- after this entry
  notes VARCHAR(255) DEFAULT NULL,
  created_by INT(11) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY one_charge_per_day (sale_id, entry_type, charge_day_no),  -- a day can never be charged twice
  KEY sale_id (sale_id),
  CONSTRAINT credit_ledger_sale FOREIGN KEY (sale_id) REFERENCES sales (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Opening entries for the credit invoices that are open today.
INSERT INTO credit_ledger (sale_id, entry_type, amount, principal_balance, charges_balance, notes)
SELECT id, 'PRINCIPAL', total_amount, total_amount, 0, 'Opening entry (invoice open when charges started)'
FROM sales WHERE status IN ('Credit', 'Partially Paid');

INSERT INTO credit_ledger (sale_id, entry_type, amount, principal_balance, charges_balance, notes)
SELECT id, 'PAYMENT_PRINCIPAL', amount_paid, total_amount - amount_paid, 0, 'Opening entry: paid before charges started'
FROM sales WHERE status IN ('Credit', 'Partially Paid') AND amount_paid > 0;