<?php
/**
 * NEW FILE: includes/loan_report_queries.php
 * Data layer for the Loans reports. Same return shape as the Sales, Customers, Assets and
 * Transactions reports, so report_screen_result() and report_render_pdf() draw it.
 *
 * Sources: `loans` (amounts, dates, totals), `loan_payments` (each repayment with its principal and
 * interest parts) and `loan_schedule` (each installment: due date, amount due, amount paid).
 * "Open" = Active or Defaulted (still owed). Cancelled loans are left out unless asked for.
 * Overdue = an open loan whose maturity date has passed.
 */
require_once __DIR__ . '/report_helpers.php';
require_once __DIR__ . '/sales_report_queries.php'; // reuses sales_report_fetch()

const LOANS_REPORT_OPEN = "('Active', 'Defaulted')";

function loans_report_types(): array
{
    return [
        'register'    => 'Loan Register',
        'outstanding' => 'Outstanding Loans',
        'maturity'    => 'Loans Maturing',
        'lenders'     => 'Summary by Lender',
        'repayments'  => 'Repayments Made',
        'installments'=> 'Upcoming & Overdue Installments',
    ];
}

// Which reports use the period, and what the period filters on.
function loans_report_period_field(string $type): ?string
{
    return ['register' => 'l.loan_start_date', 'maturity' => 'l.maturity_date', 'repayments' => 'p.payment_date',
            'installments' => 's.due_date'][$type] ?? null;
}

function loans_report_lookups(mysqli $conn): array
{
    return [
        'lenders'  => array_column(sales_report_fetch($conn, "SELECT DISTINCT lender FROM loans WHERE lender <> '' ORDER BY lender"), 'lender'),
        'statuses' => ['open' => 'Open (Active + Defaulted)', 'Active' => 'Active', 'Defaulted' => 'Defaulted',
                       'Fully Paid' => 'Fully Paid', 'not_cancelled' => 'All except Cancelled', 'Cancelled' => 'Cancelled'],
    ];
}

