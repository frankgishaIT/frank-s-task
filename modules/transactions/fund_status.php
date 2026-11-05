<?php
// NEW FILE: modules/transactions/fund_status.php — what the expense form shows before submission (JSON).
require '../../config/db.php';
require_once '../../includes/budget_helpers.php';
header('Content-Type: application/json');
$fundId = filter_input(INPUT_GET, 'fund_id', FILTER_VALIDATE_INT);
$amount = max(0, (float) ($_GET['amount'] ?? 0));
$st = $fundId ? bdg_fund_status($conn, (int) $fundId, $amount) : null;
echo json_encode($st ?: ['error' => 'Fund not found.']);