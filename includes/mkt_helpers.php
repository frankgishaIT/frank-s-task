<?php
/**
 * NEW FILE: includes/mkt_helpers.php — MARKETING & GROWTH engine.
 *
 * Built ON existing MY MOTIVE records (no duplicates): team members = users (Employees), offerings = products,
 * customers = customers, money = sales + transactions, activities = tasks (stage 3), files = Digital Library.
 *
 * "Verified paying customer": a sale with status Paid whose income transaction (sales.transaction_id) is APPROVED in
 * Transactions, dated by sales.fully_paid_at (credit sales) or the sale date (paid at checkout). An unpaid invoice,
 * a cancelled sale or a deleted transaction never counts.
 * Eligible profit = the existing rule calculate_sale_profit() (products: net minus purchase cost; services: 80% rule),
 * the same basis as Loyalty Points.
 *
 * Policies not yet final are SETTINGS with documented defaults (Marketing Settings), never hard-coded.
 */
require_once __DIR__ . '/rm_org.php';

const MKT_PLAN_FLOW = ['Draft', 'Submitted for Approval', 'Approved', 'Active', 'Completed', 'Evaluated'];
const MKT_CAMPAIGN_FLOW = ['Draft', 'Pending Approval', 'Approved', 'Active', 'Paused', 'Completed', 'Evaluated', 'Archived'];
const MKT_LEAD_FLOW = ['New', 'Contacted', 'Interested', 'Qualified', 'Demonstration Scheduled', 'Proposal Sent', 'Negotiation', 'Won', 'Lost'];
const MKT_QUALIFIED = ['Qualified', 'Demonstration Scheduled', 'Proposal Sent', 'Negotiation', 'Won'];

/** Settings with their documented defaults (management can change them; changes apply to the future only). */
const MKT_DEFAULTS = [
    'commission_rate'       => '20',    // % of eligible net profit from a newly acquired paying customer (proposed default)
    'commission_months'     => '3',     // commission period: months after the first verified payment
    'partial_policy'        => 'full',  // 'full' = only fully paid sales count (same rule as Loyalty Points)
    'behind_tolerance'      => '15',    // a plan / campaign is "behind" when achievement is this many points below elapsed time
    'channels'              => "Direct visit\nPhone call\nWhatsApp\nFacebook\nLinkedIn\nInstagram\nWebsite\nEmail\nSMS\nEvents\nPartnerships\nReferral",
    'lead_sources'          => "Direct Visit\nReferral\nFacebook\nLinkedIn\nWhatsApp\nWebsite\nPhone Call\nEmail\nPartnership\nExisting Customer Referral\nOther",
    'customer_types'        => "Individual\nSmall business\nCompany\nNGO\nGovernment institution\nSchool\nCooperative\nOther",
];