function loans_report_build(mysqli $conn, string $type, array $req): array
{
    $types = loans_report_types();
    $fail = function ($msg) use ($type, $types) {
        return ['error' => $msg, 'title' => $types[$type] ?? 'Loan Report', 'type' => $type];
    };
    if (!isset($types[$type])) {
        return $fail('Please choose a report.');
    }
    $lookups = loans_report_lookups($conn);

    $where = [];
    $bt = '';
    $params = [];
    $filters = [];

    $field = loans_report_period_field($type);
    if ($field) {
        $period = report_period_from_request($req, true);
        if ($period['error']) {
            return $fail($period['error']);
        }
        if ($type === 'installments') {
            // Everything due up to the end of the period, including installments already overdue.
            if ($period['end'] !== null) {
                $where[] = "$field <= ?"; $bt .= 's'; $params[] = $period['end'];
            }
            $filters[] = ['Due up to', $period['end'] !== null ? date('d M Y', strtotime($period['end'])) . ' (overdue included)' : 'All'];
        } else {
            if ($period['start'] !== null) {
                $where[] = "$field BETWEEN ? AND ?"; $bt .= 'ss'; $params[] = $period['start']; $params[] = $period['end'];
            }
            $filters[] = [['maturity' => 'Maturing', 'repayments' => 'Paid', 'register' => 'Started'][$type], $period['label']];
        }
    } else {
        $filters[] = ['As of', date('d M Y')];
    }

    $lender = (string) ($req['lender'] ?? '');
    if ($lender !== '' && in_array($lender, $lookups['lenders'], true)) {
        $where[] = 'l.lender = ?'; $bt .= 's'; $params[] = $lender;
        $filters[] = ['Lender', $lender];
    }

    // NEW: reports built from loan_payments and loan_schedule.
    if ($type === 'repayments') {
        return loans_report_repayments($conn, $types[$type], $filters, $where, $bt, $params);
    }
    if ($type === 'installments') {
        $where[] = 'l.status IN ' . LOANS_REPORT_OPEN;
        return loans_report_installments($conn, $types[$type], $filters, $where, $bt, $params);
    }

    // Status: the register lets you choose; the others use what makes sense for them.
    if ($type === 'register') {
        $status = (string) ($req['status'] ?? 'not_cancelled');
        if (!isset($lookups['statuses'][$status])) { $status = 'not_cancelled'; }
        if ($status === 'open') {
            $where[] = 'l.status IN ' . LOANS_REPORT_OPEN;
        } elseif ($status === 'not_cancelled') {
            $where[] = "l.status NOT IN ('Cancelled', 'Deleted')";
        } else {
            $where[] = 'l.status = ?'; $bt .= 's'; $params[] = $status;
        }
        $filters[] = ['Status', $lookups['statuses'][$status]];
    } elseif ($type === 'lenders') {
        $where[] = "l.status NOT IN ('Cancelled', 'Deleted')";
    } else {
        $where[] = 'l.status IN ' . LOANS_REPORT_OPEN;
    }

    $rows = sales_report_fetch($conn, "SELECT l.*, DATEDIFF(l.maturity_date, CURDATE()) AS days_to_maturity
        FROM loans l WHERE " . implode(' AND ', $where) . " ORDER BY " . ($type === 'maturity' ? 'l.maturity_date' : 'l.loan_start_date') . ", l.id",
        $bt, $params);

    $report = [
        'error' => null, 'type' => $type, 'title' => $types[$type], 'filters' => $filters,
        'summary' => [], 'columns' => [], 'rows' => [], 'totals' => [], 'totals_label' => 'TOTAL',
        'footer_summary' => [], 'notes' => [],
    ];

    foreach ($rows as &$r) {
        $payable = (float) $r['total_payable'];
        $r['outstanding_now'] = in_array($r['status'], ['Active', 'Defaulted'], true) ? (float) $r['outstanding_balance'] : 0.0;
        $r['repaid_pct'] = (float) $r['loan_amount'] > 0
            ? round(100 * (float) $r['principal_repaid'] / (float) $r['loan_amount'], 1) . '%' : '—';
        $r['rate'] = rtrim(rtrim(number_format((float) $r['interest_rate'], 3), '0'), '.') . '%';
        $r['terms'] = (int) $r['repayment_period'] . ' x ' . $r['repayment_frequency'];
        $overdue = in_array($r['status'], ['Active', 'Defaulted'], true) && (int) $r['days_to_maturity'] < 0 && $r['outstanding_now'] > 0.009;
        $r['flag'] = $overdue ? 'Overdue ' . abs((int) $r['days_to_maturity']) . ' days' : '';
        $r['payable_left'] = max(0, $payable - (float) $r['total_paid']);
    }
    unset($r);

    switch ($type) {
        case 'register':    return loans_report_register($report, $rows);
        case 'outstanding': return loans_report_outstanding($report, $rows);
        case 'maturity':    return loans_report_maturity($report, $rows);
        case 'lenders':     return loans_report_lenders($report, $rows);
    }
    return $fail('Please choose a report.');
}

function loans_report_sum(array $rows, string $key): float
{
    return round(array_sum(array_map(function ($r) use ($key) { return (float) $r[$key]; }, $rows)), 2);
}

/* ---------------------------------------------------------------------------------------- */

