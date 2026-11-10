<?php
/**
 * NEW FILE: modules/marketing/materials.php — CREATIVE STUDIO: marketing materials linked to campaigns. The FILE stays in
 * the RM Digital Library (no second copy; Library permissions apply). Draft → Submitted → Approved / Needs changes
 * (Administrators approve). Only approved materials are marked ready for use.
 */
require __DIR__ . '/mkt_common.php';
mkt_ensure_studio($conn);
$camps = mysqli_fetch_all(mysqli_query($conn, "SELECT id, name, created_at FROM mkt_campaigns WHERE status <> 'Archived' ORDER BY name"), MYSQLI_ASSOC);
$docs = mysqli_fetch_all(mysqli_query($conn, "SELECT id, title, reference_no, created_at FROM lib_documents WHERE deleted_at IS NULL AND status <> 'Archived' ORDER BY id DESC LIMIT 300"), MYSQLI_ASSOC);
$canApprove = access_can('marketing.approve', $conn);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string) ($_POST['action'] ?? ''); $note = trim((string) ($_POST['note'] ?? ''));
    try {
        if ($act === 'add') {
            if (!access_can('marketing.create', $conn)) { throw new RuntimeException('You cannot add materials.'); }
            $title = mb_substr(trim((string) $_POST['title']), 0, 160); $type = in_array($_POST['type'] ?? '', MKT_MATERIAL_TYPES, true) ? $_POST['type'] : '';
            $camp = (int) ($_POST['campaign_id'] ?? 0) ?: null; $doc = (int) ($_POST['lib_document_id'] ?? 0) ?: null; $link = mb_substr(trim((string) ($_POST['link'] ?? '')), 0, 500) ?: null;
            $desc = trim((string) ($_POST['description'] ?? '')) ?: null;
            if ($title === '' || $type === '') { throw new RuntimeException('Give the material a title and a type.'); }
            if ($doc && !in_array($doc, array_map('intval', array_column($docs, 'id')), true)) { throw new RuntimeException('Choose a document from the RM Digital Library.'); }
            if (!$doc && !$link) { throw new RuntimeException('Choose the file in the RM Digital Library (upload it there first), or give a link (e.g. a published post).'); }
            if ($link && !filter_var($link, FILTER_VALIDATE_URL)) { throw new RuntimeException('The link is not a valid web address.'); }
            $s = mysqli_prepare($conn, 'INSERT INTO mkt_materials (campaign_id, title, type, description, lib_document_id, link, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
            mysqli_stmt_bind_param($s, 'isssisi', $camp, $title, $type, $desc, $doc, $link, $me); mysqli_stmt_execute($s); $id = (int) mysqli_insert_id($conn);
            mkt_audit($conn, 'Material added (Draft): ' . $title, 'MAT-' . $id, null, ['type' => $type, 'library_document' => $doc, 'link' => $link], null, $me);
            $msg = 'Material saved as a draft. Submit it for approval when it is final.';
        } else {
            $id = (int) ($_POST['id'] ?? 0); $m = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM mkt_materials WHERE id = $id"));
            if (!$m) { throw new RuntimeException('Material not found.'); }
            if ($act === 'submit') {
                if (!in_array($m['status'], ['Draft', 'Needs changes'], true) || ((int) $m['created_by'] !== $me && !mkt_is_manager())) { throw new RuntimeException('Only its author (or a Manager) submits a draft.'); }
                mysqli_query($conn, "UPDATE mkt_materials SET status = 'Submitted', updated_at = NOW() WHERE id = $id");
                mkt_notify($conn, mkt_admin_ids($conn), 'Material to approve: ' . $m['title'], 'MAT-' . $id); $to = 'Submitted';
            } elseif (in_array($act, ['approve', 'changes'], true)) {
                if (!$canApprove) { throw new RuntimeException('Only Administrators approve materials.'); }
                if ($m['status'] !== 'Submitted') { throw new RuntimeException('Only a submitted material can be decided.'); }
                if ((int) $m['created_by'] === $me && mb_strlen($note) < 5) { throw new RuntimeException('You made this material: write a short note (kept in the audit trail).'); }
                if ($act === 'changes' && mb_strlen($note) < 5) { throw new RuntimeException('Say what must be changed.'); }
                $to = $act === 'approve' ? 'Approved' : 'Needs changes';
                $s = mysqli_prepare($conn, 'UPDATE mkt_materials SET status = ?, decided_by = ?, decided_at = NOW(), decision_note = ?, updated_at = NOW() WHERE id = ?'); mysqli_stmt_bind_param($s, 'sisi', $to, $me, $note, $id); mysqli_stmt_execute($s);
                mkt_notify($conn, [(int) $m['created_by']], 'Material ' . strtolower($to) . ': ' . $m['title'], $note ?: 'Ready to use.');
            } elseif ($act === 'archive') {
                if (!mkt_is_manager()) { throw new RuntimeException('Only Managers archive materials.'); }
                mysqli_query($conn, "UPDATE mkt_materials SET status = 'Archived', updated_at = NOW() WHERE id = $id"); $to = 'Archived';
            } else { throw new RuntimeException('Unknown action.'); }
            mkt_audit($conn, 'Material ' . strtolower($to), 'MAT-' . $id, $m['status'], $to, $note ?: null, $me); $msg = 'Material: ' . $to . '.';
        }
        header('Location: materials.php?success=' . urlencode($msg)); exit;
    } catch (RuntimeException $ex) { $error = $ex->getMessage(); }
}
$fc = (int) ($_GET['campaign'] ?? 0); $fs = in_array($_GET['status'] ?? '', ['Draft', 'Submitted', 'Approved', 'Needs changes', 'Archived'], true) ? $_GET['status'] : '';
$w = ['1 = 1']; if ($fc) { $w[] = "m.campaign_id = $fc"; } if ($fs) { $w[] = "m.status = '$fs'"; } elseif (empty($_GET['all'])) { $w[] = "m.status <> 'Archived'"; }
$rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT m.*, c.name AS campaign, u.names AS author, d.title AS doc_title, d.created_at AS doc_created, d.reference_no FROM mkt_materials m LEFT JOIN mkt_campaigns c ON c.id = m.campaign_id
    LEFT JOIN users u ON u.id = m.created_by LEFT JOIN lib_documents d ON d.id = m.lib_document_id WHERE ' . implode(' AND ', $w) . ' ORDER BY m.id DESC LIMIT 300'), MYSQLI_ASSOC);
