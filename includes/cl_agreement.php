<?php
/**
 * NEW FILE: includes/cl_agreement.php — the printable loan agreement of an approved offer (customer and staff side).
 */
require_once __DIR__ . '/cl_helpers.php';

function cl_agreement_html(mysqli $conn, array $a, array $c): string
{
    $h = function ($v) { return htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8'); };
    $id = (int) $a['id'];
    $sched = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM cl_schedule WHERE application_id = $id ORDER BY no"), MYSQLI_ASSOC);
    $tot = array_sum(array_column($sched, 'amount_due'));
    $rows = '';
    foreach ($sched as $r) { $rows .= '<tr><td>' . (int) $r['no'] . '</td><td>' . date('d M Y', strtotime($r['due_date'])) . '</td><td class="r">' . number_format((float) $r['principal']) . '</td><td class="r">' . number_format((float) $r['interest']) . '</td><td class="r">' . number_format((float) $r['fee']) . '</td><td class="r">' . number_format((float) $r['amount_due']) . '</td></tr>'; }
    $p = json_decode((string) $a['personal'], true) ?: [];
    $signed = $a['accepted_at'] ? 'Signed electronically by <strong>' . $h($a['accepted_name']) . '</strong> on ' . date('d M Y \a\t H:i', strtotime($a['accepted_at'])) . '.' : '<em>Not signed yet: the customer accepts it in the Customer Portal.</em>';
    $testing = cl_testing($conn) ? '<p class="note">PILOT PHASE: this agreement is issued for assessment only. No funds are disbursed until RISE MOTIVE has its lending authorisation or a licensed financial partner.</p>' : '';
    return '<!doctype html><html><head><meta charset="utf-8"><title>Loan agreement ' . cl_ref($id, $a['created_at']) . '</title><style>
        body{font-family:Arial,sans-serif;color:#1E2333;max-width:820px;margin:24px auto;padding:0 16px;font-size:13px;line-height:1.5}
        h1{color:#1E2FE0;font-size:20px;margin-bottom:2px} table{width:100%;border-collapse:collapse;margin:10px 0} td,th{border:1px solid #D8DCE8;padding:5px 7px;text-align:left}
        th{background:#EEF3FF} .r{text-align:right} .note{background:#EEF3FF;padding:8px;border-left:3px solid #1E2FE0} @media print{.noprint{display:none}}
    </style></head><body>
    <div class="noprint" style="text-align:right"><button onclick="window.print()">Print / Save as PDF</button></div>
    <h1>LOAN AGREEMENT</h1><div>Reference <strong>' . cl_ref($id, $a['created_at']) . '</strong> · issued ' . date('d M Y', strtotime($a['offered_at'] ?? 'now')) . '</div>' . $testing . '
    <h3>1. Parties</h3><p><strong>Lender:</strong> RISE MOTIVE Ltd (TIN 122923513), Kicukiro, Kigali, or its licensed financial partner.<br>
    <strong>Borrower:</strong> ' . $h($c['name']) . ', Customer ID ' . portal_customer_code((int) $c['id']) . ', National ID ' . $h($p['national_id'] ?? $c['national_id'] ?? '') . ', phone ' . $h($c['phone']) . ', ' . $h(trim(($c['address'] ?? '') . ', ' . ($c['sector'] ?? '') . ', ' . ($c['district'] ?? ''), ', ')) . '.</p>
    <h3>2. Loan</h3><table><tr><th>Principal</th><td>' . cl_money($a['approved_amount']) . '</td></tr><tr><th>Purpose</th><td>' . $h($a['purpose'] === 'Other' ? $a['purpose_other'] : $a['purpose']) . '</td></tr>
        <tr><th>Interest</th><td>' . (float) $a['monthly_rate'] . '% per month, ' . ($a['interest_method'] === 'reducing' ? 'on the reducing balance' : 'flat on the principal') . '</td></tr>
        <tr><th>Total to repay</th><td><strong>' . cl_money($tot) . '</strong> in ' . count($sched) . ' ' . $h(strtolower((string) $a['frequency'])) . ' instalment(s)</td></tr>
        <tr><th>Disbursement</th><td>' . $h($a['disbursement_method']) . '</td></tr></table>
    <h3>3. Repayment schedule</h3><table><tr><th>#</th><th>Due date</th><th class="r">Principal</th><th class="r">Interest</th><th class="r">Fees</th><th class="r">Amount due (RWF)</th></tr>' . $rows . '</table>
    <h3>4. Terms</h3><div style="white-space:pre-wrap">' . $h(cl_setting($conn, 'terms')) . '</div>
    <p>Late payment: an instalment not paid by its due date is overdue' . ((float) cl_setting($conn, 'late_fee_pct') > 0 ? ' and bears a late fee of ' . (float) cl_setting($conn, 'late_fee_pct') . '% of the late amount' : '') . '. Repeated non-payment may lead to default, recovery action and reporting as permitted by law.</p>
    <p>Early repayment is allowed at any time. Payments count once verified by RISE MOTIVE. Disputes are first settled amicably, then before the competent courts of Rwanda.</p>
    <h3>5. Privacy</h3><p>' . $h(cl_setting($conn, 'privacy')) . '</p>
    <h3>6. Acceptance</h3><p>' . $signed . '</p></body></html>';
}