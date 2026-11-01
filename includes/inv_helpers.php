<?php
/**
 * NEW FILE: includes/inv_helpers.php
 * RM INVEST (RM Investment Opportunities) — engine shared by the Customer Portal and RM Investment Management.
 *
 *   Capital      only VERIFIED contributions are raised capital. New applications are limited by the remaining
 *                capital, counting what approved applicants have committed but not paid yet (no over-subscription).
 *   Profit       realized profit = actual revenue - actual direct costs (incl. what the capital bought) - other expenses.
 *                Management fee = fee % of a POSITIVE realized profit only (never of the capital).
 *                Investor profit = (verified contribution / total verified capital) x distributable profit.
 *                A loss is shared in proportion: each investor gets back their share of (capital + loss).
 *   Approval     results must be approved by management before payouts. Projections are never treated as results.
 *   Testing mode ON by default: contribution payments and payouts are blocked until legal clearance.
 */
require_once __DIR__ . '/rm_org.php';
require_once __DIR__ . '/cl_helpers.php'; // portal_notify_customer(), portal_notifications

const INV_PROJECT_STATUSES = ['Draft', 'Published', 'Paused', 'Funding Closed', 'Active', 'Completed', 'Cancelled'];
const INV_APP_STATUSES = ['Draft', 'Submitted', 'Under Review', 'Additional Information Required', 'Approved', 'Rejected', 'Awaiting Payment',
    'Payment Pending Verification', 'Confirmed', 'Active Investor', 'Completed', 'Cancelled', 'Refunded'];
/** Applications whose amount is reserved against the project's capital (committed or paid). */
const INV_COMMITTED = ['Approved', 'Awaiting Payment', 'Payment Pending Verification', 'Confirmed', 'Active Investor', 'Completed'];
const INV_CATEGORIES = ['Retail / stock', 'Services', 'Agriculture', 'Manufacturing', 'Technology', 'Events', 'Real estate', 'Other'];
const INV_RISK_LEVELS = ['Low', 'Medium', 'High'];
const INV_SOURCES = ['Salary / savings', 'Business income', 'Sale of an asset', 'Gift / family', 'Other'];
const INV_PAY_METHODS = ['Mobile Money', 'Bank transfer', 'Cash at RISE MOTIVE office'];
const INV_DEFAULTS = ['enabled' => '1', 'testing_mode' => '1', 'mgmt_fee_pct' => '15',
    'payment_instructions' => "Pay to RISE MOTIVE Ltd using your contribution reference as the payment description:\n- Mobile Money: (number to be set by RISE MOTIVE)\n- Bank: (account to be set by RISE MOTIVE)\nThen enter the transaction ID in the portal so we can verify it.",
    'agreement_general' => "Loss sharing: losses are shared in proportion to each investor's verified contribution.\nExit and withdrawal: contributions are committed until the project ends; early withdrawal only with RISE MOTIVE's written agreement.\nDispute resolution: disputes are first settled amicably, then by mediation, then before the competent courts of Rwanda.\nLegal: this is a project-specific participation, not a share in RISE MOTIVE Ltd, and is subject to the laws and regulations of Rwanda."];

