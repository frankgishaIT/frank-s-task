-- Asset codes now use the RM-AST prefix
UPDATE assets SET asset_code = CONCAT('RM-', asset_code) WHERE asset_code LIKE 'AST-%';

-- Lender Type values no longer end with "Loan"
UPDATE loans SET loan_type = REPLACE(loan_type, ' Loan', '');