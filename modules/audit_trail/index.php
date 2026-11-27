<?php
/**
 * NEW FILE: modules/audit_trail/index.php — ONE AUDIT TRAIL across the four tasks (cross-task section 5.3).
 * Reads the audit records each module already keeps (nothing is copied):
 *   RM Market Insights (mi_audit; opportunity decisions with their reason from mi_decisions), Financial Accounts
 *   (fa_audit: transfers, reversals, adjustments, reconciliation, account links), Transactions (transaction_audit:
 *   edits and deletions), RM Customer First (cf_log: escalations, outcomes), below-set-price decisions (sales),
 *   Staff Dashboard Cards (sdc_audit: publication, approval, deactivation) and Roles & Permissions (acl_audit).
 * Permission audit_trail.view opens the page; each line is ALSO limited to people who may see its module.
 */
if (session_status() === PHP_SESSION_NONE) { require_once __DIR__ . '/../../includes/session_boot.php'; rm_session_start(); }
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/access_control.php';
require_role(['Admin', 'Manager', 'Employee']);
if (!access_can('audit_trail.view', $conn)) { http_response_code(403); include __DIR__ . '/../../includes/access_denied.php'; exit; }

function at_e($v): string { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function at_rows(mysqli $conn, string $sql): array { try { $r = mysqli_query($conn, $sql); return $r ? mysqli_fetch_all($r, MYSQLI_ASSOC) : []; } catch (Throwable $e) { return []; } }

$isAdmin = (($_SESSION['user_role'] ?? '') === 'Admin');
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-d', strtotime('-30 days'));
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($_GET['to'] ?? '')) ? $_GET['to'] : date('Y-m-d');
$a = "'$from 00:00:00'"; $b = "'$to 23:59:59'";
$L = 1500;

// Which modules this person may see in the trail.
$src = [
    'market'    => ['RM Market Insights', access_can('market.reports', $conn) || access_can('market.approve', $conn)],
    'finance'   => ['Financial Accounts', access_can('fin_accounts.view', $conn)],
    'tx'        => ['Transactions', $isAdmin || access_can('fin_accounts.reconcile', $conn)],
    'cf'        => ['RM Customer First', access_can('customer_first.manage', $conn)],
    'price'     => ['Below-set-price approvals', in_array($_SESSION['user_role'] ?? '', ['Admin', 'Manager'], true)],
    'cards'     => ['Staff Dashboard Cards', access_can('staff_cards.manage', $conn) || access_can('staff_cards.approve', $conn) || access_can('staff_cards.settings', $conn)],
    'access'    => ['Roles & Permissions', access_can('access.manage', $conn)],
];
$only = isset($src[$_GET['m'] ?? '']) ? $_GET['m'] : '';
$want = function ($k) use ($src, $only) { return $src[$k][1] && ($only === '' || $only === $k); };
$L1 = function ($s) { return mb_substr((string) $s, 0, 600); };
$rows = [];
$add = function (string $m, $at, $action, $record, $details, $reason, $uid, $link = '') use (&$rows, $L1) {
    $rows[] = ['at' => (string) $at, 'm' => $m, 'action' => (string) $action, 'record' => (string) $record, 'details' => $L1($details), 'reason' => (string) ($reason ?? ''), 'uid' => (int) $uid, 'link' => $link];
};

