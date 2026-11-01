<?php
// NEW FILE: modules/rm_loans/agreement.php — the printable loan agreement (staff side).
require __DIR__ . '/loans_common.php';
require_once __DIR__ . '/../../includes/cl_agreement.php';
$id = (int) ($_GET['id'] ?? 0);
$a = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM cl_applications WHERE id = $id"));
if (!$a || !$a['offered_at']) { header('Location: index.php?error=' . urlencode('No offer has been made on this application yet.')); exit; }
$c = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM customers WHERE id = ' . (int) $a['customer_id']));
cl_audit($conn, $id, 'Agreement generated / printed', null, null, null, $me);
echo cl_agreement_html($conn, $a, $c);