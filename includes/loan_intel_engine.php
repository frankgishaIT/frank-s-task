<?php
/**
 * NEW FILE: includes/loan_intel_engine.php — Company Loans & Investment Intelligence: the CALCULATIONS.
 *
 * Pure functions (no database): every figure can be reproduced and tested independently
 * (tests/loan_intel_engine_test.php). Money in RWF, periods in MONTHS unless said otherwise.
 *
 * Definitions used everywhere (shown on screen as well):
 *  - CFADS (cash flow available for debt service), per month = operating profit from the Profit & Loss
 *    + non-cash charges (depreciation, impairment, assets written off) — i.e. EBITDA. Interest is NOT deducted
 *    (it is part of the debt service). Loan money received is NOT income (never counted).
 *  - Debt service = principal + interest due in the same months.
 *  - DSCR = CFADS / debt service over the SAME 12 months (or the whole loan when shorter).
 *  - ROI = net investment return / investment cost x 100, where investment cost = equipment/setup + working capital,
 *    and net investment return = operating profit over the evaluation horizon + working capital released at the end
 *    - investment cost.
 *  - Payback = months until the cumulative cash flow of the investment (cost at month 0) becomes >= 0.
 *  - NPV = sum of monthly cash flows discounted at the configured ANNUAL rate (converted to a monthly rate).
 *  - IRR = the annual rate where NPV = 0 (monthly IRR found by bisection, then annualised).
 */

const LI_FREQUENCIES = ['Weekly' => 52, 'Monthly' => 12, 'Quarterly' => 4, 'Annually' => 1];
const LI_STRUCTURES = [
    'emi'      => 'Equal instalments (principal + interest)',
    'equal'    => 'Equal principal (instalments go down)',
    'bullet'   => 'Interest only, principal at the end',
];

/** Monthly rate from an annual percentage (compound). */
function li_monthly_rate(float $annualPct): float
{
    return pow(1 + $annualPct / 100, 1 / 12) - 1;
}

/**
 * Repayment schedule. $n = number of instalments (including grace instalments), $grace = first instalments that pay
 * interest only. Interest is on the reducing balance (same method as the Loans module).
 * Returns rows [no, month (1-based month in which it is due), interest, principal, payment, balance] and totals.
 */
function li_schedule(float $principal, float $annualPct, int $n, string $freq = 'Monthly', int $grace = 0, string $structure = 'emi'): array
{
    $ppy = LI_FREQUENCIES[$freq] ?? 12;
    $n = max(1, $n);
    $grace = max(0, min($grace, $n - 1));
    $r = $annualPct / 100 / $ppy;
    $amortN = $n - $grace;
    $emi = $r > 0 ? $principal * $r * pow(1 + $r, $amortN) / (pow(1 + $r, $amortN) - 1) : $principal / $amortN;
    $bal = $principal; $rows = []; $ti = 0.0; $tp = 0.0;
    for ($k = 1; $k <= $n; $k++) {
        $int = round($bal * $r, 2);
        if ($k <= $grace) { $prin = 0.0; }
        elseif ($structure === 'bullet') { $prin = $k === $n ? $bal : 0.0; }
        elseif ($structure === 'equal') { $prin = $principal / $amortN; }
        else { $prin = $emi - $int; }
        if ($k === $n) { $prin = $bal; }
        $prin = round(max(0, min($bal, $prin)), 2);
        $bal = round($bal - $prin, 2);
        $pay = round($int + $prin, 2);
        $month = (int) max(1, ceil($k * 12 / $ppy - 1e-9));
        $rows[] = ['no' => $k, 'month' => $month, 'interest' => $int, 'principal' => $prin, 'payment' => $pay, 'balance' => $bal];
        $ti += $int; $tp += $pay;
    }
    $regular = $rows[min($n, $grace + 1) - 1]['payment'];
    return ['rows' => $rows, 'instalment' => round($regular, 2), 'first_payment' => $rows[0]['payment'], 'total_interest' => round($ti, 2),
            'total_repayment' => round($tp, 2), 'months' => end($rows)['month'], 'per_year' => $ppy];
}