if ($want('market')) {
    foreach (at_rows($conn, "SELECT * FROM mi_audit WHERE action <> 'decision' AND created_at BETWEEN $a AND $b ORDER BY id DESC LIMIT $L") as $r) {
        $add('market', $r['created_at'], str_replace('_', ' ', $r['action']), $r['opportunity_id'] ? 'Opportunity #' . $r['opportunity_id'] : '—', $r['details'], '', $r['user_id'], $r['opportunity_id'] ? '../market/opportunity.php?id=' . (int) $r['opportunity_id'] : '');
    }
    foreach (at_rows($conn, "SELECT d.*, o.name FROM mi_decisions d LEFT JOIN mi_opportunities o ON o.id = d.opportunity_id WHERE d.created_at BETWEEN $a AND $b ORDER BY d.id DESC LIMIT $L") as $r) {
        $add('market', $r['created_at'], 'decision: ' . $r['action'], 'Opportunity #' . $r['opportunity_id'] . ' ' . $r['name'], trim(($r['recommendation'] ? 'Recommendation: ' . $r['recommendation'] . '. ' : '') . ($r['next_action'] ? 'Next: ' . $r['next_action'] . '. ' : '') . ($r['budget'] !== null ? 'Budget RWF ' . number_format((float) $r['budget']) . '.' : '')), trim($r['reason'] . ($r['comments'] ? ' — ' . $r['comments'] : ''), ' —'), $r['user_id'], '../market/opportunity.php?id=' . (int) $r['opportunity_id']);
    }
}
if ($want('finance')) {
    $lk = ['transfer' => '../fin_accounts/transfers.php', 'adjustment' => '../fin_accounts/adjustments.php', 'statement' => '../fin_accounts/reconcile.php', 'account' => '../fin_accounts/accounts.php'];
    foreach (at_rows($conn, "SELECT * FROM fa_audit WHERE created_at BETWEEN $a AND $b ORDER BY id DESC LIMIT $L") as $r) {
        $add('finance', $r['created_at'], $r['entity'] . ' ' . str_replace('_', ' ', $r['action']), ucfirst(str_replace('_', ' ', $r['entity'])) . ($r['entity_id'] ? ' #' . $r['entity_id'] : ''), $r['details'], preg_match('/(?:reason|because)[:\s]+(.+)$/i', (string) $r['details'], $m) ? $m[1] : '', $r['user_id'], $lk[$r['entity']] ?? '');
    }
}
if ($want('tx')) {
    try { require_once __DIR__ . '/../../includes/transaction_audit_helpers.php'; tx_audit_ensure($conn); } catch (Throwable $e) {}
    foreach (at_rows($conn, "SELECT * FROM transaction_audit WHERE created_at BETWEEN $a AND $b ORDER BY id DESC LIMIT $L") as $r) {
        $add('tx', $r['created_at'], strtolower($r['action']), $r['reference'] . ' · ' . $r['transaction_type'] . ' · RWF ' . number_format((float) $r['amount']), $r['details'], $r['reason'], $r['user_id'], '../transactions/audit.php');
    }
}
if ($want('cf')) {
    foreach (at_rows($conn, "SELECT * FROM cf_log WHERE created_at BETWEEN $a AND $b ORDER BY id DESC LIMIT $L") as $r) {
        $add('cf', $r['created_at'], str_replace('_', ' ', $r['action']), 'Case CF-' . str_pad((string) $r['case_id'], 5, '0', STR_PAD_LEFT), $r['details'], $r['action'] === 'escalated' && preg_match('/ — (.+?) \(/', (string) $r['details'], $m) ? $m[1] : '', $r['user_id'], '../customer_first/case.php?id=' . (int) $r['case_id']);
    }
}
if ($want('price')) {
    foreach (at_rows($conn, "SELECT id, total_amount, discount_reason, price_decision, price_decided_by, price_decided_at, price_decision_reason, COALESCE(discount_requested_by, recorded_by) requested_by, created_at FROM sales WHERE discount_reason IS NOT NULL AND ((created_at BETWEEN $a AND $b) OR (price_decided_at BETWEEN $a AND $b)) ORDER BY id DESC LIMIT $L") as $r) {
        if ($r['created_at'] >= "$from 00:00:00" && $r['created_at'] <= "$to 23:59:59") { $add('price', $r['created_at'], 'requested below set price', 'Sale #' . $r['id'] . ' · RWF ' . number_format((float) $r['total_amount']), 'Sold below the set price: sent for approval.', $r['discount_reason'], $r['requested_by'], '../sales/approve_discount.php?id=' . (int) $r['id']); }
        if ($r['price_decided_at'] && $r['price_decided_at'] >= "$from 00:00:00" && $r['price_decided_at'] <= "$to 23:59:59") { $add('price', $r['price_decided_at'], strtolower((string) $r['price_decision']), 'Sale #' . $r['id'] . ' · RWF ' . number_format((float) $r['total_amount']), 'Below-set-price request ' . strtolower((string) $r['price_decision']) . '.', $r['price_decision_reason'], $r['price_decided_by'], '../sales/approve_discount.php?id=' . (int) $r['id']); }
    }
}
if ($want('cards')) {
    foreach (at_rows($conn, "SELECT * FROM sdc_audit WHERE created_at BETWEEN $a AND $b ORDER BY id DESC LIMIT $L") as $r) {
        $add('cards', $r['created_at'], $r['action'], $r['card_id'] ? 'Card #' . $r['card_id'] : 'Settings', $r['detail'], $r['reason'], $r['user_id'], $r['card_id'] ? '../staff_cards/edit.php?id=' . (int) $r['card_id'] : '../staff_cards/settings.php');
    }
}
if ($want('access')) {
    foreach (at_rows($conn, "SELECT * FROM acl_audit WHERE created_at BETWEEN $a AND $b ORDER BY id DESC LIMIT $L") as $r) {
        $add('access', $r['created_at'], str_replace('_', ' ', $r['action']), $r['target_user_id'] ? 'Employee #' . $r['target_user_id'] : ($r['role_id'] ? 'Role #' . $r['role_id'] : '—'), $r['details'], '', $r['actor_id']);
    }
}

