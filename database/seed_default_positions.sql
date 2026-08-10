-- Seeds typical roles/positions under each of the standard departments.
-- Run this AFTER seed_default_departments.sql (it looks departments up by name).
-- Safe to re-run: each role is only inserted if it doesn't already exist
-- under that department.

-- Human Resources (HR)
INSERT INTO positions (department_id, title, description, is_active)
SELECT d.id, 'Recruiter', 'Finds and hires new staff.', 1
FROM departments d WHERE d.name = 'Human Resources (HR)'
AND NOT EXISTS (SELECT 1 FROM positions p WHERE p.department_id = d.id AND p.title = 'Recruiter');

INSERT INTO positions (department_id, title, description, is_active)
SELECT d.id, 'HR Officer', 'Supports staff welfare, leave, and HR records.', 1
FROM departments d WHERE d.name = 'Human Resources (HR)'
AND NOT EXISTS (SELECT 1 FROM positions p WHERE p.department_id = d.id AND p.title = 'HR Officer');

-- Finance / Accounting
INSERT INTO positions (department_id, title, description, is_active)
SELECT d.id, 'Accountant', 'Manages accounts, budgets, and bills.', 1
FROM departments d WHERE d.name = 'Finance / Accounting'
AND NOT EXISTS (SELECT 1 FROM positions p WHERE p.department_id = d.id AND p.title = 'Accountant');

INSERT INTO positions (department_id, title, description, is_active)
SELECT d.id, 'Payroll Officer', 'Processes employee salaries and payments.', 1
FROM departments d WHERE d.name = 'Finance / Accounting'
AND NOT EXISTS (SELECT 1 FROM positions p WHERE p.department_id = d.id AND p.title = 'Payroll Officer');

-- Marketing
INSERT INTO positions (department_id, title, description, is_active)
SELECT d.id, 'Marketing Officer', 'Promotes products and manages campaigns.', 1
FROM departments d WHERE d.name = 'Marketing'
AND NOT EXISTS (SELECT 1 FROM positions p WHERE p.department_id = d.id AND p.title = 'Marketing Officer');

INSERT INTO positions (department_id, title, description, is_active)
SELECT d.id, 'Content Creator', 'Produces promotional content and materials.', 1
FROM departments d WHERE d.name = 'Marketing'
AND NOT EXISTS (SELECT 1 FROM positions p WHERE p.department_id = d.id AND p.title = 'Content Creator');

-- Sales
INSERT INTO positions (department_id, title, description, is_active)
SELECT d.id, 'Sales Officer', 'Sells products and services to customers.', 1
FROM departments d WHERE d.name = 'Sales'
AND NOT EXISTS (SELECT 1 FROM positions p WHERE p.department_id = d.id AND p.title = 'Sales Officer');

INSERT INTO positions (department_id, title, description, is_active)
SELECT d.id, 'Cashier', 'Handles payments and receipts at point of sale.', 1
FROM departments d WHERE d.name = 'Sales'
AND NOT EXISTS (SELECT 1 FROM positions p WHERE p.department_id = d.id AND p.title = 'Cashier');

-- Information Technology (IT)
INSERT INTO positions (department_id, title, description, is_active)
SELECT d.id, 'Network Administrator', 'Maintains computers, networks, and systems.', 1
FROM departments d WHERE d.name = 'Information Technology (IT)'
AND NOT EXISTS (SELECT 1 FROM positions p WHERE p.department_id = d.id AND p.title = 'Network Administrator');

INSERT INTO positions (department_id, title, description, is_active)
SELECT d.id, 'Support Technician', 'Provides technical support to staff.', 1
FROM departments d WHERE d.name = 'Information Technology (IT)'
AND NOT EXISTS (SELECT 1 FROM positions p WHERE p.department_id = d.id AND p.title = 'Support Technician');

-- Operations
INSERT INTO positions (department_id, title, description, is_active)
SELECT d.id, 'Operations Officer', 'Oversees daily work and production.', 1
FROM departments d WHERE d.name = 'Operations'
AND NOT EXISTS (SELECT 1 FROM positions p WHERE p.department_id = d.id AND p.title = 'Operations Officer');

INSERT INTO positions (department_id, title, description, is_active)
SELECT d.id, 'Storekeeper', 'Manages stock and inventory movement.', 1
FROM departments d WHERE d.name = 'Operations'
AND NOT EXISTS (SELECT 1 FROM positions p WHERE p.department_id = d.id AND p.title = 'Storekeeper');