/** Debt service per month (index 1..$months) from a schedule's rows. */
function li_monthly_debt(array $schedule, int $months, int $offset = 0): array
{
    $out = array_fill(1, max(1, $months), 0.0);
    foreach ($schedule['rows'] as $row) {
        $m = $row['month'] + $offset;
        if ($m >= 1 && $m <= $months) { $out[$m] += $row['payment']; }
    }
    return $out;
}

/** Net present value of monthly flows (index 0 = today, not discounted). */
function li_npv(float $annualPct, array $flows): float
{
    $mr = li_monthly_rate($annualPct);
    $v = 0.0;
    foreach (array_values($flows) as $t => $f) { $v += $f / pow(1 + $mr, $t); }
    return round($v, 2);
}

/** Annual IRR (%) of monthly flows; null when it does not exist (no sign change) or cannot be found. */
function li_irr(array $flows): ?float
{
    $flows = array_values($flows);
    $hasNeg = false; $hasPos = false;
    foreach ($flows as $f) { if ($f < 0) { $hasNeg = true; } if ($f > 0) { $hasPos = true; } }
    if (!$hasNeg || !$hasPos) { return null; }
    $npv = function (float $m) use ($flows) { $v = 0.0; foreach ($flows as $t => $f) { $v += $f / pow(1 + $m, $t); } return $v; };
    $lo = -0.99; $hi = 1.0;
    if ($npv($lo) * $npv($hi) > 0) { return null; }
    for ($i = 0; $i < 200; $i++) {
        $mid = ($lo + $hi) / 2;
        if ($npv($lo) * $npv($mid) <= 0) { $hi = $mid; } else { $lo = $mid; }
        if ($hi - $lo < 1e-10) { break; }
    }
    return round((pow(1 + ($lo + $hi) / 2, 12) - 1) * 100, 2);
}

/** Months (with one decimal) until the cumulative flow is >= 0; null when never within the flows. */
function li_payback(array $flows): ?float
{
    $flows = array_values($flows);
    $cum = 0.0;
    foreach ($flows as $t => $f) {
        $prev = $cum; $cum += $f;
        if ($t > 0 && $cum >= 0 && $prev < 0) { return round($t - 1 + (-$prev) / max(1e-9, $f), 1); }
        if ($t === 0 && $cum >= 0) { return 0.0; }
    }
    return null;
}

/**
 * An investment proposal's figures. Input (per month unless said):
 *  investment_cost, working_capital, impl_months (no revenue yet), horizon_months (evaluation period, from today),
 *  revenue (monthly when fully running), cost_of_sales_pct (% of revenue), opex (extra monthly operating expenses),
 *  ramp_months (months to reach full revenue, linear; optional).
 * Scenario adjustments: revenue_pct, opex_pct, delay_months, slower_pct (stock sells slower: revenue / (1+x),
 * working capital x (1+x)).
 */
