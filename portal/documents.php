<?php
// NEW FILE: portal/documents.php — documents RISE MOTIVE published to this customer.
require __DIR__ . '/portal_start.php';
$docs = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM portal_documents WHERE customer_id = $cid AND unpublished_at IS NULL ORDER BY published_at DESC"), MYSQLI_ASSOC);
portal_header('My documents', $me);
?>
<h1 class="h5 mb-3">My documents</h1>
<div class="card"><div class="card-body"><div class="table-responsive"><table class="table mb-0 align-middle">
    <tr><th>Document</th><th>Category</th><th>Date</th><th></th></tr>
    <?php if (!$docs) { ?><tr><td colspan="4" class="text-muted">No documents have been shared with you yet. Need one? <a href="requests.php">Send a request</a>.</td></tr><?php } ?>
    <?php foreach ($docs as $d) { ?>
    <tr><td><i class="bi bi-file-earmark-text me-1"></i><?= portal_e($d['title']); ?><?php if ($d['note']) { ?><div class="small text-muted"><?= portal_e($d['note']); ?></div><?php } ?></td>
        <td><?= portal_e($d['category']); ?></td><td><?= date('d M Y', strtotime($d['published_at'])); ?></td>
        <td><a href="document_file.php?id=<?= (int) $d['id']; ?>" class="btn btn-sm btn-outline-primary" target="_blank" rel="noopener"><i class="bi bi-download"></i> Open</a></td></tr>
    <?php } ?></table></div></div></div>
<?php portal_footer();