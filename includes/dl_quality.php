<?php
/**
 * NEW FILE: includes/dl_quality.php — DELEGATIONS batch 4: service quality & issues, Tasker performance and
 * incentives, staff delegation performance and recognition, the management figures behind the reports and the
 * dashboard, and the internalization analysis.
 *
 * Fairness rules kept here:
 *  - An issue counts against a Tasker ONLY once it has been investigated and the finding is "Tasker" (or "Shared")
 *    and the issue is Resolved or Closed. A complaint under investigation never lowers a rating.
 *  - A verified delegation's rating becomes final after the complaint / review period (Settings) without an open issue;
 *    until then it is shown as provisional and not counted in the Tasker's rating.
 *  - No money is ever deducted from a Tasker automatically. A recovery is proposed, then authorized by another
 *    approver; Finance then changes the approved payment amount (with the reason) if it is to be deducted.
 *  - Bonuses are proposals until a second approver approves them; payments are never raised automatically.
 */
require_once __DIR__ . '/dl_payments.php';

const DLQ_TYPES = ['Poor Quality', 'Late Delivery', 'Incorrect Service', 'Customer Complaint', 'Failure to Follow Instructions', 'Other'];
const DLQ_REPORTERS = ['Customer', 'Staff', 'RM Tasker', 'Other'];
const DLQ_STATUSES = ['Open', 'Under Investigation', 'Resolved', 'Closed'];
const DLQ_OPEN = ['Open', 'Under Investigation'];
const DLQ_RESP = ['Not yet investigated', 'RM Tasker', 'RISE MOTIVE', 'Customer', 'Shared', 'No fault found'];
const DLQ_IMPACT = ['None', 'Rework cost', 'Refund to customer', 'Discount given', 'Materials wasted', 'Other cost'];
const DLQ_INCENTIVES = ['Recognition for consistent quality', 'Priority in recommendations', 'More assignment opportunities', 'Performance bonus'];
const DLQ_STAFF_RECOGNITION = ['Customer Retention Recognition', 'Successful Delegation Recognition', 'Revenue Contribution Recognition', 'Monthly Performance Recognition'];

