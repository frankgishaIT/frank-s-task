<?php
// NEW FILE: modules/portal/records.php — optional record types (scores, grades ...) and customers' records.
require __DIR__ . '/portal_common.php';
$canManage = access_can('portal.manage', $conn);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    try {
        if (in_array($action, ['toggle', 'add_type'], true) && !$canManage) { throw new RuntimeException('Only portal managers can switch record types on or off.'); }
        if ($action === 'toggle') { mysqli_query($conn, 'UPDATE portal_record_types SET is_enabled = 1 - is_enabled WHERE id = ' . (int) $_POST['type_id']); $msg = 'Saved.'; }
        elseif ($action === 'add_type') {
            $n = mb_substr(trim((string) $_POST['name']), 0, 80); if ($n === '') { throw new RuntimeException('Enter the record type name.'); }
            $s = mysqli_prepare($conn, 'INSERT IGNORE INTO portal_record_types (name, is_enabled) VALUES (?, 1)'); mysqli_stmt_bind_param($s, 's', $n); mysqli_stmt_execute($s); $msg = 'Record type added.';
        } elseif ($action === 'add') {
            $cid = (int) $_POST['customer_id']; $tid = (int) $_POST['type_id']; $title = mb_substr(trim((string) $_POST['title']), 0, 200);
            $val = mb_substr(trim((string) $_POST['value']), 0, 100) ?: null; $date = (string) ($_POST['record_date'] ?? ''); $notes = mb_substr(trim((string) ($_POST['notes'] ?? '')), 0, 500) ?: null;
            if (!mysqli_fetch_row(mysqli_query($conn, "SELECT 1 FROM customers WHERE id = $cid")) || !mysqli_fetch_row(mysqli_query($conn, "SELECT 1 FROM portal_record_types WHERE id = $tid AND is_enabled = 1")) || $title === '') {
                throw new RuntimeException('Choose the customer, an active record type, and give a title.'); }
            $dV = DateTime::createFromFormat('Y-m-d', $date) ? $date : null; $uid = (int) current_user_id();
            $s = mysqli_prepare($conn, 'INSERT INTO portal_records (customer_id, type_id, title, value, record_date, notes, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
            mysqli_stmt_bind_param($s, 'iissssi', $cid, $tid, $title, $val, $dV, $notes, $uid); mysqli_stmt_execute($s);
            portal_log($conn, $cid, 'record_added', $title . ' by ' . ($_SESSION['user_name'] ?? 'staff')); $msg = 'Record added.';
        } elseif ($action === 'delete') {
            $rid = (int) $_POST['id']; mysqli_query($conn, "DELETE FROM portal_records WHERE id = $rid"); $msg = 'Record removed.';
        } else { $msg = ''; }
        header('Location: records.php?success=' . urlencode($msg)); exit;
    } catch (RuntimeException $ex) { $error = $ex->getMessage(); }
}
$types = mysqli_fetch_all(mysqli_query($conn, 'SELECT t.*, (SELECT COUNT(*) FROM portal_records r WHERE r.type_id = t.id) AS n FROM portal_record_types t ORDER BY t.name'), MYSQLI_ASSOC);
$enabled = array_values(array_filter($types, function ($t) { return (int) $t['is_enabled'] === 1; }));
$customers = mysqli_fetch_all(mysqli_query($conn, 'SELECT id, name FROM customers ORDER BY name'), MYSQLI_ASSOC);
$recent = mysqli_fetch_all(mysqli_query($conn, 'SELECT r.*, c.name, t.name AS type_name FROM portal_records r JOIN customers c ON c.id = r.customer_id JOIN portal_record_types t ON t.id = r.type_id ORDER BY r.id DESC LIMIT 100'), MYSQLI_ASSOC);
include '../../includes/header.php'; include '../../includes/sidebar.php';
portal_staff_tabs('records.php'); portal_staff_alerts();
?>
<?php if (isset($error)) { ?><div class="alert alert-danger"><?= $e($error); ?></div><?php } ?>
<p class="small text-muted">Optional. Customers see "My records" only when at least one record type is switched on. Most businesses do not need it.</p>
<div class="row g-3">
<div class="col-lg-4"><div class="card border-0 shadow-sm"><div class="card-body">
    <h6>Record types</h6>
    <?php foreach ($types as $t) { ?>
    <form method="POST" class="d-flex justify-content-between align-items-center border-bottom py-1 small"><input type="hidden" name="action" value="toggle"><input type="hidden" name="type_id" value="<?= (int) $t['id']; ?>">
        <span><?= $e($t['name']); ?> <span class="text-muted">(<?= (int) $t['n']; ?>)</span></span>
        <button class="btn btn-sm <?= (int) $t['is_enabled'] ? 'btn-success' : 'btn-outline-secondary'; ?>" <?= $canManage ? '' : 'disabled'; ?>><?= (int) $t['is_enabled'] ? 'On' : 'Off'; ?></button></form>
    <?php } ?>
    <?php if ($canManage) { ?><form method="POST" class="d-flex gap-2 mt-2"><input type="hidden" name="action" value="add_type"><input name="name" class="form-control form-control-sm" maxlength="80" placeholder="New type"><button class="btn btn-primary btn-sm">Add</button></form><?php } ?>
