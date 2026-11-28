<?php
/**
 * NEW FILE: modules/delegations/new.php — delegate a sale line to an RM Tasker (Scenario B).
 * ?sale=ID (choose the line) or ?item=SALE_ITEM_ID. Shows the eligible RM Taskers with the recommendation, the
 * expected payment and profit from the approved profit-sharing basis, then creates the delegation DL000001…
 * It goes straight to the Tasker unless it needs a manager's approval (another Tasker than recommended, rate above
 * the expected one, deadline beyond the maximum, expected loss): then it waits in Pending Approval.
 */
require __DIR__ . '/common.php';
dl_require($conn, 'create');
$itemId = (int) ($_GET['item'] ?? $_POST['item'] ?? 0);
$saleId = (int) ($_GET['sale'] ?? 0);
if (!$itemId && $saleId) {
    $lines = dl_sale_lines_to_delegate($conn, $saleId);
    if (count($lines) === 1) { $itemId = $lines[0]; }
}
$line = $itemId ? dl_sale_line($conn, $itemId) : null;
$error = '';

if (!$line) {
    // Choose a line of the sale.
    $sale = $saleId ? mysqli_fetch_assoc(mysqli_query($conn, 'SELECT s.*, c.name customer FROM sales s LEFT JOIN customers c ON c.id = s.customer_id WHERE s.id = ' . $saleId)) : null;
    $opts = $sale ? dl_sale_lines_to_delegate($conn, $saleId) : [];
    include __DIR__ . '/../../includes/header.php';
    include __DIR__ . '/../../includes/sidebar.php';
    dl_tabs($conn, 'list.php', 'Delegate a sale');
    if (!$sale) { echo '<div class="alert alert-warning py-2 small">Open a sale from Sales and press <b>Delegate</b>, or give the sale number.</div><form class="d-flex gap-2" style="max-width:360px"><input name="sale" class="form-control form-control-sm" placeholder="Sale number" required><button class="btn btn-primary btn-sm">Open</button></form>'; }
    elseif (!$opts) { echo '<div class="alert alert-info py-2 small">Sale #' . (int) $saleId . ' has no line that needs an RM Tasker (or every such line is already delegated). Services provided internally are sold normally, with no Tasker payment.</div>'; }
    else {
        echo '<div class="small text-muted mb-2">Sale #' . (int) $saleId . ' · ' . dl_e($sale['customer'] ?: 'Walk-in') . ': choose the line to delegate.</div><div class="list-group" style="max-width:640px">';
        foreach ($opts as $i) { $l = dl_sale_line($conn, $i); echo '<a class="list-group-item list-group-item-action small" href="new.php?item=' . $i . '">' . dl_e($l['product_name']) . ' · ' . dl_e(rtrim(rtrim(number_format((float) $l['quantity'], 2), '0'), '.')) . ' × · ' . dl_money($l['revenue']) . '</a>'; }
        echo '</div>';
    }
    include __DIR__ . '/../../includes/footer.php';
    exit;
}

