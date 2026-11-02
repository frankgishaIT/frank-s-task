<?php
// portal/index.php — the customer's home page (new design: four summary cards, recent invoices & receipts).
require __DIR__ . '/portal_start.php';
$open = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM sales WHERE customer_id = $cid AND status IN ('Credit', 'Partially Paid')"), MYSQLI_ASSOC);
$owed = array_sum(array_map('portal_sale_balance', $open));
$totalInvoices = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM sales WHERE customer_id = $cid AND status <> 'Cancelled'"))[0];
$recent = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM sales WHERE customer_id = $cid AND status <> 'Cancelled' ORDER BY id DESC LIMIT 5"), MYSQLI_ASSOC);
$requests = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM portal_requests WHERE customer_id = $cid AND status IN ('Open', 'In progress')"))[0];
$investments = 0;
try { $investments = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM inv_applications WHERE customer_id = $cid AND status NOT IN ('Draft', 'Rejected', 'Cancelled', 'Refunded')"))[0]; } catch (Throwable $e) { $investments = 0; }
$notice = portal_setting($conn, 'notice');
portal_header('Home', $me);
portal_alerts();
$cards = [
    ['wallet2', '#1E2FE0', 'Your Balance', portal_money($owed), count($open) . ' unpaid invoice(s)', null, null],
    ['file-earmark-text-fill', '#1E2FE0', 'Total Invoices', number_format($totalInvoices), null, 'history.php', 'View all invoices'],
    [$requests ? 'chat-dots-fill' : 'check-circle-fill', $requests ? '#1E2FE0' : '#0FA968', 'Open Requests', (string) $requests, null, 'requests.php', 'View requests'],
    ['briefcase-fill', '#1E2FE0', 'My Investments', (string) $investments, null, 'my_investments.php', 'View investments'],
];
?>
<h1 class="p-title">Welcome to your RM Customer Portal<?= trim((string) ($me['first_name'] ?? '')) !== '' ? ', ' . portal_e($me['first_name']) : ''; ?></h1>
<?php if (trim($notice) !== '') { ?><div class="alert alert-primary"><i class="bi bi-megaphone me-1"></i><?= nl2br(portal_e($notice)); ?></div><?php } ?>
<div class="row g-3 mb-4">
    <?php foreach ($cards as [$icon, $color, $label, $value, $sub, $link, $linkLabel]) { ?>
    <div class="col-6 col-xl-3"><div class="card h-100"><div class="card-body d-flex flex-column flex-sm-row gap-2 gap-sm-3 align-items-start">
        <div class="p-card-ico flex-shrink-0 d-flex align-items-center justify-content-center rounded-4" style="width:62px; height:62px; background:<?= $color === '#0FA968' ? '#E7F7EF' : '#EEF3FF'; ?>;"><i class="bi bi-<?= $icon; ?>" style="font-size:2rem; color:<?= $color; ?>;"></i></div>
        <div class="min-w-0"><div class="text-muted"><?= $label; ?></div>
            <div class="p-card-val fs-3 fw-bold lh-sm text-break <?= $label === 'Your Balance' && $owed > 0 ? 'text-danger' : ''; ?>"><?= portal_e($value); ?></div>
            <?php if ($sub) { ?><div class="small text-muted"><?= portal_e($sub); ?></div><?php } ?>
            <?php if ($link) { ?><a href="<?= $link; ?>" class="small fw-semibold text-decoration-none"><?= $linkLabel; ?> <i class="bi bi-arrow-right"></i></a><?php } ?></div>
    </div></div></div>
    <?php } ?>
</div>
<div class="card overflow-hidden">
    <div class="p-bar d-flex justify-content-between align-items-center gap-2 px-4 py-3 text-white" style="background:linear-gradient(90deg, #0B1A8C, #1E2FE0);">
        <span class="fs-5 fw-bold"><i class="bi bi-receipt me-2"></i>Recent invoices &amp; receipts</span>
        <a href="history.php" class="text-white fw-semibold">All history <i class="bi bi-arrow-right"></i></a></div>
    <div class="table-responsive"><table class="table mb-0 align-middle">
        <tr class="fw-bold"><th class="ps-4">Number</th><th>Date</th><th class="text-end">Total</th><th class="text-center">Balance</th><th class="p-hide-sm">Status</th><th class="text-center">Action</th></tr>
        <?php if (!$recent) { ?><tr><td colspan="6" class="text-center text-muted py-4">No invoices yet.</td></tr><?php } ?>
        <?php foreach ($recent as $s) { $bal = portal_sale_balance($s); ?>
        <tr><td class="ps-4"><?= portal_sale_number((int) $s['id']); ?></td><td class="text-nowrap"><?= date('d M Y', strtotime($s['sale_date'])); ?></td><td class="text-end"><?= portal_money($s['total_amount']); ?></td>
            <td class="text-center"><?= $bal > 0.009 ? '<span class="text-danger fw-semibold">' . portal_money($bal) . '</span>' : '<span class="text-success fw-semibold"><i class="bi bi-check-circle-fill me-1"></i>Paid</span>'; ?></td>
            <td class="p-hide-sm <?= $bal > 0.009 ? 'text-danger' : 'text-success'; ?> fw-semibold"><?= portal_e($s['status']); ?></td>
            <td class="text-center"><a href="invoice.php?id=<?= (int) $s['id']; ?>" class="btn btn-sm btn-outline-primary rounded-pill px-3"><i class="bi bi-eye me-1"></i>Open</a></td></tr>
        <?php } ?>
    </table></div>
</div>
<?php portal_footer();