-- Asset Management <-> RM Capital Fund integration. Run once (or use modules/tools/migrations.php).

-- 3. Every asset is matched to its funding; 5. valuation treatment per asset.
ALTER TABLE assets
  ADD COLUMN funding_type ENUM('Capital Fund purchase','Opening balance') NULL AFTER status,
  ADD COLUMN funding_transaction_id INT(11) NULL AFTER funding_type,      -- the Capital Fund payment
  ADD COLUMN funding_note VARCHAR(255) NULL AFTER funding_transaction_id,
  ADD COLUMN asset_class ENUM('Tangible','Purchased software','Internally developed software','Capitalized development cost','Other intangible')
      NOT NULL DEFAULT 'Tangible' AFTER asset_type,
  ADD COLUMN valuation_method ENUM('Depreciation','Amortization','No depreciation') NOT NULL DEFAULT 'Depreciation' AFTER asset_class,
  ADD COLUMN daily_depreciation_override DECIMAL(14,4) NULL AFTER useful_life_days;  -- set after an impairment / revaluation

-- Assets that exist today were added to the Capital Fund as value only: they are opening balances.
UPDATE assets SET funding_type = 'Opening balance',
       funding_note = 'Registered before the Capital Fund purchase rule (no payment taken from the fund)'
WHERE funding_type IS NULL;

-- 4. Asset returned to the seller, with the refund received or receivable.
ALTER TABLE asset_disposals
  MODIFY disposal_type ENUM('Sold','Stolen','Damaged','Disposed','Returned to Seller') NOT NULL,
  ADD COLUMN refund_status ENUM('Not applicable','Received','Receivable') NOT NULL DEFAULT 'Not applicable' AFTER proceeds,
  ADD COLUMN refund_received_at DATETIME NULL AFTER refund_status;

-- 5. Impairments and revaluations (evidence required). No cash is ever moved by these.
CREATE TABLE asset_valuations (
  id INT(11) NOT NULL AUTO_INCREMENT,
  asset_id INT(11) NOT NULL,
  valuation_type ENUM('Impairment','Revaluation') NOT NULL,
  valuation_date DATE NOT NULL,
  value_before DECIMAL(14,2) NOT NULL,
  value_after DECIMAL(14,2) NOT NULL,
  change_amount DECIMAL(14,2) NOT NULL,     -- value_after - value_before
  to_reserve DECIMAL(14,2) NOT NULL DEFAULT 0,  -- part taken to / from the Revaluation Reserve (equity)
  to_profit_loss DECIMAL(14,2) NOT NULL DEFAULT 0, -- part recognised as a loss (negative) or reversal (positive)
  valuer VARCHAR(150) NULL,
  evidence_file VARCHAR(100) NULL,
  notes TEXT NULL,
  created_by INT(11) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY asset_id (asset_id),
  CONSTRAINT asset_valuations_asset FOREIGN KEY (asset_id) REFERENCES assets (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2. The opening reconciliation (posted once, by an Admin, with its supporting note).
CREATE TABLE capital_reconciliations (
  id INT(11) NOT NULL AUTO_INCREMENT,
  posted_by INT(11) NULL,
  posted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  note TEXT NOT NULL,
  summary TEXT NOT NULL,          -- every line posted, with its amount and supporting record
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
