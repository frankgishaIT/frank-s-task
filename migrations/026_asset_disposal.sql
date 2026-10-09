CREATE TABLE asset_disposals (
  id INT(11) NOT NULL AUTO_INCREMENT,
  asset_id INT(11) NOT NULL,
  disposal_type ENUM('Sold','Stolen','Damaged','Disposed') NOT NULL,
  disposal_date DATE NOT NULL,
  book_value DECIMAL(14,2) NOT NULL,
  proceeds DECIMAL(14,2) NOT NULL DEFAULT 0,
  gain_loss DECIMAL(14,2) NOT NULL,
  notes TEXT DEFAULT NULL,
  transaction_id INT(11) DEFAULT NULL,
  recorded_by INT(11) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY asset_id (asset_id),
  CONSTRAINT asset_disposals_ibfk_1 FOREIGN KEY (asset_id) REFERENCES assets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;