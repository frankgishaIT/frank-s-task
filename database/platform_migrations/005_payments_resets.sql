-- MY MOTIVE SaaS, Patch 4: online payment (XentriPay) and password reset.

-- the secure payment link: only a fingerprint (hash) of it is stored, never the link itself
ALTER TABLE signup_requests
  ADD COLUMN pay_token_hash CHAR(64) NULL,
  ADD COLUMN pay_token_expires DATETIME NULL,
  ADD UNIQUE KEY uq_signup_paytoken (pay_token_hash);

-- every try to pay: what we asked XentriPay, and what came back
CREATE TABLE IF NOT EXISTS payment_attempts (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  signup_request_id INT UNSIGNED NOT NULL,
  customer_ref      VARCHAR(60)  NOT NULL,
  gateway_refid     VARCHAR(80)  NULL,
  msisdn            VARCHAR(15)  NOT NULL,
  amount            INT UNSIGNED NOT NULL,
  list_amount       INT UNSIGNED NOT NULL,
  status            ENUM('initiated','pending','success','failed','expired') NOT NULL DEFAULT 'initiated',
  failure_reason    VARCHAR(300) NULL,
  last_checked_at   DATETIME     NULL,
  confirmed_at      DATETIME     NULL,
  created_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_attempt_ref (customer_ref),
  KEY idx_attempt_request (signup_request_id),
  KEY idx_attempt_status (status, created_at),
  CONSTRAINT fk_attempt_request FOREIGN KEY (signup_request_id) REFERENCES signup_requests (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- "I forgot my password" links for the people of every company
CREATE TABLE IF NOT EXISTS password_resets (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  company_id  INT UNSIGNED NOT NULL,
  email       VARCHAR(150) NOT NULL,
  token_hash  CHAR(64)     NOT NULL,
  expires_at  DATETIME     NOT NULL,
  used_at     DATETIME     NULL,
  ip          VARCHAR(45)  NULL,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_reset_token (token_hash),
  KEY idx_reset_user (company_id, email),
  CONSTRAINT fk_reset_company FOREIGN KEY (company_id) REFERENCES companies (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
