<?php
// Asset Management calculation helpers.
//
// Depreciation runs as a "catch-up": whenever this is called for an asset,
// it fills in any missing daily history rows between the last recorded
// date and today, then updates assets.current_value to match. This is
// called from index.php (for every active asset) and view.php (for the
// one asset being viewed), so values are always current as of the last
// time someone opened the module. For true midnight-exact updates even
// with nobody visiting, point a scheduled task at
// modules/assets/depreciate_cron.php.
//
// NEW (spec sections 10 and 13): every catch-up that lowers an asset's value also
// lowers the RM Capital Fund by the same amount (see asset_fund_helpers.php).

require_once __DIR__ . '/asset_fund_helpers.php';

// Generates the next asset code, e.g. AST-000001.
function asset_generate_code($conn, $assetId) {
    return 'RM-AST-' . str_pad($assetId, 6, '0', STR_PAD_LEFT);
}

// Straight-line only for now. Returns the constant daily depreciation
// amount for an asset (0 if useful_life_days is invalid, to avoid division
// by zero).
function asset_daily_depreciation_amount($acquisitionValue, $residualValue, $usefulLifeDays) {
    if ($usefulLifeDays <= 0) { return 0; }
    return round(($acquisitionValue - $residualValue) / $usefulLifeDays, 2);
}

// NEW: true when the asset already has its value in the RM Capital Fund.
// Assets registered before the Capital Fund integration have no movements yet; they are
// added once by modules/assets/sync_capital_fund.php, not by the daily depreciation.
function asset_is_in_capital_fund($conn, $assetId) {
    $s = mysqli_prepare($conn, "SELECT id FROM fund_movements WHERE ref_type = 'ASSET' AND ref_id = ? LIMIT 1");
    mysqli_stmt_bind_param($s, 'i', $assetId);
    mysqli_stmt_execute($s);
    return (bool) mysqli_fetch_assoc(mysqli_stmt_get_result($s));
}

// NEW (spec section 10): after depreciation has lowered current_value, take the same amount
// out of the RM Capital Fund. Only for assets already in the fund (see above), so a brand-new
// asset is added once by its create page as ASSET_IN, never as "DEPRECIATION".
// Because it posts only the difference, running it many times a day is harmless, and a run
// that failed half-way is corrected by the next one.
function asset_sync_depreciation_to_fund($conn, $assetId, $assetCode) {
    if (!asset_is_in_capital_fund($conn, (int) $assetId)) {
        return 0.0;
    }
    return asset_sync_capital_fund($conn, (int) $assetId, 'DEPRECIATION', null,
        'Depreciation of asset ' . $assetCode . ' up to ' . date('Y-m-d'));
}

// Runs the catch-up for ONE asset: inserts one history row per missing
// calendar day (acquisition date up to today), stops adding rows once the
// asset reaches its residual value, and refreshes assets.current_value.
// Assets that are not 'Active' are left exactly as they are — no further
// depreciation is applied while Under Maintenance / Disposed / Lost.
function asset_run_depreciation_catchup($conn, $assetId) {
    $assetStmt = mysqli_prepare($conn, 'SELECT * FROM assets WHERE id = ? FOR UPDATE');
    mysqli_stmt_bind_param($assetStmt, 'i', $assetId);
    mysqli_stmt_execute($assetStmt);
    $asset = mysqli_fetch_assoc(mysqli_stmt_get_result($assetStmt));
    if (!$asset || $asset['status'] !== 'Active') {
        return; // nothing to do — frozen at its last recorded value
    }

    $dailyAmount = asset_daily_depreciation_amount((float) $asset['acquisition_value'], (float) $asset['residual_value'], (int) $asset['useful_life_days']);
    $residual = (float) $asset['residual_value'];

    $lastStmt = mysqli_prepare($conn, 'SELECT valuation_date, value FROM asset_valuation_history WHERE asset_id = ? ORDER BY valuation_date DESC LIMIT 1');
    mysqli_stmt_bind_param($lastStmt, 'i', $assetId);
    mysqli_stmt_execute($lastStmt);
    $last = mysqli_fetch_assoc(mysqli_stmt_get_result($lastStmt));

    $today = new DateTime('today');

    // CHANGED: the insert is prepared once and re-used for every day (it was prepared again
    // for each day, which is slow for an old asset with years of missing history).
    $dayStr = '';
    $depreciationToday = 0.0;
    $currentValue = 0.0;
    $insert = mysqli_prepare($conn, 'INSERT IGNORE INTO asset_valuation_history (asset_id, valuation_date, daily_depreciation, value) VALUES (?, ?, ?, ?)');
    mysqli_stmt_bind_param($insert, 'isdd', $assetId, $dayStr, $depreciationToday, $currentValue);

    if (!$last) {
        // First-ever record: day zero, no depreciation applied yet.
        $day = new DateTime($asset['acquisition_date']);
        $dayStr = $day->format('Y-m-d');
        $depreciationToday = 0.0;
        $currentValue = (float) $asset['acquisition_value'];
        mysqli_stmt_execute($insert);
        $cursor = $day;
    } else {
        $currentValue = (float) $last['value'];
        $cursor = new DateTime($last['valuation_date']);
        if ($currentValue <= $residual + 0.001) {
            // Already fully depreciated — nothing further to add or update.
            // NEW: still make sure the Capital Fund matches (fixes any run that failed earlier).
            asset_sync_depreciation_to_fund($conn, $assetId, $asset['asset_code']);
            return;
        }
    }

    // Walk forward day by day from the last known day, up to and including today.
    while ($cursor < $today) {
        $cursor->modify('+1 day');
        $depreciationToday = min($dailyAmount, max(0, round($currentValue - $residual, 2)));
        $currentValue = round($currentValue - $depreciationToday, 2);
        if ($currentValue < $residual) { $currentValue = $residual; }

        $dayStr = $cursor->format('Y-m-d');
        mysqli_stmt_execute($insert);

        if ($currentValue <= $residual + 0.001) {
            break; // fully depreciated — stop adding further identical rows
        }
    }

    $update = mysqli_prepare($conn, 'UPDATE assets SET current_value = ? WHERE id = ?');
    mysqli_stmt_bind_param($update, 'di', $currentValue, $assetId);
    mysqli_stmt_execute($update);

    // NEW (spec section 10): depreciation lowers the RM Capital Fund by the same amount.
    asset_sync_depreciation_to_fund($conn, $assetId, $asset['asset_code']);
}

