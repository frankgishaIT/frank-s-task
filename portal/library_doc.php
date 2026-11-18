<?php
/** NEW FILE: portal/library_doc.php — one RM Digital Library document for a customer: details and preview (only if it is opened to them). */
require __DIR__ . '/portal_start.php';
require_once __DIR__ . '/../includes/lib_access.php';
if (empty($GLOBALS['PORTAL_LIBRARY_ON'])) { header('Location: index.php'); exit; }
lib_access_ensure($conn);
$d = lib_customer_document($conn, $cid, (int) ($_GET['id'] ?? 0));
if (!$d) { portal_header('RM Digital Library', $me); echo '<h1 class="p-title">RM Digital Library</h1><div class="card"><div class="card-body text-center text-muted py-5">This document is not available to you. <a href="library.php">Back to the library</a></div></div>'; portal_footer(); exit; }
$ext = strtolower(pathinfo((string) $d['original_name'], PATHINFO_EXTENSION));
$view = in_array($ext, LIB_VIEWABLE, true);
$isImg = in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true);
portal_header($d['title'], $me);
?>
<a href="library.php" class="small text-decoration-none"><i class="bi bi-arrow-left"></i> RM Digital Library</a>
<h1 class="h5 mt-2 mb-1" style="font-size:1.3rem;"><?= portal_e($d['title']); ?></h1>
<div class="small text-muted mb-2"><?= portal_e(LIB_KINDS[$d['kind']] ?? 'Document'); ?> · <?= date('d M Y', strtotime($d['published_at'] ?: $d['created_at'])); ?> · <?= strtoupper(portal_e($ext)); ?> · <?= lib_size((int) $d['size_bytes']); ?>
    <?= $d['due_date'] ? ' · <strong class="text-dark">due ' . date('d M Y', strtotime($d['due_date'])) . '</strong>' : ''; ?></div>
<?php if ($d['description']) { ?><div class="card mb-3"><div class="card-body py-2"><?= nl2br(portal_e($d['description'])); ?></div></div><?php } ?>
<div class="d-flex flex-wrap gap-2 mb-3">
    <?php if ($view) { ?><a href="library_file.php?id=<?= (int) $d['id']; ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-primary"><i class="bi bi-box-arrow-up-right"></i> Open full screen</a><?php } ?>
    <?php if ($d['allow_download']) { ?><a href="library_file.php?id=<?= (int) $d['id']; ?>&amp;mode=download" class="btn btn-sm btn-primary"><i class="bi bi-download"></i> Download</a><?php } ?>
</div>
<?php if ($view) { ?>
<div class="card"><div class="card-body p-1"><?php if ($isImg) { ?><img src="library_file.php?id=<?= (int) $d['id']; ?>" alt="" style="max-width:100%;height:auto;display:block;margin:auto;"><?php } else { ?>
    <iframe src="library_file.php?id=<?= (int) $d['id']; ?>" title="<?= portal_e($d['title']); ?>" style="width:100%;height:75vh;border:0;border-radius:10px;"></iframe><?php } ?></div></div>
<?php } elseif (!$d['allow_download']) { ?><div class="alert alert-light border">This type of file cannot be shown in the browser. Please contact RISE MOTIVE if you need it.</div>
<?php } else { ?><div class="alert alert-light border">This type of file (<?= strtoupper(portal_e($ext)); ?>) opens after downloading it.</div><?php } ?>
<?php portal_footer();