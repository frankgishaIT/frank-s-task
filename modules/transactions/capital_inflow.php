<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require '../../config/db.php';
require '../../includes/fund_helpers.php';

$isAdmin = isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin';
if (!$isAdmin) {
    header('Location: index.php?error=' . urlencode('Only an admin can add money to the Capital Fund.'));
    exit;
}
$userId = $_SESSION['user_id'] ?? null;

$sources = [
    'Initial Share Capital',
    'Additional Share Capital',
    'Owner Funding',
    'Business Loan',
    'Grant',
    'Donation',
    'Other Approved Funding',
];

$capRes = mysqli_query($conn, "SELECT * FROM funds WHERE code = 'CAPITAL' AND is_active = 1 LIMIT 1");
$capital = mysqli_fetch_assoc($capRes);
if (!$capital) {
    header('Location: index.php?error=' . urlencode('RM Capital Fund was not found. Run the fund migration first.'));
    exit;
}
$capitalId = (int) $capital['id'];

if (isset($_POST['save'])) {
    $source = $_POST['source_type'] ?? '';
    $amount = filter_input(INPUT_POST, 'amount', FILTER_VALIDATE_FLOAT);
    $date = $_POST['inflow_date'] ?? '';
    $note = trim($_POST['description'] ?? '');
    $validDate = DateTime::createFromFormat('Y-m-d', $date);

    if (!in_array($source, $sources, true)) {
        $error = 'Please select the source of the money.';
    } elseif ($amount === false || $amount <= 0) {
        $error = 'Please enter a valid amount.';
    } elseif (!$validDate || $validDate->format('Y-m-d') !== $date || $date > date('Y-m-d')) {
        $error = 'Please enter a valid date (not in the future).';
    } elseif ($source === 'Other Approved Funding' && $note === '') {
        $error = 'Please describe the approved funding in the Description field.';
    } else {
        try {
            $desc = mb_substr($note !== '' ? $note : $source, 0, 255);
            fund_record_movement($conn, $capitalId, 'CAPITAL_INFLOW', 'IN', $amount, substr($date, 0, 7), null, $userId, $desc, $source);
            header('Location: index.php?success=' . urlencode('Capital Fund increased by RWF ' . number_format($amount, 2) . ' (' . $source . ').'));
            exit;
        } catch (Throwable $e) {
            $error = 'Unable to save the capital inflow.';
        }
    }
}

$balance = fund_available($conn, $capitalId);
$recent = mysqli_fetch_all(mysqli_query($conn, "SELECT m.*, u.names AS who FROM fund_movements m LEFT JOIN users u ON m.created_by = u.id WHERE m.fund_id = $capitalId AND m.movement_type = 'CAPITAL_INFLOW' ORDER BY m.id DESC LIMIT 10"), MYSQLI_ASSOC);

include '../../includes/header.php'; include '../../includes/sidebar.php';

$modal_icon = 'bi-bank';
$modal_title = 'Add Capital Fund Money';
$modal_subtitle = 'Record money that did not come from business profit.';
?>

<div class="rm-modal-backdrop">
    <div class="rm-modal">
        <?php include '../../includes/model_header.php'; ?>

        <div class="rm-modal-body">
            <?php if (isset($error)) { ?>
            <div class="alert alert-danger d-flex align-items-center gap-2 mb-3" style="border-radius:10px; border:none; background:var(--accent-red-bg); color:var(--accent-red); font-size:13px; padding:10px 14px;">
                <i class="bi bi-exclamation-circle-fill"></i>
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <?php } ?>

            <div class="alert alert-info mb-3" style="border-radius:10px; border:none; font-size:13px; padding:10px 14px;">
                Current RM Capital Fund balance: <strong>RWF <?= number_format($balance, 2); ?></strong>.
                This money is not income and does not affect Net Profit.
            </div>

            <form method="POST">
                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Source</label>
                    <select name="source_type" class="form-select rm-input" required>
                        <option value="">Select source</option>
                        <?php foreach ($sources as $s) { ?>
                        <option value="<?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); ?>" <?= (($source ?? '') === $s) ? 'selected' : ''; ?>><?= htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php } ?>
                    </select>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Amount (RWF)</label>
                        <input type="number" name="amount" class="form-control rm-input" min="0.01" step="0.01" value="<?= htmlspecialchars(isset($amount) && $amount !== false ? (string) $amount : '', ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                    <div class="col-6">
                        <label class="form-label small fw-semibold text-muted">Date Received</label>
                        <input type="date" name="inflow_date" class="form-control rm-input" max="<?= date('Y-m-d'); ?>" value="<?= htmlspecialchars($date ?? date('Y-m-d'), ENT_QUOTES, 'UTF-8'); ?>" required>
                    </div>
                </div>

                <div class="mb-3">
                    <label class="form-label small fw-semibold text-muted">Description / Reference</label>
                    <textarea name="description" class="form-control rm-input" rows="3" style="height:auto;" placeholder="e.g. Bank deposit ref, lender name, grant name"><?= htmlspecialchars($note ?? '', ENT_QUOTES, 'UTF-8'); ?></textarea>
                </div>

                <div class="d-grid gap-2 d-md-flex justify-content-end mt-4">
                    <button type="submit" name="save" class="rm-btn rm-btn-primary">
                        <i class="bi bi-check-circle-fill me-2"></i>Add to Capital Fund
                    </button>
                    <a href="index.php" class="rm-btn rm-btn-secondary">
                        <i class="bi bi-x-circle-fill me-2"></i>Cancel
                    </a>
                </div>
            </form>

            <?php if ($recent) { ?>
            <hr class="my-4">
            <div class="small fw-semibold text-muted mb-2">Recent capital inflows</div>
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0" style="font-size:12px;">
                    <tr><th>Date</th><th>Source</th><th class="text-end">Amount</th></tr>
                    <?php foreach ($recent as $r) { ?>
                    <tr>
                        <td><?= date('d M Y', strtotime($r['created_at'])); ?></td>
                        <td><?= htmlspecialchars($r['source_type'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td>
                        <td class="text-end"><?= number_format((float) $r['amount'], 2); ?></td>
                    </tr>
                    <?php } ?>
                </table>
            </div>
            <?php } ?>
        </div> <!-- rm-modal-body -->
    </div> <!-- rm-modal -->
</div> <!-- rm-modal-backdrop -->

<?php include '../../includes/footer.php'; ?>