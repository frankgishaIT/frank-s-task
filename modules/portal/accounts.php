<?php
// NEW FILE: modules/portal/accounts.php — which customers can use the portal (allow, block, reset).
require __DIR__ . '/portal_common.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $cid = (int) ($_POST['customer_id'] ?? 0); $action = (string) ($_POST['action'] ?? '');
    $name = mysqli_fetch_row(mysqli_query($conn, 'SELECT name FROM customers WHERE id = ' . $cid))[0] ?? null;
    if ($name === null) { header('Location: accounts.php?error=' . urlencode('Customer not found.')); exit; }
    if ($action === 'block' || $action === 'allow') {
        $st = $action === 'block' ? 'blocked' : 'active';
        mysqli_query($conn, "INSERT INTO portal_accounts (customer_id, status) VALUES ($cid, '$st') ON DUPLICATE KEY UPDATE status = '$st'");
        $msg = $name . ($action === 'block' ? ' can no longer use the portal.' : ' can use the portal.');
    } elseif ($action === 'reset') {
        mysqli_query($conn, "UPDATE portal_accounts SET password_hash = NULL WHERE customer_id = $cid");
        $msg = $name . ' must activate the account again (with an SMS code).';
    } else { $msg = ''; }
    portal_log($conn, $cid, 'staff_' . $action, ($_SESSION['user_name'] ?? 'staff'));
    header('Location: accounts.php?q=' . urlencode((string) ($_POST['q'] ?? '')) . '&success=' . urlencode($msg)); exit;
}
$q = trim((string) ($_GET['q'] ?? ''));
$like = '%' . $q . '%';
$s = mysqli_prepare($conn, "SELECT c.id, c.name, c.phone, a.status, a.password_hash IS NOT NULL AS activated, a.last_login_at, a.phone_verified_at
    FROM customers c LEFT JOIN portal_accounts a ON a.customer_id = c.id
    WHERE (? = '' OR c.name LIKE ? OR c.phone LIKE ? OR CONCAT('RMC', LPAD(c.id, 6, '0')) LIKE ?) ORDER BY a.last_login_at IS NULL, a.last_login_at DESC, c.name LIMIT 200");
mysqli_stmt_bind_param($s, 'ssss', $q, $like, $like, $like);
mysqli_stmt_execute($s);
$rows = mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC);
include '../../includes/header.php'; include '../../includes/sidebar.php';
portal_staff_tabs('accounts.php'); portal_staff_alerts();
?>
<form class="d-flex gap-2 mb-3" style="max-width:520px;"><input name="q" class="form-control form-control-sm" placeholder="Name, phone or Customer ID" value="<?= $e($q); ?>"><button class="btn btn-primary btn-sm">Search</button></form>
<p class="small text-muted">With self-activation on (Settings), any customer with a phone number can activate their account by SMS code. Block a customer here to keep them out.</p>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover bg-white mb-0 align-middle" style="font-size:13px;">
    <tr><th>Customer</th><th>Phone</th><th>Portal</th><th>Last sign-in</th><th></th></tr>
    <?php foreach ($rows as $r) { $st = $r['status'] === 'blocked' ? 'Blocked' : ((int) $r['activated'] ? 'Active' : 'Not activated'); ?>
    <tr><td><?= $e($r['name']); ?><div class="small text-muted"><?= portal_customer_code((int) $r['id']); ?></div></td><td><?= $e($r['phone']); ?></td>
        <td><span class="badge <?= ['Active' => 'bg-success', 'Blocked' => 'bg-danger', 'Not activated' => 'bg-secondary'][$st]; ?>"><?= $st; ?></span></td>
        <td class="small"><?= $r['last_login_at'] ? date('d M Y, H:i', strtotime($r['last_login_at'])) : '—'; ?></td>
        <td class="text-nowrap">
            <a href="../customers/reports.php?report=statement&period=this_year&customer_id=<?= (int) $r['id']; ?>" class="btn btn-sm btn-outline-primary">Statement</a>
            <form method="POST" class="d-inline"><input type="hidden" name="customer_id" value="<?= (int) $r['id']; ?>"><input type="hidden" name="q" value="<?= $e($q); ?>">
                <input type="hidden" name="action" value="<?= $st === 'Blocked' ? 'allow' : 'block'; ?>">
                <button class="btn btn-sm <?= $st === 'Blocked' ? 'btn-outline-success' : 'btn-outline-danger'; ?>" onclick="return confirm('<?= $st === 'Blocked' ? 'Allow' : 'Block'; ?> portal access for this customer?');"><?= $st === 'Blocked' ? 'Allow' : 'Block'; ?></button></form>
            <?php if ((int) $r['activated']) { ?><form method="POST" class="d-inline"><input type="hidden" name="customer_id" value="<?= (int) $r['id']; ?>"><input type="hidden" name="q" value="<?= $e($q); ?>"><input type="hidden" name="action" value="reset">
                <button class="btn btn-sm btn-outline-secondary" onclick="return confirm('Reset this account? The customer must activate it again by SMS code.');">Reset</button></form><?php } ?>
        </td></tr>
    <?php } ?>
</table></div></div></div>
<?php include '../../includes/footer.php'; ?>