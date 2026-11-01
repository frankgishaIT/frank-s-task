<?php
// NEW FILE: modules/kpi/teams.php — teams for shared targets (a team can mix people from several departments).
require __DIR__ . '/kpi_common.php';
kpi_guard($conn, 'kpi.create');
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $a = $_POST['action'] ?? '';
    if ($a === 'create') { $n = mb_substr(trim((string) $_POST['name']), 0, 120); if ($n !== '') { $s = mysqli_prepare($conn, 'INSERT INTO kpi_teams (name) VALUES (?)'); mysqli_stmt_bind_param($s, 's', $n); mysqli_stmt_execute($s); } }
    if ($a === 'members') {
        $tid = (int) $_POST['team_id']; mysqli_query($conn, "DELETE FROM kpi_team_members WHERE team_id = $tid");
        foreach (array_unique(array_map('intval', (array) ($_POST['users'] ?? []))) as $u) { if ($u > 0) { mysqli_query($conn, "INSERT IGNORE INTO kpi_team_members VALUES ($tid, $u)"); } }
    }
    if ($a === 'delete') {
        $tid = (int) $_POST['team_id'];
        if ((int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM kpis WHERE team_id = $tid"))[0] > 0) { header('Location: teams.php?error=' . urlencode('This team has KPIs. Change or cancel them first.')); exit; }
        mysqli_query($conn, "DELETE FROM kpi_team_members WHERE team_id = $tid"); mysqli_query($conn, "DELETE FROM kpi_teams WHERE id = $tid");
    }
    header('Location: teams.php?success=' . urlencode('Saved.')); exit;
}
$teams = mysqli_fetch_all(mysqli_query($conn, 'SELECT * FROM kpi_teams ORDER BY name'), MYSQLI_ASSOC);
$people = mysqli_fetch_all(mysqli_query($conn, 'SELECT id, names FROM users WHERE is_active = 1 ORDER BY names'), MYSQLI_ASSOC);
$members = []; foreach (mysqli_fetch_all(mysqli_query($conn, 'SELECT * FROM kpi_team_members'), MYSQLI_ASSOC) as $m) { $members[(int) $m['team_id']][] = (int) $m['user_id']; }
include '../../includes/header.php'; include '../../includes/sidebar.php';
kpi_tabs('teams.php');
?>
<form method="POST" class="d-flex gap-2 mb-3" style="max-width:480px;"><input type="hidden" name="action" value="create"><input name="name" class="form-control form-control-sm" maxlength="120" required placeholder="New team, e.g. Field Sales Team"><button class="btn btn-primary btn-sm">Add team</button></form>
<div class="row g-3">
<?php foreach ($teams as $t) { $tid = (int) $t['id']; ?>
    <div class="col-md-6 col-xl-4"><div class="card border-0 shadow-sm h-100"><div class="card-body"><h6><?= $e($t['name']); ?> <span class="text-muted small">(<?= count($members[$tid] ?? []); ?>)</span></h6>
        <form method="POST"><input type="hidden" name="action" value="members"><input type="hidden" name="team_id" value="<?= $tid; ?>">
            <div style="max-height:200px; overflow-y:auto;" class="small border rounded p-2 mb-2"><?php foreach ($people as $p) { ?><label class="d-block"><input type="checkbox" class="form-check-input me-1" name="users[]" value="<?= (int) $p['id']; ?>" <?= in_array((int) $p['id'], $members[$tid] ?? [], true) ? 'checked' : ''; ?>><?= $e($p['names']); ?></label><?php } ?></div>
            <button class="btn btn-primary btn-sm">Save members</button></form>
        <form method="POST" class="mt-2" onsubmit="return confirm('Delete this team?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="team_id" value="<?= $tid; ?>"><button class="btn btn-outline-danger btn-sm">Delete team</button></form>
    </div></div></div>
<?php } if (!$teams) { ?><div class="text-muted">No teams yet.</div><?php } ?>
</div>
<?php include '../../includes/footer.php'; ?>