function inv_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    cl_ensure($conn);
    $q = function ($s) use ($conn) { mysqli_query($conn, $s); };
    $q('CREATE TABLE IF NOT EXISTS inv_settings (name VARCHAR(40) NOT NULL, value TEXT NULL, PRIMARY KEY (name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q("CREATE TABLE IF NOT EXISTS inv_projects (id INT(11) NOT NULL AUTO_INCREMENT, name VARCHAR(160) NOT NULL, category VARCHAR(40) NULL,
        description TEXT NULL, objectives TEXT NULL, why_capital TEXT NULL, use_of_funds TEXT NULL, location VARCHAR(160) NULL, status VARCHAR(20) NOT NULL DEFAULT 'Draft',
        capital_required DECIMAL(14,2) NOT NULL DEFAULT 0, min_invest DECIMAL(14,2) NOT NULL DEFAULT 0, max_invest DECIMAL(14,2) NOT NULL DEFAULT 0,
        max_investors INT(11) NULL, min_investors INT(11) NULL, funding_deadline DATE NULL, start_date DATE NULL, end_date DATE NULL,
        expected_revenue DECIMAL(14,2) NOT NULL DEFAULT 0, expected_direct DECIMAL(14,2) NOT NULL DEFAULT 0, expected_other DECIMAL(14,2) NOT NULL DEFAULT 0,
        mgmt_fee_pct DECIMAL(6,2) NOT NULL DEFAULT 15, distribution_method VARCHAR(20) NOT NULL DEFAULT 'proportional', risk_level VARCHAR(10) NOT NULL DEFAULT 'Medium',
        risk_business TEXT NULL, risk_market TEXT NULL, risk_delays TEXT NULL, risk_losses TEXT NULL, risk_other TEXT NULL,
        capital_recovery TEXT NULL, cancellation_conditions TEXT NULL, distribution_conditions TEXT NULL, agreement_terms TEXT NULL,
        actual_revenue DECIMAL(14,2) NULL, actual_direct DECIMAL(14,2) NULL, actual_other DECIMAL(14,2) NULL, results_note TEXT NULL,
        results_status VARCHAR(12) NOT NULL DEFAULT 'None', results_by INT(11) NULL, results_approved_by INT(11) NULL, results_approved_at DATETIME NULL,
        created_by INT(11) NULL, published_at DATETIME NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL,
        PRIMARY KEY (id), KEY status (status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q('CREATE TABLE IF NOT EXISTS inv_project_files (id INT(11) NOT NULL AUTO_INCREMENT, project_id INT(11) NOT NULL, kind VARCHAR(20) NOT NULL,
        file_ref VARCHAR(30) NOT NULL, original_name VARCHAR(255) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY project_id (project_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q('CREATE TABLE IF NOT EXISTS inv_updates (id INT(11) NOT NULL AUTO_INCREMENT, project_id INT(11) NOT NULL, title VARCHAR(160) NOT NULL, body TEXT NOT NULL,
        created_by INT(11) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY project_id (project_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q("CREATE TABLE IF NOT EXISTS inv_applications (id INT(11) NOT NULL AUTO_INCREMENT, project_id INT(11) NOT NULL, customer_id INT(11) NOT NULL,
        status VARCHAR(32) NOT NULL DEFAULT 'Draft', amount DECIMAL(14,2) NULL, proposed_date DATE NULL, source_of_funds VARCHAR(40) NULL,
        payment_method VARCHAR(40) NULL, comments TEXT NULL, personal TEXT NULL, risk_ack_at DATETIME NULL, agreement_accepted_at DATETIME NULL,
        accepted_name VARCHAR(120) NULL, submitted_at DATETIME NULL, info_request TEXT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL,
        PRIMARY KEY (id), KEY project_id (project_id), KEY customer_id (customer_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS inv_contributions (id INT(11) NOT NULL AUTO_INCREMENT, application_id INT(11) NOT NULL, project_id INT(11) NOT NULL, customer_id INT(11) NOT NULL,
        contribution_ref VARCHAR(30) NOT NULL, amount DECIMAL(14,2) NOT NULL, method VARCHAR(40) NULL, payer_reference VARCHAR(80) NULL, paid_on DATE NULL,
        status VARCHAR(25) NOT NULL DEFAULT 'Awaiting payment', verified_by INT(11) NULL, verified_at DATETIME NULL, note VARCHAR(255) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), UNIQUE KEY one_per_application (application_id), UNIQUE KEY ref (contribution_ref)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS inv_distributions (id INT(11) NOT NULL AUTO_INCREMENT, project_id INT(11) NOT NULL, application_id INT(11) NOT NULL, customer_id INT(11) NOT NULL,
        contribution DECIMAL(14,2) NOT NULL, share_pct DECIMAL(9,4) NOT NULL, profit_share DECIMAL(14,2) NOT NULL, capital_returned DECIMAL(14,2) NOT NULL, total_payout DECIMAL(14,2) NOT NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'Approved', paid_at DATETIME NULL, paid_ref VARCHAR(80) NULL, paid_by INT(11) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), UNIQUE KEY one (project_id, application_id)) ENGINE=InnoDB");
    $q('CREATE TABLE IF NOT EXISTS inv_audit (id INT(11) NOT NULL AUTO_INCREMENT, project_id INT(11) NULL, application_id INT(11) NULL, user_id INT(11) NULL, customer_id INT(11) NULL,
        action VARCHAR(160) NOT NULL, reason TEXT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY project_id (project_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

function inv_setting(mysqli $conn, string $name): string
{
    $s = mysqli_prepare($conn, 'SELECT value FROM inv_settings WHERE name = ?');
    mysqli_stmt_bind_param($s, 's', $name); mysqli_stmt_execute($s);
    $r = mysqli_fetch_row(mysqli_stmt_get_result($s));
    return $r ? (string) $r[0] : (INV_DEFAULTS[$name] ?? '');
}
function inv_testing(mysqli $conn): bool { return inv_setting($conn, 'testing_mode') === '1'; }
function inv_project_ref(array $p): string { return 'INV-' . date('Y', strtotime($p['created_at'] ?? 'now')) . '-' . str_pad((string) $p['id'], 4, '0', STR_PAD_LEFT); }
function inv_app_ref(array $a): string { return 'IA-' . date('Y', strtotime($a['created_at'] ?? 'now')) . '-' . str_pad((string) $a['id'], 5, '0', STR_PAD_LEFT); }

function inv_audit(mysqli $conn, ?int $projectId, ?int $appId, string $action, ?string $reason, ?int $userId = null, ?int $customerId = null): void
{
    $s = mysqli_prepare($conn, 'INSERT INTO inv_audit (project_id, application_id, user_id, customer_id, action, reason) VALUES (?, ?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($s, 'iiiiss', $projectId, $appId, $userId, $customerId, $action, $reason); mysqli_stmt_execute($s);
}

/* ------------------------------------------------------------------ projections and funding */

/** Projected figures of a project (always ESTIMATES). */
function inv_projection(array $p): array
{
    $gross = (float) $p['expected_revenue'] - (float) $p['expected_direct'];
    $net = $gross - (float) $p['expected_other'];
    $fee = $net > 0 ? round($net * (float) $p['mgmt_fee_pct'] / 100, 2) : 0.0;
    $dist = $net > 0 ? $net - $fee : 0.0;
    $cap = (float) $p['capital_required'];
    return ['gross' => $gross, 'net' => $net, 'fee' => $fee, 'distributable' => $dist, 'return_pct' => $cap > 0 ? round(100 * $dist / $cap, 1) : null];
}

/** Raised (verified), committed (approved / paid, not yet verified), remaining, investors. */
function inv_funding(mysqli $conn, int $projectId): array
{
    $verified = (float) mysqli_fetch_row(mysqli_query($conn, "SELECT COALESCE(SUM(amount), 0) FROM inv_contributions WHERE project_id = $projectId AND status = 'Verified'"))[0];
    $in = "'" . implode("','", INV_COMMITTED) . "'";
    $committed = (float) mysqli_fetch_row(mysqli_query($conn, "SELECT COALESCE(SUM(amount), 0) FROM inv_applications WHERE project_id = $projectId AND status IN ($in)"))[0];
    $investors = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(DISTINCT customer_id) FROM inv_applications WHERE project_id = $projectId AND status IN ('Confirmed', 'Active Investor', 'Completed')"))[0];
    $holders = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(DISTINCT customer_id) FROM inv_applications WHERE project_id = $projectId AND status IN ($in)"))[0];
    $p = mysqli_fetch_assoc(mysqli_query($conn, "SELECT capital_required FROM inv_projects WHERE id = $projectId"));
    $req = (float) ($p['capital_required'] ?? 0);
    return ['required' => $req, 'raised' => round($verified, 2), 'committed' => round($committed, 2),
            'remaining' => max(0, round($req - $verified, 2)), 'available' => max(0, round($req - $committed, 2)),
            'investors' => $investors, 'holders' => $holders, 'pct' => $req > 0 ? min(100, round(100 * $verified / $req, 1)) : 0];
}

/** Why a customer cannot apply for this amount now (null = they can). Server-side; the form shows the same limits. */
function inv_check_application(mysqli $conn, array $p, int $customerId, float $amount, ?int $ignoreAppId = null): ?string
{
    if ($p['status'] !== 'Published') { return 'This opportunity is not open for applications.'; }
    if ($p['funding_deadline'] && $p['funding_deadline'] < date('Y-m-d')) { return 'The funding deadline has passed.'; }
    $f = inv_funding($conn, (int) $p['id']);
    $mine = (float) mysqli_fetch_row(mysqli_query($conn, 'SELECT COALESCE(SUM(amount), 0) FROM inv_applications WHERE project_id = ' . (int) $p['id'] . " AND customer_id = $customerId
        AND status IN ('Submitted', 'Under Review', 'Additional Information Required', '" . implode("','", INV_COMMITTED) . "')" . ($ignoreAppId ? " AND id <> $ignoreAppId" : '')))[0];
    if ($amount < (float) $p['min_invest']) { return 'The minimum investment is ' . cl_money($p['min_invest']) . '.'; }
    if ((float) $p['max_invest'] > 0 && $mine + $amount > (float) $p['max_invest']) { return 'The maximum per investor is ' . cl_money($p['max_invest']) . ($mine > 0 ? ' (you already applied for ' . cl_money($mine) . ')' : '') . '.'; }
    if ($amount > $f['available']) { return $f['available'] > 0 ? 'Only ' . cl_money($f['available']) . ' of capital is still available in this project.' : 'This project is fully committed.'; }
    if ($p['max_investors'] && $mine <= 0 && $f['holders'] >= (int) $p['max_investors']) { return 'This project has reached its maximum number of investors.'; }
    return null;
}

/* ------------------------------------------------------------------ results and distribution */

/**
 * Realized results and each investor's distribution, from the ACTUAL figures and VERIFIED contributions.
 * Returns ['ok', 'reason', 'capital', 'revenue', 'direct', 'other', 'profit', 'fee', 'distributable', 'rows' => [...]].
 */
function inv_calculate(mysqli $conn, array $p): array
{
    if ($p['actual_revenue'] === null) { return ['ok' => false, 'reason' => 'The actual revenue and expenses have not been entered yet.']; }
    $pid = (int) $p['id'];
    $contribs = mysqli_fetch_all(mysqli_query($conn, "SELECT c.application_id, c.customer_id, c.amount, cu.name FROM inv_contributions c JOIN customers cu ON cu.id = c.customer_id
        WHERE c.project_id = $pid AND c.status = 'Verified' ORDER BY c.amount DESC, c.id"), MYSQLI_ASSOC);
    $capital = round(array_sum(array_column($contribs, 'amount')), 2);
    if ($capital <= 0) { return ['ok' => false, 'reason' => 'No verified contributions: nothing to distribute.']; }
    $revenue = (float) $p['actual_revenue']; $direct = (float) $p['actual_direct']; $other = (float) $p['actual_other'];
    $profit = round($revenue - $direct - $other, 2);                              // realized project profit (or loss)
    $fee = $profit > 0 ? round($profit * (float) $p['mgmt_fee_pct'] / 100, 2) : 0.0; // on the PROFIT only, never on capital
    $distributable = $profit > 0 ? round($profit - $fee, 2) : 0.0;
    $toReturn = $profit >= 0 ? $capital : max(0, round($capital + $profit, 2));     // a loss reduces the capital returned, in proportion
    $rows = []; $sumP = 0; $sumC = 0;
    foreach ($contribs as $i => $c) {
        $share = (float) $c['amount'] / $capital;
        $pr = floor(100 * $share * $distributable) / 100; $cr = floor(100 * $share * $toReturn) / 100;
        $rows[] = ['application_id' => (int) $c['application_id'], 'customer_id' => (int) $c['customer_id'], 'name' => $c['name'],
                   'contribution' => (float) $c['amount'], 'share_pct' => round(100 * $share, 4), 'profit' => $pr, 'capital' => $cr];
        $sumP += $pr; $sumC += $cr;
    }
    // Rounding cents go to the largest contributor, so the totals match exactly.
    $rows[0]['profit'] = round($rows[0]['profit'] + round($distributable - $sumP, 2), 2);
    $rows[0]['capital'] = round($rows[0]['capital'] + round($toReturn - $sumC, 2), 2);
    foreach ($rows as &$r) { $r['total'] = round($r['profit'] + $r['capital'], 2); } unset($r);
    return ['ok' => true, 'reason' => '', 'capital' => $capital, 'revenue' => $revenue, 'direct' => $direct, 'other' => $other,
            'profit' => $profit, 'fee' => $fee, 'distributable' => $distributable, 'capital_returned' => $toReturn, 'rows' => $rows];
}

/** Investors of a project (confirmed / active / completed), for notifications. */
function inv_project_investors(mysqli $conn, int $projectId): array
{
    return array_map('intval', array_column(mysqli_fetch_all(mysqli_query($conn, "SELECT DISTINCT customer_id FROM inv_applications WHERE project_id = $projectId
        AND status IN ('Confirmed', 'Active Investor', 'Completed', 'Approved', 'Awaiting Payment', 'Payment Pending Verification')"), MYSQLI_ASSOC), 'customer_id'));
}

/** Changes an application's status, records it, tells the customer. */
function inv_set_app_status(mysqli $conn, int $appId, string $to, ?int $userId, ?string $reason, string $action = 'Status changed'): void
{
    $a = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM inv_applications WHERE id = $appId"));
    if (!$a) { return; }
    $s = mysqli_prepare($conn, 'UPDATE inv_applications SET status = ?, updated_at = NOW() WHERE id = ?');
    mysqli_stmt_bind_param($s, 'si', $to, $appId); mysqli_stmt_execute($s);
    inv_audit($conn, (int) $a['project_id'], $appId, $action . ': ' . $a['status'] . ' → ' . $to, $reason, $userId, $userId ? null : (int) $a['customer_id']);
    $titles = ['Submitted' => 'Investment application submitted', 'Under Review' => 'Investment application under review', 'Additional Information Required' => 'More information needed for your investment application',
        'Rejected' => 'Investment application not approved', 'Awaiting Payment' => 'Investment approved: contribution payment pending', 'Confirmed' => 'Investment confirmed',
        'Active Investor' => 'Your investment project has started', 'Completed' => 'Investment project completed', 'Cancelled' => 'Investment application cancelled', 'Refunded' => 'Investment refunded'];
    if (isset($titles[$to])) {
        portal_notify_customer($conn, (int) $a['customer_id'], $titles[$to] . ' (' . inv_app_ref($a) . ')', in_array($to, ['Rejected', 'Additional Information Required', 'Cancelled'], true) ? (string) $reason : '', 'invest_app.php?id=' . $appId);
    }
}