$names = array_column(at_rows($conn, 'SELECT id, names FROM users'), 'names', 'id');
$who = (int) ($_GET['u'] ?? 0);
$q = mb_strtolower(trim((string) ($_GET['q'] ?? '')));
$rows = array_values(array_filter($rows, function ($r) use ($who, $q, $names) {
    if ($who && $r['uid'] !== $who) { return false; }
    if ($q !== '' && mb_strpos(mb_strtolower($r['action'] . ' ' . $r['record'] . ' ' . $r['details'] . ' ' . $r['reason'] . ' ' . ($names[$r['uid']] ?? '')), $q) === false) { return false; }
    return true;
}));
usort($rows, function ($x, $y) { return strcmp($y['at'], $x['at']); });

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit_trail_' . $from . '_' . $to . '.csv"');
    $o = fopen('php://output', 'w');
    fputcsv($o, ['Date / time', 'Module', 'Action', 'Record', 'Details', 'Reason', 'Responsible user']);
    foreach ($rows as $r) { fputcsv($o, [$r['at'], $src[$r['m']][0], $r['action'], $r['record'], $r['details'], $r['reason'], $r['uid'] ? ($names[$r['uid']] ?? '#' . $r['uid']) : 'System']); }
    exit;
}
$per = 100;
$page = max(1, (int) ($_GET['p'] ?? 1));
$total = count($rows);
$show = array_slice($rows, ($page - 1) * $per, $per);
$qs = function (array $x) { return http_build_query(array_filter(array_merge(['from' => $_GET['from'] ?? '', 'to' => $_GET['to'] ?? '', 'm' => $_GET['m'] ?? '', 'u' => $_GET['u'] ?? '', 'q' => $_GET['q'] ?? ''], $x), 'strlen')); };

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<style>.at td,.at th{font-size:12.5px;vertical-align:top}.at-m{font-size:11px;font-weight:600;padding:1px 8px;border-radius:20px;background:#EEF1FD;color:#1E2FE0;white-space:nowrap}</style>
<div class="mb-2"><h3 class="mb-0">Audit Trail</h3><div class="small text-muted">Who did what, when and why: RM Market Insights, Financial Accounts, Transactions, RM Customer First, price approvals, Staff Dashboard Cards and Roles &amp; Permissions.</div></div>
<form class="d-flex flex-wrap gap-2 align-items-end mb-2">
    <div><label class="form-label small mb-0">From</label><input type="date" name="from" value="<?= at_e($from); ?>" class="form-control form-control-sm"></div>
    <div><label class="form-label small mb-0">To</label><input type="date" name="to" value="<?= at_e($to); ?>" class="form-control form-control-sm"></div>
    <div><label class="form-label small mb-0">Module</label><select name="m" class="form-select form-select-sm"><option value="">All I may see</option><?php foreach ($src as $k => [$n, $ok]) { if ($ok) { ?><option value="<?= $k; ?>" <?= $only === $k ? 'selected' : ''; ?>><?= at_e($n); ?></option><?php } } ?></select></div>
    <div><label class="form-label small mb-0">User</label><select name="u" class="form-select form-select-sm"><option value="">Anyone</option><?php foreach ($names as $id => $n) { ?><option value="<?= (int) $id; ?>" <?= $who === (int) $id ? 'selected' : ''; ?>><?= at_e($n); ?></option><?php } ?></select></div>
    <div><label class="form-label small mb-0">Search</label><input name="q" value="<?= at_e($_GET['q'] ?? ''); ?>" class="form-control form-control-sm" placeholder="transfer, escalated…"></div>
    <button class="btn btn-outline-primary btn-sm">Show</button>
    <a class="btn btn-outline-secondary btn-sm" href="?<?= at_e($qs(['export' => 'csv'])); ?>"><i class="bi bi-download"></i> Excel (CSV)</a>
</form>
<div class="small text-muted mb-1"><?= number_format($total); ?> record<?= $total === 1 ? '' : 's'; ?><?= !array_filter(array_column($src, 1)) ? '' : ''; ?></div>
<div class="card"><div class="table-responsive"><table class="table table-sm at mb-0">
<thead><tr><th>Date / time</th><th>Module</th><th>Action</th><th>Record</th><th>Details</th><th>Reason</th><th>User</th></tr></thead><tbody>
<?php foreach ($show as $r) { ?>
<tr><td class="text-nowrap"><?= at_e(date('d M Y H:i', strtotime($r['at']))); ?></td><td><span class="at-m"><?= at_e($src[$r['m']][0]); ?></span></td><td><?= at_e($r['action']); ?></td>
    <td class="text-nowrap"><?= $r['link'] ? '<a href="' . at_e($r['link']) . '">' . at_e($r['record']) . '</a>' : at_e($r['record']); ?></td><td><?= at_e($r['details']); ?></td><td><?= at_e($r['reason']); ?></td>
    <td class="text-nowrap"><?= at_e($r['uid'] ? ($names[$r['uid']] ?? '#' . $r['uid']) : 'System'); ?></td></tr>
<?php } ?>
<?php if (!$show) { ?><tr><td colspan="7" class="text-center text-muted py-5">Nothing recorded in this period.</td></tr><?php } ?>
</tbody></table></div></div>
<?php if ($total > $per) { ?><div class="d-flex gap-2 mt-2 small"><?php if ($page > 1) { ?><a href="?<?= at_e($qs(['p' => $page - 1])); ?>">← Newer</a><?php } ?><span class="text-muted">Page <?= $page; ?> of <?= (int) ceil($total / $per); ?></span><?php if ($page * $per < $total) { ?><a href="?<?= at_e($qs(['p' => $page + 1])); ?>">Older →</a><?php } ?></div><?php } ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>