<?php
/**
 * NEW FILE: modules/staff_cards/preview.php — preview before publishing.
 *   ?id=N            one card exactly as staff will see it (also drafts and pending ones)
 *   POST from edit   the form, not saved yet
 *   (nothing)        the carousel as it is now, the next items in order with their selection score
 */
require __DIR__ . '/common.php';
sdc_require($conn, 'any');
$base = sdc_base();
$one = null;
$note = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['preview'])) {
    sdc_require($conn, 'manage');
    if (!sdc_csrf_ok()) { exit('The form expired.'); }
    $cat = isset(SDC_CATS[$_POST['cat'] ?? '']) ? $_POST['cat'] : 'reminder';
    $old = !empty($_POST['id']) ? sdc_card($conn, (int) $_POST['id']) : null;
    [$r, $err] = sdc_form_card($conn, $cat, $_POST, $old);
    $one = $r + ['id' => 0, 'status' => 'Draft'];
    $note = $err ? 'Not ready to save yet: ' . $err : 'Not saved. Close this tab and save the card on the form.';
    if (!empty($_FILES['image']['name'])) { $note .= ' A newly chosen image appears after saving.'; }
} elseif (!empty($_GET['id'])) {
    $one = sdc_card($conn, (int) $_GET['id']);
    if (!$one) { sdc_go('index.php', 'error', 'Card not found.'); }
}

include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
sdc_tabs($conn, 'preview.php', 'Preview');
echo sdc_css();
if ($one) {
    $items = sdc_pool($conn, null, $one);
    echo '<div class="small text-muted mb-2">' . sdc_e(SDC_CATS[$one['category']][0]) . ' · ' . sdc_e(sdc_status_label($one)) . ($note ? ' · ' . sdc_e($note) : '') . '</div>';
    if (!$items) { echo '<div class="alert alert-warning py-2 small">This card cannot be shown: the employee is not active or the item no longer exists.</div>'; }
    else {
        $it = $items[0];
        echo '<div class="sdc" style="max-width:860px"><div class="sdc-stage">' . sdc_card_html($conn, $it, $base, sdc_get($conn, 'employee_photos') === '1') . '</div></div>';
        $why = [];
        if (($one['status'] ?? '') !== 'Published') { $why[] = 'it is not published' . (in_array($one['category'], SDC_NEEDS_APPROVAL, true) ? ' / approved' : ''); }
        if (($one['label'] ?? '') === 'Excluded') { $why[] = 'it is labelled Excluded'; }
        if (sdc_get($conn, 'cat_' . $one['category']) !== '1') { $why[] = 'its category is switched off'; }
        if (!empty($one['end_date']) && $one['end_date'] < date('Y-m-d')) { $why[] = 'its end date has passed'; }
        if (!empty($one['start_date']) && $one['start_date'] > date('Y-m-d')) { $why[] = 'it starts on ' . date('d M Y', strtotime($one['start_date'])); }
        if (in_array('out', $it['flags'], true) && $one['category'] === 'product' && sdc_get($conn, 'stock_policy') === 'exclude') { $why[] = 'the product is out of stock (stock policy: hide)'; }
        if ($one['category'] === 'employee' && !in_array((string) $one['month'], sdc_recognition_months(), true)) { $why[] = 'the recognition month is not last month or this month'; }
        echo $why ? '<div class="small text-danger">Not on the Dashboard now: ' . sdc_e(implode('; ', $why)) . '.</div>' : '<div class="small text-success">It can appear on the Dashboard now.</div>';
    }
    echo '<a href="' . (!empty($one['id']) ? 'edit.php?id=' . (int) $one['id'] : 'index.php') . '" class="btn btn-link btn-sm px-0 mt-2">Back to the card</a>';
} else {
    $viewer = null;
    $as = (string) ($_GET['as'] ?? '');
    if (preg_match('/^(Admin|Manager|Employee)(?::(\d+))?$/', $as, $m)) { $viewer = ['role' => $m[1], 'department_id' => (int) ($m[2] ?? 0)]; }
    $list = sdc_playlist($conn, $viewer);
    $depts = mysqli_fetch_all(mysqli_query($conn, 'SELECT id, name FROM departments WHERE is_active = 1 ORDER BY name'), MYSQLI_ASSOC);
    ?>
    <form class="d-flex gap-2 align-items-center mb-2 small"><span class="text-muted">See it as</span><select name="as" class="form-select form-select-sm" style="max-width:260px" onchange="this.form.submit()"><option value="">Me</option>
        <?php foreach (['Admin', 'Manager', 'Employee'] as $ro) { ?><option value="<?= $ro; ?>" <?= $as === $ro ? 'selected' : ''; ?>><?= $ro; ?> (no department)</option><?php foreach ($depts as $dp) { $k = $ro . ':' . (int) $dp['id']; ?><option value="<?= $k; ?>" <?= $as === $k ? 'selected' : ''; ?>><?= $ro; ?> · <?= sdc_e($dp['name']); ?></option><?php } } ?></select>
        <span class="text-muted">Rotation: every <?= (int) sdc_interval($conn); ?> s</span></form>
    <?php if (!$list) { echo '<div class="alert alert-warning py-2 small">Nothing to show: every category is off or has no published content.</div>'; } ?>
    <p class="small text-muted mb-2">The next <?= count($list); ?> items, in order. Categories take turns; within a category, Featured / higher priority, stock, season, customer demand and how long ago it was shown decide.</p>
    <div class="row g-2">
    <?php foreach ($list as $n => $it) { ?>
        <div class="col-lg-6"><div class="sdc mb-0"><div class="sdc-stage" style="min-height:0"><?= sdc_card_html($conn, $it, $base, sdc_get($conn, 'employee_photos') === '1'); ?></div>
            <div class="sdc-nav small text-muted" style="justify-content:flex-start">#<?= $n + 1; ?> · score <?= sdc_e($it['score']); ?> · <?= sdc_e($it['label']); ?> P<?= (int) $it['priority']; ?> · shown <?= (int) $it['shown']; ?>× (30 days)<?= $it['card'] && !empty($it['card']['id']) ? ' · <a href="edit.php?id=' . (int) $it['card']['id'] . '">edit</a>' : ' · automatic'; ?></div></div></div>
    <?php } ?>
    </div>
<?php } ?>
<?php include __DIR__ . '/../../includes/footer.php'; ?>