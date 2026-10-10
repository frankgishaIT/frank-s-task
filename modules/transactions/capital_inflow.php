<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require '../../config/db.php';
require '../../includes/fund_helpers.php';
require_once '../../includes/capital_entry_helpers.php'; // NEW: shared rules for add / edit / delete

$isAdmin = isset($_SESSION['user_role']) && strtolower($_SESSION['user_role']) === 'admin';
if (!$isAdmin) {
    header('Location: index.php?error=' . urlencode('Only an admin can add money to the Capital Fund.'));
    exit;
}
$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

// CHANGED: "Business Loan" is no longer offered here. Loans must be recorded in the Loans module,
// which puts the money into the RM Capital Fund AND keeps the repayment schedule and the amount
// still owed. A loan typed here has no schedule and is missing from "Outstanding loans".
$sources = array_values(array_filter(capital_sources(), function ($s) { return $s !== 'Business Loan'; }));

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

    $error = $source === 'Business Loan'
        ? 'Please record loans in the Loans module, so their repayments and the amount still owed are tracked.'
        : capital_entry_validate($source, $amount, $date, $note);
    if ($error === null) {
        try {
            // CHANGED: shared function; the amount is rounded to 2 decimals.
            capital_entry_record($conn, $source, (float) $amount, $date, $note, $userId);
            header('Location: index.php?success=' . urlencode('Capital Fund increased by RWF ' . number_format($amount, 2) . ' (' . $source . ').'));
            exit;
        } catch (Throwable $e) {
            error_log('add capital failed: ' . $e->getMessage());
            $error = 'Unable to save the capital inflow.';
        }
    }
}

// Messages sent back from the Edit / Delete pages.
if (empty($error) && !empty($_GET['error'])) {
    $error = (string) $_GET['error'];
}

$balance = fund_available($conn, $capitalId);
// CHANGED: only MANUAL entries are listed (before, loan inflows, stock receipts and other
// automatic inflows were mixed in), with Edit / Delete and their history.
$recent = capital_entry_recent($conn, 15);

include '../../includes/header.php'; include '../../includes/sidebar.php';

$modal_icon = 'bi-bank';
$modal_title = 'Add Capital Fund Money';
$modal_subtitle = 'Record money that did not come from business profit.';
?>

<div class="rm-modal-backdrop">
    <div class="rm-modal">
        <?php include '../../includes/model_header.php'; ?>

        <div class="rm-modal-body">
            <?php if (!empty($error)) { ?>
            <div class="alert alert-danger d-flex align-items-center gap-2 mb-3" style="border-radius:10px; border:none; background:var(--accent-red-bg); color:var(--accent-red); font-size:13px; padding:10px 14px;">
                <i class="bi bi-exclamation-circle-fill"></i>
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <?php } ?>
            <?php if (!empty($_GET['success'])) { ?>
            <div class="alert alert-success mb-3" style="border-radius:10px; border:none; font-size:13px; padding:10px 14px;">
                <?= htmlspecialchars($_GET['success'], ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <?php } ?>

            <div class="alert alert-info mb-3" style="border-radius:10px; border:none; font-size:13px; padding:10px 14px;">
                Current RM Capital Fund balance: <strong>RWF <?= number_format($balance, 2); ?></strong>.
                This money is not income and does not affect Net Profit. Loans are recorded in the Loans module.
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
                        <input type="number" name="amount" class="form-control rm-input" min="0.01" step="0.01" value="<?= htmlspecialchars(isset($amount) && $amount !== false && $amount !== null ? (string) $amount : '', ENT_QUOTES, 'UTF-8'); ?>" required>
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
            <div class="small fw-semibold text-muted mb-2">Recent capital entries</div>
            <div class="table-responsive">
                <table class="table table-sm table-bordered mb-0 align-middle" style="font-size:12px;">
                    <tr><th>#</th><th>Month</th><th>Source</th><th class="text-end">Amount</th><th>Status</th><th></th></tr>
                    <?php foreach ($recent as $r) {
                        $isLive = empty($r['reversed_by_id']); ?>
                    <tr<?= $isLive ? '' : ' class="text-muted"'; ?>>
                        <td><?= (int) $r['id']; ?></td>
                        <td><?= htmlspecialchars(date('M Y', strtotime($r['period'] . '-01')), ENT_QUOTES, 'UTF-8'); ?></td>
                        <td>
                            <?= htmlspecialchars($r['source_type'] ?? '—', ENT_QUOTES, 'UTF-8'); ?>
                            <?php if ($r['ref_type'] === 'CAPITAL_EDIT') { ?>
                                <div class="text-muted">Correction of #<?= (int) $r['ref_id']; ?></div>
                            <?php } ?>
                        </td>
                        <td class="text-end"><?= $isLive ? '' : '<s>'; ?><?= number_format((float) $r['amount'], 2); ?><?= $isLive ? '' : '</s>'; ?></td>
                        <td>
                            <?php if ($isLive) { ?>
                                <span class="badge bg-success">Active</span>
                            <?php } elseif (!empty($r['corrected_by_id'])) { ?>
                                <span class="badge bg-secondary">Corrected by #<?= (int) $r['corrected_by_id']; ?></span>
                            <?php } else { ?>
                                <span class="badge bg-dark">Deleted</span>
                            <?php } ?>
                        </td>
                        <td class="text-nowrap">
                            <?php if ($isLive) { ?>
                                <a href="capital_edit.php?id=<?= (int) $r['id']; ?>" class="btn btn-sm btn-outline-primary py-0">Edit</a>
                                <a href="capital_delete.php?id=<?= (int) $r['id']; ?>" class="btn btn-sm btn-outline-danger py-0">Delete</a>
                            <?php } ?>
                        </td>
                    </tr>
                    <?php } ?>
                </table>
            </div>
            <?php } ?>
        </div> <!-- rm-modal-body -->
    </div> <!-- rm-modal -->
</div> <!-- rm-modal-backdrop -->

<?php include '../../includes/footer.php'; ?>