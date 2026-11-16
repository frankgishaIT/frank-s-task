<?php
/**
 * NEW FILE: modules/branches/switch.php — the top-bar branch selector.
 * The server checks that the user may use the chosen branch. Choosing a branch never gives more permissions:
 * it only decides which branch's information is shown and which branch new records belong to.
 */
require __DIR__ . '/common.php';
$back = (string) ($_POST['back'] ?? '');
if (!preg_match('#^/[^\s]*$#', $back) || strpos($back, '//') === 0 || strpos($back, '/branches/switch.php') !== false) { $back = rtrim((string) BASE_URL, '/') . '/modules/dashboard/index.php'; }
if ($_SERVER['REQUEST_METHOD'] === 'POST' && br_enabled($conn)) {
    $id = (int) ($_POST['branch'] ?? -1);
    $allowed = br_allowed_ids($conn);
    if ($id === 0 && $allowed === null) { $_SESSION['branch_view'] = 0; }
    elseif ($id > 0 && br_get($conn, $id) && br_can_access($conn, $id)) { $_SESSION['branch_view'] = $id; }
    else { br_audit($conn, $id > 0 ? $id : null, 'access_denied', 'Tried to switch to a branch without access.'); }
}
header('Location: ' . $back);
exit;