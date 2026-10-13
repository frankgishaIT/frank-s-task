<?php
/**
 * NEW FILE: includes/reward_helpers.php
 * Customer Rewards (redeeming Loyalty Points). The way points are EARNED is not changed.
 *
 *   Record   -> the points are deducted from the customer right away (status Pending, or Fulfilled
 *               at once when an Admin records it as given now).
 *   Fulfil   -> the reward is given / paid (Admin). A reward type that pays money is paid from the
 *               RM Business Operating Fund (automatic expense, in the fund ledger), never another fund.
 *   Cancel   -> a Pending reward that will not be given: the points go back to the customer.
 *   Reverse  -> a Fulfilled reward that is undone: the points go back, and any money paid returns
 *               to the Operating Fund (linked reversal; the expense transaction is kept, marked Deleted).
 * Every step runs in one database transaction and keeps the full history.
 */
require_once __DIR__ . '/fund_helpers.php';

const REWARD_EVIDENCE_DIR = __DIR__ . '/../storage/rewards';
const REWARD_EVIDENCE_MAX_BYTES = 5 * 1024 * 1024;
const REWARD_EVIDENCE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];

function reward_code(int $id): string { return 'RW' . str_pad((string) $id, 6, '0', STR_PAD_LEFT); }
function reward_voucher_code(int $id): string { return 'RV' . str_pad((string) $id, 6, '0', STR_PAD_LEFT); }

function reward_types(mysqli $conn, bool $activeOnly = true): array
{
    $res = mysqli_query($conn, 'SELECT * FROM reward_types' . ($activeOnly ? ' WHERE is_active = 1' : '') . ' ORDER BY sort_order, name');
    return mysqli_fetch_all($res, MYSQLI_ASSOC);
}

function reward_get(mysqli $conn, int $id, bool $lock = false): ?array
{
    $s = mysqli_prepare($conn, 'SELECT r.*, t.name AS type_name, t.pays_money, c.name AS customer_name, c.phone AS customer_phone, c.loyalty_points AS customer_points
        FROM customer_rewards r JOIN reward_types t ON t.id = r.reward_type_id JOIN customers c ON c.id = r.customer_id
        WHERE r.id = ?' . ($lock ? ' FOR UPDATE' : ''));
    mysqli_stmt_bind_param($s, 'i', $id);
    mysqli_stmt_execute($s);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($s)) ?: null;
}

/**
 * Saves an uploaded evidence file (photo or PDF, max 5 MB) under a random name.
 * Returns the stored file name, null when nothing was uploaded, or throws RuntimeException.
 */
function reward_store_evidence(?array $file): ?string
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('The evidence file could not be uploaded. Please try again.');
    }
    if ($file['size'] > REWARD_EVIDENCE_MAX_BYTES) {
        throw new RuntimeException('The evidence file is too large (maximum 5 MB).');
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    if (!isset(REWARD_EVIDENCE_TYPES[$mime])) {
        throw new RuntimeException('Evidence must be a photo (JPG or PNG) or a PDF.');
    }
    if (!is_dir(REWARD_EVIDENCE_DIR) && !@mkdir(REWARD_EVIDENCE_DIR, 0775, true)) {
        throw new RuntimeException('The evidence folder could not be created on the server.');
    }
    $name = bin2hex(random_bytes(16)) . '.' . REWARD_EVIDENCE_TYPES[$mime];
    if (!move_uploaded_file($file['tmp_name'], REWARD_EVIDENCE_DIR . '/' . $name)) {
        throw new RuntimeException('The evidence file could not be saved.');
    }
    return $name;
}

/**
 * Records a reward. $d: customer_id, reward_type_id, reward_details, reward_amount, points_required,
 * points_redeemed, date_earned, notes, evidence_file, fulfil_now (bool).
 * A Manager's reward always starts Pending (an Admin approves and fulfils it).
 * Returns ['ok' => bool, 'id' => int, 'fulfilled' => bool, 'error' => string].
 */
