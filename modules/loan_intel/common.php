<?php
/**
 * NEW FILE: modules/loan_intel/common.php — shared by the Company Loans & Investment Intelligence pages.
 * Corporate BORROWING only (loans payable). RM Credit Hub (customer lending) is a different module and is not touched.
 */
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/access_control.php';
require_once __DIR__ . '/../../includes/loan_intel.php';
require_once __DIR__ . '/../../includes/analytics_ui.php';
if (current_user_role() === 'Admin') { require_once __DIR__ . '/../../includes/admin_error_guard.php'; }
li_ensure($conn);

function li_e($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); }
function li_rwf($v, int $dec = 0): string { return $v === null ? '—' : 'RWF ' . number_format((float) $v, $dec); }
function li_n($v, int $dec = 0): string { return $v === null ? '—' : number_format((float) $v, $dec); }
function li_pct($v): string { return $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 1), '0'), '.') . '%'; }

function li_deny(): void
{
    http_response_code(403);
    include __DIR__ . '/../../includes/access_denied.php';
    exit;
}

function li_require(mysqli $conn, string $action): void { if (!li_can($conn, $action)) { li_deny(); } }

function li_csrf(): string { if (empty($_SESSION['li_csrf'])) { $_SESSION['li_csrf'] = bin2hex(random_bytes(16)); } return $_SESSION['li_csrf']; }
function li_csrf_field(): string { return '<input type="hidden" name="csrf" value="' . li_csrf() . '">'; }
function li_csrf_ok(): bool { return isset($_POST['csrf'], $_SESSION['li_csrf']) && hash_equals($_SESSION['li_csrf'], (string) $_POST['csrf']); }

function li_go(string $url, string $type, string $msg): void
{
    header('Location: ' . $url . (strpos($url, '?') === false ? '?' : '&') . $type . '=' . urlencode($msg));
    exit;
}

/** "Actual" / "Estimated" / "Forecast" / "Assumption" label next to a figure. */
function li_tag(string $kind): string
{
    $c = ['Actual' => ['#0FA968', '#E1F7EE'], 'Estimated' => ['#1E2FE0', '#E6E8FD'], 'Forecast' => ['#7C5CE0', '#EFEBFC'], 'Assumption' => ['#B7791F', '#FDF3E1']][$kind] ?? ['#6B7280', '#F1F3F9'];
    return '<span class="li-tag" style="color:' . $c[0] . ';background:' . $c[1] . ';">' . li_e($kind) . '</span>';
}

function li_decision_badge(string $code, bool $big = false): string
{
    [$l, $c] = LI_DECISIONS[$code] ?? [$code, '#6B7280'];
    return '<span class="badge" style="background:' . $c . ';' . ($big ? 'font-size:1rem;padding:.55em .9em;' : '') . '">' . li_e($l) . '</span>';
}

function li_status_badge(string $s): string
{
    $c = ['Draft' => 'secondary', 'Submitted' => 'warning text-dark', 'Approved' => 'success', 'Rejected' => 'danger', 'Funded' => 'primary', 'Completed' => 'dark',
          'Cancelled' => 'light text-dark border', 'Proposed' => 'warning text-dark', 'Awaiting review' => 'warning text-dark', 'Accepted' => 'success',
          'Above Expectations' => 'success', 'Performing as Expected' => 'primary', 'Below Expectations' => 'warning text-dark', 'Underperforming' => 'danger',
          'Financially At Risk' => 'danger', 'Not yet measurable' => 'light text-dark border'][$s] ?? 'secondary';
    return '<span class="badge bg-' . $c . '">' . li_e($s) . '</span>';
}

