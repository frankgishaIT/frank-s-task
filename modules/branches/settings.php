<?php
/** NEW FILE: modules/branches/settings.php — what a TEMPORARILY SUSPENDED branch may still do (Closed = nothing new). Head Office. */
require __DIR__ . '/common.php';
br_require_setup($conn);
if (!br_head_office($conn)) { br_deny(); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!br_csrf_ok()) { br_go('settings.php', 'error', 'The page had expired.'); }
    $ch = [];
    foreach (BR_SUSPEND_RULES as $k => $l) {
        $v = !empty($_POST['block'][$k]) ? '1' : '0';
        if (br_setting($conn, 'suspend_block_' . $k, 0, '1') !== $v) { $ch[] = $l . ($v === '1' ? ' blocked' : ' allowed'); }
        br_setting_set($conn, 'suspend_block_' . $k, $v);
    }
    if ($ch) { br_audit($conn, null, 'settings', 'Suspension rules: ' . implode('; ', $ch) . '.'); }
    br_go('settings.php', 'success', 'Settings saved.');
}
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
br_tabs($conn, 'settings.php');
?>
<form method="POST" class="card border-0 shadow-sm" style="max-width:720px;"><div class="card-body"><?= br_csrf_field(); ?>
    <h6>While a branch is TEMPORARILY SUSPENDED, block:</h6>
    <?php foreach (BR_SUSPEND_RULES as $k => $l) { ?><div class="form-check"><input class="form-check-input" type="checkbox" name="block[<?= $k; ?>]" value="1" id="s<?= $k; ?>" <?= br_setting($conn, 'suspend_block_' . $k, 0, '1') === '1' ? 'checked' : ''; ?>><label class="form-check-label" for="s<?= $k; ?>"><?= br_e($l); ?></label></div><?php } ?>
    <button class="btn btn-primary btn-sm mt-3">Save</button>
    <div class="small text-muted mt-2">A CLOSED branch can never start new operations. In both cases all historical records, transactions and reports stay available to authorized users. Setup done: <?= br_e(br_setting($conn, 'setup_at')); ?> (<a href="setup.php">setup page</a>).</div>
</div></form>
<?php include __DIR__ . '/../../includes/footer.php'; ?>