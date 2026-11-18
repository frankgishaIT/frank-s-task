<?php
/**
 * NEW FILE: includes/sms_center.php — CENTRALIZED SMS MANAGEMENT (Task 10).
 *
 * Every automatic SMS of MY MOTIVE is a NOTIFICATION listed in SMS_CATALOG (event code, module, trigger, recipients,
 * placeholders, standard wording, standard delivery rules). Administrators manage them in SMS Management
 * (modules/sms/index.php): wording, on/off, recipients and targeting, duplicate / repeat rules, sending hours,
 * versions with restore, test SMS. Modules no longer write SMS text: they call
 *
 *     sms_notify($conn, 'EVENT', $phone, ['placeholder' => value, ...], ['to' => 'customer', 'ref_type' => .., 'ref_id' => .., ...]);
 *
 * which applies, in this order:  notification switched off → audience check (a customer message never goes to another
 * kind of recipient) → targeting (branch, role, customer group, course, status) → duplicate / repeat rule →
 * sending hours (queued until the window opens) → the existing Pindo sender (sms_send, sender ID RISEMOTIVE).
 * Every attempt is written to sms_log with its template version, segments, recipient category, module and branch.
 * Missing placeholder values print nothing (never "{amount}").
 */
require_once __DIR__ . '/sms_helpers.php';

const SMS_RECIPIENTS = ['customer' => 'Customers', 'student' => 'Students (Learning Centre)', 'applicant' => 'Applicants (Learning Centre / internships)',
    'employee' => 'Employees', 'supervisor' => 'Supervisors', 'branch_manager' => 'Branch managers', 'instructor' => 'Instructors', 'supplier' => 'Business partners (suppliers)'];
const SMS_CUSTOMER_GROUPS = ['student' => 'Learning Centre students', 'credit' => 'Customers who owe money', 'loyalty' => 'Customers with loyalty points', 'portal' => 'Customer Portal users', 'other' => 'Other customers'];
const SMS_STATUSES = ['queued' => 'Queued', 'sent' => 'Sent', 'delivered' => 'Delivered', 'failed' => 'Failed', 'skipped' => 'Not sent (no phone / SMS off)',
    'disabled' => 'Not sent (notification off)', 'filtered' => 'Not sent (targeting)', 'duplicate' => 'Duplicate blocked', 'blocked' => 'Blocked (wrong audience)', 'cancelled' => 'Cancelled'];

/** Placeholders every notification can use. */
function sms_common_vars(): array
{
    return ['company' => ['Company name', 'RISE MOTIVE'], 'help_phone' => ['Help line', SMS_HELP_PHONE_C], 'portal_url' => ['Customer Portal address', SMS_PORTAL_URL_C],
            'momo_code' => ['MTN MoMo pay code', SMS_MOMO_CODE_C], 'website' => ['Website', 'www.risemotive.rw']];
}
const SMS_HELP_PHONE_C = '0795344768';
const SMS_MOMO_CODE_C = '070600';
const SMS_PORTAL_URL_C = 'myspace.risemotive.rw/portal';

/**
 * The notifications. kind: transactional | reminder | info | security (security = can never be switched off).
 * to: allowed recipient categories (the first is the normal recipient). vars: placeholder => [meaning, sample].
 * rules: standard dedupe (once | interval | none) + interval hours. targets: targeting filters that make sense.
 */