$pid = (int) $line['product_id'];
$o = dl_offering($conn, $pid);
$customer = $line['customer_id'] ? mysqli_fetch_assoc(mysqli_query($conn, 'SELECT id, name, phone FROM customers WHERE id = ' . (int) $line['customer_id'])) : null;
$live = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT id FROM dl_delegations WHERE sale_item_id = ' . $itemId . " AND status NOT IN ('Cancelled', 'Reassigned', 'Rejected') LIMIT 1"));
$c = dl_candidates($conn, $pid, (float) $line['revenue']);
$best = $c['picks']['best'] ?? null;
$basis = dl_setting($conn, 'profit_basis');
$qty = max(1, (float) $line['quantity']);
$hoursStd = (float) ($o['std_hours'] ?: 24);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $chosenId = (int) ($_POST['party_id'] ?? 0);
    $chosen = null;
    foreach ($c['eligible'] as $r) { if ((int) $r['party'] === $chosenId) { $chosen = $r; } }
    [$mats, $dirs] = dl_costs_from_post($conn, '');
    $urgent = !empty($_POST['urgent']) ? 1 : 0;
    $auto = date('Y-m-d H:i:s', time() + (int) round((($urgent && $o['urgent_hours']) ? (float) $o['urgent_hours'] : $hoursStd) * 3600));
    $dlInput = (string) ($_POST['deadline'] ?? '');
    $deadline = preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $dlInput) ? str_replace('T', ' ', $dlInput) . ':00' : $auto;
    $dlReason = mb_substr(trim((string) ($_POST['deadline_reason'] ?? '')), 0, 255);
    $changed = abs(strtotime($deadline) - strtotime($auto)) > 15 * 60;
    $instr = mb_substr(trim((string) ($_POST['instructions'] ?? '')), 0, 5000);
    $delivery = mb_substr(trim((string) ($_POST['delivery'] ?? '')), 0, 1000);
    $override = mb_substr(trim((string) ($_POST['override_reason'] ?? '')), 0, 255);
    if (!dl_csrf_ok()) { $error = 'The form expired. Please try again.'; }
    elseif ($live) { $error = 'This sale line is already delegated (' . dl_ref((int) $live['id']) . ').'; }
    elseif (in_array($line['sale_status'], ['Cancelled'], true)) { $error = 'The sale is cancelled.'; }
    elseif (!dl_needs_tasker($o)) { $error = 'This offering is provided internally: process the sale normally, no Tasker payment.'; }
    elseif (!$chosen) { $error = 'Choose an eligible RM Tasker.'; }
    elseif ($best && (int) $best['party'] !== $chosenId && $override === '') { $error = 'You chose another RM Tasker than the recommended one: give the reason.'; }
    elseif ($instr === '') { $error = 'Give the work instructions for the RM Tasker.'; }
    elseif (strtotime($deadline) <= time()) { $error = 'The deadline must be in the future.'; }
    elseif ($changed && $dlReason === '') { $error = 'You changed the calculated deadline (' . dl_deadline_text($auto) . '): give the reason.'; }
    else {
        $cogs = 0; foreach ($mats as $m) { $cogs += $m['qty'] * $m['unit_cost']; }
        $direct = 0; foreach ($dirs as $x) { $direct += $x['amount']; }
        $f = dl_profit((float) $line['revenue'], $cogs, $direct, (float) $chosen['rate'], $basis);
        $hours = (strtotime($deadline) - time()) / 3600;
        $reasons = dl_approval_reasons($o, $best, $chosen, $hours, $f);
        $canApprove = dlc($conn, 'approve');
        $status = $reasons && !$canApprove ? 'Pending Approval' : 'Awaiting Tasker Acceptance';
        $now = dl_now(); $u = dl_uid();
        $bestId = $best ? (int) $best['party'] : null;
        $reasonTxt = $reasons ? implode('; ', $reasons) : null;
        $ov = $override ?: null; $dr = $changed ? $dlReason : null;
        mysqli_begin_transaction($conn);
        try {
            $s = mysqli_prepare($conn, 'INSERT INTO dl_delegations (sale_id, sale_item_id, customer_id, product_id, party_id, quantity, revenue, rate_pct, basis, cogs_exp, direct_exp, pool_exp, pay_exp, retained_exp, final_exp,
                status, urgent, deadline, deadline_auto, deadline_reason, instructions, delivery, recommended_party_id, override_reason, approval_reasons, created_by, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $sid = (int) $line['sale_id']; $cid = $line['customer_id'] ? (int) $line['customer_id'] : null; $rev = (float) $line['revenue']; $rate = (float) $chosen['rate'];
            mysqli_stmt_bind_param($s, 'iiiiiddds' . 'dddddd' . 'si' . 'sssss' . 'iss' . 'iss', $sid, $itemId, $cid, $pid, $chosenId, $qty, $rev, $rate, $basis, $f['cogs'], $f['direct'], $f['pool'], $f['tasker'], $f['retained'], $f['final'],
                $status, $urgent, $deadline, $auto, $dr, $instr, $delivery, $bestId, $ov, $reasonTxt, $u, $now, $now);
            mysqli_stmt_execute($s);
            $did = (int) mysqli_insert_id($conn);
            dl_save_costs($conn, $did, 'expected', $mats, $dirs);
            $ref = dl_ref($did);
            dl_event($conn, $did, 'created', 'Delegation of ' . $line['product_name'] . ' (sale #' . $sid . ') to ' . $chosen['display'] . ' at ' . dl_pct($rate) . ' of the profit pool (' . dl_basis_text($basis) . '): pool ' . dl_money($f['pool']) . ', expected Tasker payment ' . dl_money($f['tasker']) . ', RISE MOTIVE keeps ' . dl_money($f['final']) . '. Deadline ' . dl_deadline_text($deadline) . ($urgent ? ' (urgent)' : ''), 'staff', 0, $ov ? 'Not the recommended Tasker: ' . $ov : null);
            if ($dr) { dl_event($conn, $did, 'deadline_set', 'Deadline ' . dl_deadline_text($deadline) . ' instead of the calculated ' . dl_deadline_text($auto), 'staff', 0, $dr, 1); }
            mysqli_commit($conn);
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            error_log('dl create: ' . $e->getMessage());
            $error = 'The delegation could not be saved. Please try again.';
        }
        if (!$error) {
            [$nf, $ferr] = dl_save_files($conn, $did, 'files', 'instruction');
            if ($reasons && $canApprove) {
                dl_set_status($conn, $did, $status, ['approved_by' => $u, 'approved_at' => dl_now(), 'approval_note' => 'Approved when created (approver)']);
                dl_event($conn, $did, 'approved', 'Approved when created: ' . $reasonTxt, 'staff');
            }
            if ($status === 'Pending Approval') {
                dl_notify_staff($conn, dl_approvers($conn), 'Delegation waiting for approval: ' . $ref, $line['product_name'] . ' to ' . $chosen['display'] . ': ' . $reasonTxt);
                dl_go('view.php?id=' . $did, 'success', $ref . ' created. It needs a manager\'s approval (' . $reasonTxt . ') before it goes to the RM Tasker.');
            }
            dl_set_status($conn, $did, $status, ['notified_at' => dl_now(), 'followup_due_at' => dl_followup_due($conn, (bool) $urgent, $deadline)]);
            $n = dl_notify_tasker($conn, dl_get($conn, $did), 'assigned');
            dl_go('view.php?id=' . $did, 'success', $ref . ' sent to ' . $chosen['display'] . ' (SMS ' . ($n['sms'] ? 'sent' : 'NOT sent') . ($n['email'] ? ', e-mail sent' : '') . '). If they do not answer in time you will be reminded to call or WhatsApp them.' . ($ferr ? ' File problem: ' . $ferr : ''));
        }
    }
}

