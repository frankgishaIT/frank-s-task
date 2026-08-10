-- Upgrades the tasks table to match the Task & Performance Management workflow
-- in the MY MOTIVE spec:
-- Pending -> Accepted -> In Progress -> Completed -> Approved -> Scored
-- Run this AFTER attendance_workflow.sql.

ALTER TABLE tasks
    MODIFY COLUMN status ENUM(
        'Pending',
        'Accepted',
        'In Progress',
        'Completed',
        'Approved',
        'Rejected'
    ) NOT NULL DEFAULT 'Pending',
    ADD COLUMN accepted_at DATETIME NULL AFTER status,
    ADD COLUMN submitted_at DATETIME NULL AFTER accepted_at,
    ADD COLUMN completion_note TEXT NULL AFTER submitted_at,
    ADD COLUMN reviewed_by INT NULL AFTER completion_note,
    ADD COLUMN review_note TEXT NULL AFTER reviewed_by,
    ADD COLUMN performance_score TINYINT NULL AFTER review_note,
    ADD CONSTRAINT fk_tasks_reviewed_by FOREIGN KEY (reviewed_by) REFERENCES users(id);
