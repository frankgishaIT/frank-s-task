<?php
/**
 * NEW FILE: includes/sms_messages.php
 * The SMS messages from the "MY MOTIVE SMS" document, and when each one is sent.
 *
 * Every function here is SAFE: it never throws and never stops the page. Call them only
 * AFTER mysqli_commit(), so an SMS never goes out for something that was rolled back.
 * Each message is sent at most once per record (checked in sms_log), so a double click or
 * a page refresh never sends it twice.
 */
require_once __DIR__ . '/sms_helpers.php';

const SMS_HELP_PHONE = '0795344768';  // EBM / Ikibazo / Ubufasha number in the messages
const SMS_MOMO_CODE  = '070600';      // MTN MoMo payment code in the messages

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
    $s = mysqli_prepare($conn, "SELECT COALESCE(SUM(total_amount - amount_paid), 0) AS owed
        FROM sales WHERE customer_id = ? AND status IN ('Credit', 'Partially Paid')");
    mysqli_stmt_bind_param($s, 'i', $customerId);
    mysqli_stmt_execute($s);
    return max(0, round((float) mysqli_fetch_assoc(mysqli_stmt_get_result($s))['owed'], 2));
}

// A sale with its customer's name and phone.
function sms_load_sale(mysqli $conn, int $saleId): ?array {
    $phoneCol = sms_phone_column($conn, 'customers');
    $phoneSql = $phoneCol ? "c.`$phoneCol`" : 'NULL';
    $s = mysqli_prepare($conn, "SELECT s.*, c.name AS customer_name, $phoneSql AS customer_phone
        FROM sales s LEFT JOIN customers c ON c.id = s.customer_id WHERE s.id = ?");
    mysqli_stmt_bind_param($s, 'i', $saleId);
    mysqli_stmt_execute($s);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($s)) ?: null;
}

/* =====================================================================
 * Messages 2 and 3: a sale is completed (paid, or given on credit)
 * Call after the sale is finalized: new_sale.php, and approve_discount.php after approval.
 * ===================================================================== */
function sms_notify_sale_created(mysqli $conn, int $saleId, ?int $userId = null): void {
    try {
        $sale = sms_load_sale($conn, $saleId);
        // Walk-in customers have no phone; pending and cancelled sales get no receipt.
        if (!$sale || !$sale['customer_id'] || in_array($sale['status'], ['Pending Discount Approval', 'Cancelled'], true)) { return; }
        if (sms_already_sent($conn, 'SALE_CREATED', 'SALE', $saleId)) { return; }

        $name = sms_greet($sale['customer_name'], 'Mukiliya');
        // CHANGED: "Ideni risigaye" = ALL the customer still owes (every unpaid sale), not only this invoice.
        $balance = sms_customer_outstanding($conn, (int) $sale['customer_id']);
        $invoice = sms_invoice_no($saleId);

        if ($sale['payment_method'] === 'Credit') {
            // Message 3: goods or services given on credit.
            $text = $name . ', Invoice No: ' . $invoice . '. Itegereje kwishyurwa: ' . sms_money($sale['total_amount'])
                . 'FRW, Ideni risigaye: ' . sms_money($balance) . 'FRW. MTN MoMo Code: ' . SMS_MOMO_CODE
                . ' Murakoze guhitamo RISE MOTIVE';
        } else {
            // Message 2: the customer bought and paid (not on credit).
            $text = $name . ', twakiriye ' . sms_money($sale['amount_paid']) . 'FRW wishyuye kuri Invoice No: ' . $invoice
                . '. Ideni risigaye: ' . sms_money($balance) . 'FRW. EBM/Ikibazo: ' . SMS_HELP_PHONE
                . '. Murakoze guhitamo RISE MOTIVE';
        }
        sms_send($conn, $sale['customer_phone'], $text, 'SALE_CREATED', 'SALE', $saleId, $userId);
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
        if (sms_already_sent($conn, 'PAYMENT_RECEIVED', 'SALE_PAYMENT', $paymentId)) { return; }

        // CHANGED: "Ideni risigaye" = ALL the customer still owes after this payment, on every unpaid sale.
        $balance = sms_customer_outstanding($conn, (int) $sale['customer_id']);
        $text = sms_greet($sale['customer_name'], 'Mukiliya') . ', twakiriye ' . sms_money($amount) . 'FRW wishyuye kuri Invoice No: '
            . sms_invoice_no($saleId) . '. Ideni risigaye: ' . sms_money($balance) . 'FRW. EBM/Ikibazo: ' . SMS_HELP_PHONE
            . '. Murakoze guhitamo RISE MOTIVE';
        sms_send($conn, $sale['customer_phone'], $text, 'PAYMENT_RECEIVED', 'SALE_PAYMENT', $paymentId, $userId);
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
        if (sms_already_sent($conn, 'SALE_CANCELLED', 'SALE', $saleId)) { return; }

        $text = sms_greet($sale['customer_name'], 'Mukiliya') . ', Invoice No:' . sms_invoice_no($saleId)
            . ' yahagaritswe (Cancelled). Nta bwishyu bugisabwa kuri iyi Invoice. Ubufasha: ' . SMS_HELP_PHONE
            . '. Murakoze guhitamo RISE MOTIVE!';
        sms_send($conn, $sale['customer_phone'], $text, 'SALE_CANCELLED', 'SALE', $saleId, $userId);
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
        if (sms_already_sent($conn, 'PO_ORDERED', 'PO', $poId)) { return; }

        $due = !empty($po['expected_delivery_date']) ? date('d M Y', strtotime($po['expected_delivery_date'])) : 'as soon as possible';
        $text = sms_greet($po['supplier_name'], 'Supplier', 'en') . ', RISE MOTIVE issued PO No: ' . $poId . ' to you, Estimated at '
            . sms_money($po['total_amount']) . 'FRW. Delivery Required by ' . $due . '. Please Confirm. Thank You!';
        sms_send($conn, $po['supplier_phone'], $text, 'PO_ORDERED', 'PO', $poId, $userId);
    } catch (Throwable $e) {
        error_log('sms_notify_po_ordered failed for PO #' . $poId . ': ' . $e->getMessage());
    }
}

