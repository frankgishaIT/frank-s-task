<?php
/**
 * NEW FILE: modules/staff_cards/common.php — shared by the STAFF DASHBOARD CARDS MANAGEMENT pages (Task 4).
 * Permissions: staff_cards.manage (create / edit / publish / deactivate), staff_cards.approve (announcements and
 * Best Employee recognitions), staff_cards.settings (categories, rotation interval, stock display policy).
 */
if (session_status() === PHP_SESSION_NONE) { require_once __DIR__ . '/../../includes/session_boot.php'; rm_session_start(); }
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/access_control.php';
require_once __DIR__ . '/../../includes/sdc_helpers.php';
require_role(['Admin', 'Manager', 'Employee']);
sdc_ensure($conn);

function sdcc(mysqli $conn, string $a): bool { return access_can('staff_cards.' . $a, $conn); }
function sdc_any(mysqli $conn): bool { return sdcc($conn, 'manage') || sdcc($conn, 'approve') || sdcc($conn, 'settings'); }
function sdc_require(mysqli $conn, string $a): void
{
    if ($a === 'any' ? !sdc_any($conn) : !sdcc($conn, $a)) { http_response_code(403); include __DIR__ . '/../../includes/access_denied.php'; exit; }
}
function sdc_user_can(mysqli $conn, int $uid, string $perm): bool { try { return in_array($perm, access_user_breakdown($conn, $uid)['effective'], true); } catch (Throwable $e) { return false; } }
function sdc_csrf_field(): string { if (empty($_SESSION['sdc_csrf'])) { $_SESSION['sdc_csrf'] = bin2hex(random_bytes(16)); } return '<input type="hidden" name="csrf" value="' . $_SESSION['sdc_csrf'] . '">'; }
function sdc_csrf_ok(): bool { return isset($_POST['csrf'], $_SESSION['sdc_csrf']) && hash_equals($_SESSION['sdc_csrf'], (string) $_POST['csrf']); }
function sdc_go(string $url, string $type, string $msg): void { header('Location: ' . $url . (strpos($url, '?') === false ? '?' : '&') . $type . '=' . urlencode($msg)); exit; }
function sdc_base(): string { return rtrim((string) (defined('BASE_URL') ? BASE_URL : ''), '/'); }
function sdc_user_names(mysqli $conn): array { return array_column(mysqli_fetch_all(mysqli_query($conn, 'SELECT id, names FROM users'), MYSQLI_ASSOC), 'names', 'id'); }

