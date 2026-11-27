<?php
/**
 * NEW FILE: includes/sdc_helpers.php — SMART STAFF REMINDER & PRODUCT SHOWCASE CAROUSEL (Task 4).
 *
 * ONE shared rotating area on the staff Dashboard. Six categories rotate inside it:
 *   reminder (Customer First reminders), product (Product Showcase), education (Product Education & Sales Advice),
 *   service (Service Showcase), employee (Best Employee of the Month), announcement (Approved Management Announcements).
 *
 * Live data, never copied: product name, category, stock, unit and price come from `products` at display time;
 * services are `products` rows of type Service that are active (RM Offerings — no separate service catalogue);
 * employee name, photo and department from `users`; the automatic Best Employee from the approved KPI results
 * (includes/best_employee.php). A card (sdc_cards) only adds what MY MOTIVE does not hold: uses, sales tips,
 * requirements, recognition reason, announcement text, an image.
 *
 * Statuses: Draft → Published. Announcements and Best Employee entries need approval: Draft → Pending → Published
 * (shown as "Approved") or Rejected. Deactivated content is Inactive ("Withdrawn" for announcements).
 * Labels: Featured, Priority, Normal, Excluded. Excluded content never appears (also not automatically).
 *
 * Permissions (Roles & Permissions, module staff_cards): manage (create / edit / publish / deactivate cards),
 * approve (approve announcements and recognitions), settings (categories, rotation interval, stock policy).
 * Everyone signed in sees the published carousel. Every change is in sdc_audit (who, when, what, reason).
 */
require_once __DIR__ . '/evidence_store.php';

const SDC_CATS = [
    'reminder'     => ['Customer First Reminder', 'bi-heart-pulse', '#1E2FE0'],
    'product'      => ['Product Showcase', 'bi-box-seam', '#0E9F6E'],
    'education'    => ['Product Education & Sales Advice', 'bi-mortarboard', '#7C5CE0'],
    'service'      => ['Service Showcase', 'bi-tools', '#159AA8'],
    'employee'     => ['Best Employee of the Month', 'bi-trophy', '#C27803'],
    'announcement' => ['Management Announcement', 'bi-megaphone', '#E24B4A'],
];
const SDC_LABELS = ['Featured', 'Priority', 'Normal', 'Excluded'];
const SDC_NEEDS_APPROVAL = ['employee', 'announcement'];
const SDC_SETTING_DEFAULTS = [
    'rotation_seconds' => '120', 'stock_policy' => 'exclude', 'auto_products' => '1', 'auto_services' => '1', 'auto_employee' => '1',
    'employee_photos' => '1', 'playlist_size' => '12',
    'cat_reminder' => '1', 'cat_product' => '1', 'cat_education' => '1', 'cat_service' => '1', 'cat_employee' => '1', 'cat_announcement' => '1',
];
const SDC_EDU_FIELDS = ['problem' => 'What problem does it solve?', 'who' => 'Who is most likely to need it?', 'explain' => 'Explain before the customer buys',
    'together' => 'Products that can be used together', 'mistakes' => 'Common mistakes to avoid', 'budget' => 'Helping customers choose by budget'];
const SDC_DEFAULT_REMINDERS = [
    "Understand the customer's needs before recommending a product.",
    'Always check available stock before confirming availability.',
    'If you cannot help a customer, check whether another colleague can.',
    'Explore alternatives before turning away a customer.',
    'Explain prices and requirements clearly.',
    'Never promise a delivery time you cannot meet.',
    'Treat every customer respectfully.',
    'Follow up on pending customer requests.',
];

