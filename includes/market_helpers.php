<?php
/**
 * NEW FILE: includes/market_helpers.php — RM MARKET INSIGHTS (products and services customers want).
 *
 *   OPPORTUNITY  one product or service idea (mi_opportunities). Many customer requests for the same product are
 *                consolidated into ONE opportunity while every request stays its own record (mi_requests).
 *                An opportunity is linked to an RM Offerings item when the product / service already exists
 *                (products.id) — recording demand never creates a stock item.
 *   ORIGIN       request (customers asked), research (staff discovered), system (MY MOTIVE data), portal (customer suggestion).
 *   DEMAND       computed from the requests (number of requests, unique customers, quantity, trend, readiness),
 *                classified with thresholds set by management (mi_settings): High, Growing, Moderate, Low, Insufficient data.
 *   AUDIT        every action in mi_audit (who, when, what).
 */

const MI_STATUSES = ['New', 'Under Review', 'More Information Required', 'Recommended', 'Approved', 'Rejected', 'Deferred', 'In Implementation', 'Launched/Active', 'Completed'];
const MI_SOURCES = ['Walk-in', 'Phone', 'WhatsApp', 'Customer Portal', 'Other'];
const MI_URGENCY = ['Normal', 'Urgent'];
const MI_PRODUCT_OUTCOMES = ['Request unresolved', 'Product to be sourced', 'Alternative product offered', 'Customer declined the alternative', 'Product fulfilled from available stock'];
const MI_PRODUCT_REASONS = ['Not in our catalogue', 'Out of stock', 'Not enough stock', 'Price too high for the customer', 'Wrong brand / specification', 'Other'];
const MI_SERVICE_REASONS = ['Lack of technical skills', 'Insufficient employee proficiency', 'No qualified employee available', 'Required equipment unavailable',
    'Required software or technology unavailable', 'Insufficient human resources', 'Service not yet included in company offerings', 'Other'];
const MI_SERVICE_OUTCOMES = ['Request unresolved', 'Delivered by another employee', 'Referred to a partner', 'Customer will come back later', 'Customer declined'];
const MI_DISCOVERY_SOURCES = ['Customer conversation', 'Competitor shop', 'Market visit', 'Supplier catalogue', 'Social media / online marketplace', 'Personal market research', 'Industry trend'];
const MI_DEMAND_LEVELS = ['High' => 'danger', 'Growing' => 'warning', 'Moderate' => 'primary', 'Low' => 'secondary', 'Insufficient data' => 'light'];
const MI_ORIGINS = ['request' => 'Customer requests', 'research' => 'Market research', 'system' => 'Suggested by MY MOTIVE', 'portal' => 'Customer Portal suggestion'];

const MI_SETTING_DEFAULTS = [
    'high_customers' => '5', 'high_requests' => '5', 'high_quantity' => '20',     // High: enough customers AND requests (or a large quantity)
    'moderate_customers' => '2', 'moderate_requests' => '3',                      // Moderate: some repeated interest
    'growth_days' => '30', 'growth_min' => '3', 'growth_factor' => '1.5',         // Growing: recent period vs the period before
    'analysis_min_requests' => '3',                                                // a product qualifies for market analysis
    'alert_repeat_count' => '3', 'alert_repeat_days' => '14',                     // alert: requested N times within D days
];

