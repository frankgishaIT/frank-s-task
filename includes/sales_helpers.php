<?php
/**
 * Shared helpers for the RM Mart & Spark sales module.
 */

function sales_compute_status($amountPaid, $totalAmount) {
    if ($amountPaid >= $totalAmount - 0.01) {
        return 'Paid';
    }
    if ($amountPaid <= 0.009) {
        return 'Credit';
    }
    return 'Partially Paid';
}

/**
 * Deducts stock for every line item on a sale and posts the revenue into
 * Finance/Transactions. Called either immediately (auto-approved sale)
 * or once a Manager approves a discounted sale.
 */
function sales_finalize($conn, $saleId) {
    $saleStatement = mysqli_prepare($conn, 'SELECT * FROM sales WHERE id = ?');
    mysqli_stmt_bind_param($saleStatement, 'i', $saleId);
    mysqli_stmt_execute($saleStatement);
    $sale = mysqli_fetch_assoc(mysqli_stmt_get_result($saleStatement));
    if (!$sale) {
        return false;
    }

    $itemsStatement = mysqli_prepare($conn, 'SELECT * FROM sale_items WHERE sale_id = ?');
    mysqli_stmt_bind_param($itemsStatement, 'i', $saleId);
    mysqli_stmt_execute($itemsStatement);
    $items = mysqli_stmt_get_result($itemsStatement);

    $hasProduct = false;
    while ($item = mysqli_fetch_assoc($items)) {
        if ($item['item_type'] !== 'Product' || !$item['product_id']) {
            continue; // services don't touch inventory
        }
        $hasProduct = true;
        $update = mysqli_prepare($conn, 'UPDATE products SET quantity = quantity - ? WHERE id = ?');
        mysqli_stmt_bind_param($update, 'ii', $item['quantity'], $item['product_id']);
        mysqli_stmt_execute($update);
    }

    // A sale can mix Product and Service line items, but a transaction only
    // takes one category now — tag it Product if any physical item was sold,
    // otherwise Service.
    $category = $hasProduct ? 'Product' : 'Service';

    $description = 'Sale #' . $saleId . ' (' . $sale['payment_method'] . ')';
    $transactionStatement = mysqli_prepare($conn, "INSERT INTO transactions (category, transaction_type, amount, transaction_date, description, recorded_by) VALUES (?, 'Income', ?, ?, ?, ?)");
    mysqli_stmt_bind_param($transactionStatement, 'sdssi', $category, $sale['total_amount'], $sale['sale_date'], $description, $sale['recorded_by']);
    mysqli_stmt_execute($transactionStatement);
    $transactionId = mysqli_insert_id($conn);

    $newStatus = sales_compute_status((float) $sale['amount_paid'], (float) $sale['total_amount']);
    $updateSale = mysqli_prepare($conn, 'UPDATE sales SET status = ?, transaction_id = ? WHERE id = ?');
    mysqli_stmt_bind_param($updateSale, 'sii', $newStatus, $transactionId, $saleId);
    mysqli_stmt_execute($updateSale);

    return true;
}