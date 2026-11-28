<?php
/**
 * NEW FILE: includes/dl_helpers.php — DELEGATIONS & RM TASKER MANAGEMENT (shared engine).
 *
 * RM Taskers are Business Parties of type "Tasker" (shown as "RM Tasker"): the same business_parties table as
 * suppliers, payees and partners — no second registration system. dl_taskers only adds what a Tasker needs on top
 * (RMST number, specialization, experience, availability, rating, status, preferred flag).
 * What a Tasker can do for RISE MOTIVE = rows of dl_tasker_offerings, each pointing to an RM Offering (products):
 * percentage rate, min / max customer price, completion time, availability, validity, status. Every rate change is
 * kept in dl_rate_history; a delegation stores the rate agreed at the time (it never changes afterwards).
 * How each RM Offering is fulfilled (Internal / Delegatable / External Only / Not Available) and its delivery
 * standards live in dl_offering_settings, keyed by the product id.
 *
 * Recommendation: never the lowest percentage alone. Weighted score of cost, speed, quality, reliability and
 * availability (weights in Delegation Settings, default 35/25/20/15/5). Taskers without enough completed work are
 * labelled "New / limited history" and get a neutral value instead of a poor one. Preferred RM Taskers get a small
 * configurable bonus.
 */

const DL_FULFILMENT = ['Internal', 'Delegatable', 'External Only', 'Not Available'];
const DL_FULFILMENT_HELP = [
    'Internal' => 'RISE MOTIVE provides it internally.',
    'Delegatable' => 'It can be provided internally or by an RM Tasker.',
    'External Only' => 'RISE MOTIVE currently depends on RM Taskers.',
    'Not Available' => 'RISE MOTIVE cannot currently provide it.',
];
const DL_TASKER_STATUS = ['Active', 'Inactive', 'Suspended'];
const DL_AVAILABILITY = ['Available', 'Limited', 'Unavailable'];
const DL_SETTING_DEFAULTS = [
    // recommendation weights (%)
    'w_cost' => '35', 'w_speed' => '25', 'w_quality' => '20', 'w_reliability' => '15', 'w_availability' => '5',
    // performance weights (%)
    'p_quality' => '35', 'p_ontime' => '25', 'p_satisfaction' => '20', 'p_reliability' => '10', 'p_communication' => '10',
    'min_history' => '3',            // completed delegations before a Tasker's own record is used
    'preferred_bonus' => '5',        // points added to a Preferred RM Tasker's score (0–100 scale)
    'profit_basis' => 'cogs',        // cogs: revenue − cost of sales; cogs_direct: revenue − cost of sales − other direct costs
    'profit_basis_by' => '', 'profit_basis_at' => '',
    'follow_up_minutes' => '60', 'follow_up_urgent_minutes' => '20', 'reminder_hours' => '2',
    'verify_days' => '3', 'confirm_days' => '3', 'pay_terms_days' => '7',
    'whatsapp_link' => '',
];

