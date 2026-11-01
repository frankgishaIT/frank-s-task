<?php
/**
 * NEW FILE: modules/kpi/reviews.php — performance reviews (weekly .. annual appraisal) with self- and manager
 * assessment, performance improvement plans, recognition & rewards. Employees see only their own.
 */
require __DIR__ . '/kpi_common.php';
require_once __DIR__ . '/../../includes/notification_helper.php';
$isMgr = access_can('kpi.approve', $conn);
$canAward = access_can('kpi.edit', $conn);
$types = ['daily' => 'Daily monitoring', 'weekly' => 'Weekly review', 'monthly' => 'Monthly review', 'quarterly' => 'Quarterly evaluation', 'annual' => 'Annual appraisal'];
$ratings = array_column(kpi_ratings($conn), 'name');
/** The employee's weighted score for a period (their own, team and department KPIs). */
function kpi_person_score(mysqli $conn, int $uid, string $end): ?float {
    $dep = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT department_id FROM users WHERE id = ' . $uid))[0] ?? 0);
    $teams = array_map('intval', array_column(mysqli_fetch_all(mysqli_query($conn, 'SELECT team_id FROM kpi_team_members WHERE user_id = ' . $uid), MYSQLI_ASSOC), 'team_id'));
    $ks = array_filter(kpi_list($conn), function ($k) use ($uid, $dep, $teams) {
        return ($k['level'] === 'employee' && (int) $k['user_id'] === $uid) || ($k['level'] === 'team' && in_array((int) $k['team_id'], $teams, true)) || ($k['level'] === 'department' && $dep && (int) $k['department_id'] === $dep); });
    return kpi_weighted(kpi_results($conn, array_values($ks), min($end, date('Y-m-d'))));
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = (string) ($_POST['action'] ?? '');
    try {
        if ($a === 'review_create') {
            if (!$isMgr) { throw new RuntimeException('Only managers can open reviews.'); }
            $uid = (int) $_POST['user_id']; $type = isset($types[$_POST['review_type'] ?? '']) ? $_POST['review_type'] : 'monthly';
            $start = kpi_period_start((string) $_POST['period'], $type === 'annual' ? 'annual' : $type); $end = kpi_period_end($start, $type === 'annual' ? 'annual' : $type);
            if (!$uid) { throw new RuntimeException('Choose the employee.'); }
            $score = kpi_person_score($conn, $uid, $end); $rating = kpi_rating($conn, $score);
            $s = mysqli_prepare($conn, 'INSERT INTO kpi_reviews (user_id, review_type, period_start, period_end, score, rating, manager_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            mysqli_stmt_bind_param($s, 'isssdsii', $uid, $type, $start, $end, $score, $rating, $me, $me);
            mysqli_stmt_execute($s);
            try { notifyUser($conn, $uid, 'Performance review opened', $types[$type] . ' for ' . kpi_period_label($start, $type === 'annual' ? 'annual' : $type) . '. Please add your self-assessment in My Performance.'); } catch (Throwable $x) {}
            $msg = 'Review opened. Score from the KPIs: ' . ($score === null ? 'no data yet' : $score . '% (' . $rating . ')') . '.';
        } elseif ($a === 'self') {
            $rid = (int) $_POST['id']; $txt = trim((string) $_POST['text']);
            $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM kpi_reviews WHERE id = $rid"));
            if (!$r || (int) $r['user_id'] !== $me) { throw new RuntimeException('That review is not yours.'); }
            if ($r['status'] === 'Completed') { throw new RuntimeException('This review is completed.'); }
            if ($txt === '') { throw new RuntimeException('Write your self-assessment.'); }
            $s = mysqli_prepare($conn, "UPDATE kpi_reviews SET self_assessment = ?, self_at = NOW(), status = 'Self-assessed' WHERE id = ?");
            mysqli_stmt_bind_param($s, 'si', $txt, $rid); mysqli_stmt_execute($s);
            if ($r['manager_id']) { try { notifyUser($conn, (int) $r['manager_id'], 'Self-assessment submitted', 'An employee submitted the self-assessment of a performance review.'); } catch (Throwable $x) {} }
            $msg = 'Self-assessment submitted.';
        } elseif ($a === 'manager') {
            if (!$isMgr) { throw new RuntimeException('Only managers can complete reviews.'); }
            $rid = (int) $_POST['id']; $txt = trim((string) $_POST['text']); $rt = in_array($_POST['rating'] ?? '', $ratings, true) ? $_POST['rating'] : null;
            $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM kpi_reviews WHERE id = $rid"));
            if (!$r) { throw new RuntimeException('Review not found.'); }
            if ((int) $r['user_id'] === $me) { throw new RuntimeException('Your own review must be completed by another manager.'); }
            if ($txt === '' || !$rt) { throw new RuntimeException('Write the assessment and choose the final rating.'); }
            $s = mysqli_prepare($conn, "UPDATE kpi_reviews SET manager_assessment = ?, manager_rating = ?, manager_id = ?, manager_at = NOW(), status = 'Completed' WHERE id = ?");
            mysqli_stmt_bind_param($s, 'ssii', $txt, $rt, $me, $rid); mysqli_stmt_execute($s);
            try { notifyUser($conn, (int) $r['user_id'], 'Performance review completed', 'Your review was completed: ' . $rt . '. See My Performance.'); } catch (Throwable $x) {}
            $msg = 'Review completed.';
        } elseif ($a === 'pip_create') {
            if (!$isMgr) { throw new RuntimeException('Only managers can open improvement plans.'); }
            $uid = (int) $_POST['user_id']; $t = mb_substr(trim((string) $_POST['title']), 0, 160); $reason = trim((string) $_POST['reason']); $goals = trim((string) $_POST['goals']);
            $sd = (string) $_POST['start_date']; $ed = (string) $_POST['end_date'];
            if (!$uid || $t === '' || $reason === '' || $goals === '' || !DateTime::createFromFormat('Y-m-d', $sd) || !DateTime::createFromFormat('Y-m-d', $ed) || $ed < $sd) { throw new RuntimeException('Fill in the employee, title, reason, goals and valid dates.'); }
            $s = mysqli_prepare($conn, 'INSERT INTO kpi_pips (user_id, title, reason, goals, start_date, end_date, manager_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
            mysqli_stmt_bind_param($s, 'isssssi', $uid, $t, $reason, $goals, $sd, $ed, $me); mysqli_stmt_execute($s);
            try { notifyUser($conn, $uid, 'Performance improvement plan', '"' . $t . '" was opened for you. See My Performance.'); } catch (Throwable $x) {}
            $msg = 'Improvement plan opened.';
        } elseif ($a === 'pip_update') {
            $pid = (int) $_POST['id']; $p = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM kpi_pips WHERE id = $pid"));
            if (!$p) { throw new RuntimeException('Plan not found.'); }
            if ((int) $p['user_id'] === $me && !$isMgr) {
                $c = trim((string) $_POST['employee_comment']); $s = mysqli_prepare($conn, 'UPDATE kpi_pips SET employee_comment = ? WHERE id = ?'); mysqli_stmt_bind_param($s, 'si', $c, $pid); mysqli_stmt_execute($s);
            } elseif ($isMgr) {
                $st = in_array($_POST['status'] ?? '', ['Active', 'Extended', 'Completed', 'Closed'], true) ? $_POST['status'] : $p['status']; $out = trim((string) ($_POST['outcome'] ?? ''));
                $s = mysqli_prepare($conn, 'UPDATE kpi_pips SET status = ?, outcome = ? WHERE id = ?'); mysqli_stmt_bind_param($s, 'ssi', $st, $out, $pid); mysqli_stmt_execute($s);
            } else { throw new RuntimeException('That plan is not yours.'); }
            $msg = 'Plan saved.';
        } elseif ($a === 'award') {
            if (!$canAward) { throw new RuntimeException('You cannot give recognitions.'); }
            $uid = (int) $_POST['user_id']; $t = mb_substr(trim((string) $_POST['title']), 0, 160); $reason = trim((string) $_POST['reason']); $reward = mb_substr(trim((string) ($_POST['reward'] ?? '')), 0, 160) ?: null;
            if (!$uid || $t === '' || $reason === '') { throw new RuntimeException('Choose the employee, and give a title and the reason.'); }
            $s = mysqli_prepare($conn, 'INSERT INTO kpi_recognitions (user_id, title, reason, reward, awarded_by) VALUES (?, ?, ?, ?, ?)');
            mysqli_stmt_bind_param($s, 'isssi', $uid, $t, $reason, $reward, $me); mysqli_stmt_execute($s);
            try { notifyUser($conn, $uid, 'Recognition: ' . $t, $reason . ($reward ? ' Reward: ' . $reward . '.' : '')); } catch (Throwable $x) {}
            $msg = 'Recognition recorded and the employee notified.';
        } else { $msg = ''; }
        header('Location: reviews.php?success=' . urlencode($msg)); exit;
    } catch (RuntimeException $ex) { $error = $ex->getMessage(); }
}
$scope = $isMgr ? '1 = 1' : 'x.user_id = ' . $me;
$reviews = mysqli_fetch_all(mysqli_query($conn, "SELECT x.*, u.names, m.names AS manager FROM kpi_reviews x JOIN users u ON u.id = x.user_id LEFT JOIN users m ON m.id = x.manager_id WHERE $scope ORDER BY x.id DESC LIMIT 200"), MYSQLI_ASSOC);
$pips = mysqli_fetch_all(mysqli_query($conn, "SELECT x.*, u.names FROM kpi_pips x JOIN users u ON u.id = x.user_id WHERE $scope ORDER BY x.id DESC"), MYSQLI_ASSOC);
$awards = mysqli_fetch_all(mysqli_query($conn, "SELECT x.*, u.names, a.names AS by_name FROM kpi_recognitions x JOIN users u ON u.id = x.user_id LEFT JOIN users a ON a.id = x.awarded_by WHERE $scope ORDER BY x.id DESC"), MYSQLI_ASSOC);
$people = mysqli_fetch_all(mysqli_query($conn, 'SELECT id, names FROM users WHERE is_active = 1 ORDER BY names'), MYSQLI_ASSOC);
$sel = function () use ($people, $e) { $o = '<option value="">Employee</option>'; foreach ($people as $p) { $o .= '<option value="' . (int) $p['id'] . '">' . $e($p['names']) . '</option>'; } return $o; };
include '../../includes/header.php'; include '../../includes/sidebar.php';
kpi_tabs('reviews.php');
?>
<?php if (isset($error)) { ?><div class="alert alert-danger"><?= $e($error); ?></div><?php } ?>
<?php if ($isMgr) { ?>
<div class="row g-3 mb-3">
    <div class="col-lg-4"><form method="POST" class="card border-0 shadow-sm h-100"><div class="card-body"><input type="hidden" name="action" value="review_create"><h6>Open a review</h6>
        <select name="user_id" class="form-select form-select-sm mb-2" required><?= $sel(); ?></select>
        <select name="review_type" class="form-select form-select-sm mb-2"><?php foreach ($types as $k => $l) { ?><option value="<?= $k; ?>" <?= $k === 'monthly' ? 'selected' : ''; ?>><?= $l; ?></option><?php } ?></select>
        <label class="small text-muted">Any date in the period</label><input type="date" name="period" class="form-control form-control-sm mb-2" value="<?= date('Y-m-d'); ?>">
        <button class="btn btn-primary btn-sm">Open review</button><div class="small text-muted mt-1">The score is taken from the employee's KPIs.</div></div></form></div>
    <div class="col-lg-4"><form method="POST" class="card border-0 shadow-sm h-100"><div class="card-body"><input type="hidden" name="action" value="pip_create"><h6>Performance improvement plan</h6>
        <select name="user_id" class="form-select form-select-sm mb-2" required><?= $sel(); ?></select>
        <input name="title" class="form-control form-control-sm mb-2" maxlength="160" required placeholder="Title">
        <textarea name="reason" class="form-control form-control-sm mb-2" rows="2" required placeholder="Why (which results fell short)"></textarea>
        <textarea name="goals" class="form-control form-control-sm mb-2" rows="2" required placeholder="Goals and support given"></textarea>
        <div class="d-flex gap-2 mb-2"><input type="date" name="start_date" class="form-control form-control-sm" value="<?= date('Y-m-d'); ?>"><input type="date" name="end_date" class="form-control form-control-sm" value="<?= date('Y-m-d', strtotime('+60 days')); ?>"></div>
        <button class="btn btn-primary btn-sm">Open plan</button></div></form></div>
    <div class="col-lg-4"><form method="POST" class="card border-0 shadow-sm h-100"><div class="card-body"><input type="hidden" name="action" value="award"><h6>Recognition &amp; reward</h6>
        <select name="user_id" class="form-select form-select-sm mb-2" required><?= $sel(); ?></select>
        <input name="title" class="form-control form-control-sm mb-2" maxlength="160" required placeholder="e.g. Best sales of the quarter">
        <textarea name="reason" class="form-control form-control-sm mb-2" rows="2" required placeholder="Why"></textarea>
        <input name="reward" class="form-control form-control-sm mb-2" maxlength="160" placeholder="Reward (optional), e.g. bonus RWF 50,000">
        <button class="btn btn-success btn-sm">Recognise</button></div></form></div>
</div>
<?php } ?>
<div class="card border-0 shadow-sm mb-3"><div class="card-body"><h6>Reviews</h6><div class="table-responsive"><table class="table table-sm mb-0 align-middle" style="font-size:13px;">
    <tr><th>Employee</th><th>Review</th><th>Period</th><th>KPI score</th><th>Status</th><th style="width:40%;"></th></tr>
    <?php if (!$reviews) { ?><tr><td colspan="6" class="text-center text-muted py-3">No reviews yet.</td></tr><?php } ?>
    <?php foreach ($reviews as $r) { $f = $r['review_type'] === 'annual' ? 'annual' : $r['review_type']; ?>
    <tr><td><?= $e($r['names']); ?></td><td><?= $e($types[$r['review_type']] ?? $r['review_type']); ?></td><td class="small"><?= $e(kpi_period_label($r['period_start'], $f)); ?></td>
        <td><?= $r['score'] !== null ? $e($r['score'] . '% · ' . $r['rating']) : '—'; ?></td>
        <td><span class="badge <?= $r['status'] === 'Completed' ? 'bg-success' : 'bg-primary'; ?>"><?= $e($r['status']); ?></span><?= $r['manager_rating'] ? '<div class="small">' . $e($r['manager_rating']) . '</div>' : ''; ?></td>
        <td class="small">
            <?php if ($r['self_assessment']) { ?><div><strong>Self:</strong> <?= nl2br($e($r['self_assessment'])); ?></div><?php } ?>
            <?php if ($r['manager_assessment']) { ?><div><strong>Manager (<?= $e($r['manager']); ?>):</strong> <?= nl2br($e($r['manager_assessment'])); ?></div><?php } ?>
            <?php if ($r['status'] !== 'Completed' && (int) $r['user_id'] === $me) { ?>
                <form method="POST"><input type="hidden" name="action" value="self"><input type="hidden" name="id" value="<?= (int) $r['id']; ?>"><textarea name="text" class="form-control form-control-sm mb-1" rows="2" placeholder="Your self-assessment"><?= $e($r['self_assessment']); ?></textarea><button class="btn btn-sm btn-outline-primary">Submit self-assessment</button></form>
            <?php } elseif ($r['status'] !== 'Completed' && $isMgr) { ?>
                <form method="POST"><input type="hidden" name="action" value="manager"><input type="hidden" name="id" value="<?= (int) $r['id']; ?>"><textarea name="text" class="form-control form-control-sm mb-1" rows="2" placeholder="Manager assessment"></textarea>
                    <div class="d-flex gap-1"><select name="rating" class="form-select form-select-sm"><option value="">Final rating</option><?php foreach ($ratings as $rt) { ?><option <?= $rt === $r['rating'] ? 'selected' : ''; ?>><?= $e($rt); ?></option><?php } ?></select><button class="btn btn-sm btn-primary">Complete</button></div></form>
            <?php } ?></td></tr>
    <?php } ?>
</table></div></div></div>
<div class="row g-3">
    <div class="col-lg-7"><div class="card border-0 shadow-sm"><div class="card-body"><h6>Improvement plans</h6>
        <?php if (!$pips) { ?><div class="small text-muted">None.</div><?php } ?>
        <?php foreach ($pips as $p) { ?><div class="border-top py-2 small"><strong><?= $e($p['title']); ?></strong> · <?= $e($p['names']); ?> · <?= date('d M', strtotime($p['start_date'])); ?>–<?= date('d M Y', strtotime($p['end_date'])); ?> <span class="badge bg-primary"><?= $e($p['status']); ?></span>
            <div><strong>Why:</strong> <?= $e($p['reason']); ?></div><div><strong>Goals:</strong> <?= nl2br($e($p['goals'])); ?></div>
            <?php if ($p['employee_comment']) { ?><div><strong>Employee:</strong> <?= $e($p['employee_comment']); ?></div><?php } ?><?php if ($p['outcome']) { ?><div><strong>Outcome:</strong> <?= $e($p['outcome']); ?></div><?php } ?>
            <form method="POST" class="d-flex gap-1 mt-1"><input type="hidden" name="action" value="pip_update"><input type="hidden" name="id" value="<?= (int) $p['id']; ?>">
                <?php if ($isMgr && (int) $p['user_id'] !== $me) { ?><select name="status" class="form-select form-select-sm" style="max-width:130px;"><?php foreach (['Active', 'Extended', 'Completed', 'Closed'] as $st) { ?><option <?= $p['status'] === $st ? 'selected' : ''; ?>><?= $st; ?></option><?php } ?></select><input name="outcome" class="form-control form-control-sm" placeholder="Outcome" value="<?= $e($p['outcome']); ?>">
                <?php } else { ?><input name="employee_comment" class="form-control form-control-sm" placeholder="Your comment" value="<?= $e($p['employee_comment']); ?>"><?php } ?>
                <button class="btn btn-sm btn-outline-primary">Save</button></form></div><?php } ?>
    </div></div></div>
    <div class="col-lg-5"><div class="card border-0 shadow-sm"><div class="card-body"><h6>Recognition &amp; rewards</h6>
        <?php if (!$awards) { ?><div class="small text-muted">None yet.</div><?php } ?>
        <?php foreach ($awards as $w) { ?><div class="border-top py-2 small"><i class="bi bi-award text-success"></i> <strong><?= $e($w['title']); ?></strong> · <?= $e($w['names']); ?><div><?= $e($w['reason']); ?><?= $w['reward'] ? ' · <strong>' . $e($w['reward']) . '</strong>' : ''; ?></div><div class="text-muted"><?= date('d M Y', strtotime($w['created_at'])); ?> · <?= $e($w['by_name']); ?></div></div><?php } ?>
    </div></div></div>
</div>
<?php include '../../includes/footer.php'; ?>