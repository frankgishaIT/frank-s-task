<?php
require '../../config/db.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?success=Invalid invoice requested.'); exit; }

$statement = mysqli_prepare($conn, 'SELECT sales.*, customers.name AS customer_name, customers.phone AS customer_phone, customers.email AS customer_email, customers.address AS customer_address, users.names AS recorded_by_name, users.role AS recorded_by_role, users.phone AS recorded_by_phone
    FROM sales
    LEFT JOIN customers ON sales.customer_id = customers.id
    LEFT JOIN users ON sales.recorded_by = users.id
    WHERE sales.id = ?');
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);
$sale = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));
if (!$sale) { header('Location: index.php?success=Sale not found.'); exit; }

if (current_user_role() === 'Employee' && (int) $sale['recorded_by'] !== (int) current_user_id()) {
    http_response_code(403);
    include '../../includes/access_denied.php';
    exit;
}

$itemsStatement = mysqli_prepare($conn, 'SELECT sale_items.*, products.product_name, products.product_code FROM sale_items LEFT JOIN products ON sale_items.product_id = products.id WHERE sale_id = ? ORDER BY sale_items.id');
mysqli_stmt_bind_param($itemsStatement, 'i', $id);
mysqli_stmt_execute($itemsStatement);
$items = mysqli_stmt_get_result($itemsStatement);

$isPending = $sale['status'] === 'Pending Discount Approval';
$isCancelled = $sale['status'] === 'Cancelled';

