<?php
/**
 * NEW FILE: includes/ebm_helpers.php — REQUEST EBM RECEIPT (customer asks for the official RRA EBM receipt of an invoice).
 * Each request is linked to the customer and to their original RISE MOTIVE invoice; one open / issued request per invoice.
 * Staff take it in hand, attach the issued EBM receipt, or reject it with the reason. The customer follows it in the portal.
 */
const EBM_RM_TIN = '122923513';
const EBM_STATUSES = ['Submitted', 'In progress', 'Issued', 'Rejected'];

function ebm_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS ebm_requests (id INT(11) NOT NULL AUTO_INCREMENT, customer_id INT(11) NOT NULL, sale_id INT(11) NOT NULL,
        has_tin TINYINT(1) NOT NULL, purchase_code VARCHAR(40) NULL, tin VARCHAR(20) NULL, full_name VARCHAR(160) NOT NULL, phone VARCHAR(20) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'Submitted', staff_note VARCHAR(500) NULL, receipt_ref VARCHAR(30) NULL, receipt_name VARCHAR(255) NULL,
        processed_by INT(11) NULL, processed_at DATETIME NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL,
        PRIMARY KEY (id), KEY customer_id (customer_id), KEY sale_id (sale_id), KEY status (status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ebm_ref(int $id): string { return 'EBM-' . str_pad((string) $id, 6, '0', STR_PAD_LEFT); }

/** The open or issued request of an invoice (a rejected one may be requested again), or null. */
function ebm_existing(mysqli $conn, int $saleId): ?array
{
    return mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM ebm_requests WHERE sale_id = $saleId AND status <> 'Rejected' ORDER BY id DESC LIMIT 1")) ?: null;
}

/** Checks a request; returns the list of problems (empty = valid). */
function ebm_validate(array $d): array
{
    $err = [];
    if ($d['has_tin'] === null) { $err[] = 'Say whether you have a TIN.'; return $err; }
    if (mb_strlen($d['full_name']) < 3) { $err[] = 'Enter the full name to appear on the EBM receipt.'; }
    if ($d['invoice'] === '') { $err[] = 'Enter your RM invoice number (e.g. RM001208).'; }
    if ($d['has_tin']) {
        if (!preg_match('/^\d{9}$/', $d['tin'])) { $err[] = 'The TIN must have 9 digits.'; }
        if (!preg_match('/^[A-Za-z0-9-]{4,40}$/', $d['purchase_code'])) { $err[] = 'Enter the purchase code (letters and digits).'; }
    } else {
        $digits = preg_replace('/\D/', '', $d['phone']);
        if (!preg_match('/^(?:250)?0?7\d{8}$/', $digits)) { $err[] = 'Enter a valid telephone number (e.g. 0788123456).'; }
    }
    return $err;
}