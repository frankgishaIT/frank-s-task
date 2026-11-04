<?php
// NEW FILE: portal/ebm.php — REQUEST EBM RECEIPT, and the customer's EBM receipt requests with their status.
require __DIR__ . '/portal_start.php';
require_once __DIR__ . '/../includes/ebm_helpers.php';
ebm_ensure($conn);
$d = ['has_tin' => null, 'purchase_code' => '', 'tin' => '', 'full_name' => (string) $me['name'], 'phone' => (string) $me['phone'], 'invoice' => trim((string) ($_GET['invoice'] ?? ''))];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d = ['has_tin' => ($_POST['has_tin'] ?? '') === 'yes' ? true : (($_POST['has_tin'] ?? '') === 'no' ? false : null),
          'purchase_code' => trim((string) ($_POST['purchase_code'] ?? '')), 'tin' => preg_replace('/\s/', '', (string) ($_POST['tin'] ?? '')),
          'full_name' => mb_substr(trim((string) ($_POST['full_name'] ?? '')), 0, 160), 'phone' => trim((string) ($_POST['phone'] ?? '')), 'invoice' => trim((string) ($_POST['invoice'] ?? ''))];
    $err = portal_csrf_ok() ? ebm_validate($d) : ['Your session expired. Please try again.'];
    $sale = !$err ? portal_own_sale($conn, $cid, $d['invoice']) : null;
    if (!$err && !$sale) { $err[] = 'Invoice "' . $d['invoice'] . '" was not found in your account. Check the number on your invoice.'; }
    if (!$err && ($x = ebm_existing($conn, (int) $sale['id']))) { $err[] = 'An EBM receipt was already requested for this invoice (' . ebm_ref((int) $x['id']) . ', ' . strtolower($x['status']) . ').'; }
    if (!$err) {
        $sid = (int) $sale['id']; $tin = $d['has_tin'] ? $d['tin'] : null; $pc = $d['has_tin'] ? $d['purchase_code'] : null; $ph = $d['has_tin'] ? null : $d['phone']; $ht = $d['has_tin'] ? 1 : 0;
        $s = mysqli_prepare($conn, 'INSERT INTO ebm_requests (customer_id, sale_id, has_tin, purchase_code, tin, full_name, phone, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
        mysqli_stmt_bind_param($s, 'iiissss', $cid, $sid, $ht, $pc, $tin, $d['full_name'], $ph); mysqli_stmt_execute($s);
        $rid = (int) mysqli_insert_id($conn);
        portal_log($conn, $cid, 'ebm_requested', ebm_ref($rid) . ' for ' . portal_sale_number($sid));
        portal_notify_staff($conn, 'EBM receipt request ' . ebm_ref($rid), $me['name'] . ' requests the EBM receipt of ' . portal_sale_number($sid) . ($d['has_tin'] ? ' (TIN ' . $d['tin'] . ')' : '') . '.');
        header('Location: ebm.php?success=' . urlencode('Request ' . ebm_ref($rid) . ' sent for invoice ' . portal_sale_number($sid) . '. You will be notified when your EBM receipt is ready.')); exit;
    }
}
$rows = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM ebm_requests WHERE customer_id = $cid ORDER BY id DESC"), MYSQLI_ASSOC);
$badge = ['Submitted' => 'bg-primary', 'In progress' => 'bg-primary', 'Issued' => 'bg-success', 'Rejected' => 'bg-danger'];
portal_header('Request EBM Receipt', $me);
portal_alerts();
?>
<h1 class="p-title">Request EBM Receipt</h1>
<div class="row g-3">
<div class="col-lg-7"><div class="card"><div class="card-body p-4">
    <?php if (!empty($err)) { ?><div class="alert alert-danger"><ul class="mb-0 ps-3"><?php foreach ($err as $x) { ?><li><?= portal_e($x); ?></li><?php } ?></ul></div><?php } ?>
    <form method="POST" id="ebmForm" novalidate><?= portal_csrf_field(); ?>
        <label class="form-label fw-semibold">Do you have a TIN?</label>
        <div class="btn-group w-100 mb-3" role="group">
            <input type="radio" class="btn-check" name="has_tin" id="tinYes" value="yes" <?= $d['has_tin'] === true ? 'checked' : ''; ?> required><label class="btn btn-outline-primary" for="tinYes">Yes</label>
            <input type="radio" class="btn-check" name="has_tin" id="tinNo" value="no" <?= $d['has_tin'] === false ? 'checked' : ''; ?>><label class="btn btn-outline-primary" for="tinNo">No</label>
        </div>
        <div id="ebmFields" style="display:none;">
            <div class="tin-yes">
                <div class="alert alert-primary small"><i class="bi bi-info-circle me-1"></i><strong>Important notice:</strong> please use RISE MOTIVE's TIN <strong><?= EBM_RM_TIN; ?></strong> when requesting your Purchase Code.</div>
                <label class="form-label fw-semibold">Purchase Code *</label><input name="purchase_code" class="form-control mb-3" maxlength="40" value="<?= portal_e($d['purchase_code']); ?>" placeholder="The purchase code to put on the EBM invoice">
                <label class="form-label fw-semibold">TIN Number *</label><input name="tin" class="form-control mb-3" inputmode="numeric" maxlength="11" value="<?= portal_e($d['tin']); ?>" placeholder="Your 9-digit TIN">
            </div>
            <label class="form-label fw-semibold">Full Name *</label><input name="full_name" class="form-control mb-3" maxlength="160" value="<?= portal_e($d['full_name']); ?>" placeholder="Name to appear on the EBM receipt">
            <div class="tin-no"><label class="form-label fw-semibold">Telephone Number *</label><input name="phone" class="form-control mb-3" inputmode="tel" value="<?= portal_e($d['phone']); ?>" placeholder="07XXXXXXXX"></div>
            <label class="form-label fw-semibold">RM Invoice Number *</label><input name="invoice" class="form-control mb-1" maxlength="40" value="<?= portal_e($d['invoice']); ?>" placeholder="e.g. RM001208">
            <div class="form-text mb-3">The number on the invoice RISE MOTIVE gave you. <a href="history.php">See my invoices</a>.</div>
            <button class="btn btn-primary px-4">Submit request</button>
        </div>
    </form>
    <script>(function () {
        var f = document.getElementById('ebmForm');
        function sync() { var c = f.querySelector('input[name=has_tin]:checked'); document.getElementById('ebmFields').style.display = c ? '' : 'none';
            f.querySelectorAll('.tin-yes').forEach(function (el) { el.style.display = c && c.value === 'yes' ? '' : 'none'; });
            f.querySelectorAll('.tin-no').forEach(function (el) { el.style.display = c && c.value === 'no' ? '' : 'none'; }); }
        f.querySelectorAll('input[name=has_tin]').forEach(function (r) { r.addEventListener('change', sync); }); sync();
    })();</script>
