-- 018_profit_allocations.sql
-- Monthly Net Profit allocation records. One row per month (UNIQUE) so a
-- month can never be allocated twice. Run once, after 017_fund_management.sql.

CREATE TABLE IF NOT EXISTS profit_allocations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  period CHAR(7) NOT NULL UNIQUE,                 -- e.g. 2026-09
  product_profit DECIMAL(15,2) NOT NULL DEFAULT 0,
  service_profit DECIMAL(15,2) NOT NULL DEFAULT 0,
  expenses_deducted DECIMAL(15,2) NOT NULL DEFAULT 0,
  net_profit DECIMAL(15,2) NOT NULL,
  status ENUM('CONFIRMED','REVERSED') NOT NULL DEFAULT 'CONFIRMED',
  confirmed_by INT NULL,
  confirmed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Link each ledger row created by an allocation back to its allocation (run ONCE)
ALTER TABLE fund_movements
  ADD COLUMN allocation_id INT NULL,
  ADD INDEX idx_fm_alloc (allocation_id);