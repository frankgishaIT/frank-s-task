<?php
/**
 * NEW FILE: includes/best_employee.php — BEST EMPLOYEE OF THE MONTH (Task 2, part B).
 *
 * Evaluation (from the approved KPI system, Performance & KPIs):
 *  - Each employee is scored on THEIR OWN assigned KPIs (KPI level "Employee", status Active or Completed,
 *    valid during the month). Draft and Cancelled KPIs never count.
 *  - Each KPI's achievement = actual vs target, by its direction (higher / lower / range), capped at 120%
 *    (kpi_achievement(), the same as Performance & KPIs). The score = the weighted average by the KPI weights.
 *  - Monthly KPIs use that month; daily / weekly KPIs the average of their periods in the month;
 *    quarterly / annual KPIs their result so far in the period containing the month.
 *  - Only KPIs with a recorded result count (automatic data, or an entered actual value).
 *  - Attendance alone never wins: an employee needs at least one measured KPI that is NOT attendance-based.
 *  - Ties: 1) higher score, 2) more KPIs at or above target, 3) more total KPI weight measured (broader
 *    responsibilities), 4) higher attendance rate that month. Still equal: they share the recognition.
 *  - Recognition period: the last COMPLETED month (final results). Until that month has a winner, the current
 *    month's leader is shown, marked "so far". Recalculated at most every 15 minutes, so the banner changes by
 *    itself when results change or a new month starts.
 */
require_once __DIR__ . '/kpi_helpers.php';

const BE_ATTENDANCE_SOURCES = ['attendance_rate', 'late_arrivals'];
const BE_CACHE_MINUTES = 15;

function be_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    kpi_ensure($conn);
    mysqli_query($conn, 'CREATE TABLE IF NOT EXISTS be_cache (period CHAR(7) NOT NULL, payload MEDIUMTEXT NOT NULL, computed_at DATETIME NOT NULL, PRIMARY KEY (period)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
}

/** Achievement of one KPI for a month (null = no result). */
function be_kpi_month_achievement(mysqli $conn, array $k, string $monthStart, string $monthEnd): ?float
{
    $freq = $k['frequency'];
    $starts = [];
    if ($freq === 'daily' || $freq === 'weekly') {
        $p = kpi_period_start($monthStart, $freq);
        if ($p < $monthStart) { $p = kpi_period_start(date('Y-m-d', strtotime($p . ($freq === 'weekly' ? ' +1 week' : ' +1 day'))), $freq); }
        while ($p <= min($monthEnd, date('Y-m-d'))) {
            $starts[] = $p;
            $p = date('Y-m-d', strtotime($p . ($freq === 'weekly' ? ' +1 week' : ' +1 day')));
        }
    } else {
        $starts[] = kpi_period_start($monthStart, $freq);
    }
    $vals = [];
    foreach ($starts as $s) {
        if ($s < kpi_period_start($k['start_date'], $freq) || ($k['end_date'] && $s > $k['end_date'])) { continue; }
        $a = kpi_achievement($k, kpi_actual($conn, $k, $s)['value']);
        if ($a !== null) { $vals[] = $a; }
    }
    return $vals ? round(array_sum($vals) / count($vals), 1) : null;
}

