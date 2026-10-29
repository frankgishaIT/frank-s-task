<?php
/**
 * NEW FILE: modules/tools/status.php
 * Admin diagnostic: is this server running the latest code? Shows the Git commit the server is on,
 * whether key new files exist (and their dates), whether PHP OPcache holds old copies (with a Clear
 * button), and whether the permission check in config/db.php is active. Changes nothing else.
 */
require '../../config/db.php';
require_role(['Admin']);
$root = realpath(__DIR__ . '/../..');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_opcache') {
    $ok = function_exists('opcache_reset') && @opcache_reset();
    header('Location: status.php?msg=' . urlencode($ok ? 'PHP cache cleared: every page now runs the files as they are on the server.' : 'The PHP cache could not be cleared from here (ask the server administrator to restart PHP).'));
    exit;
}

// The Git commit of the code on this server (read from the .git folder, no command needed).
$commit = 'unknown (no .git folder: the code was not deployed with Git)';
$branch = '';
$head = @file_get_contents($root . '/.git/HEAD');
if ($head !== false) {
    $head = trim($head);
    if (strpos($head, 'ref: ') === 0) {
        $ref = substr($head, 5);
        $branch = basename($ref);
        $hash = @file_get_contents($root . '/.git/' . $ref);
        if ($hash === false && is_file($root . '/.git/packed-refs')) {
            foreach (file($root . '/.git/packed-refs') as $line) { if (substr(trim($line), -strlen($ref)) === $ref) { $hash = substr($line, 0, 40); } }
        }
        $commit = $hash ? substr(trim($hash), 0, 7) : 'unknown';
    } else {
        $commit = substr($head, 0, 7);
    }
}

$files = [
    'includes/access_control.php' => 'Roles & Permissions',
    'modules/access/index.php' => 'Roles & Permissions page',
    'includes/executive_delete_helpers.php' => 'Deleting loans and assets',
    'includes/transaction_audit_helpers.php' => 'Transaction deletion and audit trail',
    'includes/comm_helpers.php' => 'Messages',
    'includes/category_helpers.php' => 'Categories',
    'includes/category_profit_queries.php' => 'Profit by Category',
    'includes/customer_name_helpers.php' => 'First / Last Name',
    'includes/searchable_select.js' => 'Type-to-search dropdowns',
];
$opcache = function_exists('opcache_get_status') ? @opcache_get_status(false) : false;
$opcacheOn = is_array($opcache) && !empty($opcache['opcache_enabled']);
$revalidate = $opcacheOn ? (int) ini_get('opcache.validate_timestamps') : null;
$dbPhp = (string) @file_get_contents($root . '/config/db.php');
$enforced = strpos($dbPhp, 'access_enforce_page') !== false;
$e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
include '../../includes/header.php'; include '../../includes/sidebar.php';
?>
<?php if (isset($_GET['msg'])) { ?><div class="alert alert-info"><?= $e($_GET['msg']); ?></div><?php } ?>
<h2 class="mb-3">System Status</h2>
<div class="card border-0 shadow-sm mb-3"><div class="card-body">
    <table class="table table-sm mb-0" style="font-size:14px;">
        <tr><th style="width:40%;">Code on this server (Git commit)</th><td><strong><?= $e($commit); ?></strong><?= $branch ? ' on branch <strong>' . $e($branch) . '</strong>' : ''; ?>
            <div class="small text-muted">Compare with the latest commit on GitHub (or <code>git log --oneline -1</code> on your computer). If they differ, the server has not been updated.</div></td></tr>
        <tr><th>Permission check in config/db.php</th><td><?= $enforced ? '<span class="badge bg-success">Active</span>' : '<span class="badge bg-danger">Missing</span> <span class="small">Add the two lines (access_control.php / access_enforce_page) at the end of config/db.php on this server.</span>'; ?></td></tr>
        <tr><th>PHP cache (OPcache)</th><td>
            <?php if (!$opcacheOn) { ?>Off (pages always use the files as they are).
            <?php } else { ?>On<?= $revalidate === 0 ? ' and it <strong class="text-danger">never re-reads changed files</strong>: after every update it must be cleared' : ' (re-reads changed files after a short delay)'; ?>.
                <form method="POST" class="d-inline ms-2"><input type="hidden" name="action" value="clear_opcache"><button class="btn btn-sm btn-primary">Clear PHP cache</button></form>
            <?php } ?></td></tr>
        <tr><th>PHP version</th><td><?= $e(PHP_VERSION); ?></td></tr>
    </table>
</div></div>
<div class="card border-0 shadow-sm"><div class="card-body p-0">
    <table class="table table-sm mb-0" style="font-size:13px;">
        <tr><th>New feature</th><th>File</th><th>On this server</th></tr>
        <?php foreach ($files as $f => $label) { $p = $root . '/' . $f; ?>
        <tr><td><?= $e($label); ?></td><td><code><?= $e($f); ?></code></td>
            <td><?= is_file($p) ? '<span class="badge bg-success">Yes</span> <span class="small text-muted">' . date('d M Y, H:i', filemtime($p)) . '</span>' : '<span class="badge bg-danger">Missing</span>'; ?></td></tr>
        <?php } ?>
    </table>
</div></div>
<?php include '../../includes/footer.php'; ?>