function sms_catalog(): array
{
    static $c = null;
    if ($c !== null) { return $c; }
    $cust = ['customer_name' => ['Customer first name', 'Aline'], 'customer_id' => ['Customer ID', 'RMC000121']];
    $sale = ['invoice_number' => ['Invoice number', 'RM000046'], 'amount' => ['Amount (RWF)', '25,000'], 'balance' => ['Everything the customer still owes (RWF)', '10,000'],
             'transaction_date' => ['Date', date('d M Y')], 'branch_name' => ['Branch', 'RISE MOTIVE – Main Branch'], 'payment_status' => ['Payment status', 'Partially Paid']];
    $emp = ['employee_name' => ['Employee first name', 'Eric']];
    $lc = function (string $name, string $trigger, string $tpl, string $to, array $vars, string $kind = 'info', array $targets = ['course', 'branch']) {
        return ['name' => $name, 'module' => 'Learning Centre', 'trigger' => $trigger, 'to' => [$to], 'kind' => $kind, 'lc_tpl' => $tpl,
                'vars' => ['name' => [$to === 'student' ? 'Student first name' : 'Applicant first name', 'Aline']] + $vars, 'rules' => ['dedupe' => 'none', 'interval' => 0], 'targets' => $targets, 'default' => ''];
    };
    $app = ['ref' => ['Application reference', 'RMLC2026-0001'], 'course' => ['Course', 'Web Development Fundamentals'], 'intake' => ['Intake', 'January 2027'], 'status' => ['Application status', 'Under Review'], 'track_url' => ['Tracking link', 'learn.risemotive.rw/track.php?ref=RMLC2026-0001']];
    $c = [
        'CUSTOMER_WELCOME' => ['name' => 'Welcome to a new customer', 'module' => 'Customers', 'trigger' => 'A new customer is registered', 'to' => ['customer'], 'kind' => 'info',
            'vars' => $cust, 'rules' => ['dedupe' => 'once', 'interval' => 0], 'targets' => ['branch'],
            'default' => "Dear {customer_name}, welcome to the RISE MOTIVE family! Your Customer ID is {customer_id}. You will receive updates about your purchases and payments through this number. Our products and services are also available at {website}. For assistance, call 0795 344 768. Thank you for choosing RISE MOTIVE!"],
        'SALE_PAID' => ['name' => 'Receipt: sale paid', 'module' => 'Sales', 'trigger' => 'A sale is completed and paid (not on credit)', 'to' => ['customer'], 'kind' => 'transactional',
            'vars' => $cust + $sale, 'rules' => ['dedupe' => 'once', 'interval' => 0], 'targets' => ['branch', 'customer_group'], 'legacy' => 'SALE_CREATED',
            'default' => "Kuri {customer_name}, twakiriye {amount} FRW wishyuye kuri Invoice No: {invoice_number}. Ideni risigaye: {balance} FRW. Sura: {portal_url} winjire muri konti yawe umenye amakuru yose ari kuri Invoice, uhakure Facture ya EBM, unahasabire ibihembo (Loyalty Rewards) wemerewe! Murakoze guhitamo RISE MOTIVE!"],
        'SALE_CREDIT' => ['name' => 'Invoice: sale on credit', 'module' => 'Sales', 'trigger' => 'A sale is given on credit (also Learning Centre fee invoices)', 'to' => ['customer'], 'kind' => 'transactional',
            'vars' => $cust + $sale, 'rules' => ['dedupe' => 'once', 'interval' => 0], 'targets' => ['branch', 'customer_group'], 'legacy' => 'SALE_CREATED',
            'default' => "Kuri {customer_name}, Invoice No: {invoice_number}. Itegereje kwishyurwa: {amount}FRW, Ideni risigaye: {balance}FRW. Kanda *182*8*1*{momo_code}# kugira ngo wishyure.\n\nSura: {portal_url} winjire muri konti yawe umenye amakuru yose ari kuri Invoice. Ubufasha: {help_phone}\nMurakoze guhitamo RISE MOTIVE!"],
        'PAYMENT_RECEIVED' => ['name' => 'Payment received', 'module' => 'Sales', 'trigger' => 'A payment is recorded on a credit invoice (cash, MoMo, digital payment)', 'to' => ['customer'], 'kind' => 'transactional',
            'vars' => $cust + $sale, 'rules' => ['dedupe' => 'once', 'interval' => 0], 'targets' => ['branch', 'customer_group'],
            'default' => "Kuri {customer_name}, twakiriye {amount} FRW wishyuye kuri Invoice No: {invoice_number}. Ideni risigaye: {balance} FRW. Sura: {portal_url} winjire muri konti yawe umenye amakuru yose ari kuri Invoice, uhakure Facture ya EBM, unahasabire ibihembo (Loyalty Rewards) wemerewe! Murakoze guhitamo RISE MOTIVE!"],
        'SALE_CANCELLED' => ['name' => 'Invoice cancelled', 'module' => 'Sales', 'trigger' => 'An invoice is cancelled', 'to' => ['customer'], 'kind' => 'transactional',
            'vars' => $cust + $sale, 'rules' => ['dedupe' => 'once', 'interval' => 0], 'targets' => ['branch', 'customer_group'], 'copy' => true,
            'default' => "Kuri {customer_name}, Invoice No:{invoice_number} yahagaritswe (Cancelled). Nta bwishyu bugisabwa kuri iyi Invoice. Ubufasha: {help_phone}. Murakoze guhitamo RISE MOTIVE!"],
        'CREDIT_REMINDER' => ['name' => 'Unpaid credit reminder', 'module' => 'Sales', 'trigger' => 'Daily task: a customer still owes money', 'to' => ['customer'], 'kind' => 'reminder',
            'vars' => $cust + ['balance' => ['Everything the customer owes (RWF)', '10,000']], 'rules' => ['dedupe' => 'interval', 'interval' => 46], 'targets' => ['branch', 'customer_group'],
            'default' => "Kuri {customer_name}, ubereyemo RISE MOTIVE {balance}FRW. Kanda *182*8*1*{momo_code}# Kugira ngo wishyure.\n\nKubindi bisobanuro, Sura: {portal_url} winjire muri konti yawe umenye amakuru yose ari kuri Invoice. Ubufasha: {help_phone}\nMurakoze!"],
        'LOYALTY_WEEKLY' => ['name' => 'Loyalty points balance', 'module' => 'Customers', 'trigger' => 'Daily task: customer has loyalty points', 'to' => ['customer'], 'kind' => 'reminder',
            'vars' => $cust + ['points' => ['Loyalty points', '1,250']], 'rules' => ['dedupe' => 'interval', 'interval' => 164], 'targets' => ['branch', 'customer_group'],
            'default' => "Dear {customer_name}, You have {points} Loyalty Points earned from your purchases with us.\n\nVisit: {portal_url}, log in to your account to access all your invoice information and claim your eligible Loyalty Rewards!\nKeep earning with RISE MOTIVE!"],
        'REWARD_FULFILLED' => ['name' => 'Loyalty reward given', 'module' => 'Customers', 'trigger' => 'A loyalty reward is fulfilled', 'to' => ['customer'], 'kind' => 'transactional',
            'vars' => $cust + ['reward' => ['Reward', 'Discount voucher - 10%'], 'points_redeemed' => ['Points used', '500'], 'points_balance' => ['Points left', '750']], 'rules' => ['dedupe' => 'once', 'interval' => 0], 'targets' => ['branch'],
            'default' => "Dear {customer_name} You have received a Loyalty Reward: {reward}. Points Redeemed: {points_redeemed}. Unredeemed Points Balance: {points_balance}. Thank you for choosing RISE MOTIVE!"],
        'PO_ORDERED' => ['name' => 'Purchase order to supplier', 'module' => 'Purchase Orders', 'trigger' => 'A purchase order is issued (status Ordered)', 'to' => ['supplier'], 'kind' => 'transactional',
            'vars' => ['supplier_name' => ['Supplier name', 'Kigali Supplies'], 'po_number' => ['PO number', '57'], 'amount' => ['Estimated amount (RWF)', '480,000'], 'due_date' => ['Delivery required by', date('d M Y', strtotime('+7 days'))], 'branch_name' => ['Branch', 'RISE MOTIVE – Main Branch']],
            'rules' => ['dedupe' => 'once', 'interval' => 0], 'targets' => ['branch'], 'copy' => true,
            'default' => "Dear {supplier_name}, RISE MOTIVE issued PO No: {po_number} to you, Estimated at {amount}FRW. Delivery Required by {due_date}. Please Confirm. Thank You!"],
        'TASK_ASSIGNED' => ['name' => 'Task assigned', 'module' => 'Tasks', 'trigger' => 'A task is given to an employee (again only if it goes to another person)', 'to' => ['employee'], 'kind' => 'info',
            'vars' => $emp + ['task_title' => ['Task title', 'Count stock in store B'], 'task_reference' => ['Task number', 'T-000123'], 'priority' => ['Priority', 'High'], 'due_date' => ['Due date', date('d M Y', strtotime('+2 days'))], 'due_text' => ['" (Due …)" or nothing', ' (Due ' . date('d M Y', strtotime('+2 days')) . ')'], 'branch_name' => ['Branch', 'RISE MOTIVE – Main Branch']],
            'rules' => ['dedupe' => 'none', 'interval' => 0], 'targets' => ['branch', 'role'], 'copy' => true,
            'default' => 'Dear {employee_name}, You have been assigned a new {priority}-Priority Task: "{task_title}"{due_text}.'],
        'PAYROLL_PAID' => ['name' => 'Salary paid', 'module' => 'Payroll', 'trigger' => 'A payroll is saved as Paid', 'to' => ['employee'], 'kind' => 'transactional',
            'vars' => $emp + ['period' => ['Pay period', date('F Y')], 'net_salary' => ['Net salary (RWF)', '350,000']], 'rules' => ['dedupe' => 'once', 'interval' => 0], 'targets' => ['branch', 'role'],
            'default' => "Dear {employee_name}, Your payroll for {period} has been generated and paid. Net Salary: {net_salary}FRW, check your account for the payslip details. Thank you!"],
        'TIMETABLE' => ['name' => 'Work Timetable alerts', 'module' => 'Work Timetable', 'trigger' => 'Timetable alerts sent by SMS (choose which alerts in Work Timetable → Settings)', 'to' => ['employee', 'supervisor'], 'kind' => 'info',
            'vars' => ['message' => ['The alert text', 'You checked in 12 minutes late.'], 'title' => ['Alert title', 'Late arrival recorded'], 'employee_name' => ['Employee name', 'Eric']], 'rules' => ['dedupe' => 'none', 'interval' => 0], 'targets' => ['branch', 'role'],
            'default' => 'MY MOTIVE: {message}'],
        'PORTAL_OTP' => ['name' => 'Customer Portal sign-in code', 'module' => 'Customer Portal', 'trigger' => 'A customer asks for a sign-in / verification code', 'to' => ['customer'], 'kind' => 'security', 'required' => ['code'],
            'vars' => ['code' => ['One-time code', '482913'], 'minutes' => ['Minutes valid', '10']], 'rules' => ['dedupe' => 'none', 'interval' => 0], 'targets' => [], 'legacy' => 'portal_otp',
            'default' => 'RISE MOTIVE: your customer portal code is {code}. It expires in {minutes} minutes. Do not share it with anyone.'],
        'PORTAL_NOTICE' => ['name' => 'Customer Portal notice', 'module' => 'Customer Portal', 'trigger' => 'A notice is posted to a customer (RM Credit Hub, RM Investors Hub …)', 'to' => ['customer'], 'kind' => 'info',
            'vars' => $cust + ['title' => ['Notice title', 'Your application was approved']], 'rules' => ['dedupe' => 'none', 'interval' => 0], 'targets' => ['branch', 'customer_group'], 'legacy' => 'portal_notice',
            'default' => 'RISE MOTIVE: {title}. Details in your customer portal.'],
        'PORTAL_REPLY' => ['name' => 'Answer to a portal request', 'module' => 'Customer Portal', 'trigger' => 'Staff answer a customer request', 'to' => ['customer'], 'kind' => 'info',
            'vars' => $cust + ['request_number' => ['Request number', 'REQ-000045']], 'rules' => ['dedupe' => 'interval', 'interval' => 2], 'targets' => ['branch', 'customer_group'], 'legacy' => 'portal_reply',
            'default' => 'RISE MOTIVE answered your request {request_number}. Sign in to the customer portal to read it.'],
        'LC_TRACK_OTP' => ['name' => 'Application tracking code', 'module' => 'Learning Centre', 'trigger' => 'An applicant opens their application on the website', 'to' => ['applicant'], 'kind' => 'security', 'required' => ['code'],
            'vars' => ['code' => ['One-time code', '482913']], 'rules' => ['dedupe' => 'none', 'interval' => 0], 'targets' => [], 'legacy' => 'learning_otp',
            'default' => 'RM Learning Centre code: {code} (valid 10 minutes). Do not share it.'],
        'learning_tpl_submitted' => $lc('Application received', 'An application is submitted', 'tpl_submitted', 'applicant', $app, 'transactional'),
        'learning_tpl_status' => $lc('Application status changed', 'An application changes status', 'tpl_status', 'applicant', $app, 'info', ['course', 'branch', 'status']),
        'learning_tpl_info' => $lc('More information requested', 'Staff ask an applicant for more information', 'tpl_info', 'applicant', $app + ['message' => ['What is needed', 'a copy of your ID']]),
        'learning_tpl_admitted' => $lc('Admission offer', 'An applicant is admitted', 'tpl_admitted', 'applicant', $app, 'transactional'),
        'learning_tpl_registered' => $lc('Registered as a student', 'An admitted applicant is registered', 'tpl_registered', 'applicant', $app + ['student_id' => ['Student ID', 'RMC000121']], 'transactional'),
        'learning_tpl_timetable' => $lc('Class timetable change', 'A class session is added, moved or cancelled', 'tpl_timetable', 'student', ['class' => ['Class', 'WDF-001-JAN27-A'], 'message' => ['What changed', 'Monday session moved to 14:00']]),
        'learning_tpl_exam' => $lc('Examination date', 'An examination is scheduled', 'tpl_exam', 'student', ['assessment' => ['Assessment', 'Final exam'], 'class' => ['Class', 'WDF-001-JAN27-A'], 'date' => ['Date', date('d M Y', strtotime('+10 days'))]]),
        'learning_tpl_attendance' => $lc('Attendance alert', 'A student falls under the minimum attendance', 'tpl_attendance', 'student', ['class' => ['Class', 'Web Development Fundamentals'], 'pct' => ['Attendance %', '62'], 'min' => ['Minimum %', '80']], 'reminder'),
        'learning_tpl_results' => $lc('Results published', 'Class results are published', 'tpl_results', 'student', ['class' => ['Class', 'Web Development Fundamentals (WDF-001-JAN27-A)'], 'decision' => ['Result', 'Pass (B)']]),
        'learning_tpl_certificate' => $lc('Certificate ready', 'A certificate is issued', 'tpl_certificate', 'student', ['number' => ['Certificate number', 'RMLC-C2026-00005'], 'course' => ['Course', 'Web Development Fundamentals']], 'transactional'),
        'learning_tpl_fee_reminder' => $lc('Fee payment reminder', 'Daily: an instalment is due soon', 'tpl_fee_reminder', 'student', ['amount' => ['Amount', 'RWF 125,000'], 'course' => ['Course', 'Web Development Fundamentals'], 'date' => ['Due date', date('d M Y', strtotime('+3 days'))], 'invoice' => ['Invoice', 'RM000046']], 'reminder'),
        'learning_tpl_accommodation' => $lc('Accommodation', 'An accommodation booking changes', 'tpl_accommodation', 'student', ['status' => ['Booking status', 'is confirmed'], 'facility' => ['Hostel', 'Huye Hostel'], 'room' => ['Room', 'B2'], 'bed' => ['Bed', '3'], 'from' => ['From', '11 Jan 2027'], 'to' => ['To', '05 Mar 2027']]),
        'learning_internship' => $lc('Internship application', 'An internship application changes', 'tpl_internship', 'applicant', ['ref' => ['Reference', 'RMIN2026-0001'], 'post' => ['Internship', 'Web development intern'], 'status' => ['Status', 'Shortlisted'], 'message' => ['Message', 'Interview on Monday 10:00']], 'info', ['status']),
    ];
    // Learning Centre: the standard wording is the Learning Centre default.
    if (!defined('LC_DEFAULTS') && is_file(__DIR__ . '/lc_helpers.php')) { require_once __DIR__ . '/lc_helpers.php'; }
    foreach ($c as $k => $v) { if (!empty($v['lc_tpl'])) { $c[$k]['default'] = defined('LC_DEFAULTS') ? (string) (LC_DEFAULTS[$v['lc_tpl']] ?? '') : ''; if ($c[$k]['default'] === '') { $c[$k]['default'] = 'RM Learning Centre: {name}'; } } }
    return $c;
}

