<?php
// NEW FILE: portal/lookup.php — find an invoice or receipt by its number (only the customer's own).
require __DIR__ . '/portal_start.php';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $num = mb_substr(trim((string) ($_POST['number'] ?? '')), 0, 40);
    $recent = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM portal_activity WHERE customer_id = $cid AND action LIKE 'lookup%' AND created_at > DATE_SUB(NOW(), INTERVAL 10 MINUTE)"))[0];
    if (!portal_csrf_ok()) { $error = 'Your session expired. Please try again.'; }
    elseif ($recent >= 30) { $error = 'Too many searches. Please wait a few minutes.'; }
    elseif ($num === '') { $error = 'Enter the number shown on your invoice or receipt.'; }
    else {
        $sale = portal_own_sale($conn, $cid, $num);
        portal_log($conn, $cid, $sale ? 'lookup_found' : 'lookup_not_found', $num);
        if ($sale) { header('Location: invoice.php?id=' . (int) $sale['id']); exit; }
        // The same answer for "does not exist" and "belongs to someone else": nothing about other customers is revealed.
        $error = 'No invoice or receipt "' . $num . '" was found in your account. Check the number, or send a request to RISE MOTIVE.';
    }
}
portal_header('Find invoice or receipt', $me);
?>
<div class="row justify-content-center"><div class="col-md-7"><div class="card"><div class="card-body p-4">
    <h1 class="h5 mb-1">Find an invoice or receipt</h1>
    <p class="small text-muted">Enter the number printed on it, for example <strong>RM000058</strong>. Only documents in your own account can be shown.</p>
    <?php if (isset($error)) { ?><div class="alert alert-warning"><?= portal_e($error); ?></div><?php } ?>
    <form method="POST" class="d-flex gap-2"><?= portal_csrf_field(); ?>
        <input name="number" class="form-control" required placeholder="Invoice / receipt number" value="<?= portal_e($_POST['number'] ?? ''); ?>">
        <button class="btn btn-primary"><i class="bi bi-search"></i> Find</button></form>
</div></div></div></div>
<?php portal_footer();