function money($v) { return number_format((float) $v, 2); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title><?= $isPending ? 'Draft Invoice' : ($isCancelled ? 'Cancelled Sale' : (($sale['payment_method'] === 'Credit' || $sale['status'] === 'Credit') ? 'Invoice' : 'Receipt')); ?> RM<?= str_pad($sale['id'], 6, '0', STR_PAD_LEFT); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root { --accent-blue:#1E2FE0; --ink:#1E2333; --muted:#8A90A3; --border-soft:#E4E8F2; --accent-teal:#0FA968; --accent-red:#E24B4A; }
        * { box-sizing: border-box; }
        body { margin:0; background:#EEF1F8; font-family:'Segoe UI', Arial, sans-serif; color:var(--ink); }
        .toolbar { max-width:820px; margin:24px auto 0; padding:0 20px; display:flex; justify-content:flex-end; gap:10px; }
        .toolbar a, .toolbar button { border:none; border-radius:10px; padding:10px 18px; font-size:14px; font-weight:500; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:6px; }
        .btn-download { background:var(--accent-blue); color:#fff; }
        .btn-back { background:#fff; color:var(--ink); border:1px solid var(--border-soft) !important; }
        .sheet { max-width:820px; margin:20px auto 60px; background:#fff; border-radius:16px; box-shadow:0 10px 30px rgba(20,24,60,.08); padding:48px 56px; position:relative; overflow:hidden; }
        .watermark { position:absolute; top:40%; left:50%; transform:translate(-50%,-50%) rotate(-25deg); font-size:64px; font-weight:800; color:rgba(226,75,74,.12); pointer-events:none; white-space:nowrap; }
        .sheet-header { display:flex; justify-content:space-between; align-items:flex-start; border-bottom:2px solid var(--ink); padding-bottom:20px; margin-bottom:28px; }
        .brand { font-size:22px; font-weight:800; letter-spacing:.02em; }
        .brand-sub { font-size:12px; color:var(--muted); margin-top:2px; }
        .doc-title { text-align:right; }
        .doc-title h2 { margin:0; font-size:20px; font-weight:700; }
        .doc-title .num { font-size:13px; color:var(--muted); margin-top:2px; }
        .status-pill { display:inline-block; margin-top:8px; padding:4px 12px; border-radius:20px; font-size:11px; font-weight:700; letter-spacing:.03em; text-transform:uppercase; }
        .status-paid { background:#E1F7EE; color:var(--accent-teal); }
        .status-pending { background:#FDF3E3; color:#B8860B; }
        .status-credit { background:#FCE9E9; color:var(--accent-red); }
        .status-cancelled { background:#F1F3F9; color:var(--muted); }
        .info-grid { display:grid; grid-template-columns:1fr 1fr; gap:4px 24px; margin-bottom:32px; font-size:13px; }
        .info-grid .label { color:var(--muted); display:inline-block; width:110px; }
        table.items { width:100%; border-collapse:collapse; font-size:13px; margin-bottom:20px; }
        table.items th { text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.03em; color:var(--muted); padding:8px 0; border-bottom:1px solid var(--border-soft); }
        table.items td { padding:10px 0; border-bottom:1px solid var(--border-soft); }
        table.items td.amount, table.items th.amount { text-align:right; font-variant-numeric:tabular-nums; }
        .totals { margin-left:auto; width:280px; font-size:13px; }
        .totals .row { display:flex; justify-content:space-between; padding:6px 0; }
        .totals .grand { border-top:2px solid var(--ink); margin-top:8px; padding-top:12px; font-size:18px; font-weight:800; color:var(--accent-blue); }
        .signature-block { margin-top:56px; padding-top:24px; border-top:1px solid var(--border-soft); }
        .signature-block .sig-heading { font-size:13px; font-weight:700; margin-bottom:18px; }
        .signature-block .sig-line { font-size:13px; margin-bottom:16px; display:flex; align-items:baseline; gap:8px; }
        .signature-block .sig-line .sig-label { color:var(--muted); flex-shrink:0; }
        .signature-block .sig-line .sig-fill { flex:1; min-width:120px; }
        .signature-block .sig-line .sig-value { font-weight:600; }
        .footnote { margin-top:36px; font-size:11px; color:var(--muted); line-height:1.6; }
        @media print {
            body{background:#fff; font-size:16px;}
            .toolbar{display:none;}
            .sheet{box-shadow:none;margin:0;border-radius:0;max-width:100%;padding:24px 20px;}
            .brand{font-size:26px;}
            .brand-sub{font-size:14px;}
            .doc-title h2{font-size:24px;}
            .doc-title .num{font-size:15px;}
            .status-pill{font-size:13px; padding:5px 14px;}
            .info-grid{font-size:15px; gap:8px 24px;}
            .info-grid .label{width:130px;}
            table.items{font-size:15px;}
            table.items th{font-size:13px; padding:10px 0;}
            table.items td{padding:12px 0;}
            .totals{font-size:15px; width:320px;}
            .totals .grand{font-size:22px;}
            .signature-block{font-size:15px;}
            .signature-block .sig-heading{font-size:15px;}
            .signature-block .sig-line{font-size:15px;}
            .footnote{font-size:13px;}
        }
    </style>
</head>
<body>
<div class="toolbar">
    <a href="index.php" class="btn-back"><i class="bi bi-arrow-left"></i> Back</a>
    <button class="btn-download" onclick="window.print()"><i class="bi bi-download"></i> Download PDF</button>
</div>

<div class="sheet">
    <?php if ($isPending) { ?><div class="watermark">PENDING APPROVAL</div><?php } elseif ($isCancelled) { ?><div class="watermark">CANCELLED</div><?php } ?>

    <div class="sheet-header">
        <div>
            <img src="../../assets/images/logo.jpg"
             alt="Rise Motive Logo"
             style="width:60px; height:60px; object-fit:contain; vertical-align:middle; margin-right:8px; border-radius:8px;">
            <div class="brand">RISE MOTIVE</div>
            <div class="brand-sub">TIN Number:122923513<br>website: www.risemotive.rw    
                <h4>Kicukiro District, Kigali, Rwanda</h4>
            </div>
        </div>
        <div class="doc-title">
            <h2><?= $isPending ? 'Draft Invoice' : (($sale['status'] === 'Credit' || $sale['payment_method'] === 'Credit') ? 'Invoice' : 'Receipt'); ?></h2>
            <div class="num">No. <?= str_pad($sale['id'], 6, '0', STR_PAD_LEFT); ?></div>
            <span class="status-pill <?= $isPending ? 'status-pending' : ($isCancelled ? 'status-cancelled' : ($sale['status'] === 'Credit' ? 'status-credit' : 'status-paid')); ?>"><?= htmlspecialchars($sale['status'], ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    </div>

    <div class="info-grid">
        <div><span class="label">Customer</span><?= htmlspecialchars($sale['customer_name'] ?? 'Walk-in Customer', ENT_QUOTES, 'UTF-8'); ?></div>
        <div><span class="label">Date</span><?= date('d M Y', strtotime($sale['sale_date'])); ?></div>
        <div><span class="label">Phone</span><?= htmlspecialchars($sale['customer_phone'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></div>
        <div><span class="label">Payment Method</span><?= htmlspecialchars($sale['payment_method'], ENT_QUOTES, 'UTF-8'); ?></div>
        <div><span class="label">Served By</span><?= htmlspecialchars($sale['recorded_by_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></div>
        <div><span class="label">Amount Paid</span>RWF <?= money($sale['amount_paid']); ?></div>
    </div>

    <table class="items">
        <tr><th>Item</th><th>Type</th><th class="amount">Qty</th><th class="amount">Unit Price</th><th class="amount">Total</th></tr>
        <?php while ($item = mysqli_fetch_assoc($items)) { ?>
        <tr>
            <td>
                <?= htmlspecialchars($item['product_name'] ?? $item['service_name'] ?? 'Item', ENT_QUOTES, 'UTF-8'); ?>
                <?php if ($item['item_type'] === 'Product') { ?>
                    <span class="text-muted" style="font-size:11px;">(<?= htmlspecialchars($item['product_code'] ?? '', ENT_QUOTES, 'UTF-8'); ?> — <?= htmlspecialchars($item['unit'] ?? 'Pieces', ENT_QUOTES, 'UTF-8'); ?>)</span>
                <?php } ?>
            </td>
            <td><?= htmlspecialchars($item['item_type'] === 'Product' ? 'Item' : 'Service', ENT_QUOTES, 'UTF-8'); ?></td>
            <td class="amount"><?= (int) $item['quantity']; ?></td>
            <td class="amount"><?= money($item['unit_price']); ?></td>
            <td class="amount"><?= money($item['line_total']); ?></td>
        </tr>
        <?php } ?>
    </table>

    <div class="totals">
        <div class="row"><span>Subtotal</span><span>RWF <?= money($sale['subtotal']); ?></span></div>
        <div class="row"><span>Discount</span><span>&minus; RWF <?= money($sale['discount_amount']); ?></span></div>
        <div class="row grand"><span>Total</span><span>RWF <?= money($sale['total_amount']); ?></span></div>
    </div>

    <?php if (!$isPending && !$isCancelled) { ?>
    <div class="signature-block">
        <div class="sig-heading">For RISE MOTIVE</div>
        <div class="sig-heading" style="font-weight:600; color:var(--muted); margin-top:-8px;">Invoice Issued and Approved by:</div>
        <div class="sig-line"><span class="sig-label">Name:</span><span class="sig-fill sig-value"><?= htmlspecialchars($sale['recorded_by_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></span></div>
        <div class="sig-line"><span class="sig-label">Position:</span><span class="sig-fill sig-value"><?= htmlspecialchars(ucfirst($sale['recorded_by_role'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></span></div>
        <div class="sig-line"><span class="sig-label">Contact:</span><span class="sig-fill sig-value"><?= htmlspecialchars($sale['recorded_by_phone'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></span></div>
    </div>
    <?php } ?>

    <div class="footnote">
        <?php if ($isPending) { ?>
            This is a draft invoice. The discount on this sale is awaiting manager approval — stock and finance have not been updated yet.
        <?php } elseif ($isCancelled) { ?>
            This sale was cancelled and was not applied to inventory or finance records.
        <?php } else { ?>
            Generated automatically by MY MOTIVE on <?= date('d M Y, H:i'); ?>. Thank you for your business.
        <?php } ?>
    </div>
</div>
</body>
</html>