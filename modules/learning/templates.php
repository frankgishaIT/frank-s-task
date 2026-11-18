<?php
/**
 * NEW FILE: modules/learning/templates.php — DOCUMENT TEMPLATES (certificates and transcripts) and the register of
 * generated documents. Create (from the standard layout), duplicate, edit, activate / deactivate, choose the default,
 * limit a template to some courses. Only approved (published) versions are used for real documents.
 */
require __DIR__ . '/common.php';
require_once __DIR__ . '/../../includes/lc_docs.php';
lcs_require($conn, 'settings');
lc_docs_ensure($conn);
$uid = (int) current_user_id();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!lcs_csrf_ok()) { lcs_go('templates.php', 'error', 'The page had expired.'); }
    $do = (string) ($_POST['do'] ?? ''); $id = (int) ($_POST['id'] ?? 0); $now = date('Y-m-d H:i:s');
    try {
        if ($do === 'new') {
            $kind = ($_POST['kind'] ?? '') === 'transcript' ? 'transcript' : 'certificate';
            $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120) ?: ($kind === 'certificate' ? 'Certificate' : 'Transcript') . ' template';
            $js = json_encode(lc_doc_default_settings($kind));
            $s = mysqli_prepare($conn, 'INSERT INTO lc_doc_templates (kind, name, draft, created_by, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
            mysqli_stmt_bind_param($s, 'sssiis', $kind, $name, $js, $uid, $uid, $now); mysqli_stmt_execute($s);
            $nid = (int) mysqli_insert_id($conn);
            lc_audit($conn, 'doc_template_create', 'doc_template', $nid, ['kind' => $kind, 'name' => $name]);
            lcs_go('template_edit.php?id=' . $nid, 'success', 'Template created from the standard layout. Change it, check the preview, then approve it.');
        }
        $t = lc_doc_template($conn, $id);
        if (!$t) { throw new RuntimeException('Template not found.'); }
        if ($do === 'duplicate') {
            $name = mb_substr($t['name'] . ' (copy)', 0, 120); $js = json_encode($t['settings']);
            $s = mysqli_prepare($conn, 'INSERT INTO lc_doc_templates (kind, name, draft, created_by, updated_by, updated_at) VALUES (?, ?, ?, ?, ?, ?)');
            mysqli_stmt_bind_param($s, 'sssiis', $t['kind'], $name, $js, $uid, $uid, $now); mysqli_stmt_execute($s);
            $nid = (int) mysqli_insert_id($conn);
            lc_audit($conn, 'doc_template_duplicate', 'doc_template', $nid, ['from' => $id]);
            lcs_go('template_edit.php?id=' . $nid, 'success', 'Copy created (draft).');
        }
        if ($do === 'activate' || $do === 'deactivate') {
            if ($do === 'activate' && (int) $t['version'] < 1) { throw new RuntimeException('Approve a version first (open the template, check the preview, "Approve and publish").'); }
            $st = $do === 'activate' ? 'Active' : 'Inactive';
            mysqli_query($conn, "UPDATE lc_doc_templates SET status = '$st'" . ($st === 'Inactive' ? ', is_default = 0' : '') . ", updated_by = $uid, updated_at = '$now' WHERE id = $id");
            lc_audit($conn, 'doc_template_' . $do, 'doc_template', $id, ['name' => $t['name']]);
            lcs_go('templates.php', 'success', $t['name'] . ': ' . strtolower($st) . '.');
        }
        if ($do === 'default') {
            if ($t['status'] !== 'Active') { throw new RuntimeException('Activate the template first.'); }
            mysqli_query($conn, "UPDATE lc_doc_templates SET is_default = 0 WHERE kind = '" . $t['kind'] . "'");
            mysqli_query($conn, "UPDATE lc_doc_templates SET is_default = 1, updated_by = $uid, updated_at = '$now' WHERE id = $id");
            lc_audit($conn, 'doc_template_default', 'doc_template', $id, ['kind' => $t['kind']]);
            lcs_go('templates.php', 'success', $t['name'] . ' is now the default ' . $t['kind'] . ' template.');
        }
        if ($do === 'delete') {
            $used = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM lc_doc_issues WHERE template_id = $id"))[0] + (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM lc_certificates WHERE template_id = $id"))[0];
            if ($used) { throw new RuntimeException('This template was used for documents: deactivate it instead (issued documents keep it).'); }
            mysqli_query($conn, "DELETE FROM lc_doc_versions WHERE template_id = $id"); mysqli_query($conn, "DELETE FROM lc_doc_templates WHERE id = $id");
            lc_audit($conn, 'doc_template_delete', 'doc_template', $id, ['name' => $t['name']]);
            lcs_go('templates.php', 'success', 'Template deleted.');
        }
    } catch (RuntimeException $e2) { lcs_go('templates.php', 'error', $e2->getMessage()); }
}
$rows = mysqli_fetch_all(mysqli_query($conn, 'SELECT t.*, u.names upd, (SELECT COUNT(*) FROM lc_doc_issues d WHERE d.template_id = t.id) used FROM lc_doc_templates t LEFT JOIN users u ON u.id = t.updated_by ORDER BY t.kind, t.is_default DESC, t.name'), MYSQLI_ASSOC);
$codes = array_column(mysqli_fetch_all(mysqli_query($conn, 'SELECT id, code FROM lc_courses'), MYSQLI_ASSOC), 'code', 'id');
$issues = mysqli_fetch_all(mysqli_query($conn, 'SELECT d.*, cu.name, t.name tname, u.names by_name FROM lc_doc_issues d LEFT JOIN customers cu ON cu.id = d.customer_id LEFT JOIN lc_doc_templates t ON t.id = d.template_id LEFT JOIN users u ON u.id = d.issued_by ORDER BY d.id DESC LIMIT 40'), MYSQLI_ASSOC);
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
lcs_tabs($conn, 'templates.php', 'Certificate & transcript templates');
?>
<div class="row g-3">
<div class="col-xl-8">
    <div class="card border-0 shadow-sm mb-3"><div class="card-body p-0"><table class="table table-sm align-middle mb-0" style="font-size:13px;">
        <tr class="table-light"><th class="ps-3">Template</th><th>Type</th><th>Status</th><th>Approved version</th><th>Courses</th><th class="text-end">Documents</th><th>Last change</th><th></th></tr>
        <?php foreach ($rows as $r) { $hid = lcs_csrf_field() . '<input type="hidden" name="id" value="' . (int) $r['id'] . '">'; ?>
        <tr><td class="ps-3"><a class="fw-semibold" href="template_edit.php?id=<?= (int) $r['id']; ?>"><?= lc_e($r['name']); ?></a><?= $r['is_default'] ? ' <span class="badge bg-primary">default</span>' : ''; ?></td>
            <td><?= ucfirst($r['kind']); ?></td><td><?= lcs_badge($r['status']); ?></td><td><?= (int) $r['version'] ? 'v' . (int) $r['version'] : '<span class="text-muted">not approved</span>'; ?></td>
            <td class="small"><?= $r['courses'] ? lc_e(implode(', ', array_map(function ($c) use ($codes) { return $codes[(int) $c] ?? '#' . $c; }, explode(',', $r['courses'])))) : 'All'; ?></td>
            <td class="text-end"><?= (int) $r['used']; ?></td><td class="small text-muted"><?= lc_e(substr((string) $r['updated_at'], 0, 16)); ?> <?= lc_e($r['upd']); ?></td>
            <td class="text-nowrap text-end pe-2">
                <a class="btn btn-sm btn-light border py-0" href="template_edit.php?id=<?= (int) $r['id']; ?>">Design</a>
                <form method="POST" class="d-inline"><?= $hid; ?><input type="hidden" name="do" value="duplicate"><button class="btn btn-sm btn-light border py-0">Duplicate</button></form>
                <?php if ($r['status'] === 'Active') { ?><?php if (!$r['is_default']) { ?><form method="POST" class="d-inline"><?= $hid; ?><input type="hidden" name="do" value="default"><button class="btn btn-sm btn-outline-primary py-0">Make default</button></form><?php } ?>
                    <form method="POST" class="d-inline"><?= $hid; ?><input type="hidden" name="do" value="deactivate"><button class="btn btn-sm btn-outline-secondary py-0">Deactivate</button></form>
                <?php } else { ?><form method="POST" class="d-inline"><?= $hid; ?><input type="hidden" name="do" value="activate"><button class="btn btn-sm btn-outline-success py-0" <?= (int) $r['version'] ? '' : 'disabled title="Approve a version first"'; ?>>Activate</button></form>
                    <?php if (!(int) $r['used']) { ?><form method="POST" class="d-inline" onsubmit="return confirm('Delete this template?');"><?= $hid; ?><input type="hidden" name="do" value="delete"><button class="btn btn-sm btn-link text-danger py-0">Delete</button></form><?php } ?><?php } ?></td></tr>
        <?php } ?>
        <?php if (!$rows) { ?><tr><td colspan="8" class="text-center text-muted py-4">No template yet: documents use the standard layout. Create one to design your own.</td></tr><?php } ?>
    </table></div></div>
    <div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="p-2 fw-semibold">Generated documents (latest)</div><table class="table table-sm mb-0" style="font-size:12.5px;">
        <tr class="table-light"><th class="ps-3">When</th><th>Document</th><th>Reference</th><th>Student</th><th>Template</th><th>Status</th><th>By</th></tr>
        <?php foreach ($issues as $d) { ?><tr><td class="ps-3"><?= lc_e(substr($d['issued_at'], 0, 16)); ?></td><td><?= ucfirst($d['kind']); ?></td><td class="font-monospace small"><?= lc_e($d['reference'] ?? '—'); ?></td><td><?= lc_e($d['name']); ?></td>
            <td class="small"><?= $d['template_id'] ? lc_e(($d['tname'] ?? '#' . $d['template_id']) . ' v' . $d['template_version']) : 'standard layout'; ?></td><td class="small"><?= lc_e($d['status']); ?></td><td class="small"><?= lc_e($d['by_name'] ?: ($d['channel'] === 'portal' ? 'student (portal)' : '')); ?></td></tr><?php } ?>
        <?php if (!$issues) { ?><tr><td colspan="7" class="ps-3 text-muted">Nothing generated yet.</td></tr><?php } ?></table></div></div>
</div>
<div class="col-xl-4">
    <form method="POST" class="card border-0 shadow-sm mb-3"><div class="card-body"><?= lcs_csrf_field(); ?><input type="hidden" name="do" value="new">
        <div class="fw-semibold mb-2">New template</div>
        <select name="kind" class="form-select form-select-sm mb-2"><option value="certificate">Certificate</option><option value="transcript">Transcript</option></select>
        <input name="name" class="form-control form-control-sm mb-2" placeholder="Name (e.g. Certificate 2027 — gold border)">
        <button class="btn btn-sm btn-primary">Create from the standard layout</button></div></form>
    <div class="small text-muted">How it works: design → check the live preview (sample data or a real student) → test the PDF → <strong>Approve and publish</strong> (a new, fixed version) → Activate → Make default, or limit it to some courses.
        Certificates keep the version they were issued with: editing a template later never changes issued certificates. Without an active template, the standard layout is used.</div>
</div></div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>