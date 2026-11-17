<?php
/**
 * NEW FILE: modules/loan_intel/recommendations.php — MANAGEMENT RECOMMENDATIONS (spec 8).
 * Each recommendation is a saved snapshot (the data at that moment) with the 14 required items and the formulas.
 * Advisory only: accepting one records management's view; it never creates a loan, a payment or a transaction.
 */
require __DIR__ . '/common.php';
li_require($conn, 'view');
$me = (int) current_user_id();
$id = (int) ($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!li_csrf_ok()) { li_go('recommendations.php?id=' . $id, 'error', 'The page had expired.'); }
    $do = $_POST['do'] ?? '';
    if ($do === 'generate') {
        $sid = (int) ($_POST['scenario_id'] ?? 0);
        $sc = $sid ? mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM li_scenarios WHERE id = $sid")) : null;
        if (!$sc) { li_go('recommendations.php', 'error', 'Choose a saved loan scenario.'); }
        $p = $sc['proposal_id'] ? li_proposal($conn, (int) $sc['proposal_id']) : null;
        $rec = li_build_recommendation($conn, $p, $sc);
        $j = json_encode($rec, JSON_UNESCAPED_UNICODE); $amt = (float) $rec['amount']; $pid = $p ? (int) $p['id'] : null;
        $s = mysqli_prepare($conn, 'INSERT INTO li_recommendations (decision, amount, proposal_id, scenario_id, snapshot, created_by) VALUES (?, ?, ?, ?, ?, ?)');
        mysqli_stmt_bind_param($s, 'sdiisi', $rec['decision'], $amt, $pid, $sid, $j, $me);
        mysqli_stmt_execute($s);
        $rid = (int) mysqli_insert_id($conn);
        li_audit($conn, 'recommendation_create', 'recommendation', $rid, null, ['decision' => $rec['decision'], 'amount' => $amt, 'scenario' => $sid]);
        li_go('recommendations.php?id=' . $rid, 'success', 'Recommendation generated.');
    }
    if ($do === 'review' && li_can($conn, 'approve')) {
        $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM li_recommendations WHERE id = $id AND status = 'Awaiting review'"));
        $dec = ($_POST['decision'] ?? '') === 'Accepted' ? 'Accepted' : 'Rejected';
        $note = trim((string) ($_POST['note'] ?? ''));
        if (!$r) { li_go('recommendations.php?id=' . $id, 'error', 'Already reviewed.'); }
        if ((int) $r['created_by'] === $me && current_user_role() !== 'Admin') { li_go('recommendations.php?id=' . $id, 'error', 'Someone else must review the recommendation you generated.'); }
        if (mb_strlen($note) < 3) { li_go('recommendations.php?id=' . $id, 'error', 'Write the management decision / reason.'); }
        $s = mysqli_prepare($conn, 'UPDATE li_recommendations SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_note = ? WHERE id = ?');
        mysqli_stmt_bind_param($s, 'sisi', $dec, $me, $note, $id);
        mysqli_stmt_execute($s);
        li_audit($conn, 'recommendation_' . strtolower($dec), 'recommendation', $id, ['status' => 'Awaiting review'], ['status' => $dec], $note);
        li_go('recommendations.php?id=' . $id, 'success', 'Review recorded. Borrowing still has to be arranged with the lender and registered in Company Loans by finance.');
    }
    li_go('recommendations.php', 'error', 'Not allowed.');
}