/** Notification names for the reports (includes manual / test / old event codes). */
function sms_event_label(string $event): string
{
    $c = sms_catalog();
    if (isset($c[$event])) { return $c[$event]['name']; }
    foreach ($c as $k => $v) { if (($v['legacy'] ?? '') === $event) { return $v['name']; } }
    if ($event === 'SALE_CREATED') { return 'Sale receipt / invoice (before templates)'; }
    if ($event === 'MANUAL') { return 'Manual SMS (Send SMS)'; }
    if (strpos($event, 'TEST') === 0) { return 'Test SMS'; }
    if (strpos($event, 'TIMETABLE_') === 0) { return 'Work Timetable: ' . strtolower(str_replace('_', ' ', substr($event, 10))); }
    return $event;
}

function sms_event_module(string $event): string
{
    $c = sms_catalog();
    if (isset($c[$event])) { return $c[$event]['module']; }
    foreach ($c as $v) { if (($v['legacy'] ?? '') === $event) { return $v['module']; } }
    if (strpos($event, 'TIMETABLE') === 0) { return 'Work Timetable'; }
    if (strpos($event, 'learning') === 0) { return 'Learning Centre'; }
    if (in_array($event, ['SALE_CREATED'], true)) { return 'Sales'; }
    return in_array($event, ['MANUAL'], true) ? 'Manual' : (strpos($event, 'TEST') === 0 ? 'Test' : 'Other');
}