function loans_report_register(array $report, array $rows): array
{
    $report['rows'] = $rows;
    $report['summary'] = [
        ['Loans', number_format(count($rows))],
        ['Borrowed', report_money(loans_report_sum($rows, 'loan_amount'))],
        ['Total Paid', report_money(loans_report_sum($rows, 'total_paid'))],
        ['Still Owed', report_money(loans_report_sum($rows, 'outstanding_now'))],
    ];
    $report['columns'] = [
        ['key' => 'lender', 'label' => 'Lender', 'w' => 12],
        ['key' => 'loan_type', 'label' => 'Type', 'w' => 9],
        ['key' => 'loan_start_date', 'label' => 'Start', 'w' => 8, 'type' => 'date'],
        ['key' => 'maturity_date', 'label' => 'Maturity', 'w' => 8, 'type' => 'date'],
        ['key' => 'rate', 'label' => 'Rate', 'w' => 5, 'align' => 'R'],
        ['key' => 'terms', 'label' => 'Terms', 'w' => 9],
        ['key' => 'loan_amount', 'label' => 'Borrowed (RWF)', 'w' => 10, 'align' => 'R', 'type' => 'money'],
        ['key' => 'total_interest', 'label' => 'Interest (RWF)', 'w' => 9, 'align' => 'R', 'type' => 'money'],
        ['key' => 'total_payable', 'label' => 'Payable (RWF)', 'w' => 10, 'align' => 'R', 'type' => 'money'],
        ['key' => 'total_paid', 'label' => 'Paid (RWF)', 'w' => 10, 'align' => 'R', 'type' => 'money'],
        ['key' => 'outstanding_now', 'label' => 'Owed (RWF)', 'w' => 10, 'align' => 'R', 'type' => 'money'],
        ['key' => 'status', 'label' => 'Status', 'w' => 7],
    ];
    $report['totals'] = [
        'loan_amount' => loans_report_sum($rows, 'loan_amount'), 'total_interest' => loans_report_sum($rows, 'total_interest'),
        'total_payable' => loans_report_sum($rows, 'total_payable'), 'total_paid' => loans_report_sum($rows, 'total_paid'),
        'outstanding_now' => loans_report_sum($rows, 'outstanding_now'),
    ];
    $report['footer_summary'] = [
        ['Principal Repaid', report_money(loans_report_sum($rows, 'principal_repaid'))],
        ['Interest Paid', report_money(loans_report_sum($rows, 'interest_paid'))],
    ];
    $report['notes'][] = 'Payable = amount borrowed + total interest. Owed = the outstanding balance of Active and Defaulted loans (Fully Paid and Cancelled loans owe nothing).';
    return $report;
}

function loans_report_outstanding(array $report, array $rows): array
{
    $rows = array_values(array_filter($rows, function ($r) { return $r['outstanding_now'] > 0.009; }));
    $overdue = array_filter($rows, function ($r) { return $r['flag'] !== ''; });
    $report['rows'] = $rows;
    $report['summary'] = [
        ['Open Loans', number_format(count($rows))],
        ['Still Owed', report_money(loans_report_sum($rows, 'outstanding_now'))],
        ['Overdue Loans', number_format(count($overdue))],
        ['Overdue Amount', report_money(loans_report_sum(array_values($overdue), 'outstanding_now'))],
    ];
    $report['columns'] = [
        ['key' => 'lender', 'label' => 'Lender', 'w' => 13],
        ['key' => 'loan_type', 'label' => 'Type', 'w' => 9],
        ['key' => 'loan_start_date', 'label' => 'Start', 'w' => 8, 'type' => 'date'],
        ['key' => 'maturity_date', 'label' => 'Maturity', 'w' => 8, 'type' => 'date'],
        ['key' => 'installment_amount', 'label' => 'Installment (RWF)', 'w' => 10, 'align' => 'R', 'type' => 'money'],
        ['key' => 'repayment_frequency', 'label' => 'Every', 'w' => 7],
        ['key' => 'loan_amount', 'label' => 'Borrowed (RWF)', 'w' => 10, 'align' => 'R', 'type' => 'money'],
        ['key' => 'repaid_pct', 'label' => 'Repaid', 'w' => 6, 'align' => 'R'],
        ['key' => 'outstanding_now', 'label' => 'Owed (RWF)', 'w' => 10, 'align' => 'R', 'type' => 'money'],
        ['key' => 'status', 'label' => 'Status', 'w' => 7],
        ['key' => 'flag', 'label' => 'Note', 'w' => 10],
    ];
    $report['totals'] = ['loan_amount' => loans_report_sum($rows, 'loan_amount'), 'outstanding_now' => loans_report_sum($rows, 'outstanding_now')];
    $report['totals_label'] = 'TOTAL OWED';
    $report['notes'][] = 'Overdue = the maturity date has passed but money is still owed. Repaid = principal repaid as a share of the amount borrowed.';
    return $report;
}