function li_flash(): void
{
    foreach (['success' => 'success', 'error' => 'danger'] as $k => $c) {
        if (isset($_GET[$k])) { echo '<div class="alert alert-' . $c . ' alert-dismissible fade show py-2" style="white-space:pre-line;">' . li_e($_GET[$k]) . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>'; }
    }
}

/** Header + section tabs. The sections are kept visually separate (spec 15). */
function li_tabs(mysqli $conn, string $active, string $title, string $sub = ''): void
{
    $tabs = [
        ['index.php', 'Dashboard', 'bi-speedometer2', li_can($conn, 'view')],
        ['../loans/index.php', 'Company Loans', 'bi-bank', current_user_role() === 'Admin'],
        ['needs.php', 'Financing Needs', 'bi-search', li_can($conn, 'view')],
        ['proposals.php', 'Investment Opportunities', 'bi-lightbulb', li_can($conn, 'view') || li_can($conn, 'propose')],
        ['simulate.php', 'Repayment Simulation', 'bi-calculator', li_can($conn, 'view')],
        ['risk.php', 'Financial Risk', 'bi-shield-exclamation', li_can($conn, 'view')],
        ['performance.php', 'Loan Performance', 'bi-graph-up-arrow', li_can($conn, 'view')],
        ['recommendations.php', 'Recommendations', 'bi-clipboard-check', li_can($conn, 'view')],
        ['settings.php', 'Settings', 'bi-sliders', li_can($conn, 'configure')],
        ['audit.php', 'Audit Trail', 'bi-journal-text', li_can($conn, 'approve') || li_can($conn, 'configure')],
    ];
    echo '<style>.li-tabs{display:flex;flex-wrap:wrap;gap:4px;border-bottom:1px solid var(--border-soft,#E4E7EF);margin-bottom:14px}
        .li-tabs a{padding:7px 11px;font-size:13px;color:#5B6275;text-decoration:none;border-bottom:2px solid transparent}
        .li-tabs a.on{color:#1E2FE0;border-bottom-color:#1E2FE0;font-weight:600}.li-tabs a:hover{color:#1E2FE0}
        .li-tag{font-size:10.5px;font-weight:600;padding:1px 6px;border-radius:6px;white-space:nowrap}
        .li-kpi .lbl{font-size:12px;color:#6B7280;align-items:flex-start}.li-kpi .lbl .li-tag{flex:0 0 auto}.li-kpi .val{font-weight:700;font-size:1.05rem}
        .li-sec{font-size:12px;letter-spacing:.06em;text-transform:uppercase;color:#1E2FE0;font-weight:700;margin:6px 0 8px}
        .li-formula{font-family:ui-monospace,Consolas,monospace;font-size:12px;background:#F4F6FB;border-radius:6px;padding:2px 6px}</style>';
    echo '<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-2"><div><h3 class="mb-0">' . li_e($title) . '</h3>'
        . '<div class="small text-muted">Company Loans &amp; Investment Intelligence · corporate borrowing (loans payable). RM Credit Hub (customer lending) is separate.' . ($sub ? ' ' . $sub : '') . '</div></div></div>';
    echo '<div class="li-tabs">';
    foreach ($tabs as [$f, $l, $i, $show]) { if ($show) { echo '<a href="' . $f . '" class="' . ($f === $active ? 'on' : '') . '"><i class="bi ' . $i . '"></i> ' . $l . '</a>'; } }
    echo '</div>';
    li_flash();
}

function li_kpi(string $label, string $value, string $tag = '', string $hint = '', string $color = ''): string
{
    return '<div class="col"><div class="card border-0 shadow-sm h-100 li-kpi"><div class="card-body py-2 px-3"><div class="lbl d-flex justify-content-between gap-1"><span>' . li_e($label) . '</span>' . $tag . '</div>'
        . '<div class="val"' . ($color ? ' style="color:' . $color . '"' : '') . '>' . $value . '</div>' . ($hint ? '<div class="small text-muted">' . $hint . '</div>' : '') . '</div></div></div>';
}

/** CSV download of one table. */
function li_csv(string $file, array $head, array $rows, array $meta = []): void
{
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    $o = fopen('php://output', 'w');
    fwrite($o, "\xEF\xBB\xBF");
    foreach ($meta as $m) { fputcsv($o, $m); }
    if ($meta) { fputcsv($o, []); }
    fputcsv($o, $head);
    foreach ($rows as $r) { fputcsv($o, $r); }
    fclose($o);
    exit;
}

function li_users(mysqli $conn): array
{
    $o = [];
    foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT id, names FROM users ORDER BY names'), MYSQLI_ASSOC) as $u) { $o[(int) $u['id']] = $u['names']; }
    return $o;
}

function li_departments(mysqli $conn): array
{
    $o = [];
    foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT id, name FROM departments ORDER BY name'), MYSQLI_ASSOC) as $d) { $o[(int) $d['id']] = $d['name']; }
    return $o;
}

function li_branches(mysqli $conn): array
{
    if (!br_enabled($conn)) { return []; }
    $a = br_allowed_ids($conn);
    $o = [];
    foreach (br_all($conn) as $id => $b) { if ($a === null || in_array($id, $a, true)) { $o[$id] = $b['name']; } }
    return $o;
}