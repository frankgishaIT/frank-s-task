<?php
/**
 * NEW FILE: modules/sms/log.php — SMS DELIVERY LOG: every SMS attempt (automatic, manual, test) with notification,
 * recipient, date, text and template version, status, segments, module and branch. Filters + CSV export.
 */
require __DIR__ . '/sms_common.php';
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-d', strtotime('-30 days'));
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
$fe = (string) ($_GET['event'] ?? ''); $fs = (string) ($_GET['status'] ?? ''); $fr = (string) ($_GET['recipient'] ?? ''); $fb = (int) ($_GET['branch'] ?? 0); $fq = trim((string) ($_GET['q'] ?? ''));
$fm = (string) ($_GET['module'] ?? '');
$cat = sms_catalog();
$w = ["l.created_at >= '" . $from . " 00:00:00'", "l.created_at <= '" . $to . " 23:59:59'"]; $types = ''; $p = [];
if ($fe !== '') {
    $evs = [$fe]; if (!empty($cat[$fe]['legacy'])) { $evs[] = $cat[$fe]['legacy']; }
    $in = "'" . implode("','", array_map(function ($x) use ($conn) { return mysqli_real_escape_string($conn, $x); }, $evs)) . "'";
    $w[] = $fe === 'TIMETABLE' ? "l.event LIKE 'TIMETABLE%'" : "(l.event IN ($in) OR l.event = '" . mysqli_real_escape_string($conn, $fe) . "_COPY')";
}
if ($fs === 'not_sent') { $w[] = "l.status NOT IN ('sent', 'delivered', 'queued')"; } elseif ($fs !== '' && isset(SMS_STATUSES[$fs])) { $w[] = 'l.status = ?'; $types .= 's'; $p[] = $fs; }
if ($fr !== '') { $w[] = 'l.recipient_type = ?'; $types .= 's'; $p[] = $fr; }
if ($fb) { $w[] = 'l.branch_id = ' . $fb; }
if ($fm !== '') { $w[] = 'l.module = ?'; $types .= 's'; $p[] = $fm; }
if ($fq !== '') { $w[] = '(l.phone LIKE ? OR l.message LIKE ?)'; $types .= 'ss'; $p[] = '%' . $fq . '%'; $p[] = '%' . $fq . '%'; }
$where = implode(' AND ', $w);
$run = function (string $sql) use ($conn, $types, $p) { $s = mysqli_prepare($conn, $sql); if ($types !== '') { mysqli_stmt_bind_param($s, $types, ...$p); } mysqli_stmt_execute($s); return mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC); };
$branches = smsm_branches($conn);
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="sms-log-' . $from . '-' . $to . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['Date and time', 'Notification', 'Module', 'Recipient category', 'Phone', 'Branch', 'Status', 'Reason / provider', 'Segments', 'Template version', 'Message', 'By']);
    foreach ($run("SELECT l.*, u.names FROM sms_log l LEFT JOIN users u ON u.id = l.created_by WHERE $where ORDER BY l.id DESC LIMIT 50000") as $r) {
        fputcsv($o, [$r['created_at'], sms_event_label($r['event']), $r['module'] ?? sms_event_module($r['event']), SMS_RECIPIENTS[$r['recipient_type'] ?? ''] ?? ($r['recipient_type'] ?? ''), $r['phone'], $branches[(int) ($r['branch_id'] ?? 0)] ?? '', $r['status'], $r['error'], $r['segments'], $r['template_version'], $r['message'], $r['names']]);
    }
    exit;
}
$page = max(1, (int) ($_GET['p'] ?? 1)); $per = 100;
$total = (int) ($run("SELECT COUNT(*) n FROM sms_log l WHERE $where")[0]['n'] ?? 0);
$rows = $run("SELECT l.*, u.names FROM sms_log l LEFT JOIN users u ON u.id = l.created_by WHERE $where ORDER BY l.id DESC LIMIT $per OFFSET " . (($page - 1) * $per));
$qs = function (array $chg) { return 'log.php?' . http_build_query(array_merge($_GET, $chg)); };
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
smsm_tabs($conn, 'log.php', 'SMS delivery log');
?>
<form class="card border-0 shadow-sm mb-3"><div class="card-body py-2 row g-2 align-items-end small">
    <div class="col-6 col-md-2"><label class="form-label small mb-0">From</label><input type="date" name="from" value="<?= smsm_e($from); ?>" class="form-control form-control-sm"></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-0">To</label><input type="date" name="to" value="<?= smsm_e($to); ?>" class="form-control form-control-sm"></div>
    <div class="col-md-3"><label class="form-label small mb-0">Notification</label><select name="event" class="form-select form-select-sm"><option value="">All</option><?php foreach ($cat as $k => $c) { ?><option value="<?= smsm_e($k); ?>" <?= $fe === $k ? 'selected' : ''; ?>><?= smsm_e($c['module'] . ': ' . $c['name']); ?></option><?php } ?><option value="MANUAL" <?= $fe === 'MANUAL' ? 'selected' : ''; ?>>Manual SMS</option></select></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-0">Recipient</label><select name="recipient" class="form-select form-select-sm"><option value="">All</option><?php foreach (SMS_RECIPIENTS as $k => $l) { ?><option value="<?= $k; ?>" <?= $fr === $k ? 'selected' : ''; ?>><?= smsm_e($l); ?></option><?php } ?></select></div>
    <div class="col-6 col-md-2"><label class="form-label small mb-0">Status</label><select name="status" class="form-select form-select-sm"><option value="">All</option><option value="not_sent" <?= $fs === 'not_sent' ? 'selected' : ''; ?>>All not sent</option><?php foreach (SMS_STATUSES as $k => $l) { ?><option value="<?= $k; ?>" <?= $fs === $k ? 'selected' : ''; ?>><?= smsm_e($l); ?></option><?php } ?></select></div>
    <?php if ($branches) { ?><div class="col-6 col-md-2"><label class="form-label small mb-0">Branch</label><select name="branch" class="form-select form-select-sm"><option value="0">All</option><?php foreach ($branches as $id => $n) { ?><option value="<?= (int) $id; ?>" <?= $fb === (int) $id ? 'selected' : ''; ?>><?= smsm_e($n); ?></option><?php } ?></select></div><?php } ?>
    <div class="col-6 col-md-3"><label class="form-label small mb-0">Phone or text</label><input name="q" value="<?= smsm_e($fq); ?>" class="form-control form-control-sm"></div>
    <div class="col-md-3 d-flex gap-1"><button class="btn btn-sm btn-primary">Filter</button><a class="btn btn-sm btn-light border" href="log.php">Reset</a><a class="btn btn-sm btn-outline-secondary" href="<?= smsm_e($qs(['export' => 'csv'])); ?>">CSV</a></div>
