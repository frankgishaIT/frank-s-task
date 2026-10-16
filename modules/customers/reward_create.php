<?php
/**
 * NEW FILE: modules/customers/reward_create.php
 * Record a reward: the points are deducted at once; an Admin can also mark it as given now.
 */
require '../../config/db.php';
require_once '../../includes/reward_helpers.php';
require_once '../../includes/sms_messages.php';
require_role(['Admin', 'Manager']);

$isAdmin = current_user_role() === 'Admin';
$userId = (int) current_user_id();
$types = reward_types($conn);
$customers = mysqli_fetch_all(mysqli_query($conn, 'SELECT id, name, phone, loyalty_points FROM customers WHERE is_active = 1 ORDER BY name'), MYSQLI_ASSOC);

$v = [
    'customer_id' => (int) ($_POST['customer_id'] ?? ($_GET['customer_id'] ?? 0)),
    'reward_type_id' => (int) ($_POST['reward_type_id'] ?? 0),
    'reward_details' => trim($_POST['reward_details'] ?? ''),
    'reward_amount' => $_POST['reward_amount'] ?? '0',
    'points_required' => $_POST['points_required'] ?? '',
    'points_redeemed' => $_POST['points_redeemed'] ?? '',
    'date_earned' => $_POST['date_earned'] ?? date('Y-m-d'),
    'notes' => trim($_POST['notes'] ?? ''),
    'fulfil_now' => isset($_POST['fulfil_now']) && $isAdmin,
];

if (isset($_POST['save'])) {
    $amount = filter_var($v['reward_amount'], FILTER_VALIDATE_FLOAT);
    $required = filter_var($v['points_required'], FILTER_VALIDATE_INT);
    $redeemed = filter_var($v['points_redeemed'], FILTER_VALIDATE_INT);
    $date = DateTime::createFromFormat('Y-m-d', $v['date_earned']);

    if (!$v['customer_id'] || !$v['reward_type_id']) {
        $error = 'Please choose the customer and the reward type.';
    } elseif ($v['reward_details'] === '') {
        $error = 'Please describe the reward given.';
    } elseif ($amount === false || $amount < 0) {
        $error = 'Please enter a valid reward amount (0 or more).';
    } elseif ($required === false || $required <= 0 || $redeemed === false || $redeemed <= 0) {
        $error = 'Points required and points redeemed must be whole numbers above 0.';
    } elseif ($redeemed < $required) {
        $error = 'Points redeemed cannot be less than the points this reward costs.';
    } elseif (!$date || $date->format('Y-m-d') !== $v['date_earned'] || $v['date_earned'] > date('Y-m-d')) {
        $error = 'Please enter a valid date (not in the future).';
    } else {
        try {
            $evidence = reward_store_evidence($_FILES['evidence'] ?? null);
            $result = reward_create($conn, [
                'customer_id' => $v['customer_id'], 'reward_type_id' => $v['reward_type_id'], 'reward_details' => mb_substr($v['reward_details'], 0, 255),
                'reward_amount' => round($amount, 2), 'points_required' => $required, 'points_redeemed' => $redeemed,
                'date_earned' => $v['date_earned'], 'notes' => $v['notes'] !== '' ? $v['notes'] : null, 'evidence_file' => $evidence,
                'fulfil_now' => $v['fulfil_now'],
            ], $userId, $isAdmin);
            if ($result['ok']) {
                if ($result['fulfilled']) { sms_notify_reward_fulfilled($conn, $result['id'], $userId); }
                $msg = 'Reward ' . reward_code($result['id']) . ' recorded. ' . number_format($redeemed) . ' points deducted.'
                    . ($result['fulfilled'] ? ' It was fulfilled and the customer was notified by SMS.' : ' It is Pending until an Admin fulfils it.');
                header('Location: rewards.php?customer_id=' . $v['customer_id'] . '&success=' . urlencode($msg));
                exit;
            }
            if ($evidence) { @unlink(REWARD_EVIDENCE_DIR . '/' . $evidence); }
            $error = $result['error'];
        } catch (RuntimeException $ex) {
            $error = $ex->getMessage();
        }
    }
}

