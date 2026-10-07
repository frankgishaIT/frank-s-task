-- 019_fund_movement_reference.sql
-- Lets a fund ledger row point back to the record that caused it
-- (for example ref_type = 'loan', ref_id = the loan id). Run ONCE,
-- after 017 and 018.
ALTER TABLE fund_movements
  ADD COLUMN ref_type VARCHAR(30) NULL,
  ADD COLUMN ref_id INT NULL,
  ADD INDEX idx_fm_ref (ref_type, ref_id);