</div></div></div>
<div class="col-lg-8">
    <?php if ($enabled) { ?>
    <form method="POST" class="card border-0 shadow-sm mb-3"><div class="card-body row g-2 align-items-end"><input type="hidden" name="action" value="add">
        <div class="col-md-5"><label class="form-label small text-muted mb-1">Customer</label><select name="customer_id" class="form-select form-select-sm" data-searchable required><option value="">Choose</option><?php foreach ($customers as $c) { ?><option value="<?= (int) $c['id']; ?>"><?= $e($c['name']); ?></option><?php } ?></select></div>
        <div class="col-md-3"><label class="form-label small text-muted mb-1">Type</label><select name="type_id" class="form-select form-select-sm"><?php foreach ($enabled as $t) { ?><option value="<?= (int) $t['id']; ?>"><?= $e($t['name']); ?></option><?php } ?></select></div>
        <div class="col-md-4"><label class="form-label small text-muted mb-1">Date</label><input type="date" name="record_date" class="form-control form-control-sm"></div>
        <div class="col-md-5"><input name="title" class="form-control form-control-sm" maxlength="200" required placeholder="Title (e.g. Term 1 exam)"></div>
        <div class="col-md-3"><input name="value" class="form-control form-control-sm" maxlength="100" placeholder="Result (e.g. 78%)"></div>
        <div class="col-md-4"><button class="btn btn-primary btn-sm w-100">Add record</button></div>
        <div class="col-12"><input name="notes" class="form-control form-control-sm" maxlength="500" placeholder="Notes (optional)"></div>
    </div></form>
    <?php } ?>
    <div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm bg-white mb-0 align-middle" style="font-size:13px;">
        <tr><th class="ps-3">Customer</th><th>Type</th><th>Title</th><th>Result</th><th>Date</th><th></th></tr>
        <?php if (!$recent) { ?><tr><td colspan="6" class="text-center text-muted py-3">No records.</td></tr><?php } ?>
        <?php foreach ($recent as $r) { ?><tr><td class="ps-3"><?= $e($r['name']); ?></td><td><?= $e($r['type_name']); ?></td><td><?= $e($r['title']); ?></td><td><?= $e($r['value']); ?></td><td><?= $r['record_date'] ? date('d M Y', strtotime($r['record_date'])) : '—'; ?></td>
            <td><form method="POST" onsubmit="return confirm('Remove this record?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int) $r['id']; ?>"><button class="btn btn-sm btn-outline-danger">Remove</button></form></td></tr><?php } ?>
    </table></div></div></div>
</div></div>
<?php include '../../includes/footer.php'; ?>