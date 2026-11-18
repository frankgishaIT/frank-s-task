<?php
/**
 * NEW FILE: includes/sms_messages.php
 * The SMS messages from the "MY MOTIVE SMS" document, and when each one is sent.
 * CHANGED (Task 10): the WORDING is no longer written here. Each function collects the data (placeholders) and calls
 * sms_notify() with its notification code; the text, on/off switch, recipients, duplicate / repeat rules and sending
 * hours come from SMS Management (includes/sms_center.php, modules/sms/index.php).
 *
 * Every function here is SAFE: it never throws and never stops the page. Call them only
 * AFTER mysqli_commit(), so an SMS never goes out for something that was rolled back.
 * Each message is sent at most once per record (checked in sms_log), so a double click or
 * a page refresh never sends it twice.
 */
require_once __DIR__ . '/sms_helpers.php';
require_once __DIR__ . '/sms_center.php'; // NEW (Task 10): wording, switches, recipients and rules are managed in SMS Management

const SMS_HELP_PHONE = '0795344768';  // EBM / Ikibazo / Ubufasha number in the messages
const SMS_MOMO_CODE  = '070600';      // MTN MoMo payment code in the messages
const SMS_PORTAL_URL = 'myspace.risemotive.rw/portal'; // NEW: the Customer Portal address in the messages

// How often the automatic messages are repeated (spec: credit reminder 1 in 2 days, loyalty weekly).
// A little under 2 days / 7 days, so a daily task that runs a few minutes early is not skipped.
const SMS_CREDIT_REMINDER_EVERY_HOURS = 46;
const SMS_LOYALTY_EVERY_HOURS = 164;

/**
 * Finds the phone column of a table (names differ between modules: phone, phone_number,
 * telephone, mobile, contact ...). Returns null when the table has none.
 */
function sms_phone_column(mysqli $conn, string $table): ?string {
    static $cache = [];
    if (array_key_exists($table, $cache)) { return $cache[$table]; }
    $cols = [];
    $res = @mysqli_query($conn, 'SHOW COLUMNS FROM `' . str_replace('`', '', $table) . '`');
    if ($res) {
        while ($r = mysqli_fetch_assoc($res)) { $cols[] = strtolower($r['Field']); }
    }
    foreach (['phone', 'phone_number', 'telephone', 'tel', 'mobile', 'mobile_number', 'contact_phone', 'contact', 'phone_no'] as $c) {
        if (in_array($c, $cols, true)) { return $cache[$table] = $c; }
    }
    return $cache[$table] = null;
}

function sms_money($v): string {
    return number_format((float) $v, 0, '.', ',');
}

/**
 * The opening of every message + the person's name, capitalised (e.g. "patrick" -> "Patrick"):
 *   Kinyarwanda messages ($lang 'rw') start with "Kuri", English messages ($lang 'en') with "Dear".
 * $fallback is used when the name is empty.
 */
/**
 * NEW (Task 2A): SQL for a customer's FIRST name (SMS greet customers by first name only): the First Name
 * field when it is filled, otherwise the first word of the full name (customers saved before the change).
 * $alias = the customers table alias in the query ('' when the query has no alias).
 */
function sms_first_name_sql(mysqli $conn, string $alias = 'c'): string {
    static $hasFirst = null;
    if ($hasFirst === null) {
        try {
            $hasFirst = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM information_schema.columns
                WHERE table_schema = DATABASE() AND table_name = 'customers' AND column_name = 'first_name'"))[0] > 0;
        } catch (Throwable $e) { $hasFirst = false; }
    }
    $p = $alias !== '' ? $alias . '.' : '';
    $fromName = "SUBSTRING_INDEX(TRIM({$p}name), ' ', 1)";
    return $hasFirst ? "COALESCE(NULLIF(TRIM({$p}first_name), ''), $fromName)" : $fromName;
}

function sms_greet(?string $name, string $fallback = 'Mukiliya', string $lang = 'rw'): string {
    $name = trim((string) $name);
    if ($name === '') { $name = $fallback; }
    return ($lang === 'en' ? 'Dear ' : 'Kuri ') . mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');
}

// The invoice number shown in the messages, in the same format as the printed receipt:
// "RM" + the sale number with 6 digits, e.g. sale 46 -> RM000046.
function sms_invoice_no(int $saleId): string {
    return 'RM' . str_pad((string) $saleId, 6, '0', STR_PAD_LEFT);
}

