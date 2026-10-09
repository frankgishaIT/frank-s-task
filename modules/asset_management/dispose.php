<?php
/**
 * NEW FILE: modules/assets/dispose.php
 * Spec section 12: an asset is Sold, Stolen, Damaged or Disposed.
 *
 *   1. Depreciation is brought up to today, so the book value is current.
 *   2. The asset becomes Lost (Stolen) or Disposed (Sold / Damaged / Disposed).
 *   3. Its book value leaves the RM Capital Fund (ASSET_OUT).
 *   4. Money received (sale or scrap) comes into the RM Capital Fund.
 *   5. The gain or loss (proceeds - book value) is recorded in Transactions.
 *
 * Example: printer worth RWF 100,000 is stolen
 *   -> Capital Fund -100,000, Expense "Asset Loss" 100,000.
 * Example: printer worth RWF 100,000 is sold for RWF 120,000
 *   -> Capital Fund -100,000 (asset) +120,000 (cash), Income "Asset Gain" 20,000.
 */
require '../../config/db.php';
require '../../includes/asset_helpers.php'; // also loads asset_fund_helpers.php and fund_helpers.php
require_role(['Admin']);

$assetId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$assetId) { header('Location: index.php'); exit; }

// Bring the value up to today before showing it.
asset_run_depreciation_catchup($conn, $assetId);

$assetStmt = mysqli_prepare($conn, 'SELECT * FROM assets WHERE id = ?');
mysqli_stmt_bind_param($assetStmt, 'i', $assetId);
mysqli_stmt_execute($assetStmt);
$asset = mysqli_fetch_assoc(mysqli_stmt_get_result($assetStmt));
if (!$asset) { header('Location: index.php?error=' . urlencode('Asset not found.')); exit; }

if (!in_array($asset['status'], ASSET_COUNTED_STATUSES, true)) {
    header('Location: view.php?id=' . (int) $assetId . '&error=' . urlencode('This asset is already ' . $asset['status'] . '.'));
    exit;
}

$disposalTypes = [
    'Sold'     => 'Sold: the asset was sold',
    'Stolen'   => 'Stolen: the asset was stolen or lost',
    'Damaged'  => 'Damaged: the asset is broken beyond use',
    'Disposed' => 'Disposed: thrown away, scrapped or given away',
];
// Money can only be received for a sale, or for scrap when an asset is disposed of.
$typesWithProceeds = ['Sold', 'Disposed'];

