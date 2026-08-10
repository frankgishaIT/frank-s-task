<?php
require '../../config/db.php';
 $id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT); 
 if (!$id) { header('Location: index.php?success=Invalid transaction selected.');
  exit; }
   $statement = mysqli_prepare($conn, 'SELECT transactions.*, users.names AS recorder_name FROM transactions LEFT JOIN users ON transactions.recorded_by = users.id WHERE transactions.id = ?');
    mysqli_stmt_bind_param($statement, 'i', $id); mysqli_stmt_execute($statement);
     $transaction = mysqli_fetch_assoc(mysqli_stmt_get_result($statement)); 
     if (!$transaction) { header('Location: index.php?success=Transaction not found.');
      exit; } 
      include '../../includes/header.php'; 
      include '../../includes/sidebar.php';
?>
<div class="card shadow">
    <div class="card-header">
        <h3>Transaction Details</h3></div>
        <div class="card-body">
            <table class="table table-bordered mb-4">
                <tr><th width="200">Category</th><td>
                    <?= htmlspecialchars($transaction['category'], ENT_QUOTES, 'UTF-8'); ?>
                </td></tr><tr><th>Type</th>
                <td><?= htmlspecialchars($transaction['transaction_type'], ENT_QUOTES, 'UTF-8'); ?>
            </td></tr><tr><th>Amount</th><td>RWF <?= number_format((float) $transaction['amount'], 2); ?>
        </td></tr>
        <tr><th>Date</th><td><?= date('d M Y', strtotime($transaction['transaction_date'])); ?></td></tr>
        <tr><th>Description</th><td><?= nl2br(htmlspecialchars($transaction['description'] ?? '', ENT_QUOTES, 'UTF-8')); ?></td></tr>
        <tr><th>Recorded By</th><td><?= htmlspecialchars($transaction['recorder_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></td></tr></table>
        <a href="index.php" class="btn btn-secondary">Back</a></div></div>
        <?php include '../../includes/footer.php'; ?>