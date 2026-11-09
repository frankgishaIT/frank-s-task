<?php
// modules/marketing/mkt_common.php — shared by the Marketing & Growth pages.
require __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/mkt_helpers.php';
require_once __DIR__ . '/../../includes/access_control.php';
mkt_ensure($conn);
mkt_ensure_leads($conn);
if (!access_can('marketing.view', $conn)) { http_response_code(403); include __DIR__ . '/../../includes/access_denied.php'; exit; }
$me = (int) current_user_id();
$isAdmin = current_user_role() === 'Admin';
$e = function ($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); };
$rwf = function ($v) { return 'RWF ' . number_format((float) $v, 0); };
function mkt_tabs(string $active): void {
    // The submodules that exist so far (the others are added stage by stage).
    $t = ['index.php' => 'Overview', 'plans.php' => 'Plans & Strategy', 'campaigns.php' => 'Campaigns', 'leads.php' => 'Leads & Customers'];
    if (access_can('marketing.manage')) { $t['settings.php'] = 'Settings'; }
    if (access_can('marketing.reports') || access_can('marketing.approve') || access_can('marketing.manage')) { $t['audit.php'] = 'Audit trail'; }
    echo '<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2"><h2 class="mb-0">Marketing &amp; Growth</h2></div><ul class="nav nav-tabs mb-3 flex-nowrap overflow-auto">';
    foreach ($t as $f => $l) { echo '<li class="nav-item"><a class="nav-link text-nowrap' . ($active === $f ? ' active' : '') . '" href="' . $f . '">' . $l . '</a></li>'; }
    echo '</ul>';
    foreach (['success' => 'success', 'error' => 'danger'] as $k => $c) { if (!empty($_GET[$k])) { echo '<div class="alert alert-' . $c . '">' . htmlspecialchars($_GET[$k], ENT_QUOTES, 'UTF-8') . '</div>'; } }
}
function mkt_badge(string $s): string {
    $c = in_array($s, ['Active', 'Approved', 'Won', 'Completed', 'Evaluated', 'Paying'], true) ? 'bg-success' : (in_array($s, ['Lost', 'Rejected'], true) ? 'bg-danger'
        : (in_array($s, ['Draft', 'Archived', 'Paused'], true) ? 'bg-secondary' : 'bg-primary'));
    return '<span class="badge ' . $c . '">' . htmlspecialchars($s, ENT_QUOTES, 'UTF-8') . '</span>';
}
/** Lists used by the forms: real records (users, departments, offerings). */
function mkt_lists(mysqli $conn): array {
    return ['users' => mysqli_fetch_all(mysqli_query($conn, 'SELECT id, names, role, department_id FROM users WHERE is_active = 1 ORDER BY names'), MYSQLI_ASSOC),
            'departments' => mysqli_fetch_all(mysqli_query($conn, 'SELECT id, name FROM departments ORDER BY name'), MYSQLI_ASSOC),
            'offerings' => mysqli_fetch_all(mysqli_query($conn, "SELECT id, product_name, item_type FROM products WHERE is_active = 1 ORDER BY item_type DESC, product_name"), MYSQLI_ASSOC),
            'channels' => mkt_list_setting($conn, 'channels'), 'customer_types' => mkt_list_setting($conn, 'customer_types')];
}
/** Names of the offerings in a stored id list. */
function mkt_offering_names(array $offerings, ?string $ids): string {
    $want = array_filter(array_map('intval', explode(',', (string) $ids))); $names = [];
    foreach ($offerings as $o) { if (in_array((int) $o['id'], $want, true)) { $names[] = $o['product_name']; } }
    return implode(', ', $names);
}
/** Change of workflow status, with permission, self-approval rule, audit and notifications. */
function mkt_apply_transition(mysqli $conn, string $kind, array $row, string $action, string $note, int $me, bool $isAdmin): string {
    $flow = mkt_transitions($kind);
    if (!isset($flow[$row['status']][$action])) { throw new RuntimeException('This action is not possible while the ' . $kind . ' is "' . $row['status'] . '".'); }
    [$to, $perm] = $flow[$row['status']][$action];
    if (!access_can('marketing.' . $perm, $conn)) { throw new RuntimeException('You are not authorised to ' . $action . ' ' . $kind . 's.'); }
    if ($perm === 'create' && !mkt_may_run($row, $me)) { throw new RuntimeException('Only the ' . $kind . "'s creator, its manager, or a Manager / Administrator can " . $action . ' it.'); }
    if (in_array($action, ['approve', 'activate'], true) && (int) $row['created_by'] === $me) {
        if (!$isAdmin) { throw new RuntimeException('You created this ' . $kind . ': it must be approved by someone else (or an Administrator).'); }
        if (mb_strlen(trim($note)) < 5) { throw new RuntimeException('You created this ' . $kind . ' yourself: write a short approval note (kept in the audit trail).'); }
    }
    if (in_array($action, ['reject', 'pause'], true) && mb_strlen(trim($note)) < 5) { throw new RuntimeException('Write the reason.'); }
    if ($action === 'evaluate' && mb_strlen(trim($note)) < 10) { throw new RuntimeException('Write the evaluation (what worked, what did not, lessons).'); }
    $table = $kind === 'plan' ? 'mkt_plans' : 'mkt_campaigns'; $id = (int) $row['id'];
    $extra = $action === 'approve' ? ', approved_by = ' . $me . ', approved_at = NOW()' : '';
    $s = mysqli_prepare($conn, "UPDATE $table SET status = ?, updated_at = NOW()" . ($action === 'evaluate' ? ', evaluation = ?' : '') . "$extra WHERE id = ? AND status = ?");
    if ($action === 'evaluate') { mysqli_stmt_bind_param($s, 'ssis', $to, $note, $id, $row['status']); } else { mysqli_stmt_bind_param($s, 'sis', $to, $id, $row['status']); }
    mysqli_stmt_execute($s);
    if (mysqli_stmt_affected_rows($s) !== 1) { throw new RuntimeException('It was changed by someone else meanwhile. Reload the page.'); }
    $ref = mkt_code($kind, $row); $name = $row['title'] ?? $row['name'];
    mkt_audit($conn, ucfirst($kind) . ' ' . $action . ($action === 'approve' && (int) $row['created_by'] === $me ? ' (self-approved by Administrator)' : ''), $ref, $row['status'], $to, $note ?: null, $me);
    if ($action === 'submit') { mkt_notify($conn, mkt_admin_ids($conn), ucfirst($kind) . ' to approve: ' . $name, $ref . ' was submitted for approval.'); }
    if (in_array($action, ['approve', 'reject'], true)) { mkt_notify($conn, [(int) $row['created_by'], (int) $row['manager_id']], ucfirst($kind) . ' ' . ($action === 'approve' ? 'approved' : 'returned to draft') . ': ' . $name, $note ?: $ref); }
    if ($action === 'activate' || $action === 'complete') {
        $mt = $kind === 'plan' ? 'mkt_plan_members' : 'mkt_campaign_members'; $col = $kind === 'plan' ? 'plan_id' : 'campaign_id';
        $members = array_map('intval', array_column(mysqli_fetch_all(mysqli_query($conn, "SELECT user_id FROM $mt WHERE $col = $id"), MYSQLI_ASSOC), 'user_id'));
        mkt_notify($conn, array_merge($members, [(int) $row['manager_id']]), ucfirst($kind) . ($action === 'activate' ? ' started: ' : ' completed: ') . $name, $ref . ($action === 'activate' ? ' is now active. You are part of the team.' : ' is completed.'));
    }
    return $to;
}