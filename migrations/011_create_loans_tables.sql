-- Loan Management module
-- Interest is calculated on a REDUCING BALANCE basis (standard amortization,
-- like a bank loan): each installment's interest is charged on whatever
-- principal is still outstanding at that point in the schedule, not on the
-- original loan amount. The schedule (and each installment's principal /
-- interest split) is computed once, at loan creation.

CREATE TABLE IF NOT EXISTS loans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lender VARCHAR(150) NOT NULL,
    loan_type VARCHAR(100) NOT NULL,              -- Bank, Financial Institution, Company, Individual, etc.
    loan_amount DECIMAL(14,2) NOT NULL,            -- principal
    interest_rate DECIMAL(6,3) NOT NULL,           -- annual %, e.g. 12.500
    loan_start_date DATE NOT NULL,
    repayment_period INT NOT NULL,                 -- number of installments
    repayment_frequency ENUM('Weekly','Monthly','Quarterly','Annually') NOT NULL DEFAULT 'Monthly',
    installment_amount DECIMAL(14,2) NULL,         -- optional; auto-calculated (EMI) if left blank
    maturity_date DATE NOT NULL,
    loan_purpose TEXT NULL,
    collateral TEXT NULL,
    status ENUM('Active','Fully Paid','Defaulted','Cancelled') NOT NULL DEFAULT 'Active',

    -- Running totals, kept in sync by includes/loan_helpers.php on every
    -- payment so reports never need to re-sum loan_payments live.
    total_interest DECIMAL(14,2) NOT NULL DEFAULT 0,      -- interest over the full schedule
    total_payable DECIMAL(14,2) NOT NULL DEFAULT 0,       -- loan_amount + total_interest
    principal_repaid DECIMAL(14,2) NOT NULL DEFAULT 0,
    interest_paid DECIMAL(14,2) NOT NULL DEFAULT 0,
    total_paid DECIMAL(14,2) NOT NULL DEFAULT 0,
    outstanding_balance DECIMAL(14,2) NOT NULL DEFAULT 0,

    created_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

-- The auto-generated repayment schedule (one row per installment).
CREATE TABLE IF NOT EXISTS loan_schedule (
    id INT AUTO_INCREMENT PRIMARY KEY,
    loan_id INT NOT NULL,
    installment_no INT NOT NULL,
    due_date DATE NOT NULL,
    principal_due DECIMAL(14,2) NOT NULL,
    interest_due DECIMAL(14,2) NOT NULL,
    amount_due DECIMAL(14,2) NOT NULL,
    paid_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    status ENUM('Pending','Partial','Paid') NOT NULL DEFAULT 'Pending',
    FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE
);

-- Append-only payment ledger. Rows are never deleted or edited once saved,
-- so this is always the full, honest history of what was actually paid.
CREATE TABLE IF NOT EXISTS loan_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    loan_id INT NOT NULL,
    payment_date DATE NOT NULL,
    amount DECIMAL(14,2) NOT NULL,
    principal_portion DECIMAL(14,2) NOT NULL,
    interest_portion DECIMAL(14,2) NOT NULL,
    remaining_balance DECIMAL(14,2) NOT NULL,      -- outstanding_balance AFTER this payment
    notes TEXT NULL,
    recorded_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (loan_id) REFERENCES loans(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
);