function loans_report_maturity(array $report, array $rows): array
{
    $report['rows'] = $rows;
    $report['summary'] = [
        ['Loans Maturing', number_format(count($rows))],
        ['Still Owed on Them', report_money(loans_report_sum($rows, 'outstanding_now'))],
        ['Already Overdue', number_format(count(array_filter($rows, function ($r) { return $r['flag'] !== ''; })))],
    ];
    $report['columns'] = [
        ['key' => 'maturity_date', 'label' => 'Maturity', 'w' => 9, 'type' => 'date'],
        ['key' => 'days_to_maturity', 'label' => 'Days Left', 'w' => 7, 'align' => 'R', 'type' => 'int'],
        ['key' => 'lender', 'label' => 'Lender', 'w' => 14],
        ['key' => 'loan_type', 'label' => 'Type', 'w' => 10],
        ['key' => 'installment_amount', 'label' => 'Installment (RWF)', 'w' => 11, 'align' => 'R', 'type' => 'money'],
        ['key' => 'loan_amount', 'label' => 'Borrowed (RWF)', 'w' => 11, 'align' => 'R', 'type' => 'money'],
        ['key' => 'outstanding_now', 'label' => 'Owed (RWF)', 'w' => 11, 'align' => 'R', 'type' => 'money'],
        ['key' => 'status', 'label' => 'Status', 'w' => 8],
        ['key' => 'flag', 'label' => 'Note', 'w' => 11],
    ];
    $report['totals'] = ['loan_amount' => loans_report_sum($rows, 'loan_amount'), 'outstanding_now' => loans_report_sum($rows, 'outstanding_now')];
    $report['notes'][] = 'Open loans (Active and Defaulted) whose maturity date falls in the period. Days Left is negative when the date has already passed.';
    return $report;
}

function loans_report_lenders(array $report, array $rows): array
{
    $by = [];
    foreach ($rows as $r) {
        $k = $r['lender'];
        if (!isset($by[$k])) {
            $by[$k] = ['lender' => $k, 'loans' => 0, 'open' => 0, 'loan_amount' => 0.0, 'principal_repaid' => 0.0,
                       'interest_paid' => 0.0, 'outstanding_now' => 0.0];
        }
        $by[$k]['loans']++;
        if (in_array($r['status'], ['Active', 'Defaulted'], true)) { $by[$k]['open']++; }
        foreach (['loan_amount', 'principal_repaid', 'interest_paid', 'outstanding_now'] as $f) { $by[$k][$f] += (float) $r[$f]; }
    }
    $out = array_values($by);
    usort($out, function ($a, $b) { return $b['outstanding_now'] <=> $a['outstanding_now']; });

    $report['rows'] = $out;
    $report['summary'] = [
        ['Lenders', number_format(count($out))],
        ['Borrowed', report_money(loans_report_sum($out, 'loan_amount'))],
        ['Interest Paid', report_money(loans_report_sum($out, 'interest_paid'))],
        ['Still Owed', report_money(loans_report_sum($out, 'outstanding_now'))],
    ];
    $report['columns'] = [
        ['key' => 'lender', 'label' => 'Lender', 'w' => 20],
        ['key' => 'loans', 'label' => 'Loans', 'w' => 7, 'align' => 'R', 'type' => 'int'],
        ['key' => 'open', 'label' => 'Open', 'w' => 7, 'align' => 'R', 'type' => 'int'],
        ['key' => 'loan_amount', 'label' => 'Borrowed (RWF)', 'w' => 14, 'align' => 'R', 'type' => 'money'],
        ['key' => 'principal_repaid', 'label' => 'Principal Repaid (RWF)', 'w' => 14, 'align' => 'R', 'type' => 'money'],
        ['key' => 'interest_paid', 'label' => 'Interest Paid (RWF)', 'w' => 13, 'align' => 'R', 'type' => 'money'],
        ['key' => 'outstanding_now', 'label' => 'Owed (RWF)', 'w' => 14, 'align' => 'R', 'type' => 'money'],
    ];
    $report['totals'] = [
        'loans' => array_sum(array_column($out, 'loans')), 'open' => array_sum(array_column($out, 'open')),
        'loan_amount' => loans_report_sum($out, 'loan_amount'), 'principal_repaid' => loans_report_sum($out, 'principal_repaid'),
        'interest_paid' => loans_report_sum($out, 'interest_paid'), 'outstanding_now' => loans_report_sum($out, 'outstanding_now'),
    ];
    $report['notes'][] = 'All loans except Cancelled, grouped by lender. Interest paid is the cost of borrowing from each lender.';
    return $report;
}

