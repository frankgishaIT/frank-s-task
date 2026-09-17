<?php
/**
 * Shared helpers for the Purchase Order sub-module (under RM Offerings).
 */

const PO_LOW_STOCK_THRESHOLD = 5; // kept in sync with products/index.php's LOW_STOCK_THRESHOLD

/**
 * Active, physical Items currently at or below the low-stock threshold —
 * used to pre-populate a new Purchase Order.
 */
function po_low_stock_products($conn) {
    $result = mysqli_query($conn, "SELECT id, product_name, product_code, buying_price, quantity, unit
        FROM products
        WHERE item_type = 'Item' AND is_active = 1 AND quantity <= " . PO_LOW_STOCK_THRESHOLD . "
        ORDER BY quantity ASC, product_name ASC");
    $list = [];
    while ($row = mysqli_fetch_assoc($result)) { $list[] = $row; }
    return $list;
}

function po_status_badge($status) {
    $map = ['Draft' => 'secondary', 'Ordered' => 'warning text-dark', 'Received' => 'success', 'Cancelled' => 'dark'];
    $class = $map[$status] ?? 'secondary';
    return '<span class="badge bg-' . $class . '">' . htmlspecialchars($status, ENT_QUOTES, 'UTF-8') . '</span>';
}

/**
 * Receives a Purchase Order: for every line item, adds the stock and
 * records a `purchases` row exactly like restock.php does — so no product
 * data has to be re-entered manually. Posts ONE Expense transaction for the
 * whole PO (not one per line) so Transactions doesn't get flooded with
 * many rows for a single delivery.
 *
 * Only 'Ordered' Purchase Orders can be received.
 */
function po_receive($conn, $poId, $userId) {
    $poStatement = mysqli_prepare($conn, 'SELECT * FROM purchase_orders WHERE id = ?');
    mysqli_stmt_bind_param($poStatement, 'i', $poId);
    mysqli_stmt_execute($poStatement);
    $po = mysqli_fetch_assoc(mysqli_stmt_get_result($poStatement));

    if (!$po) {
        return ['ok' => false, 'error' => 'Purchase Order not found.'];
    }
    if ($po['status'] !== 'Ordered') {
        return ['ok' => false, 'error' => 'Only Purchase Orders with status "Ordered" can be received.'];
    }

    $itemsStatement = mysqli_prepare($conn, 'SELECT purchase_order_items.*, products.product_name
        FROM purchase_order_items LEFT JOIN products ON purchase_order_items.product_id = products.id
        WHERE purchase_order_id = ?');
    mysqli_stmt_bind_param($itemsStatement, 'i', $poId);
    mysqli_stmt_execute($itemsStatement);
    $items = mysqli_stmt_get_result($itemsStatement);

    if (mysqli_num_rows($items) === 0) {
        return ['ok' => false, 'error' => 'This Purchase Order has no items.'];
    }

    $today = date('Y-m-d');
    $itemCount = 0;

    mysqli_begin_transaction($conn);
    try {
        while ($item = mysqli_fetch_assoc($items)) {
            $insertPurchase = mysqli_prepare($conn, 'INSERT INTO purchases
                (product_id, quantity, unit_cost, supplier, purchase_date, notes, recorded_by, purchase_order_id)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
            $notes = 'Received via Purchase Order #' . $poId;
            mysqli_stmt_bind_param($insertPurchase, 'iidsssii',
                $item['product_id'], $item['quantity'], $item['unit_cost'], $po['supplier'], $today, $notes, $userId, $poId);
            mysqli_stmt_execute($insertPurchase);

            $updateStock = mysqli_prepare($conn, 'UPDATE products SET quantity = quantity + ? WHERE id = ?');
            mysqli_stmt_bind_param($updateStock, 'ii', $item['quantity'], $item['product_id']);
            mysqli_stmt_execute($updateStock);

            $itemCount++;
        }

        // Single Expense transaction for the whole PO — mirrors restock.php's
        // pattern (status = 'approved', posted immediately, no approval needed).
        $description = 'Purchase Order #' . $poId . ' received (' . $itemCount . ' item' . ($itemCount === 1 ? '' : 's') . ')'
            . ($po['supplier'] ? ' from ' . $po['supplier'] : '');
        $insertTransaction = mysqli_prepare($conn, "INSERT INTO transactions
            (category, transaction_type, amount, transaction_date, description, recorded_by, status)
            VALUES ('Purchase (Re-stock)', 'Expense', ?, ?, ?, ?, 'approved')");
        mysqli_stmt_bind_param($insertTransaction, 'dssi', $po['total_amount'], $today, $description, $userId);
        mysqli_stmt_execute($insertTransaction);

        $updatePo = mysqli_prepare($conn, "UPDATE purchase_orders SET status = 'Received', received_by = ?, received_at = NOW() WHERE id = ?");
        mysqli_stmt_bind_param($updatePo, 'ii', $userId, $poId);
        mysqli_stmt_execute($updatePo);

        mysqli_commit($conn);
        return ['ok' => true];
    } catch (Exception $e) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => 'Unable to receive Purchase Order. Please try again.'];
    }
}