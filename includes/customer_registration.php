<?php
/**
 * NEW FILE: includes/customer_registration.php — ORIGINAL REGISTRATION BRANCH of every customer (permanent record).
 * customers stay SHARED: every branch can find and serve every customer. This record only remembers which branch first
 * registered the customer, when, and by whom. It never changes when the customer is served elsewhere.
 *   - New customers: recorded at registration (branch the staff member worked in, date, staff member).
 *   - Historical customers: the branch MY MOTIVE recorded at creation is used when it is reliable (created after branch
 *     tracking started). Customers created BEFORE branch tracking were attached to the Main Branch by the setup, not
 *     observed: they are flagged "To review" with that suggestion, never silently assigned.
 *   - Only Admins can confirm or correct a record; every change keeps the previous value, the reason, who and when.
 */
require_once __DIR__ . '/branch_helpers.php';

function cr_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    try {
        mysqli_query($conn, 'CREATE TABLE IF NOT EXISTS customer_registrations (customer_id INT(11) NOT NULL, branch_id INT(11) NULL, suggested_branch_id INT(11) NULL,
            registered_at DATETIME NULL, registered_by INT(11) NULL, basis VARCHAR(160) NOT NULL, status VARCHAR(10) NOT NULL DEFAULT \'confirmed\',
            reviewed_by INT(11) NULL, reviewed_at DATETIME NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (customer_id), KEY branch (branch_id), KEY status (status)) ENGINE=InnoDB');
        mysqli_query($conn, 'CREATE TABLE IF NOT EXISTS customer_registration_log (id INT(11) NOT NULL AUTO_INCREMENT, customer_id INT(11) NOT NULL, action VARCHAR(20) NOT NULL,
            old_value VARCHAR(200) NULL, new_value VARCHAR(200) NULL, reason VARCHAR(300) NULL, user_id INT(11) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), KEY customer (customer_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    } catch (Throwable $e) { error_log('cr_ensure: ' . $e->getMessage()); }
}

/** When branch tracking started (the Main Branch was registered). Null = branch management not set up. */
function cr_tracking_start(mysqli $conn): ?string
{
    if (!br_enabled($conn)) { return null; }
    $r = mysqli_fetch_row(mysqli_query($conn, 'SELECT created_at FROM branches WHERE id = ' . br_main_id($conn)));
    return $r[0] ?? null;
}

/** Records a NEW customer's registration (call right after the customer is saved and stamped). Never overwrites. */
function cr_record_new(mysqli $conn, int $customerId, ?int $userId, string $basis = 'Recorded when the customer was registered'): void
{
    cr_ensure($conn);
    try {
        $b = br_enabled($conn) ? br_of($conn, 'customers', $customerId) : null;
        $now = date('Y-m-d H:i:s');
        $s = mysqli_prepare($conn, "INSERT IGNORE INTO customer_registrations (customer_id, branch_id, registered_at, registered_by, basis, status) VALUES (?, ?, ?, ?, ?, 'confirmed')");
        mysqli_stmt_bind_param($s, 'iisis', $customerId, $b, $now, $userId, $basis);
        mysqli_stmt_execute($s);
    } catch (Throwable $e) { error_log('cr_record_new: ' . $e->getMessage()); }
}

