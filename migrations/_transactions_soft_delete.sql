-- Run once in phpMyAdmin BEFORE pasting the new transactions delete.php.
-- Deleted transactions are kept (status = 'deleted') with who deleted them and when.
ALTER TABLE transactions
  ADD COLUMN deleted_by INT(11) DEFAULT NULL,
  ADD COLUMN deleted_at DATETIME DEFAULT NULL;

-- Also recommended earlier: approved_by must not block inserts on strict MySQL servers.
ALTER TABLE transactions MODIFY approved_by INT(11) DEFAULT NULL;