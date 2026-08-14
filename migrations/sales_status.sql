ALTER TABLE sales MODIFY COLUMN status
    ENUM('Pending Discount Approval','Paid','Partially Paid','Credit','Cancelled') NOT NULL;