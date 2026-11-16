<?php
/** NEW FILE: modules/branches/edit.php — update branch details (Head Office only; code and company identity stay). */
require __DIR__ . '/common.php';
require __DIR__ . '/form.php';
br_require_setup($conn);
if (!br_head_office($conn)) { br_deny(); }
$id = (int) ($_GET['id'] ?? 0);
$old = br_get($conn, $id);
if (!$old) { br_go('branches.php', 'error', 'Branch not found.'); }
$users = br_users($conn);
$error = null; $v = $old;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v = $_POST + $old;
    if (!br_csrf_ok()) { $error = 'The page had expired. Please try again.'; }
    else { [$ok, $msg] = br_form_save($conn, $_POST, $old); if ($ok) { br_go('view.php?id=' . $id, 'success', $msg); } $error = $msg; }
}
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
br_tabs($conn, 'branches.php');
echo '<h5 class="mb-3">Edit ' . br_e($old['name']) . ' <span class="text-muted">' . br_e($old['code']) . '</span></h5>';
if ($error) { echo '<div class="alert alert-danger">' . br_e($error) . '</div>'; }
br_form_render($conn, $v, $old, $users);
include __DIR__ . '/../../includes/footer.php';