</div></form>
<div class="small text-muted mb-1"><?= number_format($total); ?> SMS attempt(s). "Sent" = accepted by Pindo for delivery (Pindo does not report the final delivery to the phone back to MY MOTIVE).</div>
<div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm align-middle mb-0" style="font-size:12px;">
    <tr class="table-light"><th class="ps-3">When</th><th>Notification</th><th>Recipient</th><th>Branch</th><th>Status</th><th class="text-end">Seg.</th><th>Message</th></tr>
    <?php foreach ($rows as $r) { ?><tr>
        <td class="ps-3 text-nowrap"><?= smsm_e(substr($r['created_at'], 0, 16)); ?></td>
        <td><?= smsm_e(sms_event_label($r['event'])); ?><div class="text-muted"><?= smsm_e($r['module'] ?? sms_event_module($r['event'])); ?><?= $r['template_version'] ? ' · v' . (int) $r['template_version'] : ''; ?><?= $r['names'] ? ' · ' . smsm_e($r['names']) : ''; ?></div></td>
        <td class="text-nowrap"><?= smsm_e($r['phone']); ?><div class="text-muted"><?= smsm_e(SMS_RECIPIENTS[$r['recipient_type'] ?? ''] ?? ($r['recipient_type'] ?? '')); ?></div></td>
        <td><?= smsm_e($branches[(int) ($r['branch_id'] ?? 0)] ?? ''); ?></td>
        <td><?= smsm_badge((string) $r['status']); ?><?= $r['error'] ? '<div class="text-muted" style="max-width:200px;">' . smsm_e($r['error']) . '</div>' : ''; ?></td>
        <td class="text-end"><?= (int) $r['segments']; ?></td>
        <td><details><summary class="text-truncate d-inline-block" style="max-width:280px;vertical-align:bottom;"><?= smsm_e(mb_substr($r['message'], 0, 60)); ?></summary><div style="white-space:pre-wrap;max-width:420px;"><?= smsm_e($r['message']); ?></div></details></td></tr><?php } ?>
    <?php if (!$rows) { ?><tr><td colspan="7" class="text-center text-muted py-4">No SMS for these filters.</td></tr><?php } ?>
</table></div></div></div>
<?php if ($total > $per) { ?><div class="d-flex gap-2 mt-2 small"><?php if ($page > 1) { ?><a href="<?= smsm_e($qs(['p' => $page - 1])); ?>">← newer</a><?php } ?><span>Page <?= $page; ?> of <?= (int) ceil($total / $per); ?></span><?php if ($page * $per < $total) { ?><a href="<?= smsm_e($qs(['p' => $page + 1])); ?>">older →</a><?php } ?></div><?php } ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>