function reward_create(mysqli $conn, array $d, int $userId, bool $isAdmin): array
{
    mysqli_begin_transaction($conn);
    try {
        $c = mysqli_prepare($conn, 'SELECT id, loyalty_points FROM customers WHERE id = ? AND is_active = 1 FOR UPDATE');
        mysqli_stmt_bind_param($c, 'i', $d['customer_id']);
        mysqli_stmt_execute($c);
        $customer = mysqli_fetch_assoc(mysqli_stmt_get_result($c));
        if (!$customer) {
            throw new RuntimeException('Customer not found.');
        }
        $before = (int) $customer['loyalty_points'];
        $redeemed = (int) $d['points_redeemed'];
        if ($redeemed > $before) {
            throw new RuntimeException('The customer has only ' . number_format($before) . ' Loyalty Points; ' . number_format($redeemed) . ' cannot be redeemed.');
        }
        $after = $before - $redeemed;

        $t = mysqli_prepare($conn, 'SELECT * FROM reward_types WHERE id = ? AND is_active = 1');
        mysqli_stmt_bind_param($t, 'i', $d['reward_type_id']);
        mysqli_stmt_execute($t);
        $type = mysqli_fetch_assoc(mysqli_stmt_get_result($t));
        if (!$type) {
            throw new RuntimeException('Please choose a valid reward type.');
        }
        $paysMoney = (int) $type['pays_money'] === 1 && (float) $d['reward_amount'] > 0;
        $payment = $paysMoney ? 'Unpaid' : 'Not applicable';

        $ins = mysqli_prepare($conn, "INSERT INTO customer_rewards
            (customer_id, reward_type_id, reward_details, reward_amount, points_required, points_redeemed, points_before, points_after,
             status, payment_status, date_earned, processed_by, evidence_file, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Pending', ?, ?, ?, ?, ?)");
        mysqli_stmt_bind_param($ins, 'iisdiiiississ', $d['customer_id'], $d['reward_type_id'], $d['reward_details'], $d['reward_amount'],
            $d['points_required'], $redeemed, $before, $after, $payment, $d['date_earned'], $userId, $d['evidence_file'], $d['notes']);
        mysqli_stmt_execute($ins);
        $id = (int) mysqli_insert_id($conn);

        if ($type['name'] === 'Discount Voucher') {
            $code = reward_voucher_code($id);
            $v = mysqli_prepare($conn, 'UPDATE customer_rewards SET voucher_code = ? WHERE id = ?');
            mysqli_stmt_bind_param($v, 'si', $code, $id);
            mysqli_stmt_execute($v);
        }

        // The points are taken now (the guard stops a balance going below zero).
        $u = mysqli_prepare($conn, 'UPDATE customers SET loyalty_points = loyalty_points - ? WHERE id = ? AND loyalty_points >= ?');
        mysqli_stmt_bind_param($u, 'iii', $redeemed, $d['customer_id'], $redeemed);
        mysqli_stmt_execute($u);
        if ($redeemed > 0 && mysqli_stmt_affected_rows($u) !== 1) {
            throw new RuntimeException('The customer\'s points changed meanwhile. Please try again.');
        }

        $fulfilled = false;
        if (!empty($d['fulfil_now']) && $isAdmin) {
            reward_fulfil_inner($conn, $id, $userId);
            $fulfilled = true;
        }

        mysqli_commit($conn);
        return ['ok' => true, 'id' => $id, 'fulfilled' => $fulfilled, 'error' => ''];
    } catch (InsufficientFundException $e) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => $e->getMessage() . ' Save the reward as Pending and fulfil it when the fund has enough money.'];
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        if (!($e instanceof RuntimeException) || $e instanceof mysqli_sql_exception) {
            error_log('reward_create failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Unable to record the reward. Nothing was saved.'];
        }
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/** Fulfils a Pending reward inside the caller's transaction (pays it from the Operating Fund if needed). */
function reward_fulfil_inner(mysqli $conn, int $id, int $userId): void
{
    $r = reward_get($conn, $id, true);
    if (!$r || $r['status'] !== 'Pending') {
        throw new RuntimeException('Only a Pending reward can be fulfilled.');
    }
    $txId = null;
    $payment = $r['payment_status'];
    if ((int) $r['pays_money'] === 1 && (float) $r['reward_amount'] > 0) {
        $txId = fund_post_automatic_expense($conn, 'OPERATING', 'Loyalty Reward', (float) $r['reward_amount'], date('Y-m-d'),
            'Loyalty reward ' . reward_code($id) . ' to ' . $r['customer_name'] . ': ' . $r['type_name'] . ' — ' . $r['reward_details'],
            $userId, null, 'REWARD', $id);
        $payment = 'Paid';
    }
    $u = mysqli_prepare($conn, "UPDATE customer_rewards SET status = 'Fulfilled', payment_status = ?, fulfilled_at = NOW(),
        approved_by = ?, approved_at = NOW(), transaction_id = ? WHERE id = ? AND status = 'Pending'");
    mysqli_stmt_bind_param($u, 'siii', $payment, $userId, $txId, $id);
    mysqli_stmt_execute($u);
}

/** Admin: approve and fulfil a Pending reward (own transaction). */
function reward_fulfil(mysqli $conn, int $id, int $userId): array
{
    mysqli_begin_transaction($conn);
    try {
        reward_fulfil_inner($conn, $id, $userId);
        mysqli_commit($conn);
        return ['ok' => true, 'error' => ''];
    } catch (InsufficientFundException $e) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => $e->getMessage()];
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        if ($e instanceof RuntimeException && !($e instanceof mysqli_sql_exception)) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        error_log('reward_fulfil failed for #' . $id . ': ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Unable to fulfil the reward. Nothing was changed.'];
    }
}

/** Cancel (Pending) or Reverse (Fulfilled): the points go back; money paid returns to the Operating Fund. */
function reward_undo(mysqli $conn, int $id, int $userId, string $reason, bool $reverse): array
{
    mysqli_begin_transaction($conn);
    try {
        $r = reward_get($conn, $id, true);
        $needed = $reverse ? 'Fulfilled' : 'Pending';
        if (!$r || $r['status'] !== $needed) {
            throw new RuntimeException($reverse ? 'Only a Fulfilled reward can be reversed.' : 'Only a Pending reward can be cancelled.');
        }

        // Points back to the customer.
        $p = mysqli_prepare($conn, 'UPDATE customers SET loyalty_points = loyalty_points + ? WHERE id = ?');
        $pts = (int) $r['points_redeemed'];
        $cid = (int) $r['customer_id'];
        mysqli_stmt_bind_param($p, 'ii', $pts, $cid);
        mysqli_stmt_execute($p);

        $payment = $r['payment_status'];
        if ($reverse && !empty($r['transaction_id'])) {
            fund_reverse_movements($conn, "m.ref_type = 'REWARD' AND m.ref_id = ?", 'i', [$id], $userId,
                'Loyalty reward ' . reward_code($id) . ' reversed');
            $m = mysqli_prepare($conn, "UPDATE transactions SET status = 'deleted', deleted_by = ?, deleted_at = NOW() WHERE id = ? AND status <> 'deleted'");
            $txId = (int) $r['transaction_id'];
            mysqli_stmt_bind_param($m, 'ii', $userId, $txId);
            mysqli_stmt_execute($m);
            $payment = 'Refunded';
        }

        $status = $reverse ? 'Reversed' : 'Cancelled';
        $u = mysqli_prepare($conn, 'UPDATE customer_rewards SET status = ?, payment_status = ?, cancelled_by = ?, cancelled_at = NOW(), cancel_reason = ? WHERE id = ?');
        mysqli_stmt_bind_param($u, 'ssisi', $status, $payment, $userId, $reason, $id);
        mysqli_stmt_execute($u);

        mysqli_commit($conn);
        return ['ok' => true, 'error' => ''];
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        if ($e instanceof RuntimeException && !($e instanceof mysqli_sql_exception)) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
        error_log('reward_undo failed for #' . $id . ': ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Unable to change the reward. Nothing was changed.'];
    }
}