// Runs the catch-up for every Active asset. Called from index.php so the
// list and the Total Current Assets Value are always up to date when viewed.
function asset_run_depreciation_catchup_all($conn) {
    $ids = mysqli_query($conn, "SELECT id FROM assets WHERE status = 'Active'");
    while ($row = mysqli_fetch_assoc($ids)) {
        asset_run_depreciation_catchup($conn, (int) $row['id']);
    }
}

// Total Current Assets Value.
// CHANGED: counts Active AND Under Maintenance assets (was Active only). An asset being
// repaired is still owned by the business, and the RM Capital Fund counts it too, so this
// total and the Capital Fund's asset value now match.
function asset_total_current_value($conn) {
    $row = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COALESCE(SUM(current_value), 0) AS total FROM assets WHERE status IN ('Active', 'Under Maintenance')"));
    return (float) $row['total'];
}

// Wipes an asset's valuation history and rebuilds it from the given terms,
// one row per day from the acquisition date up to $endDate (or until the
// residual value is reached), then sets assets.current_value to match.
// Used by the Edit page when depreciation inputs were corrected.
// Returns the new current value.
// NOTE: the caller (Edit page) must call asset_sync_capital_fund(..., 'ADJUSTMENT', ...)
// afterwards, so the RM Capital Fund follows the corrected value.
function asset_rebuild_history($conn, $assetId, $acquisitionDate, $acquisitionValue, $residualValue, $usefulLifeDays, $endDate) {
    $del = mysqli_prepare($conn, 'DELETE FROM asset_valuation_history WHERE asset_id = ?');
    mysqli_stmt_bind_param($del, 'i', $assetId);
    mysqli_stmt_execute($del);

    $dailyAmount = asset_daily_depreciation_amount((float) $acquisitionValue, (float) $residualValue, (int) $usefulLifeDays);
    $residual = (float) $residualValue;
    $currentValue = (float) $acquisitionValue;

    $cursor = new DateTime($acquisitionDate);
    $end = new DateTime($endDate);

    // Prepared once, re-executed for every day.
    $dayStr = $cursor->format('Y-m-d');
    $depreciationToday = 0.0;
    $insert = mysqli_prepare($conn, 'INSERT INTO asset_valuation_history (asset_id, valuation_date, daily_depreciation, value) VALUES (?, ?, ?, ?)');
    mysqli_stmt_bind_param($insert, 'isdd', $assetId, $dayStr, $depreciationToday, $currentValue);

    // Day zero: no depreciation applied yet.
    mysqli_stmt_execute($insert);

    while ($cursor < $end && $currentValue > $residual + 0.001) {
        $cursor->modify('+1 day');
        $depreciationToday = min($dailyAmount, max(0, round($currentValue - $residual, 2)));
        $currentValue = round($currentValue - $depreciationToday, 2);
        if ($currentValue < $residual) { $currentValue = $residual; }
        $dayStr = $cursor->format('Y-m-d');
        mysqli_stmt_execute($insert);
    }
    mysqli_stmt_close($insert);

    $update = mysqli_prepare($conn, 'UPDATE assets SET current_value = ? WHERE id = ?');
    mysqli_stmt_bind_param($update, 'di', $currentValue, $assetId);
    mysqli_stmt_execute($update);
    mysqli_stmt_close($update);

    return $currentValue;
}