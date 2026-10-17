-- MY MOTIVE SaaS, Patch 2: platform admins, packages, sign-up requests, subscriptions, payments, owner invites.

CREATE TABLE IF NOT EXISTS platform_admins (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email                VARCHAR(150) NOT NULL,
  name                 VARCHAR(150) NOT NULL,
  password_hash        VARCHAR(255) NOT NULL,
  is_active            TINYINT(1)   NOT NULL DEFAULT 1,
  must_change_password TINYINT(1)   NOT NULL DEFAULT 1,
  last_login_at        DATETIME     NULL,
  created_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pa_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS packages (
  id                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  code                    VARCHAR(30)  NOT NULL,
  name                    VARCHAR(60)  NOT NULL,
  monthly_price           INT UNSIGNED NOT NULL,
  yearly_discount_percent TINYINT UNSIGNED NOT NULL DEFAULT 5,
  max_users               INT UNSIGNED NULL,
  sms_quota               INT UNSIGNED NULL,
  is_active               TINYINT(1)   NOT NULL DEFAULT 1,
  sort_order              INT          NOT NULL DEFAULT 0,
  PRIMARY KEY (id),
  UNIQUE KEY uq_pkg_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO packages (code, name, monthly_price, yearly_discount_percent, sort_order) VALUES
  ('dream', 'Dream', 15000, 5, 1),
  ('hasty', 'Hasty', 40000, 5, 2)
ON DUPLICATE KEY UPDATE code = code;

CREATE TABLE IF NOT EXISTS signup_requests (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  reference         VARCHAR(20)  NOT NULL,
  company_name      VARCHAR(150) NOT NULL,
  owner_name        VARCHAR(150) NOT NULL,
  owner_email       VARCHAR(150) NOT NULL,
  owner_phone       VARCHAR(30)  NOT NULL,
  tin               VARCHAR(30)  NULL,
  package_id        INT UNSIGNED NOT NULL,
  billing_period    ENUM('monthly','yearly') NOT NULL,
  amount_due        INT UNSIGNED NOT NULL,
  status            ENUM('pending','approved','rejected','activating','provisioning','active','failed') NOT NULL DEFAULT 'pending',
  company_code      VARCHAR(30)  NULL,
  reject_reason     VARCHAR(500) NULL,
  last_error        TEXT         NULL,
  company_id        INT UNSIGNED NULL,
  terms_accepted_at DATETIME     NOT NULL,
  ip                VARCHAR(45)  NULL,
  reviewed_by       VARCHAR(150) NULL,
  reviewed_at       DATETIME     NULL,
  invite_sent_at    DATETIME     NULL,
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_signup_reference (reference),
  KEY idx_signup_status (status),
  KEY idx_signup_email (owner_email),
  CONSTRAINT fk_signup_package FOREIGN KEY (package_id) REFERENCES packages (id),
  CONSTRAINT fk_signup_company FOREIGN KEY (company_id) REFERENCES companies (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS subscriptions (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id        INT UNSIGNED NOT NULL,
  package_id        INT UNSIGNED NOT NULL,
  billing_period    ENUM('monthly','yearly') NOT NULL,
  status            ENUM('active','expired','cancelled') NOT NULL DEFAULT 'active',
  starts_at         DATE NOT NULL,
  ends_at           DATE NOT NULL,
  signup_request_id INT UNSIGNED NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_sub_company (company_id, status),
  CONSTRAINT fk_sub_company FOREIGN KEY (company_id) REFERENCES companies (id),
  CONSTRAINT fk_sub_package FOREIGN KEY (package_id) REFERENCES packages (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payments (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  signup_request_id INT UNSIGNED NULL,
  company_id        INT UNSIGNED NULL,
  amount            INT UNSIGNED NOT NULL,
  method            VARCHAR(30)  NOT NULL,
  reference         VARCHAR(100) NOT NULL,
  recorded_by       VARCHAR(150) NOT NULL,
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_pay_request (signup_request_id),
  KEY idx_pay_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS owner_invites (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id  INT UNSIGNED NOT NULL,
  email       VARCHAR(150) NOT NULL,
  token_hash  CHAR(64)     NOT NULL,
  expires_at  DATETIME     NOT NULL,
  used_at     DATETIME     NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_invite_token (token_hash),
  KEY idx_invite_company (company_id),
  CONSTRAINT fk_invite_company FOREIGN KEY (company_id) REFERENCES companies (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS platform_rate_limits (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  bucket      VARCHAR(30)  NOT NULL,
  subject     VARCHAR(150) NOT NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rate (bucket, subject, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
