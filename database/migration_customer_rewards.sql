-- Run once in phpMyAdmin (local AND live database).
-- Customer Rewards: configurable reward types and the reward records.

CREATE TABLE reward_types (
  id INT(11) NOT NULL AUTO_INCREMENT,
  name VARCHAR(80) NOT NULL,
  pays_money TINYINT(1) NOT NULL DEFAULT 0,   -- 1 = money is paid out (from the RM Business Operating Fund)
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT(11) NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO reward_types (name, pays_money, is_active, sort_order) VALUES
  ('Cash Reward', 1, 1, 1),
  ('Discount Voucher', 0, 1, 2),
  ('Airtime', 1, 1, 3),
  ('Free Product', 0, 1, 4),
  ('Free Service', 0, 1, 5),
  ('Free or Subsidized Delivery', 1, 1, 6),
  ('Wallet Credit', 0, 0, 7),          -- off: MY MOTIVE has no customer wallet yet
  ('Other Reward', 0, 1, 8);

CREATE TABLE customer_rewards (
  id INT(11) NOT NULL AUTO_INCREMENT,
  customer_id INT(11) NOT NULL,
  reward_type_id INT(11) NOT NULL,
  reward_details VARCHAR(255) NOT NULL,          -- "Rewards Given"
  reward_amount DECIMAL(12,2) NOT NULL DEFAULT 0, -- value in RWF
  points_required INT(11) NOT NULL,
  points_redeemed INT(11) NOT NULL,
  points_before INT(11) NOT NULL,                 -- customer's points before this reward
  points_after INT(11) NOT NULL,                  -- "Remaining Points"
  status ENUM('Pending','Fulfilled','Cancelled','Reversed') NOT NULL DEFAULT 'Pending',
  payment_status ENUM('Not applicable','Unpaid','Paid','Refunded') NOT NULL DEFAULT 'Not applicable',
  voucher_code VARCHAR(30) DEFAULT NULL,
  date_earned DATE NOT NULL,
  fulfilled_at DATETIME DEFAULT NULL,
  processed_by INT(11) DEFAULT NULL,
  approved_by INT(11) DEFAULT NULL,
  approved_at DATETIME DEFAULT NULL,
  cancelled_by INT(11) DEFAULT NULL,
  cancelled_at DATETIME DEFAULT NULL,
  cancel_reason VARCHAR(255) DEFAULT NULL,
  evidence_file VARCHAR(100) DEFAULT NULL,
  notes TEXT DEFAULT NULL,
  transaction_id INT(11) DEFAULT NULL,            -- the Operating Fund expense, for paid rewards
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY customer_id (customer_id),
  KEY status (status),
  CONSTRAINT customer_rewards_customer FOREIGN KEY (customer_id) REFERENCES customers (id),
  CONSTRAINT customer_rewards_type FOREIGN KEY (reward_type_id) REFERENCES reward_types (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