if (isset($_POST['confirm_dispose'])) {
    $type = $_POST['disposal_type'] ?? '';
    $disposalDate = $_POST['disposal_date'] ?? '';
    $proceeds = filter_input(INPUT_POST, 'proceeds', FILTER_VALIDATE_FLOAT);
    $proceeds = ($proceeds === false || $proceeds === null) ? 0.0 : round($proceeds, 2);
    $notes = trim($_POST['notes'] ?? '');
    $validDate = DateTime::createFromFormat('Y-m-d', $disposalDate);

    if (!isset($disposalTypes[$type])) {
        $error = 'Please choose what happened to the asset.';
    } elseif (!$validDate || $validDate->format('Y-m-d') !== $disposalDate) {
        $error = 'Please provide a valid date.';
    } elseif ($disposalDate > date('Y-m-d')) {
        $error = 'The date cannot be in the future.';
    } elseif ($disposalDate < $asset['acquisition_date']) {
        $error = 'The date cannot be before the asset was acquired.';
    } elseif ($proceeds < 0) {
        $error = 'The amount received cannot be negative.';
    } elseif ($type === 'Sold' && $proceeds <= 0) {
        $error = 'Please enter the amount the asset was sold for.';
    } elseif (!in_array($type, $typesWithProceeds, true) && $proceeds > 0) {
        $error = 'No money can be received for a ' . strtolower($type) . ' asset. Leave the amount at 0.';
    } else {
        $userId = current_user_id();
        $userId = $userId ? (int) $userId : null;

        mysqli_begin_transaction($conn);
        try {
            $lock = mysqli_prepare($conn, 'SELECT * FROM assets WHERE id = ? FOR UPDATE');
            mysqli_stmt_bind_param($lock, 'i', $assetId);
            mysqli_stmt_execute($lock);
            $current = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));
            if (!$current || !in_array($current['status'], ASSET_COUNTED_STATUSES, true)) {
                throw new RuntimeException('This asset has already been disposed of or marked as lost.');
            }

            // 1. Depreciation up to today.
            asset_run_depreciation_catchup($conn, $assetId);

            // An asset registered before the Capital Fund integration is added first, so
            // that removing it below takes out exactly what was put in.
            if (!asset_is_in_capital_fund($conn, $assetId)) {
                asset_sync_capital_fund($conn, (int) $assetId, 'ASSET_IN', $userId,
                    'Opening balance: asset ' . $current['asset_code'] . ' added to RM Capital Fund');
            }

            $valueStmt = mysqli_prepare($conn, 'SELECT current_value FROM assets WHERE id = ?');
            mysqli_stmt_bind_param($valueStmt, 'i', $assetId);
            mysqli_stmt_execute($valueStmt);
            $bookValue = round((float) mysqli_fetch_assoc(mysqli_stmt_get_result($valueStmt))['current_value'], 2);
            $gainLoss = round($proceeds - $bookValue, 2);

            $insert = mysqli_prepare($conn, 'INSERT INTO asset_disposals
                (asset_id, disposal_type, disposal_date, book_value, proceeds, gain_loss, notes, recorded_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $notesValue = $notes !== '' ? $notes : null;
            mysqli_stmt_bind_param($insert, 'issdddsi', $assetId, $type, $disposalDate, $bookValue, $proceeds, $gainLoss, $notesValue, $userId);
            mysqli_stmt_execute($insert);
            $disposalId = (int) mysqli_insert_id($conn);

            // 2. New status.
            $newStatus = $type === 'Stolen' ? 'Lost' : 'Disposed';
            $updStatus = mysqli_prepare($conn, 'UPDATE assets SET status = ? WHERE id = ?');
            mysqli_stmt_bind_param($updStatus, 'si', $newStatus, $assetId);
            mysqli_stmt_execute($updStatus);

            $label = 'Asset ' . $current['asset_code'] . ' (' . $current['asset_name'] . ') ' . strtolower($type);

            // 3. Book value out of the Capital Fund.
            asset_sync_capital_fund($conn, (int) $assetId, 'ASSET_OUT', $userId, $label . ': book value removed');

            // 4. Money received into the Capital Fund. Linked to the disposal ('ASSET_SALE'),
            //    NOT to the asset, so the asset's own fund total stays at 0.
            if ($proceeds > 0) {
                fund_record_capital_inflow($conn, $proceeds, $disposalDate, 'Asset sale proceeds',
                    $label . ': amount received', $userId, 'ASSET_SALE', $disposalId);
            }

            // 5. Gain or loss in Transactions (for the financial reports).
            //    fund_id is left empty: the Capital Fund was already updated above,
            //    so linking it to the fund would count it twice there.
            $transactionId = null;
            if (abs($gainLoss) >= 0.01) {
                $txType = $gainLoss > 0 ? 'Income' : 'Expense';
                $txCategory = $gainLoss > 0 ? 'Asset Gain' : 'Asset Loss';
                $txAmount = abs($gainLoss);
                $txDescription = $label . ': book value RWF ' . number_format($bookValue, 2)
                    . ', received RWF ' . number_format($proceeds, 2)
                    . ($gainLoss > 0 ? ', gain' : ', loss') . ' RWF ' . number_format($txAmount, 2);
                $approvedBy = $userId ?? 0;
                $tx = mysqli_prepare($conn, "INSERT INTO transactions
                    (category, transaction_type, amount, transaction_date, description, recorded_by, status, approved_by, approved_at, is_automatic)
                    VALUES (?, ?, ?, ?, ?, ?, 'approved', ?, NOW(), 1)");
                mysqli_stmt_bind_param($tx, 'ssdssii', $txCategory, $txType, $txAmount, $disposalDate, $txDescription, $userId, $approvedBy);
                mysqli_stmt_execute($tx);
                $transactionId = (int) mysqli_insert_id($conn);

                $link = mysqli_prepare($conn, 'UPDATE asset_disposals SET transaction_id = ? WHERE id = ?');
                mysqli_stmt_bind_param($link, 'ii', $transactionId, $disposalId);
                mysqli_stmt_execute($link);
            }

            mysqli_commit($conn);

            $message = 'Asset ' . $current['asset_code'] . ' marked as ' . $newStatus . '.';
            if ($gainLoss > 0) { $message .= ' Gain of RWF ' . number_format($gainLoss, 2) . ' recorded.'; }
            if ($gainLoss < 0) { $message .= ' Loss of RWF ' . number_format(abs($gainLoss), 2) . ' recorded.'; }
            header('Location: view.php?id=' . (int) $assetId . '&success=' . urlencode($message));
            exit;
            
                } catch (mysqli_sql_exception $e) {
            mysqli_rollback($conn);
            error_log('asset dispose failed for asset #' . $assetId . ': ' . $e->getMessage());
            $error = 'Unable to record this. Nothing was changed. Please try again.';
        } catch (RuntimeException $e) {
            mysqli_rollback($conn);
            $error = $e->getMessage() . ' Nothing was changed.';
        } catch (Throwable $e) {
            mysqli_rollback($conn);
            error_log('asset dispose failed for asset #' . $assetId . ': ' . $e->getMessage());
            $error = 'Unable to record this. Nothing was changed. Please try again.';
        }
    }
}

include '../../includes/header.php'; include '../../includes/sidebar.php';
$bookNow = (float) $asset['current_value'];
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Remove Asset <span class="text-muted fs-6 fw-normal"><?= htmlspecialchars($asset['asset_code'], ENT_QUOTES, 'UTF-8'); ?></span></h2>
    <a href="view.php?id=<?= (int) $assetId; ?>" class="rm-btn rm-btn-light">Back to Asset</a>
</div>

<?php if (isset($error)) { ?>
<div class="alert alert-danger d-flex align-items-center gap-2 mb-3" style="border-radius:10px; border:none; background:var(--accent-red-bg); color:var(--accent-red); font-size:13px; padding:10px 14px;">
    <i class="bi bi-exclamation-circle-fill"></i>
    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
</div>
<?php } ?>

<div class="card border-0 shadow-sm">
    <div class="card-body p-4">
        <table class="table table-borderless mb-4" style="max-width:520px;">
            <tr><td class="text-muted">Asset</td><td><?= htmlspecialchars($asset['asset_name'], ENT_QUOTES, 'UTF-8'); ?></td></tr>
            <tr><td class="text-muted">Status</td><td><?= htmlspecialchars($asset['status'], ENT_QUOTES, 'UTF-8'); ?></td></tr>
            <tr><td class="text-muted">Book value today</td><td class="fw-semibold">RWF <span id="bookValue" data-value="<?= htmlspecialchars((string) $bookNow, ENT_QUOTES, 'UTF-8'); ?>"><?= number_format($bookNow, 2); ?></span></td></tr>
        </table>

        <form method="POST" id="disposeForm">
            <input type="hidden" name="id" value="<?= (int) $assetId; ?>">

            <div class="row g-3 mb-3">
                <div class="col-md-6">
                    <label class="form-label small fw-semibold text-muted">What happened?</label>
                    <select name="disposal_type" id="disposalType" class="form-select rm-input" required>
                        <option value="">Choose…</option>
                        <?php foreach ($disposalTypes as $key => $label) { ?>
                        <option value="<?= $key; ?>" <?= ($_POST['disposal_type'] ?? '') === $key ? 'selected' : ''; ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-semibold text-muted">Date</label>
                    <input type="date" name="disposal_date" class="form-control rm-input" min="<?= htmlspecialchars($asset['acquisition_date'], ENT_QUOTES, 'UTF-8'); ?>" max="<?= date('Y-m-d'); ?>" value="<?= htmlspecialchars($_POST['disposal_date'] ?? date('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>" required>
                </div>
            </div>

            <div class="mb-3" id="proceedsGroup">
                <label class="form-label small fw-semibold text-muted">Amount received (RWF)</label>
                <input type="number" name="proceeds" id="proceeds" class="form-control rm-input" min="0" step="0.01" value="<?= htmlspecialchars($_POST['proceeds'] ?? '0', ENT_QUOTES, 'UTF-8'); ?>">
                <div class="form-text">Sale price, or scrap value if it was disposed of. This money goes into the RM Capital Fund.</div>
            </div>

            <div class="alert alert-light border mb-3" style="border-radius:10px; font-size:13px;" id="resultBox"></div>

            <label class="form-label small fw-semibold text-muted">Notes (optional)</label>
            <textarea name="notes" class="form-control rm-input mb-4" rows="3" placeholder="e.g. Police report number, buyer's name"><?= htmlspecialchars($_POST['notes'] ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>

            <div class="d-flex gap-2 justify-content-end">
                <a href="view.php?id=<?= (int) $assetId; ?>" class="rm-btn rm-btn-light">Never mind</a>
                <button type="submit" name="confirm_dispose" value="1" class="rm-btn rm-btn-danger">
                    <i class="bi bi-box-arrow-right me-1"></i>Confirm
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const typeSelect = document.getElementById('disposalType');
    const proceedsGroup = document.getElementById('proceedsGroup');
    const proceeds = document.getElementById('proceeds');
    const resultBox = document.getElementById('resultBox');
    const book = parseFloat(document.getElementById('bookValue').getAttribute('data-value')) || 0;
    const withProceeds = ['Sold', 'Disposed'];
    const fmt = function (n) { return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };

    function refresh() {
        const type = typeSelect.value;
        const allowProceeds = withProceeds.indexOf(type) !== -1;
        proceedsGroup.style.display = allowProceeds ? '' : 'none';
        if (!allowProceeds) { proceeds.value = '0'; }
        proceeds.required = type === 'Sold';

        if (!type) { resultBox.textContent = 'Choose what happened to see the effect.'; return; }
        const received = parseFloat(proceeds.value || 0);
        const diff = received - book;
        let text = 'RM Capital Fund: -RWF ' + fmt(book) + ' (asset value)';
        if (received > 0) { text += ', +RWF ' + fmt(received) + ' (amount received)'; }
        text += '. ';
        if (diff > 0.005) { text += 'Gain of RWF ' + fmt(diff) + ' will be recorded as Income.'; }
        else if (diff < -0.005) { text += 'Loss of RWF ' + fmt(-diff) + ' will be recorded as an Expense.'; }
        else { text += 'No gain or loss.'; }
        resultBox.textContent = text;
    }

    typeSelect.addEventListener('change', refresh);
    proceeds.addEventListener('input', refresh);
    refresh();

    document.getElementById('disposeForm').addEventListener('submit', function (e) {
        if (!confirm('This removes the asset from the business and cannot be undone here. Continue?')) { e.preventDefault(); }
    });
})();
</script>

<?php include '../../includes/footer.php'; ?>