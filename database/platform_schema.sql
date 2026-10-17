-- MY MOTIVE SaaS: the PLATFORM database.
-- It knows every company (its code, its own database, its status). It holds NO company data.
-- Each company's real data lives in that company's own database (made from company_template.sql).

CREATE TABLE IF NOT EXISTS companies (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_code  VARCHAR(30)  NOT NULL,
  name          VARCHAR(150) NOT NULL,
  db_host       VARCHAR(100) NOT NULL DEFAULT 'localhost',
  db_name       VARCHAR(64)  NOT NULL,
  db_user       VARCHAR(32)  NOT NULL,
  db_pass_enc   TEXT         NOT NULL,
  status        ENUM('pending','active','suspended') NOT NULL DEFAULT 'pending',
  owner_name    VARCHAR(150) NULL,
  owner_email   VARCHAR(150) NULL,
  owner_phone   VARCHAR(30)  NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_company_code (company_code),
  UNIQUE KEY uq_company_db (db_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS platform_audit_log (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id  INT UNSIGNED NULL,
  actor       VARCHAR(100) NOT NULL,
  action      VARCHAR(60)  NOT NULL,
  details     TEXT NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_company (company_id),
  KEY idx_audit_action (action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