function sdc_tabs(mysqli $conn, string $active, string $title, string $right = ''): void
{
    $pending = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM sdc_cards WHERE status = 'Pending'"))[0];
    $tabs = [['index.php', 'Cards', 'manage'], ['catalogue.php', 'Products & services', 'manage'], ['approvals.php', 'Approvals' . ($pending ? ' (' . $pending . ')' : ''), 'approve'],
        ['preview.php', 'Preview', 'any'], ['history.php', 'Display history', 'any'], ['settings.php', 'Settings', 'settings']];
    echo '<style>.sdc-tabs{display:flex;flex-wrap:nowrap;overflow-x:auto;gap:2px;border-bottom:1px solid #E4E7EF;margin-bottom:14px;scrollbar-width:none}.sdc-tabs::-webkit-scrollbar{display:none}.sdc-tabs a{padding:7px 12px;font-size:13.5px;color:#5B6275;text-decoration:none;border-bottom:2px solid transparent;white-space:nowrap}
        .sdc-tabs a.on{color:#1E2FE0;border-bottom-color:#1E2FE0;font-weight:600}.sdc-tbl td,.sdc-tbl th{font-size:13px;vertical-align:middle}.sdc-st{font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px;white-space:nowrap}
        .st-Published{background:#E1F7EE;color:#0B7A50}.st-Draft{background:#F1F2F6;color:#4B5263}.st-Pending{background:#FFF1E0;color:#A04D00}.st-Rejected{background:#FCEAEA;color:#B42318}.st-Inactive{background:#F1F2F6;color:#8A90A3}
        .lb-Featured{background:#FFF4D6;color:#8A5A00}.lb-Priority{background:#E8EDFD;color:#1E2FE0}.lb-Normal{background:#F1F2F6;color:#4B5263}.lb-Excluded{background:#1E2333;color:#fff}</style>';
    echo '<div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-2"><div><h3 class="mb-0">' . sdc_e($title) . '</h3><div class="small text-muted">Staff Dashboard Cards Management · the one rotating area on the staff Dashboard</div></div>' . $right . '</div>';
    echo '<div class="sdc-tabs">';
    foreach ($tabs as [$f, $l, $p]) { if ($p === 'any' ? sdc_any($conn) : sdcc($conn, $p)) { echo '<a href="' . $f . '" class="' . ($f === $active ? 'on' : '') . '">' . $l . '</a>'; } }
    echo '</div>';
    foreach (['success' => 'success', 'error' => 'danger'] as $k => $c) { if (!empty($_GET[$k])) { echo '<div class="alert alert-' . $c . ' py-2 small">' . sdc_e($_GET[$k]) . '</div>'; } }
}

function sdc_badge(array $c): string { return '<span class="sdc-st st-' . sdc_e($c['status']) . '">' . sdc_e(sdc_status_label($c)) . '</span>'; }
function sdc_label_badge(string $l): string { return '<span class="sdc-st lb-' . sdc_e($l) . '">' . sdc_e($l) . '</span>'; }

/** Status change with audit. $to: Published | Pending | Draft | Rejected | Inactive */
function sdc_set_status(mysqli $conn, array $c, string $to, ?string $reason = null): void
{
    $id = (int) $c['id'];
    $now = sdc_now();
    $u = sdc_uid();
    if ($to === 'Published' && in_array($c['category'], SDC_NEEDS_APPROVAL, true)) {
        $s = mysqli_prepare($conn, "UPDATE sdc_cards SET status = 'Published', approved_by = ?, approved_at = ?, decision_reason = ?, updated_by = ?, updated_at = ? WHERE id = ?");
        mysqli_stmt_bind_param($s, 'issisi', $u, $now, $reason, $u, $now, $id);
    } else {
        $s = mysqli_prepare($conn, 'UPDATE sdc_cards SET status = ?, decision_reason = COALESCE(?, decision_reason), updated_by = ?, updated_at = ? WHERE id = ?');
        mysqli_stmt_bind_param($s, 'ssisi', $to, $reason, $u, $now, $id);
    }
    mysqli_stmt_execute($s);
    $act = ['Published' => in_array($c['category'], SDC_NEEDS_APPROVAL, true) ? 'approve' : 'publish', 'Pending' => 'submit', 'Draft' => 'reopen', 'Rejected' => 'reject', 'Inactive' => 'deactivate'][$to] ?? $to;
    sdc_audit($conn, $id, $act, SDC_CATS[$c['category']][0] . ': ' . ($c['title'] ?: '#' . $id) . ' (' . $c['status'] . ' → ' . $to . ')', $reason);
}

/** In-app notice to the audience of an approved announcement (no e-mail flood). */
function sdc_notify_audience(mysqli $conn, array $c): int
{
    $n = 0;
    $users = mysqli_fetch_all(mysqli_query($conn, 'SELECT id, role, department_id FROM users WHERE is_active = 1'), MYSQLI_ASSOC);
    $t = 'Announcement: ' . mb_substr((string) $c['title'], 0, 120);
    $m = mb_substr((string) $c['body'], 0, 400) . ' (on your Dashboard' . ($c['start_date'] && $c['start_date'] > date('Y-m-d') ? ' from ' . date('d M Y', strtotime($c['start_date'])) : '') . ')';
    $s = mysqli_prepare($conn, 'INSERT INTO notifications (user_id, title, message) VALUES (?, ?, ?)');
    foreach ($users as $u) {
        if (!sdc_audience_ok((string) $c['audience'], (string) $u['role'], (int) $u['department_id'])) { continue; }
        $uid = (int) $u['id'];
        mysqli_stmt_bind_param($s, 'iss', $uid, $t, $m);
        mysqli_stmt_execute($s);
        $n++;
    }
    return $n;
}

/** Reads the form into a card row (also used by preview.php, without saving). Returns [row, error]. */
function sdc_form_card(mysqli $conn, string $cat, array $in, ?array $old): array
{
    $t = function ($k, $max = 255) use ($in) { return mb_substr(trim((string) ($in[$k] ?? '')), 0, $max); };
    $r = ['category' => $cat, 'title' => $t('title', 160), 'body' => $t('body', 2000), 'product_id' => (int) ($in['product_id'] ?? 0) ?: null, 'user_id' => (int) ($in['user_id'] ?? 0) ?: null,
        'label' => in_array($in['label'] ?? '', SDC_LABELS, true) ? $in['label'] : 'Normal', 'priority' => max(1, min(5, (int) ($in['priority'] ?? 3))),
        'season_months' => implode(',', array_filter(array_map('intval', (array) ($in['season'] ?? [])), function ($m) { return $m >= 1 && $m <= 12; })) ?: null,
        'audience' => 'all', 'month' => null, 'start_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($in['start_date'] ?? '')) ? $in['start_date'] : null,
        'end_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($in['end_date'] ?? '')) ? $in['end_date'] : null, 'media_url' => null, 'show_photo' => empty($in['show_photo']) ? 0 : 1,
        'image_ref' => $old['image_ref'] ?? null, 'd' => []];
    if (!empty($in['image_pick']) && preg_match('/^db:\d+$/', (string) $in['image_pick'])) { $r['image_ref'] = $in['image_pick']; }
    if (!empty($in['image_remove'])) { $r['image_ref'] = null; }
    $err = '';
    $prod = $r['product_id'] ? mysqli_fetch_assoc(mysqli_query($conn, 'SELECT id, item_type, is_active, product_name FROM products WHERE id = ' . (int) $r['product_id'])) : null;
    switch ($cat) {
        case 'reminder':
            if ($r['title'] === '') { $r['title'] = 'Customer First'; }
            if (mb_strlen($r['body']) < 10) { $err = 'Write the reminder (at least 10 characters).'; }
            elseif (mb_strlen($r['body']) > 300) { $err = 'Keep a reminder short: 300 characters at most.'; }
            break;
        case 'product':
            $r['d'] = ['uses' => $t('uses', 600), 'suitable' => $t('suitable', 400), 'tip' => $t('tip', 400), 'complements' => array_values(array_unique(array_filter(array_map('intval', (array) ($in['complements'] ?? [])), function ($x) use ($r) { return $x !== (int) $r['product_id']; })))];
            if (!$prod || $prod['item_type'] !== 'Item') { $err = 'Choose the product from the stock records.'; }
            else { $r['title'] = $prod['product_name']; }
            break;
        case 'education':
            foreach (SDC_EDU_FIELDS as $k => $l) { $r['d'][$k] = $t($k, 600); }
            if ($r['title'] === '') { $err = 'Give the lesson a title (for example "Choosing the right paper").'; }
            elseif (!array_filter($r['d']) && $r['body'] === '') { $err = 'Answer at least one of the questions.'; }
            elseif ($r['product_id'] && !$prod) { $err = 'That product no longer exists.'; }
            break;
        case 'service':
            $r['d'] = ['description' => $t('description', 600), 'requirements' => $t('requirements', 600), 'documents' => $t('documents', 400), 'price_note' => $t('price_note', 300), 'assist' => $t('assist', 600)];
            if (!$prod || $prod['item_type'] !== 'Service') { $err = 'Choose the service from RM Offerings.'; }
            else { $r['title'] = $prod['product_name']; if (!(int) $prod['is_active']) { $err = 'This service is not active in RM Offerings: only active services can be shown.'; } }
            break;
        case 'employee':
            $r['month'] = preg_match('/^\d{4}-\d{2}$/', (string) ($in['month'] ?? '')) ? $in['month'] : null;
            $r['d'] = ['source' => ($in['source'] ?? '') === 'kpi' ? 'kpi' : 'decision', 'reference' => $t('reference', 200)];
            if ($r['title'] === '') { $r['title'] = 'Best Employee of the Month'; }
            if (!$r['user_id']) { $err = 'Choose the employee.'; }
            elseif (!$r['month'] || $r['month'] > date('Y-m')) { $err = 'Choose the month of the recognition (not a future month).'; }
            elseif (mb_strlen($r['body']) < 15) { $err = 'Give the factual reason (approved decision or verified performance), at least 15 characters.'; }
            elseif ($r['d']['source'] === 'decision' && $r['d']['reference'] === '') { $err = 'Say which management decision this is based on (meeting, date or reference).'; }
            break;
        case 'announcement':
            $aud = (string) ($in['audience'] ?? 'all');
            $r['audience'] = preg_match('/^(all|role:(Admin|Manager|Employee)|dept:\d+)$/', $aud) ? $aud : 'all';
            $m = $t('media_url', 255);
            if ($m !== '' && !preg_match('#^https?://#i', $m)) { $err = 'The media link must start with http:// or https://'; }
            $r['media_url'] = $m ?: null;
            if ($r['title'] === '') { $err = 'Give the announcement a title.'; }
            elseif (mb_strlen($r['body']) < 5) { $err = 'Write the message.'; }
            elseif (!$r['start_date'] || !$r['end_date']) { $err = 'Set the start and end dates.'; }
            break;
    }
    if (!$err && $r['start_date'] && $r['end_date'] && $r['end_date'] < $r['start_date']) { $err = 'The end date is before the start date.'; }
    return [$r, $err];
}