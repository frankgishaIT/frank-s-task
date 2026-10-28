<?php
/**
 * NEW FILE: modules/transactions/record_deletions.php
 * Task I: the audit trail of deleted loans and assets (original details, reason, who, when, every reference).
 */
require '../../config/db.php';
require_once '../../includes/executive_delete_helpers.php';
exec_delete_ensure($conn);
$rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT d.*, u.names FROM record_deletions d LEFT JOIN users u ON u.id = d.user_id ORDER BY d.id DESC LIMIT 300'), MYSQLI_ASSOC);
$e = function ($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); };
include '../../includes/header.php'; include '../../includes/sidebar.php';
?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="mb-0">Deleted Loans &amp; Assets</h2>
    <a href="audit.php" class="rm-btn rm-btn-light">Transaction Audit Trail</a>
</div>
<p class="small text-muted">Every executive deletion, with the original record kept as it was. Deleted records no longer count in any list or total.</p>
<?php if (!$rows) { ?><div class="text-muted">No loans or assets have been deleted.</div><?php } ?>
<?php foreach ($rows as $r) { $snap = json_decode($r['snapshot'], true) ?: []; ?>
<div class="card border-0 shadow-sm mb-3"><div class="card-body small">
    <div class="d-flex justify-content-between"><strong><?= $e(ucfirst($r['record_type']) . ' ' . $r['reference']); ?></strong>
        <span class="text-muted"><?= date('d M Y, H:i', strtotime($r['created_at'])); ?> · by <?= $e($r['names'] ?? '—'); ?></span></div>
    <div class="mt-1"><strong>Reason:</strong> <?= $e($r['reason']); ?></div>
    <div class="mt-1"><strong>Reversed:</strong><pre class="mb-1" style="white-space:pre-wrap; font-size:12px;"><?= $e($r['affected']); ?></pre></div>
    <details><summary>Original record</summary><pre style="white-space:pre-wrap; font-size:11.5px; max-height:320px; overflow:auto;"><?= $e(json_encode($snap['record'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre></details>
</div></div>
<?php } ?>
<?php include '../../includes/footer.php'; ?>