function sdc_e($v): string { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function sdc_uid(): int { return (int) ($_SESSION['user_id'] ?? 0); }
function sdc_now(): string { return date('Y-m-d H:i:s'); }

function sdc_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sdc_cards (
        id INT(11) NOT NULL AUTO_INCREMENT, category VARCHAR(20) NOT NULL, product_id INT(11) NULL, user_id INT(11) NULL,
        title VARCHAR(160) NULL, body TEXT NULL, details TEXT NULL, image_ref VARCHAR(40) NULL, media_url VARCHAR(255) NULL,
        label VARCHAR(10) NOT NULL DEFAULT 'Normal', priority TINYINT NOT NULL DEFAULT 3, season_months VARCHAR(40) NULL, audience VARCHAR(40) NOT NULL DEFAULT 'all',
        month CHAR(7) NULL, start_date DATE NULL, end_date DATE NULL, status VARCHAR(12) NOT NULL DEFAULT 'Draft', show_photo TINYINT NOT NULL DEFAULT 1,
        decision_reason VARCHAR(255) NULL, created_by INT(11) NULL, created_at DATETIME NULL, updated_by INT(11) NULL, updated_at DATETIME NULL,
        approved_by INT(11) NULL, approved_at DATETIME NULL,
        PRIMARY KEY (id), KEY cat_status (category, status), KEY product (product_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn, 'CREATE TABLE IF NOT EXISTS sdc_settings (k VARCHAR(40) NOT NULL, v VARCHAR(255) NULL, PRIMARY KEY (k)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sdc_history (
        id INT(11) NOT NULL AUTO_INCREMENT, card_key VARCHAR(40) NOT NULL, category VARCHAR(20) NOT NULL, title VARCHAR(160) NULL, priority VARCHAR(30) NULL,
        user_id INT(11) NULL, shown_at DATETIME NOT NULL, PRIMARY KEY (id), KEY card (card_key, shown_at), KEY shown (shown_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS sdc_audit (
        id INT(11) NOT NULL AUTO_INCREMENT, card_id INT(11) NULL, action VARCHAR(40) NOT NULL, detail TEXT NULL, reason VARCHAR(255) NULL,
        user_id INT(11) NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id), KEY card (card_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // The example reminders of the specification, once (management can edit, deactivate or delete them).
    if (sdc_setting($conn, 'seeded') === null) {
        sdc_set($conn, 'seeded', '1');
        $now = sdc_now();
        $s = mysqli_prepare($conn, "INSERT INTO sdc_cards (category, title, body, status, created_at, updated_at) VALUES ('reminder', 'Customer First', ?, 'Published', ?, ?)");
        foreach (SDC_DEFAULT_REMINDERS as $r) { mysqli_stmt_bind_param($s, 'sss', $r, $now, $now); mysqli_stmt_execute($s); }
        sdc_audit($conn, null, 'seed', count(SDC_DEFAULT_REMINDERS) . ' standard Customer First reminders added', null, 0);
    }
}

function sdc_setting(mysqli $conn, string $k): ?string
{
    $s = mysqli_prepare($conn, 'SELECT v FROM sdc_settings WHERE k = ?');
    mysqli_stmt_bind_param($s, 's', $k);
    mysqli_stmt_execute($s);
    $r = mysqli_fetch_row(mysqli_stmt_get_result($s));
    return $r ? (string) $r[0] : null;
}
function sdc_get(mysqli $conn, string $k): string { return sdc_setting($conn, $k) ?? (SDC_SETTING_DEFAULTS[$k] ?? ''); }
function sdc_set(mysqli $conn, string $k, string $v): void
{
    $s = mysqli_prepare($conn, 'INSERT INTO sdc_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
    mysqli_stmt_bind_param($s, 'ss', $k, $v);
    mysqli_stmt_execute($s);
}
function sdc_interval(mysqli $conn): int { return max(10, min(3600, (int) sdc_get($conn, 'rotation_seconds') ?: 120)); }

function sdc_audit(mysqli $conn, ?int $cardId, string $action, string $detail, ?string $reason = null, ?int $uid = null): void
{
    $u = $uid ?? sdc_uid();
    $now = sdc_now();
    $s = mysqli_prepare($conn, 'INSERT INTO sdc_audit (card_id, action, detail, reason, user_id, created_at) VALUES (?, ?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($s, 'isssis', $cardId, $action, $detail, $reason, $u, $now);
    mysqli_stmt_execute($s);
}

function sdc_card(mysqli $conn, int $id): ?array
{
    $r = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM sdc_cards WHERE id = ' . $id));
    if (!$r) { return null; }
    $r['d'] = json_decode((string) $r['details'], true) ?: [];
    return $r;
}

/** Status as people read it: announcements / recognitions are "Approved" when live, a withdrawn announcement "Withdrawn". */
function sdc_status_label(array $c): string
{
    $s = $c['status'];
    if ($s === 'Published' && in_array($c['category'], SDC_NEEDS_APPROVAL, true)) { return 'Approved'; }
    if ($s === 'Pending') { return 'Waiting for approval'; }
    if ($s === 'Inactive' && $c['category'] === 'announcement') { return 'Withdrawn'; }
    return $s;
}

function sdc_products(mysqli $conn, ?string $type = null): array
{
    $w = $type ? " WHERE item_type = '" . ($type === 'Service' ? 'Service' : 'Item') . "'" : '';
    $rows = [];
    foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT id, product_name, product_code, item_type, category, quantity, reorder_level, selling_price, unit, description, is_active FROM products' . $w . ' ORDER BY product_name'), MYSQLI_ASSOC) as $p) { $rows[(int) $p['id']] = $p; }
    return $rows;
}

function sdc_low_level(array $p): float { $r = (float) ($p['reorder_level'] ?? 0); return $r > 0 ? $r : (defined('STOCK_DEFAULT_REORDER_LEVEL') ? (float) STOCK_DEFAULT_REORDER_LEVEL : 5.0); }

/** Products launched from RM Market Insights in the last 60 days (Task 1 → Task 4: "once available, it can appear in the carousel"). */
function sdc_from_demand(mysqli $conn): array
{
    try {
        $since = date('Y-m-d H:i:s', strtotime('-60 days'));
        return array_map('intval', array_column(mysqli_fetch_all(mysqli_query($conn, "SELECT product_id FROM mi_opportunities WHERE product_id IS NOT NULL AND launched_at IS NOT NULL AND launched_at >= '$since'"), MYSQLI_NUM), 0));
    } catch (Throwable $e) { return []; }
}

/** Does this announcement address the user? audience: all | role:Employee | dept:<id> */
function sdc_audience_ok(string $aud, string $role, int $dept): bool
{
    if ($aud === '' || $aud === 'all') { return true; }
    [$k, $v] = array_pad(explode(':', $aud, 2), 2, '');
    if ($k === 'role') { return strcasecmp($v, $role) === 0; }
    if ($k === 'dept') { return (int) $v === $dept; }
    return false;
}
function sdc_audience_text(mysqli $conn, string $aud): string
{
    if ($aud === '' || $aud === 'all') { return 'All staff'; }
    [$k, $v] = array_pad(explode(':', $aud, 2), 2, '');
    if ($k === 'role') { return $v . 's'; }
    if ($k === 'dept') { $r = mysqli_fetch_row(mysqli_query($conn, 'SELECT name FROM departments WHERE id = ' . (int) $v)); return 'Department: ' . ($r[0] ?? '#' . (int) $v); }
    return $aud;
}

/** Recognition months that may still be shown: last month and this month. */
function sdc_recognition_months(): array { return [date('Y-m', strtotime(date('Y-m-01') . ' -1 month')), date('Y-m')]; }

/**
 * Every item that may appear now for this user, with its selection score.
 * Each: ['key', 'cat', 'title', 'label', 'priority', 'score', 'card' => sdc_cards row|null, 'p' => product|null, 'u' => user|null, 'flags' => [...]].
 * $onlyCard: preview a single (even unpublished) card.
 */
function sdc_pool(mysqli $conn, ?array $viewer = null, ?array $onlyCard = null): array
{
    sdc_ensure($conn);
    $today = date('Y-m-d');
    $role = (string) ($viewer['role'] ?? ($_SESSION['user_role'] ?? ''));
    $dept = (int) ($viewer['department_id'] ?? 0);
    if (!$viewer && sdc_uid()) { $r = mysqli_fetch_row(mysqli_query($conn, 'SELECT department_id FROM users WHERE id = ' . sdc_uid())); $dept = (int) ($r[0] ?? 0); }
    $on = function ($c) use ($conn, $onlyCard) { return $onlyCard ? true : sdc_get($conn, 'cat_' . $c) === '1'; };
    $policy = sdc_get($conn, 'stock_policy');
    $products = sdc_products($conn);
    $cards = $onlyCard ? [$onlyCard] : array_map(function ($r) { $r['d'] = json_decode((string) $r['details'], true) ?: []; return $r; },
        mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM sdc_cards WHERE status <> 'Rejected'"), MYSQLI_ASSOC));

    // Display history: last time and count per item (smart rotation: what was shown recently waits).
    $hist = [];
    $since = date('Y-m-d H:i:s', strtotime('-30 days'));
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT card_key, MAX(shown_at) last_at, COUNT(*) n FROM sdc_history WHERE shown_at >= '$since' GROUP BY card_key"), MYSQLI_ASSOC) as $h) { $hist[$h['card_key']] = $h; }
    $month = (int) date('n');
    $demand = sdc_from_demand($conn);

    $excludedProducts = [];
    $carded = [];
    foreach ($cards as $c) {
        if ($c['product_id'] && in_array($c['category'], ['product', 'service'], true)) {
            if ($c['label'] === 'Excluded' && $c['status'] !== 'Inactive') { $excludedProducts[(int) $c['product_id']] = true; }
            if ($c['status'] === 'Published') { $carded[(int) $c['product_id']] = true; } // a draft does not hide the automatic card
        }
    }

    $items = [];
    $add = function (array $it) use (&$items, $hist, $month) {
        $w = ['Featured' => 30, 'Priority' => 20, 'Normal' => 10][$it['label']] ?? 10;
        $s = $w + 4 * (int) $it['priority'];
        $months = array_filter(array_map('intval', explode(',', (string) ($it['card']['season_months'] ?? ''))));
        if ($months && in_array($month, $months, true)) { $s += 8; $it['flags'][] = 'season'; }
        $s += $it['bonus'] ?? 0;
        $h = $hist[$it['key']] ?? null;
        $s += $h ? min(48, (time() - strtotime($h['last_at'])) / 3600) / 4 - min(10, (int) $h['n'] / 20) : 12;
        $it['score'] = round($s, 2);
        $it['shown'] = $h ? (int) $h['n'] : 0;
        $items[] = $it;
    };

    foreach ($cards as $c) {
        $cat = $c['category'];
        if (!isset(SDC_CATS[$cat]) || !$on($cat)) { continue; }
        if (!$onlyCard) {
            if ($c['status'] !== 'Published' || $c['label'] === 'Excluded') { continue; }
            if ($c['start_date'] && $c['start_date'] > $today) { continue; }
            if ($c['end_date'] && $c['end_date'] < $today) { continue; }
        }
        $it = ['key' => 'c' . (int) $c['id'], 'cat' => $cat, 'title' => (string) $c['title'], 'label' => $c['label'], 'priority' => (int) $c['priority'], 'card' => $c, 'p' => null, 'u' => null, 'flags' => [], 'bonus' => 0];
        if ($cat === 'product' || $cat === 'service' || ($cat === 'education' && $c['product_id'])) {
            $p = $products[(int) $c['product_id']] ?? null;
            if (!$p || !(int) $p['is_active']) { if (!$onlyCard) { continue; } }
            if ($p && $cat === 'service' && $p['item_type'] !== 'Service' && !$onlyCard) { continue; }
            if ($p && $cat === 'product' && $p['item_type'] !== 'Item' && !$onlyCard) { continue; }
            $it['p'] = $p;
            $it['title'] = $p['product_name'] ?? $it['title'];
            if ($p && $p['item_type'] === 'Item') {
                $q = (float) $p['quantity'];
                if ($q <= 0) {
                    $it['flags'][] = 'out';
                    if ($cat === 'product' && $policy === 'exclude' && !$onlyCard) { continue; }
                    $it['bonus'] -= 15;
                } else {
                    $it['bonus'] += min(3, $q / max(1, sdc_low_level($p))) * 2;
                    if ($q <= sdc_low_level($p)) { $it['flags'][] = 'low'; }
                }
                // Complementary products in stock: a cross-selling opportunity.
                $comp = array_filter((array) ($c['d']['complements'] ?? []), function ($id) use ($products) { $x = $products[(int) $id] ?? null; return $x && (int) $x['is_active'] && ($x['item_type'] === 'Service' || (float) $x['quantity'] > 0); });
                if ($comp) { $it['bonus'] += 3; }
                if (in_array((int) $p['id'], $demand, true)) { $it['bonus'] += 6; $it['flags'][] = 'demand'; }
            }
        }
        if ($cat === 'employee') {
            if (!$onlyCard && !in_array((string) $c['month'], sdc_recognition_months(), true)) { continue; }
            $u = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT u.id, u.names, u.photo, u.is_active, d.name department FROM users u LEFT JOIN departments d ON d.id = u.department_id WHERE u.id = ' . (int) $c['user_id']));
            if (!$u || (!(int) $u['is_active'] && !$onlyCard)) { continue; }
            $it['u'] = $u;
            $it['title'] = $u['names'];
            $it['bonus'] += 5;
        }
        if ($cat === 'announcement') {
            if (!$onlyCard && !sdc_audience_ok((string) $c['audience'], $role, $dept)) { continue; }
            $it['bonus'] += 10;
        }
        $add($it);
    }

    // Automatic products: active items in stock that have no card of their own.
    if ($on('product') && !$onlyCard && sdc_get($conn, 'auto_products') === '1') {
        foreach ($products as $id => $p) {
            if ($p['item_type'] !== 'Item' || !(int) $p['is_active'] || (float) $p['quantity'] <= 0 || isset($carded[$id]) || isset($excludedProducts[$id])) { continue; }
            $flags = (float) $p['quantity'] <= sdc_low_level($p) ? ['low'] : [];
            $b = min(3, (float) $p['quantity'] / max(1, sdc_low_level($p))) * 2 - 6; // configured cards come first
            if (in_array($id, $demand, true)) { $b += 12; $flags[] = 'demand'; }
            $add(['key' => 'p' . $id, 'cat' => 'product', 'title' => $p['product_name'], 'label' => 'Normal', 'priority' => 3, 'card' => null, 'p' => $p, 'u' => null, 'flags' => $flags, 'bonus' => $b]);
        }
    }
    // Automatic services: every active service of RM Offerings without a card of its own.
    if ($on('service') && !$onlyCard && sdc_get($conn, 'auto_services') === '1') {
        foreach ($products as $id => $p) {
            if ($p['item_type'] !== 'Service' || !(int) $p['is_active'] || isset($carded[$id]) || isset($excludedProducts[$id])) { continue; }
            $add(['key' => 's' . $id, 'cat' => 'service', 'title' => $p['product_name'], 'label' => 'Normal', 'priority' => 3, 'card' => null, 'p' => $p, 'u' => null, 'flags' => [], 'bonus' => -6]);
        }
    }
    // Automatic Best Employee: the FINAL result of the last completed month from the approved KPI system, unless management
    // already published a recognition for that month or excluded it.
    if ($on('employee') && !$onlyCard && sdc_get($conn, 'auto_employee') === '1') {
        try {
            require_once __DIR__ . '/best_employee.php';
            $cur = be_current($conn);
            if ($cur && $cur['final']) {
                $taken = false;
                foreach ($cards as $c) { if ($c['category'] === 'employee' && $c['month'] === $cur['month'] && ($c['status'] === 'Published' || $c['label'] === 'Excluded')) { $taken = true; } }
                if (!$taken) {
                    foreach ($cur['winners'] as $w) {
                        $u = ['id' => $w['user_id'], 'names' => $w['name'], 'photo' => $w['photo'], 'department' => $w['department']];
                        $reason = 'Highest weighted KPI achievement in ' . date('F Y', strtotime($cur['month'] . '-01')) . ': ' . rtrim(rtrim(number_format((float) $w['score'], 1), '0'), '.') . '% · ' . (int) $w['met'] . ' of ' . (int) $w['measured'] . ' KPIs on target (approved KPI results).';
                        $add(['key' => 'be' . str_replace('-', '', $cur['month']) . '_' . (int) $w['user_id'], 'cat' => 'employee', 'title' => $w['name'], 'label' => 'Priority', 'priority' => 3,
                            'card' => ['category' => 'employee', 'title' => 'Best Employee of the Month', 'body' => $reason, 'month' => $cur['month'], 'show_photo' => 1, 'image_ref' => null, 'd' => ['source' => 'kpi']],
                            'p' => null, 'u' => $u, 'flags' => ['kpi'], 'bonus' => 5]);
                    }
                }
            }
        } catch (Throwable $e) { error_log('sdc best employee: ' . $e->getMessage()); }
    }
    return $items;
}

/**
 * The playlist: categories take turns (no category repeats while another has something to show), the best-scoring
 * item of each first; Featured items and announcements lead. Size = setting playlist_size.
 */
function sdc_playlist(mysqli $conn, ?array $viewer = null): array
{
    $pool = sdc_pool($conn, $viewer);
    $by = [];
    foreach ($pool as $it) { $by[$it['cat']][] = $it; }
    foreach ($by as &$list) { usort($list, function ($a, $b) { return $b['score'] <=> $a['score']; }); }
    unset($list);
    $order = array_keys(SDC_CATS);
    // The category whose best item scores highest starts.
    usort($order, function ($a, $b) use ($by) { return ($by[$b][0]['score'] ?? -999) <=> ($by[$a][0]['score'] ?? -999); });
    $size = max(4, min(40, (int) sdc_get($conn, 'playlist_size') ?: 12));
    $out = [];
    while (count($out) < $size) {
        $added = false;
        foreach ($order as $c) {
            if (!empty($by[$c])) { $out[] = array_shift($by[$c]); $added = true; if (count($out) >= $size) { break; } }
        }
        if (!$added) { break; }
    }
    return $out;
}

function sdc_media_url(?string $ref, string $base): string { return $ref ? $base . '/modules/staff_cards/media.php?ref=' . rawurlencode($ref) : ''; }
function sdc_photo_url(?string $photo, string $base): string { return $photo ? $base . '/assets/uploads/users/' . rawurlencode(basename($photo)) : ''; }

/** One card as HTML (the carousel, the preview and the admin list all use this). */
function sdc_card_html(mysqli $conn, array $it, string $base, bool $showPhotos = true): string
{
    $e = 'sdc_e';
    [$catName, $icon, $col] = SDC_CATS[$it['cat']];
    $c = $it['card'] ?? [];
    $d = $c['d'] ?? [];
    $p = $it['p'];
    $img = sdc_media_url($c['image_ref'] ?? null, $base);
    if ($it['cat'] === 'employee' && !$img && $showPhotos && (int) ($c['show_photo'] ?? 1) && !empty($it['u']['photo'])) { $img = sdc_photo_url($it['u']['photo'], $base); }
    $chips = '<span class="sdc-cat" style="color:' . $col . '"><i class="bi ' . $icon . '"></i> ' . $e($catName) . '</span>';
    if (($it['label'] ?? '') === 'Featured') { $chips .= '<span class="sdc-chip sdc-feat">Featured</span>'; }
    if (in_array('demand', $it['flags'], true)) { $chips .= '<span class="sdc-chip sdc-new">New · requested by customers</span>'; }
    if (in_array('season', $it['flags'], true)) { $chips .= '<span class="sdc-chip">In season</span>'; }
    $rows = [];
    $row = function ($label, $val) use (&$rows, $e) { if (trim((string) $val) !== '') { $rows[] = '<div class="sdc-row"><b>' . $e($label) . ':</b> ' . nl2br($e($val)) . '</div>'; } };
    $money = function ($v) { return 'RWF ' . number_format((float) $v); };
    $title = $it['title'];
    $sub = '';
    $big = '';

    switch ($it['cat']) {
        case 'reminder':
            $title = $c['title'] ?: 'Customer First';
            $big = '<div class="sdc-quote">' . $e($c['body']) . '</div>';
            break;
        case 'product':
            if (!$p) { $sub = 'Choose a product from the stock records.'; break; }
            $q = (float) ($p['quantity'] ?? 0);
            $unit = $p['unit'] ?: 'units';
            $stock = $q > 0 ? '<span class="sdc-chip ' . (in_array('low', $it['flags'], true) ? 'sdc-low' : 'sdc-ok') . '">' . $e(rtrim(rtrim(number_format($q, 2), '0'), '.') . ' ' . $unit) . ' available' . (in_array('low', $it['flags'], true) ? ' · low' : '') . '</span>'
                             : '<span class="sdc-chip sdc-out">Out of stock</span>';
            $sub = $e($p['category'] ?: 'No category') . ' &middot; ' . $stock . ' <span class="sdc-price">' . ((float) $p['selling_price'] > 0 ? $money($p['selling_price']) . ' / ' . $e($p['unit'] ?: 'unit') : 'Price not set') . '</span>';
            $row('Uses & benefits', ($d['uses'] ?? '') ?: ($c ? '' : (string) $p['description']));
            $row('Suitable for', $d['suitable'] ?? '');
            $comp = [];
            foreach ((array) ($d['complements'] ?? []) as $cid) {
                $x = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT product_name, item_type, quantity, is_active FROM products WHERE id = ' . (int) $cid));
                if ($x && (int) $x['is_active']) { $comp[] = $x['product_name'] . ($x['item_type'] === 'Item' && (float) $x['quantity'] <= 0 ? ' (out of stock)' : ''); }
            }
            if (!$comp) { try { require_once __DIR__ . '/market_ops.php'; } catch (Throwable $ex) {} }
            if (!$comp && function_exists('mi_bought_together')) {
                foreach (mi_bought_together($conn, (int) $p['id'], 3) as $b) { $comp[] = $b['product_name']; }
                if ($comp) { $row('Often bought with it', implode(', ', $comp)); $comp = []; }
            }
            $row('Goes well with', implode(', ', $comp));
            $row('Sales tip', $d['tip'] ?? '');
            if (!$rows) { $rows[] = '<div class="sdc-row text-muted">Ask the customer what they need it for, then check the quantity that fits.</div>'; }
            break;
        case 'education':
            $title = $c['title'] ?: ($p['product_name'] ?? 'Product knowledge');
            if ($p) { $sub = 'About: ' . $e($p['product_name']) . ($p['item_type'] === 'Item' ? ' &middot; ' . ((float) $p['quantity'] > 0 ? 'in stock' : 'out of stock') : ''); }
            foreach (SDC_EDU_FIELDS as $k => $l) { $row($l, $d[$k] ?? ''); }
            if (trim((string) ($c['body'] ?? '')) !== '') { $big = '<div class="sdc-text">' . nl2br($e($c['body'])) . '</div>'; }
            break;
        case 'service':
            if (!$p) { $sub = 'Choose a service from RM Offerings.'; break; }
            $price = (float) ($p['selling_price'] ?? 0);
            $sub = $e($p['category'] ?: 'RM Offerings') . ' &middot; <span class="sdc-price">' . ($price > 0 ? 'Price guide: from ' . $money($price) : 'Quotation required') . '</span>';
            $row('About', ($d['description'] ?? '') ?: (string) ($p['description'] ?? ''));
            $row('Customer requirements', $d['requirements'] ?? '');
            $row('Documents needed', $d['documents'] ?? '');
            $row('Price / quotation', $d['price_note'] ?? '');
            $row('How you can help', ($d['assist'] ?? '') ?: ($c ? '' : 'Explain the service, check who can deliver it and when, then record the customer\'s request.'));
            break;
        case 'employee':
            $u = $it['u'];
            $title = $u['names'];
            $sub = $e($c['title'] ?: 'Best Employee of the Month') . ' &middot; ' . $e(date('F Y', strtotime(($c['month'] ?: date('Y-m')) . '-01'))) . (!empty($u['department']) ? ' &middot; ' . $e($u['department']) : '');
            $big = '<div class="sdc-text">' . nl2br($e($c['body'])) . '</div>';
            if (in_array('kpi', $it['flags'], true)) { $rows[] = '<div class="sdc-row text-muted small">Chosen from approved KPI results.</div>'; }
            if ((int) ($_SESSION['user_id'] ?? 0) === (int) $u['id']) { $rows[] = '<div class="sdc-row" style="color:#C27803;font-weight:600">Congratulations, this is you!</div>'; }
            break;
        case 'announcement':
            $title = $c['title'];
            $pr = (int) $c['priority'];
            if ($pr >= 5) { $chips .= '<span class="sdc-chip sdc-out">Urgent</span>'; } elseif ($pr >= 4) { $chips .= '<span class="sdc-chip sdc-low">Important</span>'; }
            $sub = ($c['end_date'] ? 'Until ' . $e(date('d M Y', strtotime($c['end_date']))) : '');
            $big = '<div class="sdc-text">' . nl2br($e($c['body'])) . '</div>';
            if (!empty($c['media_url']) && preg_match('#^https?://#i', $c['media_url'])) { $rows[] = '<div class="sdc-row"><a href="' . $e($c['media_url']) . '" target="_blank" rel="noopener"><i class="bi bi-play-circle"></i> Open the attached media</a></div>'; }
            break;
    }
    if (in_array('out', $it['flags'], true) && $it['cat'] !== 'product') { $chips .= '<span class="sdc-chip sdc-out">Out of stock</span>'; }
    $media = $img ? '<img src="' . $e($img) . '" alt="" loading="lazy" onerror="this.parentNode.classList.add(\'sdc-ph\');this.remove()">' : '';
    return '<div class="sdc-card" data-key="' . $e($it['key']) . '"><div class="sdc-media' . ($img ? '' : ' sdc-ph') . ($it['cat'] === 'employee' ? ' sdc-round' : '') . '" style="--c:' . $col . '"><i class="bi ' . $icon . '"></i>' . $media . '</div>'
        . '<div class="sdc-body"><div class="sdc-chips">' . $chips . '</div><div class="sdc-title">' . $e($title) . '</div>' . ($sub ? '<div class="sdc-sub">' . $sub . '</div>' : '') . $big . implode('', $rows) . '</div></div>';
}

function sdc_css(): string
{
    return '<style>
.sdc{position:relative;background:#fff;border:1px solid #EEF1F8;border-radius:14px;box-shadow:0 1px 2px rgba(16,24,40,.04);margin-bottom:16px;overflow:hidden}
.sdc-stage{position:relative;min-height:168px}
.sdc-slide{position:absolute;inset:0;opacity:0;visibility:hidden;transition:opacity .6s ease,visibility .6s}
.sdc-slide.on{position:relative;opacity:1;visibility:visible}
.sdc-card{display:flex;gap:14px;padding:14px 16px 10px;align-items:flex-start}
.sdc-media{position:relative;flex:0 0 112px;width:112px;height:112px;border-radius:12px;overflow:hidden;background:#F3F5FB;display:flex;align-items:center;justify-content:center}
.sdc-media>i{display:none;font-size:34px;color:var(--c)}
.sdc-media.sdc-ph{background:linear-gradient(135deg,#F3F5FB,#E8EDFD)}.sdc-media.sdc-ph>i{display:block;opacity:.8}
.sdc-media img{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}
.sdc-media.sdc-round{border-radius:50%}
.sdc-body{min-width:0;flex:1}
.sdc-chips{display:flex;flex-wrap:wrap;gap:6px;align-items:center;margin-bottom:2px}
.sdc-cat{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em}
.sdc-chip{font-size:10.5px;font-weight:600;padding:1px 8px;border-radius:20px;background:#F1F2F6;color:#4B5263}
.sdc-feat{background:#FFF4D6;color:#8A5A00}.sdc-new{background:#E8EDFD;color:#1E2FE0}.sdc-ok{background:#E1F7EE;color:#0B7A50}.sdc-low{background:#FFF1E0;color:#A04D00}.sdc-out{background:#FCEAEA;color:#B42318}
.sdc-title{font-size:15.5px;font-weight:700;color:#1E2333;line-height:1.25}
.sdc-sub{font-size:12.5px;color:#6B7280;margin:2px 0 4px}
.sdc-price{font-weight:700;color:#1E2333}
.sdc-quote{font-size:16px;font-weight:600;color:#0B1A8C;line-height:1.35;margin-top:4px}
.sdc-text{font-size:13px;color:#1E2333;margin:2px 0 4px}
.sdc-row{font-size:12.5px;color:#374151;margin:1px 0}.sdc-row b{color:#1E2333;font-weight:600}
.sdc-nav{display:flex;align-items:center;justify-content:flex-end;gap:6px;padding:0 12px 8px}
.sdc-nav button{border:1px solid #E4E7EF;background:#fff;border-radius:8px;width:26px;height:24px;font-size:12px;line-height:1;color:#4B5263;padding:0}
.sdc-nav button:hover{border-color:#1E2FE0;color:#1E2FE0}
.sdc-dots{display:flex;gap:4px;margin-right:auto;padding-left:4px;flex-wrap:wrap}
.sdc-dots span{width:6px;height:6px;border-radius:50%;background:#D5DAE6}.sdc-dots span.on{background:#1E2FE0}
.sdc-count{font-size:11px;color:#8A90A3}
@media (max-width:576px){.sdc-card{gap:10px;padding:12px 12px 8px}.sdc-media{flex-basis:64px;width:64px;height:64px}.sdc-media>i{font-size:24px}.sdc-title{font-size:14.5px}.sdc-quote{font-size:14.5px}}
@media (prefers-reduced-motion:reduce){.sdc-slide{transition:none}}
</style>';
}

/** Slides HTML of a playlist. */
function sdc_slides_html(mysqli $conn, array $list, string $base): string
{
    $photos = sdc_get($conn, 'employee_photos') === '1';
    $h = '';
    foreach ($list as $i => $it) {
        $h .= '<div class="sdc-slide' . ($i === 0 ? ' on' : '') . '" data-key="' . sdc_e($it['key']) . '" role="group" aria-roledescription="slide" aria-label="' . ($i + 1) . ' of ' . count($list) . '">' . sdc_card_html($conn, $it, $base, $photos) . '</div>';
    }
    return $h;
}

/**
 * THE shared carousel (staff Dashboard). Prints nothing when nothing is eligible. Safe: never breaks the page.
 * The browser rotates every N seconds (default 120), Previous / Next restart the timer (rotation continues), and at
 * the end of the list it fetches a fresh playlist from feed.php — the dashboard is never reloaded.
 */
function sdc_carousel(mysqli $conn): void
{
    try {
        sdc_ensure($conn);
        $list = sdc_playlist($conn);
    } catch (Throwable $e) { error_log('sdc carousel: ' . $e->getMessage()); return; }
    if (!$list) { return; }
    $base = rtrim((string) (defined('BASE_URL') ? BASE_URL : ''), '/');
    $sec = sdc_interval($conn);
    echo sdc_css();
    ?>
<div class="sdc" id="sdc" role="region" aria-roledescription="carousel" aria-label="Staff reminders, products, services and announcements" data-sec="<?= (int) $sec; ?>" data-feed="<?= sdc_e($base); ?>/modules/staff_cards/feed.php">
    <div class="sdc-stage" aria-live="off"><?= sdc_slides_html($conn, $list, $base); ?></div>
    <div class="sdc-nav"><div class="sdc-dots" aria-hidden="true"></div><span class="sdc-count"></span>
        <button type="button" data-a="prev" aria-label="Previous"><i class="bi bi-chevron-left"></i></button>
        <button type="button" data-a="pause" aria-label="Pause"><i class="bi bi-pause"></i></button>
        <button type="button" data-a="next" aria-label="Next"><i class="bi bi-chevron-right"></i></button></div>
</div>
<script>
(function () {
    var box = document.getElementById('sdc'); if (!box) { return; }
    var stage = box.querySelector('.sdc-stage'), dots = box.querySelector('.sdc-dots'), count = box.querySelector('.sdc-count');
    var sec = parseInt(box.dataset.sec, 10) || 120, feed = box.dataset.feed, i = 0, timer = null, paused = false, loading = false;
    function slides() { return stage.querySelectorAll('.sdc-slide'); }
    function logShown(k) { try { fetch(feed + '?shown=' + encodeURIComponent(k), {credentials: 'same-origin'}); } catch (e) {} }
    function draw() {
        var s = slides(); dots.innerHTML = '';
        s.forEach(function (el, n) { el.classList.toggle('on', n === i); var d = document.createElement('span'); if (n === i) { d.className = 'on'; } dots.appendChild(d); });
        count.textContent = (i + 1) + ' / ' + s.length;
        if (s[i]) { logShown(s[i].dataset.key); }
    }
    function restart() { clearTimeout(timer); if (!paused) { timer = setTimeout(function () { go(1); }, sec * 1000); } }
    function refresh() {
        if (loading) { return; } loading = true;
        fetch(feed, {credentials: 'same-origin'}).then(function (r) { return r.ok ? r.json() : null; }).then(function (j) {
            loading = false;
            if (j && j.html) { stage.innerHTML = j.html; sec = j.sec || sec; i = 0; draw(); restart(); } else { i = 0; draw(); restart(); }
        }).catch(function () { loading = false; i = 0; draw(); restart(); });
    }
    function go(step) {
        var n = slides().length;
        if (step > 0 && i + 1 >= n) { refresh(); return; } // end of the list: fresh content, no page reload
        i = (i + step + n) % n; draw(); restart();
    }
    box.addEventListener('click', function (ev) {
        var b = ev.target.closest('button[data-a]'); if (!b) { return; }
        if (b.dataset.a === 'prev') { go(-1); } else if (b.dataset.a === 'next') { go(1); }
        else { paused = !paused; b.innerHTML = paused ? '<i class="bi bi-play"></i>' : '<i class="bi bi-pause"></i>'; b.setAttribute('aria-label', paused ? 'Play' : 'Pause'); restart(); }
    });
    draw(); restart();
})();
</script>
    <?php
}

/** Saves an uploaded image for a card (cropped / compressed like other MY MOTIVE images). Returns the reference or null. */
function sdc_save_image(mysqli $conn, string $field, string $kind): ?string
{
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { return null; }
    if (is_file(__DIR__ . '/image_kit.php')) {
        require_once __DIR__ . '/image_kit.php';
        $err = img_prepare_upload($field, $kind === 'employee' ? 'profile' : ($kind === 'announcement' ? 'news' : 'product'));
        if ($err !== null) { throw new RuntimeException($err); }
    }
    return evidence_save($conn, $_FILES[$field], sdc_uid(), ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'], 3 * 1048576);
}