<?php
/**
 * Shared helpers for the Purchase Order sub-module (under RM Offerings).
 */
require_once __DIR__ . '/stock_rules.php'; // single "low stock" rule (per-product reorder level, else the default)
require_once __DIR__ . '/fund_helpers.php'; // RM Funds: purchases are paid from the RM Capital Fund
require_once __DIR__ . '/sms_messages.php'; // NEW: supplier SMS when a PO is ordered (MY MOTIVE SMS, message 6)

const PO_LOW_STOCK_THRESHOLD = STOCK_DEFAULT_REORDER_LEVEL; // default level, used when a product has none of its own

/**
 * Active, physical Items currently at or below their reorder level —
 * used to pre-populate a new Purchase Order. A product's own reorder level
 * is used when set, otherwise the default threshold. reorder_level_used
 * tells the caller which level applied.
 */
function po_low_stock_products($conn) {
    $level = stock_reorder_level_sql();
    $result = mysqli_query($conn, "SELECT id, product_name, product_code, buying_price, quantity, unit, $level AS reorder_level_used
        FROM products
        WHERE item_type = 'Item' AND is_active = 1 AND quantity <= $level
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
 * NEW: adds stock to a product AND updates its buying price to the weighted average of
 * the stock already held and the stock arriving. Used by Receive PO and Re-Stock.
 *
 * Why: sales take their cost from products.buying_price. If stock arrives at a new price
 * but buying_price stays old, the Capital Fund goes up by the new price and down by the
 * old one on every sale, and slowly stops matching the real stock value.
 *
 * Example: 10 notebooks at RWF 800 + 10 new at RWF 900 -> 20 notebooks at RWF 850.
 * MUST be called inside the caller's mysqli_begin_transaction().
 */
function stock_add_at_average_cost(mysqli $conn, int $productId, int $baseQuantity, float $costPerBaseUnit): void {
    if ($baseQuantity <= 0) { return; }

    $s = mysqli_prepare($conn, 'SELECT quantity, buying_price FROM products WHERE id = ? FOR UPDATE');
    mysqli_stmt_bind_param($s, 'i', $productId);
    mysqli_stmt_execute($s);
    $product = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    if (!$product) {
        throw new RuntimeException('Product #' . $productId . ' was not found.');
    }

    $oldQuantity = max(0, (int) $product['quantity']);
    $oldPrice = (float) $product['buying_price'];
    $newQuantity = $oldQuantity + $baseQuantity;
    $newPrice = $oldQuantity > 0
        ? round((($oldQuantity * $oldPrice) + ($baseQuantity * $costPerBaseUnit)) / $newQuantity, 2)
        : round($costPerBaseUnit, 2);

    $u = mysqli_prepare($conn, 'UPDATE products SET quantity = quantity + ?, buying_price = ? WHERE id = ?');
    mysqli_stmt_bind_param($u, 'idi', $baseQuantity, $newPrice, $productId);
    mysqli_stmt_execute($u);
}

/* =====================================================================
 * RM Capital Fund integration (spec sections 4, 5 and 6)
 *   Ordered   -> expense out of the Capital Fund (cash leaves)
 *   Received  -> the same amount comes back in (cash became stock)
 *   Cancelled -> if it had been Ordered, the expense is reversed
 * Requires fund_movements.reverses_movement_id (see the migration).
 * ===================================================================== */

/**
 * Section 4: posts the PO total as an EXPENSE of the RM Capital Fund.
 * Throws InsufficientFundException when the fund cannot cover it.
 * Safe to call twice (does nothing if the expense already exists).
 * MUST be called inside the caller's mysqli_begin_transaction().
 */
function po_post_ordered_expense(mysqli $conn, int $poId, float $total, string $supplier, ?int $userId): void {
    if ($total <= 0) { return; }

    $chk = mysqli_prepare($conn, "SELECT id FROM fund_movements
        WHERE ref_type = 'PO' AND ref_id = ? AND movement_type = 'EXPENSE' LIMIT 1");
    mysqli_stmt_bind_param($chk, 'i', $poId);
    mysqli_stmt_execute($chk);
    if (mysqli_fetch_assoc(mysqli_stmt_get_result($chk))) { return; }

    $description = 'Purchase Order #' . $poId . ' ordered' . ($supplier !== '' ? ' from ' . $supplier : '');
    // Same category name as before, so existing purchase reports keep working.
    fund_post_automatic_expense(
        $conn, 'CAPITAL', 'Purchase (Re-stock)', $total,
        date('Y-m-d'), $description, $userId, null, 'PO', $poId
    );
}

/**
 * Section 5: when the PO is received, the amount that left the Capital Fund at "Ordered"
 * comes back as value (the money is now stock). Mirrors the Ordered expense exactly.
 * If the PO was ordered before this system went live (no expense was ever posted),
 * nothing is posted, so the fund is not inflated.
 * MUST be called inside the caller's mysqli_begin_transaction().
 *
 * CHANGED: recorded as STOCK_IN instead of CAPITAL_INFLOW. CAPITAL_INFLOW means new money
 * put into the business (a loan, share capital, a grant), so receiving stock was being
 * counted as "Contributed" capital in the Capital Fund report. STOCK_IN is the opposite of
 * STOCK_OUT (a sale), so the report can show stock movements separately.
 * The balance is the same either way. It is also safe to call twice now.
 */
function po_post_received_income(mysqli $conn, int $poId, ?string $supplier, string $date, ?int $userId): void {
    $q = mysqli_prepare($conn, "SELECT m.amount FROM fund_movements m
        LEFT JOIN fund_movements r ON r.reverses_movement_id = m.id
        WHERE m.ref_type = 'PO' AND m.ref_id = ? AND m.movement_type = 'EXPENSE' AND r.id IS NULL
        LIMIT 1");
    mysqli_stmt_bind_param($q, 'i', $poId);
    mysqli_stmt_execute($q);
    $ordered = mysqli_fetch_assoc(mysqli_stmt_get_result($q));
    if (!$ordered) { return; }

    // NEW: already received into the fund? Do nothing (old receipts used CAPITAL_INFLOW).
    $chk = mysqli_prepare($conn, "SELECT id FROM fund_movements
        WHERE ref_type = 'PO' AND ref_id = ? AND movement_type IN ('STOCK_IN', 'CAPITAL_INFLOW') LIMIT 1");
    mysqli_stmt_bind_param($chk, 'i', $poId);
    mysqli_stmt_execute($chk);
    if (mysqli_fetch_assoc(mysqli_stmt_get_result($chk))) { return; }

    $fund = fund_by_code($conn, 'CAPITAL');
    if (!$fund) {
        throw new RuntimeException('RM Capital Fund was not found.');
    }

    $description = mb_substr('Purchase Order #' . $poId . ' received' . ($supplier ? ' from ' . $supplier : '') . ': cash converted to stock', 0, 255);
    fund_record_movement($conn, (int) $fund['id'], 'STOCK_IN', 'IN', (float) $ordered['amount'],
        substr($date, 0, 7), null, $userId, $description, 'Stock received (Purchase Order)', 'PO', $poId);
}

/**
 * Draft -> Ordered for an existing Purchase Order (own transaction).
 * Posts the Capital Fund expense. If the fund cannot cover it, the PO stays a Draft.
 * Do NOT call this from inside another transaction; use po_post_ordered_expense() there.
 */
function po_mark_ordered($conn, $poId, $userId) {
    mysqli_begin_transaction($conn);
    try {
        $lock = mysqli_prepare($conn, 'SELECT * FROM purchase_orders WHERE id = ? FOR UPDATE');
        mysqli_stmt_bind_param($lock, 'i', $poId);
        mysqli_stmt_execute($lock);
        $po = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));

        if (!$po) {
            mysqli_rollback($conn);
            return ['ok' => false, 'error' => 'Purchase Order not found.'];
        }
        if ($po['status'] !== 'Draft') {
            mysqli_rollback($conn);
            return ['ok' => false, 'error' => 'Only Draft Purchase Orders can be marked as Ordered.'];
        }
        // NEW: same rule as the New Purchase Order page. A RWF 0 order would take nothing
        // from the Capital Fund and later bring stock in for free.
        if ((float) $po['total_amount'] <= 0) {
            mysqli_rollback($conn);
            return ['ok' => false, 'error' => 'An order with a total of RWF 0 cannot be marked as Ordered. Please enter the cost of the products first.'];
        }

        $update = mysqli_prepare($conn, "UPDATE purchase_orders SET status = 'Ordered', ordered_by = ?, ordered_at = NOW() WHERE id = ?");
        mysqli_stmt_bind_param($update, 'ii', $userId, $poId);
        mysqli_stmt_execute($update);

        po_post_ordered_expense($conn, (int) $poId, (float) $po['total_amount'], (string) $po['supplier'], $userId ? (int) $userId : null);

        mysqli_commit($conn);
        // NEW: tell the supplier, only after the order is saved.
        sms_notify_po_ordered($conn, (int) $poId, $userId ? (int) $userId : null);
        return ['ok' => true];
    } catch (InsufficientFundException $e) {
        mysqli_rollback($conn);
        return ['ok' => false, 'error' => $e->getMessage()];
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        error_log('po_mark_ordered failed for PO #' . $poId . ': ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Unable to mark the Purchase Order as Ordered. Nothing was changed.'];
    }
}

/**
 * Section 6: cancels a Draft or Ordered Purchase Order (own transaction).
 * If it had been Ordered, the Capital Fund expense is reversed automatically (a linked
 * REVERSAL movement) and the expense transaction is removed from Transactions.
 * A Received Purchase Order cannot be cancelled: its stock is already in.
 */
function po_cancel($conn, $poId, $userId, $reason = null) {
    mysqli_begin_transaction($conn);
    try {
        $lock = mysqli_prepare($conn, 'SELECT * FROM purchase_orders WHERE id = ? FOR UPDATE');
        mysqli_stmt_bind_param($lock, 'i', $poId);
        mysqli_stmt_execute($lock);
        $po = mysqli_fetch_assoc(mysqli_stmt_get_result($lock));

        if (!$po) {
            mysqli_rollback($conn);
            return ['ok' => false, 'error' => 'Purchase Order not found.'];
        }
        if (!in_array($po['status'], ['Draft', 'Ordered'], true)) {
            mysqli_rollback($conn);
            return ['ok' => false, 'error' => 'Only Draft or Ordered Purchase Orders can be cancelled.'];
        }

        if ($po['status'] === 'Ordered') {
            // Find the expense transaction BEFORE reversing, so it can be removed afterwards.
            $find = mysqli_prepare($conn, "SELECT transaction_id FROM fund_movements
                WHERE ref_type = 'PO' AND ref_id = ? AND movement_type = 'EXPENSE' AND transaction_id IS NOT NULL LIMIT 1");
            mysqli_stmt_bind_param($find, 'i', $poId);
            mysqli_stmt_execute($find);
            $expenseTx = mysqli_fetch_assoc(mysqli_stmt_get_result($find));

            fund_reverse_movements($conn, "m.ref_type = 'PO' AND m.ref_id = ?", 'i', [(int) $poId],
                $userId ? (int) $userId : null, 'Purchase Order #' . $poId . ' cancelled');

            if ($expenseTx) {
                $deleteTx = mysqli_prepare($conn, 'DELETE FROM transactions WHERE id = ?');
                $txId = (int) $expenseTx['transaction_id'];
                mysqli_stmt_bind_param($deleteTx, 'i', $txId);
                mysqli_stmt_execute($deleteTx);
            }
        }

        $update = mysqli_prepare($conn, "UPDATE purchase_orders SET status = 'Cancelled', cancelled_by = ?, cancelled_at = NOW(), cancel_reason = ? WHERE id = ?");
        mysqli_stmt_bind_param($update, 'isi', $userId, $reason, $poId);
        mysqli_stmt_execute($update);

        mysqli_commit($conn);
        return ['ok' => true];
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        error_log('po_cancel failed for PO #' . $poId . ': ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Unable to cancel the Purchase Order. Nothing was changed.'];
    }
}

/**
 * Receives a Purchase Order: for every line item, converts the ordered
 * packs into base stock units (quantity × pack_size) and adds them to
 * stock, and records a `purchases` row exactly like restock.php does — so
 * no product data has to be re-entered manually.
 *
 * CHANGED (spec section 5): the money no longer leaves the Capital Fund here. It left when
 * the PO was marked Ordered; now the same amount comes back as stock value.
 * The PO is also locked while it is received, so two clicks can no longer add the stock twice.
 *
 * IMPORTANT for Re-Stock (spec section 7): this function does NOT go through restock.php,
 * so the Re-Stock Capital Fund posting must live in restock.php itself (never on the
 * purchases table), otherwise a received PO would be added to the Capital Fund twice.
 *
 * Only 'Ordered' Purchase Orders can be received.
 */
function po_receive($conn, $poId, $userId) {
    $today = date('Y-m-d');
    $itemCount = 0;

    mysqli_begin_transaction($conn);
    try {
        // CHANGED: read the PO under a lock, inside the transaction.
        $poStatement = mysqli_prepare($conn, 'SELECT * FROM purchase_orders WHERE id = ? FOR UPDATE');
        mysqli_stmt_bind_param($poStatement, 'i', $poId);
        mysqli_stmt_execute($poStatement);
        $po = mysqli_fetch_assoc(mysqli_stmt_get_result($poStatement));

        if (!$po) {
            mysqli_rollback($conn);
            return ['ok' => false, 'error' => 'Purchase Order not found.'];
        }
        if ($po['status'] !== 'Ordered') {
            mysqli_rollback($conn);
            return ['ok' => false, 'error' => 'Only Purchase Orders with status "Ordered" can be received.'];
        }

        $itemsStatement = mysqli_prepare($conn, 'SELECT purchase_order_items.*, products.product_name
            FROM purchase_order_items LEFT JOIN products ON purchase_order_items.product_id = products.id
            WHERE purchase_order_id = ?');
        mysqli_stmt_bind_param($itemsStatement, 'i', $poId);
        mysqli_stmt_execute($itemsStatement);
        $items = mysqli_stmt_get_result($itemsStatement);

        if (mysqli_num_rows($items) === 0) {
            mysqli_rollback($conn);
            return ['ok' => false, 'error' => 'This Purchase Order has no items.'];
        }

        while ($item = mysqli_fetch_assoc($items)) {
            $packSize = max(1, (int) $item['pack_size']); // guard against 0/negative
            $packQuantity = (int) $item['quantity']; // number of packs ordered
            $baseQuantity = $packQuantity * $packSize; // actual stock units to add
            // Cost per base unit comes from the line total (the real amount for this line),
            // so it is right whether unit_cost was entered per pack or per piece.
            $costPerBaseUnit = $baseQuantity > 0 ? ((float) $item['line_total'] / $baseQuantity) : 0;

            $insertPurchase = mysqli_prepare($conn, 'INSERT INTO purchases
                (product_id, quantity, unit_cost, supplier, supplier_party_id, purchase_date, notes, recorded_by, purchase_order_id, pack_label, pack_size, pack_quantity)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $notes = 'Received via Purchase Order #' . $poId;
            // Types in column order: i i d s i s s i i s i i  (pack_label is a string)
            mysqli_stmt_bind_param($insertPurchase, 'iidsissiisii',
                $item['product_id'], $baseQuantity, $costPerBaseUnit, $po['supplier'], $po['supplier_party_id'],
                $today, $notes, $userId, $poId, $item['pack_label'], $packSize, $packQuantity);
            mysqli_stmt_execute($insertPurchase);

            // CHANGED: adds the stock AND updates the buying price to the weighted average,
            // so later sales take the right cost out of the Capital Fund.
            if (!empty($item['product_id'])) {
                stock_add_at_average_cost($conn, (int) $item['product_id'], $baseQuantity, $costPerBaseUnit);
            }

            $itemCount++;
        }

        // NEW (spec section 5): the cash that left at "Ordered" is now stock, so the same
        // amount returns to the RM Capital Fund as value.
        po_post_received_income($conn, (int) $poId, $po['supplier'] ?: null, $today, $userId ? (int) $userId : null);

        $updatePo = mysqli_prepare($conn, "UPDATE purchase_orders SET status = 'Received', received_by = ?, received_at = NOW() WHERE id = ?");
        mysqli_stmt_bind_param($updatePo, 'ii', $userId, $poId);
        mysqli_stmt_execute($updatePo);

        mysqli_commit($conn);
        return ['ok' => true];
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        error_log('po_receive failed for PO #' . $poId . ': ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Unable to receive Purchase Order. Please try again.'];
    }
}