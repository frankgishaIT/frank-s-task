-- Adds a photo column to users, if it doesn't already exist.
-- If you get "Duplicate column name" running this, the column already
-- exists and you can safely ignore the error / skip this file.
ALTER TABLE users
    ADD COLUMN photo VARCHAR(255) DEFAULT NULL AFTER phone;