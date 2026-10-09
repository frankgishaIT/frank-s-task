<?php
class InsufficientFundException extends RuntimeException {}

function fund_expense_categories(): array {
    return ['Rent','Electricity','Water','Internet','Telephone','Other utilities',
            'Transport','Marketing','Office Expenses','Bank Charges','Taxes',
            'Maintenance','Communication','Staff training','Learning materials',
            'Employee development','Team building','Employee recognition'];
}

function funds_all(mysqli $conn): array {
    $res = mysqli_query($conn, 'SELECT * FROM funds WHERE is_active = 1 ORDER BY sort_order');
    return mysqli_fetch_all($res, MYSQLI_ASSOC);
}

function fund_get(mysqli $conn, int $fundId): ?array {
    $s = mysqli_prepare($conn, 'SELECT * FROM funds WHERE id = ? AND is_active = 1');
    mysqli_stmt_bind_param($s, 'i', $fundId);
    mysqli_stmt_execute($s);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($s)) ?: null;
}

// Finds a fund by its code: OPERATING, FUTURE_PLANS, EMERGENCY, TEAM_GROWTH, CAPITAL.
function fund_by_code(mysqli $conn, string $code): ?array {
    $s = mysqli_prepare($conn, 'SELECT * FROM funds WHERE code = ? AND is_active = 1');
    mysqli_stmt_bind_param($s, 's', $code);
    mysqli_stmt_execute($s);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($s)) ?: null;
}

function fund_balance(mysqli $conn, int $fundId): float {
    $s = mysqli_prepare($conn, "SELECT COALESCE(SUM(CASE WHEN direction='IN' THEN amount ELSE -amount END),0) AS b FROM fund_movements WHERE fund_id = ?");
    mysqli_stmt_bind_param($s, 'i', $fundId);
    mysqli_stmt_execute($s);
    return (float) mysqli_fetch_assoc(mysqli_stmt_get_result($s))['b'];
}

// Expenses waiting for admin approval are "reserved" so two pending
// expenses cannot both spend the same money.
function fund_pending_total(mysqli $conn, int $fundId): float {
    $s = mysqli_prepare($conn, "SELECT COALESCE(SUM(amount),0) AS p FROM transactions WHERE fund_id = ? AND transaction_type = 'Expense' AND status = 'pending'");
    mysqli_stmt_bind_param($s, 'i', $fundId);
    mysqli_stmt_execute($s);
    return (float) mysqli_fetch_assoc(mysqli_stmt_get_result($s))['p'];
}

function fund_available(mysqli $conn, int $fundId): float {
    return fund_balance($conn, $fundId) - fund_pending_total($conn, $fundId);
}

// Locks the fund row so two users cannot overspend at the same moment.
// Must be called inside mysqli_begin_transaction().
function fund_lock(mysqli $conn, int $fundId): void {
    $s = mysqli_prepare($conn, 'SELECT id FROM funds WHERE id = ? AND is_active = 1 FOR UPDATE');
    mysqli_stmt_bind_param($s, 'i', $fundId);
    mysqli_stmt_execute($s);
    if (!mysqli_fetch_assoc(mysqli_stmt_get_result($s))) {
        throw new RuntimeException('Selected fund does not exist.');
    }
}