include '../../includes/header.php'; include '../../includes/sidebar.php';
mkt_tabs('materials.php');
?>
<?php if (isset($error)) { ?><div class="alert alert-danger"><?= $e($error); ?></div><?php } ?>
<?php if (access_can('marketing.create', $conn)) { ?>
<details class="card border-0 shadow-sm mb-3" <?= isset($error) ? 'open' : ''; ?>><summary class="card-body py-2 fw-semibold">+ Add a marketing material</summary>
<form method="POST" class="card-body pt-0 row g-2"><input type="hidden" name="action" value="add">
    <div class="col-md-5"><label class="form-label small text-muted mb-1">Title *</label><input name="title" maxlength="160" required class="form-control form-control-sm"></div>
    <div class="col-md-3"><label class="form-label small text-muted mb-1">Type *</label><select name="type" required class="form-select form-select-sm"><option value=""></option><?php foreach (MKT_MATERIAL_TYPES as $t) { ?><option><?= $t; ?></option><?php } ?></select></div>
    <div class="col-md-4"><label class="form-label small text-muted mb-1">Campaign</label><select name="campaign_id" class="form-select form-select-sm"><option value="">— none —</option><?php foreach ($camps as $c) { ?><option value="<?= (int) $c['id']; ?>"><?= $e(mkt_code('campaign', $c) . ' ' . $c['name']); ?></option><?php } ?></select></div>
    <div class="col-md-6"><label class="form-label small text-muted mb-1">File in the RM Digital Library</label><select name="lib_document_id" class="form-select form-select-sm"><option value="">— choose —</option><?php foreach ($docs as $d) { ?><option value="<?= (int) $d['id']; ?>"><?= $e(($d['reference_no'] ?: '#' . $d['id']) . ' · ' . $d['title']); ?></option><?php } ?></select>
        <div class="form-text">Upload the file in the <a href="../library/index.php" target="_blank">RM Digital Library</a> first (e.g. folder "Marketing"), then choose it here.</div></div>
    <div class="col-md-6"><label class="form-label small text-muted mb-1">…or a link (published post, video)</label><input name="link" maxlength="500" class="form-control form-control-sm" placeholder="https://"></div>
    <div class="col-12"><label class="form-label small text-muted mb-1">Description / message</label><textarea name="description" rows="2" class="form-control form-control-sm"></textarea></div>
    <div class="col-12"><button class="btn btn-primary btn-sm">Save draft</button></div>
</form></details>
<?php } ?>
<form class="d-flex flex-wrap gap-2 mb-2"><select name="campaign" class="form-select form-select-sm" style="max-width:260px;"><option value="">All campaigns</option><?php foreach ($camps as $c) { ?><option value="<?= (int) $c['id']; ?>" <?= $fc === (int) $c['id'] ? 'selected' : ''; ?>><?= $e($c['name']); ?></option><?php } ?></select>
    <select name="status" class="form-select form-select-sm" style="max-width:180px;"><option value="">All (not archived)</option><?php foreach (['Draft', 'Submitted', 'Approved', 'Needs changes', 'Archived'] as $x) { ?><option <?= $fs === $x ? 'selected' : ''; ?>><?= $x; ?></option><?php } ?></select><button class="btn btn-sm btn-primary">Filter</button></form>
