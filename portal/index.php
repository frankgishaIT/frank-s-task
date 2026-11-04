<?php
// portal/index.php — the customer's home: welcome, the four RM Mutual Growth cards, pricing, account summary, recent invoices.
require __DIR__ . '/portal_start.php';
require_once __DIR__ . '/../includes/cl_helpers.php';
cl_ensure($conn);
$open = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM sales WHERE customer_id = $cid AND status IN ('Credit', 'Partially Paid')"), MYSQLI_ASSOC);
$owed = array_sum(array_map('portal_sale_balance', $open));
$totalInvoices = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM sales WHERE customer_id = $cid AND status <> 'Cancelled'"))[0];
$recent = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM sales WHERE customer_id = $cid AND status <> 'Cancelled' ORDER BY id DESC LIMIT 5"), MYSQLI_ASSOC);
$requests = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM portal_requests WHERE customer_id = $cid AND status IN ('Open', 'In progress')"))[0];
// RM Mutual Growth figures.
$opps = 0; $myInv = 0;
try {
    require_once __DIR__ . '/../includes/inv_helpers.php'; inv_ensure($conn);
    $opps = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM inv_projects WHERE status = 'Published' AND (funding_deadline IS NULL OR funding_deadline >= CURDATE())"))[0];
    $myInv = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM inv_applications WHERE customer_id = $cid AND status NOT IN ('Draft', 'Rejected', 'Cancelled', 'Refunded')"))[0];
} catch (Throwable $e) { }
$el = cl_eligibility($conn, $cid);
$points = (int) ($me['loyalty_points'] ?? 0);
$notice = portal_setting($conn, 'notice');
$first = trim((string) ($me['first_name'] ?? '')) !== '' ? $me['first_name'] : $me['name'];
portal_header('Home', $me);
portal_alerts();
$tiles = [
    ['invest.php', 'graph-up-arrow', 'Open RM Investment Opportunities', number_format($opps), $opps === 1 ? 'opportunity open now' : 'opportunities open now', 'See opportunities', '#1E2FE0', '#0B1A8C'],
    ['loans.php', 'cash-coin', 'Eligible Unsecured Loan', $el['limit'] > 0 ? cl_money($el['limit']) : 'Not yet', $el['limit'] > 0 ? 'you can apply for up to this amount' : 'grows with your fully paid purchases', 'Check eligibility', '#0FA968', '#087A4B'],
    ['rewards.php', 'gift', 'Request Loyalty Reward', number_format($points) . ' pts', 'free services, discounts or cash rewards', 'Request a reward', '#2A3352', '#11162B'],
    ['invest.php', 'piggy-bank-fill', 'Invest Now', $myInv ? $myInv . ' active' : 'Start', $myInv ? 'investment(s) in your portfolio' : 'grow with RISE MOTIVE projects', 'Explore & invest', '#3D5BFF', '#1E2FE0'],
];
?>
<h1 class="p-title">Hello, <?= portal_e($first); ?>! Welcome to RM Customer Portal.</h1>
<?php if (trim($notice) !== '') { ?><div class="alert alert-primary"><i class="bi bi-megaphone me-1"></i><?= nl2br(portal_e($notice)); ?></div><?php } ?>
<div class="row g-3 mb-3">
    <?php foreach ($tiles as [$href, $icon, $label, $value, $sub, $cta, $c1, $c2]) { ?>
    <div class="col-6 col-xl-3"><a href="<?= $href; ?>" class="card h-100 text-white text-decoration-none p-tile" style="background:linear-gradient(135deg, <?= $c1; ?>, <?= $c2; ?>);">
        <div class="card-body d-flex flex-column">
            <div class="d-flex justify-content-between align-items-start mb-2"><span class="p-tile-lbl fw-semibold" style="font-size:.95rem; line-height:1.25;"><?= $label; ?></span><i class="bi bi-<?= $icon; ?> ms-2" style="font-size:1.8rem; opacity:.9;"></i></div>
            <div class="p-tile-val fw-bold text-break" style="font-size:1.6rem; line-height:1.2;"><?= portal_e($value); ?></div>
            <div class="small" style="opacity:.85;"><?= portal_e($sub); ?></div>
            <div class="mt-auto pt-2 fw-semibold small"><?= $cta; ?> <i class="bi bi-arrow-right"></i></div>
        </div></a></div>
    <?php } ?>
</div>
<style>.p-tile { transition: transform .15s ease, box-shadow .15s ease; } .p-tile:hover { transform: translateY(-3px); box-shadow: 0 10px 24px rgba(16,24,64,.18); color: #fff; }</style>
<a href="#" data-bs-toggle="modal" data-bs-target="#pricesModal" class="card mb-4 text-decoration-none"><div class="card-body d-flex align-items-center gap-3">
    <div class="p-card-ico flex-shrink-0 d-flex align-items-center justify-content-center rounded-4" style="width:54px; height:54px; background:#EEF3FF;"><i class="bi bi-tags-fill" style="font-size:1.6rem; color:#1E2FE0;"></i></div>
    <div><div class="fw-bold" style="color:#0B1A8C;">Tariff Schedule</div><div class="small text-muted">Check the latest prices of all RISE MOTIVE services and products before you buy or request anything.</div></div>
    <i class="bi bi-chevron-right ms-auto" style="color:#1E2FE0;"></i></div></a>
<div class="row g-3 mb-4">
    <?php foreach ([['wallet2', 'Your Balance', portal_money($owed), count($open) . ' unpaid invoice(s)', null], ['file-earmark-text-fill', 'Total Invoices', number_format($totalInvoices), null, ['history.php', 'View all invoices']],
                    [$requests ? 'chat-dots-fill' : 'check-circle-fill', 'Open Requests', (string) $requests, null, ['requests.php', 'View requests']], ['briefcase-fill', 'My Investments', (string) $myInv, null, ['my_investments.php', 'View investments']]] as [$icon, $label, $value, $sub, $link]) { ?>
    <div class="col-6 col-xl-3"><div class="card h-100"><div class="card-body d-flex flex-column flex-sm-row gap-2 gap-sm-3 align-items-start">
        <div class="p-card-ico flex-shrink-0 d-flex align-items-center justify-content-center rounded-4" style="width:56px; height:56px; background:<?= $icon === 'check-circle-fill' ? '#E7F7EF' : '#EEF3FF'; ?>;"><i class="bi bi-<?= $icon; ?>" style="font-size:1.7rem; color:<?= $icon === 'check-circle-fill' ? '#0FA968' : '#1E2FE0'; ?>;"></i></div>
        <div class="min-w-0"><div class="text-muted small"><?= $label; ?></div>
            <div class="p-card-val fw-bold lh-sm text-break <?= $label === 'Your Balance' && $owed > 0 ? 'text-danger' : ''; ?>" style="font-size:1.45rem;"><?= portal_e($value); ?></div>
            <?php if ($sub) { ?><div class="small text-muted"><?= portal_e($sub); ?></div><?php } ?>
            <?php if ($link) { ?><a href="<?= $link[0]; ?>" class="small fw-semibold text-decoration-none"><?= $link[1]; ?> <i class="bi bi-arrow-right"></i></a><?php } ?></div>
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