function dl_e($v): string { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function dl_uid(): int { return (int) ($_SESSION['user_id'] ?? 0); }
function dl_now(): string { return date('Y-m-d H:i:s'); }
function dl_money($v): string { return 'RWF ' . number_format((float) $v); }
function dl_pct($v): string { return rtrim(rtrim(number_format((float) $v, 2), '0'), '.') . '%'; }
function dl_code(int $taskerNo): string { return 'RMST' . str_pad((string) $taskerNo, 5, '0', STR_PAD_LEFT); }
function dl_hours_text($h): string
{
    $h = (float) $h;
    if ($h <= 0) { return '—'; }
    if ($h < 24 || fmod($h, 24) != 0) { return rtrim(rtrim(number_format($h, 1), '0'), '.') . ' h'; }
    $d = (int) ($h / 24);
    return $d . ' day' . ($d === 1 ? '' : 's');
}

function dl_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    $q = function ($sql) use ($conn) { mysqli_query($conn, $sql); };
    // business_parties.type: add 'Tasker' when the column is an ENUM without it (the existing values are kept).
    try {
        $t = mysqli_fetch_assoc(mysqli_query($conn, "SELECT DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'business_parties' AND column_name = 'type'"));
        if ($t && strtolower($t['DATA_TYPE']) === 'enum' && stripos($t['COLUMN_TYPE'], "'Tasker'") === false) {
            preg_match_all("/'([^']*)'/", $t['COLUMN_TYPE'], $m);
            $vals = array_merge($m[1], ['Tasker']);
            $list = implode(', ', array_map(function ($v) use ($conn) { return "'" . mysqli_real_escape_string($conn, $v) . "'"; }, $vals));
            $def = $t['COLUMN_DEFAULT'] !== null ? " DEFAULT '" . mysqli_real_escape_string($conn, trim($t['COLUMN_DEFAULT'], "'")) . "'" : '';
            $q("ALTER TABLE business_parties MODIFY type ENUM($list) " . ($t['IS_NULLABLE'] === 'YES' ? 'NULL' : 'NOT NULL') . $def);
        }
    } catch (Throwable $e) { error_log('dl_ensure enum: ' . $e->getMessage()); }

    $q("CREATE TABLE IF NOT EXISTS dl_taskers (
        id INT(11) NOT NULL AUTO_INCREMENT, party_id INT(11) NOT NULL, specialization VARCHAR(255) NULL, experience TEXT NULL,
        availability VARCHAR(12) NOT NULL DEFAULT 'Available', availability_note VARCHAR(255) NULL, quality_rating DECIMAL(3,2) NULL,
        status VARCHAR(12) NOT NULL DEFAULT 'Active', status_reason VARCHAR(255) NULL, preferred TINYINT(1) NOT NULL DEFAULT 0, preferred_reason VARCHAR(255) NULL,
        notes TEXT NULL, created_by INT(11) NULL, created_at DATETIME NULL, updated_by INT(11) NULL, updated_at DATETIME NULL,
        PRIMARY KEY (id), UNIQUE KEY party (party_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS dl_tasker_offerings (
        id INT(11) NOT NULL AUTO_INCREMENT, party_id INT(11) NOT NULL, product_id INT(11) NOT NULL, rate_pct DECIMAL(5,2) NOT NULL,
        min_price DECIMAL(15,2) NULL, max_price DECIMAL(15,2) NULL, est_hours DECIMAL(8,2) NULL, availability VARCHAR(12) NOT NULL DEFAULT 'Available',
        valid_from DATE NULL, valid_to DATE NULL, status VARCHAR(10) NOT NULL DEFAULT 'Active', notes VARCHAR(255) NULL,
        created_by INT(11) NULL, created_at DATETIME NULL, updated_by INT(11) NULL, updated_at DATETIME NULL,
        PRIMARY KEY (id), KEY party (party_id), KEY product (product_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS dl_rate_history (
        id INT(11) NOT NULL AUTO_INCREMENT, tasker_offering_id INT(11) NOT NULL, party_id INT(11) NOT NULL, product_id INT(11) NOT NULL,
        old_rate DECIMAL(5,2) NULL, new_rate DECIMAL(5,2) NULL, change_text VARCHAR(500) NULL, reason VARCHAR(255) NULL,
        user_id INT(11) NULL, created_at DATETIME NOT NULL, PRIMARY KEY (id), KEY party (party_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q("CREATE TABLE IF NOT EXISTS dl_offering_settings (
        product_id INT(11) NOT NULL, fulfilment VARCHAR(16) NOT NULL DEFAULT 'Internal', internal_capability VARCHAR(10) NOT NULL DEFAULT 'Yes',
        delegation_allowed TINYINT(1) NOT NULL DEFAULT 0, expected_rate DECIMAL(5,2) NULL, std_hours DECIMAL(8,2) NULL, max_hours DECIMAL(8,2) NULL,
        urgent_hours DECIMAL(8,2) NULL, quality_requirements TEXT NULL, customer_verification TINYINT(1) NOT NULL DEFAULT 0, reminder_hours DECIMAL(6,2) NULL,
        internalization_plan VARCHAR(20) NOT NULL DEFAULT 'None', internalization_note VARCHAR(255) NULL, materials TEXT NULL,
        updated_by INT(11) NULL, updated_at DATETIME NULL, PRIMARY KEY (product_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $q('CREATE TABLE IF NOT EXISTS dl_settings (k VARCHAR(40) NOT NULL, v VARCHAR(500) NULL, PRIMARY KEY (k)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    $q("CREATE TABLE IF NOT EXISTS dl_audit (
        id INT(11) NOT NULL AUTO_INCREMENT, entity VARCHAR(20) NOT NULL, entity_id INT(11) NULL, action VARCHAR(40) NOT NULL, details TEXT NULL,
        reason VARCHAR(500) NULL, actor VARCHAR(10) NOT NULL DEFAULT 'staff', user_id INT(11) NULL, party_id INT(11) NULL, created_at DATETIME NOT NULL,
        PRIMARY KEY (id), KEY entity (entity, entity_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function dl_setting(mysqli $conn, string $k): string
{
    static $cache = [];
    if (!array_key_exists($k, $cache)) {
        $s = mysqli_prepare($conn, 'SELECT v FROM dl_settings WHERE k = ?');
        mysqli_stmt_bind_param($s, 's', $k);
        mysqli_stmt_execute($s);
        $r = mysqli_fetch_row(mysqli_stmt_get_result($s));
        $cache[$k] = $r ? (string) $r[0] : null;
    }
    return $cache[$k] ?? (DL_SETTING_DEFAULTS[$k] ?? '');
}
function dl_set(mysqli $conn, string $k, string $v): void
{
    $s = mysqli_prepare($conn, 'INSERT INTO dl_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)');
    mysqli_stmt_bind_param($s, 'ss', $k, $v);
    mysqli_stmt_execute($s);
}

/** actor: staff | tasker | system. A staff action ON BEHALF of a Tasker is actor 'staff' with action '..._for_tasker'. */
function dl_audit(mysqli $conn, string $entity, ?int $id, string $action, string $details, ?string $reason = null, string $actor = 'staff', ?int $partyId = null): void
{
    $u = $actor === 'staff' ? dl_uid() : null;
    $now = dl_now();
    $s = mysqli_prepare($conn, 'INSERT INTO dl_audit (entity, entity_id, action, details, reason, actor, user_id, party_id, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
    mysqli_stmt_bind_param($s, 'sissssiis', $entity, $id, $action, $details, $reason, $actor, $u, $partyId, $now);
    mysqli_stmt_execute($s);
}

/** A Tasker: business party + dl_taskers row. */
function dl_tasker(mysqli $conn, int $partyId): ?array
{
    $r = mysqli_fetch_assoc(mysqli_query($conn, "SELECT bp.*, t.id tasker_no, t.specialization, t.experience, t.availability, t.availability_note, t.quality_rating,
        t.status tasker_status, t.status_reason, t.preferred, t.preferred_reason, t.notes, t.created_at tasker_since
        FROM business_parties bp JOIN dl_taskers t ON t.party_id = bp.id WHERE bp.type = 'Tasker' AND bp.id = " . $partyId));
    if (!$r) { return null; }
    $r['code'] = dl_code((int) $r['tasker_no']);
    $r['display'] = dl_tasker_name($r);
    return $r;
}
function dl_tasker_name(array $t): string
{
    $n = trim((string) ($t['business_name'] ?? '')) ?: trim((string) ($t['name'] ?? ''));
    return $n !== '' ? $n : 'RM Tasker #' . (int) ($t['id'] ?? 0);
}

/** Delegation settings of an offering (defaults when never set). */
function dl_offering(mysqli $conn, int $productId): array
{
    $r = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM dl_offering_settings WHERE product_id = ' . $productId));
    $d = ['product_id' => $productId, 'fulfilment' => 'Internal', 'internal_capability' => 'Yes', 'delegation_allowed' => 0, 'expected_rate' => null, 'std_hours' => null, 'max_hours' => null,
        'urgent_hours' => null, 'quality_requirements' => null, 'customer_verification' => 0, 'reminder_hours' => null, 'internalization_plan' => 'None', 'internalization_note' => null, 'materials' => null, 'configured' => false];
    if ($r) { $r['configured'] = true; return $r; }
    return $d;
}
function dl_needs_tasker(array $o): bool { return $o['fulfilment'] === 'External Only' || ($o['fulfilment'] === 'Delegatable' && (int) $o['delegation_allowed']); }
function dl_fulfilment_badge(string $f): string
{
    $c = ['Internal' => '#0B7A50;background:#E1F7EE', 'Delegatable' => '#1E2FE0;background:#E8EDFD', 'External Only' => '#8A5A00;background:#FFF4D6', 'Not Available' => '#B42318;background:#FCEAEA'][$f] ?? '#4B5263;background:#F1F2F6';
    return '<span style="font-size:11px;font-weight:600;padding:2px 8px;border-radius:20px;white-space:nowrap;color:' . $c . '">' . dl_e($f) . '</span>';
}

/**
 * History of a Tasker from completed delegations (filled by the delegation workflow). Returns per party_id:
 * done, ontime (0–1), accepted (0–1), quality (1–5 average of verified ratings), complaints (upheld), assigned.
 */
function dl_tasker_history(mysqli $conn): array
{
    static $cache = null;
    if ($cache !== null) { return $cache; }
    $cache = [];
    try {
        $has = mysqli_query($conn, "SHOW TABLES LIKE 'dl_delegations'");
        if (!$has || !mysqli_num_rows($has)) { return $cache; }
        $rows = mysqli_fetch_all(mysqli_query($conn, "SELECT party_id,
                SUM(CASE WHEN verified_at IS NOT NULL THEN 1 ELSE 0 END) done,
                SUM(CASE WHEN verified_at IS NOT NULL AND completed_at IS NOT NULL AND completed_at <= deadline THEN 1 ELSE 0 END) ontime,
                SUM(CASE WHEN response IN ('Accepted', 'Staff-confirmed') THEN 1 ELSE 0 END) accepted,
                SUM(CASE WHEN response IS NOT NULL THEN 1 ELSE 0 END) responded,
                AVG(CASE WHEN verified_at IS NOT NULL THEN perf_score END) perf,
                AVG(CASE WHEN verified_at IS NOT NULL THEN quality_rating END) quality,
                COUNT(*) assigned
            FROM dl_delegations WHERE party_id IS NOT NULL AND status NOT IN ('Draft', 'Cancelled') GROUP BY party_id"), MYSQLI_ASSOC);
        foreach ($rows as $r) {
            $done = (int) $r['done'];
            $cache[(int) $r['party_id']] = ['done' => $done, 'ontime' => $done ? (int) $r['ontime'] / $done : null, 'accepted' => (int) $r['responded'] ? (int) $r['accepted'] / (int) $r['responded'] : null,
                'quality' => $r['quality'] !== null ? (float) $r['quality'] : null, 'perf' => $r['perf'] !== null ? (float) $r['perf'] : null, 'assigned' => (int) $r['assigned'], 'complaints' => 0];
        }
        foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT party_id, COUNT(*) n FROM dl_issues WHERE responsibility = 'Tasker' GROUP BY party_id"), MYSQLI_ASSOC) as $r) {
            if (isset($cache[(int) $r['party_id']])) { $cache[(int) $r['party_id']]['complaints'] = (int) $r['n']; }
        }
    } catch (Throwable $e) { /* the delegation tables arrive with the workflow */ }
    return $cache;
}

/**
 * Taskers who can provide an offering, scored. $price = the customer price, when known (checks min / max).
 * Returns ['eligible' => [...sorted best first], 'other' => [...with reason], 'picks' => [cost, speed, quality, reliability, best], 'weights' => [...]].
 */
function dl_candidates(mysqli $conn, int $productId, ?float $price = null): array
{
    $today = date('Y-m-d');
    $rows = mysqli_fetch_all(mysqli_query($conn, "SELECT o.*, t.id tasker_no, t.availability t_availability, t.quality_rating, t.status t_status, t.preferred, t.specialization,
            bp.business_name, bp.name, bp.phone, bp.email, bp.district, bp.is_active, bp.id party
        FROM dl_tasker_offerings o JOIN dl_taskers t ON t.party_id = o.party_id JOIN business_parties bp ON bp.id = o.party_id
        WHERE o.product_id = $productId AND bp.type = 'Tasker'"), MYSQLI_ASSOC);
    $hist = dl_tasker_history($conn);
    $minHist = max(1, (int) dl_setting($conn, 'min_history'));
    $w = [];
    foreach (['cost', 'speed', 'quality', 'reliability', 'availability'] as $k) { $w[$k] = max(0, (float) dl_setting($conn, 'w_' . $k)); }
    $wsum = array_sum($w) ?: 100;
    $eligible = []; $other = [];
    foreach ($rows as $r) {
        $r['code'] = dl_code((int) $r['tasker_no']);
        $r['display'] = dl_tasker_name($r);
        $why = [];
        if ($r['t_status'] !== 'Active') { $why[] = 'RM Tasker ' . strtolower($r['t_status']); }
        if (!(int) $r['is_active']) { $why[] = 'business party deactivated'; }
        if ($r['status'] !== 'Active') { $why[] = 'this service is inactive for the Tasker'; }
        if ($r['valid_from'] && $r['valid_from'] > $today) { $why[] = 'rate valid from ' . date('d M Y', strtotime($r['valid_from'])); }
        if ($r['valid_to'] && $r['valid_to'] < $today) { $why[] = 'rate expired on ' . date('d M Y', strtotime($r['valid_to'])); }
        if ($price !== null && $r['min_price'] !== null && $price < (float) $r['min_price']) { $why[] = 'price below the Tasker minimum (' . dl_money($r['min_price']) . ')'; }
        if ($price !== null && $r['max_price'] !== null && (float) $r['max_price'] > 0 && $price > (float) $r['max_price']) { $why[] = 'price above the Tasker maximum (' . dl_money($r['max_price']) . ')'; }
        $avail = $r['availability'] === 'Unavailable' || $r['t_availability'] === 'Unavailable' ? 'Unavailable' : ($r['availability'] === 'Limited' || $r['t_availability'] === 'Limited' ? 'Limited' : 'Available');
        if ($avail === 'Unavailable') { $why[] = 'not available now'; }
        $h = $hist[(int) $r['party']] ?? ['done' => 0, 'ontime' => null, 'accepted' => null, 'quality' => null, 'perf' => null, 'assigned' => 0, 'complaints' => 0];
        $r['hist'] = $h;
        $r['limited'] = $h['done'] < $minHist;
        $r['avail'] = $avail;
        if ($why) { $r['why'] = implode('; ', $why); $other[] = $r; } else { $eligible[] = $r; }
    }
    // Neutral values for limited history = the average of experienced eligible Taskers (else 3.5 / 5 and 80 %).
    $expQ = []; $expR = [];
    foreach ($eligible as $r) { if (!$r['limited']) { if ($r['hist']['quality'] !== null) { $expQ[] = $r['hist']['quality']; } if ($r['hist']['ontime'] !== null) { $expR[] = $r['hist']['ontime'] * 0.7 + ($r['hist']['accepted'] ?? 1) * 0.3; } } }
    $neutralQ = $expQ ? array_sum($expQ) / count($expQ) : 3.5;
    $neutralR = $expR ? array_sum($expR) / count($expR) : 0.8;
    foreach ($eligible as &$r) {
        if ($r['limited']) {
            // The starting rating entered by RISE MOTIVE counts until there is enough history; without one, a neutral value (never a poor one).
            $r['q'] = $r['quality_rating'] !== null ? (float) $r['quality_rating'] : $neutralQ;
            $r['q_src'] = $r['quality_rating'] !== null ? 'starting rating' : 'neutral';
            $r['rel'] = $neutralR;
        } else {
            $r['q_src'] = 'verified';
            $r['q'] = $r['hist']['quality'] ?? ($r['quality_rating'] !== null ? (float) $r['quality_rating'] : $neutralQ);
            $r['rel'] = ($r['hist']['ontime'] ?? $neutralR) * 0.7 + ($r['hist']['accepted'] ?? 1) * 0.3;
        }
        $r['rate'] = (float) $r['rate_pct'];
        $r['hours'] = (float) $r['est_hours'] > 0 ? (float) $r['est_hours'] : null;
    }
    unset($r);
    $minRate = $eligible ? min(array_map(function ($r) { return max(0.01, $r['rate']); }, $eligible)) : 1;
    $hrs = array_filter(array_map(function ($r) { return $r['hours']; }, $eligible));
    $minH = $hrs ? min($hrs) : 1;
    $bonus = (float) dl_setting($conn, 'preferred_bonus');
    foreach ($eligible as &$r) {
        $s = ['cost' => $minRate / max(0.01, $r['rate']), 'speed' => $r['hours'] ? $minH / $r['hours'] : 0.5, 'quality' => $r['q'] / 5, 'reliability' => $r['rel'], 'availability' => $r['avail'] === 'Available' ? 1 : 0.5];
        $total = 0;
        foreach ($s as $k => $v) { $total += $w[$k] * max(0, min(1, $v)); }
        $r['parts'] = $s;
        $r['score'] = round($total / $wsum * 100 + ((int) $r['preferred'] ? $bonus : 0), 1);
    }
    unset($r);
    usort($eligible, function ($a, $b) { return $b['score'] <=> $a['score']; });
    $picks = [];
    if ($eligible) {
        $by = function ($f, $dir = 1) use ($eligible) { $l = $eligible; usort($l, function ($a, $b) use ($f, $dir) { return $dir * ($f($a) <=> $f($b)); }); return $l[0]; };
        $picks['cost'] = $by(function ($r) { return $r['rate']; });
        $picks['speed'] = $by(function ($r) { return $r['hours'] ?? 1e9; });
        // Highest quality / most reliable: only among Taskers with a real basis (verified history, or a starting rating for quality).
        $qBase = array_values(array_filter($eligible, function ($r) { return $r['q_src'] !== 'neutral'; }));
        $rBase = array_values(array_filter($eligible, function ($r) { return !$r['limited']; }));
        $pick = function ($list, $f) { if (!$list) { return null; } usort($list, function ($a, $b) use ($f) { return $f($b) <=> $f($a); }); return $list[0]; };
        $picks['quality'] = $pick($qBase, function ($r) { return ($r['q_src'] === 'verified' ? 10 : 0) + $r['q']; });
        $picks['reliability'] = $pick($rBase, function ($r) { return $r['rel']; });
        $picks['best'] = $eligible[0];
    }
    return ['eligible' => $eligible, 'other' => $other, 'picks' => $picks, 'weights' => $w];
}

/** Why the best overall was recommended, in plain words. */
function dl_explain(array $c): string
{
    if (empty($c['picks']['best'])) { return ''; }
    $b = $c['picks']['best'];
    $n = count($c['eligible']);
    $p = [];
    $p[] = dl_pct($b['rate']) . ($c['picks']['cost']['party'] === $b['party'] ? ' (the lowest rate)' : ' (lowest is ' . dl_pct($c['picks']['cost']['rate']) . ')');
    $p[] = ($b['hours'] ? dl_hours_text($b['hours']) : 'time not given') . ($c['picks']['speed']['party'] === $b['party'] ? ' (the fastest)' : '');
    $p[] = 'quality ' . number_format($b['q'], 1) . '/5' . ($b['q_src'] === 'neutral' ? ' (new / limited history: neutral value)' : ($b['q_src'] === 'starting rating' ? ' (starting rating, limited history)' : ''));
    if (!$b['limited']) { $p[] = 'on time ' . round(($b['hist']['ontime'] ?? 0) * 100) . '% of ' . $b['hist']['done'] . ' completed'; }
    if ((int) $b['preferred']) { $p[] = 'Preferred RM Tasker'; }
    return $b['display'] . ' gives the best balance of the ' . $n . ' eligible RM Tasker' . ($n === 1 ? '' : 's') . ': ' . implode(', ', $p) . '. Score ' . $b['score'] . '/100.';
}

/**
 * Profit sharing (section 9). basis 'cogs': pool = revenue − cost of sales; 'cogs_direct': also minus other direct
 * costs. The Tasker's share is ALWAYS taken from the pool, never from the full price. Other direct costs not in
 * the pool are still deducted for the final profit.
 */
function dl_profit(float $revenue, float $cogs, float $direct, float $ratePct, string $basis): array
{
    $pool = $revenue - $cogs - ($basis === 'cogs_direct' ? $direct : 0);
    $pay = $pool > 0 ? round($pool * $ratePct / 100, 2) : 0.0;
    $retained = $pool - $pay;
    $final = $retained - ($basis === 'cogs_direct' ? 0 : $direct);
    return ['revenue' => $revenue, 'cogs' => $cogs, 'direct' => $direct, 'pool' => round($pool, 2), 'rate' => $ratePct, 'tasker' => $pay, 'retained' => round($retained, 2),
        'final' => round($final, 2), 'margin' => $revenue > 0 ? round($final / $revenue * 100, 1) : 0.0, 'basis' => $basis];
}
function dl_basis_text(string $b): string { return $b === 'cogs_direct' ? 'Revenue − cost of sales − other direct costs' : 'Revenue − cost of sales (other direct costs deducted after the Tasker share)'; }

/** Materials of an offering (cost of sales per unit sold): [{product_id, qty}] with current buying prices. */
function dl_materials(mysqli $conn, array $o): array
{
    $list = json_decode((string) ($o['materials'] ?? ''), true) ?: [];
    $out = [];
    foreach ($list as $m) {
        $p = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT id, product_name, unit, buying_price, quantity FROM products WHERE id = ' . (int) $m['product_id']));
        if ($p) { $out[] = ['product_id' => (int) $p['id'], 'name' => $p['product_name'], 'unit' => $p['unit'], 'qty' => (float) $m['qty'], 'cost' => (float) $p['buying_price'], 'stock' => (float) $p['quantity']]; }
    }
    return $out;
}
function dl_materials_cost(array $mats, float $units = 1): float { $t = 0; foreach ($mats as $m) { $t += $m['qty'] * $m['cost'] * $units; } return round($t, 2); }