// CHANGED: two optional parameters ($refType, $refId) link the movement to the record
// that caused it (for example 'SALE' + sale id). Old callers keep working unchanged.
function fund_record_movement(mysqli $conn, int $fundId, string $type, string $direction,
        float $amount, string $period, ?int $txId, ?int $userId, string $desc,
        ?string $source = null, ?string $refType = null, ?int $refId = null): void {
    if ($amount < 0) {
        throw new InvalidArgumentException('A fund movement amount cannot be negative. Use the direction instead.');
    }
    $s = mysqli_prepare($conn, 'INSERT INTO fund_movements (fund_id, period, movement_type, direction, amount, transaction_id, source_type, description, created_by, ref_type, ref_id) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
    mysqli_stmt_bind_param($s, 'isssdissisi', $fundId, $period, $type, $direction, $amount, $txId, $source, $desc, $userId, $refType, $refId);
    mysqli_stmt_execute($s);
}

function fund_insufficient_message(string $fundName): string {
    return 'Insufficient ' . $fundName . ' Balance. This transaction cannot be completed because the available '
         . preg_replace('/^RM /', '', $fundName) . ' is insufficient.';
}

/**
 * Posts an AUTOMATIC expense (payroll, loan repayment, purchase order, re-stocking...).
 * It locks the fund, checks the available balance, creates an approved Expense
 * transaction linked to the fund, and writes the ledger entry.
 *
 * MUST be called inside mysqli_begin_transaction(). Throws InsufficientFundException
 * when the fund cannot cover the amount, so the caller can roll everything back.
 * Returns the new transaction id.
 *
 * CHANGED: optional $refType / $refId link the ledger entry to the source record
 * (for example 'PO' + purchase order id) so it can be reversed later.
 */
function fund_post_automatic_expense(mysqli $conn, string $fundCode, string $category, float $amount,
        string $date, string $description, ?int $userId, ?string $expenseCategory = null,
        ?string $refType = null, ?int $refId = null): int {
    $fund = fund_by_code($conn, $fundCode);
    if (!$fund) {
        throw new RuntimeException('Fund ' . $fundCode . ' was not found.');
    }
    $fundId = (int) $fund['id'];

    fund_lock($conn, $fundId);
    if ($amount > fund_available($conn, $fundId) + 0.001) {
        throw new InsufficientFundException(fund_insufficient_message($fund['name']));
    }

    $s = mysqli_prepare($conn, "INSERT INTO transactions
        (category, transaction_type, amount, transaction_date, description, recorded_by, status, fund_id, expense_category, is_automatic)
        VALUES (?, 'Expense', ?, ?, ?, ?, 'approved', ?, ?, 1)");
    mysqli_stmt_bind_param($s, 'sdssiis', $category, $amount, $date, $description, $userId, $fundId, $expenseCategory);
    mysqli_stmt_execute($s);
    $txId = (int) mysqli_insert_id($conn);

    fund_record_movement($conn, $fundId, 'EXPENSE', 'OUT', $amount, substr($date, 0, 7), $txId, $userId, $description, null, $refType, $refId);
    return $txId;
}

// Records money coming INTO the RM Capital Fund from a source that is not profit
// (a business loan received, share capital, a grant...). It is NOT income and does
// not touch Net Profit. $refType / $refId optionally link the inflow to the record
// that caused it (for example 'loan' and the loan id), so it can be found and
// adjusted later. Call inside mysqli_begin_transaction() when it is part of a bigger save.
function fund_record_capital_inflow(mysqli $conn, float $amount, string $date, string $source,
        string $description, ?int $userId, ?string $refType = null, ?int $refId = null): void {
    $fund = fund_by_code($conn, 'CAPITAL');
    if (!$fund) {
        throw new RuntimeException('RM Capital Fund was not found.');
    }
    $fundId = (int) $fund['id'];
    $period = substr($date, 0, 7);
    $description = mb_substr($description, 0, 255);

    $s = mysqli_prepare($conn, "INSERT INTO fund_movements
        (fund_id, period, movement_type, direction, amount, source_type, description, created_by, ref_type, ref_id)
        VALUES (?, ?, 'CAPITAL_INFLOW', 'IN', ?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($s, 'isdssisi', $fundId, $period, $amount, $source, $description, $userId, $refType, $refId);
    mysqli_stmt_execute($s);
}

// Takes money OUT of the RM Capital Fund as a CORRECTION of an earlier inflow
// (for example when the amount of a loan that was received is reduced).
// It is stored as an ADJUSTMENT, so it lowers "Contributed" and the balance
// but is not counted as money "Used". The caller must check that the fund has
// enough money first. Call inside mysqli_begin_transaction().
function fund_record_capital_adjustment_out(mysqli $conn, float $amount, string $date, string $source,
        string $description, ?int $userId, ?string $refType = null, ?int $refId = null): void {
    $fund = fund_by_code($conn, 'CAPITAL');
    if (!$fund) {
        throw new RuntimeException('RM Capital Fund was not found.');
    }
    $fundId = (int) $fund['id'];
    $period = substr($date, 0, 7);
    $description = mb_substr($description, 0, 255);

    $s = mysqli_prepare($conn, "INSERT INTO fund_movements
        (fund_id, period, movement_type, direction, amount, source_type, description, created_by, ref_type, ref_id)
        VALUES (?, ?, 'ADJUSTMENT', 'OUT', ?, ?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($s, 'isdssisi', $fundId, $period, $amount, $source, $description, $userId, $refType, $refId);
    mysqli_stmt_execute($s);
}

/* =====================================================================
 * NEW: automatic profit allocation (spec sections 1 and 2)
 * Requires the column fund_movements.reverses_movement_id (see migration).
 * ===================================================================== */

// Splits an amount by the fund percentages. Operating gets the remainder,
// so the four parts always add up to exactly the amount (works for losses too).
// Moved here from allocate_profit.php so every module uses the same rule.
function build_allocation(array $funds, float $net): array {
    $order = ['FUTURE_PLANS', 'EMERGENCY', 'TEAM_GROWTH', 'OPERATING'];
    $byCode = [];
    foreach ($funds as $f) {
        if ($f['allocation_percent'] !== null) { $byCode[$f['code']] = $f; }
    }
    if (!isset($byCode['OPERATING'])) {
        throw new RuntimeException('RM Business Operating Fund is missing or has no allocation percentage.');
    }
    $rows = [];
    $sum = 0.0;
    foreach ($order as $code) {
        if ($code === 'OPERATING' || !isset($byCode[$code])) { continue; }
        $amt = round($net * (float) $byCode[$code]['allocation_percent'] / 100, 2);
        $rows[$code] = ['fund' => $byCode[$code], 'amount' => $amt];
        $sum += $amt;
    }
    $rows['OPERATING'] = ['fund' => $byCode['OPERATING'], 'amount' => round($net - $sum, 2)];

    $ordered = [];
    foreach ($order as $code) {
        if (isset($rows[$code])) { $ordered[] = $rows[$code]; }
    }
    return $ordered;
}

// Real profit of ONE sale, using the same rules as the monthly profit page.
function sale_profit(mysqli $conn, int $saleId): float {
    if (!function_exists('sales_report_line_net_sql') || !function_exists('sales_report_line_cost_sql')) {
        throw new RuntimeException('includes/profit_rules.php must be loaded before calculating sale profit.');
    }
    $lineNet  = sales_report_line_net_sql('sales', 'sale_items');
    $lineCost = sales_report_line_cost_sql('sales', 'sale_items', 'products');
    $s = mysqli_prepare($conn, "SELECT COALESCE(SUM($lineNet - $lineCost), 0) AS profit
        FROM sale_items
        JOIN sales ON sale_items.sale_id = sales.id
        LEFT JOIN products ON sale_items.product_id = products.id
        WHERE sales.id = ?");
    mysqli_stmt_bind_param($s, 'i', $saleId);
    mysqli_stmt_execute($s);
    return round((float) mysqli_fetch_assoc(mysqli_stmt_get_result($s))['profit'], 2);
}

// Section 1: call when a sale becomes paid/completed. Safe to call twice.
// MUST be called inside mysqli_begin_transaction().
function allocate_sale_profit(mysqli $conn, int $saleId, ?int $userId): void {
    // Lock the sale row so two requests cannot allocate at the same time.
    $lock = mysqli_prepare($conn, 'SELECT id FROM sales WHERE id = ? FOR UPDATE');
    mysqli_stmt_bind_param($lock, 'i', $saleId);
    mysqli_stmt_execute($lock);
    mysqli_stmt_get_result($lock);

    // Already allocated? Do nothing.
    $chk = mysqli_prepare($conn, "SELECT id FROM fund_movements
        WHERE ref_type = 'SALE' AND ref_id = ? AND movement_type = 'ALLOCATION' LIMIT 1");
    mysqli_stmt_bind_param($chk, 'i', $saleId);
    mysqli_stmt_execute($chk);
    if (mysqli_fetch_assoc(mysqli_stmt_get_result($chk))) { return; }

    $profit = sale_profit($conn, $saleId);
    if ($profit == 0.0) { return; }

    $period = date('Y-m');
    foreach (build_allocation(funds_all($conn), $profit) as $row) {
        $dir = $row['amount'] >= 0 ? 'IN' : 'OUT';
        fund_record_movement($conn, (int) $row['fund']['id'], 'ALLOCATION', $dir, abs($row['amount']),
            $period, null, $userId, 'Sale #' . $saleId . ' profit allocation', null, 'SALE', $saleId);
    }
}

/**
 * Generic reversal. Finds every movement matching $whereSql that has not been
 * reversed yet and posts an opposite-direction REVERSAL row for each, linked to
 * the original through reverses_movement_id. Originals are never edited or deleted.
 *
 * $whereSql refers to the movement as "m", for example:
 *   "m.ref_type = 'SALE' AND m.ref_id = ?"      with $types 'i', $params [$saleId]
 *   "m.transaction_id = ?"                       with $types 'i', $params [$txId]
 * MUST be called inside mysqli_begin_transaction(). Returns how many rows were reversed.
 */
function fund_reverse_movements(mysqli $conn, string $whereSql, string $types, array $params,
        ?int $userId, string $reason): int {
    $q = mysqli_prepare($conn, "SELECT m.* FROM fund_movements m
        LEFT JOIN fund_movements r ON r.reverses_movement_id = m.id
        WHERE m.movement_type <> 'REVERSAL' AND r.id IS NULL AND ($whereSql)
        FOR UPDATE");
    mysqli_stmt_bind_param($q, $types, ...$params);
    mysqli_stmt_execute($q);
    $originals = mysqli_fetch_all(mysqli_stmt_get_result($q), MYSQLI_ASSOC);

    $rev = mysqli_prepare($conn, "INSERT INTO fund_movements
        (fund_id, period, movement_type, direction, amount, transaction_id, source_type, description, created_by, ref_type, ref_id, reverses_movement_id)
        VALUES (?, ?, 'REVERSAL', ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $period = date('Y-m');   // the reversal is posted in the month it happens
    $desc = mb_substr('Reversal: ' . $reason, 0, 255);
    foreach ($originals as $m) {
        $fid    = (int) $m['fund_id'];
        $dir    = $m['direction'] === 'IN' ? 'OUT' : 'IN';
        $amt    = (float) $m['amount'];
        $txId   = $m['transaction_id'] !== null ? (int) $m['transaction_id'] : null;
        $source = $m['source_type'];
        $refT   = $m['ref_type'];
        $refId  = $m['ref_id'] !== null ? (int) $m['ref_id'] : null;
        $origId = (int) $m['id'];
        mysqli_stmt_bind_param($rev, 'issdissisii', $fid, $period, $dir, $amt, $txId, $source, $desc, $userId, $refT, $refId, $origId);
        mysqli_stmt_execute($rev);
    }
    return count($originals);
}

// Section 2: call when a paid sale is cancelled. Reverses the PROFIT ALLOCATIONS the sale
// made to the four funds. Safe to call twice: already reversed rows are skipped.
// CHANGED: limited to ALLOCATION rows. The Capital Fund cost-of-stock movement is reversed
// separately by reverse_sale_stock_cost(), and only when the goods really come back into
// stock — otherwise the Capital Fund would no longer match the stock value.
function reverse_sale_profit(mysqli $conn, int $saleId, ?int $userId): int {
    return fund_reverse_movements($conn,
        "m.ref_type = 'SALE' AND m.ref_id = ? AND m.movement_type = 'ALLOCATION'", 'i', [$saleId],
        $userId, 'Sale #' . $saleId . ' cancelled');
}

/* =====================================================================
 * NEW: Capital Fund and cost of stock sold (spec section 8)
 * Stock leaving through a sale lowers the RM Capital Fund by its BUYING price.
 * Sales revenue and profit are handled separately (allocate_sale_profit).
 * No Expense transaction is created: the cost is already inside the sale's
 * profit, so recording it again in Transactions would count it twice.
 * ===================================================================== */

// Buying-price value of the PRODUCTS on one sale (services carry no stock).
// Uses the same cost rule as profit (profit_rules.php), so profit + cost always
// match the sale amount.
function sale_stock_cost(mysqli $conn, int $saleId): float {
    if (!function_exists('sales_report_line_cost_sql')) {
        throw new RuntimeException('includes/profit_rules.php must be loaded before calculating the cost of stock sold.');
    }
    $lineCost = sales_report_line_cost_sql('sales', 'sale_items', 'products');
    $s = mysqli_prepare($conn, "SELECT COALESCE(SUM(CASE WHEN sale_items.item_type = 'Product' THEN $lineCost ELSE 0 END), 0) AS cost
        FROM sale_items
        JOIN sales ON sale_items.sale_id = sales.id
        LEFT JOIN products ON sale_items.product_id = products.id
        WHERE sales.id = ?");
    mysqli_stmt_bind_param($s, 'i', $saleId);
    mysqli_stmt_execute($s);
    return round((float) mysqli_fetch_assoc(mysqli_stmt_get_result($s))['cost'], 2);
}

// Call when a sale's stock is deducted (sales_finalize). Lowers the RM Capital Fund by the
// cost of the stock sold. Safe to call twice. MUST be called inside mysqli_begin_transaction().
// It does NOT check the Capital Fund balance: this is stock that already left the shop,
// not money being spent, so a sale must never be blocked by it.
function record_sale_stock_cost(mysqli $conn, int $saleId, ?int $userId): void {
    $chk = mysqli_prepare($conn, "SELECT id FROM fund_movements
        WHERE ref_type = 'SALE' AND ref_id = ? AND movement_type = 'STOCK_OUT' LIMIT 1");
    mysqli_stmt_bind_param($chk, 'i', $saleId);
    mysqli_stmt_execute($chk);
    if (mysqli_fetch_assoc(mysqli_stmt_get_result($chk))) { return; }

    $cost = sale_stock_cost($conn, $saleId);
    if ($cost <= 0) { return; }

    $fund = fund_by_code($conn, 'CAPITAL');
    if (!$fund) {
        throw new RuntimeException('RM Capital Fund was not found.');
    }

    fund_record_movement($conn, (int) $fund['id'], 'STOCK_OUT', 'OUT', $cost, date('Y-m'), null, $userId,
        'Sale #' . $saleId . ' cost of stock sold', null, 'SALE', $saleId);
}

// Call when a cancelled sale's goods are put back into stock. Returns the cost of stock
// to the RM Capital Fund. Safe to call twice.
function reverse_sale_stock_cost(mysqli $conn, int $saleId, ?int $userId): int {
    return fund_reverse_movements($conn,
        "m.ref_type = 'SALE' AND m.ref_id = ? AND m.movement_type = 'STOCK_OUT'", 'i', [$saleId],
        $userId, 'Sale #' . $saleId . ' cancelled, goods returned to stock');
}