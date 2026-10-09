<?php
/**
 * NEW FILE: modules/assets/sync_capital_fund.php
 * One-time step (spec sections 9 and 13): adds the assets that existed BEFORE the
 * Capital Fund integration to the RM Capital Fund at their current value.
 * Safe to open or run again: it only posts differences, so assets already in the fund
 * are skipped.
 */
require '../../config/db.php';
require '../../includes/asset_helpers.php';
require_role(['Admin']);

// Bring every Active asset's value up to today first.
asset_run_depreciation_catchup_all($conn);

$assets = mysqli_fetch_all(mysqli_query($conn,
    "SELECT id, asset_code, asset_name, status, current_value FROM assets ORDER BY id"), MYSQLI_ASSOC);

$rows = [];
$totalToAdd = 0.0;
foreach ($assets as $a) {
    $inFund = asset_fund_value($conn, (int) $a['id']);
    $target = in_array($a['status'], ASSET_COUNTED_STATUSES, true) ? round((float) $a['current_value'], 2) : 0.0;
    $diff = round($target - $inFund, 2);
    $rows[] = $a + ['in_fund' => $inFund, 'target' => $target, 'diff' => $diff];
    $totalToAdd += $diff;
}

if (isset($_POST['run'])) {
    $userId = current_user_id();
    mysqli_begin_transaction($conn);
    try {
        $count = 0;
        foreach ($assets as $a) {
            $posted = asset_sync_capital_fund($conn, (int) $a['id'], 'ASSET_IN', $userId ? (int) $userId : null,
                'Opening balance: asset ' . $a['asset_code'] . ' (' . $a['asset_name'] . ') added to RM Capital Fund');
            if ($posted != 0.0) { $count++; }
        }
        mysqli_commit($conn);
        header('Location: index.php?success=' . urlencode($count . ' asset(s) synchronised with the RM Capital Fund.'));
        exit;
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        error_log('asset capital fund sync failed: ' . $e->getMessage());
        $error = 'Unable to synchronise. Nothing was changed. Please try again.';
    }
}

include '../../includes/header.php'; include '../../includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Sync Assets with RM Capital Fund</h2>
    <a href="index.php" class="rm-btn rm-btn-light">Back to Assets</a>
</div>

<?php if (isset($error)) { ?>
<div class="alert alert-danger mb-3" style="border-radius:10px;"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
<?php } ?>

<div class="card border-0 shadow-sm">
    <div class="card-body p-4">
        <p class="text-muted small">
            Assets that are Active or Under Maintenance must be counted in the RM Capital Fund at their current value.
            This page shows the difference and posts it once. Rows with a difference of 0 are already correct.
        </p>

        <div class="table-responsive mb-4">
            <table class="table table-sm align-middle">
                <thead>
                    <tr><th>Asset</th><th>Status</th><th class="text-end">Current Value</th><th class="text-end">In Capital Fund</th><th class="text-end">Difference</th></tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r) { ?>
                    <tr>
                        <td><?= htmlspecialchars($r['asset_code'] . ' — ' . $r['asset_name'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td><?= htmlspecialchars($r['status'], ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="text-end"><?= number_format($r['target'], 2); ?></td>
                        <td class="text-end"><?= number_format($r['in_fund'], 2); ?></td>
                        <td class="text-end fw-semibold"><?= number_format($r['diff'], 2); ?></td>
                    </tr>
                <?php } ?>
                </tbody>
                <tfoot>
                    <tr><th colspan="4" class="text-end">Total to post</th><th class="text-end">RWF <?= number_format($totalToAdd, 2); ?></th></tr>
                </tfoot>
            </table>
        </div>

        <?php if (abs($totalToAdd) >= 0.01 || array_filter($rows, function ($r) { return abs($r['diff']) >= 0.01; })) { ?>
        <form method="POST" onsubmit="return confirm('Post these differences to the RM Capital Fund?');">
            <button type="submit" name="run" value="1" class="rm-btn rm-btn-primary">
                <i class="bi bi-arrow-repeat me-2"></i>Sync Now
            </button>
        </form>
        <?php } else { ?>
        <div class="alert alert-success mb-0" style="border-radius:10px;">All assets already match the RM Capital Fund.</div>
        <?php } ?>
    </div>
</div>

<?php include '../../includes/footer.php'; ?>