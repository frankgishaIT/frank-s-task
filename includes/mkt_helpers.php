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
    'ai_enabled'            => '1', // MY MOTIVE AI on / off (Administrators)
    'score_weights'         => "lead=1\nfollowup=0.5\nqualified=2\nconversion=5\npaying=10\nactivity_on_time=2\nactivity_late=1", // performance points
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

/* ================================================================== STAGE 3: TEAM, ACTIVITIES & COMMISSIONS */

const MKT_ACTIVITY_TYPES = ['Field visit', 'Phone calls', 'Product demonstration', 'Social media content', 'Event / exhibition', 'Proposal preparation', 'Partnership meeting', 'Market research', 'Design of materials', 'Other'];
const MKT_COMM_FLOW = ['Calculated', 'Verified', 'Approved', 'In payroll', 'Paid'];
/** Performance points (weights are a setting): "key=points" per line. */
const MKT_SCORE_DEFAULT = "lead=1\nfollowup=0.5\nqualified=2\nconversion=5\npaying=10\nactivity_on_time=2\nactivity_late=1";

function mkt_ensure_team(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    mkt_ensure_leads($conn);
    $q = function ($s) use ($conn) { mysqli_query($conn, $s); };
    $q("CREATE TABLE IF NOT EXISTS mkt_activities (id INT(11) NOT NULL AUTO_INCREMENT, task_id INT(11) NOT NULL, campaign_id INT(11) NULL, lead_id INT(11) NULL,
        activity_type VARCHAR(60) NOT NULL, expected_output VARCHAR(500) NULL, actual_output TEXT NULL, evidence VARCHAR(500) NULL,
        review_status VARCHAR(20) NOT NULL DEFAULT 'Not reviewed', quality_score TINYINT NULL, review_note VARCHAR(500) NULL, reviewed_by INT(11) NULL, reviewed_at DATETIME NULL,
        created_by INT(11) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), UNIQUE KEY task_id (task_id), KEY campaign_id (campaign_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS mkt_commission_rules (id INT(11) NOT NULL AUTO_INCREMENT, scope VARCHAR(12) NOT NULL, scope_id INT(11) NULL, scope_value VARCHAR(40) NULL,
        rate DECIMAL(5,2) NOT NULL, effective_from DATE NOT NULL, effective_to DATE NULL, note VARCHAR(255) NULL, created_by INT(11) NULL, active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY scope (scope, scope_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS mkt_commissions (id INT(11) NOT NULL AUTO_INCREMENT, kind VARCHAR(12) NOT NULL, acquisition_id INT(11) NULL, sale_id INT(11) NULL,
        customer_id INT(11) NULL, campaign_id INT(11) NULL, pool_id INT(11) NULL, user_id INT(11) NOT NULL, period CHAR(7) NOT NULL,
        eligible_profit DECIMAL(16,2) NULL, profit_adjusted DECIMAL(16,2) NULL, adjust_reason VARCHAR(500) NULL, rate DECIMAL(5,2) NULL, rule_label VARCHAR(80) NULL,
        share_pct DECIMAL(5,2) NULL, amount DECIMAL(16,2) NOT NULL, status VARCHAR(12) NOT NULL DEFAULT 'Calculated', note VARCHAR(500) NULL, reverses_id INT(11) NULL,
        verified_by INT(11) NULL, verified_at DATETIME NULL, approved_by INT(11) NULL, approved_at DATETIME NULL, payroll_id INT(11) NULL, paid_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), UNIQUE KEY once (sale_id, user_id, kind), KEY user_status (user_id, status), KEY payroll_id (payroll_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS mkt_pools (id INT(11) NOT NULL AUTO_INCREMENT, name VARCHAR(160) NOT NULL, campaign_id INT(11) NULL, amount DECIMAL(16,2) NOT NULL,
        method VARCHAR(12) NOT NULL, period CHAR(7) NOT NULL, reason TEXT NOT NULL, status VARCHAR(12) NOT NULL DEFAULT 'Proposed', created_by INT(11) NULL,
        decided_by INT(11) NULL, decided_at DATETIME NULL, decision_note VARCHAR(500) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q('CREATE TABLE IF NOT EXISTS mkt_pool_shares (pool_id INT(11) NOT NULL, user_id INT(11) NOT NULL, share_pct DECIMAL(6,3) NULL, points INT(11) NULL, amount DECIMAL(16,2) NULL, PRIMARY KEY (pool_id, user_id)) ENGINE=InnoDB');
}

/* ------------------------------------------------------------------ activities (real tasks) */

/** Creates the task in Tasks and its marketing link. Returns the activity id. */
function mkt_activity_create(mysqli $conn, array $d, int $uid): int
{
    mysqli_begin_transaction($conn);
    try {
        $title = mb_substr($d['activity_type'] . ': ' . $d['title'], 0, 200); $desc = trim($d['description'] . ($d['expected_output'] ? "\nExpected output: " . $d['expected_output'] : '') . "\n[Marketing activity]");
        $project = null; $status = 'Pending'; $assigned = (int) $d['assigned_to']; $priority = $d['priority']; $due = $d['due_date'];
        $s = mysqli_prepare($conn, "INSERT INTO tasks (project_id, title, description, assigned_to, priority, status, due_date) VALUES (?, ?, ?, ?, ?, ?, NULLIF(?, ''))");
        mysqli_stmt_bind_param($s, 'ississs', $project, $title, $desc, $assigned, $priority, $status, $due); mysqli_stmt_execute($s);
        $taskId = (int) mysqli_insert_id($conn);
        $s = mysqli_prepare($conn, 'INSERT INTO mkt_activities (task_id, campaign_id, lead_id, activity_type, expected_output, created_by) VALUES (?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($s, 'iiissi', $taskId, $d['campaign_id'], $d['lead_id'], $d['activity_type'], $d['expected_output'], $uid); mysqli_stmt_execute($s);
        $id = (int) mysqli_insert_id($conn);
        mkt_audit($conn, 'Activity assigned (task #' . $taskId . ')', 'ACT-' . $id, null, ['assigned_to' => $assigned, 'type' => $d['activity_type'], 'due' => $due], null, $uid);
        mysqli_commit($conn);
        mkt_notify($conn, [$assigned], 'New marketing activity: ' . $d['title'], 'Due ' . ($due ? date('d M Y', strtotime($due)) : 'without date') . '. See My Tasks.');
        return $id;
    } catch (Throwable $e) { mysqli_rollback($conn); throw $e; }
}

/* ------------------------------------------------------------------ performance points */

function mkt_score_weights(mysqli $conn): array
{
    $w = [];
    foreach (preg_split('/\r?\n/', mkt_setting($conn, 'score_weights') ?: MKT_SCORE_DEFAULT) as $line) {
        if (preg_match('/^\s*([a-z_]+)\s*=\s*([\d.]+)\s*$/', $line, $m)) { $w[$m[1]] = (float) $m[2]; }
    }
    return $w + ['lead' => 0, 'followup' => 0, 'qualified' => 0, 'conversion' => 0, 'paying' => 0, 'activity_on_time' => 0, 'activity_late' => 0];
}

/** Each person's counts and performance points for a date range (verified records only). */
function mkt_team_performance(mysqli $conn, string $from, string $to): array
{
    $w = mkt_score_weights($conn); $rows = [];
    $add = function (string $sql, string $key) use ($conn, $from, $to, &$rows) {
        $s = mysqli_prepare($conn, $sql); mysqli_stmt_bind_param($s, 'ss', $from, $to); mysqli_stmt_execute($s);
        foreach (mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC) as $r) { $rows[(int) $r['u']][$key] = (float) $r['n']; }
    };
    $add('SELECT owner_id u, COUNT(*) n FROM mkt_leads WHERE DATE(created_at) BETWEEN ? AND ? AND owner_id IS NOT NULL GROUP BY owner_id', 'lead');
    $add('SELECT user_id u, COUNT(*) n FROM mkt_followups WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY user_id', 'followup');
    $add("SELECT owner_id u, COUNT(*) n FROM mkt_leads WHERE status IN ('" . implode("','", MKT_QUALIFIED) . "') AND DATE(COALESCE(updated_at, created_at)) BETWEEN ? AND ? GROUP BY owner_id", 'qualified');
    $add("SELECT owner_id u, COUNT(*) n FROM mkt_acquisitions WHERE status <> 'Existing customer' AND DATE(acquired_at) BETWEEN ? AND ? GROUP BY owner_id", 'conversion');
    $add("SELECT owner_id u, COUNT(*) n FROM mkt_acquisitions WHERE status = 'Paying' AND DATE(first_paid_at) BETWEEN ? AND ? GROUP BY owner_id", 'paying');
    // On time = submitted (tasks.submitted_at) on or before the due date: the same rule as the Task reports.
    $add("SELECT t.assigned_to u, COUNT(*) n FROM mkt_activities a JOIN tasks t ON t.id = a.task_id WHERE t.status IN ('Completed', 'Approved') AND t.submitted_at IS NOT NULL AND (t.due_date IS NULL OR DATE(t.submitted_at) <= t.due_date) AND t.due_date BETWEEN ? AND ? GROUP BY t.assigned_to", 'activity_on_time');
    $add("SELECT t.assigned_to u, COUNT(*) n FROM mkt_activities a JOIN tasks t ON t.id = a.task_id WHERE t.status IN ('Completed', 'Approved') AND t.due_date IS NOT NULL AND t.submitted_at IS NOT NULL AND DATE(t.submitted_at) > t.due_date AND t.due_date BETWEEN ? AND ? GROUP BY t.assigned_to", 'activity_late');
    $s = mysqli_prepare($conn, 'SELECT t.assigned_to u, AVG(a.quality_score) q, COUNT(a.quality_score) nq FROM mkt_activities a JOIN tasks t ON t.id = a.task_id WHERE a.quality_score IS NOT NULL AND DATE(a.reviewed_at) BETWEEN ? AND ? GROUP BY t.assigned_to');
    mysqli_stmt_bind_param($s, 'ss', $from, $to); mysqli_stmt_execute($s);
    foreach (mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC) as $r) { $rows[(int) $r['u']]['quality'] = round((float) $r['q'], 2); }
    foreach ($rows as $u => &$r) {
        $pts = 0.0; foreach ($w as $k => $wt) { $pts += ($r[$k] ?? 0) * $wt; }
        // Quality multiplies the points: 3/5 = neutral, 5/5 = +20%, 1/5 = -20%.
        $r['points'] = round($pts * (isset($r['quality']) ? 1 + 0.1 * ($r['quality'] - 3) : 1), 1);
    } unset($r);
    return $rows;
}

/* ------------------------------------------------------------------ commission rules and calculation */

/** The rate for a commission: employee > campaign > product / service > role > default (on the payment date). */
function mkt_commission_rate(mysqli $conn, int $userId, ?int $campaignId, ?int $productId, string $date): array
{
    $role = strtolower((string) (mysqli_fetch_row(mysqli_query($conn, "SELECT role FROM users WHERE id = $userId"))[0] ?? ''));
    $checks = [['employee', $userId, null, 'Employee rule'], ['campaign', $campaignId, null, 'Campaign rule'], ['product', $productId, null, 'Product / service rule'], ['role', null, $role, 'Role rule']];
    foreach ($checks as [$scope, $sid, $sval, $label]) {
        if ($sid === null && $sval === null) { continue; }
        $s = mysqli_prepare($conn, 'SELECT id, rate FROM mkt_commission_rules WHERE active = 1 AND scope = ? AND ' . ($sid !== null ? 'scope_id = ?' : 'scope_value = ?')
            . ' AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?) ORDER BY effective_from DESC, id DESC LIMIT 1');
        if ($sid !== null) { mysqli_stmt_bind_param($s, 'siss', $scope, $sid, $date, $date); } else { mysqli_stmt_bind_param($s, 'ssss', $scope, $sval, $date, $date); }
        mysqli_stmt_execute($s);
        if ($r = mysqli_fetch_assoc(mysqli_stmt_get_result($s))) { return ['rate' => (float) $r['rate'], 'label' => $label . ' #' . $r['id']]; }
    }
    return ['rate' => (float) mkt_setting($conn, 'commission_rate'), 'label' => 'Default rate'];
}

/**
 * Calculates (and reverses) acquisition commissions from verified records. Safe to run any number of times:
 * each sale gives each person ONE commission line (UNIQUE sale + person + kind).
 */
function mkt_calculate_commissions(mysqli $conn): array
{
    $made = 0; $updated = 0; $reversed = 0; $clawbacks = 0;
    $months = max(0, (int) mkt_setting($conn, 'commission_months')); $proportional = mkt_setting($conn, 'partial_policy') === 'proportional';
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT a.*, l.product_id FROM mkt_acquisitions a LEFT JOIN mkt_leads l ON l.id = a.lead_id WHERE a.status = 'Paying' AND a.owner_id IS NOT NULL"), MYSQLI_ASSOC) as $a) {
        $cid = (int) $a['customer_id']; $from = substr($a['first_paid_at'], 0, 10);
        $to = $months > 0 ? date('Y-m-d', strtotime($a['first_paid_at'] . " +$months months")) : $from;
        $statusSql = $proportional ? "s.status IN ('Paid', 'Partially Paid')" : "s.status = 'Paid'";
        $s = mysqli_prepare($conn, "SELECT s.id, s.total_amount, s.amount_paid, s.status, COALESCE(s.fully_paid_at, s.sale_date) paid_at FROM sales s JOIN transactions t ON t.id = s.transaction_id AND t.status = 'approved'
            WHERE s.customer_id = ? AND $statusSql AND DATE(COALESCE(s.fully_paid_at, s.sale_date)) BETWEEN ? AND ?" . ($months === 0 ? ' AND s.id = ' . (int) $a['first_paid_sale_id'] : '') . ' ORDER BY s.id');
        mysqli_stmt_bind_param($s, 'iss', $cid, $from, $to); mysqli_stmt_execute($s);
        $sales = mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC); // read before the next query
        $contribs = mysqli_fetch_all(mysqli_query($conn, 'SELECT user_id, share_pct FROM mkt_lead_contributors WHERE lead_id = ' . (int) $a['lead_id'] . " AND status = 'Approved' AND share_pct > 0"), MYSQLI_ASSOC);
        $shares = []; $others = 0.0;
        foreach ($contribs as $k) { if ((int) $k['user_id'] === (int) $a['owner_id']) { continue; } $shares[(int) $k['user_id']] = ($shares[(int) $k['user_id']] ?? 0) + (float) $k['share_pct']; $others += (float) $k['share_pct']; }
        $shares = [(int) $a['owner_id'] => max(0, 100 - min(100, $others))] + $shares;
        foreach ($sales as $sale) {
            $profit = mkt_sale_profit($conn, (int) $sale['id']);
            if ($proportional && (float) $sale['total_amount'] > 0) { $profit = round($profit * min(1, (float) $sale['amount_paid'] / (float) $sale['total_amount']), 2); }
            if ($profit <= 0) { continue; } // never a commission on zero or negative profit
            $period = substr($sale['paid_at'], 0, 7);
            foreach ($shares as $uid => $pct) {
                if ($pct <= 0) { continue; }
                $r = mkt_commission_rate($conn, $uid, $a['campaign_id'] ? (int) $a['campaign_id'] : null, $a['product_id'] ? (int) $a['product_id'] : null, substr($sale['paid_at'], 0, 10));
                $kind = $uid === (int) $a['owner_id'] ? 'Direct' : 'Contribution';
                $ex = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM mkt_commissions WHERE sale_id = ' . (int) $sale['id'] . " AND user_id = $uid AND kind = '$kind'"));
                $base = $ex && $ex['profit_adjusted'] !== null ? (float) $ex['profit_adjusted'] : $profit;
                $amount = round($base * $r['rate'] / 100 * $pct / 100, 2);
                if (!$ex) {
                    $st = mysqli_prepare($conn, 'INSERT INTO mkt_commissions (kind, acquisition_id, sale_id, customer_id, campaign_id, user_id, period, eligible_profit, rate, rule_label, share_pct, amount) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                    $aid = (int) $a['id']; $sid = (int) $sale['id']; $camp = $a['campaign_id'] ? (int) $a['campaign_id'] : null;
                    mysqli_stmt_bind_param($st, 'siiiiisddsdd', $kind, $aid, $sid, $cid, $camp, $uid, $period, $profit, $r['rate'], $r['label'], $pct, $amount); mysqli_stmt_execute($st); $made++;
                } elseif ($ex['status'] === 'Calculated' && (abs((float) $ex['amount'] - $amount) > 0.004 || abs((float) $ex['eligible_profit'] - $profit) > 0.004)) {
                    // Only a line nobody has checked yet follows the latest figures (e.g. a proportional payment came in).
                    $st = mysqli_prepare($conn, 'UPDATE mkt_commissions SET eligible_profit = ?, rate = ?, rule_label = ?, share_pct = ?, amount = ? WHERE id = ?');
                    $eid = (int) $ex['id']; mysqli_stmt_bind_param($st, 'ddsddi', $profit, $r['rate'], $r['label'], $pct, $amount, $eid); mysqli_stmt_execute($st); $updated++;
                }
            }
        }
    }
    // Reversals: the sale is no longer a verified payment (cancelled, refunded, transaction deleted or not approved).
    $bad = mysqli_fetch_all(mysqli_query($conn, "SELECT c.* FROM mkt_commissions c LEFT JOIN sales s ON s.id = c.sale_id LEFT JOIN transactions t ON t.id = s.transaction_id
        WHERE c.sale_id IS NOT NULL AND c.kind IN ('Direct', 'Contribution') AND c.status NOT IN ('Reversed', 'Rejected')
        AND (s.id IS NULL OR s.status NOT IN ('Paid', 'Partially Paid') OR t.id IS NULL OR t.status <> 'approved')"), MYSQLI_ASSOC);
    foreach ($bad as $c) {
        $cid = (int) $c['id'];
        if ($c['status'] === 'Paid') {
            if ((int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM mkt_commissions WHERE reverses_id = $cid"))[0]) { continue; }
            $st = mysqli_prepare($conn, "INSERT INTO mkt_commissions (kind, customer_id, campaign_id, user_id, period, amount, note, reverses_id) VALUES ('Clawback', ?, ?, ?, ?, ?, ?, ?)");
            $amt = -1 * (float) $c['amount']; $per = date('Y-m'); $note = 'Recovery of paid commission #' . $cid . ': sale #' . (int) $c['sale_id'] . ' is no longer a verified payment.';
            mysqli_stmt_bind_param($st, 'iiisdsi', $c['customer_id'], $c['campaign_id'], $c['user_id'], $per, $amt, $note, $cid); mysqli_stmt_execute($st);
            mkt_audit($conn, 'Clawback created for paid commission', 'COM-' . $cid, $c['amount'], $amt, $note, 0); $clawbacks++;
        } else {
            $st = mysqli_prepare($conn, "UPDATE mkt_commissions SET status = 'Reversed', payroll_id = NULL, note = ? WHERE id = ? AND status NOT IN ('Paid', 'Reversed', 'Rejected')");
            $note = 'Reversed: sale #' . (int) $c['sale_id'] . ' is no longer a verified payment (cancelled, refunded or transaction not approved).';
            mysqli_stmt_bind_param($st, 'si', $note, $cid); mysqli_stmt_execute($st);
            if (mysqli_stmt_affected_rows($st) === 1) { mkt_audit($conn, 'Commission reversed', 'COM-' . $cid, $c['status'], 'Reversed', $note, 0); $reversed++; }
        }
    }
    return ['made' => $made, 'updated' => $updated, 'reversed' => $reversed, 'clawbacks' => $clawbacks];
}

/** Moves commissions along the chain. Returns how many changed. Finance verifies; an Administrator approves. */
function mkt_commission_decide(mysqli $conn, array $ids, string $action, int $uid, bool $isAdmin, string $note = ''): int
{
    $map = ['verify' => ['Calculated', 'Verified', 'verified_by', 'verified_at'], 'approve' => ['Verified', 'Approved', 'approved_by', 'approved_at'],
            'reject' => [null, 'Rejected', null, null]];
    if (!isset($map[$action])) { throw new RuntimeException('Unknown action.'); }
    if ($action === 'reject' && mb_strlen(trim($note)) < 5) { throw new RuntimeException('Write the reason for rejecting.'); }
    // Money decisions are enforced HERE too (not only by the screens): Finance verifies, an approver approves.
    $perm = $action === 'verify' ? 'marketing.finance' : ($action === 'approve' ? 'marketing.approve' : null);
    if (function_exists('access_can') && ($perm ? !access_can($perm, $conn) : !(access_can('marketing.finance', $conn) || access_can('marketing.approve', $conn)))) {
        throw new RuntimeException('Only authorised Finance / Administrators can ' . $action . ' commissions.');
    }
    [$from, $to, $byCol, $atCol] = $map[$action]; $n = 0;
    foreach (array_unique(array_map('intval', $ids)) as $id) {
        $c = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM mkt_commissions WHERE id = $id"));
        if (!$c) { continue; }
        if ($action === 'reject' ? !in_array($c['status'], ['Calculated', 'Verified'], true) : $c['status'] !== $from) { continue; }
        if ((int) $c['user_id'] === $uid) {
            if (!$isAdmin) { continue; } // nobody approves their own pay (Administrators: only with a note)
            if (mb_strlen(trim($note)) < 5) { throw new RuntimeException('One of the selected lines is your own commission: write a note (kept in the audit trail), or let another Administrator decide.'); }
        }
        $sql = "UPDATE mkt_commissions SET status = ?" . ($byCol ? ", $byCol = ?, $atCol = NOW()" : '') . ($action === 'reject' ? ', note = ?' : '') . ' WHERE id = ? AND status = ?';
        $s = mysqli_prepare($conn, $sql);
        if ($action === 'reject') { mysqli_stmt_bind_param($s, 'ssis', $to, $note, $id, $c['status']); } else { mysqli_stmt_bind_param($s, 'siis', $to, $uid, $id, $c['status']); }
        mysqli_stmt_execute($s);
        if (mysqli_stmt_affected_rows($s) === 1) {
            $n++;
            mkt_audit($conn, 'Commission ' . strtolower($to) . ((int) $c['user_id'] === $uid ? ' (own commission, Administrator)' : '') . ': RWF ' . number_format((float) $c['amount'], 2), 'COM-' . $id, $c['status'], $to, $note ?: null, $uid);
            if ($to === 'Approved' || $to === 'Rejected') { mkt_notify($conn, [(int) $c['user_id']], 'Commission ' . strtolower($to) . ': RWF ' . number_format((float) $c['amount']), $to === 'Approved' ? 'It will be paid with your payroll.' : $note); }
        }
    }
    return $n;
}

/* ------------------------------------------------------------------ Payroll link */

/** Approved marketing pay not yet in any payroll, for a person, up to a pay period (YYYY-MM). */
function mkt_payroll_due(mysqli $conn, int $userId, string $period): array
{
    $rows = mysqli_fetch_all(mysqli_query($conn, "SELECT id, kind, amount FROM mkt_commissions WHERE user_id = $userId AND status = 'Approved' AND payroll_id IS NULL AND period <= '"
        . mysqli_real_escape_string($conn, $period) . "'"), MYSQLI_ASSOC);
    $plus = 0.0; $minus = 0.0;
    foreach ($rows as $r) { if ((float) $r['amount'] >= 0) { $plus += (float) $r['amount']; } else { $minus += -1 * (float) $r['amount']; } }
    return ['ids' => array_map('intval', array_column($rows, 'id')), 'plus' => round($plus, 2), 'minus' => round($minus, 2), 'count' => count($rows)];
}

/** Links due commissions to a payroll line (In payroll, or Paid if the payroll is paid). */
function mkt_payroll_link(mysqli $conn, int $payrollId, array $ids, bool $paid): void
{
    if (!$ids) { return; }
    $in = implode(',', array_map('intval', $ids));
    mysqli_query($conn, "UPDATE mkt_commissions SET payroll_id = $payrollId, status = '" . ($paid ? 'Paid' : 'In payroll') . "'" . ($paid ? ', paid_at = NOW()' : '') . " WHERE id IN ($in) AND status = 'Approved' AND payroll_id IS NULL");
    mkt_audit($conn, 'Marketing pay added to payroll #' . $payrollId . ($paid ? ' (paid)' : ' (draft)'), 'PAYROLL-' . $payrollId, null, $ids, null);
}
/** The payroll line was paid: its marketing pay is Paid. */
function mkt_payroll_paid(mysqli $conn, int $payrollId): void
{
    mysqli_query($conn, "UPDATE mkt_commissions SET status = 'Paid', paid_at = NOW() WHERE payroll_id = $payrollId AND status = 'In payroll'");
}
/** The payroll line was deleted: its unpaid marketing pay becomes Approved again (for the next payroll). */
function mkt_payroll_unlink(mysqli $conn, int $payrollId): void
{
    mysqli_query($conn, "UPDATE mkt_commissions SET payroll_id = NULL, status = 'Approved' WHERE payroll_id = $payrollId AND status = 'In payroll'");
    mysqli_query($conn, "UPDATE mkt_commissions SET payroll_id = NULL, status = 'Approved', paid_at = NULL, note = CONCAT(COALESCE(note, ''), ' [its payroll line was deleted]') WHERE payroll_id = $payrollId AND status = 'Paid'");
}

/* ------------------------------------------------------------------ shared reward pools */

/** Splits a pool: equal, by approved contribution points in the campaign, or by set percentages. Returns [user => amount]. */
function mkt_pool_split(mysqli $conn, array $pool, array $members, array $pcts = []): array
{
    $amount = (float) $pool['amount']; $members = array_values(array_unique(array_map('intval', $members)));
    if (!$members) { throw new RuntimeException('Choose the team members who share the pool.'); }
    if ($pool['method'] === 'equal') { $w = array_fill_keys($members, 1.0); }
    elseif ($pool['method'] === 'points') {
        $w = array_fill_keys($members, 0.0);
        if ($pool['campaign_id']) {
            foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT k.user_id, SUM(k.points) p FROM mkt_lead_contributors k JOIN mkt_leads l ON l.id = k.lead_id WHERE l.campaign_id = ' . (int) $pool['campaign_id'] . " AND k.status = 'Approved' AND k.points > 0 GROUP BY k.user_id"), MYSQLI_ASSOC) as $r) {
                if (isset($w[(int) $r['user_id']])) { $w[(int) $r['user_id']] = (float) $r['p']; }
            }
        }
        if (array_sum($w) <= 0) { throw new RuntimeException('No approved contribution points for these members in this campaign: choose Equal or Set percentages.'); }
    } else {
        $w = []; foreach ($members as $m) { $w[$m] = max(0, (float) ($pcts[$m] ?? 0)); }
        if (abs(array_sum($w) - 100) > 0.01) { throw new RuntimeException('The percentages must add up to 100% (now ' . round(array_sum($w), 2) . '%).'); }
    }
    $total = array_sum($w); $out = []; $given = 0.0;
    foreach ($w as $u => $x) { $out[$u] = floor(100 * $amount * $x / $total) / 100; $given += $out[$u]; }
    $first = array_key_first(array_filter($out, function ($v) { return $v > 0; })) ?? array_key_first($out);
    $out[$first] = round($out[$first] + round($amount - $given, 2), 2); // rounding cents: the totals match exactly
    return $out;
}

/* ================================================================== STAGE 4: MATERIALS, SPENDING, DASHBOARD, REPORTS */

const MKT_MATERIAL_TYPES = ['Brochure', 'Flyer', 'Poster / banner', 'Social media post', 'Video', 'Presentation', 'Proposal template', 'Email / SMS text', 'Photo', 'Other'];

function mkt_ensure_studio(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    mkt_ensure_team($conn);
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS mkt_materials (id INT(11) NOT NULL AUTO_INCREMENT, campaign_id INT(11) NULL, title VARCHAR(160) NOT NULL, type VARCHAR(40) NOT NULL,
        description TEXT NULL, lib_document_id INT(11) NULL, link VARCHAR(500) NULL, status VARCHAR(16) NOT NULL DEFAULT 'Draft', ai_generated TINYINT(1) NOT NULL DEFAULT 0,
        created_by INT(11) NULL, decided_by INT(11) NULL, decided_at DATETIME NULL, decision_note VARCHAR(500) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL, PRIMARY KEY (id), KEY campaign_id (campaign_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS mkt_expense_links (transaction_id INT(11) NOT NULL, campaign_id INT(11) NOT NULL, linked_by INT(11) NULL, note VARCHAR(255) NULL,
        linked_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (transaction_id), KEY campaign_id (campaign_id)) ENGINE=InnoDB");
}

/** Approved expense transactions that may be linked to a campaign (not linked to any campaign yet). */
function mkt_linkable_expenses(mysqli $conn, string $q = '', int $limit = 50): array
{
    $w = "t.transaction_type = 'Expense' AND t.status = 'approved' AND NOT EXISTS (SELECT 1 FROM mkt_expense_links l WHERE l.transaction_id = t.id)";
    if ($q !== '') { $qq = mysqli_real_escape_string($conn, $q); $w .= " AND (t.description LIKE '%$qq%' OR t.expense_category LIKE '%$qq%' OR t.id = " . (int) preg_replace('/\D/', '', $q) . ')'; }
    return mysqli_fetch_all(mysqli_query($conn, "SELECT t.id, t.amount, t.transaction_date, t.description, t.expense_category FROM transactions t WHERE $w ORDER BY t.transaction_date DESC, t.id DESC LIMIT $limit"), MYSQLI_ASSOC);
}

/** Links an approved expense to a campaign (one campaign per expense) and alerts at 100% of the budget. */
function mkt_link_expense(mysqli $conn, int $campaignId, int $txId, int $uid, string $note = ''): void
{
    $t = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM transactions WHERE id = $txId"));
    if (!$t || $t['transaction_type'] !== 'Expense' || $t['status'] !== 'approved') { throw new RuntimeException('Only an approved expense transaction can be linked.'); }
    $s = mysqli_prepare($conn, 'INSERT IGNORE INTO mkt_expense_links (transaction_id, campaign_id, linked_by, note) VALUES (?, ?, ?, ?)');
    mysqli_stmt_bind_param($s, 'iiis', $txId, $campaignId, $uid, $note); mysqli_stmt_execute($s);
    if (mysqli_stmt_affected_rows($s) !== 1) { throw new RuntimeException('This expense is already linked to a campaign (an expense counts for one campaign only).'); }
    $c = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM mkt_campaigns WHERE id = $campaignId"));
    mkt_audit($conn, 'Expense TX-' . str_pad((string) $txId, 6, '0', STR_PAD_LEFT) . ' linked: RWF ' . number_format((float) $t['amount'], 2), mkt_code('campaign', $c), null, $txId, $note ?: null, $uid);
    $spent = mkt_actuals($conn, [$campaignId])['expenses'];
    if ((float) $c['budget'] > 0 && $spent > (float) $c['budget']) {
        $s = mysqli_prepare($conn, "INSERT IGNORE INTO mkt_alerts (ref, kind, sent_at) VALUES (?, 'over_budget', NOW())"); $ref = mkt_code('campaign', $c);
        mysqli_stmt_bind_param($s, 's', $ref); mysqli_stmt_execute($s);
        if (mysqli_stmt_affected_rows($s) === 1) {
            mkt_notify($conn, array_merge([(int) $c['manager_id']], mkt_admin_ids($conn)), 'Campaign over budget: ' . $c['name'], 'Spent RWF ' . number_format($spent) . ' of RWF ' . number_format((float) $c['budget']) . '.');
        }
    }
}

/** Monthly series for a date range: leads, new customers, paying customers. */
function mkt_monthly(mysqli $conn, string $from, string $to, ?int $campaignId = null): array
{
    $out = []; $m = strtotime(date('Y-m-01', strtotime($from)));
    while ($m <= strtotime($to)) { $out[date('Y-m', $m)] = ['leads' => 0, 'converted' => 0, 'paying' => 0]; $m = strtotime('+1 month', $m); }
    $cw = $campaignId ? " AND campaign_id = $campaignId" : '';
    foreach ([['leads', "SELECT DATE_FORMAT(created_at, '%Y-%m') m, COUNT(*) n FROM mkt_leads WHERE DATE(created_at) BETWEEN ? AND ?$cw GROUP BY m"],
              ['converted', "SELECT DATE_FORMAT(acquired_at, '%Y-%m') m, COUNT(*) n FROM mkt_acquisitions WHERE status <> 'Existing customer' AND DATE(acquired_at) BETWEEN ? AND ?$cw GROUP BY m"],
              ['paying', "SELECT DATE_FORMAT(first_paid_at, '%Y-%m') m, COUNT(*) n FROM mkt_acquisitions WHERE status = 'Paying' AND DATE(first_paid_at) BETWEEN ? AND ?$cw GROUP BY m"]] as [$k, $sql]) {
        $s = mysqli_prepare($conn, $sql); mysqli_stmt_bind_param($s, 'ss', $from, $to); mysqli_stmt_execute($s);
        foreach (mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC) as $r) { if (isset($out[$r['m']])) { $out[$r['m']][$k] = (int) $r['n']; } }
    }
    return $out;
}