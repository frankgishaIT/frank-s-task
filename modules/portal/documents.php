<?php
// NEW FILE: modules/portal/documents.php — publish documents to ONE customer (they see it in "My documents").
require __DIR__ . '/portal_common.php';
require_once __DIR__ . '/../../includes/evidence_store.php';
const PORTAL_DOC_CATEGORIES = ['Invoice', 'Receipt', 'Contract / agreement', 'Service certificate', 'Delivery note', 'Account statement', 'Report', 'Other'];
const PORTAL_DOC_TYPES = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx'];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (($_POST['action'] ?? '') === 'unpublish') {
            $did = (int) $_POST['id'];
            $d = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM portal_documents WHERE id = $did"));
            mysqli_query($conn, "UPDATE portal_documents SET unpublished_at = NOW() WHERE id = $did");
            if ($d) { portal_log($conn, (int) $d['customer_id'], 'document_unpublished', $d['title'] . ' by ' . ($_SESSION['user_name'] ?? 'staff')); }
            header('Location: documents.php?success=' . urlencode('The customer no longer sees it.')); exit;
        }
        $cid = (int) ($_POST['customer_id'] ?? 0); $title = mb_substr(trim((string) ($_POST['title'] ?? '')), 0, 200);
        $cat = (string) ($_POST['category'] ?? ''); $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 255) ?: null;
        if (!mysqli_fetch_row(mysqli_query($conn, 'SELECT 1 FROM customers WHERE id = ' . $cid))) { throw new RuntimeException('Choose the customer.'); }
        if ($title === '' || !in_array($cat, PORTAL_DOC_CATEGORIES, true)) { throw new RuntimeException('Give a title and a category.'); }
        $ref = evidence_save($conn, $_FILES['file'] ?? null, (int) current_user_id(), PORTAL_DOC_TYPES, 10 * 1024 * 1024);
        if (!$ref) { throw new RuntimeException('Choose the file (PDF, Word, Excel or a photo; 10 MB at most).'); }
        $name = mb_substr(basename((string) $_FILES['file']['name']), 0, 255); $uid = (int) current_user_id();
        $s = mysqli_prepare($conn, 'INSERT INTO portal_documents (customer_id, title, category, file_ref, original_name, note, published_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($s, 'isssssi', $cid, $title, $cat, $ref, $name, $note, $uid);
        mysqli_stmt_execute($s);
        portal_log($conn, $cid, 'document_published', $title . ' by ' . ($_SESSION['user_name'] ?? 'staff'));
        header('Location: documents.php?success=' . urlencode('Published. The customer sees it in "My documents".')); exit;
    } catch (RuntimeException $ex) { $error = $ex->getMessage(); }
}
$customers = mysqli_fetch_all(mysqli_query($conn, 'SELECT id, name, phone FROM customers ORDER BY name'), MYSQLI_ASSOC);
$rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT d.*, c.name, u.names AS publisher FROM portal_documents d JOIN customers c ON c.id = d.customer_id LEFT JOIN users u ON u.id = d.published_by ORDER BY d.id DESC LIMIT 200'), MYSQLI_ASSOC);
include '../../includes/header.php'; include '../../includes/sidebar.php';
portal_staff_tabs('documents.php'); portal_staff_alerts();
?>
<?php if (isset($error)) { ?><div class="alert alert-danger"><?= $e($error); ?></div><?php } ?>
<form method="POST" enctype="multipart/form-data" class="card border-0 shadow-sm mb-3"><div class="card-body row g-2 align-items-end">
    <div class="col-md-4"><label class="form-label small text-muted mb-1">Customer</label><select name="customer_id" class="form-select form-select-sm" data-searchable required><option value="">Choose</option>
        <?php foreach ($customers as $c) { ?><option value="<?= (int) $c['id']; ?>"><?= $e($c['name'] . ' · ' . portal_customer_code((int) $c['id'])); ?></option><?php } ?></select></div>
    <div class="col-md-3"><label class="form-label small text-muted mb-1">Title</label><input name="title" class="form-control form-control-sm" maxlength="200" required></div>
    <div class="col-md-2"><label class="form-label small text-muted mb-1">Category</label><select name="category" class="form-select form-select-sm"><?php foreach (PORTAL_DOC_CATEGORIES as $c) { ?><option><?= $e($c); ?></option><?php } ?></select></div>
    <div class="col-md-3"><label class="form-label small text-muted mb-1">File</label><input type="file" name="file" class="form-control form-control-sm" required accept=".pdf,.jpg,.jpeg,.png,.docx,.xlsx"></div>
    <div class="col-md-9"><input name="note" class="form-control form-control-sm" maxlength="255" placeholder="Note for the customer (optional)"></div>
    <div class="col-md-3"><button class="btn btn-primary btn-sm w-100"><i class="bi bi-send me-1"></i>Publish to this customer</button></div>
</div></form>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive">
<table class="table table-hover bg-white mb-0 align-middle" style="font-size:13px;">
    <tr><th>Customer</th><th>Document</th><th>Published</th><th>Status</th><th></th></tr>
    <?php if (!$rows) { ?><tr><td colspan="5" class="text-center text-muted py-4">Nothing published yet.</td></tr><?php } ?>
    <?php foreach ($rows as $d) { ?>
    <tr><td><?= $e($d['name']); ?></td><td><?= $e($d['title']); ?><div class="small text-muted"><?= $e($d['category'] . ' · ' . $d['original_name']); ?></div></td>
        <td class="small"><?= date('d M Y', strtotime($d['published_at'])); ?> · <?= $e($d['publisher']); ?></td>
        <td><?= $d['unpublished_at'] ? '<span class="badge bg-secondary">Withdrawn</span>' : '<span class="badge bg-success">Visible</span>'; ?></td>
        <td><?php if (!$d['unpublished_at']) { ?><form method="POST" class="d-inline" onsubmit="return confirm('Withdraw this document from the customer?');"><input type="hidden" name="action" value="unpublish"><input type="hidden" name="id" value="<?= (int) $d['id']; ?>"><button class="btn btn-sm btn-outline-danger">Withdraw</button></form><?php } ?></td></tr>
    <?php } ?>
</table></div></div></div>
<?php include '../../includes/footer.php'; ?>