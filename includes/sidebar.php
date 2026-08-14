<?php
$currentPath = $_SERVER['REQUEST_URI'] ?? '';
function navActive($segment, $currentPath) {
    return str_contains($currentPath, $segment) ? 'active' : '';
}
$userName = $_SESSION['user_name'] ?? 'Admin';
$initials = strtoupper(substr($userName, 0, 1) . (strpos($userName, ' ') !== false ? substr($userName, strpos($userName, ' ') + 1, 1) : ''));
?>

<div class="sidebar">

    <h3>
        <img src="<?= BASE_URL ?>/assets/images/logo.jpg"
             alt="Rise Motive Logo"
             style="width:36px; height:36px; object-fit:contain; vertical-align:middle; margin-right:8px; border-radius:8px;">
        MY MOTIVE
    </h3>

    <a href="../dashboard/index.php" class="<?= navActive('dashboard', $currentPath) ?>">
        <i class="bi bi-house-door-fill"></i> Dashboard
    </a>

    <?php if (current_user_role() === 'Admin') { ?>
    <a href="../departments/index.php" class="<?= navActive('departments', $currentPath) ?>">
        <i class="bi bi-building"></i> Departments
    </a>
    <?php } ?>

    <?php if (current_user_role() === 'Admin') { ?>
    <a href="../users/index.php" class="<?= navActive('users', $currentPath) ?>">
        <i class="bi bi-people-fill"></i> Employees
    </a>
    <?php } ?>

    <a href="../attendance/index.php" class="<?= navActive('attendance', $currentPath) ?>">
        <i class="bi bi-calendar-check"></i> Attendance
    </a>

    <a href="../leave/index.php" class="<?= navActive('leave', $currentPath) ?>">
        <i class="bi bi-calendar-plus"></i> Leave Requests
    </a>

    <a href="../projects/index.php" class="<?= navActive('projects', $currentPath) ?>">
        <i class="bi bi-folder-fill"></i> Projects
    </a>

    <a href="../tasks/index.php" class="<?= navActive('tasks', $currentPath) ?>">
        <i class="bi bi-check2-square"></i> Tasks
    </a>

    <a href="../transactions/index.php" class="<?= navActive('transactions', $currentPath) ?>">
        <i class="bi bi-cash-stack"></i> Transactions
    </a>

    <a href="../products/index.php" class="<?= navActive('products', $currentPath) ?>">
        <i class="bi bi-box-seam"></i> Products
    </a>

    <a href="../customers/index.php" class="<?= navActive('customers', $currentPath) ?>">
        <i class="bi bi-person-lines-fill"></i> Customers
    </a>

    <a href="../sales/index.php" class="<?= navActive('sales', $currentPath) ?>">
        <i class="bi bi-cart-check-fill"></i> Sales
    </a>

    <?php if (current_user_role() === 'Admin') { ?>
    <a href="../payroll/index.php" class="<?= navActive('payroll', $currentPath) ?>">
        <i class="bi bi-wallet2"></i> Payroll
    </a>
    <?php } else { ?>
    <a href="../payroll/my.php" class="<?= navActive('payroll', $currentPath) ?>">
        <i class="bi bi-wallet2"></i> My Payslips
    </a>
    <?php } ?>

    <a href="../notifications/index.php" class="<?= navActive('notifications', $currentPath) ?>">
        <i class="bi bi-bell-fill"></i> Notifications
    </a>

    <div style="border-top:1px solid rgba(255,255,255,.08); margin:14px 10px 10px; padding-top:14px;">

        <div style="display:flex; align-items:center; gap:10px; padding:0 22px 12px;">
            <div style="width:34px; height:34px; border-radius:50%; background:var(--accent-blue); color:#fff; display:flex; align-items:center; justify-content:center; font-size:13px; font-weight:600; flex-shrink:0;">
                <?= htmlspecialchars($initials ?: 'A', ENT_QUOTES, 'UTF-8'); ?>
            </div>
            <div style="min-width:0;">
                <div style="color:#fff; font-size:13px; font-weight:500; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;">
                    <?= htmlspecialchars($userName, ENT_QUOTES, 'UTF-8'); ?>
                </div>
                <div style="color:rgba(255,255,255,.5); font-size:11px;">Signed in</div>
            </div>
        </div>

        <a href="../../logout.php" style="color:rgba(255,255,255,.6);">
            <i class="bi bi-box-arrow-right"></i> Logout
        </a>

    </div>

</div>

<div class="content">

    <div class="topbar">
        <button class="menu-toggle-btn" onclick="toggleSidebar()" aria-label="Toggle menu">
            <i class="bi bi-list"></i>
        </button>
       <?php $searchScope = $pageSearchScope ?? 'all'; ?>
<form class="topbar-search" method="GET" action="../search/index.php" id="topbarSearchForm">
    <i class="bi bi-search"></i>
    <input type="hidden" name="scope" value="<?= htmlspecialchars($searchScope, ENT_QUOTES, 'UTF-8'); ?>">
    <input type="text" name="q" id="topbarSearchInput" placeholder="Search anything..." value="<?= htmlspecialchars($_GET['q'] ?? '', ENT_QUOTES, 'UTF-8'); ?>" autocomplete="off">
</form>
        <div class="topbar-icons">
            <a href="../notifications/index.php" class="topbar-icon-btn">
                <i class="bi bi-bell"></i>
                <span class="topbar-badge">3</span>
            </a>
            <div class="dropdown">
    <div class="topbar-org dropdown-toggle" role="button" data-bs-toggle="dropdown" aria-expanded="false" style="cursor:pointer;">
        <div class="topbar-org-avatar">
            <i class="bi bi-building"></i>
        </div>
        <span class="d-none d-md-inline"><?= htmlspecialchars($_SESSION['user_name'] ?? 'Account', ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
    <ul class="dropdown-menu dropdown-menu-end">
        <li><a class="dropdown-item" href="../users/profile.php"><i class="bi bi-person-circle me-2"></i>Profile</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item text-danger" href="../../logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
    </ul>
</div>
        </div>
    </div>