-- MY MOTIVE SaaS: each company's own identity (shown on its invoices, receipts, reports and SMS) and its own payment methods.
-- One row in company_profile (id = 1). Empty fields are simply not printed.

CREATE TABLE IF NOT EXISTS company_profile (
  id                   TINYINT UNSIGNED NOT NULL DEFAULT 1,
  display_name         VARCHAR(150) NULL,
  tin                  VARCHAR(30)  NULL,
  website              VARCHAR(150) NULL,
  address              VARCHAR(255) NULL,
  phone                VARCHAR(30)  NULL,
  email                VARCHAR(150) NULL,
  momo_phone           VARCHAR(30)  NULL,
  help_phone           VARCHAR(30)  NULL,
  invoice_prefix       VARCHAR(8)   NOT NULL DEFAULT 'INV',
  logo_file            VARCHAR(120) NULL,
  sms_credit_reminders TINYINT(1)   NOT NULL DEFAULT 1,
  sms_loyalty_weekly   TINYINT(1)   NOT NULL DEFAULT 0,
  auto_credit_charges  TINYINT(1)   NOT NULL DEFAULT 0,
  updated_at           TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS company_payment_methods (
  id             INT NOT NULL AUTO_INCREMENT,
  method_name    VARCHAR(80)  NOT NULL,
  account_label  VARCHAR(60)  NULL,
  account_number VARCHAR(60)  NOT NULL,
  account_name   VARCHAR(100) NULL,
  sort_order     INT NOT NULL DEFAULT 0,
  is_active      TINYINT(1) NOT NULL DEFAULT 1,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