function mkt_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    $q = function ($s) use ($conn) { mysqli_query($conn, $s); };
    $q('CREATE TABLE IF NOT EXISTS mkt_settings (name VARCHAR(40) NOT NULL, value TEXT NULL, PRIMARY KEY (name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q('CREATE TABLE IF NOT EXISTS mkt_audit (id INT(11) NOT NULL AUTO_INCREMENT, user_id INT(11) NULL, action VARCHAR(255) NOT NULL, reference VARCHAR(40) NULL,
        previous_value TEXT NULL, new_value TEXT NULL, reason TEXT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY reference (reference)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q('CREATE TABLE IF NOT EXISTS mkt_alerts (ref VARCHAR(40) NOT NULL, kind VARCHAR(30) NOT NULL, sent_at DATETIME NOT NULL, PRIMARY KEY (ref, kind)) ENGINE=InnoDB');
    $q("CREATE TABLE IF NOT EXISTS mkt_plans (id INT(11) NOT NULL AUTO_INCREMENT, title VARCHAR(160) NOT NULL, description TEXT NULL, objective TEXT NULL,
        product_ids VARCHAR(500) NULL, target_market VARCHAR(255) NULL, customer_type VARCHAR(80) NULL, start_date DATE NOT NULL, end_date DATE NOT NULL,
        expected_leads INT(11) NOT NULL DEFAULT 0, expected_customers INT(11) NOT NULL DEFAULT 0, expected_revenue DECIMAL(16,2) NOT NULL DEFAULT 0,
        expected_profit DECIMAL(16,2) NOT NULL DEFAULT 0, budget DECIMAL(16,2) NOT NULL DEFAULT 0, department_id INT(11) NULL, manager_id INT(11) NULL,
        channels VARCHAR(500) NULL, kpis TEXT NULL, status VARCHAR(30) NOT NULL DEFAULT 'Draft', evaluation TEXT NULL, created_by INT(11) NULL,
        approved_by INT(11) NULL, approved_at DATETIME NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL,
        PRIMARY KEY (id), KEY status (status), KEY manager (manager_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q('CREATE TABLE IF NOT EXISTS mkt_plan_members (plan_id INT(11) NOT NULL, user_id INT(11) NOT NULL, PRIMARY KEY (plan_id, user_id), KEY user_id (user_id)) ENGINE=InnoDB');
    $q("CREATE TABLE IF NOT EXISTS mkt_campaigns (id INT(11) NOT NULL AUTO_INCREMENT, plan_id INT(11) NULL, name VARCHAR(160) NOT NULL, objective TEXT NULL, description TEXT NULL,
        product_ids VARCHAR(500) NULL, target_audience VARCHAR(255) NULL, target_orgs TEXT NULL, message TEXT NULL, channels VARCHAR(500) NULL,
        start_date DATE NOT NULL, end_date DATE NOT NULL, budget DECIMAL(16,2) NOT NULL DEFAULT 0, expected_leads INT(11) NOT NULL DEFAULT 0,
        expected_customers INT(11) NOT NULL DEFAULT 0, expected_revenue DECIMAL(16,2) NOT NULL DEFAULT 0, expected_profit DECIMAL(16,2) NOT NULL DEFAULT 0,
        manager_id INT(11) NULL, kpis TEXT NULL, reached INT(11) NULL, inquiries INT(11) NULL, status VARCHAR(30) NOT NULL DEFAULT 'Draft', evaluation TEXT NULL,
        ai_generated TINYINT(1) NOT NULL DEFAULT 0, created_by INT(11) NULL, approved_by INT(11) NULL, approved_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL,
        PRIMARY KEY (id), KEY plan_id (plan_id), KEY status (status), KEY manager (manager_id), KEY dates (start_date, end_date)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q('CREATE TABLE IF NOT EXISTS mkt_campaign_members (campaign_id INT(11) NOT NULL, user_id INT(11) NOT NULL, PRIMARY KEY (campaign_id, user_id), KEY user_id (user_id)) ENGINE=InnoDB');
    // Leads, follow-ups and acquisitions (pages in stage 2; created now so campaign results are measured from the start).
    $q("CREATE TABLE IF NOT EXISTS mkt_leads (id INT(11) NOT NULL AUTO_INCREMENT, name VARCHAR(160) NOT NULL, contact_person VARCHAR(120) NULL, phone VARCHAR(30) NULL,
        email VARCHAR(120) NULL, location VARCHAR(160) NULL, org_type VARCHAR(80) NULL, product_id INT(11) NULL, source VARCHAR(60) NULL, campaign_id INT(11) NULL,
        owner_id INT(11) NULL, estimated_value DECIMAL(16,2) NULL, notes TEXT NULL, next_followup DATE NULL, status VARCHAR(30) NOT NULL DEFAULT 'New',
        lost_reason VARCHAR(500) NULL, customer_id INT(11) NULL, converted_at DATETIME NULL, created_by INT(11) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL,
        PRIMARY KEY (id), KEY campaign_id (campaign_id), KEY owner_id (owner_id), KEY status (status), KEY phone (phone), KEY next_followup (next_followup)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS mkt_followups (id INT(11) NOT NULL AUTO_INCREMENT, lead_id INT(11) NOT NULL, user_id INT(11) NULL, method VARCHAR(40) NULL,
        note TEXT NOT NULL, status_after VARCHAR(30) NULL, next_date DATE NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY lead_id (lead_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS mkt_acquisitions (id INT(11) NOT NULL AUTO_INCREMENT, customer_id INT(11) NOT NULL, lead_id INT(11) NULL, campaign_id INT(11) NULL,
        owner_id INT(11) NULL, acquired_at DATETIME NOT NULL, first_paid_sale_id INT(11) NULL, first_paid_at DATETIME NULL, status VARCHAR(20) NOT NULL DEFAULT 'Awaiting payment',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), UNIQUE KEY one_per_customer (customer_id), KEY campaign_id (campaign_id), KEY owner_id (owner_id)) ENGINE=InnoDB");
}

/* ------------------------------------------------------------------ settings, codes, audit */

function mkt_setting(mysqli $conn, string $name): string
{
    $s = mysqli_prepare($conn, 'SELECT value FROM mkt_settings WHERE name = ?');
    mysqli_stmt_bind_param($s, 's', $name); mysqli_stmt_execute($s);
    $r = mysqli_fetch_row(mysqli_stmt_get_result($s));
    return $r ? (string) $r[0] : (MKT_DEFAULTS[$name] ?? '');
}
function mkt_list_setting(mysqli $conn, string $name): array
{
    return array_values(array_filter(array_map('trim', preg_split('/\r?\n/', mkt_setting($conn, $name))), 'strlen'));
}

function mkt_code(string $kind, array $row): string
{
    $p = ['plan' => 'MKP', 'campaign' => 'CMP', 'lead' => 'LEAD'][$kind];
    return $p . '-' . date('Y', strtotime($row['created_at'] ?? 'now')) . '-' . str_pad((string) $row['id'], $kind === 'lead' ? 5 : 4, '0', STR_PAD_LEFT);
}

function mkt_audit(mysqli $conn, string $action, ?string $ref, $prev, $new, ?string $reason, ?int $userId = null): void
{
    $uid = $userId ?? (int) (function_exists('current_user_id') ? current_user_id() : 0);
    $enc = function ($v) { return $v === null ? null : (is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE)); };
    $a = mb_substr($action, 0, 255); $p = $enc($prev); $n = $enc($new);
    $s = mysqli_prepare($conn, 'INSERT INTO mkt_audit (user_id, action, reference, previous_value, new_value, reason) VALUES (?, ?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($s, 'isssss', $uid, $a, $ref, $p, $n, $reason); mysqli_stmt_execute($s);
}

function mkt_notify(mysqli $conn, array $userIds, string $title, string $message): void
{
    require_once __DIR__ . '/notification_helper.php';
    foreach (array_unique(array_filter(array_map('intval', $userIds))) as $u) {
        try { notifyUser($conn, $u, $title, $message); } catch (Throwable $e) { error_log('mkt_notify: ' . $e->getMessage()); }
    }
}

/** Administrators (Managing Director / authorised management): approvals and alerts. */
function mkt_admin_ids(mysqli $conn): array
{
    return array_map('intval', array_column(mysqli_fetch_all(mysqli_query($conn, "SELECT id FROM users WHERE is_active = 1 AND LOWER(role) = 'admin'"), MYSQLI_ASSOC), 'id'));
}

/* ------------------------------------------------------------------ workflows */

/**
 * Allowed status changes: [from => [action => [to, permission]]]. Approve / activate / evaluate need 'approve'.
 * Plans:     Draft → Submitted for Approval → Approved → Active → Completed → Evaluated.
 * Campaigns: Draft → Pending Approval → Approved → Active → Paused → Completed → Evaluated → Archived.
 */
function mkt_transitions(string $kind): array
{
    if ($kind === 'plan') {
        return ['Draft' => ['submit' => ['Submitted for Approval', 'create']], 'Submitted for Approval' => ['approve' => ['Approved', 'approve'], 'reject' => ['Draft', 'approve']],
                'Approved' => ['activate' => ['Active', 'approve']], 'Active' => ['complete' => ['Completed', 'create']], 'Completed' => ['evaluate' => ['Evaluated', 'approve']]];
    }
    return ['Draft' => ['submit' => ['Pending Approval', 'create']], 'Pending Approval' => ['approve' => ['Approved', 'approve'], 'reject' => ['Draft', 'approve']],
            'Approved' => ['activate' => ['Active', 'approve']], 'Active' => ['pause' => ['Paused', 'create'], 'complete' => ['Completed', 'create']],
            'Paused' => ['resume' => ['Active', 'create'], 'complete' => ['Completed', 'create']], 'Completed' => ['evaluate' => ['Evaluated', 'approve']],
            'Evaluated' => ['archive' => ['Archived', 'approve']]];
}

/* ------------------------------------------------------------------ verified results */

/** Fully paid sales whose income transaction is approved (verified payments), for these customers, in [from, to]. */
function mkt_verified_sales(mysqli $conn, array $customerWindows): array
{
    $out = [];
    foreach ($customerWindows as $cid => [$from, $to]) {
        $s = mysqli_prepare($conn, "SELECT s.id, s.customer_id, s.total_amount, COALESCE(s.fully_paid_at, s.sale_date) AS paid_at FROM sales s
            JOIN transactions t ON t.id = s.transaction_id AND t.status = 'approved'
            WHERE s.customer_id = ? AND s.status = 'Paid' AND DATE(COALESCE(s.fully_paid_at, s.sale_date)) BETWEEN ? AND ? ORDER BY paid_at, s.id");
        mysqli_stmt_bind_param($s, 'iss', $cid, $from, $to); mysqli_stmt_execute($s);
        foreach (mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC) as $r) { $out[] = $r; }
    }
    return $out;
}

/** Eligible profit of one sale (the existing MY MOTIVE profit rule). */
function mkt_sale_profit(mysqli $conn, int $saleId): float
{
    if (!function_exists('calculate_sale_profit')) { require_once __DIR__ . '/sales_helpers.php'; }
    $p = calculate_sale_profit($conn, $saleId);
    return round((float) ($p['total_profit'] ?? 0), 2);
}

/**
 * Actual results of a set of campaigns. Revenue and profit: verified paid sales of the customers they acquired, from
 * the acquisition date to the end of the commission period after the first verified payment.
 */
function mkt_actuals(mysqli $conn, array $campaignIds): array
{
    $ids = array_values(array_filter(array_map('intval', $campaignIds)));
    $r = ['leads' => 0, 'qualified' => 0, 'demos' => 0, 'proposals' => 0, 'won' => 0, 'lost' => 0, 'acquired' => 0, 'paying' => 0,
          'revenue' => 0.0, 'profit' => 0.0, 'expenses' => 0.0, 'reached' => null, 'inquiries' => null];
    if (!$ids) { return $r; }
    $in = implode(',', $ids);
    $q = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) leads,
        SUM(status IN ('" . implode("','", MKT_QUALIFIED) . "')) qualified, SUM(status IN ('Demonstration Scheduled','Proposal Sent','Negotiation','Won')) demos,
        SUM(status IN ('Proposal Sent','Negotiation','Won')) proposals, SUM(status = 'Won') won, SUM(status = 'Lost') lost FROM mkt_leads WHERE campaign_id IN ($in)"));
    foreach (['leads', 'qualified', 'demos', 'proposals', 'won', 'lost'] as $k) { $r[$k] = (int) $q[$k]; }
    $m = mysqli_fetch_assoc(mysqli_query($conn, "SELECT SUM(reached) reached, SUM(inquiries) inquiries, COUNT(reached) nr, COUNT(inquiries) ni FROM mkt_campaigns WHERE id IN ($in)"));
    $r['reached'] = (int) $m['nr'] > 0 ? (int) $m['reached'] : null; $r['inquiries'] = (int) $m['ni'] > 0 ? (int) $m['inquiries'] : null;
    $months = max(0, (int) mkt_setting($conn, 'commission_months'));
    $windows = [];
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM mkt_acquisitions WHERE campaign_id IN ($in) AND status <> 'Duplicate'"), MYSQLI_ASSOC) as $a) {
        $r['acquired']++;
        if ($a['first_paid_at']) {
            $r['paying']++;
            $windows[(int) $a['customer_id']] = [substr($a['acquired_at'], 0, 10), $months > 0 ? date('Y-m-d', strtotime($a['first_paid_at'] . " +$months months")) : substr($a['first_paid_at'], 0, 10)];
        }
    }
    foreach (mkt_verified_sales($conn, $windows) as $s) { $r['revenue'] += (float) $s['total_amount']; $r['profit'] += mkt_sale_profit($conn, (int) $s['id']); }
    $r['revenue'] = round($r['revenue'], 2); $r['profit'] = round($r['profit'], 2);
    // Marketing expenses: references to approved expense transactions (linked in stage 4; never a copy).
    if ((int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'mkt_expense_links'"))[0]) {
        $r['expenses'] = round((float) mysqli_fetch_row(mysqli_query($conn, "SELECT COALESCE(SUM(t.amount), 0) FROM mkt_expense_links l JOIN transactions t ON t.id = l.transaction_id
            WHERE l.campaign_id IN ($in) AND t.transaction_type = 'Expense' AND t.status = 'approved'"))[0], 2);
    }
    return $r;
}

/** Percentage, or null (shown as N/A) when the denominator is zero or missing. */
function mkt_pct(?float $num, ?float $den): ?float
{
    return ($num === null || $den === null || abs($den) < 0.00001) ? null : round(100 * $num / $den, 1);
}
function mkt_pct_text(?float $v): string { return $v === null ? 'N/A' : rtrim(rtrim(number_format($v, 1), '0'), '.') . '%'; }

/**
 * Performance of a campaign or plan (row with expected_* and budget, plus actuals):
 *   Target Achievement % = actual ÷ target × 100 (per target)
 *   Lead Conversion %    = paying customers ÷ qualified leads × 100
 *   ROI %                = (attributable eligible profit − marketing cost) ÷ marketing cost × 100
 * Any zero or missing denominator gives N/A, never a wrong number.
 */
function mkt_performance(array $row, array $act): array
{
    $ach = ['leads' => mkt_pct($act['leads'], (float) $row['expected_leads']), 'customers' => mkt_pct($act['paying'], (float) $row['expected_customers']),
            'revenue' => mkt_pct($act['revenue'], (float) $row['expected_revenue']), 'profit' => mkt_pct($act['profit'], (float) $row['expected_profit'])];
    $set = array_filter($ach, function ($v) { return $v !== null; });
    $start = strtotime($row['start_date']); $end = strtotime($row['end_date'] . ' 23:59:59'); $now = time();
    $elapsed = $end > $start ? max(0, min(100, round(100 * ($now - $start) / ($end - $start), 1))) : null;
    return ['achievement' => $ach, 'overall' => $set ? round(array_sum($set) / count($set), 1) : null,
            'conversion' => mkt_pct($act['paying'], $act['qualified']), 'roi' => mkt_pct($act['profit'] - $act['expenses'], $act['expenses']),
            'cac' => $act['paying'] > 0 && $act['expenses'] > 0 ? round($act['expenses'] / $act['paying'], 2) : null,
            'budget_used' => mkt_pct($act['expenses'], (float) $row['budget']), 'elapsed' => $elapsed,
            'days_left' => max(0, (int) round((strtotime($row['end_date']) - strtotime(date('Y-m-d'))) / 86400)), // whole days until the end date
            'remaining' => ['leads' => max(0, (int) $row['expected_leads'] - $act['leads']), 'customers' => max(0, (int) $row['expected_customers'] - $act['paying']),
                            'revenue' => max(0, round((float) $row['expected_revenue'] - $act['revenue'], 2)), 'profit' => max(0, round((float) $row['expected_profit'] - $act['profit'], 2))]];
}

/** Behind schedule: the weakest set target is more than the tolerance below the share of time already elapsed. */
function mkt_is_behind(mysqli $conn, array $perf): bool
{
    $set = array_filter($perf['achievement'], function ($v) { return $v !== null; });
    if (!$set || $perf['elapsed'] === null || $perf['elapsed'] < 10) { return false; }
    return min($set) < $perf['elapsed'] - (float) mkt_setting($conn, 'behind_tolerance');
}

/** Alerts the responsible manager (and Administrators) when an active plan or campaign falls behind; at most weekly. */
function mkt_check_behind(mysqli $conn): int
{
    $sent = 0;
    foreach (['plan' => "SELECT * FROM mkt_plans WHERE status = 'Active'", 'campaign' => "SELECT * FROM mkt_campaigns WHERE status = 'Active'"] as $kind => $sql) {
        foreach (mysqli_fetch_all(mysqli_query($conn, $sql), MYSQLI_ASSOC) as $row) {
            $ids = $kind === 'plan' ? array_column(mysqli_fetch_all(mysqli_query($conn, 'SELECT id FROM mkt_campaigns WHERE plan_id = ' . (int) $row['id']), MYSQLI_ASSOC), 'id') : [(int) $row['id']];
            $perf = mkt_performance($row, mkt_actuals($conn, $ids));
            if (!mkt_is_behind($conn, $perf)) { continue; }
            $ref = mkt_code($kind, $row);
            $last = mysqli_fetch_row(mysqli_query($conn, "SELECT sent_at FROM mkt_alerts WHERE ref = '" . mysqli_real_escape_string($conn, $ref) . "' AND kind = 'behind'"));
            if ($last && strtotime($last[0]) > strtotime('-7 days')) { continue; }
            mysqli_query($conn, "REPLACE INTO mkt_alerts (ref, kind, sent_at) VALUES ('" . mysqli_real_escape_string($conn, $ref) . "', 'behind', NOW())");
            $name = $row['title'] ?? $row['name'];
            mkt_notify($conn, array_merge([(int) $row['manager_id']], mkt_admin_ids($conn)), ucfirst($kind) . ' behind target: ' . $name,
                'Time elapsed ' . $perf['elapsed'] . '%, weakest target at ' . mkt_pct_text(min(array_filter($perf['achievement'], function ($v) { return $v !== null; }))) . '. ' . $perf['days_left'] . ' day(s) left.');
            mkt_audit($conn, 'Behind-target alert sent', $ref, null, $perf['overall'], null, 0);
            $sent++;
        }
    }
    return $sent;
}

/* ------------------------------------------------------------------ visibility */

/** Administrators and Managers (by account type): reassign leads, propose contributions, run anyone's campaigns. */
function mkt_is_manager(): bool
{
    return function_exists('current_user_role') && in_array(current_user_role(), ['Admin', 'Manager'], true);
}
/** May this user run (submit, pause, complete, edit) this plan / campaign? Its creator, its manager, or a Manager / Admin. */
function mkt_may_run(array $row, int $uid): bool
{
    return mkt_is_manager() || (int) $row['created_by'] === $uid || (int) $row['manager_id'] === $uid;
}

/** Users who see everything (approvers, settings managers); others see what they manage or are members of. */
function mkt_sees_all(mysqli $conn): bool
{
    return function_exists('access_can') && (access_can('marketing.approve', $conn) || access_can('marketing.manage', $conn));
}
/** CHANGED (management decision): everyone with access to Marketing & Growth sees all plans, campaigns and leads. */
function mkt_campaign_scope_sql(mysqli $conn, int $uid, string $alias = 'c'): string
{
    if (mkt_sees_all($conn) || (function_exists('access_can') && access_can('marketing.view', $conn))) { return '1 = 1'; }
    return "($alias.manager_id = $uid OR $alias.created_by = $uid OR EXISTS (SELECT 1 FROM mkt_campaign_members m WHERE m.campaign_id = $alias.id AND m.user_id = $uid))";
}
function mkt_plan_scope_sql(mysqli $conn, int $uid, string $alias = 'p'): string
{
    if (mkt_sees_all($conn) || (function_exists('access_can') && access_can('marketing.view', $conn))) { return '1 = 1'; }
    return "($alias.manager_id = $uid OR $alias.created_by = $uid OR EXISTS (SELECT 1 FROM mkt_plan_members m WHERE m.plan_id = $alias.id AND m.user_id = $uid))";
}

/* ================================================================== STAGE 2: LEADS & CUSTOMER ACQUISITION */

const MKT_CONTRIB_ROLES = ['Found the prospect', 'Designed promotional material', 'Conducted the demonstration', 'Followed up and closed', 'Prepared the proposal', 'Partnership introduction', 'Other support'];
const MKT_FOLLOWUP_METHODS = ['Phone call', 'Visit', 'WhatsApp', 'Email', 'SMS', 'Meeting', 'Other'];

function mkt_ensure_leads(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    mkt_ensure($conn);
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS mkt_lead_contributors (id INT(11) NOT NULL AUTO_INCREMENT, lead_id INT(11) NOT NULL, user_id INT(11) NOT NULL,
        role VARCHAR(60) NOT NULL, share_pct DECIMAL(5,2) NULL, points INT(11) NULL, reason VARCHAR(500) NULL, status VARCHAR(12) NOT NULL DEFAULT 'Proposed',
        added_by INT(11) NULL, approved_by INT(11) NULL, approved_at DATETIME NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), UNIQUE KEY one_role (lead_id, user_id, role), KEY user_id (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/** Phone numbers compared on their last 9 digits (07XXXXXXXX, +2507XXXXXXXX and 2507XXXXXXXX are the same number). */
function mkt_phone_key(?string $phone): string
{
    $d = preg_replace('/\D/', '', (string) $phone);
    return strlen($d) >= 9 ? substr($d, -9) : $d;
}

function mkt_lead_open(array $l): bool { return !in_array($l['status'], ['Won', 'Lost'], true); }

/** An OPEN lead with the same phone or email (duplicate), or null. */
function mkt_duplicate_lead(mysqli $conn, ?string $phone, ?string $email, int $exceptId = 0): ?array
{
    $pk = mkt_phone_key($phone); $em = strtolower(trim((string) $email));
    if ($pk === '' && $em === '') { return null; }
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM mkt_leads WHERE status NOT IN ('Won', 'Lost') AND id <> $exceptId AND (phone IS NOT NULL OR email IS NOT NULL)"), MYSQLI_ASSOC) as $l) {
        if (($pk !== '' && strlen($pk) >= 9 && mkt_phone_key($l['phone']) === $pk) || ($em !== '' && strtolower(trim((string) $l['email'])) === $em)) { return $l; }
    }
    return null;
}

function mkt_col_exists(mysqli $conn, string $table, string $col): bool
{
    $s = mysqli_prepare($conn, 'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
    mysqli_stmt_bind_param($s, 'ss', $table, $col); mysqli_stmt_execute($s);
    return (int) mysqli_fetch_row(mysqli_stmt_get_result($s))[0] > 0;
}

/** Existing customer with the same phone (last 9 digits) or email, or null. */
function mkt_matching_customer(mysqli $conn, ?string $phone, ?string $email): ?array
{
    $pk = mkt_phone_key($phone);
    if (strlen($pk) >= 9) {
        $like = '%' . $pk;
        $s = mysqli_prepare($conn, "SELECT * FROM customers WHERE REPLACE(REPLACE(REPLACE(phone, ' ', ''), '+', ''), '-', '') LIKE ? ORDER BY id LIMIT 1");
        mysqli_stmt_bind_param($s, 's', $like); mysqli_stmt_execute($s);
        if ($c = mysqli_fetch_assoc(mysqli_stmt_get_result($s))) { return $c; }
    }
    $em = strtolower(trim((string) $email));
    if ($em !== '' && mkt_col_exists($conn, 'customers', 'email')) {
        $s = mysqli_prepare($conn, 'SELECT * FROM customers WHERE LOWER(TRIM(email)) = ? ORDER BY id LIMIT 1');
        mysqli_stmt_bind_param($s, 's', $em); mysqli_stmt_execute($s);
        if ($c = mysqli_fetch_assoc(mysqli_stmt_get_result($s))) { return $c; }
    }
    return null;
}

/** A verified paid purchase BEFORE a date = the customer is not new. */
function mkt_paid_before(mysqli $conn, int $customerId, string $date): bool
{
    $s = mysqli_prepare($conn, "SELECT COUNT(*) FROM sales s JOIN transactions t ON t.id = s.transaction_id AND t.status = 'approved'
        WHERE s.customer_id = ? AND s.status = 'Paid' AND DATE(COALESCE(s.fully_paid_at, s.sale_date)) < ?");
    mysqli_stmt_bind_param($s, 'is', $customerId, $date); mysqli_stmt_execute($s);
    return (int) mysqli_fetch_row(mysqli_stmt_get_result($s))[0] > 0;
}

/**
 * Converts a lead into a customer: links an existing customer (same phone / email) or creates one, marks the lead Won,
 * and records the acquisition (lead, campaign, owner preserved). One acquisition per customer. Returns a summary.
 */
function mkt_convert_lead(mysqli $conn, int $leadId, int $uid, ?int $chosenCustomerId = null): array
{
    mysqli_begin_transaction($conn);
    try {
        $l = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM mkt_leads WHERE id = $leadId FOR UPDATE"));
        if (!$l || !mkt_lead_open($l)) { throw new RuntimeException('Only an open lead can be converted.'); }
        if ($chosenCustomerId) {
            $cust = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM customers WHERE id = ' . (int) $chosenCustomerId));
            if (!$cust) { throw new RuntimeException('That customer does not exist.'); }
        } else { $cust = mkt_matching_customer($conn, $l['phone'], $l['email']); }
        $created = false;
        if (!$cust) {
            if (mkt_phone_key($l['phone']) === '' && trim((string) $l['email']) === '') { throw new RuntimeException('Add the phone number or email first, so the customer can be matched and contacted.'); }
            $name = $l['name']; $cols = ['name' => $name, 'phone' => $l['phone']];
            if (mkt_col_exists($conn, 'customers', 'email') && $l['email']) { $cols['email'] = $l['email']; }
            if (mkt_col_exists($conn, 'customers', 'address') && $l['location']) { $cols['address'] = $l['location']; }
            if (mkt_col_exists($conn, 'customers', 'first_name')) { $parts = preg_split('/\s+/', trim((string) ($l['contact_person'] ?: $name)), 2); $cols['first_name'] = $parts[0]; if (mkt_col_exists($conn, 'customers', 'last_name')) { $cols['last_name'] = $parts[1] ?? ''; } }
            $s = mysqli_prepare($conn, 'INSERT INTO customers (' . implode(', ', array_keys($cols)) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')');
            $vals = array_values($cols); mysqli_stmt_bind_param($s, str_repeat('s', count($vals)), ...$vals); mysqli_stmt_execute($s);
            $cust = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM customers WHERE id = ' . (int) mysqli_insert_id($conn))); $created = true;
        }
        $cid = (int) $cust['id'];
        $existing = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM mkt_acquisitions WHERE customer_id = $cid"));
        if ($existing) {
            $prev = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT id, created_at FROM mkt_leads WHERE id = ' . (int) $existing['lead_id']));
            throw new RuntimeException('This customer was already acquired' . ($prev ? ' through ' . mkt_code('lead', $prev) : '') . '. One customer can only be attributed once.');
        }
        $isNew = $created || !mkt_paid_before($conn, $cid, substr($l['created_at'], 0, 10));
        $status = $isNew ? 'Awaiting payment' : 'Existing customer';
        $s = mysqli_prepare($conn, 'INSERT INTO mkt_acquisitions (customer_id, lead_id, campaign_id, owner_id, acquired_at, status) VALUES (?, ?, ?, ?, NOW(), ?)');
        mysqli_stmt_bind_param($s, 'iiiis', $cid, $leadId, $l['campaign_id'], $l['owner_id'], $status); mysqli_stmt_execute($s);
        mysqli_query($conn, "UPDATE mkt_leads SET status = 'Won', customer_id = $cid, converted_at = NOW(), next_followup = NULL, updated_at = NOW() WHERE id = $leadId");
        $ref = mkt_code('lead', $l);
        mkt_audit($conn, 'Lead converted: ' . ($created ? 'new customer created' : 'linked to existing customer #' . $cid) . ($isNew ? '' : ' (already a paying customer before: not a new acquisition)'), $ref, $l['status'], 'Won', null, $uid);
        mysqli_commit($conn);
        mkt_refresh_acquisitions($conn, $cid);
        return ['customer_id' => $cid, 'created' => $created, 'new' => $isNew, 'name' => $cust['name']];
    } catch (Throwable $e) { mysqli_rollback($conn); throw $e; }
}

/**
 * First verified payment of acquired customers: the earliest fully paid sale with an APPROVED income transaction,
 * paid on or after the acquisition. An invoice alone never makes a paying customer.
 */
function mkt_refresh_acquisitions(mysqli $conn, ?int $customerId = null): int
{
    $n = 0;
    $w = $customerId ? ' AND customer_id = ' . (int) $customerId : '';
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM mkt_acquisitions WHERE status = 'Awaiting payment'$w"), MYSQLI_ASSOC) as $a) {
        $s = mysqli_prepare($conn, "SELECT s.id, COALESCE(s.fully_paid_at, s.sale_date) paid_at FROM sales s JOIN transactions t ON t.id = s.transaction_id AND t.status = 'approved'
            WHERE s.customer_id = ? AND s.status = 'Paid' AND COALESCE(s.fully_paid_at, s.sale_date) >= DATE(?) ORDER BY paid_at, s.id LIMIT 1");
        $cid = (int) $a['customer_id']; mysqli_stmt_bind_param($s, 'is', $cid, $a['acquired_at']); mysqli_stmt_execute($s);
        $sale = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
        if (!$sale) { continue; }
        $u = mysqli_prepare($conn, "UPDATE mkt_acquisitions SET first_paid_sale_id = ?, first_paid_at = ?, status = 'Paying' WHERE id = ? AND status = 'Awaiting payment'");
        $sid = (int) $sale['id']; $aid = (int) $a['id']; mysqli_stmt_bind_param($u, 'isi', $sid, $sale['paid_at'], $aid); mysqli_stmt_execute($u);
        if (mysqli_stmt_affected_rows($u) === 1) {
            $n++;
            $lead = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM mkt_leads WHERE id = ' . (int) $a['lead_id']));
            $ref = $lead ? mkt_code('lead', $lead) : 'ACQ-' . $aid;
            mkt_audit($conn, 'First verified payment: sale #' . $sid . ' (paid, approved in Transactions)', $ref, 'Awaiting payment', 'Paying', null, 0);
            mkt_notify($conn, [(int) $a['owner_id']], 'New paying customer: ' . ($lead['name'] ?? 'customer'), 'First verified payment received (sale #' . $sid . ').');
        }
    }
    return $n;
}

/** Follow-up reminders to lead owners: due today, and overdue. At most once per day per lead. */
function mkt_followup_reminders(mysqli $conn): int
{
    $n = 0; $today = date('Y-m-d');
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM mkt_leads WHERE status NOT IN ('Won', 'Lost') AND next_followup IS NOT NULL AND next_followup <= CURDATE() AND owner_id IS NOT NULL"), MYSQLI_ASSOC) as $l) {
        $ref = mkt_code('lead', $l); $kind = 'fu:' . $today;
        $s = mysqli_prepare($conn, 'INSERT IGNORE INTO mkt_alerts (ref, kind, sent_at) VALUES (?, ?, NOW())');
        mysqli_stmt_bind_param($s, 'ss', $ref, $kind); mysqli_stmt_execute($s);
        if (mysqli_stmt_affected_rows($s) !== 1) { continue; }
        $overdue = $l['next_followup'] < $today;
        mkt_notify($conn, [(int) $l['owner_id']], ($overdue ? 'Overdue follow-up: ' : 'Follow-up due today: ') . $l['name'], $ref . ($overdue ? ' was due on ' . date('d M Y', strtotime($l['next_followup'])) . '.' : ''));
        $n++;
    }
    mysqli_query($conn, "DELETE FROM mkt_alerts WHERE kind LIKE 'fu:%' AND kind < 'fu:" . date('Y-m-d', strtotime('-14 days')) . "'");
    return $n;
}

/** Leads a user may see: managers / approvers all; others their own, those they support, or in their campaigns. */
function mkt_lead_scope_sql(mysqli $conn, int $uid, string $a = 'l'): string
{
    if (mkt_sees_all($conn) || (function_exists('access_can') && access_can('marketing.view', $conn))) { return '1 = 1'; }
    return "($a.owner_id = $uid OR $a.created_by = $uid OR EXISTS (SELECT 1 FROM mkt_lead_contributors k WHERE k.lead_id = $a.id AND k.user_id = $uid)
            OR EXISTS (SELECT 1 FROM mkt_campaign_members m WHERE m.campaign_id = $a.campaign_id AND m.user_id = $uid))";
}

/** Approved contribution total for a lead (percent), excluding one row if given. */
function mkt_contrib_total(mysqli $conn, int $leadId, int $exceptId = 0): float
{
    return (float) mysqli_fetch_row(mysqli_query($conn, "SELECT COALESCE(SUM(share_pct), 0) FROM mkt_lead_contributors WHERE lead_id = $leadId AND status <> 'Rejected' AND id <> $exceptId"))[0];
}