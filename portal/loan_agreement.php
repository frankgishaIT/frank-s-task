<?php
// NEW FILE: portal/loan_agreement.php — the loan agreement of the customer's own offer / loan (printable).
require __DIR__ . '/portal_start.php';
require_once __DIR__ . '/../includes/cl_helpers.php';
require_once __DIR__ . '/../includes/cl_agreement.php';
$id = (int) ($_GET['id'] ?? 0);
$a = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM cl_applications WHERE id = $id AND customer_id = $cid"));
if (!$a || !in_array($a['status'], ['Awaiting Customer Acceptance', 'Agreement Signed', 'Disbursed', 'Active', 'Overdue', 'Defaulted', 'Fully Repaid'], true)) { header('Location: loans.php'); exit; }
echo cl_agreement_html($conn, $a, $me);