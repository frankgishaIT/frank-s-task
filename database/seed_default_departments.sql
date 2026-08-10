-- Seeds the standard set of departments so they're ready to use immediately
-- when adding employees. Safe to run even if some already exist — each
-- insert only happens if that department name isn't already present.

INSERT INTO departments (name, description, is_active)
SELECT 'Human Resources (HR)', 'Hires new workers and helps staff.', 1
WHERE NOT EXISTS (SELECT 1 FROM departments WHERE name = 'Human Resources (HR)');

INSERT INTO departments (name, description, is_active)
SELECT 'Finance / Accounting', 'Handles money, budgets, and bills.', 1
WHERE NOT EXISTS (SELECT 1 FROM departments WHERE name = 'Finance / Accounting');

INSERT INTO departments (name, description, is_active)
SELECT 'Marketing', 'Shares information and advertises products.', 1
WHERE NOT EXISTS (SELECT 1 FROM departments WHERE name = 'Marketing');

INSERT INTO departments (name, description, is_active)
SELECT 'Sales', 'Sells items to make money for the group.', 1
WHERE NOT EXISTS (SELECT 1 FROM departments WHERE name = 'Sales');

INSERT INTO departments (name, description, is_active)
SELECT 'Information Technology (IT)', 'Manages computers and networks.', 1
WHERE NOT EXISTS (SELECT 1 FROM departments WHERE name = 'Information Technology (IT)');

INSERT INTO departments (name, description, is_active)
SELECT 'Operations', 'Handles daily work and production.', 1
WHERE NOT EXISTS (SELECT 1 FROM departments WHERE name = 'Operations');