if (!$id) {
    $list = mysqli_fetch_all(mysqli_query($conn, 'SELECT r.*, p.name pname, p.code, s.name sname, u.names who, v.names reviewer FROM li_recommendations r LEFT JOIN li_proposals p ON p.id = r.proposal_id
        LEFT JOIN li_scenarios s ON s.id = r.scenario_id LEFT JOIN users u ON u.id = r.created_by LEFT JOIN users v ON v.id = r.reviewed_by ORDER BY r.id DESC LIMIT 200'), MYSQLI_ASSOC);
    if (($_GET['export'] ?? '') === 'csv') {
        li_csv('recommendations-' . date('Ymd') . '.csv', ['#', 'Date', 'Recommendation', 'Amount', 'Investment', 'Loan scenario', 'Status', 'Reviewed by', 'Note'],
            array_map(function ($r) { return [$r['id'], $r['created_at'], LI_DECISIONS[$r['decision']][0] ?? $r['decision'], $r['amount'], $r['pname'], $r['sname'], $r['status'], $r['reviewer'], $r['review_note']]; }, $list));
    }
    $scen = mysqli_fetch_all(mysqli_query($conn, 'SELECT s.id, s.name, s.principal, s.rate, s.n, p.name pname FROM li_scenarios s LEFT JOIN li_proposals p ON p.id = s.proposal_id ORDER BY s.id DESC LIMIT 40'), MYSQLI_ASSOC);
    include __DIR__ . '/../../includes/header.php';
    include __DIR__ . '/../../includes/sidebar.php';
    li_tabs($conn, 'recommendations.php', 'Management Recommendations');
    ?>
    <div class="d-flex flex-wrap gap-2 justify-content-between mb-2">
        <form method="POST" class="d-flex gap-2"><?= li_csrf_field(); ?><input type="hidden" name="do" value="generate"><select name="scenario_id" class="form-select form-select-sm" required><option value="">Generate from a saved loan scenario…</option>
            <?php foreach ($scen as $s) { ?><option value="<?= (int) $s['id']; ?>"><?= li_e($s['name'] . ' · ' . number_format($s['principal']) . ' @ ' . (float) $s['rate'] . '% / ' . $s['n'] . ($s['pname'] ? ' · ' . $s['pname'] : '')); ?></option><?php } ?></select><button class="btn btn-sm btn-primary">Generate</button></form>
        <a href="?export=csv" class="btn btn-sm btn-outline-secondary"><i class="bi bi-filetype-csv"></i> CSV</a></div>
    <div class="small text-muted mb-2">Recommendations can also be generated from an investment proposal. They are advisory: only authorized management approves borrowing.</div>
    <div class="card border-0 shadow-sm"><div class="card-body p-0"><div class="table-responsive"><table class="table table-sm table-hover mb-0" style="font-size:12.5px;">
        <tr class="table-light"><th class="ps-3">#</th><th>Date</th><th>Recommendation</th><th class="text-end">Amount</th><th>Investment / scenario</th><th>Status</th><th>Reviewed</th></tr>
        <?php foreach ($list as $r) { ?><tr style="cursor:pointer" onclick="location.href='?id=<?= (int) $r['id']; ?>'"><td class="ps-3"><a href="?id=<?= (int) $r['id']; ?>"><?= (int) $r['id']; ?></a></td><td><?= li_e($r['created_at']); ?><div class="small text-muted"><?= li_e($r['who']); ?></div></td>
            <td><?= li_decision_badge($r['decision']); ?></td><td class="text-end"><?= li_n($r['amount']); ?></td><td><?= li_e(trim(($r['code'] ? $r['code'] . ' ' : '') . ($r['pname'] ?? ''))); ?><div class="small text-muted"><?= li_e($r['sname'] ?: 'reference terms'); ?></div></td>
            <td><?= li_status_badge($r['status']); ?></td><td class="small"><?= li_e($r['reviewer']); ?><div class="text-muted"><?= li_e($r['review_note']); ?></div></td></tr><?php } ?>
        <?php if (!$list) { ?><tr><td colspan="7" class="text-center text-muted py-4">No recommendation yet.</td></tr><?php } ?>
    </table></div></div></div>
    <?php include __DIR__ . '/../../includes/footer.php';
    exit;
}

$r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT r.*, u.names who, v.names reviewer FROM li_recommendations r LEFT JOIN users u ON u.id = r.created_by LEFT JOIN users v ON v.id = r.reviewed_by WHERE r.id = $id"));
if (!$r) { li_go('recommendations.php', 'error', 'Not found.'); }
$s = json_decode($r['snapshot'], true) ?: [];
if (!empty($s['proposal']) && ($pp = li_proposal($conn, (int) $s['proposal']['id'])) && !li_proposal_visible($conn, $pp)) { li_deny(); }
$c = $s['company'] ?? []; $m = $s['metrics'] ?? null; $at = $s['at_amount'] ?? null; $loan = $s['loan'] ?? [];
$items = [
    ['1. Recommendation', LI_DECISIONS[$s['decision']][0] ?? $s['decision']],
    ['2. Reason', implode(' ', $s['reasons'] ?? [])],
    ['3. Data used', 'CFADS ' . number_format($c['cfads'] ?? 0) . '/month from ' . (int) ($c['active_months'] ?? 0) . ' month(s) of P&L; cash ' . number_format((float) ($c['cash'] ?? 0)) . '; existing repayments ' . number_format($c['debt_month'] ?? 0) . '/month; internal funds ' . number_format($c['internal'] ?? 0)],
    ['4. Proposed financing amount', number_format((float) $s['amount']) . ' (requested ' . number_format((float) ($loan['principal'] ?? 0)) . ')'],
    ['5. Suggested investment allocation', implode('; ', array_map(function ($a) { return $a[0] . ' ' . number_format($a[1]); }, $s['allocation'] ?? [])) ?: '—'],
    ['6. Expected additional revenue', $m ? number_format($m['monthly_revenue']) . '/month' : '—'],
    ['7. Expected operating profit', $m ? number_format($m['monthly_net']) . '/month · ' . number_format($m['net_total']) . ' over ' . $m['horizon'] . ' months' : '—'],
    ['8. Expected repayment obligations', $at ? number_format($at['instalment']) . ' per instalment · total ' . number_format($at['total_repayment']) . ' (interest ' . number_format($at['total_interest']) . ')' : '—'],
    ['9. Cash remaining after debt service', $at ? number_format($at['monthly_after']) . '/month (base case)' : '—'],
    ['10. Estimated repayment period', $at ? $at['months'] . ' months' : '—'],
    ['11. Risk assessment', ($s['risk'] ?? '—') . (!empty($s['risk_reasons']) ? ' (' . implode('; ', $s['risk_reasons']) . ')' : '') . ' · lowest DSCR: ' . implode(', ', array_map(function ($x) { return $x['label'] . ' ' . ($x['dscr_min'] ?? '—'); }, $s['stress'] ?? []))],
    ['12. Data confidence', $s['confidence'] ?? '—'],
    ['13. Key assumptions', implode(' ', $s['assumptions'] ?? [])],
    ['14. Alternative actions', implode(' ', $s['alternatives'] ?? [])],
];
if (($_GET['export'] ?? '') === 'pdf') {
    li_audit($conn, 'export', 'recommendation', $id, null, null, 'Recommendation PDF');
    an_render_pdf('Borrowing recommendation #' . $id . ' — ' . ($items[0][1]), [['Generated', $s['generated_at'] ?? $r['created_at']], ['Investment', $s['proposal']['name'] ?? 'none'], ['Status', $r['status'] . ($r['reviewer'] ? ' (' . $r['reviewer'] . ')' : '')]], [],
        [], [['title' => 'Recommendation', 'columns' => ['Item', 'Detail'], 'rows' => $items],
             ['title' => 'Stress test', 'columns' => ['Scenario', 'Lowest DSCR', 'Left after debt / month', 'Lowest cash', 'Investment ROI'], 'rows' => array_map(function ($x) { return [$x['label'], $x['dscr_min'] ?? '—', number_format((float) $x['monthly_after']), number_format((float) $x['cash_low']), $x['roi'] === null ? '—' : $x['roi'] . '%']; }, $s['stress'] ?? [])],
             ['title' => 'Formulas', 'columns' => ['Figure', 'Calculation'], 'rows' => array_map(null, array_keys($s['formulas'] ?? []), array_values($s['formulas'] ?? []))]], 'recommendation-' . $id . '.pdf');
}
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
li_tabs($conn, 'recommendations.php', 'Recommendation #' . $id);
?>
<div class="card border-0 shadow-sm mb-3" style="border-left:5px solid <?= LI_DECISIONS[$s['decision']][1] ?? '#6B7280'; ?> !important;"><div class="card-body">
    <div class="d-flex flex-wrap justify-content-between gap-2">
        <div><?= li_decision_badge($s['decision'], true); ?> <span class="fs-5 fw-bold ms-2"><?= $s['amount'] > 0 ? li_rwf($s['amount']) : ''; ?></span>
            <div class="small text-muted mt-1">Generated <?= li_e($s['generated_at'] ?? $r['created_at']); ?> by <?= li_e($r['who']); ?> · <?= li_status_badge($r['status']); ?><?= $r['reviewer'] ? ' by ' . li_e($r['reviewer']) . ': ' . li_e($r['review_note']) : ''; ?></div>
            <?php if (!empty($s['proposal'])) { ?><div class="small">Investment: <a href="proposal.php?id=<?= (int) $s['proposal']['id']; ?>"><?= li_e($s['proposal']['code'] . ' ' . $s['proposal']['name']); ?></a></div><?php } ?></div>
        <div><a href="?id=<?= $id; ?>&export=pdf" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-file-earmark-pdf"></i> PDF</a></div>
    </div>
    <ul class="mb-0 mt-2"><?php foreach ($s['reasons'] ?? [] as $x) { ?><li><?= li_e($x); ?></li><?php } ?></ul>
</div></div>
<div class="row g-3">
    <div class="col-xl-8"><div class="card border-0 shadow-sm mb-3"><div class="card-body p-0"><table class="table table-sm mb-0" style="font-size:13px;">
        <?php foreach ($items as [$k, $v]) { ?><tr><td class="ps-3 text-muted" style="width:250px;"><?= li_e($k); ?></td><td><?= li_e($v); ?></td></tr><?php } ?>
    </table></div></div>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body"><h6>Why this amount</h6>
        <table class="table table-sm small mb-0">
            <tr><td>Total funding required (investment + working capital)</td><td class="text-end"><?= li_n($s['need']); ?></td></tr>
            <tr><td>- Internal funds available</td><td class="text-end"><?= li_n($s['internal']); ?></td></tr>
            <tr><td>= Funding gap</td><td class="text-end fw-bold"><?= li_n($s['gap']); ?></td></tr>
            <tr><td>Largest amount the cash flow can repay (DSCR ≥ <?= li_e(li_setting($conn, 'min_dscr')); ?>, <?= (float) ($loan['rate'] ?? 0); ?>%, <?= (int) ($loan['n'] ?? 0); ?> x <?= li_e(strtolower($loan['freq'] ?? '')); ?>)</td><td class="text-end"><?= li_n($s['affordable']); ?></td></tr>
            <tr class="table-light"><td><strong>Recommended = the smaller of the gap and the affordable amount (rounded down to 10,000)</strong></td><td class="text-end fw-bold"><?= li_n($s['amount']); ?></td></tr>
        </table></div></div>
    <div class="card border-0 shadow-sm mb-3"><div class="card-body"><h6>Stress test</h6>
        <table class="table table-sm small mb-0"><tr><th>Scenario</th><th>Assumptions</th><th class="text-end">Lowest DSCR</th><th class="text-end">Left after debt / month</th><th class="text-end">Lowest cash</th><th class="text-end">Investment ROI</th></tr>
        <?php foreach ($s['stress'] ?? [] as $x) { ?><tr><td><?= li_e($x['label']); ?></td><td class="text-muted">revenue <?= sprintf('%+d', $x['adj']['revenue_pct']); ?>%, expenses <?= sprintf('%+d', $x['adj']['opex_pct']); ?>%, delay <?= (int) $x['adj']['delay_months']; ?> mo, rate +<?= (float) $x['adj']['rate_up']; ?></td>
            <td class="text-end fw-bold"><?= $x['dscr_min'] ?? '—'; ?></td><td class="text-end"><?= li_n($x['monthly_after']); ?></td><td class="text-end"><?= li_n($x['cash_low']); ?></td><td class="text-end"><?= li_pct($x['roi']); ?></td></tr><?php } ?></table></div></div>
    </div>
    <div class="col-xl-4">
        <?php if ($r['status'] === 'Awaiting review' && li_can($conn, 'approve')) { ?>
        <div class="card border-0 shadow-sm mb-3 border-start border-4 border-warning"><div class="card-body"><h6>Management review</h6>
            <form method="POST"><?= li_csrf_field(); ?><input type="hidden" name="do" value="review"><textarea name="note" class="form-control form-control-sm mb-2" rows="3" placeholder="Decision and reason (required)" required></textarea>
                <button name="decision" value="Accepted" class="btn btn-sm btn-success">Accept</button> <button name="decision" value="Rejected" class="btn btn-sm btn-outline-danger">Reject</button></form>
            <div class="small text-muted mt-2">Accepting records management's decision. No loan, payment or transaction is created automatically.</div></div></div>
        <?php } ?>
        <div class="card border-0 shadow-sm"><div class="card-body small"><h6>Formulas used</h6>
            <?php foreach ($s['formulas'] ?? [] as $k => $f) { ?><div class="mb-2"><strong><?= li_e($k); ?></strong><div class="li-formula"><?= li_e($f); ?></div></div><?php } ?>
            <div class="text-muted">All figures are a snapshot of the data on <?= li_e($s['generated_at'] ?? ''); ?>. Generate a new recommendation to use today's data.</div></div></div>
    </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>