$mats = [];
foreach (dl_materials($conn, $o) as $m) { $mats[] = ['product_id' => $m['product_id'], 'qty' => round($m['qty'] * $qty, 3), 'unit_cost' => $m['cost'], 'name' => $m['name'], 'stock' => $m['stock']]; }
$items = mysqli_fetch_all(mysqli_query($conn, "SELECT id, product_name, unit, buying_price, quantity FROM products WHERE item_type = 'Item' AND is_active = 1 ORDER BY product_name"), MYSQLI_ASSOC);
$autoDl = date('Y-m-d\TH:i', time() + (int) round($hoursStd * 3600));
$urgentDl = $o['urgent_hours'] ? date('Y-m-d\TH:i', time() + (int) round((float) $o['urgent_hours'] * 3600)) : '';
$pick = (int) ($_POST['party_id'] ?? ($best['party'] ?? 0));

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
dl_tabs($conn, 'list.php', 'Delegate: ' . $line['product_name']);
if ($error) { echo '<div class="alert alert-danger py-2 small">' . dl_e($error) . '</div>'; }
?>
<div class="dl-kpi mb-3 small d-flex flex-wrap gap-3"><span>Sale <b>#<?= (int) $line['sale_id']; ?></b> (<?= dl_e($line['sale_status']); ?>)</span><span>Customer: <b><?= dl_e($customer['name'] ?? 'Walk-in'); ?></b></span>
    <span><?= dl_e($line['product_name']); ?> × <?= dl_e(rtrim(rtrim(number_format($qty, 2), '0'), '.')); ?></span><span>Customer price: <b><?= dl_money($line['revenue']); ?></b></span><span><?= dl_fulfilment_badge($o['fulfilment']); ?></span></div>
