<?php
/**
 * NEW FILE: modules/marketing/ai.php — MY MOTIVE AI (Marketing Copilot): 6 tools. Answers are DRAFTS; "Save as draft"
 * creates a Draft marked AI-generated that follows the normal approval. Saving takes the answer from the server log
 * (not from the browser), once per answer.
 */
require __DIR__ . '/mkt_common.php';
require_once __DIR__ . '/../../includes/mkt_ai.php';
mkt_ai_ensure($conn);
$L = mkt_lists($conn);
$tool = isset(MKT_AI_TOOLS[$_GET['tool'] ?? '']) ? $_GET['tool'] : 'campaign';
$why = mkt_ai_unavailable($conn, $me);
$camps = mysqli_fetch_all(mysqli_query($conn, "SELECT * FROM mkt_campaigns WHERE status <> 'Archived' ORDER BY start_date DESC"), MYSQLI_ASSOC);
$plans = mysqli_fetch_all(mysqli_query($conn, "SELECT id, title, created_at FROM mkt_plans WHERE status IN ('Approved', 'Active') ORDER BY title"), MYSQLI_ASSOC);
$langs = ['English', 'Kinyarwanda', 'French'];
$result = null; $resultLog = null;
$num = function ($v) { return max(0, round((float) $v, 2)); };
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'share') { // handled FIRST: sharing never calls the AI
    $lid = (int) $_POST['log_id']; $lg = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM mkt_ai_log WHERE id = $lid AND user_id = $me AND tool = 'weekly' AND status = 'ok'"));
    if ($lg && mkt_is_manager()) { mkt_notify($conn, array_merge(mkt_admin_ids($conn), array_map('intval', array_column(mysqli_fetch_all(mysqli_query($conn, "SELECT id FROM users WHERE is_active = 1 AND LOWER(role) = 'manager'"), MYSQLI_ASSOC), 'id'))), 'Weekly marketing report (AI draft)', 'Open Marketing & Growth → MY MOTIVE AI → history, request #' . $lid . '.'); }
    header('Location: ai.php?view=' . $lid . '&success=' . urlencode('Shared with the managers.')); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string) ($_POST['action'] ?? '');
    try {
        if (!access_can('marketing.create', $conn)) { throw new RuntimeException('You cannot use MY MOTIVE AI.'); }
        if (in_array($act, ['save_plan', 'save_campaign', 'save_material'], true)) {
            // Save: the answer comes from the server log of THIS user's request, once.
            $lid = (int) ($_POST['log_id'] ?? 0); $lg = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM mkt_ai_log WHERE id = $lid AND user_id = $me AND status = 'ok'"));
            if (!$lg) { throw new RuntimeException('That AI answer was not found.'); }
            if ($lg['ref'] !== null && $lg['ref'] !== '') { throw new RuntimeException('This AI answer was already saved (' . $lg['ref'] . ').'); }
            $j = mkt_ai_json((string) $lg['output']); $in = json_decode((string) $lg['input_summary'], true) ?: [];
            if (!$j) { throw new RuntimeException('The AI answer could not be read as a structured draft. Generate it again.'); }
            $chan = implode(', ', array_values(array_intersect(array_map('strval', (array) ($j['channels'] ?? [])), $L['channels']))) ?: null;
            $start = DateTime::createFromFormat('Y-m-d', (string) ($in['start'] ?? '')) ? $in['start'] : date('Y-m-d'); $end = DateTime::createFromFormat('Y-m-d', (string) ($in['end'] ?? '')) && $in['end'] >= $start ? $in['end'] : date('Y-m-d', strtotime($start . ' +1 month'));
            $prods = implode(',', array_values(array_intersect(array_map('intval', (array) ($in['products'] ?? [])), array_map('intval', array_column($L['offerings'], 'id'))))) ?: null;
            mysqli_begin_transaction($conn);
            if ($act === 'save_plan') {
                $title = mb_substr(trim((string) ($j['title'] ?? 'AI marketing plan')), 0, 160); $desc = trim((string) ($j['description'] ?? '') . "\n\n" . (string) ($j['strategy'] ?? ''));
                $s = mysqli_prepare($conn, "INSERT INTO mkt_plans (title, description, objective, product_ids, target_market, customer_type, start_date, end_date, expected_leads, expected_customers, expected_revenue, expected_profit, budget, manager_id, channels, kpis, status, ai_generated, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Draft', 1, ?)");
                $obj = (string) ($j['objective'] ?? ''); $tm = mb_substr((string) ($j['target_market'] ?? ''), 0, 255); $ct = in_array($j['customer_type'] ?? '', $L['customer_types'], true) ? $j['customer_type'] : null;
                $el = (int) $num($j['expected_leads'] ?? 0); $ec = min($el ?: PHP_INT_MAX, (int) $num($j['expected_customers'] ?? 0)); $er = $num($j['expected_revenue'] ?? 0); $ep = min($er ?: PHP_FLOAT_MAX, $num($j['expected_profit'] ?? 0));
                $bd = $num($in['budget'] ?? ($j['budget'] ?? 0)); $kp = is_array($j['kpis'] ?? null) ? implode("\n", $j['kpis']) : (string) ($j['kpis'] ?? '');
                mysqli_stmt_bind_param($s, 'ssssssssiidddissi', $title, $desc, $obj, $prods, $tm, $ct, $start, $end, $el, $ec, $er, $ep, $bd, $me, $chan, $kp, $me); mysqli_stmt_execute($s);
                $row = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM mkt_plans WHERE id = ' . (int) mysqli_insert_id($conn))); $ref = mkt_code('plan', $row); $go = 'plan.php?id=' . (int) $row['id'];
            } elseif ($act === 'save_campaign') {
                $name = mb_substr(trim((string) ($j['name'] ?? 'AI campaign')), 0, 160); $planId = in_array((int) ($in['plan'] ?? 0), array_map('intval', array_column($plans, 'id')), true) ? (int) $in['plan'] : null;
                $desc = trim((string) ($j['description'] ?? '') . (empty($j['activities']) ? '' : "\n\nSuggested activities:\n- " . implode("\n- ", array_map('strval', (array) $j['activities']))));
                $s = mysqli_prepare($conn, "INSERT INTO mkt_campaigns (plan_id, name, objective, description, product_ids, target_audience, target_orgs, message, channels, start_date, end_date, budget, expected_leads, expected_customers, expected_revenue, expected_profit, manager_id, kpis, status, ai_generated, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'Draft', 1, ?)");
                $obj = (string) ($j['objective'] ?? ''); $aud = mb_substr((string) ($j['target_audience'] ?? ''), 0, 255); $org = (string) ($j['target_orgs'] ?? ''); $msgt = (string) ($j['message'] ?? '');
                $el = (int) $num($j['expected_leads'] ?? 0); $ec = min($el ?: PHP_INT_MAX, (int) $num($j['expected_customers'] ?? 0)); $er = $num($j['expected_revenue'] ?? 0); $ep = min($er ?: PHP_FLOAT_MAX, $num($j['expected_profit'] ?? 0));
                $bd = $num($in['budget'] ?? ($j['budget'] ?? 0)); $kp = is_array($j['kpis'] ?? null) ? implode("\n", $j['kpis']) : (string) ($j['kpis'] ?? '');
                mysqli_stmt_bind_param($s, 'issssssssssdiiddisi', $planId, $name, $obj, $desc, $prods, $aud, $org, $msgt, $chan, $start, $end, $bd, $el, $ec, $er, $ep, $me, $kp, $me); mysqli_stmt_execute($s);
                $row = mysqli_fetch_assoc(mysqli_query($conn, 'SELECT * FROM mkt_campaigns WHERE id = ' . (int) mysqli_insert_id($conn))); $ref = mkt_code('campaign', $row); $go = 'campaign.php?id=' . (int) $row['id'];
            } else {
                $i = (int) ($_POST['variant'] ?? 0); $v = $j['variants'][$i] ?? null;
                if (!$v || trim((string) ($v['text'] ?? '')) === '') { throw new RuntimeException('Choose one of the proposed texts.'); }
                $type = in_array($in['type'] ?? '', MKT_MATERIAL_TYPES, true) ? $in['type'] : 'Other'; $camp = in_array((int) ($in['campaign'] ?? 0), array_map('intval', array_column($camps, 'id')), true) ? (int) $in['campaign'] : null;
                $title = mb_substr(trim((string) ($v['title'] ?? $type . ' (' . ($in['language'] ?? '') . ')')), 0, 160); $text = (string) $v['text'];
                $s = mysqli_prepare($conn, "INSERT INTO mkt_materials (campaign_id, title, type, description, status, ai_generated, created_by) VALUES (?, ?, ?, ?, 'Draft', 1, ?)");
                mysqli_stmt_bind_param($s, 'isssi', $camp, $title, $type, $text, $me); mysqli_stmt_execute($s); $ref = 'MAT-' . (int) mysqli_insert_id($conn); $go = 'materials.php';
            }
            $s = mysqli_prepare($conn, 'UPDATE mkt_ai_log SET ref = ? WHERE id = ? AND (ref IS NULL OR ref = \'\')'); mysqli_stmt_bind_param($s, 'si', $ref, $lid); mysqli_stmt_execute($s);
            mkt_audit($conn, 'AI draft saved (' . MKT_AI_TOOLS[$lg['tool']] . ')', $ref, null, 'AI-generated draft, AI request #' . $lid, null, $me);
            mysqli_commit($conn);
            header('Location: ' . $go . (strpos($go, '?') ? '&' : '?') . 'success=' . urlencode($ref . ' saved as an AI-generated DRAFT: review and complete it, then submit it for approval.')); exit;
        }
        // Generate.
        $sys = mkt_ai_system($conn); $in = []; $prompt = '';
        $prodNames = function ($ids) use ($L) { $want = array_map('intval', (array) $ids); return implode(', ', array_column(array_filter($L['offerings'], function ($o) use ($want) { return in_array((int) $o['id'], $want, true); }), 'product_name')); };
        switch ($tool) {
            case 'strategy': case 'campaign':
                $in = ['objective' => trim((string) $_POST['objective']), 'products' => array_map('intval', (array) ($_POST['products'] ?? [])), 'market' => trim((string) ($_POST['market'] ?? '')),
                       'budget' => $num($_POST['budget'] ?? 0), 'start' => (string) ($_POST['start'] ?? ''), 'end' => (string) ($_POST['end'] ?? ''), 'notes' => trim((string) ($_POST['notes'] ?? '')), 'plan' => (int) ($_POST['plan'] ?? 0)];
                if (mb_strlen($in['objective']) < 10) { throw new RuntimeException('Describe the objective (at least a sentence).'); }
                if (!DateTime::createFromFormat('Y-m-d', $in['start']) || !DateTime::createFromFormat('Y-m-d', $in['end']) || $in['end'] < $in['start']) { throw new RuntimeException('Choose the start and end dates.'); }
                $history = array_map(function ($c) use ($conn) { $a = mkt_actuals($conn, [(int) $c['id']]); return ['campaign' => $c['name'], 'leads' => $a['leads'], 'paying' => $a['paying'], 'revenue_rwf' => $a['revenue'], 'spending_rwf' => $a['expenses']]; },
                    array_slice(array_values(array_filter($camps, function ($c) { return in_array($c['status'], ['Completed', 'Evaluated', 'Archived', 'Active'], true); })), 0, 8));
                $prompt = ($tool === 'strategy' ? "Draft a MARKETING PLAN" : "Draft a MARKETING CAMPAIGN") . " for RISE MOTIVE.\nObjective: {$in['objective']}\nProducts / services: " . ($prodNames($in['products']) ?: 'not specified') . "\nTarget market: " . ($in['market'] ?: 'not specified')
                    . "\nBudget: RWF " . number_format($in['budget']) . "\nPeriod: {$in['start']} to {$in['end']}\nNotes: " . ($in['notes'] ?: '-') . "\nAvailable channels (use these names exactly): " . implode(', ', $L['channels'])
                    . ($tool === 'strategy' ? "\nCustomer types (use one exactly): " . implode(', ', $L['customer_types']) : '')
                    . "\nPast campaign results (verified; use them to set realistic targets): " . json_encode($history, JSON_UNESCAPED_UNICODE)
                    . "\nRespond ONLY with a JSON object, no other text, with these keys: "
                    . ($tool === 'strategy' ? '"title", "objective", "description", "target_market", "customer_type", "channels" (array), "kpis" (array of short measurable KPIs), "expected_leads", "expected_customers", "expected_revenue", "expected_profit" (numbers in RWF), "strategy" (the strategy in 6-10 short paragraphs or bullet lines: positioning, key messages, channel plan with budget split, timeline by month, risks, how results will be measured)'
                                            : '"name", "objective", "description", "target_audience", "target_orgs", "message" (the core marketing message), "channels" (array), "kpis" (array), "expected_leads", "expected_customers", "expected_revenue", "expected_profit" (numbers in RWF), "activities" (array of 5-10 concrete activities with timing and budget)')
                    . '. Targets must be realistic for the budget and past results; expected customers cannot exceed expected leads, and profit cannot exceed revenue.';
                break;
            case 'content':
                $in = ['type' => in_array($_POST['type'] ?? '', MKT_MATERIAL_TYPES, true) ? $_POST['type'] : 'Social media post', 'language' => in_array($_POST['language'] ?? '', $langs, true) ? $_POST['language'] : 'English',
                       'tone' => mb_substr(trim((string) ($_POST['tone'] ?? 'Professional and friendly')), 0, 60), 'campaign' => (int) ($_POST['campaign'] ?? 0), 'products' => array_map('intval', (array) ($_POST['products'] ?? [])), 'points' => trim((string) ($_POST['points'] ?? ''))];
                $c = null; foreach ($camps as $x) { if ((int) $x['id'] === $in['campaign']) { $c = $x; } }
                if (!$c && !$in['products'] && $in['points'] === '') { throw new RuntimeException('Choose a campaign or a product, or write the key points.'); }
                $prompt = "Write 3 alternative texts for this marketing material.\nType: {$in['type']}\nLanguage: {$in['language']} (write naturally in this language)\nTone: {$in['tone']}\n"
                    . ($c ? "Campaign: {$c['name']}\nCampaign message: {$c['message']}\nAudience: {$c['target_audience']}\n" : '') . 'Products / services: ' . ($prodNames($in['products']) ?: '-') . "\nKey points: " . ($in['points'] ?: '-')
                    . "\nRespect the format: SMS max 300 characters; social posts short with a call to action; email with a subject line. Include a clear call to action (e.g. call or WhatsApp RISE MOTIVE). Do not invent prices, discounts or guarantees that are not in the key points."
                    . "\nRespond ONLY with JSON: {\"variants\": [{\"title\": \"...\", \"text\": \"...\"}, ...]}";
                break;
            case 'analyst':
                $c = null; foreach ($camps as $x) { if ((int) $x['id'] === (int) ($_POST['campaign'] ?? 0)) { $c = $x; } }
                if (!$c) { throw new RuntimeException('Choose the campaign to analyse.'); }
                $in = ['campaign' => (int) $c['id']];
                $prompt = "Analyse the performance of this campaign using ONLY these verified figures (JSON). Structure: 1) Summary (2-3 sentences), 2) What works, 3) What does not work and likely causes, 4) 5 concrete recommendations for the remaining time (who/what/when), 5) Data gaps.\n" . json_encode(mkt_ai_campaign_facts($conn, $c), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                break;
            case 'advisor': case 'weekly':
                $from = $tool === 'weekly' ? date('Y-m-d', strtotime('-6 days')) : (DateTime::createFromFormat('Y-m-d', (string) ($_POST['from'] ?? '')) ? $_POST['from'] : date('Y-m-d', strtotime('-6 months')));
                $to = date('Y-m-d'); $in = ['from' => $from, 'to' => $to];
                $prompt = ($tool === 'weekly' ? "Write the WEEKLY MARKETING REPORT for management (period below). Structure: Highlights, Numbers of the week, Campaigns at risk, Follow-up discipline (overdue follow-ups), Priorities for next week. Keep it to one page."
                    : "Act as a growth advisor. Using ONLY these verified figures, identify: 1) the best-performing sources, offerings and campaigns, 2) where money or effort is wasted, 3) 5 growth opportunities ranked by expected impact and cost, 4) 3 risks. Be specific and realistic.")
                    . "\nFigures (JSON):\n" . json_encode(mkt_ai_company_facts($conn, $from, $to), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
                break;
        }
        $text = mkt_ai_call($conn, $me, $tool, $sys, $prompt, json_encode($in, JSON_UNESCAPED_UNICODE));
        $resultLog = (int) $GLOBALS['MKT_AI_LAST_LOG']; $result = $text;
    } catch (RuntimeException $ex) { @mysqli_rollback($conn); $error = $ex->getMessage(); }
}
if (!$result && ($v = (int) ($_GET['view'] ?? 0))) { // an earlier answer (own; Managers / Admins: all)
    $lg = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM mkt_ai_log WHERE id = $v AND status = 'ok'" . (mkt_is_manager() ? '' : " AND user_id = $me")));
    if ($lg) { $result = $lg['output']; $resultLog = (int) $lg['id']; $tool = $lg['tool']; }
}
$render = function (string $t) { // light formatting of the AI text (escaped first)
    $h = htmlspecialchars($t, ENT_QUOTES, 'UTF-8');
    $h = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $h); $h = preg_replace('/^#{1,4}\s*(.+)$/m', '<div class="fw-bold mt-2">$1</div>', $h);
    return nl2br($h);
};
$hist = mysqli_fetch_all(mysqli_query($conn, 'SELECT l.id, l.tool, l.status, l.ref, l.error, l.created_at, u.names FROM mkt_ai_log l JOIN users u ON u.id = l.user_id' . (mkt_is_manager() ? '' : " WHERE l.user_id = $me") . ' ORDER BY l.id DESC LIMIT 25'), MYSQLI_ASSOC);
$usedToday = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM mkt_ai_log WHERE user_id = $me AND status = 'ok' AND created_at >= CURDATE()"))[0];
$j = $result ? mkt_ai_json($result) : null;
include '../../includes/header.php'; include '../../includes/sidebar.php';
mkt_tabs('ai.php');
?>
<?php if (isset($error)) { ?><div class="alert alert-danger"><?= $e($error); ?></div><?php } ?>
<?php if ($why) { ?><div class="alert alert-warning"><i class="bi bi-robot"></i> <?= $e($why); ?></div><?php } ?>
<ul class="nav nav-pills mb-3 small flex-nowrap overflow-auto"><?php foreach (MKT_AI_TOOLS as $k => $l) { ?><li class="nav-item"><a class="nav-link text-nowrap <?= $tool === $k ? 'active' : ''; ?>" href="ai.php?tool=<?= $k; ?>"><?= $l; ?></a></li><?php } ?></ul>
<div class="row g-3">
<div class="col-lg-5"><form method="POST" action="ai.php?tool=<?= $tool; ?>" class="card border-0 shadow-sm"><div class="card-body small">
    <?php if (in_array($tool, ['strategy', 'campaign'], true)) { ?>
        <label class="form-label text-muted mb-1">Objective *</label><textarea name="objective" rows="2" class="form-control form-control-sm mb-2" placeholder="e.g. Win 10 schools as paying customers for website development before February"><?= $e($_POST['objective'] ?? ''); ?></textarea>
        <label class="form-label text-muted mb-1">Products / services</label><select name="products[]" multiple size="4" class="form-select form-select-sm mb-2"><?php foreach ($L['offerings'] as $o) { ?><option value="<?= (int) $o['id']; ?>"><?= $e($o['product_name']); ?></option><?php } ?></select>
        <label class="form-label text-muted mb-1">Target market</label><input name="market" class="form-control form-control-sm mb-2" value="<?= $e($_POST['market'] ?? ''); ?>">
        <div class="row g-2 mb-2"><div class="col-4"><label class="text-muted">Budget (RWF)</label><input type="number" min="0" name="budget" class="form-control form-control-sm" value="<?= $e($_POST['budget'] ?? ''); ?>"></div>
            <div class="col-4"><label class="text-muted">Start *</label><input type="date" name="start" class="form-control form-control-sm" value="<?= $e($_POST['start'] ?? date('Y-m-d')); ?>"></div>
            <div class="col-4"><label class="text-muted">End *</label><input type="date" name="end" class="form-control form-control-sm" value="<?= $e($_POST['end'] ?? date('Y-m-d', strtotime('+2 months'))); ?>"></div></div>
        <?php if ($tool === 'campaign') { ?><label class="form-label text-muted mb-1">Plan</label><select name="plan" class="form-select form-select-sm mb-2"><option value="">— none —</option><?php foreach ($plans as $p) { ?><option value="<?= (int) $p['id']; ?>"><?= $e($p['title']); ?></option><?php } ?></select><?php } ?>
        <label class="form-label text-muted mb-1">Other notes</label><textarea name="notes" rows="2" class="form-control form-control-sm mb-2"><?= $e($_POST['notes'] ?? ''); ?></textarea>
    <?php } elseif ($tool === 'content') { ?>
        <div class="row g-2 mb-2"><div class="col-6"><label class="text-muted">Type</label><select name="type" class="form-select form-select-sm"><?php foreach (MKT_MATERIAL_TYPES as $t) { ?><option <?= ($_POST['type'] ?? 'Social media post') === $t ? 'selected' : ''; ?>><?= $t; ?></option><?php } ?></select></div>
            <div class="col-6"><label class="text-muted">Language</label><select name="language" class="form-select form-select-sm"><?php foreach ($langs as $l) { ?><option <?= ($_POST['language'] ?? '') === $l ? 'selected' : ''; ?>><?= $l; ?></option><?php } ?></select></div></div>
        <label class="form-label text-muted mb-1">Campaign</label><select name="campaign" class="form-select form-select-sm mb-2"><option value="">— none —</option><?php foreach ($camps as $c) { ?><option value="<?= (int) $c['id']; ?>"><?= $e($c['name']); ?></option><?php } ?></select>
        <label class="form-label text-muted mb-1">Products / services</label><select name="products[]" multiple size="3" class="form-select form-select-sm mb-2"><?php foreach ($L['offerings'] as $o) { ?><option value="<?= (int) $o['id']; ?>"><?= $e($o['product_name']); ?></option><?php } ?></select>
        <label class="form-label text-muted mb-1">Tone</label><input name="tone" class="form-control form-control-sm mb-2" value="<?= $e($_POST['tone'] ?? 'Professional and friendly'); ?>">
        <label class="form-label text-muted mb-1">Key points (facts to include: offer, price, contact…)</label><textarea name="points" rows="3" class="form-control form-control-sm mb-2"><?= $e($_POST['points'] ?? ''); ?></textarea>
    <?php } elseif ($tool === 'analyst') { ?>
        <label class="form-label text-muted mb-1">Campaign *</label><select name="campaign" class="form-select form-select-sm mb-2"><?php foreach ($camps as $c) { ?><option value="<?= (int) $c['id']; ?>"><?= $e(mkt_code('campaign', $c) . ' ' . $c['name'] . ' (' . $c['status'] . ')'); ?></option><?php } ?></select>
        <div class="text-muted mb-2">The AI receives the campaign's verified figures (targets, results, funnel, sources, spending, ROI), without any customer's personal data.</div>
    <?php } elseif ($tool === 'advisor') { ?>
        <label class="form-label text-muted mb-1">From</label><input type="date" name="from" value="<?= date('Y-m-d', strtotime('-6 months')); ?>" class="form-control form-control-sm mb-2">
        <div class="text-muted mb-2">Uses the verified marketing figures of the period: sources, offerings, campaigns, ROI, follow-up discipline.</div>
    <?php } else { ?><div class="text-muted mb-2">A one-page report on the last 7 days for management, from the verified figures. Managers can share it with the other managers.</div><?php } ?>
    <button class="btn btn-primary btn-sm" <?= $why ? 'disabled' : ''; ?> onclick="this.innerHTML = 'Generating… (up to a minute)'; this.form.submit(); this.disabled = true;"><i class="bi bi-stars"></i> Generate</button>
    <div class="text-muted mt-2">Used today: <?= $usedToday; ?> / <?= mkt_ai_config()['daily_limit']; ?>. Answers are drafts: check facts, figures and prices before using them.</div>
