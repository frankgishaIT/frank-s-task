<?php
// NEW FILE: portal/invest_agreement.php — the project-specific investment agreement (with the customer's amount when known).
require __DIR__ . '/invest_start.php';
$a = !empty($_GET['app']) ? mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM inv_applications WHERE id = ' . (int) $_GET['app'] . " AND customer_id = $cid")) : null;
$pid = $a ? (int) $a['project_id'] : (int) ($_GET['project'] ?? 0);
$p = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM inv_projects WHERE id = $pid AND status <> 'Draft'"));
if (!$p) { header('Location: invest.php'); exit; }
$h = function ($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); };
$amt = $a ? (float) $a['amount'] : null; $pr = inv_projection($p);
?><!doctype html><html><head><meta charset="utf-8"><title>Investment agreement <?= inv_project_ref($p); ?></title><style>body{font-family:Arial,sans-serif;max-width:820px;margin:24px auto;padding:0 16px;font-size:13px;line-height:1.5;color:#1E2333}h1{color:#1E2FE0;font-size:20px}.note{background:#EEF3FF;padding:8px;border-left:3px solid #1E2FE0}@media print{.noprint{display:none}}</style></head><body>
<div class="noprint" style="text-align:right"><button onclick="window.print()">Print / Save as PDF</button></div>
<h1>INVESTMENT AGREEMENT</h1><div>Project <strong><?= $h($p['name']); ?></strong> (<?= inv_project_ref($p); ?>)<?= $a ? ' · application ' . inv_app_ref($a) : ''; ?></div>
<?php if (inv_testing($conn)) { ?><p class="note">PILOT PHASE: no contribution is collected until RISE MOTIVE completes the legal requirements.</p><?php } ?>
<h3>1. Parties</h3><p>RISE MOTIVE Ltd (TIN 122923513), Kicukiro, Kigali, as project manager, and the investor <?= $h($me['name']); ?> (Customer ID <?= portal_customer_code($cid); ?>).</p>
<h3>2. Investment</h3><p>Amount invested: <strong><?= $amt !== null ? cl_money($amt) : 'the amount in the investor\'s approved application'; ?></strong>. Purpose: <?= $h($p['use_of_funds'] ?: $p['description']); ?></p>
<p>Investment period: <?= $p['start_date'] ? date('d M Y', strtotime($p['start_date'])) : '—'; ?> to <?= $p['end_date'] ? date('d M Y', strtotime($p['end_date'])) : '—'; ?>. The funds are committed for this period.</p>
<h3>3. Management fee and profit distribution</h3><p>RISE MOTIVE deducts an administrative and management cost of <strong><?= (float) $p['mgmt_fee_pct']; ?>% of the realized net project profit</strong> (never of the invested capital). The remaining <?= 100 - (float) $p['mgmt_fee_pct']; ?>% is distributed to investors:
<br><em>Investor's profit = (investor's verified contribution ÷ total verified project capital) × distributable profit.</em></p>
<p>Projections (revenue <?= cl_money($p['expected_revenue']); ?>, projected investor return <?= $pr['return_pct'] ?? '—'; ?>%) are <strong>estimates, not guaranteed</strong>. Payouts are based only on the actual results approved by RISE MOTIVE management.</p>
<h3>4. Capital recovery and losses</h3><p><?= nl2br($h($p['capital_recovery'])); ?></p><p><?= nl2br($h($p['distribution_conditions'])); ?></p>
<h3>5. Project-specific terms</h3><p><?= nl2br($h($p['agreement_terms'])); ?></p>
<h3>6. General terms</h3><p><?= nl2br($h(inv_setting($conn, 'agreement_general'))); ?></p>
<h3>7. Acceptance</h3><p><?= $a && $a['agreement_accepted_at'] ? 'Accepted electronically by <strong>' . $h($a['accepted_name']) . '</strong> on ' . date('d M Y \a\t H:i', strtotime($a['agreement_accepted_at'])) . '.' : '<em>Accepted in the Customer Portal when the application is submitted.</em>'; ?></p>
</body></html>