function li_proposal_metrics(array $p, float $discountPct, array $adj = []): array
{
    $cost = max(0, (float) ($p['investment_cost'] ?? 0));
    $slower = max(0, (float) ($adj['slower_pct'] ?? 0)) / 100;
    $wc = max(0, (float) ($p['working_capital'] ?? 0)) * (1 + $slower);
    $impl = max(0, (int) ($p['impl_months'] ?? 0)) + max(0, (int) ($adj['delay_months'] ?? 0));
    $h = max(1, (int) ($p['horizon_months'] ?? 24));
    $rev = max(0, (float) ($p['revenue'] ?? 0)) * (1 + (float) ($adj['revenue_pct'] ?? 0) / 100) / (1 + $slower);
    $cosPct = min(100, max(0, (float) ($p['cost_of_sales_pct'] ?? 0))) / 100;
    $opex = max(0, (float) ($p['opex'] ?? 0)) * (1 + (float) ($adj['opex_pct'] ?? 0) / 100);
    $ramp = max(0, (int) ($p['ramp_months'] ?? 0));
    $total = $cost + $wc;
    $flows = [-$total]; $revSum = 0.0; $gpSum = 0.0; $npSum = 0.0; $monthly = [];
    for ($m = 1; $m <= $h; $m++) {
        $running = $m - $impl;
        $f = $running <= 0 ? 0.0 : ($ramp > 0 ? min(1, $running / $ramp) : 1.0);
        $r = $rev * $f; $gp = $r * (1 - $cosPct); $np = $gp - ($running > 0 ? $opex : 0.0);
        $cf = $np + ($m === $h ? $wc : 0.0);
        $flows[] = $cf; $revSum += $r; $gpSum += $gp; $npSum += $np;
        $monthly[$m] = ['revenue' => round($r, 2), 'gross' => round($gp, 2), 'net' => round($np, 2), 'cash' => round($cf, 2)];
    }
    $full = $rev * (1 - $cosPct) - $opex;
    $netReturn = $npSum + $wc - $total;
    return [
        'total_cost' => round($total, 2), 'working_capital' => round($wc, 2), 'impl_months' => $impl,
        'monthly_revenue' => round($rev, 2), 'monthly_gross' => round($rev * (1 - $cosPct), 2), 'monthly_net' => round($full, 2), 'monthly_cash' => round($full, 2),
        'revenue_total' => round($revSum, 2), 'gross_total' => round($gpSum, 2), 'net_total' => round($npSum, 2),
        'net_return' => round($netReturn, 2), 'roi' => $total > 0 ? round($netReturn / $total * 100, 1) : null,
        'payback' => li_payback($flows), 'npv' => li_npv($discountPct, $flows), 'irr' => li_irr($flows),
        'flows' => $flows, 'monthly' => $monthly, 'horizon' => $h,
    ];
}

/** Largest principal whose debt service keeps DSCR >= $minDscr, given the monthly CFADS and the existing debt per month. */
function li_affordable_principal(float $monthlyCfads, float $existingMonthlyDebt, float $minDscr, float $annualPct, int $n, string $freq, int $grace, string $structure): float
{
    if ($monthlyCfads <= 0 || $minDscr <= 0) { return 0.0; }
    $room = $monthlyCfads / $minDscr - $existingMonthlyDebt;   // debt service the company can carry per month
    if ($room <= 0) { return 0.0; }
    $unit = li_schedule(1000000, $annualPct, $n, $freq, $grace, $structure);
    // Worst 12-month window of debt service per RWF 1,000,000, converted to an average month.
    $months = $unit['months'];
    $per = li_monthly_debt($unit, $months);
    $worst = 0.0;
    for ($s = 1; $s <= max(1, $months - 11); $s++) {
        $w = 0.0; $len = min(12, $months - $s + 1);
        for ($m = $s; $m < $s + $len; $m++) { $w += $per[$m]; }
        $worst = max($worst, $w / $len);
    }
    if ($structure === 'bullet' || $worst <= 0) { $worst = max($worst, max($per)); } // bullet: the last payment must be covered too
    return $worst > 0 ? floor($room / $worst * 1000000 / 10000) * 10000 : 0.0;
}

/**
 * Month-by-month simulation of the company with a NEW loan.
 * $base: ['cfads' => monthly CFADS (base), 'revenue' => monthly revenue, 'margin' => gross margin 0-1, 'opex' => monthly cash opex,
 *         'cash' => cash available today, 'reserve' => cash to keep, 'existing' => [month => existing debt service]].
 * $loan:  principal, rate, n, freq, grace, structure, fees, other, rate_type ('Fixed'|'Variable').
 * $inv:   li_proposal_metrics() result for the investment financed (optional).
 * $adj:   revenue_pct, opex_pct, delay_months (investment), rate_up (percentage points, variable loans), shock (one-off obligation in month 3).
 */