</div></form>
<div class="card border-0 shadow-sm mt-3"><div class="card-body small"><h6>History</h6>
    <?php if (!$hist) { ?><div class="text-muted">No requests yet.</div><?php } ?>
    <?php foreach ($hist as $h) { ?><div class="d-flex justify-content-between border-top py-1"><span><?= $h['status'] === 'ok' ? '<a href="ai.php?view=' . (int) $h['id'] . '">#' . (int) $h['id'] . '</a>' : '#' . (int) $h['id']; ?> · <?= $e(MKT_AI_TOOLS[$h['tool']] ?? $h['tool']); ?><?= mkt_is_manager() ? ' · ' . $e($h['names']) : ''; ?></span>
        <span class="text-muted"><?= date('d M, H:i', strtotime($h['created_at'])); ?> <?= $h['ref'] ? '· saved ' . $e($h['ref']) : ($h['status'] !== 'ok' ? '<span class="text-danger" title="' . $e($h['error']) . '">error</span>' : ''); ?></span></div><?php } ?>
</div></div></div>
<div class="col-lg-7">
    <?php if ($result) { ?><div class="card border-0 shadow-sm"><div class="card-body small">
        <div class="d-flex justify-content-between mb-2"><h6 class="mb-0"><?= $e(MKT_AI_TOOLS[$tool]); ?></h6><span class="badge bg-dark">AI draft · review before use</span></div>
        <?php if (in_array($tool, ['strategy', 'campaign'], true) && $j) { ?>
            <div class="fs-6 fw-bold"><?= $e($j['title'] ?? $j['name'] ?? ''); ?></div>
            <?php foreach (['objective' => 'Objective', 'description' => 'Description', 'target_market' => 'Target market', 'target_audience' => 'Target audience', 'target_orgs' => 'Target organizations', 'message' => 'Message'] as $k => $l) { if (!empty($j[$k])) { ?><div class="mt-2"><span class="text-muted"><?= $l; ?>:</span> <?= $render((string) $j[$k]); ?></div><?php } } ?>
            <div class="mt-2"><span class="text-muted">Channels:</span> <?= $e(implode(', ', (array) ($j['channels'] ?? []))); ?></div>
            <div class="mt-2"><span class="text-muted">Targets:</span> <?= (int) ($j['expected_leads'] ?? 0); ?> leads · <?= (int) ($j['expected_customers'] ?? 0); ?> paying customers · <?= $rwf($j['expected_revenue'] ?? 0); ?> revenue · <?= $rwf($j['expected_profit'] ?? 0); ?> profit</div>
            <?php if (!empty($j['kpis'])) { ?><div class="mt-2"><span class="text-muted">KPIs:</span><ul class="mb-0"><?php foreach ((array) $j['kpis'] as $k) { ?><li><?= $e($k); ?></li><?php } ?></ul></div><?php } ?>
            <?php if (!empty($j['activities'])) { ?><div class="mt-2"><span class="text-muted">Activities:</span><ul class="mb-0"><?php foreach ((array) $j['activities'] as $k) { ?><li><?= $e(is_array($k) ? implode(' · ', $k) : $k); ?></li><?php } ?></ul></div><?php } ?>
            <?php if (!empty($j['strategy'])) { ?><div class="mt-2"><span class="text-muted">Strategy:</span><div><?= $render(is_array($j['strategy']) ? implode("\n", $j['strategy']) : (string) $j['strategy']); ?></div></div><?php } ?>
            <form method="POST" class="mt-3"><input type="hidden" name="log_id" value="<?= (int) $resultLog; ?>"><button name="action" value="<?= $tool === 'strategy' ? 'save_plan' : 'save_campaign'; ?>" class="btn btn-success btn-sm"><i class="bi bi-save"></i> Save as draft <?= $tool === 'strategy' ? 'plan' : 'campaign'; ?></button>
                <span class="text-muted ms-2">You become its manager; review, complete and submit it for approval.</span></form>
        <?php } elseif ($tool === 'content' && $j && !empty($j['variants'])) { ?>
            <?php foreach ((array) $j['variants'] as $i => $v) { ?><div class="border rounded p-2 mb-2"><div class="fw-semibold"><?= $e($v['title'] ?? 'Option ' . ($i + 1)); ?></div><div><?= $render((string) ($v['text'] ?? '')); ?></div>
                <form method="POST" class="mt-1"><input type="hidden" name="log_id" value="<?= (int) $resultLog; ?>"><input type="hidden" name="variant" value="<?= (int) $i; ?>"><button name="action" value="save_material" class="btn btn-sm btn-outline-success">Save this text as a draft material</button></form></div><?php } ?>
        <?php } else { ?>
            <div><?= $render($result); ?></div>
            <?php if ($tool === 'weekly' && mkt_is_manager()) { ?><form method="POST" class="mt-3"><input type="hidden" name="log_id" value="<?= (int) $resultLog; ?>"><button name="action" value="share" class="btn btn-sm btn-outline-primary">Share with the managers</button></form><?php } ?>
        <?php } ?>
    </div></div>
    <?php } else { ?><div class="card border-0 shadow-sm"><div class="card-body text-muted small"><i class="bi bi-stars fs-3 d-block mb-2"></i>Choose a tool, fill in the form and click <strong>Generate</strong>. The answer appears here as a draft: nothing is saved or changed until you decide.</div></div><?php } ?>
</div></div>
<?php include '../../includes/footer.php'; ?>