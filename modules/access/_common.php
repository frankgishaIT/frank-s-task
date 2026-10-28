<?php
// Shared by the Roles & Permissions pages (modules/access/).
require '../../config/db.php';
require_once '../../includes/access_control.php';
require_role(['Admin']);
access_ensure($conn);
if (!access_can('access.manage', $conn)) { http_response_code(403); include '../../includes/access_denied.php'; exit; }
$me = (int) current_user_id();
$e = function ($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); };
function access_tabs(string $active): void {
    $tabs = ['index.php' => 'Employees', 'roles.php' => 'Roles', 'audit.php' => 'Change history'];
    echo '<ul class="nav nav-tabs mb-3">';
    foreach ($tabs as $f => $l) { echo '<li class="nav-item"><a class="nav-link' . ($active === $f ? ' active' : '') . '" href="' . $f . '">' . $l . '</a></li>'; }
    echo '</ul>';
}
/** Number of active employees who would still hold access.manage. Prevents locking everyone out. */
function access_managers_left(mysqli $conn, ?int $changedUser = null, ?array $changedEffective = null, ?int $changedRole = null, ?array $changedRolePerms = null): int {
    $n = 0;
    foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT id FROM users WHERE is_active = 1'), MYSQLI_ASSOC) as $u) {
        $uid = (int) $u['id'];
        if ($changedUser === $uid) { $eff = $changedEffective; }
        else {
            $b = access_user_breakdown($conn, $uid);
            $eff = $b['effective'];
            if ($changedRole !== null && isset($b['roles'][$changedRole])) {
                // recompute with the role's new permissions
                $others = array_diff(array_keys($b['roles']), [$changedRole]);
                $fromOthers = $others ? array_column(mysqli_fetch_all(mysqli_query($conn, 'SELECT DISTINCT permission FROM acl_role_permissions WHERE role_id IN (' . implode(',', array_map('intval', $others)) . ')'), MYSQLI_ASSOC), 'permission') : [];
                $eff = array_values(array_diff(array_unique(array_merge($fromOthers, $changedRolePerms, $b['grants'])), $b['denies']));
            }
        }
        if (in_array('access.manage', $eff, true)) { $n++; }
    }
    return $n;
}