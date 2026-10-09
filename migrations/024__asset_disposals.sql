-- Run once in phpMyAdmin before using modules/assets/dispose.php
-- Keeps one record per asset sale / theft / damage / disposal (spec section 12).

CREATE TABLE asset_disposals (
  id INT(11) NOT NULL AUTO_INCREMENT,
  asset_id INT(11) NOT NULL,
  disposal_type ENUM('Sold','Stolen','Damaged','Disposed') NOT NULL,
  disposal_date DATE NOT NULL,
  book_value DECIMAL(14,2) NOT NULL,          -- the asset's value when it left the business
  proceeds DECIMAL(14,2) NOT NULL DEFAULT 0,  -- money received (sale or scrap)
  gain_loss DECIMAL(14,2) NOT NULL,           -- proceeds - book_value (negative = loss)
  notes TEXT DEFAULT NULL,
  transaction_id INT(11) DEFAULT NULL,        -- the Income (gain) or Expense (loss) transaction
  recorded_by INT(11) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY asset_id (asset_id),
  CONSTRAINT asset_disposals_ibfk_1 FOREIGN KEY (asset_id) REFERENCES assets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;