<?php
/**
 * NEW FILE: includes/dl_workflow.php — DELEGATION WORKFLOW (from a sale to a verified service).
 *
 * A delegation is one sale line given to one RM Tasker. It never records revenue: the revenue is the sale's.
 * It stores the agreed rate and profit-sharing basis AT CREATION, the expected costs (materials from stock and other
 * direct costs) and, after completion, the actual ones, so expected and actual figures stay separate.
 *
 * Tasker response is kept apart from the delegation status and from the notification:
 *   response NULL = Awaiting Tasker Response · Tasker-confirmed · Staff-confirmed · Tasker-declined · Staff-recorded decline.
 * An SMS, e-mail or WhatsApp message is never taken as acceptance. Staff acting for a Tasker is always recorded as
 * such (dl_events.on_behalf = 1, staff identity, reason, contact notes).
 *
 * dl_monitor() (every 10 minutes at most, from staff pages and the cron URL) sends the follow-up to staff when a
 * Tasker does not answer, the reminder before a deadline, and the alert when a deadline is missed.
 */
require_once __DIR__ . '/dl_helpers.php';

const DL_STATUSES = ['Draft', 'Pending Approval', 'Awaiting Tasker Acceptance', 'Accepted', 'In Progress', 'Completed – Awaiting Verification', 'Verified',
    'Awaiting Payment', 'Partially Paid', 'Paid', 'Closed – Financially Reconciled', 'Rejected', 'Cancelled', 'On Hold', 'Issue Review', 'Reassigned'];
const DL_LIVE = ['Pending Approval', 'Awaiting Tasker Acceptance', 'Accepted', 'In Progress', 'Completed – Awaiting Verification', 'On Hold', 'Issue Review'];
const DL_WORKING = ['Awaiting Tasker Acceptance', 'Accepted', 'In Progress'];
const DL_ENDED = ['Rejected', 'Cancelled', 'Reassigned'];
const DL_RESPONSES = ['Tasker-confirmed', 'Staff-confirmed', 'Tasker-declined', 'Staff-recorded decline'];

function dl_ref(int $id): string { return 'DL' . str_pad((string) $id, 6, '0', STR_PAD_LEFT); }
function dl_id_from_ref(string $ref): int { return preg_match('/^DL0*(\d+)$/i', trim($ref), $m) ? (int) $m[1] : 0; }

