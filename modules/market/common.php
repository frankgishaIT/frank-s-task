<?php
/**
 * NEW FILE: modules/market/common.php — RM MARKET INSIGHTS, shared start of every page.
 * Permissions (Roles & Permissions → RM Market Insights):
 *   market.view      see demand information (dashboard, products and services requested)
 *   market.request   record customer requests and discovered products
 *   market.research  review opportunities, merge duplicates, link to RM Offerings, change review status (department managers)
 *   market.finance   see costs, investment and working capital
 *   market.approve   approve, reject, defer opportunities (management)
 *   market.reports   strategic reports
 *   market.settings  demand thresholds and alerts
 */
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/access_control.php';
require_once __DIR__ . '/../../includes/market_helpers.php';
require_once __DIR__ . '/../../includes/branch_helpers.php';
mi_ensure($conn);

function mic(mysqli $conn, string $a): bool { return access_can('market.' . $a, $conn); }
function mi_require(mysqli $conn, string $a): void { if (!mic($conn, $a)) { http_response_code(403); include __DIR__ . '/../../includes/access_denied.php'; exit; } }
function mi_e($v): string { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function mi_csrf_field(): string { if (empty($_SESSION['mi_csrf'])) { $_SESSION['mi_csrf'] = bin2hex(random_bytes(16)); } return '<input type="hidden" name="csrf" value="' . $_SESSION['mi_csrf'] . '">'; }
function mi_csrf_ok(): bool { return isset($_POST['csrf'], $_SESSION['mi_csrf']) && hash_equals($_SESSION['mi_csrf'], (string) $_POST['csrf']); }
function mi_go(string $url, string $type, string $msg): void { header('Location: ' . $url . (strpos($url, '?') === false ? '?' : '&') . $type . '=' . urlencode($msg)); exit; }

/** Requests this user may see: their branches (null = all). */
function mi_branch_sql(mysqli $conn, string $col = 'r.branch_id'): string
{
    if (!br_enabled($conn)) { return '1=1'; }
    $a = br_allowed_ids($conn);
    return $a === null ? '1=1' : 'COALESCE(' . $col . ', ' . (int) br_main_id($conn) . ') IN (' . implode(',', array_map('intval', $a ?: [0])) . ')';
}

/** Period filter: today, week, month, 3m, 6m, custom (from/to). Returns [from, to, key, label]. */
function mi_period(): array
{
    $p = (string) ($_GET['period'] ?? 'month'); $t = date('Y-m-d');
    $map = ['today' => [$t, 'Today'], 'week' => [date('Y-m-d', strtotime('monday this week')), 'This week'], 'month' => [date('Y-m-01'), 'This month'],
            '3m' => [date('Y-m-d', strtotime('-3 months')), 'Last 3 months'], '6m' => [date('Y-m-d', strtotime('-6 months')), 'Last 6 months'], 'all' => [null, 'All time']];
    if ($p === 'custom') {
        $f = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-01');
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : $t;
        return [$f, $to, 'custom', date('d M Y', strtotime($f)) . ' – ' . date('d M Y', strtotime($to))];
    }
    if (!isset($map[$p])) { $p = 'month'; }
    return [$map[$p][0], $map[$p][0] ? $t : null, $p, $map[$p][1]];
}

function mi_period_form(array $per, array $extra = []): string
{
    $o = '<form class="d-flex flex-wrap gap-1 align-items-center small mb-3">';
    foreach (['today' => 'Today', 'week' => 'This week', 'month' => 'This month', '3m' => '3 months', '6m' => '6 months', 'all' => 'All'] as $k => $l) {
        $o .= '<button name="period" value="' . $k . '" class="btn btn-sm py-0 ' . ($per[2] === $k ? 'btn-primary' : 'btn-light border') . '">' . $l . '</button>';
    }
    $o .= '<span class="ms-1">or</span><input type="date" name="from" value="' . mi_e($per[2] === 'custom' ? $per[0] : '') . '" class="form-control form-control-sm" style="width:auto"><input type="date" name="to" value="' . mi_e($per[2] === 'custom' ? $per[1] : '') . '" class="form-control form-control-sm" style="width:auto">'
        . '<button name="period" value="custom" class="btn btn-sm btn-outline-primary py-0">Apply</button>';
    foreach ($extra as $k => $v) { if ($v !== '' && $v !== null) { $o .= '<input type="hidden" name="' . mi_e($k) . '" value="' . mi_e($v) . '">'; } }
    return $o . '</form>';
}

function mi_tabs(mysqli $conn, string $active, string $title, string $right = ''): void
{
    $tabs = [['index.php', 'Dashboard', 'view'], ['request.php', 'Record a request', 'request'], ['requests.php', 'Requests', 'view'], ['opportunities.php', 'Demand analysis', 'view'],
             ['discover.php', 'New product discovery', 'request'], ['settings.php', 'Settings', 'settings']];
    echo '<style>.mi-tabs{display:flex;flex-wrap:nowrap;overflow-x:auto;gap:2px;border-bottom:1px solid #E4E7EF;margin-bottom:14px;scrollbar-width:none}.mi-tabs::-webkit-scrollbar{display:none}.mi-tabs a{padding:7px 12px;font-size:13.5px;color:#5B6275;text-decoration:none;border-bottom:2px solid transparent;white-space:nowrap}
        .mi-tabs a.on{color:#1E2FE0;border-bottom-color:#1E2FE0;font-weight:600}.mi-k .lbl{font-size:12px;color:#6B7280}.mi-k .val{font-weight:700;font-size:1.3rem;color:#0B1A8C}.mi-k .sub{font-size:11.5px;color:#8A90A2}
        .mi-pill{display:inline-block;font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px;white-space:nowrap}.mi-tbl td,.mi-tbl th{font-size:13px;vertical-align:middle}</style>';
    echo '<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-2"><div><h3 class="mb-0">' . mi_e($title) . '</h3><div class="small text-muted">RM Market Insights · Discover demand. Make smarter stock decisions.</div></div>' . $right . '</div>';
    echo '<div class="mi-tabs">';
    foreach ($tabs as [$f, $l, $p]) { if (mic($conn, $p)) { echo '<a href="' . $f . '" class="' . ($f === $active ? 'on' : '') . '">' . $l . '</a>'; } }
    echo '</div>';
    foreach (['success' => 'success', 'error' => 'danger'] as $k => $c) { if (!empty($_GET[$k])) { echo '<div class="alert alert-' . $c . ' py-2 small">' . mi_e($_GET[$k]) . '</div>'; } }
}

/** Badge for a demand level. */
function mi_level_badge(string $l): string
{
    $c = MI_DEMAND_LEVELS[$l] ?? 'light';
    return '<span class="badge bg-' . $c . ($c === 'warning' || $c === 'light' ? ' text-dark border' : '') . '">' . mi_e($l) . '</span>';
}

function mi_status_badge(string $s): string
{
    $c = ['New' => '#EEF3FF;color:#0B1A8C', 'Under Review' => '#FFF4D6;color:#6B4E00', 'More Information Required' => '#FFF4D6;color:#6B4E00', 'Recommended' => '#E7F7EF;color:#0A6B43',
          'Approved' => '#0FA968;color:#fff', 'Rejected' => '#FDECEC;color:#9B1C1C', 'Deferred' => '#F1F2F6;color:#5B6275', 'In Implementation' => '#E6F0FF;color:#1E2FE0',
          'Launched/Active' => '#1E2FE0;color:#fff', 'Completed' => '#E9ECF3;color:#1E2333'][$s] ?? '#F1F2F6;color:#5B6275';
    return '<span class="mi-pill" style="background:' . $c . '">' . mi_e($s) . '</span>';
}