<?php
// NEW FILE: portal/records.php — scores / grades / other records, only the types RISE MOTIVE switched on.
require __DIR__ . '/portal_start.php';
if (empty($GLOBALS['PORTAL_RECORDS_ON'])) { header('Location: index.php'); exit; }
$rows = mysqli_fetch_all(mysqli_query($conn, "SELECT r.*, t.name AS type_name FROM portal_records r JOIN portal_record_types t ON t.id = r.type_id
    WHERE r.customer_id = $cid AND t.is_enabled = 1 ORDER BY t.name, r.record_date DESC, r.id DESC"), MYSQLI_ASSOC);
portal_header('My records', $me);
?>
<h1 class="h5 mb-3">My records</h1>
<?php if (!$rows) { ?><div class="card"><div class="card-body text-muted">No records yet.</div></div><?php } ?>
<?php $type = null; foreach ($rows as $r) { if ($r['type_name'] !== $type) { if ($type !== null) { echo '</table></div></div></div>'; } $type = $r['type_name']; ?>
<div class="card mb-3"><div class="card-body"><h2 class="h6"><?= portal_e($type); ?></h2><div class="table-responsive"><table class="table mb-0"><tr><th>Date</th><th>Title</th><th>Result</th><th>Notes</th></tr>
<?php } ?>
<tr><td><?= $r['record_date'] ? date('d M Y', strtotime($r['record_date'])) : '—'; ?></td><td><?= portal_e($r['title']); ?></td><td class="fw-semibold"><?= portal_e($r['value']); ?></td><td class="small"><?= portal_e($r['notes']); ?></td></tr>
<?php } if ($type !== null) { echo '</table></div></div></div>'; } ?>
<?php portal_footer();