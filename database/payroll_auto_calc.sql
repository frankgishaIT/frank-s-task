-- Upgrades payroll to auto-calculate from Attendance and Task Performance,
-- per the MY MOTIVE spec: "Payroll is automatically calculated using:
-- Attendance, Task Performance, Sales Commission, Bonuses, Deductions"
-- Run this AFTER task_workflow.sql.

ALTER TABLE payroll
    ADD COLUMN present_days INT NOT NULL DEFAULT 0 AFTER basic_salary,
    ADD COLUMN absent_days INT NOT NULL DEFAULT 0 AFTER present_days,
    ADD COLUMN overtime_minutes INT NOT NULL DEFAULT 0 AFTER absent_days,
    ADD COLUMN attendance_deduction DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER overtime_minutes,
    ADD COLUMN overtime_pay DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER attendance_deduction,
    ADD COLUMN avg_performance_score DECIMAL(5,2) NULL AFTER overtime_pay,
    ADD COLUMN performance_bonus DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER avg_performance_score,
    ADD COLUMN sales_commission DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER performance_bonus;

-- Also record when a task was reviewed, so payroll can match performance
-- scores to the correct pay period (falls back to submitted_at if this is
-- ever null on older rows).
ALTER TABLE tasks
    ADD COLUMN reviewed_at DATETIME NULL AFTER reviewed_by;