include '../../includes/header.php'; include '../../includes/sidebar.php';
$e = function ($x) { return htmlspecialchars((string) ($x ?? ''), ENT_QUOTES, 'UTF-8'); };
$modal_icon = 'bi-gift-fill'; $modal_title = 'Record Reward'; $modal_subtitle = 'Redeem a customer\'s Loyalty Points for a reward.';
?>
<div class="rm-modal-backdrop"><div class="rm-modal">
    <?php include '../../includes/model_header.php'; ?>
    <div class="rm-modal-body">
        <?php if (isset($error)) { ?><div class="alert alert-danger" style="border-radius:10px;"><?= $e($error); ?></div><?php } ?>
        <?php if (!$types) { ?><div class="alert alert-warning">No reward types are active. An Admin can switch them on under Reward Types.</div><?php } ?>
        <form method="POST" enctype="multipart/form-data">
            <?php
            // NEW: searchable customer field (type a name, phone or Customer ID instead of scrolling).
            $customerData = [];
            $selectedLabel = '';
            foreach ($customers as $c) {
                $code = 'RMC' . str_pad((string) $c['id'], 6, '0', STR_PAD_LEFT);
                $label = $code . ' — ' . $c['name'] . ' (' . number_format((int) $c['loyalty_points']) . ' points)';
                $customerData[] = ['id' => (int) $c['id'], 'label' => $label, 'search' => mb_strtolower($code . ' ' . $c['name'] . ' ' . ($c['phone'] ?? '')),
                                   'points' => (int) $c['loyalty_points']];
                if ($v['customer_id'] === (int) $c['id']) { $selectedLabel = $label; }
            }
            ?>
            <div class="mb-3 position-relative">
                <label class="form-label small fw-semibold text-muted">Customer</label>
                <input type="text" id="customerSearch" class="form-control rm-input" autocomplete="off"
                       placeholder="Type a name, phone number or Customer ID (RMC...)" value="<?= $e($selectedLabel); ?>">
                <input type="hidden" name="customer_id" id="customerId" value="<?= $v['customer_id'] ?: ''; ?>">
                <div id="customerList" class="list-group shadow position-absolute w-100" style="z-index:1060; max-height:260px; overflow-y:auto; display:none;"></div>
                <div class="form-text" id="customerHint"></div>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-6">
                    <label class="form-label small fw-semibold text-muted">Reward Type</label>
                    <select name="reward_type_id" id="typeSelect" class="form-select rm-input" required>
                        <option value="">Select type</option>
                        <?php foreach ($types as $t) { ?>
                        <option value="<?= (int) $t['id']; ?>" data-pays="<?= (int) $t['pays_money']; ?>" <?= $v['reward_type_id'] === (int) $t['id'] ? 'selected' : ''; ?>><?= $e($t['name']); ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-6">
                    <label class="form-label small fw-semibold text-muted">Reward Amount / Value (RWF)</label>
                    <input type="number" name="reward_amount" class="form-control rm-input" min="0" step="0.01" value="<?= $e($v['reward_amount']); ?>" required>
                    <div class="form-text" id="payNote"></div>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold text-muted">Reward Given</label>
                <input type="text" name="reward_details" class="form-control rm-input" maxlength="255" placeholder="e.g. RWF 5,000 cash, 2,000 MTN airtime, free notebook" value="<?= $e($v['reward_details']); ?>" required>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-4">
                    <label class="form-label small fw-semibold text-muted">Points Required</label>
                    <input type="number" name="points_required" id="pointsRequired" class="form-control rm-input" min="1" step="1" value="<?= $e($v['points_required']); ?>" required>
                </div>
                <div class="col-4">
                    <label class="form-label small fw-semibold text-muted">Points Redeemed</label>
                    <input type="number" name="points_redeemed" id="pointsRedeemed" class="form-control rm-input" min="1" step="1" value="<?= $e($v['points_redeemed']); ?>" required>
                </div>
                <div class="col-4">
                    <label class="form-label small fw-semibold text-muted">Remaining Points</label>
                    <input type="text" id="pointsRemaining" class="form-control rm-input" disabled>
                </div>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-6">
                    <label class="form-label small fw-semibold text-muted">Date Earned</label>
                    <input type="date" name="date_earned" class="form-control rm-input" max="<?= date('Y-m-d'); ?>" value="<?= $e($v['date_earned']); ?>" required>
                </div>
                <div class="col-6">
                    <label class="form-label small fw-semibold text-muted">Supporting Evidence (photo or PDF, optional)</label>
                    <input type="file" name="evidence" class="form-control rm-input" accept=".jpg,.jpeg,.png,.pdf">
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label small fw-semibold text-muted">Notes</label>
                <textarea name="notes" class="form-control rm-input" rows="2" style="height:auto;"><?= $e($v['notes']); ?></textarea>
            </div>
            <?php if ($isAdmin) { ?>
            <div class="form-check mb-4">
                <input class="form-check-input" type="checkbox" name="fulfil_now" value="1" id="fulfilNow" <?= $v['fulfil_now'] ? 'checked' : ''; ?>>
                <label class="form-check-label" for="fulfilNow">The reward is given now (fulfil and send the SMS)</label>
            </div>
            <?php } else { ?>
            <div class="alert alert-info small">The reward is saved as Pending. An Admin approves and fulfils it.</div>
            <?php } ?>
            <div class="d-grid gap-2 d-md-flex justify-content-end">
                <button type="submit" name="save" value="1" class="rm-btn rm-btn-primary"><i class="bi bi-check-circle-fill me-2"></i>Record Reward</button>
                <a href="rewards.php<?= $v['customer_id'] ? '?customer_id=' . (int) $v['customer_id'] : ''; ?>" class="rm-btn rm-btn-secondary">Cancel</a>
            </div>
        </form>
    </div>
</div></div>
<script>
(function () {
    var type = document.getElementById('typeSelect');
    // --- NEW: customer search ---
    var CUSTOMERS = <?= json_encode($customerData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    var search = document.getElementById('customerSearch'), hidden = document.getElementById('customerId');
    var list = document.getElementById('customerList'), hint = document.getElementById('customerHint');
    var selectedPoints = null, active = -1, shown = [];
    (function () { var c = CUSTOMERS.find(function (x) { return String(x.id) === hidden.value; }); if (c) { selectedPoints = c.points; } })();
    function render() {
        var q = search.value.trim().toLowerCase();
        shown = q === '' ? CUSTOMERS.slice(0, 8) : CUSTOMERS.filter(function (c) { return c.search.indexOf(q) !== -1; }).slice(0, 8);
        list.innerHTML = '';
        shown.forEach(function (c, i) {
            var a = document.createElement('button');
            a.type = 'button';
            a.className = 'list-group-item list-group-item-action' + (i === active ? ' active' : '');
            a.textContent = c.label;
            a.addEventListener('mousedown', function (ev) { ev.preventDefault(); choose(c); });
            list.appendChild(a);
        });
        if (!shown.length) {
            var none = document.createElement('div');
            none.className = 'list-group-item text-muted small';
            none.textContent = 'No customer matches "' + search.value + '"';
            list.appendChild(none);
        }
        list.style.display = '';
    }
    function choose(c) {
        hidden.value = c.id; search.value = c.label; selectedPoints = c.points;
        list.style.display = 'none'; hint.textContent = ''; active = -1; sync();
    }
    search.addEventListener('input', function () { hidden.value = ''; selectedPoints = null; active = -1; render(); sync(); });
    search.addEventListener('focus', function () { if (!hidden.value) { render(); } });
    search.addEventListener('blur', function () { setTimeout(function () { list.style.display = 'none'; }, 150); });
    search.addEventListener('keydown', function (ev) {
        if (list.style.display === 'none' && (ev.key === 'ArrowDown' || ev.key === 'ArrowUp')) { render(); }
        if (ev.key === 'ArrowDown') { ev.preventDefault(); active = Math.min(active + 1, shown.length - 1); render(); }
        else if (ev.key === 'ArrowUp') { ev.preventDefault(); active = Math.max(active - 1, 0); render(); }
        else if (ev.key === 'Enter') { if (shown[active] || shown.length === 1) { ev.preventDefault(); choose(shown[active] || shown[0]); } }
        else if (ev.key === 'Escape') { list.style.display = 'none'; }
    });
    search.form.addEventListener('submit', function (ev) {
        if (!hidden.value) { ev.preventDefault(); hint.innerHTML = '<span class="text-danger">Choose a customer from the list.</span>'; search.focus(); render(); }
    });
    var req = document.getElementById('pointsRequired'), red = document.getElementById('pointsRedeemed'), rem = document.getElementById('pointsRemaining');
    var payNote = document.getElementById('payNote');
    function sync() {
        var bal = selectedPoints;
        var used = parseInt(red.value || req.value || 0, 10) || 0;
        rem.value = bal === null ? '' : (bal - used).toLocaleString() + (bal - used < 0 ? ' (not enough points)' : '');
        rem.style.color = bal !== null && bal - used < 0 ? 'var(--accent-red)' : '';
        var t = type.options[type.selectedIndex];
        payNote.textContent = t && t.dataset.pays === '1' ? 'Paid from the RM Business Operating Fund when fulfilled.' : '';
    }
    req.addEventListener('input', function () { if (!red.value) { red.value = req.value; } sync(); });
    [type, red].forEach(function (el) { el.addEventListener('input', sync); el.addEventListener('change', sync); });
    sync();
})();
</script>
<?php include '../../includes/footer.php'; ?>