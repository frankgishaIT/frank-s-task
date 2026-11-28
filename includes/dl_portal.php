<?php
/**
 * NEW FILE: includes/dl_portal.php — RM Tasker access through the EXISTING Customers Portal (no separate portal).
 *
 * Separation (server side, not only menus): a Tasker session holds portal_tasker_id and NEVER portal_customer_id,
 * so every customer page (portal_require_login) sends a Tasker back to the sign-in page. Every Tasker page checks the
 * session and reads only rows of that Tasker (party_id = own id): other Taskers' work, customer lists, internal
 * prices, profit and staff data are never queried for display.
 * A person who is also a regular customer signs in as a customer with the customer account, and as a Tasker with
 * the RM Tasker ID: one identity per session, and Tasker status never gives customer access.
 */
require_once __DIR__ . '/dl_workflow.php';

function dlp_tasker(mysqli $conn): ?array
{
    $pid = (int) ($_SESSION['portal_tasker_id'] ?? 0);
    if ($pid <= 0) { return null; }
    dlw_ensure($conn);
    $a = dl_account($conn, $pid);
    $t = dl_tasker($conn, $pid);
    if (!$a || $a['status'] !== 'active' || !$a['password_hash'] || !$t) { unset($_SESSION['portal_tasker_id']); return null; }
    return $t;
}

/** Tasker pages: signed-in Tasker or back to the sign-in page. Never a customer session. */
function dlp_require(mysqli $conn): array
{
    if (!portal_is_open($conn)) { http_response_code(503); echo 'The portal is not available at the moment.'; exit; }
    $t = dlp_tasker($conn);
    if (!$t) { header('Location: login.php'); exit; }
    return $t;
}

function dlp_find(mysqli $conn, string $idf): ?array
{
    dlw_ensure($conn);
    $idf = trim($idf);
    if (preg_match('/^RMST0*(\d+)$/i', $idf, $m)) {
        $r = mysqli_fetch_row(mysqli_query($conn, 'SELECT party_id FROM dl_taskers WHERE id = ' . (int) $m[1]));
        return $r ? dl_tasker($conn, (int) $r[0]) : null;
    }
    $digits = preg_replace('/\D/', '', $idf);
    if (strlen($digits) < 9) { return null; }
    $last9 = mysqli_real_escape_string($conn, substr($digits, -9));
    $r = mysqli_fetch_row(mysqli_query($conn, "SELECT id FROM business_parties WHERE type = 'Tasker' AND RIGHT(REPLACE(REPLACE(phone, ' ', ''), '+', ''), 9) = '$last9' LIMIT 1"));
    return $r ? dl_tasker($conn, (int) $r[0]) : null;
}

function dlp_login(mysqli $conn, string $idf, string $password): bool
{
    $t = dlp_find($conn, $idf);
    if (!$t) { return false; }
    $a = dl_account($conn, (int) $t['id']);
    if (!$a || $a['status'] !== 'active' || !$a['password_hash'] || !password_verify($password, $a['password_hash'])) { return false; }
    session_regenerate_id(true);
    unset($_SESSION['portal_customer_id']);
    $_SESSION['portal_tasker_id'] = (int) $t['id'];
    $now = dl_now();
    mysqli_query($conn, "UPDATE dl_tasker_accounts SET last_login_at = '$now' WHERE party_id = " . (int) $t['id']);
    dl_audit($conn, 'tasker', (int) $t['id'], 'portal_login', $t['code'] . ' signed in to the portal', null, 'tasker', (int) $t['id']);
    return true;
}

/** The Tasker's own delegation, or null (never another Tasker's). */
function dlp_own(mysqli $conn, array $t, string $ref): ?array
{
    $id = dl_id_from_ref($ref);
    if (!$id) { return null; }
    $d = dl_get($conn, $id);
    if (!$d || (int) $d['party_id'] !== (int) $t['id'] || in_array($d['status'], ['Draft', 'Pending Approval'], true)) { return null; }
    return $d;
}

function dlp_header(string $title, array $t): void
{
    portal_header($title, ['id' => (int) $t['id'], 'name' => $t['display'], 'tasker_code' => $t['code']]);
}

/** What the Tasker sees as status (no internal wording). */
function dlp_status(array $d): string
{
    if ($d['status'] === 'Reassigned') { return 'Reassigned (no longer yours)'; }
    if ($d['status'] === 'Awaiting Tasker Acceptance') { return 'Waiting for your answer'; }
    if ($d['status'] === 'Completed – Awaiting Verification') { return 'Delivered – being checked'; }
    if ($d['status'] === 'Issue Review') { return 'Under review'; }
    return $d['status'];
}