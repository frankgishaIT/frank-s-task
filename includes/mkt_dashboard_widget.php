<?php
/**
 * NEW FILE: includes/mkt_dashboard_widget.php — Marketing & Growth summary on the main dashboard (for users with
 * Marketing access). Included by modules/dashboard/index.php. Never breaks the dashboard if anything is missing.
 */
try {
    if (!function_exists('access_can')) { require_once __DIR__ . '/access_control.php'; }
    if (!access_can('marketing.view', $conn)) { return; }
    require_once __DIR__ . '/mkt_helpers.php';
    mkt_ensure($conn);
    if ((int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'mkt_leads'"))[0] === 0) { return; }
    $mUid = (int) current_user_id();
    $mOne = function ($sql) use ($conn) { $r = mysqli_query($conn, $sql); return $r ? (int) (mysqli_fetch_row($r)[0] ?? 0) : 0; };
    $mActive = $mOne("SELECT COUNT(*) FROM mkt_campaigns WHERE status = 'Active'");
    $mLeads = $mOne("SELECT COUNT(*) FROM mkt_leads WHERE created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')");
    $mPaying = $mOne("SELECT COUNT(*) FROM mkt_acquisitions WHERE status = 'Paying' AND first_paid_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')");
    $mOverdue = $mOne("SELECT COUNT(*) FROM mkt_leads WHERE owner_id = $mUid AND status NOT IN ('Won', 'Lost') AND next_followup < CURDATE()");
    $mWaiting = access_can('marketing.approve', $conn) ? $mOne("SELECT (SELECT COUNT(*) FROM mkt_campaigns WHERE status = 'Pending Approval') + (SELECT COUNT(*) FROM mkt_plans WHERE status = 'Submitted for Approval')") : 0;
    ?>
<div class="row g-3 mb-4">
    <div class="col-12"><div class="rm-card">
        <div class="card-header d-flex justify-content-between align-items-center"><span>📣 Marketing &amp; Growth <span class="text-muted fw-normal">· this month</span></span><a href="../marketing/index.php" class="small">Open</a></div>
        <div class="card-body"><div class="row g-3 text-center small">
            <div class="col-6 col-md"><div class="text-muted">Active campaigns</div><div class="fs-5 fw-bold"><?= $mActive; ?></div></div>
            <div class="col-6 col-md"><div class="text-muted">New leads</div><div class="fs-5 fw-bold"><?= $mLeads; ?></div></div>
            <div class="col-6 col-md"><div class="text-muted">New paying customers</div><div class="fs-5 fw-bold text-success"><?= $mPaying; ?></div></div>
            <div class="col-6 col-md"><div class="text-muted">My overdue follow-ups</div><div class="fs-5 fw-bold <?= $mOverdue ? 'text-danger' : ''; ?>"><?= $mOverdue ? '<a class="text-danger" href="../marketing/leads.php?overdue=1">' . $mOverdue . '</a>' : 0; ?></div></div>
            <?php if (access_can('marketing.approve', $conn)) { ?><div class="col-6 col-md"><div class="text-muted">Waiting for approval</div><div class="fs-5 fw-bold"><?= $mWaiting; ?></div></div><?php } ?>
        </div></div>
    </div></div>
</div>
<?php
} catch (Throwable $mErr) { error_log('marketing dashboard widget: ' . $mErr->getMessage()); }