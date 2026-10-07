-- 017_fund_management.sql
-- RM Funds Management, Profit Allocation & Business Value
-- Run once. Tables use IF NOT EXISTS and the seed uses INSERT IGNORE,
-- so re-running those parts is safe. The ALTER TABLE at the bottom is NOT
-- repeatable: run it only once.

-- 1. Funds master list
CREATE TABLE IF NOT EXISTS funds (
  id INT AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(30) NOT NULL UNIQUE,
  name VARCHAR(100) NOT NULL,
  allocation_percent DECIMAL(5,2) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB;

INSERT IGNORE INTO funds (code, name, allocation_percent, sort_order) VALUES
('OPERATING',    'RM Business Operating Fund', 75,   1),
('FUTURE_PLANS', 'RM Future Plans Fund',       20,   2),
('EMERGENCY',    'RM Emergency Fund',           3,   3),
('TEAM_GROWTH',  'RM Team Growth Fund',         2,   4),
('CAPITAL',      'RM Capital Fund',          NULL,   5);

-- 2. Fund ledger (audit trail). Balance = SUM(IN) - SUM(OUT).
-- Never edit or delete rows. Correct mistakes with a REVERSAL row.
CREATE TABLE IF NOT EXISTS fund_movements (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  fund_id INT NOT NULL,
  period CHAR(7) NOT NULL,                       -- e.g. 2026-10
  movement_type ENUM('ALLOCATION','CAPITAL_INFLOW','EXPENSE','REVERSAL') NOT NULL,
  direction ENUM('IN','OUT') NOT NULL,
  amount DECIMAL(15,2) NOT NULL,
  transaction_id INT NULL,
  source_type VARCHAR(50) NULL,                  -- Share Capital, Loan, Grant...
  description VARCHAR(255) NULL,
  created_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_fm_fund (fund_id),
  INDEX idx_fm_tx (transaction_id),
  INDEX idx_fm_period (period),
  CONSTRAINT fk_fm_fund FOREIGN KEY (fund_id) REFERENCES funds(id)
) ENGINE=InnoDB;

-- 3. New columns on transactions (run ONCE)
ALTER TABLE transactions
  ADD COLUMN fund_id INT NULL,
  ADD COLUMN expense_category VARCHAR(60) NULL,
  ADD COLUMN is_automatic TINYINT(1) NOT NULL DEFAULT 0;