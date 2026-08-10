-- Leave Management + Approval Engine (first module of the workflow/approval system)
-- Run this against your rm_os_db database.

CREATE TABLE IF NOT EXISTS leave_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,                         -- requester (employee)
    leave_type ENUM('Annual', 'Sick', 'Emergency', 'Unpaid', 'Other') NOT NULL,
    start_date DATE NOT NULL,
    end_date DATE NOT NULL,
    reason TEXT NULL,

    status ENUM('Pending', 'Manager Approved', 'Approved', 'Rejected') NOT NULL DEFAULT 'Pending',

    manager_id INT NULL,                          -- who acted at the supervisor stage
    manager_comment TEXT NULL,
    manager_acted_at DATETIME NULL,

    hr_id INT NULL,                                -- who gave the final (HR/Admin) decision
    hr_comment TEXT NULL,
    hr_acted_at DATETIME NULL,

    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_leave_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_leave_manager FOREIGN KEY (manager_id) REFERENCES users(id),
    CONSTRAINT fk_leave_hr FOREIGN KEY (hr_id) REFERENCES users(id)
);