<?php if (!$live && dl_needs_tasker($o)) { /* NEW (batch 4): staff guidance — serve the customer, but only delegate when it is the smart option */
    if ($o['fulfilment'] === 'External Only' || $o['internal_capability'] !== 'Yes') { ?><div class="small mb-2 p-2 rounded" style="background:#FFF8E6;color:#7A4E00"><i class="bi bi-lightbulb me-1"></i>RISE MOTIVE cannot currently provide this service internally, but you may serve the customer through an available RM Tasker. Check the recommended providers before turning the customer away.</div>
    <?php } else { ?><div class="small mb-2 p-2 rounded" style="background:#EEF1FD;color:#1E2FE0"><i class="bi bi-info-circle me-1"></i>RISE MOTIVE can provide this service internally. Delegate it only when internal delivery is not possible at an acceptable quality, time and cost, and say why below.</div><?php }
} ?>
<?php if ($live) { ?><div class="alert alert-info py-2 small">Already delegated: <a href="view.php?id=<?= (int) $live['id']; ?>"><?= dl_ref((int) $live['id']); ?></a>.</div>
<?php } elseif (!dl_needs_tasker($o)) { ?><div class="alert alert-info py-2 small">This offering is provided internally (Scenario A): process the sale normally. No external Tasker payment is created.</div>
<?php } elseif (!$c['eligible']) { ?><div class="alert alert-warning py-2 small">No RM Tasker can take it now<?= $c['other'] ? ': ' . dl_e(implode('; ', array_map(function ($r) { return $r['display'] . ' (' . $r['why'] . ')'; }, $c['other']))) : ''; ?>.
    <a href="lost.php?product=<?= $pid; ?>&customer=<?= (int) ($customer['id'] ?? 0); ?>&price=<?= (int) round((float) $line['revenue']); ?>" class="fw-semibold">Record it as an unavailable request</a> so the demand is not lost.</div>
<?php } else { ?>
<div class="alert alert-primary py-2 small mb-2"><b>Recommended:</b> <?= dl_e(dl_explain($c)); ?></div>
<form method="post" enctype="multipart/form-data" id="dlForm"><?= dl_csrf_field(); ?><input type="hidden" name="item" value="<?= $itemId; ?>">
<div class="card mb-3"><div class="table-responsive"><table class="table table-sm dl-tbl mb-0">
<thead><tr><th></th><th>RM Tasker</th><th class="text-end">Rate</th><th>Time</th><th>Quality</th><th>Reliability</th><th class="text-end">Score</th><th class="text-end">Expected payment</th><th class="text-end">RISE MOTIVE keeps</th></tr></thead><tbody>
<?php foreach ($c['eligible'] as $n => $r) { ?>
<tr><td><input type="radio" name="party_id" value="<?= (int) $r['party']; ?>" data-rate="<?= dl_e($r['rate']); ?>" <?= $pick === (int) $r['party'] ? 'checked' : ''; ?> required></td>
    <td><?= dl_e($r['display']); ?> <span class="small text-muted"><?= dl_e($r['code']); ?></span><?= $n === 0 ? ' <span class="dl-st dl-blue">Recommended</span>' : ''; ?><?= (int) $r['preferred'] ? ' <span class="dl-st dl-gold">Preferred</span>' : ''; ?><?= $r['limited'] ? ' <span class="dl-st">New / limited history</span>' : ''; ?></td>
    <td class="text-end fw-semibold"><?= dl_pct($r['rate']); ?></td><td><?= dl_hours_text($r['hours']); ?></td><td><?= number_format($r['q'], 1); ?>/5</td><td><?= round($r['rel'] * 100); ?>%</td>
    <td class="text-end"><?= dl_e($r['score']); ?></td><td class="text-end pay" data-party="<?= (int) $r['party']; ?>">…</td><td class="text-end keep" data-party="<?= (int) $r['party']; ?>">…</td></tr>
<?php } ?>
</tbody></table></div></div>
<div class="mb-3" id="overrideBox" style="display:none"><label class="form-label small mb-0">Why not the recommended RM Tasker? (a manager will approve it)</label><input name="override_reason" class="form-control form-control-sm" value="<?= dl_e($_POST['override_reason'] ?? ''); ?>"></div>

<div class="row g-3">
<div class="col-lg-6"><div class="card h-100"><div class="card-body py-2">
    <div class="fw-semibold small mb-1">Cost of sales (expected)</div>
    <div id="mats">
    <?php foreach (array_merge($mats, [['product_id' => 0, 'qty' => '', 'unit_cost' => '']]) as $m) { ?>
        <div class="d-flex gap-1 mb-1"><select name="mat_product[]" class="form-select form-select-sm mp"><option value="">— material from stock —</option><?php foreach ($items as $it) { ?><option value="<?= (int) $it['id']; ?>" data-cost="<?= (float) $it['buying_price']; ?>" <?= (int) $m['product_id'] === (int) $it['id'] ? 'selected' : ''; ?>><?= dl_e($it['product_name']); ?> (<?= dl_e(rtrim(rtrim(number_format((float) $it['quantity'], 2), '0'), '.')); ?> in stock)</option><?php } ?></select>
            <input name="mat_qty[]" type="number" step="0.01" min="0" class="form-control form-control-sm mq" style="max-width:80px" placeholder="qty" value="<?= dl_e($m['qty']); ?>">
            <input name="mat_cost[]" inputmode="numeric" class="form-control form-control-sm mc" style="max-width:110px" placeholder="unit cost" value="<?= $m['unit_cost'] !== '' ? dl_e((float) $m['unit_cost']) : ''; ?>"></div>
    <?php } ?>
    </div>
    <button type="button" class="btn btn-link btn-sm px-0" onclick="addRow('mats')">+ material</button>
    <div class="fw-semibold small mt-2 mb-1">Other direct costs</div>
    <div id="dirs"><div class="d-flex gap-1 mb-1"><input name="dir_desc[]" class="form-control form-control-sm" placeholder="e.g. transport to the customer"><input name="dir_amount[]" inputmode="numeric" class="form-control form-control-sm dc" style="max-width:120px" placeholder="RWF"></div></div>
    <button type="button" class="btn btn-link btn-sm px-0" onclick="addRow('dirs')">+ direct cost</button>
    <div class="small mt-2" id="sum"></div>
    <div class="small text-muted">Basis (management): <?= dl_e(dl_basis_text($basis)); ?>. The Tasker's share is never taken from the full price.</div>
</div></div></div>
<div class="col-lg-6"><div class="card h-100"><div class="card-body py-2">
    <div class="row g-2">
        <div class="col-7"><label class="form-label small mb-0">Deadline (calculated: <?= dl_hours_text($hoursStd); ?>)</label><input type="datetime-local" name="deadline" id="dl" class="form-control form-control-sm" value="<?= dl_e($_POST['deadline'] ?? $autoDl); ?>" data-std="<?= $autoDl; ?>" data-urgent="<?= $urgentDl; ?>" required></div>
        <div class="col-5 d-flex align-items-end"><label class="small"><input type="checkbox" name="urgent" value="1" id="urg" <?= $o['urgent_hours'] ? '' : 'disabled'; ?> <?= !empty($_POST['urgent']) ? 'checked' : ''; ?>> Urgent<?= $o['urgent_hours'] ? ' (' . dl_hours_text($o['urgent_hours']) . ')' : ' (not supported)'; ?></label></div>
        <div class="col-12" id="dlReasonBox" style="display:none"><input name="deadline_reason" class="form-control form-control-sm" placeholder="Why another deadline?" value="<?= dl_e($_POST['deadline_reason'] ?? ''); ?>"></div>
        <?php if ($o['max_hours']) { ?><div class="col-12 small text-muted">Maximum allowed time: <?= dl_hours_text($o['max_hours']); ?> (beyond it a manager approves).</div><?php } ?>
        <div class="col-12"><label class="form-label small mb-0">Instructions for the RM Tasker *</label><textarea name="instructions" rows="3" class="form-control form-control-sm" required placeholder="What exactly to deliver, format, size, colours, quantity…"><?= dl_e($_POST['instructions'] ?? ''); ?></textarea>
            <?php if ($o['quality_requirements']) { ?><div class="form-text">Quality requirements: <?= dl_e($o['quality_requirements']); ?></div><?php } ?></div>
        <div class="col-12"><label class="form-label small mb-0">Location / delivery details</label><textarea name="delivery" rows="2" class="form-control form-control-sm" placeholder="Where to deliver or pick up; who to ask for"><?= dl_e($_POST['delivery'] ?? ''); ?></textarea><div class="form-text">The RM Tasker sees only these instructions and details, not the customer's account.</div></div>
        <div class="col-12"><label class="form-label small mb-0">Attachments (PDF, images, Word, Excel, ZIP; up to 10)</label><input type="file" name="files[]" multiple class="form-control form-control-sm"></div>
    </div>
</div></div></div>
</div>
<div class="d-flex gap-2 mt-3"><button class="btn btn-primary btn-sm">Confirm the delegation</button><a href="../sales/index.php" class="btn btn-link btn-sm">Back to Sales</a></div>
</form>
<script>
(function () {
    var rev = <?= json_encode((float) $line['revenue']); ?>, basis = <?= json_encode($basis); ?>, best = <?= json_encode((int) ($best['party'] ?? 0)); ?>;
    var f = document.getElementById('dlForm');
    function money(v) { return 'RWF ' + Math.round(v).toLocaleString(); }
    function num(x) { return parseFloat(String(x || '').replace(/,/g, '')) || 0; }
    window.addRow = function (id) { var d = document.getElementById(id), r = d.lastElementChild.cloneNode(true); r.querySelectorAll('select,input').forEach(function (x) { x.value = ''; }); d.appendChild(r); };
    function calc() {
        var cogs = 0, direct = 0;
        document.querySelectorAll('#mats > div').forEach(function (r) { var s = r.querySelector('.mp'), q = num(r.querySelector('.mq').value), c = r.querySelector('.mc');
            if (s.value && !c.value) { c.value = s.options[s.selectedIndex].dataset.cost; } if (s.value) { cogs += q * num(c.value); } });
        document.querySelectorAll('.dc').forEach(function (x) { direct += num(x.value); });
        var pool = rev - cogs - (basis === 'cogs_direct' ? direct : 0);
        document.querySelectorAll('input[name=party_id]').forEach(function (r) {
            var pay = pool > 0 ? pool * num(r.dataset.rate) / 100 : 0, keep = pool - pay - (basis === 'cogs_direct' ? 0 : direct);
            document.querySelector('.pay[data-party="' + r.value + '"]').textContent = money(pay);
            var k = document.querySelector('.keep[data-party="' + r.value + '"]'); k.textContent = money(keep); k.className = 'text-end keep' + (keep < 0 ? ' text-danger fw-semibold' : '');
        });
        document.getElementById('sum').innerHTML = 'Customer price ' + money(rev) + ' − cost of sales ' + money(cogs) + (basis === 'cogs_direct' ? ' − direct costs ' + money(direct) : '') + ' = <b>profit pool ' + money(pool) + '</b>' + (basis === 'cogs_direct' ? '' : '; direct costs ' + money(direct) + ' are deducted after the Tasker\'s share');
        var sel = f.querySelector('input[name=party_id]:checked');
        document.getElementById('overrideBox').style.display = sel && +sel.value !== best ? '' : 'none';
        var dl = document.getElementById('dl'), ref = document.getElementById('urg').checked && dl.dataset.urgent ? dl.dataset.urgent : dl.dataset.std;
        document.getElementById('dlReasonBox').style.display = Math.abs(new Date(dl.value) - new Date(ref)) > 15 * 60000 ? '' : 'none';
    }
    document.getElementById('urg').addEventListener('change', function () { var dl = document.getElementById('dl'); dl.value = this.checked && dl.dataset.urgent ? dl.dataset.urgent : dl.dataset.std; calc(); });
    f.addEventListener('input', calc); f.addEventListener('change', calc); calc();
})();
</script>
<?php } ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>