function li_simulate(array $base, array $loan, ?array $proposal, float $discountPct, array $adj = []): array
{
    $rate = (float) $loan['rate'] + (($loan['rate_type'] ?? 'Fixed') === 'Variable' ? (float) ($adj['rate_up'] ?? 0) : 0);
    $sch = li_schedule((float) $loan['principal'], $rate, (int) $loan['n'], $loan['freq'] ?? 'Monthly', (int) ($loan['grace'] ?? 0), $loan['structure'] ?? 'emi');
    $months = max($sch['months'], 12);
    $newDebt = li_monthly_debt($sch, $months);
    $inv = $proposal ? li_proposal_metrics($proposal, $discountPct, $adj) : null;
    $revAdj = (float) ($adj['revenue_pct'] ?? 0) / 100; $opexAdj = (float) ($adj['opex_pct'] ?? 0) / 100;
    $cfadsBase = (float) $base['cfads'] + (float) $base['revenue'] * (float) $base['margin'] * $revAdj - (float) $base['opex'] * $opexAdj;
    $fees = (float) ($loan['fees'] ?? 0) + (float) ($loan['other'] ?? 0);
    $cash = (float) $base['cash'] + (float) $loan['principal'] - $fees - ($inv ? $inv['total_cost'] : 0);
    $rows = []; $first = null; $low = $cash; $sumC = 0.0; $sumD = 0.0;
    for ($m = 1; $m <= $months; $m++) {
        $invNet = $inv['monthly'][$m]['net'] ?? 0.0;
        $invCash = $inv['monthly'][$m]['cash'] ?? 0.0;
        $c = $cfadsBase + $invNet;
        $d = ($base['existing'][$m] ?? 0.0) + $newDebt[$m];
        $shock = ($m === 3) ? (float) ($adj['shock'] ?? 0) : 0.0;
        $cash += $cfadsBase + $invCash - $d - $shock;
        if ($cash < (float) $base['reserve'] && $first === null) { $first = $m; }
        $low = min($low, $cash);
        $rows[$m] = ['cfads' => round($c, 2), 'debt' => round($d, 2), 'new_debt' => round($newDebt[$m], 2), 'after' => round($c - $d, 2), 'cash' => round($cash, 2)];
        $sumC += $c; $sumD += $d;
    }
    // DSCR per 12-month window (loan years); the lowest is what counts.
    $dscrs = [];
    for ($s = 1; $s <= $months; $s += 12) {
        $c = 0.0; $d = 0.0;
        for ($m = $s; $m < min($months + 1, $s + 12); $m++) { $c += $rows[$m]['cfads']; $d += $rows[$m]['debt']; }
        $dscrs[] = $d > 0 ? round($c / $d, 2) : null;
    }
    $valid = array_filter($dscrs, function ($v) { return $v !== null; });
    $first12 = array_slice($rows, 0, 12);
    return [
        'schedule' => $sch, 'rate_used' => $rate, 'investment' => $inv, 'rows' => $rows, 'months' => $months,
        'instalment' => $sch['instalment'], 'total_interest' => $sch['total_interest'], 'total_cost' => round($sch['total_interest'] + $fees, 2),
        'total_repayment' => $sch['total_repayment'],
        'monthly_debt_new' => round(array_sum(array_column($first12, 'new_debt')) / max(1, count($first12)), 2),
        'monthly_debt_total' => round(array_sum(array_column($first12, 'debt')) / max(1, count($first12)), 2),
        'monthly_after' => round(array_sum(array_column($first12, 'after')) / max(1, count($first12)), 2),
        'dscr_years' => $dscrs, 'dscr_min' => $valid ? min($valid) : null, 'dscr_avg' => $sumD > 0 ? round($sumC / $sumD, 2) : null,
        'cash_end' => round($cash, 2), 'cash_low' => round($low, 2), 'first_below_reserve' => $first,
        'investment_profit' => $inv ? $inv['net_total'] : null,
    ];
}

