-- MY MOTIVE SaaS, Patch 5: automatic company codes, SMS limits per package, subscription renewal notices.

-- a counter for the next company code (RMCP0002 ...). RMCP0001 is your own company.
CREATE TABLE IF NOT EXISTS platform_counters (
  name  VARCHAR(40) NOT NULL,
  value BIGINT NOT NULL DEFAULT 0,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO platform_counters (name, value) VALUES ('company_code', 1), ('daily_last_run', 0)
ON DUPLICATE KEY UPDATE name = name;

-- SMS per calendar month. NULL means no limit (your own company).
UPDATE packages SET sms_quota = 200 WHERE code = 'dream';
UPDATE packages SET sms_quota = 600 WHERE code = 'hasty';
UPDATE packages SET sms_quota = NULL WHERE code = 'owner';

-- why a company is suspended (shown in the admin panel)
ALTER TABLE companies ADD COLUMN status_note VARCHAR(255) NULL AFTER status;

-- which "your subscription ends soon" emails were already sent, so each is sent once
CREATE TABLE IF NOT EXISTS subscription_notices (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  subscription_id INT UNSIGNED NOT NULL,
  stage           VARCHAR(12)  NOT NULL,
  sent_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_notice (subscription_id, stage),
  CONSTRAINT fk_notice_sub FOREIGN KEY (subscription_id) REFERENCES subscriptions (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