/* =====================================================================
 * Message 8: unpaid credit reminder, once every 2 days per customer (run daily by sms_cron.php)
 * One SMS per customer with the TOTAL they still owe on all their unpaid sales.
 * Returns how many were sent.
 * ===================================================================== */
function sms_send_credit_reminders(mysqli $conn): int {
    $sent = 0;
    try {
        $phoneCol = sms_phone_column($conn, 'customers');
        if (!$phoneCol) { return 0; }
        $rows = mysqli_fetch_all(mysqli_query($conn, "SELECT c.id, c.name, c.`$phoneCol` AS phone,
                SUM(s.total_amount - s.amount_paid) AS balance
            FROM customers c JOIN sales s ON s.customer_id = c.id
            WHERE s.status IN ('Credit', 'Partially Paid') AND c.`$phoneCol` IS NOT NULL AND c.`$phoneCol` <> ''
            GROUP BY c.id, c.name, c.`$phoneCol`
            HAVING balance > 0.009"), MYSQLI_ASSOC);

        foreach ($rows as $c) {
            if (sms_already_sent($conn, 'CREDIT_REMINDER', 'CUSTOMER', (int) $c['id'], SMS_CREDIT_REMINDER_EVERY_HOURS)) { continue; }
            $text = sms_greet($c['name'], 'Mukiliya') . ', ubereyemo RISE MOTIVE ' . sms_money($c['balance']) . 'FRW. Ishyura nonaha kuri MTN MoMo Code: '
                . SMS_MOMO_CODE . ', wongere Loyalty Points. EBM/Ikibazo: ' . SMS_HELP_PHONE . '. Murakoze!';
            if (sms_send($conn, $c['phone'], $text, 'CREDIT_REMINDER', 'CUSTOMER', (int) $c['id'])) { $sent++; }
        }
    } catch (Throwable $e) {
        error_log('sms_send_credit_reminders failed: ' . $e->getMessage());
    }
    return $sent;
}

/* =====================================================================
 * Message 9: weekly Loyalty Points message to every active customer (run daily by sms_cron.php;
 * each customer gets it once a week). Customers with 0 points are skipped.
 * Returns how many were sent.
 * ===================================================================== */
function sms_send_loyalty_weekly(mysqli $conn): int {
    $sent = 0;
    try {
        $phoneCol = sms_phone_column($conn, 'customers');
        if (!$phoneCol) { return 0; }
        $rows = mysqli_fetch_all(mysqli_query($conn, "SELECT id, name, loyalty_points, `$phoneCol` AS phone
            FROM customers WHERE is_active = 1 AND loyalty_points > 0 AND `$phoneCol` IS NOT NULL AND `$phoneCol` <> ''"), MYSQLI_ASSOC);

        foreach ($rows as $c) {
            if (sms_already_sent($conn, 'LOYALTY_WEEKLY', 'CUSTOMER', (int) $c['id'], SMS_LOYALTY_EVERY_HOURS)) { continue; }
            $text = sms_greet($c['name'], 'Customer', 'en') . ', You have ' . number_format((int) $c['loyalty_points'])
                . ' Loyalty Points earned from your purchases with us. Use them for eligible discounts. Keep earning with RISE MOTIVE!';
            if (sms_send($conn, $c['phone'], $text, 'LOYALTY_WEEKLY', 'CUSTOMER', (int) $c['id'])) { $sent++; }
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
        $s = mysqli_prepare($conn, "SELECT t.title, t.priority, t.due_date, t.assigned_to, u.names AS employee_name, $phoneSql AS employee_phone
            FROM tasks t JOIN users u ON u.id = t.assigned_to WHERE t.id = ?");
        mysqli_stmt_bind_param($s, 'i', $taskId);
        mysqli_stmt_execute($s);
        $task = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
        if (!$task) { return; }   // not assigned to anyone

        // Already told this same person about this task? Do not send it twice.
        $phone = sms_normalize_phone($task['employee_phone']);
        $last = mysqli_prepare($conn, "SELECT phone FROM sms_log WHERE event = 'TASK_ASSIGNED' AND ref_type = 'TASK' AND ref_id = ? AND status = 'sent' ORDER BY id DESC LIMIT 1");
        mysqli_stmt_bind_param($last, 'i', $taskId);
        mysqli_stmt_execute($last);
        $lastRow = mysqli_fetch_assoc(mysqli_stmt_get_result($last));
        if ($lastRow && $phone !== null && $lastRow['phone'] === $phone) { return; }

        $due = !empty($task['due_date']) ? ' (Due ' . date('d M Y', strtotime($task['due_date'])) . ')' : '';
        $text = sms_greet($task['employee_name'], 'Colleague', 'en') . ', You have been assigned a new ' . $task['priority']
            . '-Priority Task: "' . $task['title'] . '"' . $due . '.';
        sms_send($conn, $task['employee_phone'], $text, 'TASK_ASSIGNED', 'TASK', $taskId, $userId);
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
        $s = mysqli_prepare($conn, "SELECT p.pay_period, p.net_salary, p.status, u.names AS employee_name, $phoneSql AS employee_phone
            FROM payroll p JOIN users u ON u.id = p.user_id WHERE p.id = ?");
        mysqli_stmt_bind_param($s, 'i', $payrollId);
        mysqli_stmt_execute($s);
        $p = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
        if (!$p || $p['status'] !== 'Paid') { return; }
        if (sms_already_sent($conn, 'PAYROLL_PAID', 'PAYROLL', $payrollId)) { return; }

        $text = sms_greet($p['employee_name'], 'Colleague', 'en') . ', Your payroll for ' . date('F Y', strtotime($p['pay_period']))
            . ' has been generated and paid. Net Salary: ' . sms_money($p['net_salary'])
            . 'FRW, check your account for the payslip details. Thank you!';
        sms_send($conn, $p['employee_phone'], $text, 'PAYROLL_PAID', 'PAYROLL', $payrollId, $userId);
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
        $s = mysqli_prepare($conn, "SELECT name, `$phoneCol` AS phone FROM customers WHERE id = ?");
        mysqli_stmt_bind_param($s, 'i', $customerId);
        mysqli_stmt_execute($s);
        $c = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
        if (!$c || trim((string) $c['phone']) === '') { return; }
        if (sms_already_sent($conn, 'CUSTOMER_WELCOME', 'CUSTOMER', $customerId)) { return; }

        $text = sms_greet($c['name'], 'Customer', 'en') . ', welcome to the RISE MOTIVE family! Your Customer ID is '
            . sms_customer_code($conn, $customerId) . '. You will receive updates about your purchases and payments through this number. '
            . 'Our products and services are also available at ' . SMS_WEBSITE . '. For assistance, call 0795 344 768. '
            . 'Thank you for choosing RISE MOTIVE!';
        sms_send($conn, $c['phone'], $text, 'CUSTOMER_WELCOME', 'CUSTOMER', $customerId, $userId);
    } catch (Throwable $e) {
        error_log('sms_notify_customer_welcome failed for customer #' . $customerId . ': ' . $e->getMessage());
    }
}