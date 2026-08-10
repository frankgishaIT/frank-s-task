-- Upgrades the attendance table to support the self-service clock in/out workflow
-- described in the MY MOTIVE spec: Clock In -> Manager Approval -> Working ->
-- Clock Out -> Manager Confirmation -> Payroll Updated.
-- Run this AFTER leave_requests.sql and seed_first_admin.sql.

ALTER TABLE attendance
    ADD COLUMN workflow_status ENUM(
        'Pending Clock-In Approval',
        'Working',
        'Pending Clock-Out Confirmation',
        'Confirmed',
        'Rejected'
    ) NOT NULL DEFAULT 'Confirmed' AFTER status,
    ADD COLUMN manager_id INT NULL AFTER workflow_status,
    ADD COLUMN manager_note VARCHAR(255) NULL AFTER manager_id,
    ADD COLUMN confirmed_by INT NULL AFTER manager_note,
    ADD COLUMN confirmed_at DATETIME NULL AFTER confirmed_by,
    ADD COLUMN late_minutes INT NOT NULL DEFAULT 0 AFTER confirmed_at,
    ADD COLUMN overtime_minutes INT NOT NULL DEFAULT 0 AFTER late_minutes,
    ADD COLUMN worked_minutes INT NOT NULL DEFAULT 0 AFTER overtime_minutes,
    ADD CONSTRAINT fk_attendance_manager FOREIGN KEY (manager_id) REFERENCES users(id),
    ADD CONSTRAINT fk_attendance_confirmed_by FOREIGN KEY (confirmed_by) REFERENCES users(id);

-- Existing manually-entered records are treated as already-confirmed history
-- (default 'Confirmed' above handles this automatically).
