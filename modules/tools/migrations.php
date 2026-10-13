<?php
/**
 * NEW FILE: modules/tools/migrations.php
 * Admin only. Shows which database migrations THIS site's database has, and runs the missing ones,
 * using the site's own connection (so it always changes the database the site really uses).
 * Each migration is checked first and only run when it is missing, so opening this page again is safe.
 * The .sql files live in the project's database/ folder (in Git, they contain no passwords).
 */
require '../../config/db.php';
require_role(['Admin']);

const MIGRATIONS_DIR = __DIR__ . '/../../database'; // CHANGED: the project's existing database/ folder

// In the order they must run. 'check' returns true when the migration is already in the database.
$migrations = [
    'migration_transactions_soft_delete.sql' => ['Deleted transactions are kept (soft delete)', ['column', 'transactions', 'deleted_by']],
    'migration_asset_disposals.sql'          => ['Asset disposals (sold, stolen, damaged)',       ['table', 'asset_disposals']],
    'migration_sms_log.sql'                  => ['SMS log',                                       ['table', 'sms_log']],
    'migration_customer_rewards.sql'         => ['Customer rewards',                              ['table', 'reward_types']],
    'migration_points_72h.sql'               => ['72-hour Loyalty Points rule',                   ['column', 'sales', 'points_status']],
    'migration_credit_charges.sql'           => ['Overdue credit charges and ledger',             ['table', 'credit_ledger']],
];

function migration_present(mysqli $conn, array $check): bool
{
    if ($check[0] === 'table') {
        $s = mysqli_prepare($conn, 'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
        mysqli_stmt_bind_param($s, 's', $check[1]);
    } else {
        $s = mysqli_prepare($conn, 'SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
        mysqli_stmt_bind_param($s, 'ss', $check[1], $check[2]);
    }
    mysqli_stmt_execute($s);
    return (int) mysqli_fetch_row(mysqli_stmt_get_result($s))[0] > 0;
}

/** Runs one .sql file (several statements). Returns null when it worked, or the error text. */
function migration_run(mysqli $conn, string $file): ?string
{
    $path = MIGRATIONS_DIR . '/' . basename($file);
    if (!is_file($path)) {
        return 'File not found on this server: database/' . basename($file) . ' (push it with Git).';
    }
    $sql = file_get_contents($path);
    try {
        if (!mysqli_multi_query($conn, $sql)) {
            return mysqli_error($conn);
        }
        do {
            if ($r = mysqli_store_result($conn)) { mysqli_free_result($r); }
        } while (mysqli_more_results($conn) && mysqli_next_result($conn));
        if (mysqli_errno($conn)) {
            return mysqli_error($conn);
        }
    } catch (mysqli_sql_exception $e) {
        // Drain anything left so the connection stays usable.
        while (mysqli_more_results($conn)) { try { mysqli_next_result($conn); } catch (Throwable $x) { break; } }
        return $e->getMessage();
    }
    return null;
}

$results = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['run_missing'])) {
    foreach ($migrations as $file => [$label, $check]) {
        if (migration_present($conn, $check)) { continue; }
        $err = migration_run($conn, $file);
        $results[] = [$label, $err];
        if ($err !== null) { break; } // later migrations may depend on this one: stop here
    }
}

$dbName = mysqli_fetch_row(mysqli_query($conn, 'SELECT DATABASE()'))[0];
$e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$missing = 0;
foreach ($migrations as $file => [$label, $check]) { if (!migration_present($conn, $check)) { $missing++; } }

include '../../includes/header.php'; include '../../includes/sidebar.php';
?>
<h2 class="mb-1">Database Migrations</h2>
<p class="text-muted">This site is connected to the database <strong><?= $e($dbName); ?></strong>.</p>

<?php foreach ($results as [$label, $err]) { ?>
    <div class="alert <?= $err === null ? 'alert-success' : 'alert-danger'; ?>">
        <strong><?= $e($label); ?>:</strong> <?= $err === null ? 'done.' : 'failed: ' . $e($err) . ' (the migrations after it were not run).'; ?>
    </div>
<?php } ?>

<div class="card border-0 shadow-sm mb-3"><div class="card-body p-0">
<table class="table table-bordered bg-white mb-0 align-middle">
    <tr><th>Migration</th><th>File</th><th>Status</th></tr>
    <?php foreach ($migrations as $file => [$label, $check]) { $ok = migration_present($conn, $check); ?>
    <tr>
        <td><?= $e($label); ?></td>
        <td class="small text-muted"><?= $e($file); ?></td>
        <td><?= $ok ? '<span class="badge bg-success">In the database</span>' : '<span class="badge bg-warning text-dark">Missing</span>'; ?></td>
    </tr>
    <?php } ?>
</table>
</div></div>

<?php if ($missing > 0) { ?>
<form method="POST" onsubmit="return confirm('Run the <?= $missing; ?> missing migration(s) on the database <?= $e($dbName); ?>?');">
    <button type="submit" name="run_missing" value="1" class="rm-btn rm-btn-primary">Run the <?= $missing; ?> missing migration(s)</button>
</form>
<?php } else { ?>
<div class="alert alert-success">This database has every migration. Nothing to do.</div>
<?php } ?>
<?php include '../../includes/footer.php'; ?>