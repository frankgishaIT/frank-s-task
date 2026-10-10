-- Run once in phpMyAdmin. Every SMS attempt (sent, failed or skipped) is recorded here.
CREATE TABLE sms_log (
  id INT(11) NOT NULL AUTO_INCREMENT,
  phone VARCHAR(30) DEFAULT NULL,
  message TEXT NOT NULL,
  event VARCHAR(50) NOT NULL,              -- what triggered it, e.g. SALE_RECEIPT, TEST
  ref_type VARCHAR(30) DEFAULT NULL,       -- e.g. SALE, LOAN
  ref_id INT(11) DEFAULT NULL,
  status ENUM('sent','failed','skipped') NOT NULL,
  response TEXT DEFAULT NULL,              -- Pindo's reply
  error VARCHAR(500) DEFAULT NULL,
  created_by INT(11) DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY event_ref (event, ref_type, ref_id),
  KEY created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;