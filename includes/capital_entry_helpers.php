<?php
/**
 * NEW FILE: includes/capital_entry_helpers.php
 * Spec section 3: Edit and Delete for MANUAL Capital Fund entries (Add Capital).
 *
 * Nothing is ever changed or erased in fund_movements:
 *   Delete = a REVERSAL row linked to the original (reverses_movement_id).
 *   Edit   = a REVERSAL of the original + a new corrected entry linked to it
 *            (ref_type 'CAPITAL_EDIT', ref_id = the entry it replaces).
 * The fund balance, reports and dashboards read fund_movements, so they all
 * follow automatically, and the full history stays visible.
 *
 * Only manual entries can be edited here. Automatic ones are changed through
 * their own module: loans (ref_type 'loan'), asset sale proceeds ('ASSET_SALE'),
 * purchase orders ('PO'), and so on.
 */
require_once __DIR__ . '/fund_helpers.php';

function capital_sources(): array {
    return [
        'Initial Share Capital',
        'Additional Share Capital',
        'Owner Funding',
        'Business Loan',
        'Grant',
        'Donation',
        'Other Approved Funding',
    ];
}

// A manual entry: a Capital Fund inflow typed in on the Add Capital page (no ref_type),
// or a corrected version of one (ref_type 'CAPITAL_EDIT').
const CAPITAL_MANUAL_WHERE = "m.movement_type = 'CAPITAL_INFLOW' AND (m.ref_type IS NULL OR m.ref_type = 'CAPITAL_EDIT')";

/**
 * Checks the Add / Edit form. Returns an error message, or null when everything is valid.
 */
function capital_entry_validate(string $source, $amount, string $date, string $note): ?string {
    $validDate = DateTime::createFromFormat('Y-m-d', $date);
    if (!in_array($source, capital_sources(), true)) {
        return 'Please select the source of the money.';
    }
    if ($amount === false || $amount === null || $amount <= 0) {
        return 'Please enter a valid amount.';
    }
    if (!$validDate || $validDate->format('Y-m-d') !== $date || $date > date('Y-m-d')) {
        return 'Please enter a valid date (not in the future).';
    }
    if ($source === 'Other Approved Funding' && $note === '') {
        return 'Please describe the approved funding in the Description field.';
    }
    return null;
}

/**
 * Finds a manual entry that has not been deleted or replaced yet. Returns null otherwise.
 * With $lock = true the row is locked (call inside mysqli_begin_transaction()).
 */
function capital_entry_find(mysqli $conn, int $movementId, bool $lock = false): ?array {
    $sql = "SELECT m.* FROM fund_movements m
        JOIN funds f ON f.id = m.fund_id AND f.code = 'CAPITAL'
        LEFT JOIN fund_movements r ON r.reverses_movement_id = m.id
        WHERE m.id = ? AND r.id IS NULL AND " . CAPITAL_MANUAL_WHERE . ($lock ? ' FOR UPDATE' : '');
    $s = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($s, 'i', $movementId);
    mysqli_stmt_execute($s);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($s)) ?: null;
}

/**
 * Records a new manual inflow. MUST be called inside mysqli_begin_transaction() when part
 * of a bigger save. $replacesId links a corrected entry to the one it replaces.
 */
function capital_entry_record(mysqli $conn, string $source, float $amount, string $date, string $note,
        ?int $userId, ?int $replacesId = null): void {
    $fund = fund_by_code($conn, 'CAPITAL');
    if (!$fund) {
        throw new RuntimeException('RM Capital Fund was not found.');
    }
    $desc = mb_substr($note !== '' ? $note : $source, 0, 255);
    fund_record_movement($conn, (int) $fund['id'], 'CAPITAL_INFLOW', 'IN', round($amount, 2), substr($date, 0, 7),
        null, $userId, $desc, $source, $replacesId ? 'CAPITAL_EDIT' : null, $replacesId);
}

/**
 * Money that would leave the fund must still be there. If part of this capital has already
 * been spent, removing it would push the Capital Fund below zero.
 */
