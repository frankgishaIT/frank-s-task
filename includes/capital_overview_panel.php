<?php
/**
 * NEW FILE: includes/capital_overview_panel.php — capital overview for RM Credit Hub and RM Investors Hub (spec D),
 * and, for Administrators, the past movements not yet recorded in the funds (recorded once, at their dates).
 * Include it inside a page, after the header / tabs.
 */
require_once __DIR__ . '/capital_flows.php';
cf_ensure($conn);
$cfMsg = null; $cfErr = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cf_record_pending'])) {
    try {
        if (current_user_role() !== 'Admin') { throw new RuntimeException('Only Administrators record past movements in the funds.'); }
        // A fund that would go below zero needs an explicit confirmation that this money really moved.
        $neg = array_filter(cf_pending_effect($conn, cf_pending($conn)), function ($x) { return $x['after'] < -0.001; });
        if ($neg && empty($_POST['cf_confirm_negative'])) { throw new RuntimeException('Some funds would go below zero: check the list, then tick the confirmation if this money really moved.'); }
        $n = cf_record_pending($conn, (int) current_user_id());
        $cfMsg = $n . ' past movement(s) recorded in RM Capital Fund, the other funds and Transactions, at their original dates.';
    } catch (Throwable $x) { $cfErr = 'Nothing was recorded: ' . $x->getMessage(); }
}
$cf = cf_overview($conn); $cfMoney = function ($v) { return 'RWF ' . number_format((float) $v, 0); };
?>
<?php if ($cfMsg) { ?><div class="alert alert-success small"><?= htmlspecialchars($cfMsg, ENT_QUOTES, 'UTF-8'); ?></div><?php } ?>
<?php if ($cfErr) { ?><div class="alert alert-danger small"><?= htmlspecialchars($cfErr, ENT_QUOTES, 'UTF-8'); ?></div><?php } ?>
<div class="card border-0 shadow-sm mb-3"><div class="card-body small">
    <div class="d-flex justify-content-between flex-wrap gap-2 mb-2"><strong>RM Capital Fund · lending and investor capital</strong><span class="text-muted">Recorded money only (Transactions and the funds)</span></div>
    <div class="row g-2 text-center">
        <?php foreach ([['RM Capital Fund balance', $cf['capital_balance'], ''], ['Available for new loans', $cf['capital_available'], 'text-success'], ['Disbursed as customer loans', $cf['disbursed'], ''],
                        ['Outstanding customer principal', $cf['outstanding'], ''], ['Principal recovered', $cf['recovered'], ''], ['Customer loan interest earned', $cf['interest'], ''],
                        ['Investor loans received', $cf['inv_loan_in'], ''], ['Investor capital received', $cf['inv_capital_in'], ''], ['Investor loans to repay', $cf['inv_loan_owed'], 'text-danger'],
                        ['Investor capital to return', $cf['inv_capital_owed'], 'text-danger'], ['Investor returns payable', $cf['returns_due'], 'text-danger']] as [$l, $v, $c]) { ?>
        <div class="col-6 col-md-4 col-xl-2"><div class="border rounded-3 p-2 h-100"><div class="text-muted"><?= $l; ?></div><div class="fw-bold <?= $c; ?>"><?= $cfMoney($v); ?></div></div></div><?php } ?>
    </div>
    <?php if ($cf['pending'] > 0 && current_user_role() === 'Admin') { $cfList = cf_pending($conn); ?>
    <div class="alert alert-warning mt-3 mb-0"><strong><?= (int) $cf['pending']; ?> past movement(s) are not in the funds yet</strong> (they happened before this integration):
        <ul class="mb-2"><?php foreach (array_slice($cfList, 0, 8) as $it) { ?><li><?= htmlspecialchars($it['label'], ENT_QUOTES, 'UTF-8'); ?> · <?= $cfMoney($it['amount']); ?> · <?= date('d M Y', strtotime($it['date'])); ?></li><?php } ?><?= count($cfList) > 8 ? '<li>…</li>' : ''; ?></ul>
        <?php $cfEff = cf_pending_effect($conn, $cfList); $cfNeg = array_filter($cfEff, function ($x) { return $x['after'] < -0.001; }); ?>
        <div class="mb-2"><strong>Effect on the funds:</strong>
            <?php foreach ($cfEff as $x) { ?><div><?= htmlspecialchars($x['name'], ENT_QUOTES, 'UTF-8'); ?>: <?= $cfMoney($x['now']); ?> <?= $x['change'] >= 0 ? '+' : '−'; ?> <?= $cfMoney(abs($x['change'])); ?> = <strong class="<?= $x['after'] < 0 ? 'text-danger' : ''; ?>"><?= $cfMoney($x['after']); ?></strong></div><?php } ?></div>
        <?php if ($cfNeg) { ?><div class="text-danger mb-2"><i class="bi bi-exclamation-triangle-fill"></i> <strong>Some funds would go below zero.</strong> This usually means some of these movements were <strong>tests</strong>, not real money. Record them only if this money really moved; test entries should be corrected first (ask your system administrator).</div><?php } ?>
        <form method="POST" onsubmit="return confirm('Record these past movements in the funds and Transactions, at their original dates? Each is recorded once.');">
            <?php if ($cfNeg) { ?><label class="d-block mb-2"><input type="checkbox" name="cf_confirm_negative" value="1" class="form-check-input me-1" required>I confirm this money really moved (these are not tests).</label><?php } ?>
            <button name="cf_record_pending" value="1" class="btn btn-sm btn-primary">Record them now</button>
            <span class="text-muted ms-2">Recorded at their original dates, in date order, each once. If any step fails, nothing is recorded.</span></form></div>
    <?php } ?>
</div></div>