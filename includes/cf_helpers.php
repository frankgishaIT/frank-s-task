<?php
/**
 * NEW FILE: includes/cf_helpers.php — RM CUSTOMER FIRST (Task 3): "Don't lose a customer without exploring every
 * possible solution."
 *
 * A customer request (cf_cases) follows the 9 steps: need → stock → RM Offerings → other employees → alternatives →
 * sourcing / scheduling → escalation → communication → outcome. Every case ends with one of the 8 outcomes; an
 * unresolved one has a reason. Escalations (cf_escalations) have an assignee, priority, due date and response, and
 * overdue ones are notified. Unmet demand is linked ONCE to RM Market Insights (cf_cases.mi_request_id), never copied.
 * Reuses: customers, products (stock / RM Offerings), users / departments, Employee skills (Market Insights),
 * sales, tasks, Customer Portal requests, notifications.
 */
require_once __DIR__ . '/notification_helper.php';

const CF_OUTCOMES = ['Fulfilled' => ['Customer received the requested product or service', 'success'], 'Alternative Accepted' => ['Customer accepted a suitable alternative', 'success'],
    'Referred' => ['Referred to an appropriate external provider', 'info'], 'Pending Approval' => ['Waiting for a management approval', 'warning'],
    'Product to Be Sourced' => ['Sourcing / restocking has started', 'warning'], 'Service Scheduled' => ['The service will be delivered later', 'primary'],
    'Unresolved' => ['No acceptable solution was found', 'danger'], 'Customer Declined' => ['The customer chose not to proceed after an option was offered', 'secondary']];
const CF_FINAL = ['Fulfilled', 'Alternative Accepted', 'Referred', 'Unresolved', 'Customer Declined']; // close the case
const CF_REASONS = ['Product out of stock', 'Product not offered', 'Service not offered', 'No qualified employee', 'Employee unavailable', 'Equipment unavailable', 'Software unavailable',
    'Price not accepted', 'Delivery delay', 'Customer declined', 'Other'];
const CF_ESC_REASONS = ['Needs a management decision', 'Price / discount approval', 'No qualified employee here', 'Product must be sourced', 'Service must be scheduled', 'Technical problem', 'Complaint', 'Other'];
const CF_PRIORITY = ['Low' => 72, 'Normal' => 24, 'High' => 8, 'Urgent' => 2]; // default hours to respond

function cf_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    $T = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cf_cases (id INT(11) NOT NULL AUTO_INCREMENT, customer_id INT(11) NULL, walkin_name VARCHAR(120) NULL, contact VARCHAR(60) NULL, kind VARCHAR(10) NOT NULL DEFAULT 'product',
        request_text VARCHAR(200) NOT NULL, product_id INT(11) NULL, quantity VARCHAR(60) NULL, budget DECIMAL(14,2) NULL, needed_by DATE NULL, handled_by INT(11) NULL, department_id INT(11) NULL, branch_id INT(11) NULL,
        stock_check VARCHAR(255) NULL, offering_check VARCHAR(255) NULL, capability_check VARCHAR(255) NULL, alternative VARCHAR(255) NULL, alternative_product_id INT(11) NULL, sourcing VARCHAR(255) NULL,
        expected_date DATE NULL, communication VARCHAR(500) NULL, status VARCHAR(12) NOT NULL DEFAULT 'Open', outcome VARCHAR(30) NULL, unresolved_reason VARCHAR(40) NULL, outcome_note VARCHAR(500) NULL,
        feedback_rating TINYINT(1) NULL, feedback VARCHAR(500) NULL, sale_id INT(11) NULL, mi_request_id INT(11) NULL, portal_request_id INT(11) NULL, task_id INT(11) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME NULL, closed_at DATETIME NULL, closed_by INT(11) NULL, PRIMARY KEY (id), KEY st (status), KEY cu (customer_id)) $T");
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cf_escalations (id INT(11) NOT NULL AUTO_INCREMENT, case_id INT(11) NOT NULL, from_user INT(11) NULL, to_user INT(11) NOT NULL, department_id INT(11) NULL,
        reason VARCHAR(60) NOT NULL, checked VARCHAR(1000) NULL, priority VARCHAR(10) NOT NULL DEFAULT 'Normal', due_at DATETIME NULL, status VARCHAR(16) NOT NULL DEFAULT 'Open', response VARCHAR(1000) NULL,
        responded_by INT(11) NULL, responded_at DATETIME NULL, overdue_notified TINYINT(1) NOT NULL DEFAULT 0, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY c (case_id), KEY tu (to_user, status)) $T");
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS cf_log (id INT(11) NOT NULL AUTO_INCREMENT, case_id INT(11) NOT NULL, user_id INT(11) NULL, action VARCHAR(30) NOT NULL, details VARCHAR(1000) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY c (case_id)) $T");
}