/** Default stress scenarios (management can change them in Settings). */
function li_default_scenarios(): array
{
    return [
        'optimistic'  => ['label' => 'Optimistic',  'revenue_pct' => 10,  'opex_pct' => -5, 'delay_months' => 0, 'slower_pct' => 0,  'rate_up' => 0, 'shock' => 0],
        'base'        => ['label' => 'Base',        'revenue_pct' => 0,   'opex_pct' => 0,  'delay_months' => 0, 'slower_pct' => 0,  'rate_up' => 0, 'shock' => 0],
        'pessimistic' => ['label' => 'Pessimistic', 'revenue_pct' => -20, 'opex_pct' => 10, 'delay_months' => 3, 'slower_pct' => 30, 'rate_up' => 3, 'shock' => 0],
    ];
}

/**
 * Ranking score 0-100 from configurable weights. $m = metrics, $risk Low|Medium|High, $evidence 0-1, $confidence High|Medium|Low.
 * Each criterion is scaled 0-1: ROI (0% -> 0, >= 2 x the minimum ROI -> 1), payback (<= 3 months -> 1, >= max payback -> 0),
 * cash flow (monthly cash / cost: >= 10% -> 1), risk (Low 1, Medium .6, High .2), evidence, confidence (High 1, Medium .6, Low .3), NPV (> 0 -> 1).
 */
function li_score(array $m, string $risk, float $evidence, string $confidence, array $w, float $minRoi, float $maxPayback): array
{
    $roi = $m['roi'] === null ? 0 : max(0, min(1, $m['roi'] / max(1, 2 * $minRoi)));
    $pb = $m['payback'] === null ? 0 : max(0, min(1, ($maxPayback - $m['payback']) / max(1, $maxPayback - 3)));
    $cf = $m['total_cost'] > 0 ? max(0, min(1, $m['monthly_cash'] / $m['total_cost'] / 0.10)) : 0;
    $rk = ['Low' => 1, 'Medium' => 0.6, 'High' => 0.2][$risk] ?? 0.6;
    $cn = ['High' => 1, 'Medium' => 0.6, 'Low' => 0.3][$confidence] ?? 0.3;
    $npv = $m['npv'] > 0 ? 1 : 0;
    $parts = ['roi' => $roi, 'payback' => $pb, 'cash_flow' => $cf, 'risk' => $rk, 'evidence' => max(0, min(1, $evidence)), 'confidence' => $cn, 'npv' => $npv];
    $sumW = 0; $s = 0;
    foreach ($parts as $k => $v) { $wk = max(0, (float) ($w[$k] ?? 0)); $sumW += $wk; $s += $wk * $v; }
    return ['score' => $sumW > 0 ? round($s / $sumW * 100, 1) : 0, 'parts' => $parts];
}

/**
 * The recommendation (advisory only). $c:
 *  enough_history (bool), history_months, confidence, need (total funding required), internal (internal funds available),
 *  requested (principal asked), affordable (largest affordable principal, base), roi, npv, min_roi,
 *  dscr_base, dscr_pess, min_dscr, min_dscr_stress, temporary (bool: cash gap that closes by itself).
 * Returns [code, label, reasons[], amount].
 */