function dlq_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    dlp_ensure($conn);
    $q = function ($sql) use ($conn) { mysqli_query($conn, $sql); };
    $q("CREATE TABLE IF NOT EXISTS dl_issues (
        id INT(11) NOT NULL AUTO_INCREMENT, delegation_id INT(11) NOT NULL, party_id INT(11) NULL, product_id INT(11) NULL, type VARCHAR(40) NOT NULL, description TEXT NOT NULL,
        reported_by_kind VARCHAR(12) NOT NULL DEFAULT 'Staff', reporter_name VARCHAR(150) NULL, reported_at DATETIME NOT NULL,
        impact_kind VARCHAR(30) NOT NULL DEFAULT 'None', impact_amount DECIMAL(15,2) NOT NULL DEFAULT 0, impact_note VARCHAR(500) NULL,
        responsibility VARCHAR(24) NOT NULL DEFAULT 'Not yet investigated', findings TEXT NULL, corrective_action TEXT NULL, resolution TEXT NULL, status VARCHAR(24) NOT NULL DEFAULT 'Open',
        assigned_to INT(11) NULL, held_payment TINYINT(1) NOT NULL DEFAULT 0, prev_status VARCHAR(40) NULL, revised_quality DECIMAL(3,2) NULL, old_perf DECIMAL(5,2) NULL,
        recovery_amount DECIMAL(15,2) NULL, recovery_status VARCHAR(12) NOT NULL DEFAULT 'None', recovery_note VARCHAR(500) NULL, recovery_by INT(11) NULL, recovery_approved_by INT(11) NULL, recovery_at DATETIME NULL,
        created_by INT(11) NULL, resolved_by INT(11) NULL, resolved_at DATETIME NULL, updated_at DATETIME NULL,
        PRIMARY KEY (id), KEY d (delegation_id), KEY party (party_id, status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS dl_issue_notes (
        id INT(11) NOT NULL AUTO_INCREMENT, issue_id INT(11) NOT NULL, kind VARCHAR(20) NOT NULL DEFAULT 'note', note TEXT NOT NULL, actor VARCHAR(10) NOT NULL DEFAULT 'staff',
        user_id INT(11) NULL, party_id INT(11) NULL, visible_to_tasker TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, PRIMARY KEY (id), KEY i (issue_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS dl_incentives (
        id INT(11) NOT NULL AUTO_INCREMENT, party_id INT(11) NOT NULL, kind VARCHAR(40) NOT NULL, amount DECIMAL(15,2) NULL, note VARCHAR(500) NOT NULL, period VARCHAR(7) NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'Proposed', proposed_by INT(11) NULL, proposed_at DATETIME NOT NULL, decided_by INT(11) NULL, decided_at DATETIME NULL, decision_note VARCHAR(500) NULL,
        transaction_id INT(11) NULL, paid_at DATETIME NULL, PRIMARY KEY (id), KEY party (party_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS dl_staff_recognition (
        id INT(11) NOT NULL AUTO_INCREMENT, user_id INT(11) NOT NULL, kind VARCHAR(40) NOT NULL, period VARCHAR(7) NOT NULL, note VARCHAR(500) NOT NULL,
        given_by INT(11) NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id), KEY u (user_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS dl_internal_plans (
        product_id INT(11) NOT NULL, equipment_cost DECIMAL(15,2) NOT NULL DEFAULT 0, training_cost DECIMAL(15,2) NOT NULL DEFAULT 0, employee_monthly DECIMAL(15,2) NOT NULL DEFAULT 0,
        operating_monthly DECIMAL(15,2) NOT NULL DEFAULT 0, internal_unit_cost DECIMAL(15,2) NOT NULL DEFAULT 0, capacity_monthly DECIMAL(10,2) NOT NULL DEFAULT 0, expected_quality DECIMAL(3,2) NULL,
        expected_hours DECIMAL(8,2) NULL, notes TEXT NULL, decision VARCHAR(30) NULL, decision_note VARCHAR(500) NULL, decided_by INT(11) NULL, decided_at DATETIME NULL,
        updated_by INT(11) NULL, updated_at DATETIME NULL, PRIMARY KEY (product_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function dlq_ref(int $id): string { return 'QI' . str_pad((string) $id, 5, '0', STR_PAD_LEFT); }
function dlq_upheld(array $i): bool { return in_array($i['status'], ['Resolved', 'Closed'], true) && in_array($i['responsibility'], ['RM Tasker', 'Shared'], true); }
function dlq_badge(string $s): string
{
    $c = ['Open' => 'dl-bad', 'Under Investigation' => 'dl-warn', 'Resolved' => 'dl-ok', 'Closed' => ''][$s] ?? '';
    return '<span class="dl-st ' . $c . '">' . dl_e($s) . '</span>';
}
function dlq_resp_badge(string $r): string
{
    $c = ['Not yet investigated' => '', 'RM Tasker' => 'dl-bad', 'Shared' => 'dl-warn', 'RISE MOTIVE' => 'dl-blue', 'Customer' => 'dl-blue', 'No fault found' => 'dl-ok'][$r] ?? '';
    return '<span class="dl-st ' . $c . '">' . dl_e($r) . '</span>';
}

function dlq_get(mysqli $conn, int $id): ?array
{
    $i = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT i.*, p.product_name FROM dl_issues i LEFT JOIN products p ON p.id = i.product_id WHERE i.id = ' . $id));
    if (!$i) { return null; }
    $i['ref'] = dlq_ref($id);
    $i['dref'] = dl_ref((int) $i['delegation_id']);
    $t = $i['party_id'] ? dl_tasker($conn, (int) $i['party_id']) : null;
    $i['tasker'] = $t ? dl_tasker_name($t) : '—';
    return $i;
}

function dlq_note(mysqli $conn, int $iid, string $kind, string $note, int $visible = 0, string $actor = 'staff', ?int $partyId = null): void
{
    $u = $actor === 'staff' ? (dl_uid() ?: null) : null;
    $now = dl_now();
    $s = mysqli_prepare($conn, 'INSERT INTO dl_issue_notes (issue_id, kind, note, actor, user_id, party_id, visible_to_tasker, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($s, 'isssiiis', $iid, $kind, $note, $actor, $u, $partyId, $visible, $now);
    mysqli_stmt_execute($s);
}

/** Issues of a delegation (newest first). */
function dlq_for_delegation(mysqli $conn, int $did): array
{
    return mysqli_fetch_all(mysqli_query($conn, 'SELECT * FROM dl_issues WHERE delegation_id = ' . $did . ' ORDER BY id DESC'), MYSQLI_ASSOC);
}

/**
 * Reports an issue. $hold: puts the delegation in "Issue Review" and holds the Tasker payment (a legitimate hold,
 * never overdue) until the issue is resolved. Nothing is deducted.
 */
function dlq_create(mysqli $conn, array $d, string $type, string $desc, string $reporter, string $reporterName, string $impactKind, float $impact, string $impactNote, bool $hold): int
{
    $now = dl_now(); $u = dl_uid() ?: null;
    $did = (int) $d['id']; $pid = $d['party_id'] ? (int) $d['party_id'] : null; $prod = (int) $d['product_id'];
    $canHold = $hold && !in_array($d['status'], array_merge(DL_ENDED, ['Closed – Financially Reconciled', 'Draft', 'Issue Review']), true);
    $prev = $canHold ? $d['status'] : null;
    $held = $canHold ? 1 : 0;
    $s = mysqli_prepare($conn, 'INSERT INTO dl_issues (delegation_id, party_id, product_id, type, description, reported_by_kind, reporter_name, reported_at, impact_kind, impact_amount, impact_note, held_payment, prev_status, created_by, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($s, 'iiissssssdsisis', $did, $pid, $prod, $type, $desc, $reporter, $reporterName, $now, $impactKind, $impact, $impactNote, $held, $prev, $u, $now);
    mysqli_stmt_execute($s);
    $iid = (int) mysqli_insert_id($conn);
    $ref = dlq_ref($iid);
    if ($canHold) {
        // An existing legitimate hold reason (e.g. payment details missing) is kept; otherwise the complaint is the reason.
        $keep = $d['nonpay_reason'] && in_array($d['nonpay_reason'], DL_HOLD_REASONS, true);
        dl_set_status($conn, $did, 'Issue Review', $keep ? [] : ['nonpay_reason' => 'Customer has raised a complaint', 'nonpay_note' => $ref . ' under review']);
    }
    dl_event($conn, $did, 'issue_reported', $ref . ' reported: ' . $type . ($impact > 0 ? ' · cost to RISE MOTIVE ' . dl_money($impact) . ' (' . $impactKind . ')' : '') . ($canHold ? ' · Tasker payment held during the review' : ''), 'staff', 0, null, 0);
    dl_event($conn, $did, 'issue_notice', 'An issue (' . $type . ') was reported on this assignment. It will be reviewed with you before any finding.', 'staff', 0, null, 1);
    dl_audit($conn, 'issue', $iid, 'reported', $ref . ' · ' . $d['ref'] . ' · ' . $type . ' · reported by ' . $reporter . ($reporterName !== '' ? ' (' . $reporterName . ')' : '') . ' · impact ' . dl_money($impact) . ($canHold ? ' · payment held' : ''), null, 'staff', $pid);
    dlq_note($conn, $iid, 'reported', $desc, 0);
    dl_notify_staff($conn, dl_users_with($conn, 'delegations.quality'), 'Service issue ' . $ref . ' on ' . $d['ref'], $type . ': ' . mb_substr($desc, 0, 300));
    return $iid;
}

/** After an issue is resolved or closed: lifts its payment hold when no other open issue still holds the delegation. */
function dlq_release(mysqli $conn, array $i): bool
{
    if (!(int) $i['held_payment']) { return false; }
    $did = (int) $i['delegation_id'];
    $other = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM dl_issues WHERE delegation_id = $did AND held_payment = 1 AND id <> " . (int) $i['id'] . " AND status IN ('Open', 'Under Investigation')"))[0];
    if ($other) { return false; }
    $d = dl_get($conn, $did);
    if (!$d || $d['status'] !== 'Issue Review') { return false; }
    $prevRow = mysqli_fetch_assoc(mysqli_query($conn, "SELECT prev_status FROM dl_issues WHERE delegation_id = $did AND held_payment = 1 AND prev_status IS NOT NULL ORDER BY id LIMIT 1"));
    $prev = $prevRow['prev_status'] ?? 'Verified';
    $extra = $d['nonpay_reason'] === 'Customer has raised a complaint' && preg_match('/^QI\d+ under review$/', (string) $d['nonpay_note']) ? ['nonpay_reason' => null, 'nonpay_note' => null] : [];
    dl_set_status($conn, $did, $prev, $extra);
    dl_event($conn, $did, 'issue_hold_lifted', 'Issue review finished: status back to ' . $prev . ' and the payment hold is lifted.', 'staff', 0, null, 1);
    if (function_exists('dl_sync_after_payment')) { dl_sync_after_payment($conn, $did); }
    return true;
}

/**
 * A Tasker's rating from VERIFIED, FINAL outcomes only:
 *  - counted: verified delegations whose review period has ended with no open issue;
 *  - provisional (shown, not counted): still in the review period, or an issue is under investigation;
 *  - issues upheld against the Tasker lower the rating (penalty per issue from Settings); others never do.
 */
function dlq_tasker_rating(mysqli $conn, int $pid): array
{
    $now = dl_now();
    $rows = mysqli_fetch_all(mysqli_query($conn, "SELECT id, verified_at, rating_final_at, perf_score, quality_rating, satisfaction_rating, instructions_rating, communication_rating, ontime, response, status
        FROM dl_delegations WHERE party_id = $pid AND verified_at IS NOT NULL AND status NOT IN ('Cancelled', 'Draft')"), MYSQLI_ASSOC);
    $issues = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM dl_issues WHERE party_id = $pid"), MYSQLI_ASSOC);
    $openBy = [];
    foreach ($issues as $i) { if (in_array($i['status'], DLQ_OPEN, true)) { $openBy[(int) $i['delegation_id']] = true; } }
    $final = []; $prov = [];
    foreach ($rows as $r) {
        if (($r['rating_final_at'] && $r['rating_final_at'] > $now) || isset($openBy[(int) $r['id']])) { $prov[] = $r; } else { $final[] = $r; }
    }
    $avg = function ($k) use ($final) { $v = array_filter(array_column($final, $k), function ($x) { return $x !== null; }); return $v ? array_sum($v) / count($v) : null; };
    $resp = mysqli_fetch_row(mysqli_query($conn, "SELECT SUM(CASE WHEN response IN ('Tasker-confirmed', 'Staff-confirmed') THEN 1 ELSE 0 END), SUM(CASE WHEN response IS NOT NULL THEN 1 ELSE 0 END),
        SUM(CASE WHEN status NOT IN ('Draft', 'Cancelled') THEN 1 ELSE 0 END) FROM dl_delegations WHERE party_id = $pid"));
    $upheld = array_values(array_filter($issues, 'dlq_upheld'));
    $notUpheld = array_values(array_filter($issues, function ($i) { return in_array($i['status'], ['Resolved', 'Closed'], true) && !dlq_upheld($i); }));
    $open = array_values(array_filter($issues, function ($i) { return in_array($i['status'], DLQ_OPEN, true); }));
    $complaints = array_values(array_filter($issues, function ($i) { return $i['type'] === 'Customer Complaint' || $i['reported_by_kind'] === 'Customer'; }));
    $n = count($final);
    $pen = (float) dl_setting($conn, 'issue_penalty');
    $base = $avg('perf_score');
    $score = $base !== null ? max(0, round($base - $pen * count($upheld), 1)) : null;
    $minHist = max(1, (int) dl_setting($conn, 'min_history'));
    $band = $score === null ? 'No final rating yet' : ($n < $minHist ? 'New / limited history' : ($score >= 85 ? 'Excellent' : ($score >= 70 ? 'Good' : ($score >= 55 ? 'Fair' : 'Needs improvement'))));
    $cost = 0; foreach ($upheld as $i) { $cost += (float) $i['impact_amount']; }
    return ['final' => $n, 'provisional' => count($prov), 'score' => $score, 'base' => $base, 'band' => $band, 'penalty' => $pen * count($upheld),
        'quality' => $avg('quality_rating'), 'satisfaction' => $avg('satisfaction_rating'), 'instructions' => $avg('instructions_rating'), 'communication' => $avg('communication_rating'),
        'ontime' => $n ? array_sum(array_map(function ($r) { return (int) $r['ontime']; }, $final)) / $n : null,
        'accepted' => (int) $resp[1] ? (int) $resp[0] / (int) $resp[1] : null, 'assigned' => (int) $resp[2],
        'upheld' => count($upheld), 'not_upheld' => count($notUpheld), 'open_issues' => count($open), 'complaints' => count($complaints), 'issues' => count($issues),
        'error_rate' => $n ? count($upheld) / $n : null, 'error_cost' => $cost];
}

/** All Taskers with their rating and a Preferred status suggestion (management decides; nothing changes automatically). */
function dlq_all_ratings(mysqli $conn): array
{
    $min = (float) dl_setting($conn, 'pref_min_score'); $jobs = (int) dl_setting($conn, 'pref_min_jobs');
    $out = [];
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT bp.*, t.id tasker_no, t.preferred, t.preferred_reason, t.status tasker_status FROM dl_taskers t JOIN business_parties bp ON bp.id = t.party_id WHERE bp.type = 'Tasker' ORDER BY bp.name"), MYSQLI_ASSOC) as $t) {
        $r = dlq_tasker_rating($conn, (int) $t['id']);
        $t['code'] = dl_code((int) $t['tasker_no']); $t['display'] = dl_tasker_name($t); $t['rating'] = $r;
        $t['suggest'] = '';
        $qualifies = $r['score'] !== null && $r['score'] >= $min && $r['final'] >= $jobs && $r['open_issues'] === 0 && $r['upheld'] === 0;
        if (!(int) $t['preferred'] && $qualifies && $t['tasker_status'] === 'Active') { $t['suggest'] = 'grant'; }
        if ((int) $t['preferred'] && $r['score'] !== null && ($r['score'] < $min - 10 || $r['upheld'] > 1)) { $t['suggest'] = 'review'; }
        $out[] = $t;
    }
    usort($out, function ($a, $b) { return ($b['rating']['score'] ?? -1) <=> ($a['rating']['score'] ?? -1); });
    return $out;
}

/** Delegations of a period with their figures: actual (verified) when available, otherwise expected. */
function dlq_delegations(mysqli $conn, string $from, string $to, array $f = []): array
{
    $w = ["d.status <> 'Draft'", "d.created_at >= '" . mysqli_real_escape_string($conn, $from) . " 00:00:00'", "d.created_at <= '" . mysqli_real_escape_string($conn, $to) . " 23:59:59'"];
    if (!empty($f['party'])) { $w[] = 'd.party_id = ' . (int) $f['party']; }
    if (!empty($f['product'])) { $w[] = 'd.product_id = ' . (int) $f['product']; }
    if (!empty($f['user'])) { $w[] = 'd.created_by = ' . (int) $f['user']; }
    $rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT d.*, p.product_name, c.name customer_name FROM dl_delegations d LEFT JOIN products p ON p.id = d.product_id LEFT JOIN customers c ON c.id = d.customer_id WHERE ' . implode(' AND ', $w) . ' ORDER BY d.id DESC'), MYSQLI_ASSOC);
    $names = [];
    $errs = [];
    foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT delegation_id, SUM(impact_amount) c, COUNT(*) n FROM dl_issues GROUP BY delegation_id'), MYSQLI_ASSOC) as $e) { $errs[(int) $e['delegation_id']] = $e; }
    foreach ($rows as &$d) {
        $d['ref'] = dl_ref((int) $d['id']);
        $pid = (int) $d['party_id'];
        if ($pid && !isset($names[$pid])) { $t = dl_tasker($conn, $pid); $names[$pid] = $t ? dl_tasker_name($t) : '#' . $pid; }
        $d['tasker_name'] = $pid ? $names[$pid] : '—';
        $d['actual'] = $d['verified_at'] !== null;
        $d['pos'] = dl_pay_position($conn, $d);
        $d['cogs'] = (float) ($d['actual'] ? $d['cogs_act'] : $d['cogs_exp']);
        $d['direct'] = (float) ($d['actual'] ? $d['direct_act'] : $d['direct_exp']);
        $d['tasker_cost'] = $d['actual'] ? (float) ($d['pos']['approved'] ?? $d['pos']['entitled']) : (float) $d['pay_exp'];
        $d['profit'] = round((float) $d['revenue'] - $d['cogs'] - $d['direct'] - $d['tasker_cost'], 2);
        $d['error_cost'] = (float) ($errs[(int) $d['id']]['c'] ?? 0);
        $d['issues'] = (int) ($errs[(int) $d['id']]['n'] ?? 0);
        $d['profit_after'] = round($d['profit'] - $d['error_cost'], 2);
        $d['ended'] = in_array($d['status'], DL_ENDED, true);
        $d['done'] = !$d['ended'] && $d['verified_at'] !== null;
    }
    unset($d);
    return $rows;
}

/** Management dashboard KPIs for a period. Gross profit uses actual verified figures only. */
function dlq_kpis(mysqli $conn, string $from, string $to): array
{
    $D = dlq_delegations($conn, $from, $to);
    $k = ['total' => 0, 'pending' => 0, 'completed' => 0, 'failed' => 0, 'reassigned' => 0, 'revenue' => 0, 'cogs' => 0, 'tasker' => 0, 'tasker_paid' => 0, 'direct' => 0, 'profit' => 0,
        'rev_verified' => 0, 'outstanding' => 0, 'overdue' => 0, 'overdue_amt' => 0, 'unconfirmed' => 0, 'disputed' => 0, 'error_cost' => 0, 'services' => [], 'taskers' => []];
    foreach ($D as $d) {
        if ($d['status'] === 'Reassigned') { $k['reassigned']++; continue; } // its replacement is counted instead
        $k['total']++;
        if (in_array($d['status'], ['Rejected', 'Cancelled'], true)) { $k['failed']++; continue; }
        if ($d['done']) { $k['completed']++; } else { $k['pending']++; }
        $k['revenue'] += (float) $d['revenue'];
        $k['error_cost'] += $d['error_cost'];
        if ($d['party_id']) { $k['taskers'][(int) $d['party_id']] = true; }
        $sv = &$k['services'][(int) $d['product_id']];
        $sv = $sv ?? ['name' => $d['product_name'], 'n' => 0, 'revenue' => 0, 'profit' => 0, 'cost' => 0, 'verified' => 0];
        $sv['n']++; $sv['revenue'] += (float) $d['revenue'];
        if ($d['done']) {
            $k['rev_verified'] += (float) $d['revenue']; $k['cogs'] += $d['cogs']; $k['direct'] += $d['direct']; $k['tasker'] += $d['tasker_cost']; $k['profit'] += $d['profit'];
            $sv['profit'] += $d['profit_after']; $sv['cost'] += $d['cogs'] + $d['direct'] + $d['tasker_cost']; $sv['verified']++;
        }
        unset($sv);
        $p = $d['pos'];
        $k['tasker_paid'] += $p['paid'];
        if ($d['done']) { $k['outstanding'] += $p['outstanding']; }
        if ($p['overdue']) { $k['overdue']++; $k['overdue_amt'] += $p['outstanding']; }
        foreach ($p['pays'] as $x) { if ($x['status'] === 'Recorded' && $x['confirm_status'] !== 'Confirmed') { $k['unconfirmed']++; } }
        if ($p['disputes']) { $k['disputed']++; }
    }
    $k['margin'] = $k['rev_verified'] > 0 ? round($k['profit'] / $k['rev_verified'] * 100, 1) : null;
    $k['active_taskers'] = count($k['taskers']);
    $k['active_registered'] = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM dl_taskers t JOIN business_parties bp ON bp.id = t.party_id WHERE bp.type = 'Tasker' AND t.status = 'Active'"))[0];
    $sv = array_values($k['services']);
    $k['most_delegated'] = $sv; usort($k['most_delegated'], function ($a, $b) { return $b['n'] <=> $a['n']; });
    $k['most_profitable'] = array_values(array_filter($sv, function ($s) { return $s['verified'] > 0; })); usort($k['most_profitable'], function ($a, $b) { return $b['profit'] <=> $a['profit']; });
    $k['highest_cost'] = array_values(array_filter($sv, function ($s) { return $s['verified'] > 0; })); usort($k['highest_cost'], function ($a, $b) { return $b['cost'] <=> $a['cost']; });
    $k['rows'] = $D;
    return $k;
}

/**
 * Staff delegation performance for a period (by the employee who created the delegation / recorded the request).
 * "Saved" = a customer request RISE MOTIVE could not serve internally (External Only, or internal capability No)
 * that was served through an RM Tasker instead of being turned away. "Avoidable" = delegated although RISE MOTIVE
 * could provide it internally (internal capability Yes): smart delivery, not the most external assignments.
 */
function dlq_staff(mysqli $conn, string $from, string $to): array
{
    $D = dlq_delegations($conn, $from, $to);
    $os = [];
    foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT product_id, fulfilment, internal_capability FROM dl_offering_settings'), MYSQLI_ASSOC) as $o) { $os[(int) $o['product_id']] = $o; }
    $onBehalf = [];
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT user_id, COUNT(*) n FROM dl_events WHERE on_behalf = 1 AND created_at >= '$from 00:00:00' AND created_at <= '$to 23:59:59' GROUP BY user_id"), MYSQLI_ASSOC) as $r) { $onBehalf[(int) $r['user_id']] = (int) $r['n']; }
    $S = [];
    $blank = ['handled' => 0, 'successful' => 0, 'failed' => 0, 'revenue' => 0, 'profit' => 0, 'saved' => 0, 'sat' => [], 'override' => 0, 'override_noreason' => 0, 'refused' => 0,
        'avoidable' => 0, 'upheld' => 0, 'lost' => 0, 'lost_value' => 0, 'on_behalf' => 0];
    foreach ($D as $d) {
        if ($d['status'] === 'Reassigned') { continue; }
        $u = (int) $d['created_by'];
        $S[$u] = $S[$u] ?? $blank;
        $s = &$S[$u];
        $s['handled']++;
        $o = $os[(int) $d['product_id']] ?? ['fulfilment' => 'Internal', 'internal_capability' => 'Yes'];
        if ($o['fulfilment'] === 'External Only' || $o['internal_capability'] === 'No') { $s['saved']++; }
        if ($o['fulfilment'] === 'Delegatable' && $o['internal_capability'] === 'Yes') { $s['avoidable']++; }
        if (in_array($d['status'], ['Rejected', 'Cancelled'], true)) { $s['failed']++; if (strpos((string) $d['cancel_reason'], 'Not approved') === 0) { $s['refused']++; } }
        if ($d['done']) {
            $s['successful']++; $s['revenue'] += (float) $d['revenue']; $s['profit'] += $d['profit_after'];
            if ($d['satisfaction_rating'] !== null) { $s['sat'][] = (float) $d['satisfaction_rating']; }
        }
        if ($d['recommended_party_id'] && (int) $d['recommended_party_id'] !== (int) $d['party_id']) { $s['override']++; if (trim((string) $d['override_reason']) === '') { $s['override_noreason']++; } }
        unset($s);
    }
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT user_id, COUNT(*) n, SUM(COALESCE(potential_revenue, 0)) v FROM dl_lost WHERE created_at >= '$from 00:00:00' AND created_at <= '$to 23:59:59' GROUP BY user_id"), MYSQLI_ASSOC) as $r) {
        $u = (int) $r['user_id']; $S[$u] = $S[$u] ?? $blank; $S[$u]['lost'] = (int) $r['n']; $S[$u]['lost_value'] = (float) $r['v'];
    }
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT d.created_by, COUNT(*) n FROM dl_issues i JOIN dl_delegations d ON d.id = i.delegation_id WHERE i.status IN ('Resolved', 'Closed') AND i.responsibility IN ('RISE MOTIVE', 'Shared')
        AND d.created_at >= '$from 00:00:00' AND d.created_at <= '$to 23:59:59' GROUP BY d.created_by"), MYSQLI_ASSOC) as $r) { $u = (int) $r['created_by']; if (isset($S[$u])) { $S[$u]['upheld'] = (int) $r['n']; } }
    foreach ($S as $u => &$s) {
        $s['on_behalf'] = $onBehalf[$u] ?? 0;
        $s['satisfaction'] = $s['sat'] ? round(array_sum($s['sat']) / count($s['sat']), 2) : null;
        $s['success_rate'] = $s['handled'] ? round($s['successful'] / $s['handled'] * 100) : null;
        $issues = $s['override_noreason'] + $s['refused'] + $s['upheld'];
        $s['compliance'] = $s['handled'] ? max(0, round(100 - $issues / $s['handled'] * 100)) : null;
        $s['saved_rate'] = ($s['saved'] + $s['lost']) ? round($s['saved'] / ($s['saved'] + $s['lost']) * 100) : null;
    }
    unset($s);
    return $S;
}

/** Suggested staff recognition for a period (management decides): only with good outcomes, never for volume alone. */
function dlq_staff_suggestions(array $S, array $names): array
{
    $ok = array_filter($S, function ($s) { return $s['successful'] > 0 && ($s['satisfaction'] === null || $s['satisfaction'] >= 3.5) && ($s['compliance'] ?? 0) >= 80; });
    $out = [];
    $top = function ($k) use ($ok) { $best = null; $v = 0; foreach ($ok as $u => $s) { if ($s[$k] > $v) { $v = $s[$k]; $best = $u; } } return [$best, $v]; };
    [$u, $v] = $top('saved'); if ($u !== null) { $out[] = ['Customer Retention Recognition', $u, $v . ' customer request' . ($v == 1 ? '' : 's') . ' served through an RM Tasker instead of being turned away']; }
    [$u, $v] = $top('successful'); if ($u !== null) { $out[] = ['Successful Delegation Recognition', $u, $v . ' delegated service' . ($v == 1 ? '' : 's') . ' completed and verified']; }
    [$u, $v] = $top('revenue'); if ($u !== null) { $out[] = ['Revenue Contribution Recognition', $u, dl_money($v) . ' revenue from verified delegated services']; }
    return array_map(function ($x) use ($names) { return ['kind' => $x[0], 'user' => $x[1], 'name' => $names[$x[1]] ?? '#' . $x[1], 'why' => $x[2]]; }, $out);
}

/** OPTION A — continue outsourcing: what the last $months months of delegations of one service cost. */
function dlq_outsourcing(mysqli $conn, int $productId, int $months): array
{
    $from = date('Y-m-d', strtotime('-' . $months . ' months'));
    $to = date('Y-m-d');
    $D = array_values(array_filter(dlq_delegations($conn, $from, $to, ['product' => $productId]), function ($d) { return !$d['ended']; }));
    $done = array_values(array_filter($D, function ($d) { return $d['done']; }));
    $n = count($D);
    $rates = array_map(function ($d) { return (float) $d['rate_pct']; }, $D);
    $pay = array_sum(array_map(function ($d) { return $d['tasker_cost']; }, $D));
    $hours = [];
    foreach ($done as $d) { if ($d['completed_at'] && $d['approved_at']) { $hours[] = max(0, (strtotime($d['completed_at']) - strtotime($d['approved_at'])) / 3600); } }
    $q = array_filter(array_map(function ($d) { return $d['quality_rating'] !== null ? (float) $d['quality_rating'] : null; }, $done), function ($x) { return $x !== null; });
    $ontime = $done ? array_sum(array_map(function ($d) { return (int) $d['ontime']; }, $done)) / count($done) : null;
    $errors = array_sum(array_map(function ($d) { return $d['error_cost']; }, $D));
    $lost = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM dl_lost WHERE product_id = $productId AND created_at >= '$from 00:00:00'"))[0];
    $monthly = $n / max(1, $months);
    return ['months' => $months, 'count' => $n, 'verified' => count($done), 'monthly_volume' => round($monthly, 2), 'avg_rate' => $rates ? round(array_sum($rates) / count($rates), 2) : null,
        'monthly_payments' => round($pay / max(1, $months), 2), 'annual_cost' => round($pay / max(1, $months) * 12, 2), 'avg_payment' => $n ? round($pay / $n, 2) : 0,
        'revenue_monthly' => round(array_sum(array_column($D, 'revenue')) / max(1, $months), 2), 'quality' => $q ? round(array_sum($q) / count($q), 2) : null, 'ontime' => $ontime,
        'hours' => $hours ? round(array_sum($hours) / count($hours), 1) : null, 'error_cost' => $errors, 'lost' => $lost];
}

/**
 * OPTION B — internalize, compared with A. Materials / cost of sales are the same either way, so the comparison is
 * the Tasker payments (A) against the internal employee, operating and per-service costs (B).
 * The recommendation weighs demand, capacity, quality, investment and payback; management records the decision.
 */
function dlq_internal_eval(mysqli $conn, array $A, ?array $P): array
{
    if (!$P) { return ['ready' => false, 'verdict' => 'Enter the internal costs to compare', 'reasons' => []]; }
    $vol = (float) $A['monthly_volume'];
    $cap = (float) $P['capacity_monthly'];
    $served = $cap > 0 ? min($vol, $cap) : $vol;
    $invest = (float) $P['equipment_cost'] + (float) $P['training_cost'];
    $bMonthly = (float) $P['employee_monthly'] + (float) $P['operating_monthly'] + (float) $P['internal_unit_cost'] * $served;
    $aServed = $vol > 0 ? $A['monthly_payments'] * ($served / $vol) : 0; // the outsourcing cost of the volume B can take over
    $saving = round($aServed - $bMonthly, 2);
    $perService = $served > 0 ? round($bMonthly / $served, 2) : null;
    $payback = $saving > 0 ? round($invest / $saving, 1) : null;
    $maxPay = (float) dl_setting($conn, 'int_max_payback');
    $minVol = (float) dl_setting($conn, 'int_min_volume');
    $why = []; $go = true; $data = $A['count'] >= 3;
    if (!$data) { $why[] = 'Only ' . $A['count'] . ' delegation(s) in the last ' . $A['months'] . ' months: not enough demand data yet.'; }
    if ($vol < $minVol) { $go = false; $why[] = 'Demand ' . $vol . ' per month is below the minimum of ' . $minVol . ' to justify internal capacity.'; } else { $why[] = 'Demand: ' . $vol . ' per month' . ($A['lost'] ? ' (plus ' . $A['lost'] . ' unavailable request(s) recorded)' : '') . '.'; }
    if ($cap > 0 && $cap < $vol) { $why[] = 'Internal capacity (' . $cap . '/month) covers only part of the demand: the rest stays outsourced.'; }
    if ($saving <= 0) { $go = false; $why[] = 'Internal cost (' . dl_money($bMonthly) . '/month) is not lower than the Tasker payments it replaces (' . dl_money($aServed) . '/month).'; }
    else { $why[] = 'Expected saving ' . dl_money($saving) . ' per month.'; }
    if ($payback !== null) { if ($payback > $maxPay) { $go = false; $why[] = 'Payback ' . $payback . ' months is longer than the ' . $maxPay . ' months accepted.'; } else { $why[] = 'Payback in ' . $payback . ' months.'; } }
    if ($A['quality'] !== null && $P['expected_quality'] !== null) {
        if ((float) $P['expected_quality'] < (float) $A['quality'] - 0.3) { $go = false; $why[] = 'Expected internal quality ' . (float) $P['expected_quality'] . '/5 is below the Taskers\' ' . $A['quality'] . '/5.'; }
        elseif ((float) $A['quality'] < 3.5) { $why[] = 'Taskers\' verified quality is only ' . $A['quality'] . '/5: internal delivery could improve it.'; }
    }
    if ($A['error_cost'] > 0) { $why[] = 'Cost of errors on outsourced work in the period: ' . dl_money($A['error_cost']) . '.'; }
    $verdict = !$data ? 'Not enough data yet – continue outsourcing and keep measuring' : ($go ? 'Consider internalizing' : 'Continue outsourcing');
    return ['ready' => true, 'served' => $served, 'invest' => $invest, 'b_monthly' => round($bMonthly, 2), 'a_served' => round($aServed, 2), 'saving' => $saving, 'annual_saving' => round($saving * 12, 2),
        'per_service' => $perService, 'payback' => $payback, 'verdict' => $verdict, 'go' => $go && $data, 'reasons' => $why];
}