/** Creates the missing records of existing customers (once per customer; nothing existing is overwritten). */
function cr_backfill(mysqli $conn): int
{
    cr_ensure($conn);
    $start = cr_tracking_start($conn);
    $n = 0;
    $rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT c.id, c.created_at, c.created_branch_id FROM customers c LEFT JOIN customer_registrations r ON r.customer_id = c.id WHERE r.customer_id IS NULL LIMIT 5000'), MYSQLI_ASSOC);
    foreach ($rows as $c) {
        $cid = (int) $c['id']; $at = $c['created_at'] ?: null; $b = $c['created_branch_id'] !== null ? (int) $c['created_branch_id'] : null;
        if ($start === null) {           // single-branch company: nothing to decide yet
            $branch = null; $sug = null; $status = 'review'; $basis = 'Branch management not set up yet';
        } elseif ($b !== null && $at !== null && $at >= $start) {
            $branch = $b; $sug = null; $status = 'confirmed'; $basis = 'Branch recorded by MY MOTIVE when the customer was created';
        } else {
            $branch = null; $sug = $b; $status = 'review';
            $basis = 'Registered before branch tracking started (' . substr((string) $start, 0, 10) . '); the setup attached the customer to the Main Branch';
        }
        $s = mysqli_prepare($conn, 'INSERT IGNORE INTO customer_registrations (customer_id, branch_id, suggested_branch_id, registered_at, basis, status) VALUES (?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($s, 'iiisss', $cid, $branch, $sug, $at, $basis, $status);
        mysqli_stmt_execute($s); $n++;
    }
    return $n;
}

function cr_get(mysqli $conn, int $customerId): ?array
{
    cr_ensure($conn);
    $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT r.*, u.names registered_by_name, v.names reviewed_by_name FROM customer_registrations r LEFT JOIN users u ON u.id = r.registered_by
        LEFT JOIN users v ON v.id = r.reviewed_by WHERE r.customer_id = $customerId"));
    if (!$r) { cr_backfill($conn); $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT r.*, NULL registered_by_name, NULL reviewed_by_name FROM customer_registrations r WHERE r.customer_id = $customerId")); }
    return $r ?: null;
}

function cr_branch_name(mysqli $conn, ?int $branchId): string
{
    if ($branchId === null) { return 'Not established'; }
    $b = br_get($conn, $branchId);
    return $b['name'] ?? ('Branch #' . $branchId);
}

/**
 * Admin only: confirm or correct the original registration branch (and date) with a reason. Keeps the old value in the log.
 */
function cr_set(mysqli $conn, int $customerId, ?int $branchId, ?string $date, string $reason, string $action = 'correct'): void
{
    if (current_user_role() !== 'Admin') { throw new RuntimeException('Only an Admin can change the original registration branch.'); }
    if (mb_strlen(trim($reason)) < 5) { throw new RuntimeException('Give the reason (where the information comes from).'); }
    if ($branchId === null || !br_get($conn, $branchId)) { throw new RuntimeException('Choose the branch.'); }
    if ($date !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { throw new RuntimeException('The date is not valid.'); }
    $old = cr_get($conn, $customerId);
    if (!$old) { throw new RuntimeException('Customer not found.'); }
    $uid = (int) current_user_id(); $now = date('Y-m-d H:i:s');
    $at = $date !== null ? $date . ' ' . (substr((string) $old['registered_at'], 11, 8) ?: '00:00:00') : $old['registered_at'];
    $basis = mb_substr(($action === 'confirm' ? 'Confirmed by an Admin: ' : 'Corrected by an Admin: ') . trim($reason), 0, 160);
    $s = mysqli_prepare($conn, "UPDATE customer_registrations SET branch_id = ?, registered_at = ?, basis = ?, status = 'confirmed', reviewed_by = ?, reviewed_at = ? WHERE customer_id = ?");
    mysqli_stmt_bind_param($s, 'issisi', $branchId, $at, $basis, $uid, $now, $customerId);
    mysqli_stmt_execute($s);
    $ov = cr_branch_name($conn, $old['branch_id'] !== null ? (int) $old['branch_id'] : null) . ' · ' . substr((string) $old['registered_at'], 0, 10) . ' · ' . $old['status'];
    $nv = cr_branch_name($conn, $branchId) . ' · ' . substr((string) $at, 0, 10) . ' · confirmed';
    $r = mb_substr(trim($reason), 0, 300);
    $s = mysqli_prepare($conn, 'INSERT INTO customer_registration_log (customer_id, action, old_value, new_value, reason, user_id) VALUES (?, ?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($s, 'issssi', $customerId, $action, $ov, $nv, $r, $uid);
    mysqli_stmt_execute($s);
}