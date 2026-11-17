<?php
/**
 * NEW FILE: modules/learning/reports.php — LEARNING CENTRE REPORTS from real MY MOTIVE data only:
 * Marketing & applications · Academic performance · Finance (read from Sales, never re-counted) · Accommodation · Internships.
 * Small numbers are shown with their count and flagged; nothing is estimated. Profitability is NOT shown because costs
 * are not assigned to courses or branches in MY MOTIVE (showing it would be misleading).
 */
require __DIR__ . '/common.php';
require_once __DIR__ . '/../../includes/lc_accommodation.php';
require_once __DIR__ . '/../../includes/lc_internship.php';
lcs_require($conn, 'view');
lc_acc_ensure($conn); lc_int_ensure($conn);
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-01-01');
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d');
$fb = isset($_GET['branch']) && $_GET['branch'] !== '' ? (int) $_GET['branch'] : null;
$sec = in_array($_GET['s'] ?? '', ['marketing', 'academic', 'finance', 'accommodation', 'internships'], true) ? $_GET['s'] : 'marketing';
$canFin = lcs_can($conn, 'finance');
if ($sec === 'finance' && !$canFin) { $sec = 'marketing'; }
$bw = function (string $col) use ($conn, $fb) { return lcs_branch_sql($conn, $col) . ($fb !== null ? ' AND COALESCE(' . $col . ', ' . (br_enabled($conn) ? br_main_id($conn) : 0) . ') = ' . $fb : ''); };
$F = "'" . $from . "'"; $T = "'" . $to . " 23:59:59'";
$csv = [];   // [section, label, value]
$rate = function ($num, $den) { return $den > 0 ? round($num / $den * 100, 1) : null; };
$pct = function ($v, $n = null) { if ($v === null) { return '<span class="text-muted">—</span>'; } return number_format($v, 1) . '%' . ($n !== null && $n < 10 ? ' <span class="badge bg-light text-muted border" title="Fewer than 10: read with care">n=' . $n . '</span>' : ''); };
$bar = function ($v, $max) { $w = $max > 0 ? max(2, round($v / $max * 120)) : 0; return '<div style="background:#E8ECF8;border-radius:4px;height:8px;width:120px;display:inline-block"><div style="background:#1E2FE0;height:8px;border-radius:4px;width:' . $w . 'px"></div></div>'; };
$table = function (string $title, array $head, array $rows, string $key) use (&$csv) {
    echo '<div class="card border-0 shadow-sm mb-3"><div class="card-body p-0"><div class="p-2 fw-semibold">' . lc_e($title) . '</div><table class="table table-sm align-middle mb-0" style="font-size:12.5px;"><tr class="table-light">';
    foreach ($head as $i => $h) { echo '<th class="' . ($i ? 'text-end' : 'ps-3') . '">' . lc_e($h) . '</th>'; }
    echo '</tr>';
    foreach ($rows as $r) { echo '<tr>'; foreach (array_values($r) as $i => $c) { echo '<td class="' . ($i ? 'text-end' : 'ps-3') . '">' . $c . '</td>'; } echo '</tr>'; $csv[] = array_merge([$key], array_map(function ($x) { return trim(strip_tags(html_entity_decode((string) $x))); }, array_values($r))); }
    if (!$rows) { echo '<tr><td colspan="' . count($head) . '" class="ps-3 text-muted">No data for this period.</td></tr>'; }
    echo '</table></div></div>';
};
ob_start();
/* =============================================================== MARKETING & APPLICATIONS */
if ($sec === 'marketing') {
    $aw = "a.status <> 'Draft' AND COALESCE(a.submitted_at, a.created_at) BETWEEN $F AND $T AND " . $bw('a.branch_id');
    $tot = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM lc_applications a WHERE $aw"))[0];
    $acc = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM lc_applications a WHERE $aw AND a.status = 'Accepted'"))[0];
    $offer = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM lc_applications a WHERE $aw AND a.status = 'Accepted' AND a.offer_status = 'Accepted'"))[0];
    $reg = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM lc_applications a WHERE $aw AND a.registered_at IS NOT NULL"))[0];
    $rej = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM lc_applications a WHERE $aw AND a.status = 'Rejected'"))[0];
    $wd = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM lc_applications a WHERE $aw AND a.status = 'Withdrawn'"))[0];
    $views = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COALESCE(SUM(views), 0) FROM lc_views WHERE day BETWEEN $F AND '$to'"))[0];
    $k = ['Course page views' => $views, 'Applications' => $tot, 'Admitted' => $acc, 'Offer accepted' => $offer, 'Registered' => $reg, 'Rejected' => $rej, 'Withdrawn' => $wd];
    echo '<div class="row g-2 mb-3">'; foreach ($k as $l => $v) { echo '<div class="col-6 col-md"><div class="card border-0 shadow-sm lc-k"><div class="card-body py-2"><div class="lbl">' . $l . '</div><div class="val">' . number_format($v) . '</div></div></div></div>'; $csv[] = ['summary', $l, $v]; } echo '</div>';
    echo '<div class="alert alert-light border small py-2">Conversion: application → admission ' . $pct($rate($acc, $tot), $tot) . ' · admission → registration ' . $pct($rate($reg, $acc), $acc) . '. Page views count public course pages only (no cookies or personal data); applications also come from the Customer Portal and staff, so views and applications are not compared.</div>';
    $src = mysqli_fetch_all(mysqli_query($conn, "SELECT a.source, COUNT(*) n, SUM(a.status = 'Accepted') acc, SUM(a.registered_at IS NOT NULL) reg FROM lc_applications a WHERE $aw GROUP BY a.source ORDER BY n DESC"), MYSQLI_ASSOC);
    $vs = []; foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT source, SUM(views) v FROM lc_views WHERE day BETWEEN $F AND '$to' GROUP BY source"), MYSQLI_ASSOC) as $r) { $vs[$r['source']] = (int) $r['v']; }
    $mx = max([1] + array_column($src, 'n'));
    $table('Application sources', ['Source', 'Page views', 'Applications', '', 'Admitted', 'Registered', 'Admission rate'], array_map(function ($r) use ($vs, $bar, $mx, $pct, $rate) {
        return [lc_e(LC_SOURCES[$r['source']] ?? $r['source']), number_format($vs[$r['source']] ?? 0), (int) $r['n'], $bar((int) $r['n'], $mx), (int) $r['acc'], (int) $r['reg'], $pct($rate((int) $r['acc'], (int) $r['n']), (int) $r['n'])]; }, $src), 'sources');
    $crs = mysqli_fetch_all(mysqli_query($conn, "SELECT c.code, c.title, c.id, COUNT(*) n, SUM(a.status = 'Accepted') acc, SUM(a.registered_at IS NOT NULL) reg, SUM(a.status = 'Rejected') rej, SUM(a.status = 'Withdrawn') wd
        FROM lc_applications a JOIN lc_courses c ON c.id = a.course_id WHERE $aw GROUP BY c.id, c.code, c.title ORDER BY n DESC"), MYSQLI_ASSOC);
    $cv = []; foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT course_id, SUM(views) v FROM lc_views WHERE day BETWEEN $F AND '$to' GROUP BY course_id"), MYSQLI_ASSOC) as $r) { $cv[(int) $r['course_id']] = (int) $r['v']; }
    $table('Applications by course', ['Course', 'Page views', 'Applications', 'Admitted', 'Registered', 'Rejected', 'Withdrawn', 'Admission rate'], array_map(function ($r) use ($cv, $pct, $rate) {
        return [lc_e($r['code'] . ' ' . $r['title']), number_format($cv[(int) $r['id']] ?? 0), (int) $r['n'], (int) $r['acc'], (int) $r['reg'], (int) $r['rej'], (int) $r['wd'], $pct($rate((int) $r['acc'], (int) $r['n']), (int) $r['n'])]; }, $crs), 'courses');
    $brs = mysqli_fetch_all(mysqli_query($conn, "SELECT a.branch_id, COUNT(*) n, SUM(a.status = 'Accepted') acc, SUM(a.registered_at IS NOT NULL) reg FROM lc_applications a WHERE $aw GROUP BY a.branch_id ORDER BY n DESC"), MYSQLI_ASSOC);
    $table('Applications by branch', ['Branch', 'Applications', 'Admitted', 'Registered'], array_map(function ($r) use ($conn) { return [lc_e(lc_branch_name($conn, (int) $r['branch_id'] ?: null)), (int) $r['n'], (int) $r['acc'], (int) $r['reg']]; }, $brs), 'branches');
    $ins = mysqli_fetch_all(mysqli_query($conn, "SELECT c.code, i.name, i.capacity, COUNT(*) n, SUM(a.status = 'Accepted') acc, SUM(a.registered_at IS NOT NULL) reg FROM lc_applications a JOIN lc_courses c ON c.id = a.course_id LEFT JOIN lc_intakes i ON i.id = a.intake_id WHERE $aw GROUP BY c.code, i.id, i.name, i.capacity ORDER BY c.code, i.name"), MYSQLI_ASSOC);
    $table('Applications by intake', ['Course · intake', 'Capacity', 'Applications', 'Admitted', 'Registered', 'Fill rate'], array_map(function ($r) use ($pct, $rate) { return [lc_e($r['code'] . ' · ' . ($r['name'] ?? 'no intake')), $r['capacity'] ? (int) $r['capacity'] : '—', (int) $r['n'], (int) $r['acc'], (int) $r['reg'], $r['capacity'] ? $pct($rate((int) $r['acc'], (int) $r['capacity'])) : '—']; }, $ins), 'intakes');
}
/* =============================================================== ACADEMIC */
if ($sec === 'academic') {
    $ew = 'e.registered_at BETWEEN ' . $F . ' AND ' . $T . ' AND ' . $bw('e.branch_id');
    $regd = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM lc_enrollments e WHERE $ew"))[0];
    $act = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM lc_enrollments e JOIN lc_classes k ON k.id = e.class_id WHERE e.status = 'Registered' AND k.status = 'Running' AND " . $bw('e.branch_id')))[0];
    $comp = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM lc_enrollments e WHERE $ew AND e.status = 'Completed'"))[0];
    $fail = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM lc_enrollments e WHERE $ew AND e.status = 'Failed'"))[0];
    echo '<div class="row g-2 mb-3">'; foreach (['Registered (period)' => $regd, 'Active now' => $act, 'Completed' => $comp, 'Not passed' => $fail] as $l => $v) { echo '<div class="col-6 col-md"><div class="card border-0 shadow-sm lc-k"><div class="card-body py-2"><div class="lbl">' . $l . '</div><div class="val">' . $v . '</div></div></div></div>'; $csv[] = ['summary', $l, $v]; }
    echo '<div class="col-6 col-md"><div class="card border-0 shadow-sm lc-k"><div class="card-body py-2"><div class="lbl">Completion rate</div><div class="val" style="font-size:1rem">' . $pct($rate($comp, $comp + $fail), $comp + $fail) . '</div></div></div></div></div>';
    $classes = mysqli_fetch_all(mysqli_query($conn, 'SELECT k.id FROM lc_classes k WHERE ' . $bw('k.branch_id') . " AND k.status <> 'Cancelled' AND k.created_at <= $T"), MYSQLI_ASSOC);
    $rows = []; $insP = []; $modP = [];
    foreach ($classes as $kk) {
        $k = lc_class($conn, (int) $kk['id']); $att = lc_class_attendance($conn, (int) $k['id']);
        $pcts = array_filter(array_column($att, 'pct'), function ($x) { return $x !== null; });
        $res = mysqli_fetch_all(mysqli_query($conn, 'SELECT * FROM lc_results WHERE class_id = ' . (int) $k['id']), MYSQLI_ASSOC);
        $pass = count(array_filter($res, function ($r) { return $r['decision'] === 'Pass'; })); $dec = count(array_filter($res, function ($r) { return $r['decision'] !== 'Incomplete'; }));
        $fin = array_filter(array_column($res, 'final_pct'), function ($x) { return $x !== null; });
        $rows[] = [lc_e($k['code']), count($att), $pcts ? $pct(array_sum($pcts) / count($pcts), count($pcts)) : $pct(null), $res ? $pct($rate($pass, $dec), $dec) : '<span class="text-muted">not published</span>', $fin ? number_format(array_sum($fin) / count($fin), 1) . '%' : '—'];
        $iid = (int) $k['instructor_id'];
        if ($iid) { $insP[$iid]['classes'] = ($insP[$iid]['classes'] ?? 0) + 1; $insP[$iid]['students'] = ($insP[$iid]['students'] ?? 0) + count($att); $insP[$iid]['pass'] = ($insP[$iid]['pass'] ?? 0) + $pass; $insP[$iid]['dec'] = ($insP[$iid]['dec'] ?? 0) + $dec;
            $insP[$iid]['att'] = array_merge($insP[$iid]['att'] ?? [], $pcts); $insP[$iid]['fin'] = array_merge($insP[$iid]['fin'] ?? [], $fin); }
        foreach ($res as $r) { foreach (json_decode((string) $r['modules'], true) ?: [] as $m) { $modP[$k['course_code'] . ' · ' . $m['module']][] = (float) $m['pct']; } }
    }
    $table('Classes: attendance and pass rates (published results)', ['Class', 'Students', 'Average attendance', 'Pass rate', 'Average final mark'], $rows, 'classes');
    $mrows = []; foreach ($modP as $m => $v) { $mrows[] = [lc_e($m), count($v), number_format(array_sum($v) / count($v), 1) . '%' . (count($v) < 10 ? ' <span class="badge bg-light text-muted border">n=' . count($v) . '</span>' : '')]; }
    $table('Module performance (published results)', ['Module', 'Results', 'Average mark'], $mrows, 'modules');
    $ins = lc_instructors($conn, false); $irows = [];
    foreach ($insP as $iid => $v) { $irows[] = [lc_e($ins[$iid]['name'] ?? '#' . $iid), $v['classes'], $v['students'], $v['att'] ? number_format(array_sum($v['att']) / count($v['att']), 1) . '%' : '—', $v['dec'] ? $pct($rate($v['pass'], $v['dec']), $v['dec']) : '—', $v['fin'] ? number_format(array_sum($v['fin']) / count($v['fin']), 1) . '%' : '—']; }
    $table('Instructor indicators (lead instructor of the class)', ['Instructor', 'Classes', 'Students', 'Average attendance', 'Pass rate', 'Average final mark'], $irows, 'instructors');
    echo '<div class="small text-muted mb-3">Indicators describe classes, not people alone: class size, course difficulty and attendance all play a part. Use them with the evaluations, not as a ranking.</div>';
}
/* =============================================================== FINANCE */
if ($sec === 'finance') {
    $iw = "s.sale_date BETWEEN $F AND '$to' AND " . $bw('s.branch_id');
    $rows = mysqli_fetch_all(mysqli_query($conn, lc_invoice_sql() . " WHERE $iw"), MYSQLI_ASSOC);
    $T2 = ['inv' => 0, 'paid' => 0, 'bal' => 0, 'disc' => 0, 'sch' => 0, 'rev' => 0, 'pend' => 0]; $byC = []; $byB = [];
    foreach ($rows as $r) {
        $i = lc_invoice_state($conn, $r);
        if ($i['cancelled']) { $T2['rev'] += $i['total']; continue; } if ($i['pending']) { $T2['pend'] += $i['total']; continue; }
        $T2['inv'] += $i['total']; $T2['paid'] += $i['paid']; $T2['bal'] += $i['balance'];
        foreach ($i['adjustments'] as $ad) { $T2[$ad['type'] === 'Scholarship' ? 'sch' : 'disc'] += (float) $ad['amount']; }
        $c = $i['course_code']; foreach (['inv' => 'total', 'paid' => 'paid', 'bal' => 'balance'] as $kk => $f) { $byC[$c][$kk] = ($byC[$c][$kk] ?? 0) + $i[$f]; }
        $b = (int) (mysqli_fetch_row(mysqli_query($conn, 'SELECT branch_id FROM sales WHERE id = ' . (int) $i['sale_id']))[0] ?? 0); foreach (['inv' => 'total', 'paid' => 'paid', 'bal' => 'balance'] as $kk => $f) { $byB[$b][$kk] = ($byB[$b][$kk] ?? 0) + $i[$f]; }
    }
    echo '<div class="row g-2 mb-3">'; foreach (['Invoiced' => $T2['inv'], 'Collected' => $T2['paid'], 'Outstanding' => $T2['bal'], 'Discounts' => $T2['disc'], 'Scholarships' => $T2['sch'], 'Reversed (cancelled)' => $T2['rev']] as $l => $v) { echo '<div class="col-6 col-md"><div class="card border-0 shadow-sm lc-k"><div class="card-body py-2"><div class="lbl">' . $l . '</div><div class="val text-nowrap" style="font-size:.95rem">' . lc_money($v) . '</div></div></div></div>'; $csv[] = ['summary', $l, $v]; } echo '</div>';
    $ft = mysqli_fetch_all(mysqli_query($conn, "SELECT p.product_code, COALESCE(SUM(si.line_total), 0) amt, COUNT(DISTINCT s.id) inv FROM lc_invoices i JOIN sales s ON s.id = i.sale_id JOIN sale_items si ON si.sale_id = s.id LEFT JOIN products p ON p.id = si.product_id
        WHERE $iw AND s.status NOT IN ('Cancelled', 'Pending Discount Approval') GROUP BY p.product_code ORDER BY amt DESC"), MYSQLI_ASSOC);
    $mx = max([1] + array_map('floatval', array_column($ft, 'amt')));
    $table('Fees invoiced by type (before discounts and scholarships)', ['Fee type', 'Invoices', 'Amount', ''], array_map(function ($r) use ($bar, $mx) { $t = strtolower(substr((string) $r['product_code'], 3)); return [lc_e(LC_FEE_TYPES[$t] ?? ($r['product_code'] ? 'Course offering ' . $r['product_code'] : 'Other')), (int) $r['inv'], lc_money($r['amt']), $bar((float) $r['amt'], $mx)]; }, $ft), 'fee_types');
    $table('By course', ['Course', 'Invoiced', 'Collected', 'Outstanding', 'Collected %'], array_map(function ($c, $v) use ($pct, $rate) { return [lc_e($c), lc_money($v['inv']), lc_money($v['paid']), lc_money($v['bal']), $pct($rate($v['paid'], $v['inv']))]; }, array_keys($byC), $byC), 'finance_courses');
    $table('By branch', ['Branch', 'Invoiced', 'Collected', 'Outstanding'], array_map(function ($b, $v) use ($conn) { return [lc_e(lc_branch_name($conn, $b ?: null)), lc_money($v['inv']), lc_money($v['paid']), lc_money($v['bal'])]; }, array_keys($byB), $byB), 'finance_branches');
    echo '<div class="small text-muted mb-3">Collected is recorded per invoice in Sales, so it is not split by fee type. Income and cash are in Financial Reports (the same Sales records; nothing is counted twice). <strong>Course and branch profitability are not shown:</strong> MY MOTIVE does not assign costs (instructors, rooms, materials) to courses or branches yet, and a profit figure without costs would be misleading.</div>';
}
/* =============================================================== ACCOMMODATION */
if ($sec === 'accommodation') {
    $beds = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COALESCE(SUM(r.beds), 0) FROM lc_acc_rooms r JOIN lc_acc_facilities f ON f.id = r.facility_id WHERE r.status = 'Available' AND f.status = 'Active' AND " . $bw('f.branch_id')))[0];
    $days = max(1, (int) round((strtotime($to) - strtotime($from)) / 86400) + 1);
    $nights = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COALESCE(SUM(GREATEST(0, DATEDIFF(LEAST(b.end_date, DATE_ADD('$to', INTERVAL 1 DAY)), GREATEST(b.start_date, '$from')))), 0) FROM lc_acc_bookings b JOIN lc_acc_rooms r ON r.id = b.room_id JOIN lc_acc_facilities f ON f.id = r.facility_id
        WHERE b.status IN ('Reserved', 'Checked in', 'Checked out') AND " . $bw('f.branch_id')))[0];
    $bk = mysqli_fetch_all(mysqli_query($conn, "SELECT b.status, COUNT(*) n, SUM(b.amount) amt FROM lc_acc_bookings b JOIN lc_acc_rooms r ON r.id = b.room_id JOIN lc_acc_facilities f ON f.id = r.facility_id WHERE b.start_date <= '$to' AND b.end_date >= '$from' AND " . $bw('f.branch_id') . ' GROUP BY b.status'), MYSQLI_ASSOC);
    echo '<div class="row g-2 mb-3"><div class="col-md-3"><div class="card border-0 shadow-sm lc-k"><div class="card-body py-2"><div class="lbl">Beds available</div><div class="val">' . $beds . '</div></div></div></div><div class="col-md-3"><div class="card border-0 shadow-sm lc-k"><div class="card-body py-2"><div class="lbl">Occupancy (bed-nights)</div><div class="val" style="font-size:1rem">' . ($beds ? $pct($rate($nights, $beds * $days)) : '—') . '</div></div></div></div></div>';
    $csv[] = ['summary', 'Beds', $beds]; $csv[] = ['summary', 'Bed-nights used', $nights];
    $table('Bookings in the period', ['Status', 'Bookings', 'Amount'], array_map(function ($r) { return [lc_e($r['status']), (int) $r['n'], lc_money($r['amt'])]; }, $bk), 'bookings');
    echo '<div class="small text-muted mb-3">Accommodation charges are separate "Accommodation fee" invoices; their payments are in the Finance section and in Sales.</div>';
}
/* =============================================================== INTERNSHIPS */
if ($sec === 'internships') {
    $w = "a.created_at BETWEEN $F AND $T AND " . $bw('a.branch_id');
    $st = []; foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT a.status, COUNT(*) n FROM lc_int_apps a WHERE $w GROUP BY a.status"), MYSQLI_ASSOC) as $r) { $st[$r['status']] = (int) $r['n']; }
    $tot = array_sum($st); $stu = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM lc_int_apps a WHERE $w AND a.is_student = 1"))[0];
    $active = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM lc_int_apps a WHERE a.status = 'Active' AND " . $bw('a.branch_id')))[0];
    $ev = mysqli_fetch_assoc(mysqli_query($conn, "SELECT AVG(e.average) av, COUNT(*) n FROM lc_int_evals e JOIN lc_int_apps a ON a.id = e.app_id WHERE e.eval_date BETWEEN $F AND '$to' AND " . $bw('a.branch_id')));
    $k = ['Applications' => $tot, 'From LC students' => $stu, 'External' => $tot - $stu, 'Approved (incl. later stages)' => ($st['Approved'] ?? 0) + ($st['Active'] ?? 0) + ($st['Completed'] ?? 0) + ($st['Discontinued'] ?? 0), 'Active now' => $active, 'Completed' => $st['Completed'] ?? 0];
    echo '<div class="row g-2 mb-3">'; foreach ($k as $l => $v) { echo '<div class="col-6 col-md"><div class="card border-0 shadow-sm lc-k"><div class="card-body py-2"><div class="lbl">' . $l . '</div><div class="val">' . $v . '</div></div></div></div>'; $csv[] = ['summary', $l, $v]; } echo '</div>';
    echo '<div class="alert alert-light border small py-2">Average evaluation in the period: ' . ((int) $ev['n'] ? number_format((float) $ev['av'], 2) . ' / 5 (' . (int) $ev['n'] . ' evaluation(s))' : 'no evaluation yet') . '.</div>';
    $table('By status', ['Status', 'Applications'], array_map(function ($s) use ($st) { return [lc_e($s), $st[$s] ?? 0]; }, array_values(array_filter(LC_INT_STATUSES, function ($s) use ($st) { return isset($st[$s]); }))), 'internship_status');
    $po = mysqli_fetch_all(mysqli_query($conn, "SELECT p.title, COUNT(*) n, SUM(a.status IN ('Approved', 'Active', 'Completed')) placed, p.places FROM lc_int_apps a JOIN lc_int_posts p ON p.id = a.post_id WHERE $w GROUP BY p.id, p.title, p.places ORDER BY n DESC"), MYSQLI_ASSOC);
    $table('By opportunity', ['Opportunity', 'Applications', 'Placed', 'Places'], array_map(function ($r) { return [lc_e($r['title']), (int) $r['n'], (int) $r['placed'], $r['places'] ? (int) $r['places'] : '—']; }, $po), 'internship_posts');
}
$body = ob_get_clean();
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8'); header('Content-Disposition: attachment; filename="learning-centre-' . $sec . '-' . $from . '-' . $to . '.csv"');
    $o = fopen('php://output', 'w'); fputcsv($o, ['RM Learning Centre — ' . $sec, $from . ' to ' . $to]); foreach ($csv as $r) { fputcsv($o, $r); } fclose($o);
    lc_audit($conn, 'report_export', 'report', null, ['section' => $sec, 'from' => $from, 'to' => $to]);
    exit;
}
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
lcs_tabs($conn, 'reports.php', 'Learning Centre Reports');
$secs = ['marketing' => 'Marketing & applications', 'academic' => 'Academic performance'] + ($canFin ? ['finance' => 'Finance'] : []) + ['accommodation' => 'Accommodation', 'internships' => 'Internships'];
?>
<form class="row g-2 mb-2 align-items-end"><input type="hidden" name="s" value="<?= lc_e($sec); ?>">
    <div class="col-auto"><label class="form-label small mb-0">From</label><input type="date" name="from" value="<?= $from; ?>" class="form-control form-control-sm"></div>
    <div class="col-auto"><label class="form-label small mb-0">To</label><input type="date" name="to" value="<?= $to; ?>" class="form-control form-control-sm"></div>
    <?php if (br_enabled($conn)) { ?><div class="col-auto"><label class="form-label small mb-0">Branch</label><select name="branch" class="form-select form-select-sm"><option value="">All my branches</option><?php foreach (br_all($conn) as $bid => $b) { if (lcs_branch_ok($conn, (int) $bid)) { ?><option value="<?= (int) $bid; ?>" <?= $fb === (int) $bid ? 'selected' : ''; ?>><?= lc_e($b['name']); ?></option><?php } } ?></select></div><?php } ?>
    <div class="col-auto"><button class="btn btn-sm btn-primary">Show</button> <a class="btn btn-sm btn-light border" href="?<?= lc_e(http_build_query(array_merge($_GET, ['s' => $sec, 'export' => 'csv']))); ?>">CSV</a></div></form>
<ul class="nav nav-pills small mb-3"><?php foreach ($secs as $k => $l) { ?><li class="nav-item"><a class="nav-link py-1 <?= $sec === $k ? 'active' : ''; ?>" href="?<?= lc_e(http_build_query(['s' => $k, 'from' => $from, 'to' => $to, 'branch' => $fb ?? ''])); ?>"><?= $l; ?></a></li><?php } ?></ul>
<?= $body; ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>