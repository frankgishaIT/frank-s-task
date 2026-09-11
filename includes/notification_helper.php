<?php
require_once __DIR__ . '/mailer.php';

/**
 * Notify the currently logged-in user (session user). Writes an in-app
 * notification row and emails them, if they have an email on file.
 *
 * Signature unchanged from the original — every existing call site
 * (customers/edit.php, departments/create.php, etc.) keeps working as-is.
 */
function notify($conn, $title, $message)
{
    if (empty($_SESSION['user_id'])) {
        return;
    }

    notifyUser($conn, (int) $_SESSION['user_id'], $title, $message);
}

/**
 * Notify a specific user by id. Writes an in-app notification row and
 * emails them, if they have an email on file. A failed email never breaks
 * the page/action that triggered it.
 *
 * Signature unchanged from the original — every existing call site
 * (transactions/approve.php, transactions/create.php, etc.) keeps working
 * as-is.
 */
function notifyUser($conn, $userId, $title, $message)
{
    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)"
    );
    mysqli_stmt_bind_param($stmt, 'iss', $userId, $title, $message);
    mysqli_stmt_execute($stmt);

    $userStatement = mysqli_prepare($conn, 'SELECT names, email FROM users WHERE id = ?');
    mysqli_stmt_bind_param($userStatement, 'i', $userId);
    mysqli_stmt_execute($userStatement);
    $recipient = mysqli_fetch_assoc(mysqli_stmt_get_result($userStatement));

    if (!$recipient || empty($recipient['email'])) {
        return;
    }

    $bodyHtml = '<p>Hi ' . htmlspecialchars($recipient['names'], ENT_QUOTES, 'UTF-8') . ',</p>'
        . '<p>' . nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')) . '</p>';

    // A failed email should never break the page/action that triggered it.
    try {
        send_email($recipient['email'], $recipient['names'], $title, $bodyHtml);
    } catch (Throwable $e) {
        error_log('notifyUser email failed: ' . $e->getMessage());
    }
}

/**
 * New helper (not used anywhere yet): notify every active Admin and
 * Manager in one call. Handy for things like new credit sales or
 * cancellation requests that need oversight from more than one person.
 */
function notify_admins_and_managers($conn, string $title, string $message)
{
    $result = mysqli_query($conn, "SELECT id FROM users WHERE role IN ('Admin', 'Manager') AND is_active = 1");
    while ($row = mysqli_fetch_assoc($result)) {
        notifyUser($conn, (int) $row['id'], $title, $message);
    }
}

/**
 * Email a purchase receipt to a customer. Customers aren't in the `users`
 * table, so there's no in-app bell notification for them — this is
 * email-only, and silently does nothing if the customer has no email on
 * file (never blocks the sale over a missing/failed email).
 *
 * @param array $lineItems Each item needs: name, quantity, unit_price, line_total
 */
function notify_customer_purchase(
    $conn,
    int $customerId,
    array $lineItems,
    float $subtotal,
    float $discountAmount,
    float $totalAmount,
    float $amountPaid,
    string $paymentMethod
): void {
    $customerStatement = mysqli_prepare($conn, 'SELECT name, email FROM customers WHERE id = ?');
    mysqli_stmt_bind_param($customerStatement, 'i', $customerId);
    mysqli_stmt_execute($customerStatement);
    $customer = mysqli_fetch_assoc(mysqli_stmt_get_result($customerStatement));

    if (!$customer || empty($customer['email'])) {
        return;
    }

    $rows = '';
    foreach ($lineItems as $item) {
        $rows .= '<tr>'
            . '<td style="padding:6px; border-bottom:1px solid #eee;">' . htmlspecialchars($item['name'], ENT_QUOTES, 'UTF-8') . '</td>'
            . '<td style="padding:6px; border-bottom:1px solid #eee; text-align:center;">' . (int) $item['quantity'] . '</td>'
            . '<td style="padding:6px; border-bottom:1px solid #eee; text-align:right;">RWF ' . number_format($item['unit_price'], 2) . '</td>'
            . '<td style="padding:6px; border-bottom:1px solid #eee; text-align:right;">RWF ' . number_format($item['line_total'], 2) . '</td>'
            . '</tr>';
    }

    $balance = $totalAmount - $amountPaid;

    $bodyHtml = '<p>Hi ' . htmlspecialchars($customer['name'], ENT_QUOTES, 'UTF-8') . ',</p>'
        . '<p>Thank you for your purchase! Here is your receipt:</p>'
        . '<table style="width:100%; border-collapse:collapse; font-size:14px;">'
        . '<thead><tr style="background:#f4f6fb;">'
        . '<th style="padding:6px; text-align:left;">Item</th>'
        . '<th style="padding:6px; text-align:center;">Qty</th>'
        . '<th style="padding:6px; text-align:right;">Unit Price</th>'
        . '<th style="padding:6px; text-align:right;">Total</th>'
        . '</tr></thead><tbody>' . $rows . '</tbody></table>'
        . '<p style="margin-top:16px; font-size:14px;">'
        . 'Subtotal: RWF ' . number_format($subtotal, 2) . '<br>'
        . ($discountAmount > 0 ? 'Discount: -RWF ' . number_format($discountAmount, 2) . '<br>' : '')
        . '<strong>Total: RWF ' . number_format($totalAmount, 2) . '</strong><br>'
        . 'Amount Paid: RWF ' . number_format($amountPaid, 2) . '<br>'
        . 'Payment Method: ' . htmlspecialchars($paymentMethod, ENT_QUOTES, 'UTF-8')
        . '</p>';

    if ($paymentMethod === 'Credit' && $balance > 0.01) {
        $bodyHtml .= '<p style="color:#c0392b; font-weight:bold;">Outstanding balance: RWF ' . number_format($balance, 2) . '</p>';
    }

    // A failed email should never break the sale that triggered it.
    try {
        send_email($customer['email'], $customer['name'], 'Your purchase receipt', $bodyHtml);
    } catch (Throwable $e) {
        error_log('notify_customer_purchase email failed: ' . $e->getMessage());
    }
}