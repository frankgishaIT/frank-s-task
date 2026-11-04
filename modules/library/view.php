<?php
/**
 * NEW FILE: modules/library/view.php — in-browser preview of Word (.docx) and Excel (.xlsx / .xls / .csv) documents,
 * converted IN THE BROWSER (nothing is sent to an outside service). PowerPoint, Publisher and old Word (.doc) cannot be
 * previewed this way: the page says so and offers the download. If a preview fails, the reason and Download are shown.
 */
require '../../config/db.php';
require_once '../../includes/library_helpers.php';
lib_ensure($conn);
lib_guard($conn);
$vid = filter_input(INPUT_GET, 'v', FILTER_VALIDATE_INT);
$v = $vid ? mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM lib_versions WHERE id = ' . (int) $vid)) : null;
$doc = $v ? lib_document($conn, (int) $v['document_id'], lib_can('delete')) : null;
$e = function ($x) { return htmlspecialchars((string) $x, ENT_QUOTES, 'UTF-8'); };
$ext = $v ? strtolower(pathinfo($v['original_name'], PATHINFO_EXTENSION)) : '';
$canDl = lib_can('download');
$kind = in_array($ext, ['docx'], true) ? 'word' : (in_array($ext, ['xlsx', 'xls', 'csv'], true) ? 'sheet' : 'none');
$names = ['ppt' => 'PowerPoint', 'pptx' => 'PowerPoint', 'pub' => 'Publisher', 'doc' => 'Word 97-2003 (.doc)'];
?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= $e($v['original_name'] ?? 'Preview'); ?></title>
<style>
  body { font-family: 'Segoe UI', system-ui, sans-serif; margin: 0; color: #1E2333; background: #fff; }
  .msg { margin: 24px; padding: 16px; border-radius: 10px; background: #EEF3FF; border: 1px solid #C7D2FE; }
  .msg.err { background: #FDECEC; border-color: #F5B5B5; }
  .btn { display: inline-block; margin-top: 10px; padding: 8px 16px; background: #1E2FE0; color: #fff; text-decoration: none; border-radius: 8px; font-weight: 600; }
  #doc { padding: 24px 32px; max-width: 900px; margin: auto; line-height: 1.55; } #doc img { max-width: 100%; } #doc table { border-collapse: collapse; } #doc td, #doc th { border: 1px solid #D8DCE8; padding: 4px 8px; }
  .tabs { display: flex; gap: 6px; padding: 10px 12px 0; flex-wrap: wrap; border-bottom: 1px solid #D8DCE8; }
  .tabs button { border: 1px solid #D8DCE8; border-bottom: 0; background: #F6F8FC; padding: 6px 12px; border-radius: 8px 8px 0 0; cursor: pointer; font: inherit; }
  .tabs button.on { background: #1E2FE0; color: #fff; border-color: #1E2FE0; }
  #sheet { overflow: auto; padding: 8px; } #sheet table { border-collapse: collapse; font-size: 13px; } #sheet td { border: 1px solid #E4E8F2; padding: 3px 8px; white-space: nowrap; }
</style></head><body>
<?php if (!$doc) { ?><div class="msg err"><strong>This document cannot be opened.</strong> It does not exist, or you do not have access to it.</div>
<?php } elseif ($kind === 'none') { ?>
  <div class="msg"><strong><?= $e($names[$ext] ?? strtoupper($ext)); ?> files cannot be previewed in the browser.</strong><br><?= $e($v['original_name']); ?> can be opened after downloading it<?= $canDl ? '' : ' (ask a Library manager for the Download permission)'; ?>.
    <?php if ($canDl) { ?><br><a class="btn" href="file.php?v=<?= (int) $vid; ?>&mode=download" target="_top">Download</a><?php } ?></div>
<?php } else { ?>
  <div id="status" class="msg">Opening the preview…</div>
  <?php if ($kind === 'sheet') { ?><div class="tabs" id="tabs"></div><div id="sheet"></div><?php } else { ?><div id="doc"></div><?php } ?>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/<?= $kind === 'word' ? 'mammoth/1.6.0/mammoth.browser.min.js' : 'xlsx/0.18.5/xlsx.full.min.js'; ?>"></script>
  <script>
  (function () {
      var status = document.getElementById('status');
      function fail(why) {
          status.className = 'msg err';
          status.innerHTML = '<strong>The preview could not be shown.</strong> ' + why + <?= json_encode($canDl ? '<br><a class="btn" href="file.php?v=' . (int) $vid . '&mode=download" target="_top">Download the file instead</a>' : ''); ?>;
      }
      if (<?= $kind === 'word' ? '!window.mammoth' : '!window.XLSX'; ?>) { fail('The preview tool could not be loaded (no internet connection?).'); return; }
      fetch('file.php?v=<?= (int) $vid; ?>&mode=raw', { credentials: 'same-origin' }).then(function (r) {
          if (!r.ok) { throw new Error('the server answered ' + r.status); }
          return r.arrayBuffer();
      }).then(function (buf) {
          <?php if ($kind === 'word') { ?>
          return mammoth.convertToHtml({ arrayBuffer: buf }).then(function (res) { document.getElementById('doc').innerHTML = res.value || '<em>The document is empty.</em>'; status.remove(); });
          <?php } else { ?>
          var wb = XLSX.read(buf, { type: 'array' }), tabs = document.getElementById('tabs'), out = document.getElementById('sheet');
          function show(n) {
              out.innerHTML = XLSX.utils.sheet_to_html(wb.Sheets[n], { editable: false });
              Array.prototype.forEach.call(tabs.children, function (b) { b.className = b.textContent === n ? 'on' : ''; });
          }
          wb.SheetNames.forEach(function (n) { var b = document.createElement('button'); b.textContent = n; b.onclick = function () { show(n); }; tabs.appendChild(b); });
          show(wb.SheetNames[0]); status.remove();
          <?php } ?>
      }).catch(function (err) { fail('The file may be damaged or in a format the preview does not support (' + (err && err.message ? err.message : 'unknown error') + ').'); });
  })();
  </script>
<?php } ?>
</body></html>