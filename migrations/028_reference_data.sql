-- Starter data every company needs. It was inserted by older migrations (009, 017, 026), which a NEW company
-- never ran, because its database is built from the finished structure.
-- Safe to run on any database, including one that already has this data: every row is "insert if missing"
-- (the tables have UNIQUE keys on name / code), and existing rows, including edited percentages, are not touched.

INSERT IGNORE INTO units (name) VALUES
('Pieces'), ('Boxes'), ('Cartons'), ('Packets'), ('Packs'), ('Bundles'),
('Dozens'), ('Pairs'), ('Rolls'), ('Reams'), ('Bags'), ('Sacks'),
('Kilograms'), ('Grams'), ('Liters'), ('Meters'), ('Centimeters');

INSERT IGNORE INTO funds (code, name, allocation_percent, sort_order) VALUES
('OPERATING',    'RM Business Operating Fund', 75,   1),
('FUTURE_PLANS', 'RM Future Plans Fund',       20,   2),
('EMERGENCY',    'RM Emergency Fund',           3,   3),
('TEAM_GROWTH',  'RM Team Growth Fund',         2,   4),
('CAPITAL',      'RM Capital Fund',          NULL,   5);

INSERT IGNORE INTO reward_types (name, pays_money, is_active, sort_order) VALUES
('Cash Reward', 1, 1, 1),
('Discount Voucher', 0, 1, 2),
('Airtime', 1, 1, 3),
('Free Product', 0, 1, 4),
('Free Service', 0, 1, 5),
('Free or Subsidized Delivery', 1, 1, 6),
('Wallet Credit', 0, 0, 7),
('Other Reward', 0, 1, 8);