function li_decide(array $c): array
{
    $R = [];
    $need = max(0, (float) $c['need']); $internal = max(0, (float) $c['internal']);
    $gap = max(0, $need - $internal);
    if (empty($c['enough_history']) || ($c['confidence'] ?? 'Low') === 'Low' && $c['roi'] === null) {
        $R[] = 'Only ' . (int) ($c['history_months'] ?? 0) . ' month(s) of usable financial history; at least ' . (int) ($c['min_history'] ?? 6) . ' are needed for a reliable repayment analysis.';
        if ($c['roi'] === null) { $R[] = 'The expected returns of the investment are not documented.'; }
        return ['MORE_DATA', 'MORE DATA REQUIRED', $R, 0.0];
    }
    if ($need <= 0) { return ['MORE_DATA', 'MORE DATA REQUIRED', ['No funding requirement has been calculated or entered.'], 0.0]; }
    if ($c['roi'] !== null && ($c['roi'] < (float) $c['min_roi'] || (float) $c['npv'] < 0)) {
        $R[] = 'Expected ROI ' . $c['roi'] . '% ' . ($c['roi'] < $c['min_roi'] ? 'is below the minimum of ' . $c['min_roi'] . '%' : 'is positive but') . ($c['npv'] < 0 ? ' and the NPV is negative (RWF ' . number_format($c['npv'], 0) . ')' : '') . ': the investment does not create enough value to justify paying interest.';
        return ['DO_NOT', 'DO NOT BORROW', $R, 0.0];
    }
    if ($gap <= 0) {
        $R[] = 'Internal funds available (RWF ' . number_format($internal, 0) . ') cover the full requirement (RWF ' . number_format($need, 0) . ').';
        return ['INTERNAL', 'USE INTERNAL FUNDS', $R, 0.0];
    }
    if ($internal > 0) { $R[] = 'Internal funds cover RWF ' . number_format($internal, 0) . '; the funding gap is RWF ' . number_format($gap, 0) . '.'; }
    $aff = max(0, (float) $c['affordable']);
    if ($aff <= 0) {
        $R[] = 'The company cannot carry more debt service: with the current cash flow and existing loans the DSCR would fall below ' . $c['min_dscr'] . '.';
        return ['DO_NOT', 'DO NOT BORROW', $R, 0.0];
    }
    if (!empty($c['temporary'])) {
        $R[] = 'The cash gap is temporary (cash recovers within the forecast). A short-term facility or timing of payments is preferable to a long-term loan.';
    }
    $amount = min($gap, $aff);
    $amount = floor($amount / 10000) * 10000;
    if ($amount < $gap * 0.5) {
        $R[] = 'The company can safely repay only about RWF ' . number_format($aff, 0) . ' (DSCR >= ' . $c['min_dscr'] . '), less than half of the funding gap. Borrowing that little would not complete the investment.';
        return ['POSTPONE', 'POSTPONE', $R, 0.0];
    }
    if ($c['dscr_pess'] !== null && $c['dscr_pess'] < (float) $c['min_dscr_stress']) {
        $R[] = 'In the pessimistic scenario the lowest DSCR is ' . $c['dscr_pess'] . ' (minimum ' . $c['min_dscr_stress'] . '): repayments would not be covered under adverse conditions.';
        return ['POSTPONE', 'POSTPONE', $R, 0.0];
    }
    $req = (float) $c['requested'];
    if ($req > $amount * 1.02) {
        $R[] = 'The proposed amount (RWF ' . number_format($req, 0) . ') is more than the ' . ($amount < $gap ? 'amount the company can safely repay' : 'demonstrated funding gap') . ' (RWF ' . number_format($amount, 0) . ').';
        return ['LESS', 'BORROW LESS', $R, $amount];
    }
    $R[] = 'Verified funding gap of RWF ' . number_format($gap, 0) . '; base DSCR ' . ($c['dscr_base'] ?? '—') . ' and pessimistic DSCR ' . ($c['dscr_pess'] ?? '—') . ' stay above the minimums (' . $c['min_dscr'] . ' / ' . $c['min_dscr_stress'] . ').';
    if ($c['roi'] !== null) { $R[] = 'Expected ROI ' . $c['roi'] . '% (minimum ' . $c['min_roi'] . '%), NPV RWF ' . number_format((float) $c['npv'], 0) . '.'; }
    return ['BORROW', 'BORROW', $R, min($req > 0 ? $req : $amount, $amount)];
}

/** Performance assessment of a funded investment / loan: actual vs expected operating profit to date. */
function li_assess(?float $expected, ?float $actual, int $overdue, bool $completed, bool $measurable): string
{
    if ($completed) { return 'Completed'; }
    if ($overdue > 0) { return 'Financially At Risk'; }
    if (!$measurable || $expected === null || $actual === null) { return 'Not yet measurable'; }
    if ($expected <= 0) { return $actual >= 0 ? 'Performing as Expected' : 'Underperforming'; }
    $r = $actual / $expected;
    if ($r >= 1.10) { return 'Above Expectations'; }
    if ($r >= 0.90) { return 'Performing as Expected'; }
    if ($r >= 0.60) { return 'Below Expectations'; }
    return 'Underperforming';
}