<?php
require '../../config/db.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) { header('Location: index.php?success=Invalid payslip requested.'); exit; }

$statement = mysqli_prepare($conn, 'SELECT payroll.*, users.names AS employee_name, users.email AS employee_email, departments.name AS department_name
    FROM payroll
    INNER JOIN users ON payroll.user_id = users.id
    LEFT JOIN departments ON users.department_id = departments.id
    WHERE payroll.id = ?');
mysqli_stmt_bind_param($statement, 'i', $id);
mysqli_stmt_execute($statement);
$payroll = mysqli_fetch_assoc(mysqli_stmt_get_result($statement));

if (!$payroll) { header('Location: index.php?success=Payslip not found.'); exit; }

// Admins can view any payslip. Everyone else may only view their own.
if (current_user_role() !== 'Admin' && (int) $payroll['user_id'] !== (int) current_user_id()) {
    http_response_code(403);
    include '../../includes/access_denied.php';
    exit;
}

function money($value) {
    return number_format((float) $value, 2);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Payslip - <?= htmlspecialchars($payroll['employee_name'], ENT_QUOTES, 'UTF-8'); ?> - <?= date('F Y', strtotime($payroll['pay_period'])); ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        :root {
            --accent-blue: #1E2FE0;
            --accent-blue-bg: #E6E8FD;
            --ink: #1E2333;
            --muted: #8A90A3;
            --border-soft: #E4E8F2;
            --accent-teal: #0FA968;
            --accent-red: #E24B4A;
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            background: #EEF1F8;
            font-family: 'Segoe UI', Arial, sans-serif;
            color: var(--ink);
        }

        .toolbar {
            max-width: 820px;
            margin: 24px auto 0;
            padding: 0 20px;
            display: flex;
            justify-content: flex-end;
            gap: 10px;
        }

        .toolbar a, .toolbar button {
            border: none;
            border-radius: 10px;
            padding: 10px 18px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        .btn-download {
            background: var(--accent-blue);
            color: #fff;
        }

        .btn-back {
            background: #fff;
            color: var(--ink);
            border: 1px solid var(--border-soft) !important;
        }

        .sheet {
            max-width: 820px;
            margin: 20px auto 60px;
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(20, 24, 60, .08);
            padding: 48px 56px;
        }

        .sheet-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            border-bottom: 2px solid var(--ink);
            padding-bottom: 20px;
            margin-bottom: 28px;
        }

        .brand {
            font-size: 22px;
            font-weight: 800;
            letter-spacing: .02em;
            color: var(--ink);
        }

        .brand-sub {
            font-size: 12px;
            color: var(--muted);
            margin-top: 2px;
        }

        .doc-title {
            text-align: right;
        }

        .doc-title h2 {
            margin: 0;
            font-size: 20px;
            font-weight: 700;
        }

        .doc-title .period {
            font-size: 13px;
            color: var(--muted);
            margin-top: 2px;
        }

        .status-pill {
            display: inline-block;
            margin-top: 8px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 700;
            letter-spacing: .03em;
            text-transform: uppercase;
        }

        .status-paid { background: #E1F7EE; color: var(--accent-teal); }
        .status-draft { background: #F1F3F9; color: var(--muted); }

        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 4px 24px;
            margin-bottom: 32px;
            font-size: 13px;
        }

        .info-grid .label {
            color: var(--muted);
            display: inline-block;
            width: 110px;
        }

        .section-title {
            font-size: 12px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: .04em;
            color: var(--muted);
            margin: 28px 0 10px;
        }

        table.breakdown {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        table.breakdown th {
            text-align: left;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: .03em;
            color: var(--muted);
            padding: 8px 0;
            border-bottom: 1px solid var(--border-soft);
        }

        table.breakdown td {
            padding: 10px 0;
            border-bottom: 1px solid var(--border-soft);
        }

        table.breakdown td.amount { text-align: right; font-variant-numeric: tabular-nums; }
        .text-positive { color: var(--accent-teal); }
        .text-negative { color: var(--accent-red); }

        .net-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 2px solid var(--ink);
        }

        .net-row .label {
            font-size: 15px;
            font-weight: 700;
        }

        .net-row .amount {
            font-size: 26px;
            font-weight: 800;
            color: var(--accent-blue);
        }

        .footnote {
            margin-top: 36px;
            font-size: 11px;
            color: var(--muted);
            line-height: 1.6;
        }

        .signatures {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 40px;
            margin-top: 48px;
        }

        .signature-line {
            border-top: 1px solid var(--ink);
            padding-top: 6px;
            font-size: 12px;
            color: var(--muted);
        }

        @media print {
            body{background:#fff; font-size:16px;}
            .toolbar{display:none;}
            .sheet{box-shadow:none;margin:0;border-radius:0;max-width:100%;padding:24px 20px;}
            .brand{font-size:26px;}
            .brand-sub{font-size:14px;}
            .doc-title h2{font-size:24px;}
            .doc-title .num{font-size:15px;}
            .status-pill{font-size:13px; padding:5px 14px;}
            .info-grid{font-size:15px; gap:8px 24px;}
            .info-grid .label{width:130px;}
            table.items{font-size:15px;}
            table.items th{font-size:13px; padding:10px 0;}
            table.items td{padding:12px 0;}
            .totals{font-size:15px; width:320px;}
            .totals .grand{font-size:22px;}
            .signature-block{font-size:15px;}
            .signature-block .sig-heading{font-size:15px;}
            .signature-block .sig-line{font-size:15px;}
            .footnote{font-size:13px;}
        
        }
        @page {
    size: A4;
    margin: 12mm;
}
    </style>
</head>
<body>

<div class="toolbar">
    <a href="index.php" class="btn-back"><i class="bi bi-arrow-left"></i> Back</a>
    <button class="btn-download" onclick="window.print()"><i class="bi bi-download"></i> Download PDF</button>
</div>

<div class="sheet">
    <div class="sheet-header">
        <div>
            <div class="brand">RISE MOTIVE</div>
            <div class="brand-sub">Payroll &amp; HR Management System</div>
        </div>
        <div class="doc-title">
            <h2>Payslip</h2>
            <div class="period"><?= date('F Y', strtotime($payroll['pay_period'])); ?></div>
            <span class="status-pill <?= $payroll['status'] === 'Paid' ? 'status-paid' : 'status-draft'; ?>"><?= htmlspecialchars($payroll['status'], ENT_QUOTES, 'UTF-8'); ?></span>
        </div>
    </div>

    <div class="info-grid">
        <div><span class="label">Employee</span><?= htmlspecialchars($payroll['employee_name'], ENT_QUOTES, 'UTF-8'); ?></div>
        <div><span class="label">Payslip No.</span>PS-<?= str_pad($payroll['id'], 6, '0', STR_PAD_LEFT); ?></div>
        <div><span class="label">Department</span><?= htmlspecialchars($payroll['department_name'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></div>
        <div><span class="label">Pay Period</span><?= date('01 F Y', strtotime($payroll['pay_period'])); ?> &ndash; <?= date('t F Y', strtotime($payroll['pay_period'])); ?></div>
        <div><span class="label">Email</span><?= htmlspecialchars($payroll['employee_email'] ?? '—', ENT_QUOTES, 'UTF-8'); ?></div>
        <div><span class="label">Paid On</span><?= $payroll['paid_at'] ? date('d M Y', strtotime($payroll['paid_at'])) : 'Not yet paid'; ?></div>
    </div>

    <div class="section-title">Earnings</div>
    <table class="breakdown">
        <tr><th>Description</th><th style="text-align:right;">Amount (RWF)</th></tr>
        <tr><td>Basic Salary</td><td class="amount"><?= money($payroll['basic_salary']); ?></td></tr>
        <tr><td>Overtime Pay <span style="color:var(--muted);">(<?= round((int) $payroll['overtime_minutes'] / 60, 1); ?> hrs)</span></td><td class="amount text-positive">+ <?= money($payroll['overtime_pay']); ?></td></tr>
        <tr><td>Performance Bonus <span style="color:var(--muted);">(<?= $payroll['avg_performance_score'] !== null ? $payroll['avg_performance_score'] . '/100 avg score' : 'no scored tasks'; ?>)</span></td><td class="amount text-positive">+ <?= money($payroll['performance_bonus']); ?></td></tr>
        <tr><td>Sales Commission</td><td class="amount text-positive">+ <?= money($payroll['sales_commission']); ?></td></tr>
        <tr><td>Other Bonus</td><td class="amount text-positive">+ <?= money($payroll['bonus']); ?></td></tr>
    </table>

    <div class="section-title">Deductions</div>
    <table class="breakdown">
        <tr><th>Description</th><th style="text-align:right;">Amount (RWF)</th></tr>
        <tr><td>Attendance Deduction <span style="color:var(--muted);">(<?= (int) $payroll['absent_days']; ?> absent day<?= (int) $payroll['absent_days'] === 1 ? '' : 's'; ?>)</span></td><td class="amount text-negative">&minus; <?= money($payroll['attendance_deduction']); ?></td></tr>
        <tr><td>Other Deductions</td><td class="amount text-negative">&minus; <?= money($payroll['deductions']); ?></td></tr>
    </table>

    <div class="net-row">
        <span class="label">Net Salary</span>
        <span class="amount">RWF <?= money($payroll['net_salary']); ?></span>
    </div>

    <div class="signatures">
        <div class="signature-line">Employee Signature</div>
        <div class="signature-line">Authorized Signature</div>
    </div>

    <div class="footnote">
        This payslip was generated automatically by MY MOTIVE from confirmed attendance and approved task performance records for the stated pay period. Present days: <?= (int) $payroll['present_days']; ?>. Generated on <?= date('d M Y, H:i'); ?>.
    </div>
</div>

</body>
</html>
