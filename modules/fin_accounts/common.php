<?php
/**
 * NEW FILE: modules/fin_accounts/common.php — shared by the FINANCIAL ACCOUNTS pages (Task 2).
 * Permissions (Roles & Permissions → Financial Accounts):
 *   fin_accounts.view        see accounts, balances, fund distribution, activity (Management)
 *   fin_accounts.manage      add / edit / deactivate accounts, assignment rules, link movements (Admin)
 *   fin_accounts.transfer    record and reverse transfers between accounts (Admin / Finance user)
 *   fin_accounts.adjust      account adjustments (Admin)
 *   fin_accounts.reconcile   import bank statements and reconcile (Finance user)
 *   fin_accounts.approve     approve and complete reconciliations (Admin)
 *   fin_accounts.identifiers see full account numbers (others see ••••1234)
 */
if (session_status() === PHP_SESSION_NONE) { require_once __DIR__ . '/../../includes/session_boot.php'; rm_session_start(); }
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/access_control.php';
require_once __DIR__ . '/../../includes/fin_accounts.php';
require_role(['Admin', 'Manager', 'Employee']);
fa_ensure($conn);
try { fa_sync($conn); } catch (Throwable $e) { error_log('fa_sync: ' . $e->getMessage()); }

function fac(mysqli $conn, string $a): bool { return access_can('fin_accounts.' . $a, $conn); }
function fa_require(mysqli $conn, string $a): void { if (!fac($conn, $a)) { http_response_code(403); include __DIR__ . '/../../includes/access_denied.php'; exit; } }
function fa_csrf_field(): string { if (empty($_SESSION['fa_csrf'])) { $_SESSION['fa_csrf'] = bin2hex(random_bytes(16)); } return '<input type="hidden" name="csrf" value="' . $_SESSION['fa_csrf'] . '">'; }
function fa_csrf_ok(): bool { return isset($_POST['csrf'], $_SESSION['fa_csrf']) && hash_equals($_SESSION['fa_csrf'], (string) $_POST['csrf']); }
function fa_go(string $url, string $type, string $msg): void { header('Location: ' . $url . (strpos($url, '?') === false ? '?' : '&') . $type . '=' . urlencode($msg)); exit; }
function fa_date($v): string { return $v ? date('d M Y', strtotime((string) $v)) : '—'; }

function fa_tabs(mysqli $conn, string $active, string $title, string $right = ''): void
{
    $tabs = [['index.php', 'Overview', 'view'], ['activity.php', 'Activity & history', 'view'], ['accounts.php', 'Accounts', 'view'], ['transfers.php', 'Transfers', 'view'],
             ['reconcile.php', 'Bank reconciliation', 'reconcile'], ['assign.php', 'Link to accounts', 'manage'], ['adjustments.php', 'Adjustments', 'adjust']];
    echo '<style>.fa-tabs{display:flex;flex-wrap:nowrap;overflow-x:auto;gap:2px;border-bottom:1px solid #E4E7EF;margin-bottom:14px;scrollbar-width:none}.fa-tabs::-webkit-scrollbar{display:none}.fa-tabs a{padding:7px 12px;font-size:13.5px;color:#5B6275;text-decoration:none;border-bottom:2px solid transparent;white-space:nowrap}
        .fa-tabs a.on{color:#1E2FE0;border-bottom-color:#1E2FE0;font-weight:600}.fa-k .lbl{font-size:12px;color:#6B7280}.fa-k .val{font-weight:700;font-size:1.25rem;color:#0B1A8C}.fa-k .sub{font-size:11.5px;color:#8A90A2}
        .fa-tbl td,.fa-tbl th{font-size:13px;vertical-align:middle}.fa-pill{display:inline-block;font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px;white-space:nowrap}.fa-in{color:#0A7A4B}.fa-out{color:#B42318}</style>';
    echo '<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-2"><div><h3 class="mb-0">' . fa_e($title) . '</h3><div class="small text-muted">Financial Accounts · where the money is held, and for which fund</div></div>' . $right . '</div>';
    echo '<div class="fa-tabs">'; foreach ($tabs as [$f, $l, $p]) { if (fac($conn, $p)) { echo '<a href="' . $f . '" class="' . ($f === $active ? 'on' : '') . '">' . $l . '</a>'; } } echo '</div>';
    foreach (['success' => 'success', 'error' => 'danger'] as $k => $c) { if (!empty($_GET[$k])) { echo '<div class="alert alert-' . $c . ' py-2 small">' . fa_e($_GET[$k]) . '</div>'; } }
}

function fa_amt(float $v): string { return '<span class="' . ($v < 0 ? 'fa-out' : 'fa-in') . '">' . ($v < 0 ? '−' : '+') . number_format(abs($v), 0) . '</span>'; }
function fa_status_pill(string $s): string
{
    $c = ['Active' => '#E7F7EF;color:#0A6B43', 'Inactive' => '#F1F2F6;color:#5B6275', 'Posted' => '#E6F0FF;color:#1E2FE0', 'Reversed' => '#FDECEC;color:#9B1C1C', 'In progress' => '#FFF4D6;color:#6B4E00',
          'Submitted' => '#E6F0FF;color:#1E2FE0', 'Completed' => '#0FA968;color:#fff', 'Matched' => '#E7F7EF;color:#0A6B43', 'Unmatched' => '#FFF4D6;color:#6B4E00', 'Bank fee' => '#E6F0FF;color:#1E2FE0',
          'Created' => '#E7F7EF;color:#0A6B43', 'Investigate' => '#FDECEC;color:#9B1C1C', 'Duplicate' => '#F1F2F6;color:#5B6275', 'Unresolved' => '#F1F2F6;color:#5B6275'][$s] ?? '#F1F2F6;color:#5B6275';
    return '<span class="fa-pill" style="background:' . $c . '">' . fa_e($s) . '</span>';
}

/** Funds that hold MONEY (all active funds). */
function fa_funds(mysqli $conn): array { return funds_all($conn); }
function fa_fund_short(string $n): string { return trim(preg_replace(['/^RM\s+/', '/\s+Fund$/'], '', $n)); }