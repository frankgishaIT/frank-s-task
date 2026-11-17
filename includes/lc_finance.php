<?php
/**
 * NEW FILE: includes/lc_finance.php — RM Learning Centre FEES (stage 3), built ON the existing MY MOTIVE money system.
 * No separate ledger: every Learning Centre invoice IS a normal Sales invoice (RM Offerings services, customer =
 * the student / applicant), so income, receipts, payments (cash, MoMo, bank, Digital Payment), cancellations,
 * Funds and Financial Reports work exactly as for any other sale, and nothing is counted twice.
 * This file only adds: the link invoice ↔ application / enrollment, instalment due dates, the reason of discounts and
 * scholarships, reminders, the admission letter, and the financial clearance policy.
 *
 *   Invoiced  = sales.total_amount (after approved discount / scholarship)
 *   Paid      = sales.amount_paid  (payments recorded through Sales, receipts from Sales)
 *   Balance   = invoiced − paid (+ unpaid overdue charges, only when that policy is switched on)
 *   Reversed  = cancelled Learning Centre invoices (Sales cancellation, with its approval)
 */
require_once __DIR__ . '/lc_academic.php';

const LC_FIN_SCHEMA = '1';
const LC_FEE_TYPES = ['application' => 'Application fee', 'registration' => 'Registration fee', 'tuition' => 'Tuition fee', 'module' => 'Module fee',
                      'exam' => 'Examination fee', 'accommodation' => 'Accommodation fee', 'other' => 'Other approved charge'];
const LC_ADJ_TYPES = ['Discount', 'Scholarship'];