// True when this message was already sent for this record.
function sms_already_sent(mysqli $conn, string $event, string $refType, int $refId, ?int $withinHours = null): bool {
    $sql = "SELECT id FROM sms_log WHERE event = ? AND ref_type = ? AND ref_id = ? AND status = 'sent'"
         . ($withinHours !== null ? ' AND created_at >= (NOW() - INTERVAL ' . (int) $withinHours . ' HOUR)' : '')
         . ' LIMIT 1';
    $s = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($s, 'ssi', $event, $refType, $refId);
    mysqli_stmt_execute($s);
    return (bool) mysqli_fetch_assoc(mysqli_stmt_get_result($s));
}

/**
 * NEW: everything a customer still owes, on ALL their unpaid sales (Credit and Partially Paid).
 * Used for "Ideni risigaye" in the messages, so a customer with several credits sees the full
 * amount they owe, not only what is left on this one invoice.
 */
function sms_customer_outstanding(mysqli $conn, int $customerId): float {
    $s = mysqli_prepare($conn, "SELECT COALESCE(SUM(total_amount - amount_paid + charges_accrued - charges_paid), 0) AS owed
        FROM sales WHERE customer_id = ? AND status IN ('Credit', 'Partially Paid')");
    mysqli_stmt_bind_param($s, 'i', $customerId);
    mysqli_stmt_execute($s);
    return max(0, round((float) mysqli_fetch_assoc(mysqli_stmt_get_result($s))['owed'], 2));
}

// A sale with its customer's name and phone.
function sms_load_sale(mysqli $conn, int $saleId): ?array {
    $phoneCol = sms_phone_column($conn, 'customers');
    $phoneSql = $phoneCol ? "c.`$phoneCol`" : 'NULL';
    $s = mysqli_prepare($conn, "SELECT s.*, " . sms_first_name_sql($conn) . " AS customer_name, $phoneSql AS customer_phone
        FROM sales s LEFT JOIN customers c ON c.id = s.customer_id WHERE s.id = ?");
    mysqli_stmt_bind_param($s, 'i', $saleId);
    mysqli_stmt_execute($s);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($s)) ?: null;
}

/** NEW (Task 10): a first name for the messages ("patrick" -> "Patrick"), or the fallback. */
function sms_name(?string $name, string $fallback): string {
    $name = trim((string) $name);
    return mb_convert_case($name !== '' ? $name : $fallback, MB_CASE_TITLE, 'UTF-8');
}

/** NEW (Task 10): branch name for the {branch_name} placeholder ('' when branches are not used). */
function sms_branch_name(mysqli $conn, ?int $branchId): string {
    if (!$branchId) { return ''; }
    try { $r = mysqli_fetch_row(mysqli_query($conn, 'SELECT name FROM branches WHERE id = ' . (int) $branchId)); return $r ? (string) $r[0] : ''; } catch (Throwable $e) { return ''; }
}

/** NEW (Task 10): a column of a row, or null (tables differ between installations). */
function sms_col(array $row, string $k) { return array_key_exists($k, $row) && $row[$k] !== null && $row[$k] !== '' ? $row[$k] : null; }

/* =====================================================================
 * Messages 2 and 3: a sale is completed (paid, or given on credit)
 * Call after the sale is finalized: new_sale.php, and approve_discount.php after approval.
 * ===================================================================== */
function sms_notify_sale_created(mysqli $conn, int $saleId, ?int $userId = null): void {
    try {
        $sale = sms_load_sale($conn, $saleId);
        // Walk-in customers have no phone; pending and cancelled sales get no receipt.
        if (!$sale || !$sale['customer_id'] || in_array($sale['status'], ['Pending Discount Approval', 'Cancelled'], true)) { return; }
        $credit = $sale['payment_method'] === 'Credit';
        $branch = sms_col($sale, 'branch_id') !== null ? (int) $sale['branch_id'] : null;
        sms_notify($conn, $credit ? 'SALE_CREDIT' : 'SALE_PAID', $sale['customer_phone'], [
            'customer_name' => sms_name($sale['customer_name'], 'Mukiliya'), 'customer_id' => sms_customer_code($conn, (int) $sale['customer_id']),
            'invoice_number' => sms_invoice_no($saleId), 'amount' => sms_money($credit ? $sale['total_amount'] : $sale['amount_paid']),
            // "Ideni risigaye" = ALL the customer still owes (every unpaid sale), not only this invoice.
            'balance' => sms_money(sms_customer_outstanding($conn, (int) $sale['customer_id'])), 'payment_status' => $sale['status'],
            'transaction_date' => date('d M Y', strtotime((string) (sms_col($sale, 'sale_date') ?? sms_col($sale, 'created_at') ?? 'now'))), 'branch_name' => sms_branch_name($conn, $branch),
        ], ['to' => 'customer', 'ref_type' => 'SALE', 'ref_id' => $saleId, 'user' => $userId, 'branch_id' => $branch, 'customer_id' => (int) $sale['customer_id'], 'status' => $sale['status']]);
    } catch (Throwable $e) {
        error_log('sms_notify_sale_created failed for sale #' . $saleId . ': ' . $e->getMessage());
    }
}

/* =====================================================================
 * Message 4: a payment is recorded on a Credit / Partially Paid sale
 * ===================================================================== */
function sms_notify_payment(mysqli $conn, int $saleId, int $paymentId, float $amount, ?int $userId = null): void {
    try {
        $sale = sms_load_sale($conn, $saleId);
        if (!$sale || !$sale['customer_id']) { return; }
        $branch = sms_col($sale, 'branch_id') !== null ? (int) $sale['branch_id'] : null;
        sms_notify($conn, 'PAYMENT_RECEIVED', $sale['customer_phone'], [
            'customer_name' => sms_name($sale['customer_name'], 'Mukiliya'), 'customer_id' => sms_customer_code($conn, (int) $sale['customer_id']),
            'amount' => sms_money($amount), 'invoice_number' => sms_invoice_no($saleId),
            'balance' => sms_money(sms_customer_outstanding($conn, (int) $sale['customer_id'])), 'payment_status' => $sale['status'],
            'transaction_date' => date('d M Y'), 'branch_name' => sms_branch_name($conn, $branch),
        ], ['to' => 'customer', 'ref_type' => 'SALE_PAYMENT', 'ref_id' => $paymentId, 'user' => $userId, 'branch_id' => $branch, 'customer_id' => (int) $sale['customer_id'], 'status' => $sale['status']]);
    } catch (Throwable $e) {
        error_log('sms_notify_payment failed for sale #' . $saleId . ': ' . $e->getMessage());
    }
}

/* =====================================================================
 * Message 5: an invoice is cancelled
 * ===================================================================== */
function sms_notify_sale_cancelled(mysqli $conn, int $saleId, ?int $userId = null): void {
    try {
        $sale = sms_load_sale($conn, $saleId);
        if (!$sale || !$sale['customer_id'] || $sale['status'] !== 'Cancelled') { return; }
        $branch = sms_col($sale, 'branch_id') !== null ? (int) $sale['branch_id'] : null;
        sms_notify($conn, 'SALE_CANCELLED', $sale['customer_phone'], [
            'customer_name' => sms_name($sale['customer_name'], 'Mukiliya'), 'customer_id' => sms_customer_code($conn, (int) $sale['customer_id']),
            'invoice_number' => sms_invoice_no($saleId), 'amount' => sms_money($sale['total_amount']), 'payment_status' => 'Cancelled',
            'balance' => sms_money(sms_customer_outstanding($conn, (int) $sale['customer_id'])), 'transaction_date' => date('d M Y'), 'branch_name' => sms_branch_name($conn, $branch),
        ], ['to' => 'customer', 'ref_type' => 'SALE', 'ref_id' => $saleId, 'user' => $userId, 'branch_id' => $branch, 'customer_id' => (int) $sale['customer_id'], 'status' => 'Cancelled']);
    } catch (Throwable $e) {
        error_log('sms_notify_sale_cancelled failed for sale #' . $saleId . ': ' . $e->getMessage());
    }
}

/* =====================================================================
 * Message 6: a Purchase Order is issued (status becomes Ordered) -> supplier
 * ===================================================================== */
function sms_notify_po_ordered(mysqli $conn, int $poId, ?int $userId = null): void {
    try {
        $phoneCol = sms_phone_column($conn, 'business_parties');
        $phoneSql = $phoneCol ? "bp.`$phoneCol`" : 'NULL';
        $s = mysqli_prepare($conn, "SELECT po.*, COALESCE(NULLIF(bp.business_name, ''), NULLIF(bp.name, ''), po.supplier) AS supplier_name,
                $phoneSql AS supplier_phone
            FROM purchase_orders po LEFT JOIN business_parties bp ON bp.id = po.supplier_party_id WHERE po.id = ?");
        mysqli_stmt_bind_param($s, 'i', $poId);
        mysqli_stmt_execute($s);
        $po = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
        if (!$po || $po['status'] !== 'Ordered') { return; }
        $branch = sms_col($po, 'branch_id') !== null ? (int) $po['branch_id'] : null;
        sms_notify($conn, 'PO_ORDERED', $po['supplier_phone'], [
            'supplier_name' => sms_name($po['supplier_name'], 'Supplier'), 'po_number' => (string) $poId, 'amount' => sms_money($po['total_amount']),
            'due_date' => !empty($po['expected_delivery_date']) ? date('d M Y', strtotime($po['expected_delivery_date'])) : 'as soon as possible', 'branch_name' => sms_branch_name($conn, $branch),
        ], ['to' => 'supplier', 'ref_type' => 'PO', 'ref_id' => $poId, 'user' => $userId, 'branch_id' => $branch, 'status' => 'Ordered']);
    } catch (Throwable $e) {
        error_log('sms_notify_po_ordered failed for PO #' . $poId . ': ' . $e->getMessage());
    }
}

/* =====================================================================
 * Message 8: unpaid credit reminder, repeated at the interval set in SMS Management (standard: every 2 days),
 * one SMS per customer with the TOTAL they still owe on all their unpaid sales. Run daily by sms_cron.php.
 * Returns how many were sent.
 * ===================================================================== */
function sms_send_credit_reminders(mysqli $conn): int {
    $sent = 0;
    try {
        $phoneCol = sms_phone_column($conn, 'customers');
        if (!$phoneCol) { return 0; }
        $t = sms_tpl($conn, 'CREDIT_REMINDER');
        if (!$t || !(int) $t['active']) { return 0; } // switched off: nothing to do (not logged one by one)
        $rows = mysqli_fetch_all(mysqli_query($conn, "SELECT c.id, " . sms_first_name_sql($conn) . " AS name, c.`$phoneCol` AS phone, c.created_branch_id,
                SUM(s.total_amount - s.amount_paid + s.charges_accrued - s.charges_paid) AS balance
            FROM customers c JOIN sales s ON s.customer_id = c.id
            WHERE s.status IN ('Credit', 'Partially Paid') AND c.`$phoneCol` IS NOT NULL AND c.`$phoneCol` <> ''
            GROUP BY c.id, c.name, c.`$phoneCol`, c.created_branch_id
            HAVING balance > 0.009"), MYSQLI_ASSOC);
        foreach ($rows as $c) {
            if (sms_notify($conn, 'CREDIT_REMINDER', $c['phone'], ['customer_name' => sms_name($c['name'], 'Mukiliya'), 'customer_id' => sms_customer_code($conn, (int) $c['id']), 'balance' => sms_money($c['balance'])],
                ['to' => 'customer', 'ref_type' => 'CUSTOMER', 'ref_id' => (int) $c['id'], 'branch_id' => $c['created_branch_id'] !== null ? (int) $c['created_branch_id'] : null, 'customer_id' => (int) $c['id']])) { $sent++; }
        }
    } catch (Throwable $e) {
        error_log('sms_send_credit_reminders failed: ' . $e->getMessage());
    }
    return $sent;
}

/* =====================================================================
 * Message 9: Loyalty Points message to every active customer with points, repeated at the interval set in
 * SMS Management (standard: weekly). Run daily by sms_cron.php. Returns how many were sent.
 * ===================================================================== */
function sms_send_loyalty_weekly(mysqli $conn): int {
    $sent = 0;
    try {
        $phoneCol = sms_phone_column($conn, 'customers');
        if (!$phoneCol) { return 0; }
        $t = sms_tpl($conn, 'LOYALTY_WEEKLY');
        if (!$t || !(int) $t['active']) { return 0; }
        $rows = mysqli_fetch_all(mysqli_query($conn, "SELECT id, " . sms_first_name_sql($conn, '') . " AS name, loyalty_points, `$phoneCol` AS phone, created_branch_id
            FROM customers WHERE is_active = 1 AND loyalty_points > 0 AND `$phoneCol` IS NOT NULL AND `$phoneCol` <> ''"), MYSQLI_ASSOC);
        foreach ($rows as $c) {
            if (sms_notify($conn, 'LOYALTY_WEEKLY', $c['phone'], ['customer_name' => sms_name($c['name'], 'Customer'), 'customer_id' => sms_customer_code($conn, (int) $c['id']), 'points' => number_format((int) $c['loyalty_points'])],
                ['to' => 'customer', 'ref_type' => 'CUSTOMER', 'ref_id' => (int) $c['id'], 'branch_id' => $c['created_branch_id'] !== null ? (int) $c['created_branch_id'] : null, 'customer_id' => (int) $c['id']])) { $sent++; }
        }
    } catch (Throwable $e) {
        error_log('sms_send_loyalty_weekly failed: ' . $e->getMessage());
    }
    return $sent;
}

/* =====================================================================
 * Message 7: an employee is assigned a new task
 * Call after the task is saved (tasks create page, and the edit page when the person changes).
 * Sent again only if the task goes to a different person.
 * ===================================================================== */
function sms_notify_task_assigned(mysqli $conn, int $taskId, ?int $userId = null): void {
    try {
        $phoneCol = sms_phone_column($conn, 'users');
        $phoneSql = $phoneCol ? "u.`$phoneCol`" : 'NULL';
        $s = mysqli_prepare($conn, "SELECT t.*, u.names AS employee_name, u.role AS employee_role, $phoneSql AS employee_phone
            FROM tasks t JOIN users u ON u.id = t.assigned_to WHERE t.id = ?");
        mysqli_stmt_bind_param($s, 'i', $taskId);
        mysqli_stmt_execute($s);
        $task = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
        if (!$task) { return; }   // not assigned to anyone
        $branch = sms_col($task, 'branch_id') !== null ? (int) $task['branch_id'] : null;
        $due = !empty($task['due_date']) ? date('d M Y', strtotime($task['due_date'])) : '';
        sms_notify($conn, 'TASK_ASSIGNED', $task['employee_phone'], [
            'employee_name' => sms_name($task['employee_name'], 'Colleague'), 'task_title' => $task['title'], 'task_reference' => 'T-' . str_pad((string) $taskId, 6, '0', STR_PAD_LEFT),
            'priority' => $task['priority'], 'due_date' => $due, 'due_text' => $due !== '' ? ' (Due ' . $due . ')' : '', 'branch_name' => sms_branch_name($conn, $branch),
        // Already told THIS person about this task? Not twice (a new person gets it).
        ], ['to' => 'employee', 'ref_type' => 'TASK', 'ref_id' => $taskId, 'user' => $userId, 'branch_id' => $branch, 'role' => $task['employee_role'], 'recipient_user' => (int) $task['assigned_to'],
            'dedupe_phone' => sms_normalize_phone($task['employee_phone']), 'status' => $task['status'] ?? null]);
    } catch (Throwable $e) {
        error_log('sms_notify_task_assigned failed for task #' . $taskId . ': ' . $e->getMessage());
    }
}

/* =====================================================================
 * Message 10: payroll is generated AND paid -> employee
 * Call after a payroll is saved as Paid (generate page, and the page that marks a Draft as Paid).
 * ===================================================================== */
function sms_notify_payroll_paid(mysqli $conn, int $payrollId, ?int $userId = null): void {
    try {
        $phoneCol = sms_phone_column($conn, 'users');
        $phoneSql = $phoneCol ? "u.`$phoneCol`" : 'NULL';
        $s = mysqli_prepare($conn, "SELECT p.*, u.names AS employee_name, u.role AS employee_role, $phoneSql AS employee_phone
            FROM payroll p JOIN users u ON u.id = p.user_id WHERE p.id = ?");
        mysqli_stmt_bind_param($s, 'i', $payrollId);
        mysqli_stmt_execute($s);
        $p = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
        if (!$p || $p['status'] !== 'Paid') { return; }
        $branch = sms_col($p, 'branch_id') !== null ? (int) $p['branch_id'] : null;
        sms_notify($conn, 'PAYROLL_PAID', $p['employee_phone'], [
            'employee_name' => sms_name($p['employee_name'], 'Colleague'), 'period' => date('F Y', strtotime($p['pay_period'])), 'net_salary' => sms_money($p['net_salary']),
        ], ['to' => 'employee', 'ref_type' => 'PAYROLL', 'ref_id' => $payrollId, 'user' => $userId, 'branch_id' => $branch, 'role' => $p['employee_role'], 'recipient_user' => (int) $p['user_id'], 'status' => 'Paid']);
    } catch (Throwable $e) {
        error_log('sms_notify_payroll_paid failed for payroll #' . $payrollId . ': ' . $e->getMessage());
    }
}

/* =====================================================================
 * NEW: welcome message to a new customer (sent once, when the customer is registered)
 * ===================================================================== */

const SMS_WEBSITE = 'www.risemotive.rw';

/**
 * The Customer ID shown in the welcome SMS. Uses the customer's own code column if the table has
 * one (customer_code, code, customer_no ...), otherwise "RMC" + the number with 6 digits
 * (customer 25 -> RMC000025). Change here to match the ID printed elsewhere in the system.
 */
function sms_customer_code(mysqli $conn, int $customerId): string {
    static $codeCol = false;
    if ($codeCol === false) {
        $codeCol = null;
        $res = @mysqli_query($conn, 'SHOW COLUMNS FROM customers');
        $cols = [];
        if ($res) { while ($r = mysqli_fetch_assoc($res)) { $cols[] = strtolower($r['Field']); } }
        foreach (['customer_code', 'code', 'customer_no', 'customer_number', 'reference'] as $c) {
            if (in_array($c, $cols, true)) { $codeCol = $c; break; }
        }
    }
    if ($codeCol) {
        $s = mysqli_prepare($conn, "SELECT `$codeCol` AS code FROM customers WHERE id = ?");
        mysqli_stmt_bind_param($s, 'i', $customerId);
        mysqli_stmt_execute($s);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
        if ($row && trim((string) $row['code']) !== '') { return trim((string) $row['code']); }
    }
    return 'RMC' . str_pad((string) $customerId, 6, '0', STR_PAD_LEFT);
}

// Call right after a new customer is saved (customers create page). Sent only once per customer.
function sms_notify_customer_welcome(mysqli $conn, int $customerId, ?int $userId = null): void {
    try {
        $phoneCol = sms_phone_column($conn, 'customers');
        if (!$phoneCol) { return; }
        $c = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * , " . sms_first_name_sql($conn, '') . " AS first, `$phoneCol` AS phone_no FROM customers WHERE id = " . $customerId));
        if (!$c || trim((string) $c['phone_no']) === '') { return; }
        $branch = sms_col($c, 'created_branch_id') !== null ? (int) $c['created_branch_id'] : null;
        sms_notify($conn, 'CUSTOMER_WELCOME', $c['phone_no'], ['customer_name' => sms_name($c['first'], 'Customer'), 'customer_id' => sms_customer_code($conn, $customerId)],
            ['to' => 'customer', 'ref_type' => 'CUSTOMER', 'ref_id' => $customerId, 'user' => $userId, 'branch_id' => $branch, 'customer_id' => $customerId]);
    } catch (Throwable $e) {
        error_log('sms_notify_customer_welcome failed for customer #' . $customerId . ': ' . $e->getMessage());
    }
}

/* =====================================================================
 * NEW: Loyalty Reward fulfilled
 * ===================================================================== */
function sms_notify_reward_fulfilled(mysqli $conn, int $rewardId, ?int $userId = null): void
{
    try {
        $phoneCol = sms_phone_column($conn, 'customers');
        if (!$phoneCol) { return; }
        $s = mysqli_prepare($conn, "SELECT r.reward_details, r.points_redeemed, r.status, r.customer_id, t.name AS type_name,
                " . sms_first_name_sql($conn) . " AS name, c.`$phoneCol` AS phone, c.loyalty_points, c.created_branch_id
            FROM customer_rewards r JOIN reward_types t ON t.id = r.reward_type_id JOIN customers c ON c.id = r.customer_id
            WHERE r.id = ?");
        mysqli_stmt_bind_param($s, 'i', $rewardId);
        mysqli_stmt_execute($s);
        $r = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
        if (!$r || $r['status'] !== 'Fulfilled' || trim((string) $r['phone']) === '') { return; }
        sms_notify($conn, 'REWARD_FULFILLED', $r['phone'], [
            'customer_name' => sms_name($r['name'], 'Customer'), 'customer_id' => sms_customer_code($conn, (int) $r['customer_id']),
            'reward' => trim($r['type_name'] . ' - ' . $r['reward_details'], ' -'), 'points_redeemed' => number_format((int) $r['points_redeemed']), 'points_balance' => number_format((int) $r['loyalty_points']),
        ], ['to' => 'customer', 'ref_type' => 'REWARD', 'ref_id' => $rewardId, 'user' => $userId, 'branch_id' => $r['created_branch_id'] !== null ? (int) $r['created_branch_id'] : null, 'customer_id' => (int) $r['customer_id']]);
    } catch (Throwable $e) {
        error_log('sms_notify_reward_fulfilled failed for reward #' . $rewardId . ': ' . $e->getMessage());
    }
}