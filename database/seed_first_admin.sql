-- Run this ONCE to create your very first Admin account.
-- Without this, nobody can log in to reach the Employees page and add more users
-- (since that page now requires an Admin to already be logged in).

-- 1. Make sure at least one department exists (skip if you already have one).
INSERT INTO departments (name, description, is_active)
VALUES ('Administration', 'Default administration department', 1);

-- 2. Create the first Admin account, attached to the department above.
--    Login email:    admin@rmos.local
--    Login password: Admin@123
INSERT INTO users (names, email, password_hash, role, department_id, monthly_salary, is_active)
VALUES (
    'Super Admin',
    'admin@rmos.local',
    '$2y$10$OUoBncB9enYkktl7JcNOv..qc3Gdmhrm4xSciVGGeBsDa33p/1ORC',
    'Admin',
    (SELECT id FROM departments WHERE name = 'Administration' LIMIT 1),
    0,
    1
);

-- IMPORTANT: After you log in successfully with the account above,
-- go to your Employee profile and change the password to something only you know.