<div class="row g-3">
<?php if (!$rows) { ?><div class="col-12"><div class="card border-0 shadow-sm"><div class="card-body text-muted">No materials yet.</div></div></div><?php } ?>
<?php foreach ($rows as $m) { ?>
<div class="col-md-6 col-xl-4"><div class="card border-0 shadow-sm h-100"><div class="card-body small d-flex flex-column">
    <div class="d-flex justify-content-between gap-2"><strong><?= $e($m['title']); ?></strong><?= $m['status'] === 'Approved' ? '<span class="badge bg-success">Approved · ready to use</span>' : mkt_badge($m['status']); ?></div>
    <div class="text-muted">MAT-<?= (int) $m['id']; ?> · <?= $e($m['type']); ?><?= $m['campaign'] ? ' · ' . $e($m['campaign']) : ''; ?><?= (int) $m['ai_generated'] ? ' · <span class="badge bg-dark">AI-generated</span>' : ''; ?></div>
    <?php if ($m['description']) { ?><div class="mt-1" style="white-space:pre-wrap;"><?= $e(mb_strimwidth($m['description'], 0, 240, '…')); ?></div><?php } ?>
    <div class="mt-1"><?php if ($m['lib_document_id']) { ?><a href="../library/document.php?id=<?= (int) $m['lib_document_id']; ?>" target="_blank"><i class="bi bi-folder2-open"></i> <?= $e($m['reference_no'] ?: $m['doc_title']); ?></a> <?php } ?>
        <?php if ($m['link']) { ?><a href="<?= $e($m['link']); ?>" target="_blank" rel="noopener"><i class="bi bi-link-45deg"></i> Open link</a><?php } ?></div>
    <div class="text-muted mt-1">By <?= $e($m['author']); ?> · <?= date('d M Y', strtotime($m['created_at'])); ?><?= $m['decision_note'] ? ' · ' . $e($m['decision_note']) : ''; ?></div>
    <div class="mt-auto pt-2 d-flex flex-wrap gap-1">
        <?php if (in_array($m['status'], ['Draft', 'Needs changes'], true) && ((int) $m['created_by'] === $me || mkt_is_manager())) { ?><form method="POST"><input type="hidden" name="id" value="<?= (int) $m['id']; ?>"><button name="action" value="submit" class="btn btn-sm btn-primary">Submit for approval</button></form><?php } ?>
        <?php if ($m['status'] === 'Submitted' && $canApprove) { ?><form method="POST" class="d-flex gap-1 w-100"><input type="hidden" name="id" value="<?= (int) $m['id']; ?>"><input name="note" class="form-control form-control-sm" placeholder="Note"><button name="action" value="approve" class="btn btn-sm btn-success">Approve</button><button name="action" value="changes" class="btn btn-sm btn-outline-danger text-nowrap">Needs changes</button></form><?php } ?>
        <?php if ($m['status'] !== 'Archived' && mkt_is_manager()) { ?><form method="POST" onsubmit="return confirm('Archive this material?');"><input type="hidden" name="id" value="<?= (int) $m['id']; ?>"><button name="action" value="archive" class="btn btn-sm btn-link text-muted p-0">Archive</button></form><?php } ?>
    </div>
</div></div></div><?php } ?>
</div>
<?php include '../../includes/footer.php'; ?>