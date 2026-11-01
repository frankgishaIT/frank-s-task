<?php
// NEW FILE: portal/invest.php — RM INVEST: published investment opportunities.
require __DIR__ . '/invest_start.php';
$rows = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM inv_projects WHERE status IN ('Published', 'Paused', 'Funding Closed', 'Active') ORDER BY FIELD(status, 'Published', 'Paused', 'Funding Closed', 'Active'), funding_deadline"), MYSQLI_ASSOC);
portal_header('RM Invest', $me);
portal_alerts();
?>
<div class="p-4 mb-3 rounded-3 text-white" style="background:#1E2FE0;"><div class="fs-4 fw-bold">RM INVEST</div><div class="opacity-75">Invest in Opportunities. Grow with RISE MOTIVE.</div>
    <a href="my_investments.php" class="btn btn-light btn-sm mt-2">My investments</a></div>
<?php if (inv_testing($conn)) { ?><div class="alert alert-warning small"><strong>Pilot phase:</strong> you can explore opportunities and apply. Contributions are not collected yet while RISE MOTIVE completes the legal requirements.</div><?php } ?>
<?= inv_disclaimer(); ?>
<div class="row g-3 mt-1">
<?php if (!$rows) { ?><div class="col-12"><div class="card"><div class="card-body text-muted">No investment opportunities are open right now. We will notify you when one is published.</div></div></div><?php } ?>
<?php foreach ($rows as $p) { $f = inv_funding($conn, (int) $p['id']); $pr = inv_projection($p); $ph = inv_photo($conn, (int) $p['id']);
    $months = $p['start_date'] && $p['end_date'] ? max(1, (int) round((strtotime($p['end_date']) - strtotime($p['start_date'])) / 2629800)) : null; ?>
<div class="col-md-6 col-xl-4"><div class="card h-100">
    <?php if ($ph) { ?><img src="invest_file.php?id=<?= $ph; ?>" alt="" style="height:170px; object-fit:cover; border-radius:12px 12px 0 0;"><?php } ?>
    <div class="card-body small">
        <div class="d-flex justify-content-between"><h2 class="h6 mb-1"><?= portal_e($p['name']); ?></h2><span class="badge <?= $p['status'] === 'Published' ? 'bg-success' : 'bg-secondary'; ?>"><?= $p['status'] === 'Published' ? 'Open' : portal_e($p['status']); ?></span></div>
        <p class="text-muted mb-2"><?= portal_e(mb_strimwidth((string) $p['description'], 0, 140, '…')); ?></p>
        <div style="height:8px; background:#EEF1F7; border-radius:4px;"><div style="width:<?= $f['pct']; ?>%; height:8px; background:#0FA968; border-radius:4px;"></div></div>
        <div class="d-flex justify-content-between mt-1"><span><?= cl_money($f['raised']); ?> raised</span><span>of <?= cl_money($p['capital_required']); ?></span></div>
        <table class="table table-sm mt-2 mb-2">
            <tr><td>Remaining</td><td class="text-end"><?= cl_money($f['remaining']); ?></td></tr>
            <tr><td>Investment</td><td class="text-end"><?= cl_money($p['min_invest']); ?> – <?= (float) $p['max_invest'] > 0 ? cl_money($p['max_invest']) : 'no max'; ?></td></tr>
            <tr><td>Investors</td><td class="text-end"><?= $f['investors']; ?><?= $p['max_investors'] ? ' / ' . (int) $p['max_investors'] : ''; ?></td></tr>
            <tr><td>Duration</td><td class="text-end"><?= $months ? $months . ' month(s)' : '—'; ?></td></tr>
            <tr><td>Projected return <span class="text-muted">(estimate)</span></td><td class="text-end"><?= $pr['return_pct'] !== null ? $pr['return_pct'] . '%' : '—'; ?></td></tr>
            <tr><td>Deadline</td><td class="text-end"><?= $p['funding_deadline'] ? date('d M Y', strtotime($p['funding_deadline'])) : '—'; ?></td></tr>
            <tr><td>Risk</td><td class="text-end"><span class="badge <?= $p['risk_level'] === 'High' ? 'bg-danger' : ($p['risk_level'] === 'Low' ? 'bg-success' : 'bg-primary'); ?>"><?= portal_e($p['risk_level']); ?></span></td></tr>
        </table>
        <a href="invest_project.php?id=<?= (int) $p['id']; ?>" class="btn btn-primary btn-sm w-100">VIEW DETAILS &amp; INVEST</a>
    </div></div></div>
<?php } ?>
</div>
<?php portal_footer();