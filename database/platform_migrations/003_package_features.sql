-- MY MOTIVE SaaS, Patch 3: what each package includes.
-- Hasty includes "targets" (RM Targets) because every task belongs to a target: Tasks cannot be used without it.

ALTER TABLE packages ADD COLUMN features TEXT NULL AFTER sms_quota;

UPDATE packages SET features = '["dashboard","employees","attendance","leave","products","customers","business_parties","sales","notifications"]' WHERE code = 'dream';

UPDATE packages SET features = '["dashboard","employees","attendance","leave","targets","tasks","products","transactions","customers","business_parties","sales","loans","assets","financial_reports","payroll","notifications"]' WHERE code = 'hasty';

-- "owner" is not for sale (inactive): it is the package of your own company, with everything switched on.
INSERT INTO packages (code, name, monthly_price, yearly_discount_percent, is_active, sort_order, features) VALUES
  ('owner', 'Owner (everything)', 0, 0, 0, 99,
   '["dashboard","employees","attendance","leave","targets","tasks","transactions","funds","products","customers","business_parties","sales","loans","assets","financial_reports","payroll","notifications","sms","tools"]')
ON DUPLICATE KEY UPDATE features = VALUES(features);

-- Company 1 (RISE MOTIVE) keeps every feature, with no end date worth worrying about.
INSERT INTO subscriptions (company_id, package_id, billing_period, status, starts_at, ends_at)
SELECT c.id, p.id, 'yearly', 'active', CURDATE(), '2099-12-31'
FROM companies c JOIN packages p ON p.code = 'owner'
WHERE c.id = 1 AND NOT EXISTS (SELECT 1 FROM subscriptions s WHERE s.company_id = c.id);
