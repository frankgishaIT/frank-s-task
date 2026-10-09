<?php
/**
 * NEW FILE: includes/asset_fund_helpers.php
 * RM Capital Fund <-> Assets (spec sections 9, 10, 11, 12 and 13).
 *
 * One rule for every asset:
 *   the asset's total in the RM Capital Fund = its current_value while it is
 *   Active or Under Maintenance, and 0 once it is Disposed, Lost or deleted.
 *
 * Every asset event (register, depreciation, edit/revalue, dispose, theft, damage,
 * delete) just updates the asset and then calls asset_sync_capital_fund().
 * The function compares the asset's current_value with what the Capital Fund
 * already holds for it and posts ONLY the difference, linked to the asset
 * (ref_type 'ASSET' + asset id). So it can never count an asset twice, and
 * running it again changes nothing.
 */
require_once __DIR__ . '/fund_helpers.php';

// Statuses whose value counts in the RM Capital Fund.
const ASSET_COUNTED_STATUSES = ['Active', 'Under Maintenance'];

// Movement types allowed for asset postings (all exist in fund_movements.movement_type).
const ASSET_MOVEMENT_TYPES = ['ASSET_IN', 'ASSET_OUT', 'DEPRECIATION', 'ADJUSTMENT'];

/**
 * What the RM Capital Fund currently holds for one asset
 * (all its movements, including reversals, IN minus OUT).
 */
function asset_fund_value(mysqli $conn, int $assetId): float {
    $s = mysqli_prepare($conn, "SELECT COALESCE(SUM(CASE WHEN direction = 'IN' THEN amount ELSE -amount END), 0) AS v
        FROM fund_movements WHERE ref_type = 'ASSET' AND ref_id = ?");
    mysqli_stmt_bind_param($s, 'i', $assetId);
    mysqli_stmt_execute($s);
    return round((float) mysqli_fetch_assoc(mysqli_stmt_get_result($s))['v'], 2);
}

/**
 * Brings the RM Capital Fund in line with one asset's value.
 *
 * $movementType says WHY the value changed, so the fund report can show it:
 *   'ASSET_IN'      new asset registered (or value increased by revaluation)
 *   'DEPRECIATION'  value reduced by depreciation
 *   'ADJUSTMENT'    value corrected by an edit or revaluation
 *   'ASSET_OUT'     asset sold, stolen, damaged, disposed or deleted
 *
 * Returns the amount posted (positive = into the fund, negative = out, 0 = nothing to do).
 * MUST be called inside the caller's mysqli_begin_transaction(), AFTER the asset row has
 * been updated (or deleted).
 */
function asset_sync_capital_fund(mysqli $conn, int $assetId, string $movementType, ?int $userId, string $description): float {
    if (!in_array($movementType, ASSET_MOVEMENT_TYPES, true)) {
        throw new InvalidArgumentException('Invalid asset movement type: ' . $movementType);
    }

    $s = mysqli_prepare($conn, 'SELECT status, current_value FROM assets WHERE id = ? FOR UPDATE');
    mysqli_stmt_bind_param($s, 'i', $assetId);
    mysqli_stmt_execute($s);
    $asset = mysqli_fetch_assoc(mysqli_stmt_get_result($s));

    // A deleted, disposed or lost asset no longer counts in the Capital Fund.
    $target = ($asset && in_array($asset['status'], ASSET_COUNTED_STATUSES, true))
        ? round(max(0, (float) $asset['current_value']), 2)
        : 0.0;

    $difference = round($target - asset_fund_value($conn, $assetId), 2);
    if (abs($difference) < 0.01) {
        return 0.0;
    }

    $fund = fund_by_code($conn, 'CAPITAL');
    if (!$fund) {
        throw new RuntimeException('RM Capital Fund was not found.');
    }

    fund_record_movement($conn, (int) $fund['id'], $movementType, $difference > 0 ? 'IN' : 'OUT',
        abs($difference), date('Y-m'), null, $userId, mb_substr($description, 0, 255), null, 'ASSET', $assetId);

    return $difference;
}