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
    $isMe = in_array((int) ($_SESSION['user_id'] ?? 0), array_column($cur['winners'], 'user_id'), true);
    ?>
<style>
.be-banner{ position:relative; overflow:hidden; border-radius:20px; padding:22px 24px; margin-bottom:22px; color:#fff;
    background:linear-gradient(120deg,#0B1A8C 0%,#1E2FE0 45%,#7C5CE0 100%); box-shadow:0 10px 30px rgba(30,47,224,.25); }
.be-banner:before{ content:''; position:absolute; inset:0; background:linear-gradient(105deg,transparent 35%,rgba(255,255,255,.18) 50%,transparent 65%); transform:translateX(-100%); animation:beShine 4.5s ease-in-out infinite; }
@keyframes beShine{ 0%,55%{transform:translateX(-100%);} 100%{transform:translateX(100%);} }
.be-trophy{ font-size:44px; color:#F7C948; filter:drop-shadow(0 4px 10px rgba(247,201,72,.5)); animation:beBounce 2.4s ease-in-out infinite; display:inline-block; }
@keyframes beBounce{ 0%,100%{transform:translateY(0) rotate(-4deg);} 50%{transform:translateY(-8px) rotate(4deg);} }
.be-kicker{ letter-spacing:.14em; font-size:12px; font-weight:800; color:#F7C948; text-transform:uppercase; }
.be-name{ font-size:1.6rem; font-weight:800; line-height:1.15; }
.be-photo{ width:96px; height:96px; border-radius:50%; object-fit:cover; border:4px solid #F7C948; box-shadow:0 0 0 6px rgba(247,201,72,.25); background:#fff; }
.be-initials{ width:96px; height:96px; border-radius:50%; display:inline-flex; align-items:center; justify-content:center; font-size:34px; font-weight:800; background:rgba(255,255,255,.15); border:4px solid #F7C948; box-shadow:0 0 0 6px rgba(247,201,72,.25); }
.be-score{ font-size:2.4rem; font-weight:900; color:#F7C948; line-height:1; }
.be-chip{ display:inline-block; background:rgba(255,255,255,.16); border-radius:20px; padding:3px 11px; font-size:12.5px; margin:4px 6px 0 0; }
.be-confetti span{ position:absolute; top:-12px; width:8px; height:14px; opacity:.85; animation:beFall linear infinite; border-radius:2px; }
@keyframes beFall{ 0%{transform:translateY(0) rotate(0);} 100%{transform:translateY(260px) rotate(540deg);} }
@media (prefers-reduced-motion: reduce){ .be-banner:before,.be-trophy,.be-confetti span{ animation:none; display:none; } }
@media (max-width: 576px){ .be-name{font-size:1.25rem;} .be-score{font-size:1.9rem;} .be-photo,.be-initials{width:72px;height:72px;font-size:26px;} .be-trophy{font-size:34px;} }
</style>
<div class="be-banner" role="region" aria-label="Best Employee of the Month">
    <div class="be-confetti" aria-hidden="true"><?php foreach ([['5%', '#F7C948', 0, 5], ['18%', '#0FA968', 1.2, 6], ['33%', '#fff', 2.1, 5.5], ['52%', '#F7C948', .6, 7], ['68%', '#E24B4A', 1.8, 6.5], ['82%', '#fff', 3, 5], ['93%', '#0FA968', .3, 6]] as [$l, $c, $d, $t]) { ?><span style="left:<?= $l; ?>;background:<?= $c; ?>;animation-delay:<?= $d; ?>s;animation-duration:<?= $t; ?>s;"></span><?php } ?></div>
    <div class="d-flex flex-wrap align-items-center gap-3 position-relative">
        <div class="be-trophy"><i class="bi bi-trophy-fill"></i></div>
        <div class="flex-grow-1" style="min-width:200px;">
            <div class="be-kicker"><?= $multi ? 'Best Employees of the Month' : 'Best Employee of the Month'; ?> · <?= $e($label); ?><?= $cur['final'] ? '' : ' (so far)'; ?></div>
            <?php if ($isMe) { ?><div class="small" style="color:#FFE9A8;">Congratulations — this is you! 🎉</div><?php } ?>
        </div>
    </div>
    <div class="d-flex flex-wrap gap-4 mt-3 position-relative">
        <?php foreach ($cur['winners'] as $w) { $url = be_photo_url($w['photo']); ?>
        <div class="d-flex align-items-center gap-3" style="min-width:260px;">
            <?php if ($url) { ?><img class="be-photo" src="<?= $e($url); ?>" alt="<?= $e($w['name']); ?>"><?php } else { $p = preg_split('/\s+/', trim($w['name'])); ?><span class="be-initials"><?= $e(strtoupper(mb_substr($p[0] ?? '', 0, 1) . mb_substr($p[1] ?? '', 0, 1))); ?></span><?php } ?>
            <div>
                <div class="be-name"><?= $e($w['name']); ?></div>
                <div style="opacity:.9;"><i class="bi bi-building"></i> <?= $e($w['department'] ?: 'No department'); ?></div>
                <div class="d-flex align-items-end gap-2 mt-1"><div class="be-score"><?= $e(rtrim(rtrim(number_format((float) $w['score'], 1), '0'), '.')); ?>%</div><div class="small pb-1" style="opacity:.9;">performance<br>achievement</div></div>
                <div><span class="be-chip"><i class="bi bi-bullseye"></i> <?= (int) $w['met']; ?> of <?= (int) $w['measured']; ?> KPIs on target</span><span class="be-chip"><i class="bi bi-calendar-check"></i> <?= $e($label); ?></span></div>
            </div>
        </div>
        <?php } ?>
        <?php $next = array_slice(array_values(array_filter($cur['ranking'], function ($r) { return $r['rank'] !== null && $r['rank'] > 1; })), 0, 3);
        if ($next) { ?>
        <div class="ms-lg-auto" style="min-width:220px;">
            <div class="be-kicker mb-1" style="color:#fff;opacity:.85;">Top performers</div>
            <?php foreach ($next as $r) { ?><div class="d-flex justify-content-between gap-3 py-1" style="border-bottom:1px solid rgba(255,255,255,.15);"><span><strong>#<?= (int) $r['rank']; ?></strong> <?= $e($r['name']); ?></span><strong style="color:#F7C948;"><?= $e(rtrim(rtrim(number_format((float) $r['score'], 1), '0'), '.')); ?>%</strong></div><?php } ?>
        </div>
        <?php } ?>
    </div>
    <div class="small mt-3 position-relative" style="opacity:.8;">Chosen automatically from approved KPI results (weighted achievement vs targets)<?= $cur['final'] ? '' : '; final when the month ends'; ?>.
        <?php if (in_array($_SESSION['user_role'] ?? '', ['Admin', 'Manager'], true)) { ?><a href="<?= $e(rtrim((string) BASE_URL, '/')); ?>/modules/users/analytics.php#ranking" class="text-white fw-semibold">See the ranking</a><?php } else { ?><a href="<?= $e(rtrim((string) BASE_URL, '/')); ?>/modules/kpi/my.php" class="text-white fw-semibold">See my performance</a><?php } ?></div>
</div>
    <?php
}