/** Everyone's score for a month, ranked: [['user_id', 'name', 'department', 'photo', 'score', 'kpis', ...]]. */
function be_compute(mysqli $conn, string $month): array
{
    $ms = $month . '-01'; $me = date('Y-m-t', strtotime($ms));
    $kpis = mysqli_fetch_all(mysqli_query($conn, "SELECT k.* FROM kpis k JOIN users u ON u.id = k.user_id
        WHERE k.level = 'employee' AND k.status IN ('Active', 'Completed') AND u.is_active = 1
          AND k.start_date <= '$me' AND (k.end_date IS NULL OR k.end_date >= '$ms')"), MYSQLI_ASSOC);
    $by = [];
    foreach ($kpis as $k) { $by[(int) $k['user_id']][] = $k; }
    if (!$by) { return []; }
    $photoCol = kpi_col_exists($conn, 'users', 'photo') ? 'u.photo' : 'NULL';
    $users = [];
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT u.id, u.names, $photoCol AS photo, d.name AS department FROM users u LEFT JOIN departments d ON d.id = u.department_id
        WHERE u.id IN (" . implode(',', array_keys($by)) . ')'), MYSQLI_ASSOC) as $u) { $users[(int) $u['id']] = $u; }
    $att = [];
    foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT user_id, COUNT(*) n, SUM(status IN ('Present', 'Late')) p FROM attendance
        WHERE attendance_date BETWEEN '$ms' AND '$me' AND status IN ('Present', 'Late', 'Absent') GROUP BY user_id"), MYSQLI_ASSOC) as $a) {
        $att[(int) $a['user_id']] = (int) $a['n'] ? round(100 * $a['p'] / $a['n'], 1) : null;
    }
    $rows = [];
    foreach ($by as $uid => $list) {
        if (!isset($users[$uid])) { continue; }
        $w = 0.0; $sum = 0.0; $met = 0; $measured = 0; $nonAttendance = 0; $detail = [];
        foreach ($list as $k) {
            $a = be_kpi_month_achievement($conn, $k, $ms, $me);
            $detail[] = ['name' => $k['name'], 'weight' => (float) $k['weight'], 'achievement' => $a];
            if ($a === null) { continue; }
            $measured++; $w += (float) $k['weight']; $sum += (float) $k['weight'] * $a;
            if ($a >= 100) { $met++; }
            if (!in_array($k['data_source'], BE_ATTENDANCE_SOURCES, true)) { $nonAttendance++; }
        }
        $score = $w > 0 ? round($sum / $w, 1) : null;
        $rows[] = ['user_id' => $uid, 'name' => $users[$uid]['names'], 'department' => $users[$uid]['department'], 'photo' => $users[$uid]['photo'],
            'score' => $score, 'kpis' => count($list), 'measured' => $measured, 'met' => $met, 'weight' => round($w, 2),
            'attendance' => $att[$uid] ?? null, 'eligible' => $score !== null && $nonAttendance > 0, 'detail' => $detail];
    }
    usort($rows, 'be_compare');
    $rank = 0; $prev = null;
    foreach ($rows as $i => &$r) {
        if (!$r['eligible']) { $r['rank'] = null; continue; }
        $rank = ($prev !== null && be_compare($prev, $r) === 0) ? $rank : $i + 1;
        $r['rank'] = $rank; $prev = $r;
    }
    return $rows;
}

/** Ranking order (eligible first), with the tie-break rules. */
function be_compare(array $a, array $b): int
{
    return [$b['eligible'], $b['score'] ?? -1, $b['met'], $b['weight'], $b['attendance'] ?? -1]
       <=> [$a['eligible'], $a['score'] ?? -1, $a['met'], $a['weight'], $a['attendance'] ?? -1];
}

/** Ranking of a month, from the cache when fresh (≤ 15 min). */
function be_ranking(mysqli $conn, string $month, bool $fresh = false): array
{
    be_ensure($conn);
    $s = mysqli_prepare($conn, 'SELECT payload, computed_at FROM be_cache WHERE period = ?');
    mysqli_stmt_bind_param($s, 's', $month);
    mysqli_stmt_execute($s);
    $c = mysqli_fetch_assoc(mysqli_stmt_get_result($s));
    if (!$fresh && $c && strtotime($c['computed_at']) > time() - 60 * BE_CACHE_MINUTES) {
        return json_decode($c['payload'], true) ?: [];
    }
    $rows = be_compute($conn, $month);
    $json = json_encode($rows);
    $now = date('Y-m-d H:i:s');
    $u = mysqli_prepare($conn, 'INSERT INTO be_cache (period, payload, computed_at) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE payload = VALUES(payload), computed_at = VALUES(computed_at)');
    mysqli_stmt_bind_param($u, 'sss', $month, $json, $now);
    mysqli_stmt_execute($u);
    return $rows;
}

/** The recognition shown now: ['month', 'final' => bool, 'winners' => [...], 'ranking' => [...]] or null. */
function be_current(mysqli $conn): ?array
{
    $last = date('Y-m', strtotime(date('Y-m-01') . ' -1 month'));
    foreach ([[$last, true], [date('Y-m'), false]] as [$m, $final]) {
        $rank = be_ranking($conn, $m);
        $winners = array_values(array_filter($rank, function ($r) { return $r['rank'] === 1; }));
        if ($winners) { return ['month' => $m, 'final' => $final, 'winners' => $winners, 'ranking' => $rank]; }
    }
    return null;
}

function be_photo_url(?string $photo): ?string
{
    return $photo ? rtrim((string) BASE_URL, '/') . '/assets/uploads/users/' . rawurlencode(basename($photo)) : null;
}

/** The banner (Dashboard for everyone, and the Employees Dashboard). Safe: never breaks the page. */
function be_banner(mysqli $conn): void
{
    try { $cur = be_current($conn); } catch (Throwable $e) { error_log('best employee: ' . $e->getMessage()); return; }
    if (!$cur) { return; }
    $label = date('F Y', strtotime($cur['month'] . '-01'));
    $multi = count($cur['winners']) > 1;
    $e = function ($v) { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
    $pct = function ($v) { return rtrim(rtrim(number_format((float) $v, 1), '0'), '.') . '%'; };
    $isMe = in_array((int) ($_SESSION['user_id'] ?? 0), array_column($cur['winners'], 'user_id'), true);
    $next = array_slice(array_values(array_filter($cur['ranking'], function ($r) { return $r['rank'] !== null && $r['rank'] > 1; })), 0, 3);
    $base = rtrim((string) BASE_URL, '/');
    ?>
<style>
.be-banner{ position:relative; overflow:hidden; border-radius:16px; padding:14px 18px 12px; margin-bottom:16px; color:#fff;
    background:linear-gradient(115deg,#0D2A9E 0%,#1A44D6 45%,#2F6FEF 80%,#3B82F6 100%); box-shadow:0 12px 30px rgba(30,64,200,.25); }
.be-banner .be-deco1{ position:absolute; right:-60px; top:-90px; width:220px; height:220px; border-radius:50%; background:rgba(255,255,255,.07); }
.be-banner .be-deco2{ position:absolute; left:30%; bottom:-140px; width:220px; height:220px; border-radius:50%; background:rgba(94,234,212,.10); }
.be-banner > *:not(.be-deco1):not(.be-deco2):not(.be-confetti){ position:relative; }
.be-trophy{ width:32px; height:32px; border-radius:9px; background:#fff; color:#0E9F6E; display:inline-flex; align-items:center; justify-content:center; font-size:16px;
    box-shadow:0 4px 10px rgba(0,0,0,.15); animation:beBounce 2.6s ease-in-out infinite; flex-shrink:0; }
@keyframes beBounce{ 0%,100%{transform:translateY(0) rotate(-3deg);} 50%{transform:translateY(-5px) rotate(3deg);} }
.be-kicker{ letter-spacing:.12em; font-size:11.5px; font-weight:900; color:#6EE7B7; text-transform:uppercase; }
.be-ring{ width:72px; height:72px; border-radius:50%; padding:4px; background:conic-gradient(#5EEAD4,#3B82F6,#A5B4FC,#5EEAD4); flex-shrink:0;
    box-shadow:0 0 0 4px rgba(255,255,255,.10); }
.be-ring > *{ width:100%; height:100%; border-radius:50%; background:#fff; border:2px solid #fff; object-fit:cover; display:flex; align-items:center; justify-content:center;
    color:#1E3FD0; font-size:22px; font-weight:900; }
.be-name{ font-size:1.25rem; font-weight:900; line-height:1.1; }
.be-dept{ font-size:.85rem; opacity:.9; }
.be-score{ font-size:1.75rem; font-weight:900; color:#6EE7B7; line-height:1; }
.be-scorelbl{ font-size:.75rem; line-height:1.2; opacity:.92; }
.be-bar{ height:6px; border-radius:6px; background:rgba(255,255,255,.18); max-width:340px; overflow:hidden; }
.be-bar > div{ height:100%; border-radius:10px; background:linear-gradient(90deg,#34D399,#A7F3D0); }
.be-chip{ display:inline-flex; align-items:center; gap:7px; border:1px solid rgba(255,255,255,.35); background:rgba(255,255,255,.10); border-radius:20px; padding:2px 10px;
    font-size:12px; font-weight:600; margin:0 5px 4px 0; }
.be-top{ background:#fff; color:#1E2333; border-radius:12px; padding:9px 14px 4px; min-width:240px; box-shadow:0 8px 20px rgba(0,0,0,.10); }
.be-top-title{ color:#1E4FE0; font-weight:800; letter-spacing:.1em; font-size:10.5px; margin-bottom:1px; }
.be-top-row{ display:flex; align-items:center; gap:9px; padding:5px 0; font-size:13px; border-top:1px solid #E6EAF2; }
.be-top-title + .be-top-row{ border-top:0; }
.be-num{ width:20px; height:20px; border-radius:50%; background:#3B6EF0; color:#fff; font-weight:800; font-size:10.5px; display:inline-flex; align-items:center; justify-content:center; flex-shrink:0; }
.be-num.r2{ background:#0E9F6E; }
.be-top-row .be-pct{ margin-left:auto; color:#0E9F6E; font-weight:800; }
.be-foot{ font-size:11.5px; opacity:.85; }
.be-foot a{ color:#fff; font-weight:700; }
.be-confetti span{ position:absolute; top:-12px; width:9px; height:12px; opacity:.85; animation:beFall linear infinite; border-radius:2px; background:#fff; }
@keyframes beFall{ 0%{transform:translateY(0) rotate(0);} 100%{transform:translateY(300px) rotate(540deg);} }
@media (prefers-reduced-motion: reduce){ .be-trophy,.be-confetti span{ animation:none; } .be-confetti{ display:none; } }
@media (max-width: 992px){ .be-top{ min-width:0; width:100%; } }
@media (max-width: 576px){ .be-banner{ padding:12px; } .be-ring{ width:58px; height:58px; padding:3px; } .be-ring > *{ font-size:18px; }
    .be-name{ font-size:1.1rem; } .be-score{ font-size:1.5rem; } .be-kicker{ font-size:10.5px; } }
</style>
<div class="be-banner" role="region" aria-label="Best Employee of the Month">
    <div class="be-deco1"></div><div class="be-deco2"></div>
    <div class="be-confetti" aria-hidden="true"><?php foreach ([['22%', 0, 6], ['37%', 1.4, 7], ['55%', 2.2, 5.5], ['62%', .5, 6.5], ['78%', 1.8, 7.5], ['88%', 3, 6]] as [$l, $d, $t]) { ?><span style="left:<?= $l; ?>;animation-delay:<?= $d; ?>s;animation-duration:<?= $t; ?>s;"></span><?php } ?></div>

    <div class="d-flex align-items-center gap-2 mb-2">
        <span class="be-trophy"><i class="bi bi-trophy-fill"></i></span>
        <div>
            <div class="be-kicker"><?= $multi ? 'Best Employees of the Month' : 'Best Employee of the Month'; ?> · <?= $e($label); ?><?= $cur['final'] ? '' : ' (so far)'; ?></div>
            <?php if ($isMe) { ?><div class="small" style="color:#FDE68A;">Congratulations, this is you! 🎉</div><?php } ?>
        </div>
    </div>

    <div class="d-flex flex-wrap align-items-center gap-3">
        <div class="d-flex flex-column gap-2 flex-grow-1">
        <?php foreach ($cur['winners'] as $w) { $url = be_photo_url($w['photo']); $sc = min(100, max(0, (float) $w['score'])); ?>
            <div class="d-flex flex-wrap align-items-center gap-3">
                <div class="be-ring"><?php if ($url) { ?><img src="<?= $e($url); ?>" alt="<?= $e($w['name']); ?>"><?php } else { $p = preg_split('/\s+/', trim($w['name'])); ?><span><?= $e(strtoupper(mb_substr($p[0] ?? '', 0, 1) . mb_substr($p[1] ?? '', 0, 1))); ?></span><?php } ?></div>
                <div style="min-width:0; flex:1;">
                    <div class="be-name"><?= $e($w['name']); ?></div>
                    <div class="be-dept"><i class="bi bi-building"></i> <?= $e($w['department'] ?: 'No department'); ?></div>
                    <div class="d-flex align-items-center gap-2 mt-1"><div class="be-score"><?= $e($pct($w['score'])); ?></div><div class="be-scorelbl">performance<br>achievement</div></div>
                    <div class="be-bar mt-1" title="<?= $e($pct($w['score'])); ?> of target"><div style="width:<?= $sc; ?>%;"></div></div>
                    <div class="mt-2"><span class="be-chip"><i class="bi bi-bullseye"></i> <?= (int) $w['met']; ?> of <?= (int) $w['measured']; ?> KPIs on target</span><span class="be-chip"><i class="bi bi-calendar3"></i> <?= $e($label); ?></span></div>
                </div>
            </div>
        <?php } ?>
        </div>
        <?php if ($next) { ?>
        <div class="be-top ms-lg-auto">
            <div class="be-top-title">TOP PERFORMERS</div>
            <?php foreach ($next as $r) { ?>
                <div class="be-top-row"><span class="be-num<?= (int) $r['rank'] === 2 ? ' r2' : ''; ?>"><?= (int) $r['rank']; ?></span><span><?= $e($r['name']); ?></span><span class="be-pct"><?= $e($pct($r['score'])); ?></span></div>
            <?php } ?>
        </div>
        <?php } ?>
    </div>

    <div class="be-foot mt-2">Chosen automatically from approved KPI results (weighted achievement vs targets)<?= $cur['final'] ? '' : '; final when the month ends'; ?>.
        <?php if (in_array($_SESSION['user_role'] ?? '', ['Admin', 'Manager'], true)) { ?><a href="<?= $e($base); ?>/modules/users/analytics.php#ranking">See the ranking</a><?php } else { ?><a href="<?= $e($base); ?>/modules/kpi/my.php">See my performance</a><?php } ?></div>
</div>
    <?php
}