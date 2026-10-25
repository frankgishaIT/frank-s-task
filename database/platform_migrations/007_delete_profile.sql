-- Patch 9: a business can be marked "deleting" (the worker then removes it), and admins get a profile.
ALTER TABLE companies MODIFY status ENUM('pending','active','suspended','deleting') NOT NULL DEFAULT 'pending';
ALTER TABLE platform_admins ADD COLUMN phone VARCHAR(30) NULL, ADD COLUMN job_title VARCHAR(80) NULL, ADD COLUMN avatar_file VARCHAR(120) NULL;
