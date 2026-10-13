<?php
/**
 * NEW FILE: modules/customers/reward_action.php
 * POST actions on a reward: fulfil (Admin), cancel (Pending; Admin or Manager), reverse (Fulfilled; Admin).
 */
require '../../config/db.php';
require_once '../../includes/reward_helpers.php';
require_once '../../includes/sms_messages.php';
require_role(['Admin', 'Manager']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: rewards.php'); exit; }
$id = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
$action = $_POST['action'] ?? '';
$reason = mb_substr(trim($_POST['reason'] ?? ''), 0, 255);
$userId = (int) current_user_id();
$isAdmin = current_user_role() === 'Admin';

$r = $id ? reward_get($conn, (int) $id) : null;
$back = 'rewards.php' . ($r ? '?customer_id=' . (int) $r['customer_id'] . '&' : '?');
if (!$r) { header('Location: rewards.php?error=' . urlencode('Reward not found.')); exit; }

if ($action === 'fulfil' && $isAdmin) {
    $res = reward_fulfil($conn, (int) $id, $userId);
    if ($res['ok']) { sms_notify_reward_fulfilled($conn, (int) $id, $userId); }
    $ok = 'Reward ' . reward_code((int) $id) . ' fulfilled. The customer was notified by SMS.';
} elseif (in_array($action, ['cancel', 'reverse'], true) && ($action === 'cancel' || $isAdmin)) {
    if ($reason === '') { header('Location: ' . $back . 'error=' . urlencode('A reason is required.')); exit; }
    $res = reward_undo($conn, (int) $id, $userId, $reason, $action === 'reverse');
    $ok = 'Reward ' . reward_code((int) $id) . ($action === 'reverse' ? ' reversed' : ' cancelled') . '. '
        . number_format((int) $r['points_redeemed']) . ' points returned to the customer.';
} else {
    header('Location: ' . $back . 'error=' . urlencode('You are not allowed to do this.')); exit;
}
header('Location: ' . $back . ($res['ok'] ? 'success=' . urlencode($ok) : 'error=' . urlencode($res['error'])));
exit;