function capital_entry_check_can_remove(mysqli $conn, int $fundId, float $amountToRemove): ?string {
    if ($amountToRemove <= 0.001) { return null; }
    $available = fund_available($conn, $fundId);
    if ($amountToRemove > $available + 0.001) {
        return 'This change would remove RWF ' . number_format($amountToRemove, 2) . ' from the RM Capital Fund, but only RWF '
            . number_format($available, 2) . ' is available. Part of this money has already been used.';
    }
    return null;
}

/**
 * Deletes a manual entry (own transaction). Returns ['ok' => bool, 'error' => string].
 */
function capital_entry_delete(mysqli $conn, int $movementId, ?int $userId, string $reason): array {
    mysqli_begin_transaction($conn);
    try {
        $fund = fund_by_code($conn, 'CAPITAL');
        fund_lock($conn, (int) $fund['id']);

        $entry = capital_entry_find($conn, $movementId, true);
        if (!$entry) {
            mysqli_rollback($conn);
            return ['ok' => false, 'error' => 'This entry cannot be deleted. It may already have been deleted or corrected, or it was created automatically by another module.'];
        }
        if ($problem = capital_entry_check_can_remove($conn, (int) $fund['id'], (float) $entry['amount'])) {
            mysqli_rollback($conn);
            return ['ok' => false, 'error' => $problem];
        }

        fund_reverse_movements($conn, 'm.id = ?', 'i', [$movementId], $userId,
            'Capital entry #' . $movementId . ' deleted' . ($reason !== '' ? ': ' . $reason : ''));

        mysqli_commit($conn);
        return ['ok' => true];
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        error_log('capital_entry_delete failed for movement #' . $movementId . ': ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Unable to delete the entry. Nothing was changed.'];
    }
}

/**
 * Corrects a manual entry (own transaction): reverses it and records the corrected one.
 * Returns ['ok' => bool, 'error' => string].
 */
function capital_entry_edit(mysqli $conn, int $movementId, string $source, float $amount, string $date,
        string $note, ?int $userId): array {
    mysqli_begin_transaction($conn);
    try {
        $fund = fund_by_code($conn, 'CAPITAL');
        fund_lock($conn, (int) $fund['id']);

        $entry = capital_entry_find($conn, $movementId, true);
        if (!$entry) {
            mysqli_rollback($conn);
            return ['ok' => false, 'error' => 'This entry cannot be edited. It may already have been deleted or corrected, or it was created automatically by another module.'];
        }

        $amount = round($amount, 2);
        $decrease = round((float) $entry['amount'] - $amount, 2);
        if ($problem = capital_entry_check_can_remove($conn, (int) $fund['id'], $decrease)) {
            mysqli_rollback($conn);
            return ['ok' => false, 'error' => $problem];
        }

        fund_reverse_movements($conn, 'm.id = ?', 'i', [$movementId], $userId,
            'Capital entry #' . $movementId . ' corrected');
        capital_entry_record($conn, $source, $amount, $date, $note, $userId, $movementId);

        mysqli_commit($conn);
        return ['ok' => true];
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        error_log('capital_entry_edit failed for movement #' . $movementId . ': ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Unable to save the correction. Nothing was changed.'];
    }
}

/**
 * Recent manual entries for the Add Capital page, including deleted / corrected ones
 * (so the history stays visible). 'replaced' is set when the entry was reversed.
 */
function capital_entry_recent(mysqli $conn, int $limit = 15): array {
    $s = mysqli_prepare($conn, "SELECT m.*, u.names AS who, r.id AS reversed_by_id,
            (SELECT c.id FROM fund_movements c WHERE c.ref_type = 'CAPITAL_EDIT' AND c.ref_id = m.id LIMIT 1) AS corrected_by_id
        FROM fund_movements m
        JOIN funds f ON f.id = m.fund_id AND f.code = 'CAPITAL'
        LEFT JOIN fund_movements r ON r.reverses_movement_id = m.id
        LEFT JOIN users u ON m.created_by = u.id
        WHERE " . CAPITAL_MANUAL_WHERE . "
        ORDER BY m.id DESC LIMIT ?");
    mysqli_stmt_bind_param($s, 'i', $limit);
    mysqli_stmt_execute($s);
    return mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC);
}