function cf_e($v): string { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function cf_uid(): ?int { return isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null; }
function cf_log(mysqli $conn, int $case, string $action, string $details = ''): void
{
    $u = cf_uid(); $d = mb_substr($details, 0, 1000); $s = mysqli_prepare($conn, 'INSERT INTO cf_log (case_id, user_id, action, details) VALUES (?, ?, ?, ?)'); mysqli_stmt_bind_param($s, 'iiss', $case, $u, $action, $d); mysqli_stmt_execute($s);
}
function cf_case_no(int $id): string { return 'CF-' . str_pad((string) $id, 5, '0', STR_PAD_LEFT); }
function cf_outcome_badge(?string $o): string
{
    if (!$o) { return '<span class="badge bg-light text-dark border">No outcome yet</span>'; }
    $c = CF_OUTCOMES[$o][1] ?? 'secondary';
    $st = ['success' => '#0FA968;color:#fff', 'info' => '#E6F0FF;color:#1E2FE0;border:1px solid #C9D6FF', 'warning' => '#FFF4D6;color:#6B4E00;border:1px solid #F2DFA6', 'primary' => '#1E2FE0;color:#fff',
           'danger' => '#FDECEC;color:#9B1C1C;border:1px solid #F5C2C2', 'secondary' => '#EEF0F5;color:#5B6275;border:1px solid #DDE1EA'][$c] ?? '#EEF0F5;color:#5B6275';
    return '<span class="badge" style="background:' . $st . '">' . cf_e($o) . '</span>';
}

/**
 * Steps 2–5: what MY MOTIVE can offer for a request text: matching RM Offerings (with stock / active state and
 * price), in-stock alternatives of the same category, and employees who have the skill (Employee skills register).
 */
function cf_explore(mysqli $conn, string $q, string $kind, int $productId = 0): array
{
    $out = ['matches' => [], 'alternatives' => [], 'employees' => []];
    $q = trim($q); if ($q === '' && !$productId) { return $out; }
    $isService = $kind === 'service';
    $rows = mysqli_fetch_all(mysqli_query($conn, "SELECT id, product_name, product_code, item_type, category, quantity, reorder_level, selling_price, unit, is_active FROM products WHERE item_type = '" . ($isService ? 'Service' : 'Item') . "'"), MYSQLI_ASSOC);
    $norm = function ($s) { $s = mb_strtolower((string) $s); $s = preg_replace('/[^a-z0-9]+/u', ' ', $s); return trim(preg_replace('/\b(\w{4,})s\b/', '$1', $s)); };
    $nq = $norm($q); $words = array_filter(explode(' ', $nq), function ($w) { return strlen($w) > 1; });
    foreach ($rows as $p) {
        $n = $norm($p['product_name']); $score = 0;
        if ((int) $p['id'] === $productId) { $score = 100; }
        elseif ($nq !== '' && ($n === $nq || strpos($n, $nq) !== false || strpos($nq, $n) !== false)) { $score = 90; }
        elseif ($words) { $hit = 0; foreach ($words as $w) { if (strpos($n, $w) !== false) { $hit++; } } $score = (int) round($hit / count($words) * 75); similar_text($nq, $n, $pc); $score = max($score, (int) ($pc * 0.7)); }
        if ($score >= 50) { $p['score'] = $score; $out['matches'][] = $p; }
    }
    usort($out['matches'], function ($a, $b) { return $b['score'] <=> $a['score']; });
    $out['matches'] = array_slice($out['matches'], 0, 5);
    $cats = array_values(array_unique(array_filter(array_column($out['matches'], 'category'))));
    if ($cats && !$isService) {
        $ids = array_map('intval', array_column($out['matches'], 'id'));
        foreach ($rows as $p) { if ((int) $p['is_active'] && in_array($p['category'], $cats, true) && !in_array((int) $p['id'], $ids, true) && (float) $p['quantity'] > 0) { $out['alternatives'][] = $p; } }
        $out['alternatives'] = array_slice($out['alternatives'], 0, 6);
    }
    if ($isService) { // employees who have the skill (RM Market Insights → Employee skills), with availability
        $mk = __DIR__ . '/market_eval.php';
        if (is_file($mk)) { try { require_once $mk; mi_eval_ensure($conn); foreach (array_slice(mi_qualified($conn, $q, 1, 2), 0, 6) as $e) { $out['employees'][] = ['id' => (int) $e['user_id'], 'names' => $e['names'], 'level' => MI_PROFICIENCY[(int) $e['proficiency']] ?? '', 'available' => $e['available'], 'on_leave' => $e['on_leave'], 'open_tasks' => $e['open_tasks']]; } } catch (Throwable $e) { } }
    }
    return $out;
}

/** Situation of a catalogue item for the stock / offering check. */
function cf_stock_text(array $p): string
{
    if ($p['item_type'] === 'Service') { return (int) $p['is_active'] ? 'Service offered (RM Offerings) — price guide RWF ' . number_format((float) $p['selling_price']) : 'Service exists but is not active'; }
    if (!(int) $p['is_active']) { return 'Item exists but is not active'; }
    $q = (float) $p['quantity'];
    return $q <= 0 ? 'Out of stock' : ('In stock: ' . rtrim(rtrim(number_format($q, 2, '.', ''), '0'), '.') . ' ' . $p['unit'] . ($p['reorder_level'] !== null && $q <= (float) $p['reorder_level'] ? ' (low)' : '') . ' — RWF ' . number_format((float) $p['selling_price']));
}

/** Sends an escalation: assignment, priority, due date, notification. */
function cf_escalate(mysqli $conn, int $caseId, int $to, ?int $dept, string $reason, string $checked, string $priority, ?string $due): int
{
    $priority = isset(CF_PRIORITY[$priority]) ? $priority : 'Normal';
    $due = $due ?: date('Y-m-d H:i:s', time() + CF_PRIORITY[$priority] * 3600);
    $u = cf_uid(); $chk = mb_substr($checked, 0, 1000);
    $s = mysqli_prepare($conn, 'INSERT INTO cf_escalations (case_id, from_user, to_user, department_id, reason, checked, priority, due_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($s, 'iiiissss', $caseId, $u, $to, $dept, $reason, $chk, $priority, $due); mysqli_stmt_execute($s); $eid = (int) mysqli_insert_id($conn);
    mysqli_query($conn, "UPDATE cf_cases SET status = 'Escalated', updated_at = '" . date('Y-m-d H:i:s') . "' WHERE id = $caseId");
    notifyUser($conn, $to, 'Customer request escalated to you (' . $priority . ')', cf_case_no($caseId) . ': ' . $reason . '. Respond by ' . date('d M H:i', strtotime($due)) . '. Open RM Customer First.');
    cf_log($conn, $caseId, 'escalated', 'To ' . (mysqli_fetch_row(mysqli_query($conn, "SELECT names FROM users WHERE id = $to"))[0] ?? '#' . $to) . ' — ' . $reason . ' (' . $priority . ', due ' . date('d M H:i', strtotime($due)) . ')');
    return $eid;
}

/** Overdue escalations are notified once to the assignee and to the employee who escalated (runs at most hourly). */
function cf_overdue_check(mysqli $conn): void
{
    $f = sys_get_temp_dir() . '/cf_overdue_' . md5(__DIR__); if (is_file($f) && filemtime($f) > time() - 3600) { return; } @touch($f);
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT e.*, c.request_text FROM cf_escalations e JOIN cf_cases c ON c.id = e.case_id WHERE e.status IN ('Open', 'More information') AND e.overdue_notified = 0 AND e.due_at < '" . date('Y-m-d H:i:s') . "'"), MYSQLI_ASSOC) as $e) {
        $msg = cf_case_no((int) $e['case_id']) . ' (' . $e['request_text'] . ') is overdue since ' . date('d M H:i', strtotime($e['due_at'])) . '.';
        notifyUser($conn, (int) $e['to_user'], 'Overdue customer request', $msg); if ($e['from_user']) { notifyUser($conn, (int) $e['from_user'], 'Escalation not answered yet', $msg); }
        mysqli_query($conn, 'UPDATE cf_escalations SET overdue_notified = 1 WHERE id = ' . (int) $e['id']);
    }
}

/**
 * Links a case to RM Market Insights as ONE customer request (unmet demand): the existing product / service record
 * is used when it exists, otherwise it is created. Never twice for the same case.
 */
function cf_link_market(mysqli $conn, array $c): int
{
    if ($c['mi_request_id']) { return (int) $c['mi_request_id']; }
    require_once __DIR__ . '/market_helpers.php'; mi_ensure($conn);
    $kind = $c['kind'] === 'service' ? 'service' : 'product';
    if ($c['product_id']) { $opp = mi_opportunity_for_product($conn, (int) $c['product_id'], $kind); }
    else { $l = mi_lookup($conn, $c['request_text'], $kind); $hit = array_values(array_filter($l['opportunities'], function ($o) { return $o['score'] >= 85; }));
        $opp = $hit ? (int) $hit[0]['id'] : mi_create_opportunity($conn, ['kind' => $kind, 'name' => $c['request_text'], 'origin' => 'request']); }
    $reasonMap = ['Product out of stock' => 'Out of stock', 'Product not offered' => 'Not in our catalogue', 'Service not offered' => 'Service not yet included in company offerings', 'No qualified employee' => 'No qualified employee available',
                  'Employee unavailable' => 'Employee unavailable', 'Equipment unavailable' => 'Required equipment unavailable', 'Software unavailable' => 'Required software or technology unavailable'];
    $outMap = ['Product to Be Sourced' => 'Product to be sourced', 'Unresolved' => 'Request unresolved', 'Customer Declined' => 'Customer will return later', 'Referred' => 'Request unresolved', 'Alternative Accepted' => 'Alternative product offered'];
    $qty = (float) preg_replace('/[^0-9.]/', '', (string) $c['quantity']) ?: 1;
    $rid = mi_add_request($conn, $opp, ['customer_id' => $c['customer_id'], 'walkin_name' => $c['walkin_name'], 'walkin_phone' => $c['contact'], 'quantity' => $qty, 'expected_price' => $c['budget'] !== null && $qty > 0 ? round((float) $c['budget'] / $qty, 2) : '',
        'source' => $c['portal_request_id'] ? 'Customer Portal' : 'Walk-in', 'reason' => $reasonMap[$c['unresolved_reason'] ?? ''] ?? '', 'outcome' => $outMap[$c['outcome'] ?? ''] ?? 'Request unresolved',
        'notes' => 'From RM Customer First ' . cf_case_no((int) $c['id']) . ($c['outcome_note'] ? ': ' . $c['outcome_note'] : ''), 'branch_id' => $c['branch_id']]);
    mysqli_query($conn, 'UPDATE cf_cases SET mi_request_id = ' . (int) $rid . ' WHERE id = ' . (int) $c['id']);
    cf_log($conn, (int) $c['id'], 'market', 'Linked to RM Market Insights (request #' . $rid . ', opportunity #' . $opp . ')');
    return $rid;
}

/** Potentially lost opportunities (indicators, not proof that the customer left). */
function cf_loss_indicator(array $c, int $tooLongDays = 7): ?string
{
    if ($c['outcome'] === 'Unresolved') { return $c['kind'] === 'service' ? 'Service could not be delivered' : 'No solution found for the product'; }
    if ($c['outcome'] === 'Customer Declined') { return ($c['unresolved_reason'] ?? '') === 'Price not accepted' ? 'Did not proceed because of the price' : 'Rejected the option offered'; }
    if (!in_array($c['outcome'], CF_FINAL, true) && strtotime($c['created_at']) < time() - $tooLongDays * 86400) { return 'Unresolved for more than ' . $tooLongDays . ' days'; }
    return null;
}