function mi_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS mi_opportunities (
        id INT(11) NOT NULL AUTO_INCREMENT, kind VARCHAR(10) NOT NULL DEFAULT 'product', name VARCHAR(160) NOT NULL, name_key VARCHAR(160) NOT NULL,
        category VARCHAR(80) NULL, description TEXT NULL, unit VARCHAR(30) NULL, product_id INT(11) NULL, origin VARCHAR(10) NOT NULL DEFAULT 'request',
        status VARCHAR(30) NOT NULL DEFAULT 'New', est_demand VARCHAR(80) NULL, segment VARCHAR(160) NULL, est_sell_price DECIMAL(14,2) NULL, est_buy_price DECIMAL(14,2) NULL,
        possible_supplier VARCHAR(160) NULL, discovery_source VARCHAR(60) NULL, image_ref VARCHAR(255) NULL, notes TEXT NULL, merged_into INT(11) NULL,
        branch_id INT(11) NULL, created_by INT(11) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_by INT(11) NULL, updated_at DATETIME NULL,
        PRIMARY KEY (id), KEY kind_key (kind, name_key), KEY product (product_id), KEY status (status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS mi_requests (
        id INT(11) NOT NULL AUTO_INCREMENT, opportunity_id INT(11) NOT NULL, kind VARCHAR(10) NOT NULL DEFAULT 'product',
        customer_id INT(11) NULL, walkin_name VARCHAR(120) NULL, walkin_phone VARCHAR(30) NULL, quantity DECIMAL(12,2) NOT NULL DEFAULT 1, expected_price DECIMAL(14,2) NULL,
        source VARCHAR(20) NOT NULL DEFAULT 'Walk-in', urgency VARCHAR(10) NOT NULL DEFAULT 'Normal', reason VARCHAR(80) NULL, outcome VARCHAR(60) NULL,
        ready_to_buy VARCHAR(10) NULL, expected_purchase_date DATE NULL, specification VARCHAR(255) NULL, quality VARCHAR(80) NULL, notes TEXT NULL,
        skills_required VARCHAR(255) NULL, equipment_required VARCHAR(255) NULL, other_employee_can VARCHAR(10) NULL,
        branch_id INT(11) NULL, recorded_by INT(11) NULL, requested_at DATETIME NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id), KEY opp (opportunity_id), KEY customer (customer_id), KEY requested (requested_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    mysqli_query($conn, 'CREATE TABLE IF NOT EXISTS mi_settings (name VARCHAR(60) NOT NULL, value TEXT NULL, PRIMARY KEY (name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS mi_audit (id INT(11) NOT NULL AUTO_INCREMENT, opportunity_id INT(11) NULL, request_id INT(11) NULL, action VARCHAR(40) NOT NULL,
        details TEXT NULL, user_id INT(11) NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY opp (opportunity_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function mi_setting(mysqli $conn, string $k): string
{
    static $c = null;
    if ($c === null) { $c = []; try { foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT name, value FROM mi_settings')) as $r) { $c[$r[0]] = (string) $r[1]; } } catch (Throwable $e) { } }
    return $c[$k] ?? (MI_SETTING_DEFAULTS[$k] ?? '');
}

function mi_setting_set(mysqli $conn, string $k, string $v): void
{
    $s = mysqli_prepare($conn, 'DELETE FROM mi_settings WHERE name = ?'); mysqli_stmt_bind_param($s, 's', $k); mysqli_stmt_execute($s);
    $s = mysqli_prepare($conn, 'INSERT INTO mi_settings (name, value) VALUES (?, ?)'); mysqli_stmt_bind_param($s, 'ss', $k, $v); mysqli_stmt_execute($s);
}

function mi_audit(mysqli $conn, ?int $oppId, ?int $reqId, string $action, string $details = ''): void
{
    $u = !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    $s = mysqli_prepare($conn, 'INSERT INTO mi_audit (opportunity_id, request_id, action, details, user_id) VALUES (?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($s, 'iissi', $oppId, $reqId, $action, $details, $u);
    mysqli_stmt_execute($s);
}

/** Matching key for duplicate detection: "A3 Papers (80g)!" -> "a3 paper 80g". */
function mi_key(string $name): string
{
    $s = mb_strtolower(trim($name), 'UTF-8');
    if (function_exists('iconv')) { $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s); if ($t !== false && $t !== '') { $s = $t; } }
    $s = preg_replace('/[^a-z0-9]+/', ' ', $s);
    $w = array_filter(explode(' ', trim((string) $s)), 'strlen');
    $w = array_map(function ($x) { return strlen($x) > 3 && substr($x, -1) === 's' && substr($x, -2) !== 'ss' ? substr($x, 0, -1) : $x; }, $w);
    return mb_substr(implode(' ', $w), 0, 160);
}

/** Similarity 0..100 of two keys (word overlap + spelling). */
function mi_similarity(string $a, string $b): float
{
    if ($a === '' || $b === '') { return 0.0; }
    if ($a === $b) { return 100.0; }
    $wa = array_unique(explode(' ', $a)); $wb = array_unique(explode(' ', $b));
    $common = count(array_intersect($wa, $wb));
    $jac = $common / max(1, count(array_unique(array_merge($wa, $wb))));
    similar_text($a, $b, $pct);
    $contain = (strpos($a, $b) !== false || strpos($b, $a) !== false) ? 20 : 0;
    return min(99.0, max($jac * 100, $pct * 0.85) + $contain);
}

/**
 * Finds what an employee is typing in: RM Offerings items (with stock), existing opportunities (with their requests).
 * Returns ['catalogue' => [...], 'opportunities' => [...]] best first.
 */
function mi_lookup(mysqli $conn, string $q, string $kind = 'product'): array
{
    $key = mi_key($q);
    $out = ['catalogue' => [], 'opportunities' => []];
    if (mb_strlen($key) < 2) { return $out; }
    $words = array_values(array_filter(explode(' ', $key), function ($w) { return strlen($w) >= 2; }));
    if (!$words) { return $out; }
    $like = function (string $col) use ($words, $conn): string { return '(' . implode(' OR ', array_map(function ($w) use ($col, $conn) { return $col . " LIKE '%" . mysqli_real_escape_string($conn, $w) . "%'"; }, array_slice($words, 0, 5))) . ')'; };
    $type = $kind === 'service' ? 'Service' : 'Item';
    try {
        foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT id, product_name, product_code, quantity, unit, category, is_active, selling_price, buying_price, reorder_level FROM products WHERE item_type = '$type' AND " . $like('product_name') . ' LIMIT 60'), MYSQLI_ASSOC) as $p) {
            $sc = mi_similarity($key, mi_key($p['product_name']));
            if ($sc >= 35) { $p['score'] = round($sc); $out['catalogue'][] = $p; }
        }
    } catch (Throwable $e) { }
    $k = $kind === 'service' ? 'service' : 'product';
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT o.id, o.name, o.category, o.status, o.product_id, o.unit, o.origin, (SELECT COUNT(*) FROM mi_requests r WHERE r.opportunity_id = o.id) requests,
            (SELECT COALESCE(SUM(quantity), 0) FROM mi_requests r WHERE r.opportunity_id = o.id) qty FROM mi_opportunities o WHERE o.kind = '$k' AND o.merged_into IS NULL AND " . $like('o.name_key') . ' LIMIT 60'), MYSQLI_ASSOC) as $o) {
        $sc = mi_similarity($key, mi_key($o['name']));
        if ($sc >= 35) { $o['score'] = round($sc); $out['opportunities'][] = $o; }
    }
    foreach ($out as &$list) { usort($list, function ($a, $b) { return $b['score'] <=> $a['score']; }); $list = array_slice($list, 0, 6); }
    return $out;
}

/** The opportunity of an RM Offerings item (created when the first request for it is recorded). */
function mi_opportunity_for_product(mysqli $conn, int $productId, string $kind): int
{
    $r = mysqli_fetch_row(mysqli_query($conn, "SELECT id FROM mi_opportunities WHERE product_id = $productId AND merged_into IS NULL ORDER BY id LIMIT 1"));
    if ($r) { return (int) $r[0]; }
    $p = mysqli_fetch_assoc(mysqli_query($conn, "SELECT product_name, category, unit, description FROM products WHERE id = $productId"));
    if (!$p) { throw new RuntimeException('This RM Offerings item no longer exists.'); }
    return mi_create_opportunity($conn, ['kind' => $kind, 'name' => $p['product_name'], 'category' => $p['category'], 'unit' => $p['unit'], 'product_id' => $productId, 'origin' => 'request']);
}

/** Creates an opportunity; refuses an exact duplicate (same kind and same key) and returns the existing one instead. */
function mi_create_opportunity(mysqli $conn, array $d): int
{
    $kind = ($d['kind'] ?? 'product') === 'service' ? 'service' : 'product';
    $name = mb_substr(trim((string) $d['name']), 0, 160);
    if ($name === '') { throw new RuntimeException('Give the product or service name.'); }
    $key = mi_key($name);
    if (empty($d['product_id'])) {
        $s = mysqli_prepare($conn, 'SELECT id FROM mi_opportunities WHERE kind = ? AND name_key = ? AND merged_into IS NULL LIMIT 1'); mysqli_stmt_bind_param($s, 'ss', $kind, $key); mysqli_stmt_execute($s);
        if ($r = mysqli_fetch_row(mysqli_stmt_get_result($s))) { return (int) $r[0]; }
    }
    $f = ['category' => null, 'description' => null, 'unit' => null, 'product_id' => null, 'origin' => 'request', 'est_demand' => null, 'segment' => null, 'est_sell_price' => null,
          'est_buy_price' => null, 'possible_supplier' => null, 'discovery_source' => null, 'image_ref' => null, 'notes' => null, 'branch_id' => null];
    foreach ($f as $k => $v) { if (isset($d[$k]) && $d[$k] !== '') { $f[$k] = $d[$k]; } }
    $uid = !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    $s = mysqli_prepare($conn, 'INSERT INTO mi_opportunities (kind, name, name_key, category, description, unit, product_id, origin, est_demand, segment, est_sell_price, est_buy_price,
        possible_supplier, discovery_source, image_ref, notes, branch_id, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $pid = $f['product_id'] !== null ? (int) $f['product_id'] : null; $sp = $f['est_sell_price'] !== null ? (float) $f['est_sell_price'] : null; $bp = $f['est_buy_price'] !== null ? (float) $f['est_buy_price'] : null;
    $br = $f['branch_id'] !== null ? (int) $f['branch_id'] : null;
    mysqli_stmt_bind_param($s, 'ssssssisssddssssii', $kind, $name, $key, $f['category'], $f['description'], $f['unit'], $pid, $f['origin'], $f['est_demand'], $f['segment'], $sp, $bp,
        $f['possible_supplier'], $f['discovery_source'], $f['image_ref'], $f['notes'], $br, $uid);
    mysqli_stmt_execute($s);
    $id = (int) mysqli_insert_id($conn);
    mi_audit($conn, $id, null, 'created', ucfirst($kind) . ' opportunity "' . $name . '" (' . (MI_ORIGINS[$f['origin']] ?? $f['origin']) . ')' . ($pid ? ', linked to RM Offerings item #' . $pid : ''));
    return $id;
}

/** Records one customer request on an opportunity. Returns the request id. */
function mi_add_request(mysqli $conn, int $oppId, array $d): int
{
    $o = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM mi_opportunities WHERE id = $oppId"));
    if (!$o) { throw new RuntimeException('Opportunity not found.'); }
    if ($o['merged_into']) { $oppId = (int) $o['merged_into']; }
    $qty = (float) ($d['quantity'] ?? 1);
    if ($qty <= 0 || $qty > 1000000) { throw new RuntimeException('Enter the quantity requested (at least 1).'); }
    $cid = !empty($d['customer_id']) ? (int) $d['customer_id'] : null;
    if ($cid && !mysqli_fetch_row(mysqli_query($conn, "SELECT 1 FROM customers WHERE id = $cid"))) { throw new RuntimeException('The chosen customer does not exist.'); }
    $pick = function ($v, array $ok, $def) { return in_array($v, $ok, true) ? $v : $def; };
    $src = $pick($d['source'] ?? '', MI_SOURCES, 'Walk-in'); $urg = $pick($d['urgency'] ?? '', MI_URGENCY, 'Normal');
    $reasons = $o['kind'] === 'service' ? MI_SERVICE_REASONS : MI_PRODUCT_REASONS; $outcomes = $o['kind'] === 'service' ? MI_SERVICE_OUTCOMES : MI_PRODUCT_OUTCOMES;
    $reason = $pick($d['reason'] ?? '', $reasons, null); $outcome = $pick($d['outcome'] ?? '', $outcomes, 'Request unresolved');
    $ready = $pick($d['ready_to_buy'] ?? '', ['Yes', 'No', 'Not sure'], null); $other = $pick($d['other_employee_can'] ?? '', ['Yes', 'No', 'Not sure'], null);
    $price = isset($d['expected_price']) && $d['expected_price'] !== '' ? max(0, (float) $d['expected_price']) : null;
    $epd = !empty($d['expected_purchase_date']) && DateTime::createFromFormat('Y-m-d', (string) $d['expected_purchase_date']) ? $d['expected_purchase_date'] : null;
    $t = function ($k, $n) use ($d) { $v = trim((string) ($d[$k] ?? '')); return $v === '' ? null : mb_substr($v, 0, $n); };
    $wn = $cid ? null : $t('walkin_name', 120); $wp = $cid ? null : $t('walkin_phone', 30);
    $at = !empty($d['requested_at']) ? (string) $d['requested_at'] : date('Y-m-d H:i:s');
    $uid = !empty($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
    $br = isset($d['branch_id']) && $d['branch_id'] ? (int) $d['branch_id'] : null;
    $kind = $o['kind']; $spec = $t('specification', 255); $qual = $t('quality', 80); $notes = $t('notes', 2000); $sk = $t('skills_required', 255); $eq = $t('equipment_required', 255);
    $s = mysqli_prepare($conn, 'INSERT INTO mi_requests (opportunity_id, kind, customer_id, walkin_name, walkin_phone, quantity, expected_price, source, urgency, reason, outcome, ready_to_buy,
        expected_purchase_date, specification, quality, notes, skills_required, equipment_required, other_employee_can, branch_id, recorded_by, requested_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($s, 'isissddssssssssssssiis', $oppId, $kind, $cid, $wn, $wp, $qty, $price, $src, $urg, $reason, $outcome, $ready, $epd, $spec, $qual, $notes, $sk, $eq, $other, $br, $uid, $at);
    mysqli_stmt_execute($s);
    $rid = (int) mysqli_insert_id($conn);
    $now = date('Y-m-d H:i:s');
    mysqli_query($conn, "UPDATE mi_opportunities SET updated_at = '$now' WHERE id = $oppId");
    mi_audit($conn, $oppId, $rid, 'request_added', rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.') . ' requested' . ($cid ? ' by customer #' . $cid : ($wn ? ' by ' . $wn : '')) . ' (' . $src . ', ' . $outcome . ')');
    return $rid;
}

/** Who a request is from, for counting UNIQUE customers (same customer twice = one customer). */
function mi_customer_key(array $r): string
{
    if (!empty($r['customer_id'])) { return 'c' . (int) $r['customer_id']; }
    $p = preg_replace('/\D/', '', (string) ($r['walkin_phone'] ?? ''));
    if (strlen($p) >= 9) { return 'p' . substr($p, -9); }
    $n = mi_key((string) ($r['walkin_name'] ?? ''));
    return $n !== '' ? 'n' . $n : 'a' . (int) $r['id']; // an anonymous walk-in counts as one customer per request
}

/**
 * Demand figures for opportunities: [opp_id => stats]. $from / $to limit the requests (Y-m-d) when given.
 * stats: requests, customers, identified, quantity, first, last, recent, previous, ready, urgent, branches, outcomes, avg_price.
 */
function mi_stats(mysqli $conn, ?array $oppIds = null, ?string $from = null, ?string $to = null): array
{
    $w = ['1 = 1'];
    if ($oppIds !== null) { if (!$oppIds) { return []; } $w[] = 'opportunity_id IN (' . implode(',', array_map('intval', $oppIds)) . ')'; }
    if ($from) { $w[] = "requested_at >= '" . mysqli_real_escape_string($conn, $from) . " 00:00:00'"; }
    if ($to) { $w[] = "requested_at <= '" . mysqli_real_escape_string($conn, $to) . " 23:59:59'"; }
    $days = max(1, (int) mi_setting($conn, 'growth_days'));
    $recentFrom = date('Y-m-d H:i:s', time() - $days * 86400); $prevFrom = date('Y-m-d H:i:s', time() - 2 * $days * 86400);
    $out = [];
    $res = mysqli_query($conn, 'SELECT id, opportunity_id, customer_id, walkin_name, walkin_phone, quantity, expected_price, urgency, outcome, ready_to_buy, branch_id, requested_at FROM mi_requests WHERE ' . implode(' AND ', $w) . ' ORDER BY requested_at');
    while ($r = mysqli_fetch_assoc($res)) {
        $o = (int) $r['opportunity_id'];
        $s = &$out[$o];
        if ($s === null) { $s = ['requests' => 0, 'cust' => [], 'identified' => 0, 'quantity' => 0.0, 'first' => null, 'last' => null, 'recent' => 0, 'previous' => 0, 'ready' => 0, 'urgent' => 0, 'branches' => [], 'outcomes' => [], 'prices' => [], 'dates' => []]; }
        $s['requests']++; $k = mi_customer_key($r); $s['cust'][$k] = true; if ($k[0] !== 'a') { $s['identified']++; }
        $s['quantity'] += (float) $r['quantity'];
        $s['first'] = $s['first'] ?? $r['requested_at']; $s['last'] = $r['requested_at'];
        if ($r['requested_at'] >= $recentFrom) { $s['recent']++; } elseif ($r['requested_at'] >= $prevFrom) { $s['previous']++; }
        if ($r['ready_to_buy'] === 'Yes') { $s['ready']++; }
        if ($r['urgency'] === 'Urgent') { $s['urgent']++; }
        if ($r['branch_id']) { $s['branches'][(int) $r['branch_id']] = ($s['branches'][(int) $r['branch_id']] ?? 0) + 1; }
        $s['outcomes'][$r['outcome'] ?: '—'] = ($s['outcomes'][$r['outcome'] ?: '—'] ?? 0) + 1;
        if ($r['expected_price'] !== null) { $s['prices'][] = (float) $r['expected_price']; }
        $s['dates'][] = $r['requested_at'];
        unset($s);
    }
    foreach ($out as &$s) {
        $s['customers'] = count($s['cust']); unset($s['cust']);
        $s['avg_price'] = $s['prices'] ? array_sum($s['prices']) / count($s['prices']) : null; unset($s['prices']);
        // average days between two requests
        $gaps = [];
        for ($i = 1; $i < count($s['dates']); $i++) { $gaps[] = (strtotime($s['dates'][$i]) - strtotime($s['dates'][$i - 1])) / 86400; }
        $s['avg_gap_days'] = $gaps ? round(array_sum($gaps) / count($gaps), 1) : null; unset($s['dates']);
    }
    return $out;
}

/** Demand level with the reason, from the thresholds set by management. Returns [level, why]. */
function mi_classify(mysqli $conn, ?array $s): array
{
    $g = function ($k) use ($conn) { return (float) mi_setting($conn, $k); };
    if (!$s || $s['requests'] < 1) { return ['Insufficient data', 'No customer request recorded yet: more market research is needed.']; }
    $c = $s['customers']; $n = $s['requests']; $q = $s['quantity'];
    if ($c >= $g('high_customers') && ($n >= $g('high_requests') || $q >= $g('high_quantity'))) {
        return ['High', $c . ' different customers, ' . $n . ' requests, ' . mi_num($q) . ' units (High = at least ' . mi_num($g('high_customers')) . ' customers and ' . mi_num($g('high_requests')) . ' requests or ' . mi_num($g('high_quantity')) . ' units).'];
    }
    $days = (int) $g('growth_days');
    if ($s['recent'] >= $g('growth_min') && $s['recent'] >= $g('growth_factor') * max(1, $s['previous'])) {
        return ['Growing', $s['recent'] . ' requests in the last ' . $days . ' days against ' . $s['previous'] . ' in the ' . $days . ' days before (Growing = at least ' . mi_num($g('growth_min')) . ' and ' . mi_num($g('growth_factor')) . '× more).'];
    }
    if ($c >= $g('moderate_customers') || $n >= $g('moderate_requests')) {
        return ['Moderate', $c . ' customer(s), ' . $n . ' request(s): some repeated interest, not yet enough for an immediate decision.'];
    }
    return ['Low', $n . ' request(s) from ' . $c . ' customer(s): limited demand so far.'];
}

/** Situation of a product: new / existing in stock / low / out of stock; or a service: new / existing offering. Returns [code, label, colour]. */
function mi_situation(array $o, ?array $p): array
{
    if ($o['kind'] === 'service') { return $p ? ['service_existing', 'Existing service (RM Offerings)', 'info'] : ['service_new', 'New service', 'success']; }
    if (!$p) { return $o['origin'] === 'research' ? ['research', 'New product (market research)', 'success'] : ['new', 'New product — never stocked', 'success']; }
    if (!(int) $p['is_active']) { return ['inactive', 'Existing product (deactivated)', 'secondary']; }
    $qty = (float) $p['quantity'];
    if ($qty <= 0) { return ['oos', 'Existing product — out of stock', 'danger']; }
    if ($p['reorder_level'] !== null && $qty <= (float) $p['reorder_level']) { return ['low', 'Existing product — low stock', 'warning']; }
    return ['in_stock', 'Existing product — in stock', 'secondary'];
}

/** Products of the catalogue for a list of ids. */
function mi_products(mysqli $conn, array $ids): array
{
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) { return []; }
    $o = [];
    foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT id, product_name, product_code, quantity, unit, category, is_active, reorder_level, selling_price, buying_price, item_type FROM products WHERE id IN (' . implode(',', $ids) . ')'), MYSQLI_ASSOC) as $p) { $o[(int) $p['id']] = $p; }
    return $o;
}

function mi_num($v): string { $v = (float) $v; return $v == floor($v) ? number_format($v) : number_format($v, 2); }
function mi_money($v): string { return $v === null || $v === '' ? '—' : 'RWF ' . number_format((float) $v); }

/** Opportunity categories: the managed RM Offerings categories (no inconsistent spellings). */
function mi_categories(mysqli $conn, string $kind, ?string $keep = null): array
{
    if (is_file(__DIR__ . '/category_helpers.php')) { require_once __DIR__ . '/category_helpers.php'; try { return categories_active($conn, $kind === 'service' ? 'service' : 'product', $keep); } catch (Throwable $e) { } }
    return $keep ? [$keep] : [];
}

/**
 * Merges a duplicate opportunity into another one: every request moves, the duplicate stays (merged_into) for history.
 */
function mi_merge(mysqli $conn, int $fromId, int $intoId): void
{
    if ($fromId === $intoId) { throw new RuntimeException('Choose another opportunity.'); }
    $a = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM mi_opportunities WHERE id = $fromId"));
    $b = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM mi_opportunities WHERE id = $intoId"));
    if (!$a || !$b || $b['merged_into'] || $a['kind'] !== $b['kind']) { throw new RuntimeException('These two cannot be merged.'); }
    $n = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM mi_requests WHERE opportunity_id = $fromId"))[0];
    mysqli_query($conn, "UPDATE mi_requests SET opportunity_id = $intoId WHERE opportunity_id = $fromId");
    $now = date('Y-m-d H:i:s');
    mysqli_query($conn, "UPDATE mi_opportunities SET merged_into = $intoId, updated_at = '$now' WHERE id = $fromId");
    if (!$b['product_id'] && $a['product_id']) { mysqli_query($conn, 'UPDATE mi_opportunities SET product_id = ' . (int) $a['product_id'] . " WHERE id = $intoId"); }
    mi_audit($conn, $intoId, null, 'merged', '"' . $a['name'] . '" (#' . $fromId . ') merged into this opportunity: ' . $n . ' request(s) moved');
    mi_audit($conn, $fromId, null, 'merged_into', 'Merged into "' . $b['name'] . '" (#' . $intoId . ')');
}