function dlw_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    dl_ensure($conn);
    $q = function ($sql) use ($conn) { mysqli_query($conn, $sql); };
    $q("CREATE TABLE IF NOT EXISTS dl_delegations (
        id INT(11) NOT NULL AUTO_INCREMENT, sale_id INT(11) NOT NULL, sale_item_id INT(11) NULL, customer_id INT(11) NULL, product_id INT(11) NOT NULL, party_id INT(11) NULL,
        quantity DECIMAL(12,3) NOT NULL DEFAULT 1, revenue DECIMAL(15,2) NOT NULL DEFAULT 0, rate_pct DECIMAL(5,2) NOT NULL DEFAULT 0, basis VARCHAR(12) NOT NULL DEFAULT 'cogs',
        cogs_exp DECIMAL(15,2) NOT NULL DEFAULT 0, direct_exp DECIMAL(15,2) NOT NULL DEFAULT 0, pool_exp DECIMAL(15,2) NOT NULL DEFAULT 0, pay_exp DECIMAL(15,2) NOT NULL DEFAULT 0,
        retained_exp DECIMAL(15,2) NOT NULL DEFAULT 0, final_exp DECIMAL(15,2) NOT NULL DEFAULT 0,
        cogs_act DECIMAL(15,2) NULL, direct_act DECIMAL(15,2) NULL, pool_act DECIMAL(15,2) NULL, pay_act DECIMAL(15,2) NULL, final_act DECIMAL(15,2) NULL, pay_due DECIMAL(15,2) NULL,
        status VARCHAR(40) NOT NULL DEFAULT 'Draft', response VARCHAR(24) NULL, response_at DATETIME NULL, response_by INT(11) NULL, response_note VARCHAR(500) NULL,
        urgent TINYINT(1) NOT NULL DEFAULT 0, deadline DATETIME NULL, deadline_auto DATETIME NULL, deadline_reason VARCHAR(255) NULL, tasker_eta DATETIME NULL,
        instructions TEXT NULL, delivery TEXT NULL, recommended_party_id INT(11) NULL, override_reason VARCHAR(255) NULL, approval_reasons TEXT NULL,
        approved_by INT(11) NULL, approved_at DATETIME NULL, approval_note VARCHAR(255) NULL, created_by INT(11) NULL, created_at DATETIME NULL,
        notified_at DATETIME NULL, followup_due_at DATETIME NULL, followup_notified_at DATETIME NULL, reminder_sent_at DATETIME NULL, overdue_alert_at DATETIME NULL,
        started_at DATETIME NULL, completed_at DATETIME NULL, completed_by_staff INT(11) NULL, completion_note TEXT NULL,
        verified_at DATETIME NULL, verified_by INT(11) NULL, quality_rating DECIMAL(3,2) NULL, satisfaction_rating DECIMAL(3,2) NULL, instructions_rating DECIMAL(3,2) NULL,
        communication_rating DECIMAL(3,2) NULL, perf_score DECIMAL(5,2) NULL, ontime TINYINT(1) NULL, customer_confirmed TINYINT(1) NULL, verification_note TEXT NULL, rating_final_at DATETIME NULL,
        reassigned_from INT(11) NULL, reassigned_to INT(11) NULL, cancel_reason VARCHAR(255) NULL, hold_reason VARCHAR(255) NULL, closed_at DATETIME NULL, updated_at DATETIME NULL,
        PRIMARY KEY (id), KEY sale (sale_id), KEY party (party_id, status), KEY status (status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS dl_costs (
        id INT(11) NOT NULL AUTO_INCREMENT, delegation_id INT(11) NOT NULL, stage VARCHAR(8) NOT NULL, kind VARCHAR(10) NOT NULL, product_id INT(11) NULL, description VARCHAR(255) NULL,
        qty DECIMAL(12,3) NOT NULL DEFAULT 1, unit_cost DECIMAL(15,2) NOT NULL DEFAULT 0, amount DECIMAL(15,2) NOT NULL DEFAULT 0, created_by INT(11) NULL, created_at DATETIME NULL,
        PRIMARY KEY (id), KEY d (delegation_id, stage)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS dl_files (
        id INT(11) NOT NULL AUTO_INCREMENT, delegation_id INT(11) NOT NULL, kind VARCHAR(12) NOT NULL, ref VARCHAR(40) NOT NULL, name VARCHAR(200) NULL,
        user_id INT(11) NULL, party_id INT(11) NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id), KEY d (delegation_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS dl_events (
        id INT(11) NOT NULL AUTO_INCREMENT, delegation_id INT(11) NOT NULL, event VARCHAR(40) NOT NULL, details TEXT NULL, reason VARCHAR(500) NULL,
        actor VARCHAR(10) NOT NULL DEFAULT 'staff', on_behalf TINYINT(1) NOT NULL DEFAULT 0, user_id INT(11) NULL, party_id INT(11) NULL, visible_to_tasker TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL, PRIMARY KEY (id), KEY d (delegation_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS dl_lost (
        id INT(11) NOT NULL AUTO_INCREMENT, product_id INT(11) NULL, service_text VARCHAR(200) NOT NULL, customer_id INT(11) NULL, customer_name VARCHAR(150) NULL, customer_phone VARCHAR(20) NULL,
        price_quoted DECIMAL(15,2) NULL, quantity DECIMAL(12,3) NOT NULL DEFAULT 1, reason VARCHAR(40) NOT NULL, reason_note VARCHAR(500) NULL, potential_revenue DECIMAL(15,2) NULL,
        follow_up TINYINT(1) NOT NULL DEFAULT 0, follow_up_date DATE NULL, status VARCHAR(16) NOT NULL DEFAULT 'Open', outcome_note VARCHAR(500) NULL, mi_request_id INT(11) NULL,
        user_id INT(11) NULL, created_at DATETIME NOT NULL, updated_at DATETIME NULL, PRIMARY KEY (id), KEY product (product_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS dl_tasker_accounts (
        party_id INT(11) NOT NULL, password_hash VARCHAR(255) NULL, status VARCHAR(10) NOT NULL DEFAULT 'pending', token_hash VARCHAR(64) NULL, token_expires DATETIME NULL,
        activated_at DATETIME NULL, last_login_at DATETIME NULL, created_by INT(11) NULL, created_at DATETIME NULL, PRIMARY KEY (party_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function dl_get(mysqli $conn, int $id): ?array
{
    $d = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT d.*, p.product_name, p.item_type FROM dl_delegations d LEFT JOIN products p ON p.id = d.product_id WHERE d.id = ' . $id));
    if (!$d) { return null; }
    $d['ref'] = dl_ref((int) $d['id']);
    $d['tasker'] = $d['party_id'] ? dl_tasker($conn, (int) $d['party_id']) : null;
    return $d;
}

/** History line. actor: staff | tasker | system. on_behalf: staff acting for the Tasker. visible: shown in the Tasker's portal. */
function dl_event(mysqli $conn, int $did, string $event, string $details, string $actor = 'staff', int $onBehalf = 0, ?string $reason = null, int $visible = 0, ?int $partyId = null): void
{
    $u = $actor === 'staff' ? (dl_uid() ?: null) : null;
    $now = dl_now();
    $s = mysqli_prepare($conn, 'INSERT INTO dl_events (delegation_id, event, details, reason, actor, on_behalf, user_id, party_id, visible_to_tasker, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($s, 'issssiiiis', $did, $event, $details, $reason, $actor, $onBehalf, $u, $partyId, $visible, $now);
    mysqli_stmt_execute($s);
}

function dl_set_status(mysqli $conn, int $id, string $status, array $extra = []): void
{
    $sets = ['status = ?', 'updated_at = ?'];
    $types = 'ss';
    $vals = [$status, dl_now()];
    foreach ($extra as $k => $v) {
        if (!preg_match('/^[a-z_]+$/', $k)) { continue; }
        $sets[] = "$k = ?";
        $types .= is_int($v) ? 'i' : (is_float($v) ? 'd' : 's');
        $vals[] = $v;
    }
    $types .= 'i'; $vals[] = $id;
    $s = mysqli_prepare($conn, 'UPDATE dl_delegations SET ' . implode(', ', $sets) . ' WHERE id = ?');
    mysqli_stmt_bind_param($s, $types, ...$vals);
    mysqli_stmt_execute($s);
}

function dl_status_badge(string $s): string
{
    $c = ['Pending Approval' => 'dl-warn', 'Awaiting Tasker Acceptance' => 'dl-warn', 'Accepted' => 'dl-blue', 'In Progress' => 'dl-blue', 'Completed – Awaiting Verification' => 'dl-gold',
        'Verified' => 'dl-ok', 'Awaiting Payment' => 'dl-ok', 'Partially Paid' => 'dl-warn', 'Paid' => 'dl-ok', 'Closed – Financially Reconciled' => 'dl-ok', 'Rejected' => 'dl-bad', 'Cancelled' => 'dl-bad',
        'On Hold' => '', 'Issue Review' => 'dl-bad', 'Reassigned' => ''][$s] ?? '';
    return '<span class="dl-st ' . $c . '">' . dl_e($s) . '</span>';
}
function dl_response_text(?string $r): string { return $r ?: 'Awaiting Tasker Response'; }

function dl_task_url(string $ref): string { return 'https://' . (defined('SMS_PORTAL_URL_C') ? SMS_PORTAL_URL_C : 'myspace.risemotive.rw/portal') . '/tasker_task.php?ref=' . rawurlencode($ref); }
function dl_deadline_text(?string $dt): string { return $dt ? date('d M Y H:i', strtotime($dt)) : '—'; }

/** Line of a sale: revenue = line total less the line's share of the sale-level discount (never counted twice: it IS the sale's revenue). */
function dl_sale_line(mysqli $conn, int $itemId): ?array
{
    $r = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT si.*, s.customer_id, s.subtotal, s.total_amount, s.status sale_status, s.sale_date, s.recorded_by, s.amount_paid, p.product_name, p.item_type ptype
        FROM sale_items si JOIN sales s ON s.id = si.sale_id LEFT JOIN products p ON p.id = si.product_id WHERE si.id = ' . $itemId));
    if (!$r) { return null; }
    $sub = (float) $r['subtotal'];
    $r['revenue'] = round($sub > 0 ? (float) $r['line_total'] * (float) $r['total_amount'] / $sub : (float) $r['line_total'], 2);
    return $r;
}
/** Sale lines that need (or may use) an RM Tasker and have no live delegation. */
function dl_sale_lines_to_delegate(mysqli $conn, int $saleId, bool $mandatoryOnly = false): array
{
    $out = [];
    foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT si.id, si.product_id FROM sale_items si WHERE si.sale_id = ' . $saleId), MYSQLI_ASSOC) as $r) {
        $o = dl_offering($conn, (int) $r['product_id']);
        if (!dl_needs_tasker($o)) { continue; }
        if ($mandatoryOnly && $o['fulfilment'] !== 'External Only') { continue; }
        $has = mysqli_fetch_row(mysqli_query($conn, 'SELECT id FROM dl_delegations WHERE sale_item_id = ' . (int) $r['id'] . " AND status NOT IN ('Cancelled', 'Reassigned', 'Rejected') LIMIT 1"));
        if (!$has) { $out[] = (int) $r['id']; }
    }
    return $out;
}

function dl_costs(mysqli $conn, int $did, string $stage): array
{
    return mysqli_fetch_all(mysqli_query($conn, "SELECT c.*, p.product_name, p.unit FROM dl_costs c LEFT JOIN products p ON p.id = c.product_id WHERE c.delegation_id = $did AND c.stage = '" . ($stage === 'actual' ? 'actual' : 'expected') . "' ORDER BY c.kind DESC, c.id"), MYSQLI_ASSOC);
}
function dl_cost_totals(array $rows): array
{
    $m = 0; $d = 0;
    foreach ($rows as $r) { if ($r['kind'] === 'material') { $m += (float) $r['amount']; } else { $d += (float) $r['amount']; } }
    return [round($m, 2), round($d, 2)];
}
/** Saves a cost list (materials: product + qty + unit cost; direct: description + amount). */
function dl_save_costs(mysqli $conn, int $did, string $stage, array $materials, array $direct): void
{
    mysqli_query($conn, "DELETE FROM dl_costs WHERE delegation_id = $did AND stage = '" . ($stage === 'actual' ? 'actual' : 'expected') . "'");
    $now = dl_now(); $u = dl_uid() ?: null;
    $s = mysqli_prepare($conn, 'INSERT INTO dl_costs (delegation_id, stage, kind, product_id, description, qty, unit_cost, amount, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    foreach ($materials as $m) {
        $k = 'material'; $pid = (int) $m['product_id']; $desc = null; $qty = (float) $m['qty']; $uc = (float) $m['unit_cost']; $amt = round($qty * $uc, 2);
        mysqli_stmt_bind_param($s, 'issisdddis', $did, $stage, $k, $pid, $desc, $qty, $uc, $amt, $u, $now);
        mysqli_stmt_execute($s);
    }
    foreach ($direct as $x) {
        $k = 'direct'; $pid = null; $desc = (string) $x['description']; $qty = 1.0; $amt = round((float) $x['amount'], 2); $uc = $amt;
        mysqli_stmt_bind_param($s, 'issisdddis', $did, $stage, $k, $pid, $desc, $qty, $uc, $amt, $u, $now);
        mysqli_stmt_execute($s);
    }
}
/** Reads materials[] / direct[] posted by a form. */
function dl_costs_from_post(mysqli $conn, string $prefix): array
{
    $mats = []; $dir = [];
    foreach ((array) ($_POST[$prefix . 'mat_product'] ?? []) as $i => $pid) {
        $pid = (int) $pid; $qty = (float) ($_POST[$prefix . 'mat_qty'][$i] ?? 0); $uc = (float) str_replace(',', '', (string) ($_POST[$prefix . 'mat_cost'][$i] ?? ''));
        if (!$pid || $qty <= 0) { continue; }
        if ($uc <= 0) { $r = mysqli_fetch_row(mysqli_query($conn, 'SELECT buying_price FROM products WHERE id = ' . $pid)); $uc = (float) ($r[0] ?? 0); }
        $mats[] = ['product_id' => $pid, 'qty' => $qty, 'unit_cost' => max(0, $uc)];
    }
    foreach ((array) ($_POST[$prefix . 'dir_desc'] ?? []) as $i => $desc) {
        $desc = mb_substr(trim((string) $desc), 0, 255); $amt = (float) str_replace(',', '', (string) ($_POST[$prefix . 'dir_amount'][$i] ?? 0));
        if ($desc !== '' && $amt > 0) { $dir[] = ['description' => $desc, 'amount' => $amt]; }
    }
    return [$mats, $dir];
}

/**
 * Performance of one verified delegation (0–100). Weights from Delegation Settings. On-time counts only when the
 * quality is acceptable (3/5 or more): speed is never rewarded when the work is poor.
 */
function dl_perf_score(mysqli $conn, float $q, float $sat, float $instr, float $comm, bool $ontime): float
{
    $w = [];
    foreach (['quality', 'ontime', 'satisfaction', 'reliability', 'communication'] as $k) { $w[$k] = (float) dl_setting($conn, 'p_' . $k); }
    $sum = array_sum($w) ?: 100;
    $onPts = $ontime ? ($q >= 3 ? 1.0 : 0.3) : 0.0;
    $s = $w['quality'] * $q / 5 + $w['ontime'] * $onPts + $w['satisfaction'] * $sat / 5 + $w['reliability'] * $instr / 5 + $w['communication'] * $comm / 5;
    return round($s / $sum * 100, 1);
}

/** Follow-up delay for a Tasker's answer: the configured period, shorter for urgent work and close deadlines. */
function dl_followup_due(mysqli $conn, bool $urgent, ?string $deadline): string
{
    $min = (int) dl_setting($conn, $urgent ? 'follow_up_urgent_minutes' : 'follow_up_minutes');
    if ($deadline) { $left = (strtotime($deadline) - time()) / 60; if ($left > 0) { $min = (int) min($min, max(5, $left / 4)); } }
    return date('Y-m-d H:i:s', time() + max(5, $min) * 60);
}

/** SMS + e-mail to the Tasker. kind: assigned | update. Records the DELIVERY ATTEMPT (never an acceptance). */
function dl_notify_tasker(mysqli $conn, array $d, string $kind, string $message = ''): array
{
    $t = $d['tasker'] ?? ($d['party_id'] ? dl_tasker($conn, (int) $d['party_id']) : null);
    if (!$t) { return ['sms' => false, 'email' => false]; }
    $ref = dl_ref((int) $d['id']);
    $first = trim(explode(' ', trim((string) $t['name']))[0] ?? '') ?: $t['display'];
    $wa = dl_setting($conn, 'whatsapp_link');
    $vars = ['tasker_name' => $first, 'service' => $d['product_name'] ?? '', 'reference' => $ref, 'amount' => number_format((float) ($d['pay_due'] ?? $d['pay_exp'])),
        'deadline' => dl_deadline_text($d['deadline']), 'task_link' => dl_task_url($ref), 'whatsapp' => $wa ?: 'our official WhatsApp', 'message' => $message];
    $sms = false; $mail = false;
    try {
        require_once __DIR__ . '/sms_center.php';
        if (function_exists('sms_notify')) {
            $sms = sms_notify($conn, $kind === 'assigned' ? 'DL_ASSIGNED' : 'DL_TASK_UPDATE', $t['phone'], $vars, ['to' => 'tasker', 'ref_type' => 'delegation', 'ref_id' => (int) $d['id'], 'user' => dl_uid() ?: null]);
        }
    } catch (Throwable $e) { error_log('dl sms: ' . $e->getMessage()); }
    if (!empty($t['email']) && is_file(__DIR__ . '/mailer.php')) {
        try {
            require_once __DIR__ . '/mailer.php';
            $e = 'dl_e';
            if ($kind === 'assigned') {
                $subj = 'New service request ' . $ref . ': ' . ($d['product_name'] ?? '');
                $body = '<p>Dear ' . $e($first) . ',</p><p>RISE MOTIVE has a new service request for you.</p><p>Service: <b>' . $e($d['product_name'] ?? '') . '</b><br>Reference: <b>' . $ref . '</b><br>Expected payment: <b>RWF ' . $e($vars['amount'])
                    . '</b><br>Deadline: <b>' . $e($vars['deadline']) . '</b></p><p>Please confirm your availability and accept or reject this assignment: <a href="' . $e($vars['task_link']) . '">' . $e($vars['task_link']) . '</a></p>'
                    . '<p>To avoid delaying the customer, a RISE MOTIVE staff member may contact you through WhatsApp' . ($wa ? ' (<a href="' . $e($wa) . '">' . $e($wa) . '</a>)' : '') . ' or by phone to confirm details or coordinate the work.</p><p>RISE MOTIVE</p>';
            } else {
                $subj = 'Update on ' . $ref . ': ' . ($d['product_name'] ?? '');
                $body = '<p>Dear ' . $e($first) . ',</p><p>' . nl2br($e($message)) . '</p><p>Details: <a href="' . $e($vars['task_link']) . '">' . $e($vars['task_link']) . '</a></p><p>RISE MOTIVE</p>';
            }
            if (function_exists('send_email')) { $mail = send_email($t['email'], $t['display'], $subj, $body); }
        } catch (Throwable $e) { error_log('dl email: ' . $e->getMessage()); }
    }
    dl_event($conn, (int) $d['id'], $kind === 'assigned' ? 'notified' : 'tasker_informed', ($kind === 'assigned' ? 'Assignment notification' : 'Update: ' . $message) . ' · SMS ' . ($sms ? 'sent' : 'not sent') . ' · e-mail ' . (empty($t['email']) ? 'no address' : ($mail ? 'sent' : 'not sent')) . ' (delivery only: not an acceptance)', 'system', 0, null, $kind === 'assigned' ? 0 : 1);
    return ['sms' => $sms, 'email' => $mail];
}

/** In-app notice (and e-mail through notifyUser) to staff. */
function dl_notify_staff(mysqli $conn, array $userIds, string $title, string $message): void
{
    require_once __DIR__ . '/notification_helper.php';
    foreach (array_unique(array_filter(array_map('intval', $userIds))) as $u) { try { notifyUser($conn, $u, $title, $message); } catch (Throwable $e) { error_log('dl notify: ' . $e->getMessage()); } }
}
/** People who may approve delegations (for alerts). */
function dl_approvers(mysqli $conn): array
{
    if (!function_exists('access_user_breakdown') && is_file(__DIR__ . '/access_control.php')) { require_once __DIR__ . '/access_control.php'; }
    $out = [];
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT id FROM users WHERE is_active = 1 AND role IN ('Admin', 'Manager')"), MYSQLI_NUM) as [$u]) {
        try { if (in_array('delegations.approve', access_user_breakdown($conn, (int) $u)['effective'], true)) { $out[] = (int) $u; } } catch (Throwable $e) { $out[] = (int) $u; }
    }
    return $out;
}

/** Deadline reminders, missed-deadline alerts and the follow-up when a Tasker has not answered. At most every 10 minutes. */
function dl_monitor(mysqli $conn, bool $force = false): array
{
    $flag = rtrim(sys_get_temp_dir(), '/\\') . '/rm_dl_monitor_' . md5(__DIR__);
    if (!$force && is_file($flag) && filemtime($flag) > time() - 600) { return []; } // cheap check first: no query at all
    @touch($flag);
    dlw_ensure($conn);
    $last = dl_setting($conn, 'monitor_at');
    if (!$force && $last && strtotime($last) > time() - 600) { return []; }
    dl_set($conn, 'monitor_at', dl_now());
    $now = dl_now();
    $done = ['followups' => 0, 'reminders' => 0, 'overdue' => 0];
    // 1. No answer from the Tasker in time: the responsible staff member is told to call / WhatsApp. Customer service continues.
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT id FROM dl_delegations WHERE status = 'Awaiting Tasker Acceptance' AND response IS NULL AND followup_due_at IS NOT NULL AND followup_due_at <= '$now' AND followup_notified_at IS NULL"), MYSQLI_NUM) as [$id]) {
        $d = dl_get($conn, (int) $id);
        dl_set_status($conn, (int) $id, $d['status'], ['followup_notified_at' => $now]);
        dl_notify_staff($conn, [(int) $d['created_by']], 'No answer from the RM Tasker on ' . $d['ref'], ($d['tasker']['display'] ?? 'The RM Tasker') . ' has not answered about ' . $d['product_name'] . ' (deadline ' . dl_deadline_text($d['deadline']) . '). Contact them on WhatsApp or by phone, record the call, then confirm, reassign or escalate so the customer is not kept waiting.');
        dl_event($conn, (int) $id, 'followup_due', 'No Tasker response within the follow-up period: staff asked to contact the Tasker', 'system');
        $done['followups']++;
    }
    // 2. Reminder before the deadline (offering setting, else the general one).
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT d.id, d.deadline, COALESCE(s.reminder_hours, NULL) rh FROM dl_delegations d LEFT JOIN dl_offering_settings s ON s.product_id = d.product_id
        WHERE d.status IN ('Accepted', 'In Progress') AND d.reminder_sent_at IS NULL AND d.deadline IS NOT NULL AND d.deadline > '$now'"), MYSQLI_ASSOC) as $r) {
        $h = $r['rh'] !== null ? (float) $r['rh'] : (float) dl_setting($conn, 'reminder_hours');
        if ($h <= 0 || strtotime($r['deadline']) - $h * 3600 > time()) { continue; }
        $d = dl_get($conn, (int) $r['id']);
        dl_set_status($conn, (int) $r['id'], $d['status'], ['reminder_sent_at' => $now]);
        dl_notify_tasker($conn, $d, 'update', 'Reminder: ' . $d['product_name'] . ' (' . $d['ref'] . ') is due on ' . dl_deadline_text($d['deadline']) . '. If you expect a delay, report it now in the portal.');
        $done['reminders']++;
    }
    // 3. Missed deadline: staff (creator + approvers) and the Tasker are alerted once.
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT id FROM dl_delegations WHERE status IN ('Awaiting Tasker Acceptance', 'Accepted', 'In Progress') AND deadline IS NOT NULL AND deadline < '$now' AND overdue_alert_at IS NULL"), MYSQLI_NUM) as [$id]) {
        $d = dl_get($conn, (int) $id);
        dl_set_status($conn, (int) $id, $d['status'], ['overdue_alert_at' => $now]);
        dl_notify_staff($conn, array_merge([(int) $d['created_by']], dl_approvers($conn)), 'Deadline missed: ' . $d['ref'], $d['product_name'] . ' by ' . ($d['tasker']['display'] ?? '?') . ' was due ' . dl_deadline_text($d['deadline']) . ' and is not completed (status: ' . $d['status'] . '). Contact the Tasker or reassign.');
        if ($d['status'] !== 'Awaiting Tasker Acceptance') { dl_notify_tasker($conn, $d, 'update', 'The deadline for ' . $d['product_name'] . ' (' . $d['ref'] . ') has passed. Please report your progress or the delay in the portal now.'); }
        dl_event($conn, (int) $id, 'deadline_missed', 'Deadline ' . dl_deadline_text($d['deadline']) . ' passed (status ' . $d['status'] . ')', 'system');
        $done['overdue']++;
    }
    // Tasker payments: confirmation reminders and expired windows, overdue payments.
    if (is_file(__DIR__ . '/dl_payments.php')) { require_once __DIR__ . '/dl_payments.php'; try { $done += dl_pay_monitor($conn); } catch (Throwable $e) { error_log('dl_pay_monitor: ' . $e->getMessage()); } }
    return $done;
}

/** RM Tasker portal account (Customers Portal; no customer access). */
function dl_account(mysqli $conn, int $pid): ?array { $r = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM dl_tasker_accounts WHERE party_id = ' . $pid)); return $r ?: null; }
/** Creates / renews the access link (72 h, single use) and sends it by SMS and e-mail. */
function dl_send_access(mysqli $conn, int $pid): array
{
    $t = dl_tasker($conn, $pid);
    if (!$t) { return ['sms' => false, 'email' => false]; }
    $token = bin2hex(random_bytes(20));
    $hash = hash('sha256', $token);
    $exp = date('Y-m-d H:i:s', time() + 72 * 3600);
    $now = dl_now(); $u = dl_uid() ?: null;
    $s = mysqli_prepare($conn, "INSERT INTO dl_tasker_accounts (party_id, status, token_hash, token_expires, created_by, created_at) VALUES (?, 'pending', ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE token_hash = VALUES(token_hash), token_expires = VALUES(token_expires), status = IF(status = 'blocked', 'blocked', status)");
    mysqli_stmt_bind_param($s, 'issis', $pid, $hash, $exp, $u, $now);
    mysqli_stmt_execute($s);
    $link = 'https://' . (defined('SMS_PORTAL_URL_C') ? SMS_PORTAL_URL_C : 'myspace.risemotive.rw/portal') . '/tasker_activate.php?t=' . $token;
    $sms = false; $mail = false;
    try {
        require_once __DIR__ . '/sms_center.php';
        $sms = sms_notify($conn, 'DL_ACCESS', $t['phone'], ['tasker_name' => trim(explode(' ', (string) $t['name'])[0]), 'tasker_id' => $t['code'], 'link' => $link], ['to' => 'tasker', 'ref_type' => 'tasker', 'ref_id' => $pid, 'user' => $u]);
    } catch (Throwable $e) { error_log('dl access sms: ' . $e->getMessage()); }
    if (!empty($t['email']) && is_file(__DIR__ . '/mailer.php')) {
        try { require_once __DIR__ . '/mailer.php'; $mail = send_email($t['email'], $t['display'], 'Your RM Tasker access to the RISE MOTIVE portal', '<p>Dear ' . dl_e($t['name']) . ',</p><p>Your RM Tasker ID is <b>' . dl_e($t['code']) . '</b>. Set your password within 72 hours: <a href="' . dl_e($link) . '">' . dl_e($link) . '</a></p><p>You will see only your own assignments and payments.</p><p>RISE MOTIVE</p>'); } catch (Throwable $e) { error_log('dl access mail: ' . $e->getMessage()); }
    }
    dl_audit($conn, 'tasker', $pid, 'portal_access_sent', $t['code'] . ': portal access link sent (SMS ' . ($sms ? 'sent' : 'not sent') . ', e-mail ' . ($mail ? 'sent' : 'not sent') . ')', null, 'staff', $pid);
    return ['sms' => $sms, 'email' => $mail, 'link' => $link];
}

/** Saves the files of a multiple upload field (instructions or completion evidence). Returns [saved, error]. */
function dl_save_files(mysqli $conn, int $did, string $field, string $kind, ?int $partyId = null): array
{
    require_once __DIR__ . '/evidence_store.php';
    $f = $_FILES[$field] ?? null;
    if (!$f || !is_array($f['name'])) { return [0, null]; }
    $types = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx', 'application/zip' => 'zip'];
    $n = 0; $err = null;
    foreach ($f['name'] as $i => $name) {
        if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { continue; }
        if ($n >= 10) { $err = 'At most 10 files at a time.'; break; }
        $one = ['name' => $name, 'type' => $f['type'][$i] ?? '', 'tmp_name' => $f['tmp_name'][$i] ?? '', 'error' => $f['error'][$i] ?? 0, 'size' => $f['size'][$i] ?? 0];
        try {
            $ref = evidence_save($conn, $one, $partyId ? null : (dl_uid() ?: null), $types, 10 * 1048576);
            if (!$ref) { continue; }
            $now = dl_now(); $u = $partyId ? null : (dl_uid() ?: null); $nm = mb_substr(basename((string) $name), 0, 200);
            $s = mysqli_prepare($conn, 'INSERT INTO dl_files (delegation_id, kind, ref, name, user_id, party_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)');
            mysqli_stmt_bind_param($s, 'isssiis', $did, $kind, $ref, $nm, $u, $partyId, $now);
            mysqli_stmt_execute($s);
            $n++;
        } catch (Throwable $e) { $err = basename((string) $name) . ': ' . $e->getMessage(); }
    }
    return [$n, $err];
}
function dl_files(mysqli $conn, int $did, ?string $kind = null): array
{
    return mysqli_fetch_all(mysqli_query($conn, 'SELECT * FROM dl_files WHERE delegation_id = ' . $did . ($kind ? " AND kind = '" . ($kind === 'evidence' ? 'evidence' : 'instruction') . "'" : '') . ' ORDER BY id'), MYSQLI_ASSOC);
}

/** Reasons a delegation needs a manager's approval before it goes to the Tasker. */
function dl_approval_reasons(array $o, ?array $best, array $chosen, float $hours, array $profit): array
{
    $r = [];
    if ($best && (int) $best['party'] !== (int) $chosen['party']) { $r[] = 'another RM Tasker than the recommended one (' . $best['display'] . ')'; }
    if ($o['expected_rate'] !== null && (float) $chosen['rate'] > (float) $o['expected_rate'] + 0.001) { $r[] = 'rate ' . dl_pct($chosen['rate']) . ' above the expected ' . dl_pct($o['expected_rate']); }
    if ($o['max_hours'] && $hours > (float) $o['max_hours'] + 0.01) { $r[] = 'deadline beyond the maximum allowed time (' . dl_hours_text($o['max_hours']) . ')'; }
    if ($profit['final'] < 0) { $r[] = 'expected loss of ' . dl_money(-$profit['final']); }
    if ($profit['pool'] <= 0) { $r[] = 'no profit pool after cost of sales'; }
    return $r;
}