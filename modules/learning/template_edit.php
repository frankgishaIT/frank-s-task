<?php
/**
 * NEW FILE: modules/learning/template_edit.php — the DOCUMENT DESIGNER (certificate or transcript template).
 * Left: page, typography, borders and every element (text with {placeholders}, logo, signature, QR code, lines, tables).
 * Right: LIVE PREVIEW at the real page size, with sample data or a real certificate / student; on certificates the
 * elements can be dragged on the page. "Test PDF" makes the real PDF. "Approve and publish" creates a fixed version.
 */
require __DIR__ . '/common.php';
require_once __DIR__ . '/../../includes/lc_docs.php';
lcs_require($conn, 'settings');
lc_docs_ensure($conn);
$t = lc_doc_template($conn, (int) ($_GET['id'] ?? $_POST['id'] ?? 0));
if (!$t) { lcs_go('templates.php', 'error', 'Template not found.'); }
$id = (int) $t['id']; $kind = $t['kind']; $uid = (int) current_user_id();

/** Data for the preview / test PDF: sample, or a real certificate / enrollment. */
$previewData = function (string $with) use ($conn, $kind): array {
    if (preg_match('/^c(\d+)$/', $with, $m) && $kind === 'certificate') { $c = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM lc_certificates WHERE id = ' . (int) $m[1])); if ($c) { return lc_doc_cert_data($conn, $c); } }
    if (preg_match('/^e(\d+)$/', $with, $m) && $kind === 'transcript') { try { $d = lc_doc_transcript_data($conn, (int) $m[1]); $d['document_reference'] = $d['_final'] ? 'RMLC-T…' : 'provisional — no reference'; return $d; } catch (Throwable $e2) { } }
    return lc_doc_sample($kind);
};
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!lcs_csrf_ok()) { http_response_code(400); exit('The page had expired. Reload it.'); }
    $do = (string) ($_POST['do'] ?? '');
    $settings = lc_doc_clean($kind, json_decode((string) ($_POST['settings'] ?? ''), true) ?: $t['settings']);
    if ($do === 'preview') { header('Content-Type: text/html; charset=UTF-8'); echo lc_doc_html($kind, $settings, $previewData((string) ($_POST['with'] ?? '')), 'preview'); exit; }
    if ($do === 'pdf') { lc_doc_pdf($kind, $settings, $previewData((string) ($_POST['with'] ?? '')), 'TEST-' . $kind . '-template.pdf'); exit; }
    $name = mb_substr(trim((string) ($_POST['name'] ?? '')), 0, 120) ?: $t['name'];
    $courses = implode(',', array_map('intval', array_filter((array) ($_POST['courses'] ?? []), 'is_numeric'))) ?: null;
    $js = json_encode($settings); $now = date('Y-m-d H:i:s');
    $s = mysqli_prepare($conn, 'UPDATE lc_doc_templates SET name = ?, courses = ?, draft = ?, updated_by = ?, updated_at = ? WHERE id = ?');
    mysqli_stmt_bind_param($s, 'sssisi', $name, $courses, $js, $uid, $now, $id); mysqli_stmt_execute($s);
    if ($do === 'publish') {
        if (empty($_POST['checked'])) { lc_audit($conn, 'doc_template_save', 'doc_template', $id, ['name' => $name, 'blocks' => count($settings['blocks'])]); lcs_go('template_edit.php?id=' . $id, 'error', 'Draft saved. To approve, tick "I checked the preview and the test PDF".'); }
        $v = (int) $t['version'] + 1; $note = mb_substr(trim((string) ($_POST['note'] ?? '')), 0, 300) ?: null;
        $s = mysqli_prepare($conn, 'INSERT INTO lc_doc_versions (template_id, version, settings, note, approved_by, approved_at) VALUES (?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($s, 'iissis', $id, $v, $js, $note, $uid, $now); mysqli_stmt_execute($s);
        $st = !empty($_POST['activate']) ? 'Active' : ($t['status'] === 'Draft' ? 'Inactive' : $t['status']);
        mysqli_query($conn, "UPDATE lc_doc_templates SET version = $v, status = '$st' WHERE id = $id");
        if ($st === 'Active' && !(int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM lc_doc_templates WHERE kind = '$kind' AND is_default = 1 AND status = 'Active'"))[0]) { mysqli_query($conn, "UPDATE lc_doc_templates SET is_default = 1 WHERE id = $id"); }
        lc_audit($conn, 'doc_template_publish', 'doc_template', $id, ['version' => $v, 'note' => $note, 'status' => $st]);
        lcs_go('template_edit.php?id=' . $id, 'success', 'Version ' . $v . ' approved' . ($st === 'Active' ? ' and active: new documents use it.' : '.') . ' Documents already issued keep their own version.');
    }
    lc_audit($conn, 'doc_template_save', 'doc_template', $id, ['name' => $name, 'blocks' => count($settings['blocks'])]);
    lcs_go('template_edit.php?id=' . $id, 'success', 'Draft saved (not used for documents until you approve it).');
}
$courses = mysqli_fetch_all(mysqli_query($conn, "SELECT id, code, title FROM lc_courses WHERE status <> 'Archived' ORDER BY code"), MYSQLI_ASSOC);
$sel = array_filter(explode(',', (string) $t['courses']));
$real = $kind === 'certificate'
    ? mysqli_fetch_all(mysqli_query($conn, 'SELECT id, number, student_name FROM lc_certificates ORDER BY id DESC LIMIT 30'), MYSQLI_ASSOC)
    : mysqli_fetch_all(mysqli_query($conn, 'SELECT e.id, cu.name, c.code FROM lc_enrollments e JOIN customers cu ON cu.id = e.customer_id JOIN lc_courses c ON c.id = e.course_id ORDER BY e.id DESC LIMIT 30'), MYSQLI_ASSOC);
$versions = mysqli_fetch_all(mysqli_query($conn, "SELECT v.version, v.note, v.approved_at, u.names FROM lc_doc_versions v LEFT JOIN users u ON u.id = v.approved_by WHERE v.template_id = $id ORDER BY v.version DESC"), MYSQLI_ASSOC);
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
lcs_tabs($conn, 'templates.php', 'Design: ' . $t['name'], '<a href="templates.php" class="btn btn-sm btn-light border">All templates</a>');
$J = function ($v) { return json_encode($v, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); };
?>
<style>
    .ld-panel { font-size: 12.5px; } .ld-panel .form-control, .ld-panel .form-select { font-size: 12.5px; padding: 3px 6px; } .ld-panel label { font-size: 11.5px; color: #5B6275; margin-bottom: 1px; }
    .ld-block { border: 1px solid #E4E7EF; border-radius: 8px; margin-bottom: 6px; background: #fff; } .ld-block.on { border-color: #1E2FE0; box-shadow: 0 0 0 2px rgba(30,47,224,.15); }
    .ld-block .hd { padding: 5px 8px; cursor: pointer; display: flex; justify-content: space-between; align-items: center; gap: 6px; } .ld-block .bd { padding: 6px 8px 8px; border-top: 1px solid #EEF1F8; display: none; } .ld-block.open .bd { display: block; }
    .ld-chip { display: inline-block; font-size: 11px; padding: 1px 6px; margin: 0 3px 3px 0; border-radius: 10px; background: #EEF3FF; color: #0B1A8C; cursor: pointer; border: 0; }
    #ldStage { background: #E9ECF3; border-radius: 10px; padding: 12px; overflow: hidden; } #ldScale { transform-origin: 0 0; }
    #ldStage [data-b] { cursor: pointer; } #ldStage [data-b].on { outline: 1.5px dashed #1E2FE0; outline-offset: 1px; }
    .ld-sticky { position: sticky; top: 70px; }
</style>
<form method="POST" id="ldForm"><?= lcs_csrf_field(); ?><input type="hidden" name="id" value="<?= $id; ?>"><input type="hidden" name="settings" id="ldSettings"><input type="hidden" name="do" id="ldDo" value="save"><input type="hidden" name="with" id="ldWith2">
<div class="row g-3">
<div class="col-xl-5 ld-panel">
    <div class="card border-0 shadow-sm mb-2"><div class="card-body py-2">
        <div class="row g-2">
            <div class="col-8"><label>Template name</label><input name="name" class="form-control" value="<?= lc_e($t['name']); ?>"></div>
            <div class="col-4"><label>Status</label><div><?= lcs_badge($t['status']); ?> <span class="small text-muted"><?= (int) $t['version'] ? 'v' . (int) $t['version'] . ' approved' : 'never approved'; ?></span></div></div>
            <div class="col-12"><label>Use for courses (none ticked = all courses, when it is the default)</label><div><?php foreach ($courses as $c) { ?><label class="me-2 text-dark"><input type="checkbox" name="courses[]" value="<?= (int) $c['id']; ?>" <?= in_array((string) $c['id'], $sel, true) ? 'checked' : ''; ?>> <?= lc_e($c['code']); ?></label><?php } ?></div></div>
        </div>
    </div></div>
    <div class="card border-0 shadow-sm mb-2"><div class="card-body py-2"><div class="fw-semibold mb-1">Page and style</div><div class="row g-2" id="ldGeneral"></div></div></div>
    <div class="card border-0 shadow-sm mb-2"><div class="card-body py-2">
        <div class="d-flex justify-content-between align-items-center mb-1"><div class="fw-semibold">Elements</div>
            <div class="d-flex gap-1"><select id="ldNewType" class="form-select form-select-sm" style="width:auto"><?php foreach (LC_DOC_BLOCKS as $k => $l) { if ($kind === 'certificate' && in_array($k, ['modules_table', 'assessments_table', 'summary_table'], true)) { continue; } ?><option value="<?= $k; ?>"><?= $l; ?></option><?php } ?></select><button type="button" class="btn btn-sm btn-outline-primary" id="ldAdd">Add</button></div></div>
        <div id="ldBlocks"></div>
        <div class="small text-muted mt-1"><?= $kind === 'certificate' ? 'Positions are in millimetres from the top-left corner of the page. You can also drag an element in the preview.' : 'Transcripts flow from top to bottom (and onto more pages when needed): order the elements and set the space above each one.'; ?></div>
    </div></div>
    <div class="card border-0 shadow-sm mb-2"><div class="card-body py-2"><div class="fw-semibold mb-1">Information you can insert <span class="small text-muted fw-normal">(click: inserted in the text you are editing)</span></div>
        <?php foreach (lc_doc_fields($kind) as $k => [$d, $v]) { ?><button type="button" class="ld-chip" title="<?= lc_e($d . ' — e.g. ' . $v); ?>" data-ph="{<?= $k; ?>}">{<?= $k; ?>}</button><?php } ?>
        <div class="small text-muted">An empty value prints nothing (never the {code}).</div></div></div>
    <div class="card border-0 shadow-sm"><div class="card-body py-2">
        <div class="d-flex flex-wrap gap-2 align-items-center"><button type="submit" class="btn btn-sm btn-outline-primary" onclick="ldSubmit('save')">Save draft</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="ldPdf()"><i class="bi bi-file-earmark-pdf"></i> Test PDF</button></div>
        <div class="border-top mt-2 pt-2"><div class="fw-semibold small mb-1">Approve and publish</div>
            <input name="note" class="form-control form-control-sm mb-1" placeholder="What changed (kept with the version)">
            <label class="text-dark d-block"><input type="checkbox" name="checked" value="1"> I checked the preview and the test PDF</label>
            <label class="text-dark d-block"><input type="checkbox" name="activate" value="1" <?= $t['status'] !== 'Inactive' ? 'checked' : ''; ?>> Use this version for new documents now (Active)</label>
            <button type="submit" class="btn btn-sm btn-success mt-1" onclick="ldSubmit('publish')">Approve and publish version <?= (int) $t['version'] + 1; ?></button></div>
        <?php if ($versions) { ?><div class="border-top mt-2 pt-2 small"><div class="fw-semibold mb-1">Versions</div><?php foreach ($versions as $v) { ?><div>v<?= (int) $v['version']; ?> · <?= lc_e(substr($v['approved_at'], 0, 16)); ?> · <?= lc_e($v['names']); ?><?= $v['note'] ? ' · ' . lc_e($v['note']) : ''; ?></div><?php } ?></div><?php } ?>
    </div></div>
</div>
<div class="col-xl-7"><div class="ld-sticky">
    <div class="d-flex justify-content-between align-items-center mb-2 small"><div class="fw-semibold">Live preview</div>
        <div class="d-flex align-items-center gap-1">Data: <select id="ldWith" class="form-select form-select-sm" style="width:auto"><option value="">Sample data</option><?php foreach ($real as $r) { ?><option value="<?= ($kind === 'certificate' ? 'c' : 'e') . (int) $r['id']; ?>"><?= lc_e($kind === 'certificate' ? $r['number'] . ' · ' . $r['student_name'] : $r['name'] . ' · ' . $r['code']); ?></option><?php } ?></select></div></div>
    <div id="ldStage"><div id="ldScale"></div></div>
    <div class="small text-muted mt-1" id="ldInfo"></div>
</div></div>
</div></form>
<script>
(function () {
    var KIND = <?= $J($kind); ?>, S = <?= $J($t['settings']); ?>, FONTS = <?= $J(array_map(function ($f) { return $f[0]; }, LC_DOC_FONTS)); ?>, COLORS = <?= $J(LC_DOC_COLORS); ?>, PAGES = <?= $J(LC_DOC_PAGES); ?>, TYPES = <?= $J(LC_DOC_BLOCKS); ?>;
    var CERT = KIND === 'certificate', openIdx = -1, lastText = null, timer = null;
    var f = document.getElementById('ldForm'), csrf = f.querySelector('[name=csrf]').value;
    var el = function (tag, attrs, html) { var e = document.createElement(tag); Object.keys(attrs || {}).forEach(function (k) { e.setAttribute(k, attrs[k]); }); if (html !== undefined) e.innerHTML = html; return e; };
    var opt = function (map, cur) { return Object.keys(map).map(function (k) { return '<option value="' + k + '"' + (String(cur) === String(k) ? ' selected' : '') + '>' + map[k] + '</option>'; }).join(''); };
    var esc = function (s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
    function field(label, html, cls) { return '<div class="' + (cls || 'col-4') + '"><label>' + label + '</label>' + html + '</div>'; }
    function inp(path, val, type, extra) { return '<input class="form-control" data-p="' + path + '" type="' + (type || 'number') + '" step="0.5" value="' + esc(val) + '"' + (extra || '') + '>'; }
    function sel(path, map, val) { return '<select class="form-select" data-p="' + path + '">' + opt(map, val) + '</select>'; }
    function set(path, v) { var p = path.split('.'), o = S; for (var i = 0; i < p.length - 1; i++) { o = o[isNaN(p[i]) ? p[i] : +p[i]]; } var k = p[p.length - 1]; o[isNaN(k) ? k : +k] = v; }
    function general() {
        var g = document.getElementById('ldGeneral'), m = S.page.margins;
        g.innerHTML = field('Page size', sel('page.size', { A4: 'A4', A5: 'A5', Letter: 'Letter' }, S.page.size)) + field('Orientation', sel('page.orientation', { P: 'Portrait', L: 'Landscape' }, S.page.orientation))
            + field('Font', sel('font', FONTS, S.font)) + field('Base size (pt)', inp('base_size', S.base_size)) + field('Text colour', sel('color', COLORS, S.color))
            + (CERT ? '' : field('Margins mm (top, right, bottom, left)', '<div class="d-flex gap-1">' + [0, 1, 2, 3].map(function (i) { return inp('page.margins.' + i, m[i]); }).join('') + '</div>', 'col-12'))
            + field('Border', sel('border.style', { none: 'None', solid: 'Single line', double: 'Double line', dashed: 'Dashed' }, S.border.style)) + field('Border width mm', inp('border.width', S.border.width)) + field('Border colour', sel('border.color', COLORS, S.border.color))
            + field('Border distance from edge mm', inp('border.inset', S.border.inset)) + field('Decoration', sel('ornament', { none: 'None', corners: 'Corner frames', band: 'Top and bottom bands' }, S.ornament))
            + field('Logo watermark', sel('watermark_logo', { 0: 'No', 1: 'Faint logo in the centre' }, S.watermark_logo)) + (CERT ? '' : field('Table header colour', sel('table_color', COLORS, S.table_color)));
    }
    function blocks() {
        var w = document.getElementById('ldBlocks'); w.innerHTML = '';
        S.blocks.forEach(function (b, i) {
            var d = el('div', { 'class': 'ld-block' + (i === openIdx ? ' open on' : ''), 'data-i': i });
            var title = TYPES[b.type] + (b.content ? ' · ' + esc(String(b.content).slice(0, 34)) : '') + (b.visible ? '' : ' <span class="text-muted">(hidden)</span>');
            var hd = '<div class="hd"><span>' + title + '</span><span class="text-nowrap"><button type="button" class="btn btn-sm btn-link p-0 px-1" data-a="up" title="Up">↑</button><button type="button" class="btn btn-sm btn-link p-0 px-1" data-a="down" title="Down">↓</button><button type="button" class="btn btn-sm btn-link p-0 px-1" data-a="copy" title="Duplicate">⧉</button><button type="button" class="btn btn-sm btn-link text-danger p-0 px-1" data-a="del" title="Delete">×</button></span></div>';
            var p = 'blocks.' + i + '.', bd = '<div class="row g-2">';
            if (['text', 'signature', 'modules_table', 'assessments_table'].indexOf(b.type) >= 0) { bd += field(b.type === 'signature' ? 'Name / title under the line' : (b.type === 'text' ? 'Text (Enter = new line)' : 'Heading'), '<textarea class="form-control" rows="' + (b.type === 'text' ? 3 : 1) + '" data-p="' + p + 'content">' + esc(b.content) + '</textarea>', 'col-12'); }
            if (CERT) { bd += field('Left mm', inp(p + 'x', b.x), 'col-3') + field('Top mm', inp(p + 'y', b.y), 'col-3') + field(b.type === 'qr' || b.type === 'logo' ? 'Size mm' : 'Width mm', inp(p + 'w', b.w), 'col-3') + field('Height mm (0 = auto)', inp(p + 'h', b.h), 'col-3'); }
            else { bd += field('Space above mm', inp(p + 'space', b.space), 'col-3') + ((b.type === 'logo' || b.type === 'signature') ? field('Width mm', inp(p + 'w', b.w), 'col-3') : ''); }
            if (['text', 'signature', 'logo', 'qr'].indexOf(b.type) >= 0) { bd += field('Align', sel(p + 'align', { left: 'Left', center: 'Centre', right: 'Right', justify: 'Justified' }, b.align), 'col-3'); }
            if (['text', 'signature', 'modules_table', 'assessments_table', 'summary_table'].indexOf(b.type) >= 0) {
                bd += field('Size pt', inp(p + 'size', b.size), 'col-3') + field('Weight', sel(p + 'weight', { normal: 'Normal', bold: 'Bold' }, b.weight), 'col-3') + field('Italic', sel(p + 'italic', { 0: 'No', 1: 'Yes' }, b.italic), 'col-3') + field('Line spacing', inp(p + 'line', b.line, 'number', ' step="0.1"'), 'col-3');
            }
            if (b.type !== 'logo' && b.type !== 'qr') { bd += field('Colour', sel(p + 'color', COLORS, b.color), 'col-4'); }
            if (b.type === 'modules_table') { bd += field('Columns', ['code', 'module', 'hours', 'mark', 'grade'].map(function (c) { return '<label class="me-2 text-dark"><input type="checkbox" data-col="' + c + '" data-i="' + i + '"' + ((',' + (b.columns || 'code,module,hours,mark,grade') + ',').indexOf(',' + c + ',') >= 0 ? ' checked' : '') + '> ' + c + '</label>'; }).join(''), 'col-12'); }
            if (b.type === 'assessments_table') { bd += field('Columns', ['assessment', 'type', 'module', 'weight', 'mark'].map(function (c) { return '<label class="me-2 text-dark"><input type="checkbox" data-col="' + c + '" data-i="' + i + '"' + ((',' + (b.columns || 'assessment,type,module,weight,mark') + ',').indexOf(',' + c + ',') >= 0 ? ' checked' : '') + '> ' + c + '</label>'; }).join(''), 'col-12'); }
            if (b.type === 'logo' || b.type === 'signature') { bd += field(b.type === 'logo' ? 'Other logo (optional, PNG/JPG)' : 'Signature image (optional, PNG/JPG)', '<input type="file" class="form-control" accept="image/png,image/jpeg" data-img="' + i + '">' + (b.image ? '<button type="button" class="btn btn-link btn-sm p-0" data-noimg="' + i + '">remove the image</button>' : ''), 'col-8'); }
            bd += field('Visible', sel(p + 'visible', { 1: 'Yes', 0: 'Hidden' }, b.visible), 'col-4') + '</div>';
            d.innerHTML = hd + '<div class="bd">' + bd + '</div>';
            w.appendChild(d);
        });
    }
    function render() { general(); blocks(); preview(); }
    function preview() {
        clearTimeout(timer);
        timer = setTimeout(function () {
            var fd = new FormData(); fd.append('csrf', csrf); fd.append('id', <?= $id; ?>); fd.append('do', 'preview'); fd.append('with', document.getElementById('ldWith').value); fd.append('settings', JSON.stringify(S));
            fetch(location.pathname + location.search, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (h) {
                var sc = document.getElementById('ldScale'); sc.innerHTML = h; fit();
                sc.querySelectorAll('[data-b]').forEach(function (n) { if (+n.getAttribute('data-b') === openIdx) n.classList.add('on'); });
            });
        }, 250);
    }
    function fit() { var st = document.getElementById('ldStage'), sh = document.querySelector('#ldScale .lc-sheet'); if (!sh) return; var w = sh.offsetWidth, s = Math.min(1, (st.clientWidth - 24) / w); document.getElementById('ldScale').style.transform = 'scale(' + s + ')'; st.style.height = (sh.offsetHeight * s + 24) + 'px'; document.getElementById('ldInfo').textContent = 'Shown at ' + Math.round(s * 100) + '% · ' + S.page.size + ' ' + (S.page.orientation === 'L' ? 'landscape' : 'portrait'); window.ldScaleNow = s; }
    window.addEventListener('resize', fit);
    var panel = document.querySelector('.ld-panel');
    panel.addEventListener('input', function (e) { var p = e.target.getAttribute('data-p'); if (!p) return; var v = e.target.value; set(p, e.target.type === 'number' ? parseFloat(v || 0) : (/(visible|italic|watermark_logo)$/.test(p) ? +v : v)); if (/blocks\.\d+\.(content|visible)/.test(p)) { var hd = e.target.closest('.ld-block').querySelector('.hd span'); } preview(); });
    panel.addEventListener('change', function (e) { if (e.target.matches('select[data-p]')) { var p = e.target.getAttribute('data-p'); set(p, /(visible|italic|watermark_logo)$/.test(p) ? +e.target.value : e.target.value); if (/^page\./.test(p) || p === 'font') { general(); } preview(); }
        if (e.target.matches('[data-col]')) { var i = +e.target.getAttribute('data-i'); var cols = Array.prototype.map.call(e.target.closest('.ld-block').querySelectorAll('[data-col]:checked'), function (c) { return c.getAttribute('data-col'); }); S.blocks[i].columns = cols.join(','); preview(); }
        if (e.target.matches('[data-img]')) { var i2 = +e.target.getAttribute('data-img'), file = e.target.files[0]; if (!file) return; var rd = new FileReader(); rd.onload = function () { var im = new Image(); im.onload = function () { var mx = 700, w = im.width, h = im.height; if (w > mx) { h = Math.round(h * mx / w); w = mx; } var cv = document.createElement('canvas'); cv.width = w; cv.height = h; var cx = cv.getContext('2d'); cx.drawImage(im, 0, 0, w, h); var png = file.type === 'image/png'; var u = cv.toDataURL(png ? 'image/png' : 'image/jpeg', 0.9); if (u.length > 390000) { u = cv.toDataURL('image/jpeg', 0.7); } if (u.length > 390000) { alert('This image is too large. Please use a smaller picture.'); return; } S.blocks[i2].image = u; render(); }; im.src = rd.result; }; rd.readAsDataURL(file); }
    });
    panel.addEventListener('focusin', function (e) { if (e.target.matches('textarea[data-p]')) lastText = e.target; });
    panel.addEventListener('click', function (e) {
        var ch = e.target.closest('[data-ph]'); if (ch) { if (!lastText) { alert('Click first in the text where it should go.'); return; } var t = lastText, a = t.selectionStart, z = t.selectionEnd; t.value = t.value.slice(0, a) + ch.getAttribute('data-ph') + t.value.slice(z); t.focus(); t.selectionStart = t.selectionEnd = a + ch.getAttribute('data-ph').length; set(t.getAttribute('data-p'), t.value); preview(); return; }
        var ni = e.target.closest('[data-noimg]'); if (ni) { S.blocks[+ni.getAttribute('data-noimg')].image = ''; render(); return; }
        var bt = e.target.closest('[data-a]'), blk = e.target.closest('.ld-block');
        if (bt && blk) { var i = +blk.getAttribute('data-i'), a2 = bt.getAttribute('data-a');
            if (a2 === 'up' && i > 0) { S.blocks.splice(i - 1, 0, S.blocks.splice(i, 1)[0]); openIdx = i - 1; }
            if (a2 === 'down' && i < S.blocks.length - 1) { S.blocks.splice(i + 1, 0, S.blocks.splice(i, 1)[0]); openIdx = i + 1; }
            if (a2 === 'copy') { var c = JSON.parse(JSON.stringify(S.blocks[i])); if (CERT) { c.y += 8; } S.blocks.splice(i + 1, 0, c); openIdx = i + 1; }
            if (a2 === 'del' && confirm('Delete this element?')) { S.blocks.splice(i, 1); openIdx = -1; }
            render(); return; }
        if (e.target.closest('.hd') && blk) { var j = +blk.getAttribute('data-i'); openIdx = openIdx === j ? -1 : j; blocks(); preview(); }
    });
    document.getElementById('ldAdd').addEventListener('click', function () {
        var t = document.getElementById('ldNewType').value;
        var b = { type: t, content: t === 'text' ? 'New text' : (t === 'signature' ? '{signatory}' : ''), x: 20, y: 20, w: t === 'qr' ? 26 : (t === 'logo' ? 28 : (t === 'signature' ? 70 : 120)), h: 0, align: t === 'text' ? 'center' : 'left', size: 11, weight: 'normal', italic: 0, line: 1.3, color: '#1E2333', space: 4, visible: 1, image: '', columns: '' };
        S.blocks.push(b); openIdx = S.blocks.length - 1; render();
    });
    document.getElementById('ldWith').addEventListener('change', preview);
    // Click on the preview selects the element; on certificates it can be dragged.
    var drag = null;
    document.getElementById('ldStage').addEventListener('mousedown', function (e) {
        var n = e.target.closest('[data-b]'); if (!n) return; var i = +n.getAttribute('data-b');
        if (openIdx !== i) { openIdx = i; blocks(); document.querySelectorAll('#ldScale [data-b]').forEach(function (x) { x.classList.toggle('on', +x.getAttribute('data-b') === i); }); var bl = document.querySelector('.ld-block[data-i="' + i + '"]'); if (bl) bl.scrollIntoView({ block: 'nearest' }); }
        if (!CERT) return; e.preventDefault();
        drag = { i: i, sx: e.clientX, sy: e.clientY, x: S.blocks[i].x, y: S.blocks[i].y, n: n, l: parseFloat(n.style.left), t: parseFloat(n.style.top) };
    });
    document.addEventListener('mousemove', function (e) { if (!drag) return; var k = 3.7795 * (window.ldScaleNow || 1); var dx = (e.clientX - drag.sx) / k, dy = (e.clientY - drag.sy) / k; drag.n.style.left = (drag.l + dx) + 'mm'; drag.n.style.top = (drag.t + dy) + 'mm'; drag.dx = dx; drag.dy = dy; });
    document.addEventListener('mouseup', function () { if (!drag) return; if (drag.dx !== undefined) { S.blocks[drag.i].x = Math.round((drag.x + drag.dx) * 2) / 2; S.blocks[drag.i].y = Math.round((drag.y + drag.dy) * 2) / 2; blocks(); preview(); } drag = null; });
    window.ldSubmit = function (what) { document.getElementById('ldDo').value = what; document.getElementById('ldSettings').value = JSON.stringify(S); };
    window.ldPdf = function () {
        var g = document.createElement('form'); g.method = 'POST'; g.target = '_blank'; g.action = location.pathname + location.search;
        [['csrf', csrf], ['id', '<?= $id; ?>'], ['do', 'pdf'], ['with', document.getElementById('ldWith').value], ['settings', JSON.stringify(S)]].forEach(function (kv) { var i = document.createElement('input'); i.type = 'hidden'; i.name = kv[0]; i.value = kv[1]; g.appendChild(i); });
        document.body.appendChild(g); g.submit(); g.remove();
    };
    render();
})();
</script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>