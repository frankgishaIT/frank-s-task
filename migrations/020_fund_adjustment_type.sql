-- 020_fund_adjustment_type.sql
-- Adds the ADJUSTMENT movement type, used to correct an earlier Capital Fund
-- inflow downwards (for example when a loan amount is reduced).
-- Run ONCE, after 017, 018 and 019.
ALTER TABLE fund_movements
  MODIFY movement_type ENUM('ALLOCATION','CAPITAL_INFLOW','EXPENSE','REVERSAL','ADJUSTMENT') NOT NULL;
