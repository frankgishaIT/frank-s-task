<?php
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require '../../config/db.php';
require '../../includes/fund_helpers.php';

header('Content-Type: application/json');
if (empty($_SESSION['user_id'])) { http_response_code(401); echo '{}'; exit; }

$fundId = filter_input(INPUT_GET, 'fund_id', FILTER_VALIDATE_INT);
$fund = $fundId ? fund_get($conn, $fundId) : null;
if (!$fund) { http_response_code(404); echo '{}'; exit; }

echo json_encode(['balance' => fund_available($conn, $fundId), 'name' => $fund['name']]);