function lc_fin_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    lc_acad_ensure($conn);
    if (lc_setting($conn, 'fin_schema') === LC_FIN_SCHEMA) { return; }
    $q = function (string $sql) use ($conn) { try { mysqli_query($conn, $sql); } catch (Throwable $e) { error_log('lc_fin_ensure: ' . $e->getMessage()); } };
    $q('CREATE TABLE IF NOT EXISTS lc_invoices (id INT(11) NOT NULL AUTO_INCREMENT, sale_id INT(11) NOT NULL, customer_id INT(11) NOT NULL, course_id INT(11) NOT NULL,
        application_id INT(11) NULL, enrollment_id INT(11) NULL, kind VARCHAR(12) NOT NULL, fee_types VARCHAR(200) NULL, plan VARCHAR(12) NOT NULL DEFAULT \'full\',
        notes VARCHAR(300) NULL, created_by INT(11) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), UNIQUE KEY sale (sale_id), KEY app (application_id), KEY customer (customer_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q('CREATE TABLE IF NOT EXISTS lc_installments (id INT(11) NOT NULL AUTO_INCREMENT, invoice_id INT(11) NOT NULL, seq INT(11) NOT NULL, due_date DATE NOT NULL,
        amount DECIMAL(14,2) NOT NULL, PRIMARY KEY (id), KEY inv (invoice_id)) ENGINE=InnoDB');
    $q('CREATE TABLE IF NOT EXISTS lc_adjustments (id INT(11) NOT NULL AUTO_INCREMENT, invoice_id INT(11) NOT NULL, type VARCHAR(12) NOT NULL, amount DECIMAL(14,2) NOT NULL,
        reason VARCHAR(300) NOT NULL, created_by INT(11) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY inv (invoice_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    lc_setting_set($conn, 'fin_schema', LC_FIN_SCHEMA);
}

/* ------------------------------------------------------------------ RM Offerings used for fees */

/**
 * The RM Offerings service used for a fee type (created once, as a Service, if missing: "RM Learning Centre — Tuition fee").
 * Tuition uses the course's own offering when the course is linked to one.
 */
function lc_fee_offering(mysqli $conn, string $type, ?array $course = null): int
{
    if ($type === 'tuition' && $course && !empty($course['offering_id'])) {
        $ok = mysqli_fetch_row(mysqli_query($conn, 'SELECT id FROM products WHERE id = ' . (int) $course['offering_id']));
        if ($ok) { return (int) $ok[0]; }
    }
    $code = 'LC-' . strtoupper($type);
    $r = mysqli_fetch_row(mysqli_query($conn, "SELECT id FROM products WHERE product_code = '" . mysqli_real_escape_string($conn, $code) . "' LIMIT 1"));
    if ($r) { return (int) $r[0]; }
    $name = 'RM Learning Centre — ' . (LC_FEE_TYPES[$type] ?? 'Fee');
    $desc = 'Learning Centre fee (created automatically). The price is set on each invoice.';
    $s = mysqli_prepare($conn, "INSERT INTO products (item_type, product_name, product_code, description, buying_price, selling_price, quantity, unit, reorder_level, category) VALUES ('Service', ?, ?, ?, 0, 0, 0, 'Pieces', 0, 'RM Learning Centre')");
    mysqli_stmt_bind_param($s, 'sss', $name, $code, $desc);
    mysqli_stmt_execute($s);
    return (int) mysqli_insert_id($conn);
}

/* ------------------------------------------------------------------ what to bill */

/** Fee types already billed for an application (invoices not cancelled): [type => sale id]. Prevents double charging. */
function lc_billed_types(mysqli $conn, int $applicationId): array
{
    $o = [];
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT i.fee_types, i.sale_id FROM lc_invoices i JOIN sales s ON s.id = i.sale_id WHERE i.application_id = $applicationId AND s.status <> 'Cancelled'"), MYSQLI_ASSOC) as $r) {
        foreach (array_filter(explode(',', (string) $r['fee_types'])) as $t) { $o[$t] = (int) $r['sale_id']; }
    }
    return $o;
}

/**
 * Suggested invoice lines for an application, from the course / intake / modules fee structure:
 *   kind 'application' → the application fee;
 *   kind 'tuition'     → registration fee + tuition (course fee; or fees of the chosen modules; or course fee + extra modules
 *                        NOT included in the course fee) + examination fee.
 * Modules included in the course fee are never charged again. Types already billed are left out.
 * Lines: [type, description, amount, module_id].
 */
function lc_suggest_lines(mysqli $conn, array $app, string $kind): array
{
    $course = lc_course($conn, (int) $app['course_id']);
    $intake = $app['intake_id'] ? (lc_intakes($conn, (int) $app['course_id'])[(int) $app['intake_id']] ?? null) : null;
    $fees = lc_intake_fees($course, $intake);
    $billed = lc_billed_types($conn, (int) $app['id']);
    $out = [];
    if ($kind === 'application') {
        if (!isset($billed['application']) && $fees['application'] > 0) { $out[] = ['application', 'Application fee — ' . $course['code'], $fees['application'], null]; }
        return $out;
    }
    if (!isset($billed['registration']) && $fees['registration'] > 0) { $out[] = ['registration', 'Registration fee — ' . $course['code'], $fees['registration'], null]; }
    $chosen = array_filter(array_map('intval', explode(',', (string) $app['modules'])));
    $mods = lc_modules($conn, (int) $course['id']);
    if (!isset($billed['tuition']) && $course['fee_structure'] !== 'module' && $fees['course'] > 0) {
        $out[] = ['tuition', 'Tuition — ' . $course['title'] . ($intake ? ' (' . $intake['name'] . ')' : ''), $fees['course'], null];
    }
    if (!isset($billed['module']) && $course['fee_structure'] !== 'course') {
        foreach ($mods as $m) {
            if ($m['status'] === 'Retired' || (int) $m['in_bundle'] === 1 || (float) $m['fee'] <= 0) { continue; }  // included in the course fee: never twice
            if ($course['fee_structure'] === 'module' || in_array((int) $m['id'], $chosen, true) || !(int) $m['optional']) {
                if ((int) $m['optional'] && !in_array((int) $m['id'], $chosen, true)) { continue; }
                $out[] = ['module', 'Module — ' . $m['name'], (float) $m['fee'], (int) $m['id']];
            }
        }
    }
    if (!isset($billed['exam']) && $fees['exam'] > 0) { $out[] = ['exam', 'Examination fee — ' . $course['code'], $fees['exam'], null]; }
    return $out;
}

/* ------------------------------------------------------------------ creating the invoice (a normal Sales invoice) */

/**
 * Creates the Learning Centre invoice as a Sales credit invoice for the linked customer.
 * $lines:  [[type, description, amount], ...]  (amount > 0)
 * $adj:    [[Discount|Scholarship, amount, reason], ...]  (approved discounts / scholarships; reason required)
 * $plan:   [[due_date, amount], ...]  one row = full payment; several rows = instalments (sum = total)
 * A discount / scholarship entered by an Employee waits for Manager / Admin approval (the existing Sales approval).
 * Returns the sale id. Throws RuntimeException for the user.
 */
function lc_create_invoice(mysqli $conn, array $app, string $kind, array $lines, array $adj, array $plan, string $notes = '', ?int $branchOverride = null): int
{
    require_once __DIR__ . '/sales_helpers.php';
    require_once __DIR__ . '/branch_helpers.php';
    lc_fin_ensure($conn);
    if (empty($app['customer_id'])) { throw new RuntimeException('Link the application to the customer first (existing customer, or create the customer record).'); }
    if (!in_array($kind, ['application', 'tuition', 'other', 'accommodation'], true)) { throw new RuntimeException('Unknown invoice kind.'); }
    $course = lc_course($conn, (int) $app['course_id']);
    $billed = lc_billed_types($conn, (int) $app['id']);
    $clean = []; $sub = 0.0; $types = [];
    foreach ($lines as [$t, $d, $amt]) {
        $amt = round((float) $amt, 2); $d = mb_substr(trim((string) $d), 0, 150);
        if ($amt <= 0) { continue; }
        if (!isset(LC_FEE_TYPES[$t])) { throw new RuntimeException('Unknown fee type.'); }
        if (isset($billed[$t]) && !in_array($t, ['other', 'accommodation'], true)) { throw new RuntimeException(LC_FEE_TYPES[$t] . ' is already on invoice RM' . str_pad((string) $billed[$t], 6, '0', STR_PAD_LEFT) . ': cancel that invoice first if it must change.'); }
        $clean[] = [$t, $d !== '' ? $d : LC_FEE_TYPES[$t], $amt]; $sub += $amt; $types[$t] = true;
    }
    if (!$clean) { throw new RuntimeException('Add at least one fee with an amount.'); }
    $disc = 0.0; $adjClean = [];
    foreach ($adj as [$ty, $amt, $why]) {
        $amt = round((float) $amt, 2); $why = mb_substr(trim((string) $why), 0, 300);
        if ($amt <= 0) { continue; }
        if (!in_array($ty, LC_ADJ_TYPES, true)) { throw new RuntimeException('Unknown adjustment.'); }
        if (mb_strlen($why) < 3) { throw new RuntimeException('Give the reason / approval reference of each ' . strtolower($ty) . '.'); }
        $adjClean[] = [$ty, $amt, $why]; $disc += $amt;
    }
    $sub = round($sub, 2); $disc = round($disc, 2);
    if ($disc > $sub) { throw new RuntimeException('Discounts and scholarships cannot be more than the fees.'); }
    $total = round($sub - $disc, 2);
    if ($total <= 0) { throw new RuntimeException('The invoice total must be more than zero (a full scholarship needs no invoice).'); }
    $rows = []; $sum = 0.0; $prev = '';
    foreach ($plan as [$due, $amt]) {
        $amt = round((float) $amt, 2);
        if ($amt <= 0) { continue; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $due)) { throw new RuntimeException('Give each payment a due date.'); }
        if ($prev !== '' && $due < $prev) { throw new RuntimeException('Instalment dates must be in order.'); }
        $rows[] = [$due, $amt]; $sum += $amt; $prev = $due;
    }
    if (!$rows) { $rows[] = [date('Y-m-d', strtotime('+7 days')), $total]; $sum = $total; }
    if (abs(round($sum, 2) - $total) > 0.01) { throw new RuntimeException('The instalments (' . lc_money($sum) . ') must add up to the total (' . lc_money($total) . ').'); }

    $uid = (int) current_user_id(); $role = current_user_role();
    $needsApproval = $disc > 0 && $role === 'Employee';   // same rule as Sales: an Employee's discount waits for approval
    $status = $needsApproval ? 'Pending Discount Approval' : 'Credit';
    $reqBy = $disc > 0 ? $uid : null; $apBy = ($disc > 0 && !$needsApproval) ? $uid : null; $apAt = $apBy ? date('Y-m-d H:i:s') : null;
    $cid = (int) $app['customer_id']; $today = date('Y-m-d'); $zero = 0.0; $method = 'Credit'; $nca = 0;
    $branch = $branchOverride ?? ($app['branch_id'] ? (int) $app['branch_id'] : null); // CHANGED (stage 4): accommodation is billed by the facility's branch

    mysqli_begin_transaction($conn);
    try {
        $s = mysqli_prepare($conn, 'INSERT INTO sales (customer_id, sale_date, subtotal, discount_amount, total_amount, amount_paid, payment_method, status, discount_requested_by, discount_approved_by, discount_approved_at, recorded_by, needs_credit_approval)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($s, 'isddddssiisii', $cid, $today, $sub, $disc, $total, $zero, $method, $status, $reqBy, $apBy, $apAt, $uid, $nca);
        mysqli_stmt_execute($s);
        $saleId = (int) mysqli_insert_id($conn);
        br_stamp($conn, 'sales', $saleId, $branch);
        foreach ($clean as [$t, $d, $amt]) {
            $pid = lc_fee_offering($conn, $t, $course); $it = 'Service'; $q = 1; $unit = null; $pl = 'Piece'; $ps = 1;
            $i = mysqli_prepare($conn, 'INSERT INTO sale_items (sale_id, item_type, product_id, service_name, quantity, unit, unit_price, line_total, pack_label, pack_size) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            mysqli_stmt_bind_param($i, 'isisisddsi', $saleId, $it, $pid, $d, $q, $unit, $amt, $amt, $pl, $ps);
            mysqli_stmt_execute($i);
        }
        $ft = implode(',', array_keys($types)); $pl = count($rows) > 1 ? 'installments' : 'full'; $aid = (int) $app['id']; $crs = (int) $app['course_id'];
        $eid = (int) (mysqli_fetch_row(mysqli_query($conn, "SELECT id FROM lc_enrollments WHERE application_id = $aid"))[0] ?? 0) ?: null;
        $nt = mb_substr(trim($notes), 0, 300) ?: null;
        $s = mysqli_prepare($conn, 'INSERT INTO lc_invoices (sale_id, customer_id, course_id, application_id, enrollment_id, kind, fee_types, plan, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($s, 'iiiiissssi', $saleId, $cid, $crs, $aid, $eid, $kind, $ft, $pl, $nt, $uid);
        mysqli_stmt_execute($s);
        $invId = (int) mysqli_insert_id($conn);
        foreach ($rows as $n => [$due, $amt]) {
            $seq = $n + 1;
            $s = mysqli_prepare($conn, 'INSERT INTO lc_installments (invoice_id, seq, due_date, amount) VALUES (?, ?, ?, ?)');
            mysqli_stmt_bind_param($s, 'iisd', $invId, $seq, $due, $amt); mysqli_stmt_execute($s);
        }
        foreach ($adjClean as [$ty, $amt, $why]) {
            $s = mysqli_prepare($conn, 'INSERT INTO lc_adjustments (invoice_id, type, amount, reason, created_by) VALUES (?, ?, ?, ?, ?)');
            mysqli_stmt_bind_param($s, 'isdsi', $invId, $ty, $amt, $why, $uid); mysqli_stmt_execute($s);
        }
        if (!$needsApproval) { sales_finalize($conn, $saleId, $uid); }
        lc_fee_deadline($conn, $saleId);
        if ($kind === 'application' && in_array($app['status'], ['Submitted', 'Under Review', 'Additional Information Required'], true)) {
            mysqli_query($conn, "UPDATE lc_applications SET status = 'Application Fee Pending', updated_at = NOW() WHERE id = $aid");
            lc_log($conn, $aid, 'status', $app['status'], 'Application Fee Pending', 'Application fee invoice RM' . str_pad((string) $saleId, 6, '0', STR_PAD_LEFT) . ' issued: ' . lc_money($total) . '.', true);
        }
        mysqli_commit($conn);
    } catch (Throwable $e) { mysqli_rollback($conn); throw $e; }
    lc_audit($conn, 'invoice_create', 'invoice', $invId, ['sale' => $saleId, 'kind' => $kind, 'lines' => $clean, 'adjustments' => $adjClean, 'plan' => $rows, 'total' => $total, 'pending_approval' => $needsApproval], $cid);
    if (!$needsApproval) { lc_invoice_notify($conn, $saleId); }
    return $saleId;
}

/** The invoice's deadline follows the Learning Centre's plan (last instalment), never the 72-hour shop rule. */
function lc_fee_deadline(mysqli $conn, int $saleId): void
{
    $last = mysqli_fetch_row(mysqli_query($conn, "SELECT MAX(t.due_date) FROM lc_installments t JOIN lc_invoices i ON i.id = t.invoice_id WHERE i.sale_id = $saleId"))[0] ?? null;
    if (!$last) { return; }
    mysqli_query($conn, "UPDATE sales SET payment_deadline = '" . mysqli_real_escape_string($conn, $last) . " 23:59:59' WHERE id = $saleId AND transaction_id IS NOT NULL AND status IN ('Credit', 'Partially Paid')");
}

/** SMS (existing Sales invoice SMS) + Customer Portal notification with the plan. */
function lc_invoice_notify(mysqli $conn, int $saleId): void
{
    try { require_once __DIR__ . '/sms_messages.php'; if (function_exists('sms_notify_sale_created')) { sms_notify_sale_created($conn, $saleId, function_exists('current_user_id') && !empty($_SESSION['user_id']) ? (int) current_user_id() : null); } } catch (Throwable $e) { error_log('lc invoice sms: ' . $e->getMessage()); }
    $inv = lc_invoice_by_sale($conn, $saleId);
    if (!$inv) { return; }
    $plan = count($inv['installments']) > 1 ? count($inv['installments']) . ' instalments, first due ' . date('d M Y', strtotime($inv['installments'][0]['due_date'])) . '.' : 'Due ' . date('d M Y', strtotime($inv['installments'][0]['due_date'])) . '.';
    if (lc_setting($conn, 'notify_portal') !== '1') { return; }
    try {
        $text = strtr(lc_setting($conn, 'tpl_fee_invoice'), ['{invoice}' => $inv['number'], '{amount}' => lc_money($inv['total']), '{course}' => $inv['course_title'], '{plan}' => $plan]);
        $t = 'RM Learning Centre'; $b = mb_substr($text, 0, 500); $l = 'invoice.php?id=' . $saleId; $cid = (int) $inv['customer_id'];
        $s = mysqli_prepare($conn, 'INSERT INTO portal_notifications (customer_id, title, body, link) VALUES (?, ?, ?, ?)');
        mysqli_stmt_bind_param($s, 'isss', $cid, $t, $b, $l); mysqli_stmt_execute($s);
    } catch (Throwable $e) { /* portal notifications not set up */ }
}

/* ------------------------------------------------------------------ reading invoices */

/** Invoice number as printed by Sales. */
function lc_sale_number(int $saleId): string { return 'RM' . str_pad((string) $saleId, 6, '0', STR_PAD_LEFT); }

/**
 * One invoice with its money state (from Sales) and instalments: each instalment's paid part is taken from the amount paid,
 * oldest first (no separate payment records → nothing counted twice).
 */
function lc_invoice_state(mysqli $conn, array $row): array
{
    $total = (float) $row['total_amount']; $paid = (float) $row['amount_paid'];
    $charges = round((float) ($row['charges_accrued'] ?? 0) - (float) ($row['charges_paid'] ?? 0), 2);
    $cancelled = $row['status'] === 'Cancelled'; $pending = $row['status'] === 'Pending Discount Approval';
    $inst = mysqli_fetch_all(mysqli_query($conn, 'SELECT * FROM lc_installments WHERE invoice_id = ' . (int) $row['id'] . ' ORDER BY seq'), MYSQLI_ASSOC);
    $left = $paid; $today = date('Y-m-d'); $overdue = 0.0; $next = null;
    foreach ($inst as &$t) {
        $a = (float) $t['amount']; $p = min($a, max(0, $left)); $left -= $p;
        $t['paid'] = round($p, 2); $t['due'] = round($a - $p, 2);
        $t['state'] = $t['due'] <= 0.009 ? 'Paid' : ($cancelled ? 'Cancelled' : ($t['due_date'] < $today ? 'Overdue' : ($p > 0 ? 'Partly paid' : 'Due')));
        if ($t['state'] === 'Overdue') { $overdue += $t['due']; }
        if ($next === null && in_array($t['state'], ['Due', 'Partly paid', 'Overdue'], true)) { $next = $t; }
    }
    unset($t);
    $balance = $cancelled ? 0.0 : round($total - $paid + max(0, $charges), 2);
    return $row + ['number' => lc_sale_number((int) $row['sale_id']), 'total' => $total, 'paid' => $paid, 'charges_due' => max(0, $charges), 'balance' => $balance,
        'installments' => $inst, 'overdue' => round($overdue, 2), 'next' => $next, 'cancelled' => $cancelled, 'pending' => $pending,
        'adjustments' => mysqli_fetch_all(mysqli_query($conn, 'SELECT * FROM lc_adjustments WHERE invoice_id = ' . (int) $row['id']), MYSQLI_ASSOC)];
}

function lc_invoice_sql(): string
{
    return 'SELECT i.*, s.total_amount, s.subtotal, s.discount_amount, s.amount_paid, s.status, s.charges_accrued, s.charges_paid, s.sale_date, s.cancelled_at, c.title course_title, c.code course_code
        FROM lc_invoices i JOIN sales s ON s.id = i.sale_id JOIN lc_courses c ON c.id = i.course_id';
}

function lc_invoice_by_sale(mysqli $conn, int $saleId): ?array
{
    $r = mysqli_fetch_assoc(mysqli_query($conn, lc_invoice_sql() . ' WHERE i.sale_id = ' . $saleId));
    return $r ? lc_invoice_state($conn, $r) : null;
}

/** Invoices of an application (or of a customer when $customerId is given). */
function lc_invoices_for(mysqli $conn, ?int $applicationId, ?int $customerId = null): array
{
    $w = $applicationId ? 'i.application_id = ' . $applicationId : 'i.customer_id = ' . (int) $customerId;
    return array_map(function ($r) use ($conn) { return lc_invoice_state($conn, $r); }, mysqli_fetch_all(mysqli_query($conn, lc_invoice_sql() . " WHERE $w ORDER BY i.id"), MYSQLI_ASSOC));
}

/** Outstanding Learning Centre balance of a customer (not cancelled, not waiting for approval). */
function lc_customer_balance(mysqli $conn, int $customerId): float
{
    $b = 0.0;
    foreach (lc_invoices_for($conn, null, $customerId) as $i) { if (!$i['cancelled'] && !$i['pending']) { $b += $i['balance']; } }
    return round($b, 2);
}

/* ------------------------------------------------------------------ policies */

/**
 * Financial clearance (Settings): 'none' = fees never block academic access (default);
 * 'certificate' = no certificate while a balance is due; 'documents' = no certificate and no final transcript download.
 * Overdue instalments only count (what is not yet due never blocks).
 */
function lc_clearance_ok(mysqli $conn, int $customerId, string $what): bool
{
    $p = lc_setting($conn, 'clearance_policy');
    if ($p === 'none' || ($p === 'certificate' && $what !== 'certificate')) { return true; }
    foreach (lc_invoices_for($conn, null, $customerId) as $i) { if (!$i['cancelled'] && !$i['pending'] && $i['overdue'] > 0.009) { return false; } }
    return true;
}

/**
 * Keeps applications and invoices in step with Sales (call on Learning Centre pages):
 *  - application fee invoice fully Paid → status "Application Fee Paid" (never admission);
 *  - invoices approved later in Sales get the Learning Centre deadline and their notification.
 */
function lc_fin_sync(mysqli $conn): void
{
    try {
        foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT a.id, a.status, i.sale_id FROM lc_invoices i JOIN sales s ON s.id = i.sale_id JOIN lc_applications a ON a.id = i.application_id
            WHERE i.kind = 'application' AND s.status = 'Paid' AND a.status = 'Application Fee Pending'"), MYSQLI_ASSOC) as $r) {
            $aid = (int) $r['id'];
            mysqli_query($conn, "UPDATE lc_applications SET status = 'Application Fee Paid', updated_at = NOW() WHERE id = $aid AND status = 'Application Fee Pending'");
            lc_log($conn, $aid, 'status', 'Application Fee Pending', 'Application Fee Paid', 'Application fee received (invoice ' . lc_sale_number((int) $r['sale_id']) . '). Your application is still being reviewed.', true);
            lc_audit($conn, 'application_fee_paid', 'application', $aid, ['sale' => (int) $r['sale_id']]);
        }
        foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT i.sale_id FROM lc_invoices i JOIN sales s ON s.id = i.sale_id JOIN lc_installments t ON t.invoice_id = i.id
            WHERE s.transaction_id IS NOT NULL AND s.status IN ('Credit', 'Partially Paid') GROUP BY i.sale_id, s.payment_deadline
            HAVING s.payment_deadline IS NULL OR DATE(s.payment_deadline) <> MAX(t.due_date)"), MYSQLI_ASSOC) as $r) {
            $first = !mysqli_fetch_row(mysqli_query($conn, "SELECT 1 FROM lc_audit WHERE action = 'invoice_notified' AND ref_id = " . (int) $r['sale_id']));
            lc_fee_deadline($conn, (int) $r['sale_id']);
            if ($first) { lc_invoice_notify($conn, (int) $r['sale_id']); lc_audit($conn, 'invoice_notified', 'sale', (int) $r['sale_id']); }
        }
    } catch (Throwable $e) { error_log('lc_fin_sync: ' . $e->getMessage()); }
}

/** Payment reminders: instalments due within N days or overdue, once a week per instalment. Returns the number sent. */
function lc_fee_reminders(mysqli $conn): int
{
    $n = 0; $days = max(0, (int) lc_setting($conn, 'fee_reminder_days')); $limit = date('Y-m-d', strtotime('+' . $days . ' days'));
    foreach (mysqli_fetch_all(mysqli_query($conn, lc_invoice_sql() . " WHERE s.status IN ('Credit', 'Partially Paid')"), MYSQLI_ASSOC) as $row) {
        $inv = lc_invoice_state($conn, $row);
        foreach ($inv['installments'] as $t) {
            if ($t['due'] <= 0.009 || $t['due_date'] > $limit) { continue; }
            $kind = 'fee' . (int) $t['id']; $cid = (int) $inv['customer_id'];
            $last = mysqli_fetch_row(mysqli_query($conn, "SELECT sent_at FROM lc_alerts WHERE class_id = 0 AND customer_id = $cid AND kind = '$kind'"));
            if ($last && strtotime($last[0]) > time() - 7 * 86400) { continue; }
            lc_notify_student($conn, $cid, 'tpl_fee_reminder', ['{amount}' => lc_money($t['due']), '{course}' => $inv['course_title'], '{date}' => date('d M Y', strtotime($t['due_date'])), '{invoice}' => $inv['number']], 'invoice.php?id=' . (int) $inv['sale_id']);
            mysqli_query($conn, "DELETE FROM lc_alerts WHERE class_id = 0 AND customer_id = $cid AND kind = '$kind'");
            mysqli_query($conn, "INSERT INTO lc_alerts (class_id, customer_id, kind, sent_at) VALUES (0, $cid, '$kind', '" . date('Y-m-d H:i:s') . "')");
            $n++;
        }
    }
    if ($n) { lc_audit($conn, 'fee_reminders', 'fees', null, ['sent' => $n]); }
    return $n;
}

/* ------------------------------------------------------------------ admission letter */

function lc_admission_letter_html(mysqli $conn, array $app): string
{
    if ($app['status'] !== 'Accepted') { throw new RuntimeException('The admission letter exists only for admitted applicants.'); }
    $h = function ($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); };
    $course = lc_course($conn, (int) $app['course_id']);
    $intake = $app['intake_id'] ? (lc_intakes($conn, (int) $app['course_id'])[(int) $app['intake_id']] ?? null) : null;
    $logo = __DIR__ . '/../assets/images/logo.jpg';
    $inv = lc_invoices_for($conn, (int) $app['id']);
    $html = '<style>body{font-family:dejavusans;font-size:10.5pt;color:#1E2333;line-height:1.45}h1{font-size:15pt;color:#0B1A8C;margin:0}td,th{padding:4px 6px;border-bottom:0.5px solid #ccd;text-align:left}th{background:#EEF3FF;font-size:9.5pt}.mut{color:#666;font-size:9pt}</style>';
    $html .= '<table style="width:100%;border:0"><tr><td style="border:0;width:70px">' . (is_file($logo) ? '<img src="' . $h($logo) . '" style="width:60px">' : '') . '</td><td style="border:0"><h1>' . $h(lc_setting($conn, 'title')) . '</h1><div class="mut">' . $h(implode(' · ', array_filter(['RISE MOTIVE Ltd', lc_setting($conn, 'contact_address'), lc_setting($conn, 'contact_phone')]))) . '</div></td>'
        . '<td style="border:0;text-align:right" class="mut">' . date('d F Y', strtotime($app['decided_at'] ?: 'now')) . '<br>Ref. ' . $h($app['ref']) . '</td></tr></table><br>';
    $html .= '<div style="font-size:13pt;font-weight:bold;margin:10px 0">LETTER OF ADMISSION</div>';
    $html .= '<p>Dear ' . $h($app['full_name']) . ',</p><p>' . nl2br($h(lc_setting($conn, 'admission_letter'))) . '</p>';
    $html .= '<table style="width:100%;border-collapse:collapse"><tr><th style="width:35%">Course</th><td>' . $h($course['title']) . ' (' . $h($course['code']) . ')</td></tr>'
        . '<tr><th>Intake</th><td>' . $h($intake['name'] ?? '—') . '</td></tr><tr><th>Branch / training centre</th><td>' . $h(lc_branch_name($conn, $app['branch_id'] ? (int) $app['branch_id'] : null)) . '</td></tr>'
        . ($intake ? '<tr><th>Start date</th><td>' . date('l d F Y', strtotime($intake['start_date'])) . ($intake['end_date'] ? ' — ends ' . date('d F Y', strtotime($intake['end_date'])) : '') . '</td></tr>' : '')
        . ($app['mode'] ? '<tr><th>Training mode</th><td>' . $h($app['mode']) . '</td></tr>' : '')
        . ($app['customer_id'] ? '<tr><th>Student ID (when registered)</th><td>' . $h(lc_customer_id_label($conn, (int) $app['customer_id'])) . '</td></tr>' : '')
        . '<tr><th>Offer status</th><td>' . $h($app['offer_status'] ?: 'Pending') . '</td></tr></table>';
    if ($app['offer_note']) { $html .= '<p><b>Instructions:</b><br>' . nl2br($h($app['offer_note'])) . '</p>'; }
    $fees = array_filter($inv, function ($i) { return !$i['cancelled']; });
    if ($fees) {
        $html .= '<p><b>Fees</b></p><table style="width:100%;border-collapse:collapse"><tr><th>Invoice</th><th>Amount</th><th>Paid</th><th>Balance</th><th>Payment plan</th></tr>';
        foreach ($fees as $i) { $html .= '<tr><td>' . $h($i['number']) . '</td><td>' . lc_money($i['total']) . '</td><td>' . lc_money($i['paid']) . '</td><td>' . lc_money($i['balance']) . '</td><td>' . $h(implode('; ', array_map(function ($t) { return date('d M Y', strtotime($t['due_date'])) . ': ' . lc_money($t['amount']); }, $i['installments']))) . '</td></tr>'; }
        $html .= '</table>';
    } elseif ($course['fees_public']) {
        $f = lc_intake_fees($course, $intake);
        $html .= '<p class="mut">Tuition: ' . lc_money($f['course']) . ($f['registration'] > 0 ? ' · registration fee ' . lc_money($f['registration']) : '') . ($f['exam'] > 0 ? ' · examination fee ' . lc_money($f['exam']) : '') . '. Your invoice will follow.</p>';
    }
    $html .= '<p>Accept your offer on ' . $h(lc_track_url($conn, $app['ref'])) . ' or in the RISE MOTIVE Customer Portal.</p><p>Welcome to ' . $h(lc_setting($conn, 'title')) . '.</p><br><p>' . $h(lc_setting($conn, 'cert_signatory')) . '<br>RISE MOTIVE Ltd</p>';
    if (lc_setting($conn, 'accreditation_verified') === '1' && lc_setting($conn, 'accreditation_note') !== '') { $html .= '<p class="mut">' . $h(lc_setting($conn, 'accreditation_note')) . '</p>'; }
    return $html;
}