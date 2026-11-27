<?php
/**
 * NEW FILE: modules/staff_cards/feed.php — the carousel's data for the Dashboard (no page reload).
 *   GET            → JSON {html, sec, n}: a fresh playlist (live stock, prices, approvals, dates)
 *   GET ?shown=KEY → records one display in the history (same person + item within 60 s counts once)
 */
require __DIR__ . '/common.php';
$key = (string) ($_GET['shown'] ?? '');
if ($key !== '') {
    if (!preg_match('/^(c|p|s)(\d+)$|^be(\d{6})_(\d+)$/', $key, $m)) { http_response_code(400); exit; }
    $uid = sdc_uid();
    $since = date('Y-m-d H:i:s', time() - 60);
    $s = mysqli_prepare($conn, 'SELECT 1 FROM sdc_history WHERE card_key = ? AND user_id = ? AND shown_at >= ? LIMIT 1');
    mysqli_stmt_bind_param($s, 'sis', $key, $uid, $since);
    mysqli_stmt_execute($s);
    if (!mysqli_fetch_row(mysqli_stmt_get_result($s))) {
        $cat = ''; $title = ''; $prio = '';
        if ($m[1] === 'c') {
            $c = sdc_card($conn, (int) $m[2]);
            if ($c) {
                $cat = $c['category']; $prio = $c['label'] . ' · P' . (int) $c['priority'];
                $title = $c['title'];
                if ($c['product_id'] && in_array($cat, ['product', 'service'], true)) { $r = mysqli_fetch_row(mysqli_query($conn, 'SELECT product_name FROM products WHERE id = ' . (int) $c['product_id'])); $title = $r[0] ?? $title; }
                if ($cat === 'employee') { $r = mysqli_fetch_row(mysqli_query($conn, 'SELECT names FROM users WHERE id = ' . (int) $c['user_id'])); $title = ($r[0] ?? '?') . ' — ' . $c['month']; }
                if ($cat === 'reminder') { $title = mb_substr((string) $c['body'], 0, 150); }
            }
        } elseif ($m[1] === 'p' || $m[1] === 's') {
            $r = mysqli_fetch_row(mysqli_query($conn, 'SELECT product_name FROM products WHERE id = ' . (int) $m[2]));
            if ($r) { $cat = $m[1] === 'p' ? 'product' : 'service'; $title = $r[0]; $prio = 'Normal · automatic'; }
        } else {
            $r = mysqli_fetch_row(mysqli_query($conn, 'SELECT names FROM users WHERE id = ' . (int) $m[4]));
            if ($r) { $cat = 'employee'; $title = $r[0] . ' — ' . substr($m[3], 0, 4) . '-' . substr($m[3], 4); $prio = 'Priority · KPI result'; }
        }
        if ($cat !== '') {
            $now = sdc_now();
            $title = mb_substr((string) $title, 0, 160);
            $i = mysqli_prepare($conn, 'INSERT INTO sdc_history (card_key, category, title, priority, user_id, shown_at) VALUES (?, ?, ?, ?, ?, ?)');
            mysqli_stmt_bind_param($i, 'ssssis', $key, $cat, $title, $prio, $uid, $now);
            mysqli_stmt_execute($i);
        }
    }
    http_response_code(204);
    exit;
}
$list = [];
try { $list = sdc_playlist($conn); } catch (Throwable $e) { error_log('sdc feed: ' . $e->getMessage()); }
header('Content-Type: application/json');
header('Cache-Control: no-store');
echo json_encode(['html' => $list ? sdc_slides_html($conn, $list, sdc_base()) : '', 'sec' => sdc_interval($conn), 'n' => count($list)]);