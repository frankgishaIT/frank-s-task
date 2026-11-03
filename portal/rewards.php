<?php
/**
 * NEW FILE: portal/rewards.php — RM Mutual Growth > REQUEST LOYALTY REWARD.
 * The customer asks to use their Loyalty Points for a free service, a service discount or a cash reward (loan).
 * The request goes to the RISE MOTIVE team (Customer Portal > Requests); the team grants it through the existing
 * Customers > Rewards module, which deducts the points. Nothing is deducted by the request itself.
 */
require __DIR__ . '/portal_start.php';
const RW_BENEFITS = ['Free service' => ['tools', 'A service of RISE MOTIVE free of charge'], 'Service discount' => ['percent', 'A discount on a service you buy'],
                     'Cash reward (Loan)' => ['cash-coin', 'A cash reward or a loan backed by your relationship with RISE MOTIVE']];
$points = (int) ($me['loyalty_points'] ?? 0);
$services = mysqli_fetch_all(mysqli_query($conn, "SELECT product_name FROM products WHERE is_active = 1 AND item_type = 'Service' ORDER BY product_name"), MYSQLI_ASSOC);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $benefit = (string) ($_POST['benefit'] ?? ''); $use = (int) ($_POST['points'] ?? 0);
    $service = mb_substr(trim((string) ($_POST['service'] ?? '')), 0, 160); $details = trim((string) ($_POST['details'] ?? ''));
    $pending = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM portal_requests WHERE customer_id = $cid AND type = 'Loyalty reward request' AND status IN ('Open', 'In progress')"))[0];
    if (!portal_csrf_ok()) { $error = 'Your session expired. Please try again.'; }
    elseif (!isset(RW_BENEFITS[$benefit])) { $error = 'Choose the reward you would like.'; }
    elseif ($use < 1 || $use > $points) { $error = 'Choose how many points to use (you have ' . number_format($points) . ').'; }
    elseif ($benefit !== 'Cash reward (Loan)' && $service === '') { $error = 'Choose the service concerned.'; }
    elseif ($pending >= 2) { $error = 'You already have reward requests being handled. Please wait for an answer.'; }
    else {
        $subject = $benefit . ' · ' . number_format($use) . ' points' . ($service !== '' ? ' · ' . $service : '');
        $body = "Loyalty reward request\nReward: $benefit\nPoints to use: " . number_format($use) . ' (balance ' . number_format($points) . ")\n" . ($service !== '' ? "Service: $service\n" : '') . ($details !== '' ? "Details: $details\n" : '')
              . "\n[For RISE MOTIVE: grant it in Customers > Rewards for " . portal_customer_code($cid) . '; the points are deducted there.]';
        mysqli_begin_transaction($conn);
        $type = 'Loyalty reward request';
        $s = mysqli_prepare($conn, 'INSERT INTO portal_requests (customer_id, type, subject, updated_at) VALUES (?, ?, ?, NOW())');
        mysqli_stmt_bind_param($s, 'iss', $cid, $type, $subject); mysqli_stmt_execute($s);
        $rid = (int) mysqli_insert_id($conn);
        $m = mysqli_prepare($conn, 'INSERT INTO portal_request_messages (request_id, from_customer, body) VALUES (?, 1, ?)');
        mysqli_stmt_bind_param($m, 'is', $rid, $body); mysqli_stmt_execute($m);
        mysqli_commit($conn);
        portal_log($conn, $cid, 'reward_requested', portal_request_number($rid) . ' ' . $subject);
        portal_notify_staff($conn, 'Loyalty reward request ' . portal_request_number($rid), $me['name'] . ': ' . $subject);
        header('Location: request.php?id=' . $rid . '&success=' . urlencode('Reward request ' . portal_request_number($rid) . ' sent. The RISE MOTIVE team will answer here.')); exit;
    }
}
$mine = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM portal_requests WHERE customer_id = $cid AND type = 'Loyalty reward request' ORDER BY id DESC LIMIT 10"), MYSQLI_ASSOC);
portal_header('Request Loyalty Reward', $me);
portal_alerts();
?>
<h1 class="p-title">Request Loyalty Reward</h1>
<div class="row g-3">
<div class="col-lg-7"><div class="card"><div class="card-body p-4">
    <div class="d-flex align-items-center gap-3 mb-3 p-3 rounded-3" style="background:#EEF3FF;"><i class="bi bi-gift-fill" style="font-size:2rem; color:#1E2FE0;"></i>
        <div><div class="small text-muted">Your Loyalty Points</div><div class="fw-bold" style="font-size:1.6rem; color:#0B1A8C;"><?= number_format($points); ?> points</div></div></div>
    <?php if (isset($error)) { ?><div class="alert alert-danger"><?= portal_e($error); ?></div><?php } ?>
    <?php if ($points < 1) { ?><div class="alert alert-info mb-0">You have no Loyalty Points yet. You earn them with every fully paid purchase from RISE MOTIVE.</div>
    <?php } else { ?>
    <form method="POST"><?= portal_csrf_field(); ?>
        <label class="form-label fw-semibold">What would you like?</label>
        <div class="row g-2 mb-3"><?php foreach (RW_BENEFITS as $b => [$icon, $hint]) { $id = 'b' . md5($b); ?>
            <div class="col-md-4"><input type="radio" class="btn-check" name="benefit" id="<?= $id; ?>" value="<?= portal_e($b); ?>" <?= ($_POST['benefit'] ?? '') === $b ? 'checked' : ''; ?> required>
                <label class="btn btn-outline-primary w-100 h-100 text-start p-3" for="<?= $id; ?>"><i class="bi bi-<?= $icon; ?> d-block mb-1" style="font-size:1.5rem;"></i><span class="fw-bold d-block"><?= portal_e($b); ?></span><span class="small" style="opacity:.8;"><?= portal_e($hint); ?></span></label></div>
        <?php } ?></div>
        <div class="row g-2 mb-3">
            <div class="col-md-5"><label class="form-label fw-semibold">Points to use</label><input type="number" name="points" min="1" max="<?= $points; ?>" class="form-control" required value="<?= portal_e($_POST['points'] ?? ''); ?>"></div>
            <div class="col-md-7" id="svcBox"><label class="form-label fw-semibold">Service concerned</label><select name="service" class="form-select"><option value="">Choose a service</option><?php foreach ($services as $sv) { ?><option <?= ($_POST['service'] ?? '') === $sv['product_name'] ? 'selected' : ''; ?>><?= portal_e($sv['product_name']); ?></option><?php } ?></select></div>
        </div>
        <label class="form-label fw-semibold">Details (optional)</label>
        <textarea name="details" class="form-control mb-3" rows="3" maxlength="1500" placeholder="e.g. when you need the service, or what the cash reward is for"><?= portal_e($_POST['details'] ?? ''); ?></textarea>
        <button class="btn btn-primary px-4">Send request</button>
        <div class="small text-muted mt-2">The RISE MOTIVE team reviews every request and confirms the reward and the points used. Your points are only deducted when the reward is granted.</div>
    </form>
    <script>(function () { var box = document.getElementById('svcBox'); function s() { var c = document.querySelector('input[name=benefit]:checked'); box.style.display = c && c.value === 'Cash reward (Loan)' ? 'none' : ''; }
        document.querySelectorAll('input[name=benefit]').forEach(function (r) { r.addEventListener('change', s); }); s(); })();</script>
    <?php } ?>
</div></div></div>
<div class="col-lg-5"><div class="card"><div class="card-body">
    <h2>My reward requests</h2>
    <?php if (!$mine) { ?><div class="small text-muted">None yet.</div><?php } foreach ($mine as $r) { ?>
    <div class="d-flex justify-content-between align-items-center border-bottom py-2 small"><a href="request.php?id=<?= (int) $r['id']; ?>"><?= portal_request_number((int) $r['id']); ?> · <?= portal_e($r['subject']); ?></a>
        <span class="badge <?= in_array($r['status'], ['Resolved', 'Closed'], true) ? 'bg-success' : 'bg-primary'; ?>"><?= portal_e($r['status']); ?></span></div><?php } ?>
    <div class="small text-muted mt-3"><i class="bi bi-info-circle"></i> Looking for a loan? <a href="loans.php">RM Loans</a> shows your unsecured eligibility.</div>
</div></div></div>
</div>
<?php portal_footer();