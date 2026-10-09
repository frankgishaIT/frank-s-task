ALTER TABLE fund_movements
  ADD COLUMN reverses_movement_id BIGINT NULL AFTER ref_id,
  ADD UNIQUE KEY uq_reverses (reverses_movement_id);