/* ---------------------------------------------------------------------------------------- */

function loans_report_empty(string $title, array $filters, string $type): array
{
    return [
        'error' => null, 'type' => $type, 'title' => $title, 'filters' => $filters,
        'summary' => [], 'columns' => [], 'rows' => [], 'totals' => [], 'totals_label' => 'TOTAL',
        'footer_summary' => [], 'notes' => [],
    ];
}

// NEW: every repayment made in the period, split into principal and interest.
function loans_report_repayments(mysqli $conn, string $title, array $filters, array $where, string $bt, array $params): array
{
    $report = loans_report_empty($title, $filters, 'repayments');
    $rows = sales_report_fetch($conn, "SELECT p.payment_date, p.amount, p.principal_portion, p.interest_portion, p.remaining_balance, p.notes,
            l.lender, l.loan_type, u.names AS recorded_by_name
        FROM loan_payments p JOIN loans l ON l.id = p.loan_id LEFT JOIN users u ON u.id = p.recorded_by"
        . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY p.payment_date, p.id", $bt, $params);

    $report['rows'] = $rows;
    $total = loans_report_sum($rows, 'amount');
    $principal = loans_report_sum($rows, 'principal_portion');
    $interest = loans_report_sum($rows, 'interest_portion');
    $report['summary'] = [
        ['Repayments', number_format(count($rows))],
        ['Total Paid', report_money($total)],
        ['Principal (Capital Fund)', report_money($principal)],
        ['Interest (Operating Fund)', report_money($interest)],
    ];
    $report['columns'] = [
        ['key' => 'payment_date', 'label' => 'Date', 'w' => 9, 'type' => 'date'],
        ['key' => 'lender', 'label' => 'Lender', 'w' => 14],
        ['key' => 'loan_type', 'label' => 'Type', 'w' => 10],
        ['key' => 'amount', 'label' => 'Paid (RWF)', 'w' => 11, 'align' => 'R', 'type' => 'money'],
        ['key' => 'principal_portion', 'label' => 'Principal (RWF)', 'w' => 11, 'align' => 'R', 'type' => 'money'],
        ['key' => 'interest_portion', 'label' => 'Interest (RWF)', 'w' => 11, 'align' => 'R', 'type' => 'money'],
        ['key' => 'remaining_balance', 'label' => 'Owed After (RWF)', 'w' => 11, 'align' => 'R', 'type' => 'money'],
        ['key' => 'notes', 'label' => 'Notes', 'w' => 13],
        ['key' => 'recorded_by_name', 'label' => 'Recorded By', 'w' => 10],
    ];
    $report['totals'] = ['amount' => $total, 'principal_portion' => $principal, 'interest_portion' => $interest];
    $report['notes'][] = 'Each repayment is applied to the oldest unpaid installment, interest first. The principal part is paid from the RM Capital Fund (it reduces what is owed); the interest part from the RM Business Operating Fund (it is a cost of borrowing).';
    return $report;
}

// NEW: unpaid installments of open loans that are due up to the end of the period, overdue first.
function loans_report_installments(mysqli $conn, string $title, array $filters, array $where, string $bt, array $params): array
{
    $report = loans_report_empty($title, $filters, 'installments');
    $where[] = "s.status <> 'Paid'";
    $rows = sales_report_fetch($conn, "SELECT s.due_date, s.installment_no, s.principal_due, s.interest_due, s.amount_due, s.paid_amount,
            (s.amount_due - s.paid_amount) AS remaining, DATEDIFF(s.due_date, CURDATE()) AS days, s.status AS inst_status,
            l.lender, l.loan_type, l.repayment_period
        FROM loan_schedule s JOIN loans l ON l.id = s.loan_id
        WHERE " . implode(' AND ', $where) . "
        HAVING remaining > 0.009
        ORDER BY s.due_date, l.lender, s.installment_no", $bt, $params);

    $overdueAmt = 0.0; $overdueCount = 0; $dueAmt = 0.0;
    foreach ($rows as &$r) {
        $days = (int) $r['days'];
        $r['installment'] = $r['installment_no'] . ' of ' . $r['repayment_period'];
        if ($days < 0) {
            $r['flag'] = 'Overdue ' . abs($days) . ' days';
            $overdueAmt += (float) $r['remaining']; $overdueCount++;
        } elseif ($days === 0) {
            $r['flag'] = 'Due today';
            $dueAmt += (float) $r['remaining'];
        } else {
            $r['flag'] = 'In ' . $days . ' days';
            $dueAmt += (float) $r['remaining'];
        }
        if ($r['inst_status'] === 'Partial') { $r['flag'] .= ' (part paid)'; }
    }
    unset($r);

    $report['rows'] = $rows;
    $report['summary'] = [
        ['Installments', number_format(count($rows))],
        ['To Pay in Total', report_money($overdueAmt + $dueAmt)],
        ['Overdue', number_format($overdueCount) . ' — ' . report_money($overdueAmt)],
        ['Coming Due', report_money($dueAmt)],
    ];
    $report['columns'] = [
        ['key' => 'due_date', 'label' => 'Due Date', 'w' => 9, 'type' => 'date'],
        ['key' => 'flag', 'label' => 'When', 'w' => 12],
        ['key' => 'lender', 'label' => 'Lender', 'w' => 13],
        ['key' => 'loan_type', 'label' => 'Type', 'w' => 9],
        ['key' => 'installment', 'label' => 'Installment', 'w' => 8],
        ['key' => 'principal_due', 'label' => 'Principal (RWF)', 'w' => 10, 'align' => 'R', 'type' => 'money'],
        ['key' => 'interest_due', 'label' => 'Interest (RWF)', 'w' => 10, 'align' => 'R', 'type' => 'money'],
        ['key' => 'amount_due', 'label' => 'Due (RWF)', 'w' => 10, 'align' => 'R', 'type' => 'money'],
        ['key' => 'paid_amount', 'label' => 'Paid (RWF)', 'w' => 9, 'align' => 'R', 'type' => 'money'],
        ['key' => 'remaining', 'label' => 'To Pay (RWF)', 'w' => 10, 'align' => 'R', 'type' => 'money'],
    ];
    $report['totals'] = [
        'principal_due' => loans_report_sum($rows, 'principal_due'), 'interest_due' => loans_report_sum($rows, 'interest_due'),
        'amount_due' => loans_report_sum($rows, 'amount_due'), 'paid_amount' => loans_report_sum($rows, 'paid_amount'),
        'remaining' => loans_report_sum($rows, 'remaining'),
    ];
    $report['totals_label'] = 'TOTAL TO PAY';
    $report['notes'][] = 'Installments of Active and Defaulted loans that are not fully paid, due up to the end of the chosen period. Overdue installments are always included. Use it to make sure the RM Capital Fund (principal) and the Operating Fund (interest) have enough money in time.';
    return $report;
}