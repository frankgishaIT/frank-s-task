-- MY MOTIVE SaaS, Patch 3: a record of every email the platform sends (or would send in test mode).
CREATE TABLE IF NOT EXISTS platform_emails (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  to_email    VARCHAR(150) NOT NULL,
  to_name     VARCHAR(150) NULL,
  subject     VARCHAR(250) NOT NULL,
  status      ENUM('sent','failed','dry_run') NOT NULL,
  error       VARCHAR(500) NULL,
  body_html   MEDIUMTEXT NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_email_created (created_at),
  KEY idx_email_status (status, created_at),
  KEY idx_email_to (to_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