</div></div></div>
<div class="col-lg-5"><div class="card"><div class="card-body">
    <h2>My EBM receipt requests</h2>
    <?php if (!$rows) { ?><div class="small text-muted">No requests yet.</div><?php } ?>
    <?php foreach ($rows as $r) { ?>
    <div class="border-bottom py-2 small">
        <div class="d-flex justify-content-between"><strong><?= ebm_ref((int) $r['id']); ?> · <?= portal_sale_number((int) $r['sale_id']); ?></strong><span class="badge <?= $badge[$r['status']] ?? 'bg-secondary'; ?>"><?= portal_e($r['status']); ?></span></div>
        <div class="text-muted"><?= date('d M Y', strtotime($r['created_at'])); ?> · <?= $r['has_tin'] ? 'TIN ' . portal_e($r['tin']) : 'No TIN'; ?> · <?= portal_e($r['full_name']); ?></div>
        <?php if ($r['status'] === 'Issued' && $r['receipt_ref']) { ?><a href="ebm_file.php?id=<?= (int) $r['id']; ?>" class="btn btn-sm btn-success mt-1"><i class="bi bi-download me-1"></i>Download EBM receipt</a><?php } ?>
        <?php if ($r['status'] === 'Rejected' && $r['staff_note']) { ?><div class="text-danger mt-1"><?= portal_e($r['staff_note']); ?></div><?php } ?>
    </div><?php } ?>
</div></div></div>
</div>
<?php portal_footer();