<?php
/**
 * NEW FILE: modules/asset_management/valuation.php
 * Section 5: valuation treatment of one asset.
 *   - Change the valuation method (Depreciation / Amortization / No depreciation) from today.
 *   - Impairment: the recoverable amount is below the carrying value (loss in profit & loss).
 *   - Revaluation: only with reliable evidence (valuer + document). An increase goes to the Revaluation
 *     Reserve (equity), never to profit, after reversing earlier losses of the asset.
 * No money moves: only the asset part of the RM Capital Fund follows the new value.
 */
require '../../config/db.php';
require_once '../../includes/admin_error_guard.php'; // NEW: shows the Admin the exact reason if this page fails
require '../../includes/asset_helpers.php';
require_once '../../includes/asset_capital_helpers.php';
asset_capital_ensure_schema($conn); // NEW: adds any missing table / column of this feature automatically
require_role(['Admin']);

$assetId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$assetId) { header('Location: index.php'); exit; }
asset_run_depreciation_catchup($conn, $assetId);
$s = mysqli_prepare($conn, 'SELECT * FROM assets WHERE id = ?');
mysqli_stmt_bind_param($s, 'i', $assetId);
mysqli_stmt_execute($s);
$asset = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
if (!$asset) { header('Location: index.php?error=' . urlencode('Asset not found.')); exit; }
$inUse = in_array($asset['status'], ASSET_COUNTED_STATUSES, true);
$userId = (int) current_user_id();
$isIntangible = $asset['asset_class'] !== 'Tangible';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $inUse) {
    $action = $_POST['action'] ?? '';
    if ($action === 'method') {
        $method = $_POST['valuation_method'] ?? '';
        if (!in_array($method, ASSET_VALUATION_METHODS, true)) {
            $error = 'Choose a valid method.';
        } elseif (!$isIntangible && $method === 'Amortization') {
            $error = 'Amortization is for intangible assets. Use Depreciation for a tangible asset.';
        } elseif ($isIntangible && $method === 'Depreciation') {
            $error = 'An intangible asset is amortized, not depreciated.';
        } else {
            // From today on: the value so far stays as it is (no history is rewritten).
            $u = mysqli_prepare($conn, 'UPDATE assets SET valuation_method = ? WHERE id = ?');
            mysqli_stmt_bind_param($u, 'si', $method, $assetId);
            mysqli_stmt_execute($u);
            header('Location: valuation.php?id=' . (int) $assetId . '&success=' . urlencode('Valuation method changed to ' . $method . ' from today.'));
            exit;
        }
    } elseif (in_array($action, ['Impairment', 'Revaluation'], true)) {
        $newValue = filter_input(INPUT_POST, 'new_value', FILTER_VALIDATE_FLOAT);
        $valuer = trim($_POST['valuer'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        if ($newValue === false || $newValue === null || $newValue < 0) {
            $error = 'Enter a valid new value (0 or more).';
        } elseif ($action === 'Revaluation' && ($valuer === '' || empty($_FILES['evidence']['name']))) {
            $error = 'A revaluation needs reliable evidence: the valuer and a valuation document.';
        } elseif ($action === 'Revaluation' && $asset['asset_class'] === 'Internally developed software' && $newValue > (float) $asset['current_value']) {
            // Section 5: an asset's book value is not increased because its market value or usefulness grew.
            $error = 'Internally developed software cannot be revalued upward: its book value is not increased because its commercial value grew.';
        } elseif (mb_strlen($notes) < 10) {
            $error = 'Please explain the reason (at least 10 characters).';
        } else {
            try {
                $evidence = asset_store_valuation_evidence($_FILES['evidence'] ?? null);
                mysqli_begin_transaction($conn);
                $r = asset_record_valuation($conn, (int) $assetId, $action, (float) $newValue, date('Y-m-d'),
                    $valuer !== '' ? mb_substr($valuer, 0, 150) : null, $evidence, $notes, $userId);
                mysqli_commit($conn);
                $msg = $action . ' recorded: RWF ' . number_format($r['before'], 2) . ' -> RWF ' . number_format($r['after'], 2) . '.'
                    . (abs($r['to_reserve']) > 0.009 ? ' Revaluation Reserve ' . ($r['to_reserve'] > 0 ? '+' : '') . number_format($r['to_reserve'], 2) . '.' : '')
                    . (abs($r['to_pl']) > 0.009 ? ' Profit & loss ' . ($r['to_pl'] > 0 ? '+' : '') . number_format($r['to_pl'], 2) . '.' : '')
                    . ' No money moved.';
                header('Location: valuation.php?id=' . (int) $assetId . '&success=' . urlencode($msg));
                exit;
            } catch (Throwable $e) {
                @mysqli_rollback($conn);
                $error = $e instanceof RuntimeException && !($e instanceof mysqli_sql_exception) ? $e->getMessage() : 'Unable to record the valuation. Nothing was changed.';
                if ($e instanceof mysqli_sql_exception) { error_log('asset valuation failed: ' . $e->getMessage()); }
            }
        }
    }
}

$hist = mysqli_prepare($conn, 'SELECT v.*, u.names FROM asset_valuations v LEFT JOIN users u ON u.id = v.created_by WHERE v.asset_id = ? ORDER BY v.id DESC');
mysqli_stmt_bind_param($hist, 'i', $assetId);
mysqli_stmt_execute($hist);
$rows = mysqli_fetch_all(mysqli_stmt_get_result($hist), MYSQLI_ASSOC);
$e = function ($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); };
include '../../includes/header.php'; include '../../includes/sidebar.php';
?>
<?php if (isset($_GET['success'])) { ?><div class="alert alert-success"><?= $e($_GET['success']); ?></div><?php } ?>
<?php if (isset($error)) { ?><div class="alert alert-danger"><?= $e($error); ?></div><?php } ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <div><a href="index.php" class="small text-muted text-decoration-none"><i class="bi bi-arrow-left"></i> Back to Assets</a>
        <h2 class="mt-1"><?= $e($asset['asset_code'] . ' — ' . $asset['asset_name']); ?></h2>
        <div class="text-muted"><?= $e($asset['asset_class']); ?> · <?= $e($asset['valuation_method']); ?> · <?= $e($asset['status']); ?> · Funding: <?= $e($asset['funding_type'] ?? '—'); ?></div></div>
</div>
<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="small text-muted">Acquisition value</div><div class="fs-5 fw-bold">RWF <?= number_format((float) $asset['acquisition_value'], 2); ?></div></div></div></div>
    <div class="col-md-3"><div class="card border-0 shadow-sm"><div class="card-body"><div class="small text-muted">Net book value today</div><div class="fs-5 fw-bold">RWF <?= number_format((float) $asset['current_value'], 2); ?></div></div></div></div>
</div>

<?php if ($inUse) { ?>
<div class="row g-3 mb-4">
    <div class="col-md-4"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <h6>Valuation method</h6>
        <form method="POST"><input type="hidden" name="id" value="<?= (int) $assetId; ?>"><input type="hidden" name="action" value="method">
            <select name="valuation_method" class="form-select mb-2"><?php foreach (ASSET_VALUATION_METHODS as $v) { ?><option <?= $asset['valuation_method'] === $v ? 'selected' : ''; ?>><?= $v; ?></option><?php } ?></select>
            <button class="btn btn-outline-primary btn-sm">Change from today</button></form>
        <p class="small text-muted mt-2 mb-0">The value up to today is kept; the new method applies from today.</p>
    </div></div></div>
    <?php foreach (['Impairment' => 'The recoverable amount is below the book value. The loss goes to profit & loss.',
                    'Revaluation' => 'Only with reliable evidence. An increase goes to the Revaluation Reserve (equity), never to profit.'] as $act => $help) { ?>
    <div class="col-md-4"><div class="card border-0 shadow-sm h-100"><div class="card-body">
        <h6><?= $act; ?></h6>
        <form method="POST" enctype="multipart/form-data" onsubmit="return confirm('Record this <?= strtolower($act); ?>? No money will move.');">
            <input type="hidden" name="id" value="<?= (int) $assetId; ?>"><input type="hidden" name="action" value="<?= $act; ?>">
            <input type="number" name="new_value" class="form-control mb-2" min="0" step="0.01" placeholder="New value (RWF)" required>
            <label class="form-label small text-muted mb-1">Valuer / basis<?= $act === 'Revaluation' ? ' <span class="text-danger">*</span>' : ' (optional)'; ?></label>
            <input type="text" name="valuer" class="form-control mb-2" maxlength="150" placeholder="e.g. ABC Valuers Ltd, supplier quotation" <?= $act === 'Revaluation' ? 'required' : ''; ?>>
            <label class="form-label small text-muted mb-1">Valuation document (PDF, JPG or PNG, max 5 MB)<?= $act === 'Revaluation' ? ' <span class="text-danger">*</span>' : ' (optional)'; ?></label>
            <input type="file" name="evidence" class="form-control mb-2" accept=".pdf,.jpg,.jpeg,.png" <?= $act === 'Revaluation' ? 'required' : ''; ?>>
            <textarea name="notes" class="form-control mb-2" rows="2" placeholder="Reason" required></textarea>
            <button class="btn btn-outline-<?= $act === 'Impairment' ? 'danger' : 'primary'; ?> btn-sm">Record <?= strtolower($act); ?></button>
        </form>
        <p class="small text-muted mt-2 mb-0"><?= $help; ?></p>
    </div></div></div>
    <?php } ?>
</div>
<?php } ?>

<div class="card border-0 shadow-sm"><div class="card-body p-0">
<table class="table table-bordered bg-white mb-0 align-middle" style="font-size:13px;">
    <tr><th>Date</th><th>Type</th><th class="text-end">Before</th><th class="text-end">After</th><th class="text-end">Revaluation Reserve</th><th class="text-end">Profit &amp; Loss</th><th>Valuer</th><th>Reason</th><th>By</th></tr>
    <?php if (!$rows) { ?><tr><td colspan="9" class="text-center text-muted py-3">No impairment or revaluation recorded.</td></tr><?php } ?>
    <?php foreach ($rows as $r) { ?>
    <tr><td><?= date('d M Y', strtotime($r['valuation_date'])); ?></td><td><?= $e($r['valuation_type']); ?></td>
        <td class="text-end"><?= number_format((float) $r['value_before'], 2); ?></td><td class="text-end"><?= number_format((float) $r['value_after'], 2); ?></td>
        <td class="text-end"><?= number_format((float) $r['to_reserve'], 2); ?></td><td class="text-end"><?= number_format((float) $r['to_profit_loss'], 2); ?></td>
        <td><?= $e($r['valuer']); ?></td><td><?= $e($r['notes']); ?></td><td><?= $e($r['names']); ?></td></tr>
    <?php } ?>
</table>
</div></div>
<?php include '../../includes/footer.php'; ?>