/* ------------------------------------------------------------------ storage */

/**
 * True when the SMS Management tables exist. The automatic SMS path NEVER creates tables itself (CREATE / ALTER would
 * silently commit a transaction a module may have open): tables are created by sms_center_ensure(), which runs when
 * SMS Management or the daily SMS task is opened. Until then, notifications use their standard wording.
 */
function sms_center_ready(mysqli $conn): bool
{
    static $ok = null;
    if ($ok === true) { return true; }
    try { $r = mysqli_fetch_row(mysqli_query($conn, "SELECT value FROM sms_settings WHERE name = 'schema'")); $ok = $r && $r[0] === '1'; } catch (Throwable $e) { $ok = false; }
    return $ok;
}

function sms_center_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    try {
        mysqli_query($conn, 'CREATE TABLE IF NOT EXISTS sms_settings (name VARCHAR(60) NOT NULL, value TEXT NULL, PRIMARY KEY (name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $v = mysqli_fetch_row(mysqli_query($conn, "SELECT value FROM sms_settings WHERE name = 'schema'"));
        if ($v && $v[0] === '1') { return; }
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sms_templates (event VARCHAR(50) NOT NULL, body TEXT NOT NULL, active TINYINT(1) NOT NULL DEFAULT 1,
            rules TEXT NULL, version INT(11) NOT NULL DEFAULT 1, created_by INT(11) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_by INT(11) NULL, updated_at DATETIME NULL, PRIMARY KEY (event)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sms_template_versions (id INT(11) NOT NULL AUTO_INCREMENT, event VARCHAR(50) NOT NULL, version INT(11) NOT NULL,
            body TEXT NOT NULL, active TINYINT(1) NOT NULL, rules TEXT NULL, note VARCHAR(300) NULL, changed_by INT(11) NULL, changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id), KEY event (event, version)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sms_audit (id INT(11) NOT NULL AUTO_INCREMENT, event VARCHAR(50) NULL, action VARCHAR(40) NOT NULL, details TEXT NULL,
            old_value TEXT NULL, new_value TEXT NULL, user_id INT(11) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY event (event)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sms_queue (id INT(11) NOT NULL AUTO_INCREMENT, log_id INT(11) NOT NULL, phone VARCHAR(30) NOT NULL, message TEXT NOT NULL,
            send_after DATETIME NOT NULL, status VARCHAR(12) NOT NULL DEFAULT 'pending', created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY due (status, send_after)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        // sms_log: more statuses and the reporting columns.
        try { mysqli_query($conn, "ALTER TABLE sms_log MODIFY status VARCHAR(12) NOT NULL"); } catch (Throwable $e) { }
        $has = function (string $c) use ($conn): bool { try { return (bool) mysqli_num_rows(mysqli_query($conn, "SHOW COLUMNS FROM sms_log LIKE '$c'")); } catch (Throwable $e) { return true; } };
        foreach (['segments' => 'TINYINT(3) NULL', 'template_version' => 'INT(11) NULL', 'recipient_type' => 'VARCHAR(20) NULL', 'module' => 'VARCHAR(40) NULL', 'branch_id' => 'INT(11) NULL', 'sent_at' => 'DATETIME NULL'] as $c => $t) {
            if (!$has($c)) { mysqli_query($conn, "ALTER TABLE sms_log ADD COLUMN $c $t"); }
        }
        try { mysqli_query($conn, 'ALTER TABLE sms_log ADD KEY status_created (status, created_at)'); } catch (Throwable $e) { }
        mysqli_query($conn, "DELETE FROM sms_settings WHERE name = 'schema'");
        mysqli_query($conn, "INSERT INTO sms_settings (name, value) VALUES ('schema', '1')");
    } catch (Throwable $e) { error_log('sms_center_ensure: ' . $e->getMessage()); }
}

const SMS_SETTING_DEFAULTS = ['duplicate_minutes' => '10', 'daily_cap' => '0', 'cost_per_segment' => '0', 'window_start' => '07:00', 'window_end' => '20:00'];

function sms_setting(mysqli $conn, string $k): string
{
    static $cache = null;
    if ($cache === null) { $cache = []; try { foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT name, value FROM sms_settings')) as $r) { $cache[$r[0]] = (string) $r[1]; } } catch (Throwable $e) { } }
    return $cache[$k] ?? (SMS_SETTING_DEFAULTS[$k] ?? '');
}

function sms_setting_set(mysqli $conn, string $k, string $v): void
{
    $s = mysqli_prepare($conn, 'DELETE FROM sms_settings WHERE name = ?'); mysqli_stmt_bind_param($s, 's', $k); mysqli_stmt_execute($s);
    $s = mysqli_prepare($conn, 'INSERT INTO sms_settings (name, value) VALUES (?, ?)'); mysqli_stmt_bind_param($s, 'ss', $k, $v); mysqli_stmt_execute($s);
}

/** Standard rules of a notification (what is stored in sms_templates.rules). */
function sms_default_rules(array $cat): array
{
    return ['dedupe' => $cat['rules']['dedupe'], 'interval' => (int) $cat['rules']['interval'], 'schedule' => 'immediate',
            'branches' => [], 'roles' => [], 'groups' => [], 'courses' => [], 'statuses' => [], 'copy_manager' => 0];
}

/** The template of a notification (created with the standard wording the first time). */
function sms_tpl(mysqli $conn, string $event): ?array
{
    $cat = sms_catalog()[$event] ?? null;
    if (!$cat) { return null; }
    static $cache = [];
    if (isset($cache[$event])) { return $cache[$event]; }
    if (!sms_center_ready($conn)) { // tables not created yet: standard wording and rules, nothing written
        $body = $cat['default'];
        if (!empty($cat['lc_tpl']) && function_exists('lc_setting')) { $cur = trim((string) lc_setting($conn, $cat['lc_tpl'])); if ($cur !== '') { $body = $cur; } }
        return ['event' => $event, 'body' => $body, 'active' => 1, 'rules' => sms_default_rules($cat), 'version' => 0, 'created_by' => null, 'updated_by' => null, 'updated_at' => null, 'cat' => $cat];
    }
    $s = mysqli_prepare($conn, 'SELECT * FROM sms_templates WHERE event = ?'); mysqli_stmt_bind_param($s, 's', $event); mysqli_stmt_execute($s);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    if (!$row) {
        $body = $cat['default'];
        if (!empty($cat['lc_tpl']) && function_exists('lc_setting')) { $cur = trim((string) lc_setting($conn, $cat['lc_tpl'])); if ($cur !== '') { $body = $cur; } } // keep the Learning Centre wording already in use
        $rules = json_encode(sms_default_rules($cat));
        try {
            $s = mysqli_prepare($conn, 'INSERT INTO sms_templates (event, body, active, rules, version) VALUES (?, ?, 1, ?, 1)'); mysqli_stmt_bind_param($s, 'sss', $event, $body, $rules); mysqli_stmt_execute($s);
            $s = mysqli_prepare($conn, "INSERT INTO sms_template_versions (event, version, body, active, rules, note) VALUES (?, 1, ?, 1, ?, 'Standard wording (created automatically)')"); mysqli_stmt_bind_param($s, 'sss', $event, $body, $rules); mysqli_stmt_execute($s);
            mysqli_query($conn, "INSERT INTO sms_audit (event, action, details) VALUES ('" . mysqli_real_escape_string($conn, $event) . "', 'created', 'Template created with the standard wording (version 1)')");
        } catch (Throwable $e) { }
        $row = ['event' => $event, 'body' => $body, 'active' => 1, 'rules' => $rules, 'version' => 1, 'created_by' => null, 'updated_by' => null, 'updated_at' => null];
    }
    $row['rules'] = array_merge(sms_default_rules($cat), json_decode((string) $row['rules'], true) ?: []);
    $row['cat'] = $cat;
    return $cache[$event] = $row;
}

/* ------------------------------------------------------------------ text */

/** Fills {placeholders}. Missing / empty values print nothing; spaces left behind are tidied. */
function sms_render(string $body, array $vars): string
{
    $v = [];
    foreach (sms_common_vars() as $k => $d) { $v[$k] = $d[1]; }
    foreach ($vars as $k => $x) { $v[trim((string) $k, '{}')] = is_scalar($x) || $x === null ? (string) $x : ''; }
    $out = preg_replace_callback('/\{([a-z0-9_]+)\}/i', function ($m) use ($v) { return $v[$m[1]] ?? ''; }, $body);
    $out = preg_replace('/[ \t]{2,}/', ' ', (string) $out);
    $out = preg_replace('/ +([,.!?:;])/', '$1', $out);
    $out = preg_replace('/[ \t]+\n/', "\n", $out);
    return trim($out);
}

/** GSM-7 (160 characters per SMS) or Unicode (70). */
function sms_is_gsm(string $t): bool
{
    return (bool) preg_match('/^[@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,\-.\/0-9:;<=>?¡A-ZÄÖÑÜ§¿a-zäöñüà\^{}\\\\\[~\]|€]*$/u', $t);
}

/** [characters, segments, 'GSM'|'Unicode']. */
function sms_segments(string $t): array
{
    if ($t === '') { return [0, 0, 'GSM']; }
    if (sms_is_gsm($t)) { $n = mb_strlen($t) + preg_match_all('/[\^{}\\\\\[~\]|€]/u', $t); return [$n, $n <= 160 ? 1 : (int) ceil($n / 153), 'GSM']; }
    $n = mb_strlen($t);
    return [$n, $n <= 70 ? 1 : (int) ceil($n / 67), 'Unicode'];
}

/* ------------------------------------------------------------------ sending */

/** Customer groups of one customer (for targeting). */
function sms_customer_groups(mysqli $conn, int $cid): array
{
    $g = [];
    try {
        if ((bool) mysqli_fetch_row(mysqli_query($conn, "SELECT 1 FROM lc_enrollments WHERE customer_id = $cid LIMIT 1"))) { $g[] = 'student'; }
    } catch (Throwable $e) { }
    try {
        if ((float) mysqli_fetch_row(mysqli_query($conn, "SELECT COALESCE(SUM(total_amount - amount_paid + charges_accrued - charges_paid), 0) FROM sales WHERE customer_id = $cid AND status IN ('Credit', 'Partially Paid')"))[0] > 0.009) { $g[] = 'credit'; }
    } catch (Throwable $e) { }
    try { if ((int) mysqli_fetch_row(mysqli_query($conn, "SELECT loyalty_points FROM customers WHERE id = $cid"))[0] > 0) { $g[] = 'loyalty'; } } catch (Throwable $e) { }
    try { if ((bool) mysqli_fetch_row(mysqli_query($conn, "SELECT 1 FROM portal_accounts WHERE customer_id = $cid LIMIT 1"))) { $g[] = 'portal'; } } catch (Throwable $e) { }
    return $g ?: ['other'];
}

/** Writes a log row for an SMS that is NOT sent (off, filtered, duplicate, blocked). */
function sms_center_log(mysqli $conn, ?string $phone, string $text, string $event, array $ctx, string $status, string $why, ?array $tpl): void
{
    $GLOBALS['__sms_meta'] = sms_center_meta($event, $ctx, $tpl, $text);
    sms_log($conn, $phone, $text, $ctx['log_event'] ?? $event, $ctx['ref_type'] ?? null, isset($ctx['ref_id']) ? (int) $ctx['ref_id'] : null, $status, null, $why, isset($ctx['user']) ? (int) $ctx['user'] : null);
    $GLOBALS['__sms_meta'] = null;
}

function sms_center_meta(string $event, array $ctx, ?array $tpl, string $text): array
{
    return ['segments' => sms_segments($text)[1], 'template_version' => $tpl ? (int) $tpl['version'] : null, 'recipient_type' => $ctx['to'] ?? null,
            'module' => $tpl ? $tpl['cat']['module'] : sms_event_module($event), 'branch_id' => isset($ctx['branch_id']) ? (int) $ctx['branch_id'] : null];
}

/** Was this notification already sent (or queued) for this record? $hours = null: ever. */
function sms_center_sent_before(mysqli $conn, array $events, ?string $refType, ?int $refId, ?int $hours, ?string $phone = null): bool
{
    if ($refType === null || $refId === null) { return false; }
    $in = "'" . implode("','", array_map(function ($e) use ($conn) { return mysqli_real_escape_string($conn, $e); }, $events)) . "'";
    $sql = "SELECT id FROM sms_log WHERE event IN ($in) AND ref_type = ? AND ref_id = ? AND status IN ('sent', 'delivered', 'queued')"
         . ($hours !== null ? " AND created_at >= '" . date('Y-m-d H:i:s', time() - $hours * 3600) . "'" : '') . ($phone !== null ? ' AND phone = ?' : '') . ' LIMIT 1';
    $s = mysqli_prepare($conn, $sql);
    if ($phone !== null) { mysqli_stmt_bind_param($s, 'sis', $refType, $refId, $phone); } else { mysqli_stmt_bind_param($s, 'si', $refType, $refId); }
    mysqli_stmt_execute($s);
    return (bool) mysqli_fetch_assoc(mysqli_stmt_get_result($s));
}

/**
 * THE way modules send an automatic SMS. Returns true when it was sent (or queued for the sending hours).
 * $ctx: to (recipient category, required), ref_type, ref_id, user (who did the action), branch_id, customer_id,
 *       course_id, status, role, log_event (event code written in the log, default = $event), recipient_name.
 */
function sms_notify(mysqli $conn, string $event, ?string $phone, array $vars, array $ctx = []): bool
{
    try {
        $tpl = sms_tpl($conn, $event);
        if (!$tpl) { error_log('sms_notify: unknown notification ' . $event); return false; }
        $cat = $tpl['cat']; $r = $tpl['rules'];
        $text = sms_render($tpl['body'], $vars);
        $norm = sms_normalize_phone($phone);
        // 1. Switched off (security codes can never be switched off).
        if (!(int) $tpl['active'] && $cat['kind'] !== 'security') { sms_center_log($conn, $norm ?? $phone, $text, $event, $ctx, 'disabled', 'This notification is switched off in SMS Management', $tpl); return false; }
        // 2. Audience: the message is only for the recipient category it was written for.
        $to = (string) ($ctx['to'] ?? '');
        if (!in_array($to, $cat['to'], true)) { sms_center_log($conn, $norm ?? $phone, $text, $event, $ctx, 'blocked', 'Recipient category "' . $to . '" does not receive this notification', $tpl); return false; }
        // 3. Targeting.
        $why = sms_center_filtered($conn, $r, $ctx);
        if ($why !== null && $cat['kind'] !== 'security') { sms_center_log($conn, $norm ?? $phone, $text, $event, $ctx, 'filtered', $why, $tpl); return false; }
        // 4. Duplicates / repeats (per record).
        $events = array_values(array_filter([$ctx['log_event'] ?? $event, $cat['legacy'] ?? null]));
        if ($r['dedupe'] !== 'none' && sms_center_sent_before($conn, $events, $ctx['ref_type'] ?? null, isset($ctx['ref_id']) ? (int) $ctx['ref_id'] : null, $r['dedupe'] === 'interval' ? max(1, (int) $r['interval']) : null, $ctx['dedupe_phone'] ?? null)) {
            sms_center_log($conn, $norm ?? $phone, $text, $event, $ctx, 'duplicate', $r['dedupe'] === 'once' ? 'Already sent for this record' : 'Already sent in the last ' . (int) $r['interval'] . ' hour(s)', $tpl);
            return false;
        }
        // 5. Sending hours: outside the window, queue it for the next opening (security codes always go at once).
        $GLOBALS['__sms_meta'] = sms_center_meta($event, $ctx, $tpl, $text);
        $ok = false;
        if ($r['schedule'] === 'window' && $cat['kind'] !== 'security' && ($when = sms_center_next_window($conn)) !== null && $norm !== null) {
            sms_log($conn, $norm, $text, $ctx['log_event'] ?? $event, $ctx['ref_type'] ?? null, isset($ctx['ref_id']) ? (int) $ctx['ref_id'] : null, 'queued', null, 'Waiting for the sending hours (' . $when . ')', isset($ctx['user']) ? (int) $ctx['user'] : null);
            $logId = (int) mysqli_insert_id($conn);
            $s = mysqli_prepare($conn, 'INSERT INTO sms_queue (log_id, phone, message, send_after) VALUES (?, ?, ?, ?)'); mysqli_stmt_bind_param($s, 'isss', $logId, $norm, $text, $when); mysqli_stmt_execute($s);
            $ok = true;
        } else {
            $GLOBALS['__sms_security'] = $cat['kind'] === 'security';
            $ok = sms_send($conn, $phone, $text, $ctx['log_event'] ?? $event, $ctx['ref_type'] ?? null, isset($ctx['ref_id']) ? (int) $ctx['ref_id'] : null, isset($ctx['user']) ? (int) $ctx['user'] : null);
            $GLOBALS['__sms_security'] = false;
        }
        $GLOBALS['__sms_meta'] = null;
        // 6. Copy to the branch manager (with the notification name in front).
        if (!empty($r['copy_manager']) && !empty($cat['copy']) && !empty($ctx['branch_id'])) {
            $m = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT u.id, u.' . (sms_phone_column($conn, 'users') ?: 'phone') . ' AS phone FROM branches b JOIN users u ON u.id = b.manager_user_id WHERE b.id = ' . (int) $ctx['branch_id']));
            if ($m && $m['phone']) {
                $copy = mb_substr('MY MOTIVE copy (' . $cat['name'] . '): ' . $text, 0, SMS_MAX_LENGTH);
                $GLOBALS['__sms_meta'] = sms_center_meta($event, ['to' => 'branch_manager'] + $ctx, $tpl, $copy);
                if (!sms_center_sent_before($conn, [($ctx['log_event'] ?? $event) . '_COPY'], $ctx['ref_type'] ?? null, isset($ctx['ref_id']) ? (int) $ctx['ref_id'] : null, null, sms_normalize_phone($m['phone']))) {
                    sms_send($conn, $m['phone'], $copy, ($ctx['log_event'] ?? $event) . '_COPY', $ctx['ref_type'] ?? null, isset($ctx['ref_id']) ? (int) $ctx['ref_id'] : null, isset($ctx['user']) ? (int) $ctx['user'] : null);
                }
                $GLOBALS['__sms_meta'] = null;
            }
        }
        if (sms_center_ready($conn)) { sms_queue_run($conn, 5); } // send a few queued messages whose time has come
        return $ok;
    } catch (Throwable $e) {
        $GLOBALS['__sms_meta'] = null;
        error_log('sms_notify ' . $event . ': ' . $e->getMessage());
        return false;
    }
}

/** Why a message is filtered out by the targeting rules (null = it goes). */
function sms_center_filtered(mysqli $conn, array $r, array $ctx): ?string
{
    if ($r['branches'] && !in_array((int) ($ctx['branch_id'] ?? 0), array_map('intval', $r['branches']), true)) { return 'Not one of the chosen branches'; }
    if ($r['roles']) {
        $role = $ctx['role'] ?? null;
        if ($role === null && !empty($ctx['recipient_user'])) { $role = (string) (mysqli_fetch_row(mysqli_query($conn, 'SELECT role FROM users WHERE id = ' . (int) $ctx['recipient_user']))[0] ?? ''); }
        if (!in_array((string) $role, $r['roles'], true)) { return 'Not one of the chosen roles'; }
    }
    if ($r['groups'] && !empty($ctx['customer_id']) && !array_intersect($r['groups'], sms_customer_groups($conn, (int) $ctx['customer_id']))) { return 'Customer not in the chosen groups'; }
    if ($r['courses'] && !in_array((int) ($ctx['course_id'] ?? 0), array_map('intval', $r['courses']), true)) { return 'Not one of the chosen courses'; }
    if ($r['statuses'] && isset($ctx['status']) && !in_array((string) $ctx['status'], $r['statuses'], true)) { return 'Status "' . $ctx['status'] . '" is not chosen'; }
    return null;
}

/** Null when now is inside the sending hours, otherwise the next opening (Y-m-d H:i:s). */
function sms_center_next_window(mysqli $conn): ?string
{
    $a = sms_setting($conn, 'window_start'); $b = sms_setting($conn, 'window_end'); $now = date('H:i');
    if ($a <= $b ? ($now >= $a && $now < $b) : ($now >= $a || $now < $b)) { return null; }
    $day = $now >= $b && $a <= $b ? date('Y-m-d', strtotime('+1 day')) : date('Y-m-d');
    return $day . ' ' . $a . ':00';
}

/** Sends queued messages whose time has come (called on every SMS and by the daily task). Returns how many were tried. */
function sms_queue_run(mysqli $conn, int $max = 50): int
{
    static $running = false;
    if ($running) { return 0; }
    $running = true; $n = 0;
    try {
        $rows = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM sms_queue WHERE status = 'pending' AND send_after <= '" . date('Y-m-d H:i:s') . "' ORDER BY id LIMIT " . (int) $max), MYSQLI_ASSOC);
        foreach ($rows as $q) {
            mysqli_query($conn, "UPDATE sms_queue SET status = 'sending' WHERE id = " . (int) $q['id'] . " AND status = 'pending'");
            if (mysqli_affected_rows($conn) !== 1) { continue; }
            [$st, $resp, $err] = sms_transport($q['phone'], $q['message']);
            $s = mysqli_prepare($conn, 'UPDATE sms_log SET status = ?, response = ?, error = ?, sent_at = ? WHERE id = ?'); $now = date('Y-m-d H:i:s'); $lid = (int) $q['log_id'];
            mysqli_stmt_bind_param($s, 'ssssi', $st, $resp, $err, $now, $lid); mysqli_stmt_execute($s);
            mysqli_query($conn, "UPDATE sms_queue SET status = '" . ($st === 'sent' ? 'sent' : 'failed') . "' WHERE id = " . (int) $q['id']);
            $n++;
        }
    } catch (Throwable $e) { error_log('sms_queue_run: ' . $e->getMessage()); }
    $running = false;
    return $n;
}

/** Audit row. */
function sms_audit(mysqli $conn, ?string $event, string $action, string $details, ?string $old = null, ?string $new = null): void
{
    $u = function_exists('current_user_id') && !empty($_SESSION['user_id']) ? (int) current_user_id() : null;
    $s = mysqli_prepare($conn, 'INSERT INTO sms_audit (event, action, details, old_value, new_value, user_id) VALUES (?, ?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($s, 'sssssi', $event, $action, $details, $old, $new, $u);
    mysqli_stmt_execute($s);
}

/** The body of a template (Learning Centre uses it for SMS, portal notification and email). Null when unknown. */
function sms_tpl_body(mysqli $conn, string $event): ?string
{
    try { $t = sms_tpl($conn, $event); return $t ? (string) $t['body'] : null; } catch (Throwable $e) { return null; }
}