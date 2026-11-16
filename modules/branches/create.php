<?php
/** NEW FILE: modules/branches/create.php — register a new branch (Head Office only). */
require __DIR__ . '/common.php';
require __DIR__ . '/form.php';
br_require_setup($conn);
if (!br_head_office($conn)) { br_deny(); }
$users = br_users($conn);
$error = null; $v = ['branch_type' => 'Branch', 'status' => 'Active', 'opening_date' => date('Y-m-d')];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $v = $_POST;
    if (!br_csrf_ok()) { $error = 'The page had expired. Please try again.'; }
    else { [$ok, $msg, $id] = br_form_save($conn, $_POST, null); if ($ok) { br_go('view.php?id=' . $id, 'success', $msg); } $error = $msg; }
}
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
br_tabs($conn, 'branches.php');
echo '<h5 class="mb-3">Register a new branch</h5>';
if ($error) { echo '<div class="alert alert-danger">' . br_e($error) . '</div>'; }
br_form_render($conn, $v, null, $users);
include __DIR__ . '/../../includes/footer.php';