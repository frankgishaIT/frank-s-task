<?php
/** NEW FILE: modules/sms/sms_common.php — shared parts of SMS Management (tabs, helpers). */
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/sms_messages.php';
require_once __DIR__ . '/../../includes/sms_center.php';
if (is_file(__DIR__ . '/../../includes/lc_helpers.php')) { require_once __DIR__ . '/../../includes/lc_helpers.php'; }
sms_center_ensure($conn);

function smsm_e($v): string { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function smsm_can(string $p, mysqli $conn): bool { return access_can('sms.' . $p, $conn); }
function smsm_go(string $url, string $type, string $msg): void { header('Location: ' . $url . (strpos($url, '?') === false ? '?' : '&') . $type . '=' . urlencode($msg)); exit; }
function smsm_csrf(): string { if (empty($_SESSION['smsm_csrf'])) { $_SESSION['smsm_csrf'] = bin2hex(random_bytes(16)); } return '<input type="hidden" name="csrf" value="' . $_SESSION['smsm_csrf'] . '">'; }
function smsm_csrf_ok(): bool { return !empty($_SESSION['smsm_csrf']) && hash_equals($_SESSION['smsm_csrf'], (string) ($_POST['csrf'] ?? '')); }

function smsm_tabs(mysqli $conn, string $active, string $title): void
{
    $tabs = [['index.php', 'Notifications'], ['log.php', 'Delivery log'], ['usage.php', 'Usage & costs']];
    if (smsm_can('templates', $conn)) { $tabs[] = ['settings.php', 'Settings & audit']; }
    if (smsm_can('create', $conn)) { $tabs[] = ['sms_compose.php', 'Send SMS']; }
    if (smsm_can('test', $conn) || current_user_role() === 'Admin') { $tabs[] = ['sms_test.php', 'Test & daily task']; }
    echo '<div class="d-flex flex-wrap justify-content-between align-items-center mb-2"><h4 class="mb-0">' . smsm_e($title) . '</h4><span class="small text-muted">Sender ID <strong>' . smsm_e(PINDO_SENDER) . '</strong> · provider Pindo'
        . (SMS_ENABLED ? '' : ' · <span class="text-danger fw-semibold">SMS switched off in config/sms.php</span>') . '</span></div>';
    echo '<ul class="nav nav-tabs mb-3 small">';
    foreach ($tabs as [$h, $l]) { echo '<li class="nav-item"><a class="nav-link py-1' . ($h === $active ? ' active' : '') . '" href="' . $h . '">' . $l . '</a></li>'; }
    echo '</ul>';
    foreach (['success' => 'success', 'error' => 'danger'] as $k => $c) { if (!empty($_GET[$k])) { echo '<div class="alert alert-' . $c . ' py-2 small">' . smsm_e($_GET[$k]) . '</div>'; } }
}

/** Status badge. */
function smsm_badge(string $st): string
{
    $c = ['sent' => 'success', 'delivered' => 'success', 'queued' => 'info', 'failed' => 'danger', 'duplicate' => 'warning', 'filtered' => 'secondary', 'disabled' => 'secondary', 'skipped' => 'secondary', 'blocked' => 'dark', 'cancelled' => 'secondary'][$st] ?? 'light';
    return '<span class="badge bg-' . $c . ($c === 'warning' || $c === 'info' ? ' text-dark' : '') . '">' . smsm_e(ucfirst($st)) . '</span>';
}

function smsm_branches(mysqli $conn): array { try { return array_column(mysqli_fetch_all(mysqli_query($conn, "SELECT id, name FROM branches ORDER BY is_main DESC, name"), MYSQLI_ASSOC), 'name', 'id'); } catch (Throwable $e) { return []; } }
function smsm_roles(mysqli $conn): array { try { return array_column(mysqli_fetch_all(mysqli_query($conn, "SELECT DISTINCT role FROM users WHERE role IS NOT NULL AND role <> '' ORDER BY role")), 0); } catch (Throwable $e) { return []; } }
function smsm_courses(mysqli $conn): array { $o = []; try { foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT id, code, title FROM lc_courses ORDER BY code'), MYSQLI_ASSOC) as $r) { $o[(int) $r['id']] = $r['code'] . ' — ' . $r['title']; } } catch (Throwable $e) { } return $o; }

/** Statuses that can be chosen for a notification's status targeting. */
function smsm_statuses(string $event): array
{
    if ($event === 'learning_tpl_status' && defined('LC_APP_STATUSES')) { return LC_APP_STATUSES; }
    if ($event === 'learning_internship') { if (!defined('LC_INT_STATUSES') && is_file(__DIR__ . '/../../includes/lc_internship.php')) { require_once __DIR__ . '/../../includes/lc_internship.php'; } return defined('LC_INT_STATUSES') ? LC_INT_STATUSES : []; }
    return [];
}