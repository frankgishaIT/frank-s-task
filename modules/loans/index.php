<?php
require '../../config/db.php';
require '../../includes/loan_helpers.php';
require_role(['Admin']);

$totals = loan_report_totals($conn);
$loansResult = mysqli_query($conn, 'SELECT * FROM loans ORDER BY FIELD(status, "Active","Defaulted","Fully Paid","Cancelled"), created_at DESC');

function money($v) { return number_format((float) $v, 2); }

$statusBadge = [
    'Active' => ['#0FA968', '#E1F7EE'],
    'Fully Paid' => ['#1E2FE0', '#E6E8FD'],
    'Defaulted' => ['#E24B4A', '#FCEAEA'],
    'Cancelled' => ['#8A90A3', '#F1F3F9'],
];

include '../../includes/header.php'; include '../../includes/sidebar.php';
?>

<?php if (isset($_GET['success'])) { ?>
<div class="alert alert-success alert-dismissible fade show" role="alert">
    <?= htmlspecialchars($_GET['success'], ENT_QUOTES, 'UTF-8'); ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php } ?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Loan Management</h2>
    <a href="create.php" class="rm-btn rm-btn-primary"><i class="bi bi-plus-circle me-2"></i>New Loan</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small mb-1">Total Outstanding</div>
            <div class="fs-5 fw-bold" style="color:var(--accent-red);">RWF <?= money($totals['total_outstanding']); ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small mb-1">Total Principal Repaid</div>
            <div class="fs-5 fw-bold">RWF <?= money($totals['total_principal_repaid']); ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small mb-1">Total Interest Paid</div>
            <div class="fs-5 fw-bold">RWF <?= money($totals['total_interest_paid']); ?></div>
        </div></div>
    </div>
    <div class="col-6 col-md-3">
        <div class="card border-0 shadow-sm h-100"><div class="card-body">
            <div class="text-muted small mb-1">Total Amount Paid</div>
            <div class="fs-5 fw-bold" style="color:var(--accent-blue);">RWF <?= money($totals['total_paid']); ?></div>
        </div></div>
    </div>
</div>

<table>
    <thead>
        <tr>
            <th>Lender</th>
            <th>Lender Type</th>
            <th>Lender Amount</th>
            <th>Outstanding Balance</th>
            <th>Next Due</th>
            <th>Status</th>
            <th>Action</th>
        </tr>
    </thead>
    <tbody>
        <?php if (mysqli_num_rows($loansResult) === 0) { ?>
        <tr><td colspan="7" class="text-center text-muted py-4">No loans recorded yet.</td></tr>
        <?php } ?>
        <?php while ($loan = mysqli_fetch_assoc($loansResult)) {
            $next = $loan['status'] === 'Active' ? loan_next_installment($conn, $loan['id']) : null;
            $badge = $statusBadge[$loan['status']] ?? ['#8A90A3', '#F1F3F9'];
        ?>
        <tr>
            <td><?= htmlspecialchars($loan['lender'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td><?= htmlspecialchars($loan['loan_type'], ENT_QUOTES, 'UTF-8'); ?></td>
            <td>RWF <?= money($loan['loan_amount']); ?></td>
            <td>RWF <?= money($loan['outstanding_balance']); ?></td>
            <td>
                <?php if ($next) { ?>
                    <?= date('d M Y', strtotime($next['due_date'])); ?> — RWF <?= money($next['amount_due'] - $next['paid_amount']); ?>
                <?php } else { ?>
                    <span class="text-muted">—</span>
                <?php } ?>
            </td>
            <td><span style="font-size:11px; font-weight:700; color:<?= $badge[0]; ?>; background:<?= $badge[1]; ?>; padding:3px 10px; border-radius:8px;"><?= htmlspecialchars($loan['status'], ENT_QUOTES, 'UTF-8'); ?></span></td>
           <td>
        <a href="view.php?id=<?= (int) $loan['id']; ?>" class="rm-btn rm-btn-light rm-btn-sm">View</a>
        <a href="edit.php?id=<?= (int) $loan['id']; ?>" class="rm-btn rm-btn-light rm-btn-sm">Edit</a>
         </td>
        </tr>
        <?php } ?>
    </tbody>
</table>

<?php include '../../includes/footer.php'; ?>