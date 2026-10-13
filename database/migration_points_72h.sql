-- Run once in phpMyAdmin (local AND live database).
-- 72-hour Loyalty Points rule for credit invoices.

ALTER TABLE sales
  ADD COLUMN invoice_issued_at DATETIME NULL AFTER created_at,          -- when the credit invoice became effective
  ADD COLUMN payment_deadline DATETIME NULL AFTER invoice_issued_at,    -- invoice_issued_at + 72 hours
  ADD COLUMN fully_paid_at DATETIME NULL AFTER payment_deadline,
  ADD COLUMN points_status ENUM('Not applicable','Pending','Awarded','Not awarded') NULL AFTER loyalty_points_earned,
  ADD COLUMN points_pending INT(11) NOT NULL DEFAULT 0 AFTER points_status,  -- points waiting for full payment
  ADD COLUMN points_reason VARCHAR(255) NULL AFTER points_pending,
  ADD COLUMN points_decided_at DATETIME NULL AFTER points_reason;

-- Sales from before this change keep what they already received (nothing is taken back).
UPDATE sales
SET points_status = 'Awarded',
    points_reason = 'Awarded before the 72-hour rule',
    points_decided_at = NOW()
WHERE loyalty_points_earned > 0 AND points_status IS NULL;

UPDATE sales
SET points_status = 'Not applicable',
    points_reason = 'No points on this sale'
WHERE points_status IS NULL AND status <> 'Pending Discount Approval';

-- Unpaid invoices that exist today: their deadline counts from now, so no customer is surprised.
UPDATE sales
SET invoice_issued_at = NOW(),
    payment_deadline = DATE_ADD(NOW(), INTERVAL 72 HOUR)
WHERE status IN ('Credit', 'Partially Paid') AND payment_deadline IS NULL;
