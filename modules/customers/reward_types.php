<?php
/**
 * NEW FILE: modules/customers/reward_types.php
 * Admin: configure the Reward Type dropdown (add types, switch on/off, mark whether they pay money).
 */
require '../../config/db.php';
require_once '../../includes/reward_helpers.php';
require_role(['Admin']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['add'])) {
        $name = mb_substr(trim($_POST['name'] ?? ''), 0, 80);
        $pays = isset($_POST['pays_money']) ? 1 : 0;
        if ($name === '') {
            $error = 'Please enter the name of the reward type.';
        } else {
            try {
                $s = mysqli_prepare($conn, 'INSERT INTO reward_types (name, pays_money, is_active, sort_order) VALUES (?, ?, 1, (SELECT COALESCE(MAX(x.sort_order), 0) + 1 FROM (SELECT sort_order FROM reward_types) x))');
                mysqli_stmt_bind_param($s, 'si', $name, $pays);
                mysqli_stmt_execute($s);
                header('Location: reward_types.php?success=' . urlencode('Reward type added.')); exit;
            } catch (mysqli_sql_exception $ex) {
                $error = (int) $ex->getCode() === 1062 ? 'A reward type with that name already exists.' : 'Unable to add the reward type.';
            }
        }
    } elseif (isset($_POST['toggle'], $_POST['id'], $_POST['field']) && in_array($_POST['field'], ['is_active', 'pays_money'], true)) {
        $field = $_POST['field'];
        $tid = (int) $_POST['id'];
        mysqli_query($conn, "UPDATE reward_types SET $field = 1 - $field WHERE id = " . $tid);
        header('Location: reward_types.php?success=' . urlencode('Reward type updated.')); exit;
    }
}

$types = reward_types($conn, false);
include '../../includes/header.php'; include '../../includes/sidebar.php';
$e = function ($x) { return htmlspecialchars((string) ($x ?? ''), ENT_QUOTES, 'UTF-8'); };
?>
<?php if (isset($_GET['success'])) { ?><div class="alert alert-success"><?= $e($_GET['success']); ?></div><?php } ?>
<?php if (isset($error)) { ?><div class="alert alert-danger"><?= $e($error); ?></div><?php } ?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div><a href="rewards.php" class="text-decoration-none small text-muted"><i class="bi bi-arrow-left"></i> Back to Rewards</a><h2 class="mt-1">Reward Types</h2></div>
</div>
<div class="card border-0 shadow-sm mb-4"><div class="card-body">
    <form method="POST" class="row g-2 align-items-end">
        <div class="col-md-5"><label class="form-label small fw-semibold text-muted">New reward type</label><input type="text" name="name" class="form-control rm-input" maxlength="80" required></div>
        <div class="col-md-4"><div class="form-check"><input class="form-check-input" type="checkbox" name="pays_money" value="1" id="pm"><label class="form-check-label" for="pm">Money is paid out (from the RM Business Operating Fund)</label></div></div>
        <div class="col-md-3"><button type="submit" name="add" value="1" class="rm-btn rm-btn-primary">Add Type</button></div>
    </form>
</div></div>
<div class="card border-0 shadow-sm"><div class="card-body p-0">
<table class="table table-bordered bg-white mb-0 align-middle">
    <tr><th>Reward Type</th><th>Pays Money</th><th>In Dropdown</th></tr>
    <?php foreach ($types as $t) { ?>
    <tr>
        <td><?= $e($t['name']); ?></td>
        <td><form method="POST" class="d-inline"><input type="hidden" name="id" value="<?= (int) $t['id']; ?>"><input type="hidden" name="field" value="pays_money">
            <button name="toggle" value="1" class="btn btn-sm <?= $t['pays_money'] ? 'btn-success' : 'btn-outline-secondary'; ?>"><?= $t['pays_money'] ? 'Yes' : 'No'; ?></button></form></td>
        <td><form method="POST" class="d-inline"><input type="hidden" name="id" value="<?= (int) $t['id']; ?>"><input type="hidden" name="field" value="is_active">
            <button name="toggle" value="1" class="btn btn-sm <?= $t['is_active'] ? 'btn-success' : 'btn-outline-secondary'; ?>"><?= $t['is_active'] ? 'Active' : 'Off'; ?></button></form></td>
    </tr>
    <?php } ?>
</table>
</div></div>
<p class="small text-muted mt-3">Click Yes/No or Active/Off to switch. Rewards already recorded keep the type they were given with.</p>
<?php include '../../includes/footer.php'; ?>