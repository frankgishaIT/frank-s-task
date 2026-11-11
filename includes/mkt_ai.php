<?php
/**
 * NEW FILE: includes/mkt_ai.php — MY MOTIVE AI (Marketing Copilot).
 *
 *   Key       config/ai.php (NOT in Git, like config/db.php). Without it, the Copilot says "not configured".
 *   Drafts    the AI only produces drafts; saving creates a Draft marked "AI-generated" that follows the normal
 *             approval. It never approves, never changes records by itself, never touches money.
 *   Privacy   only aggregated business figures and campaign / offering names are sent: no customer names, phone
 *             numbers or emails, and no individual pay.
 *   Cost      daily limit per person; Administrators can switch the Copilot off (Marketing Settings).
 *   Log       every request: who, tool, when, tokens, success or error.
 */
require_once __DIR__ . '/mkt_helpers.php';

const MKT_AI_TOOLS = ['strategy' => 'Marketing strategy generator', 'campaign' => 'Campaign generator', 'content' => 'Content generator',
                      'analyst' => 'Campaign performance analyst', 'advisor' => 'Growth advisor', 'weekly' => 'Weekly marketing report'];

function mkt_ai_ensure(mysqli $conn): void
{
    static $done = false;
    if ($done) { return; }
    $done = true;
    mkt_ensure_studio($conn);
    mysqli_query($conn, "CREATE TABLE IF NOT EXISTS mkt_ai_log (id INT(11) NOT NULL AUTO_INCREMENT, user_id INT(11) NOT NULL, tool VARCHAR(20) NOT NULL, ref VARCHAR(40) NULL,
        input_summary TEXT NULL, output MEDIUMTEXT NULL, status VARCHAR(10) NOT NULL, error VARCHAR(500) NULL, input_tokens INT(11) NULL, output_tokens INT(11) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id), KEY user_day (user_id, created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $s = mysqli_query($conn, "SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'mkt_plans' AND column_name = 'ai_generated'");
    if ((int) mysqli_fetch_row($s)[0] === 0) { mysqli_query($conn, 'ALTER TABLE mkt_plans ADD COLUMN ai_generated TINYINT(1) NOT NULL DEFAULT 0'); }
}

/** config/ai.php returns: ['api_key' => '...', 'model' => '...', 'daily_limit' => 20, 'max_tokens' => 2000]. */
function mkt_ai_config(): array
{
    static $cfg = null;
    if ($cfg !== null) { return $cfg; }
    $file = __DIR__ . '/../config/ai.php';
    $c = is_file($file) ? (include $file) : [];
    $c = is_array($c) ? $c : [];
    return $cfg = ['api_key' => trim((string) ($c['api_key'] ?? '')), 'model' => trim((string) ($c['model'] ?? 'claude-sonnet-5-5')),
                   'daily_limit' => max(1, (int) ($c['daily_limit'] ?? 20)), 'max_tokens' => min(4000, max(500, (int) ($c['max_tokens'] ?? 2000)))];
}

/** Why the Copilot cannot be used now (null = ready). */
function mkt_ai_unavailable(mysqli $conn, int $uid): ?string
{
    if (mkt_setting($conn, 'ai_enabled') === '0') { return 'MY MOTIVE AI is switched off by an Administrator (Marketing Settings).'; }
    $cfg = mkt_ai_config();
    if ($cfg['api_key'] === '' || stripos($cfg['api_key'], 'PASTE') !== false) { return 'MY MOTIVE AI is not configured yet: the AI service key must be added on the server (config/ai.php). Ask the system administrator.'; }
    if (!function_exists('curl_init') && !isset($GLOBALS['MKT_AI_TRANSPORT'])) { return 'The server cannot reach the AI service (the PHP "curl" extension is missing).'; }
    $used = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM mkt_ai_log WHERE user_id = $uid AND status = 'ok' AND created_at >= CURDATE()"))[0];
    if ($used >= $cfg['daily_limit']) { return 'You have used your ' . $cfg['daily_limit'] . ' AI requests for today. The limit resets tomorrow.'; }
    return null;
}

/**
 * Sends one request to the AI service and logs it. Returns the text. Throws RuntimeException with a clear message.
 * (Tests can set $GLOBALS['MKT_AI_TRANSPORT'] = function (array $payload): array {...} instead of calling the service.)
 */
function mkt_ai_call(mysqli $conn, int $uid, string $tool, string $system, string $prompt, string $summary, ?string $ref = null): string
{
    if ($why = mkt_ai_unavailable($conn, $uid)) { throw new RuntimeException($why); }
    $cfg = mkt_ai_config();
    $payload = ['model' => $cfg['model'], 'max_tokens' => $cfg['max_tokens'], 'system' => $system, 'messages' => [['role' => 'user', 'content' => $prompt]]];
    $log = function (string $status, ?string $out, ?string $err, ?int $in = null, ?int $outTok = null) use ($conn, $uid, $tool, $ref, $summary) {
        $s = mysqli_prepare($conn, 'INSERT INTO mkt_ai_log (user_id, tool, ref, input_summary, output, status, error, input_tokens, output_tokens) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $e = $err ? mb_substr($err, 0, 500) : null; mysqli_stmt_bind_param($s, 'issssssii', $uid, $tool, $ref, $summary, $out, $status, $e, $in, $outTok); mysqli_stmt_execute($s);
        return (int) mysqli_insert_id($conn);
    };
    try {
        if (isset($GLOBALS['MKT_AI_TRANSPORT'])) { $res = ($GLOBALS['MKT_AI_TRANSPORT'])($payload); $code = (int) ($res['_http'] ?? 200); }
        else {
            $ch = curl_init('https://api.anthropic.com/v1/messages');
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-api-key: ' . $cfg['api_key'], 'anthropic-version: 2023-06-01'],
                CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE)]);
            $body = curl_exec($ch); $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); $cerr = curl_error($ch); curl_close($ch);
            if ($body === false) { throw new RuntimeException('The AI service could not be reached (' . $cerr . '). Check the server internet connection.'); }
            $res = json_decode((string) $body, true) ?: [];
        }
        if ($code === 401 || $code === 403) { throw new RuntimeException('The AI service refused the key (check config/ai.php).'); }
        if ($code === 429) { throw new RuntimeException('The AI service is busy or the account limit was reached. Try again in a few minutes.'); }
        if ($code >= 400 || empty($res['content'])) { throw new RuntimeException('The AI service returned an error: ' . (string) ($res['error']['message'] ?? ('HTTP ' . $code))); }
        $text = trim(implode("\n", array_map(function ($b) { return ($b['type'] ?? '') === 'text' ? (string) $b['text'] : ''; }, $res['content'])));
        if ($text === '') { throw new RuntimeException('The AI service returned an empty answer. Please try again.'); }
        $GLOBALS['MKT_AI_LAST_LOG'] = $log('ok', $text, null, (int) ($res['usage']['input_tokens'] ?? 0), (int) ($res['usage']['output_tokens'] ?? 0));
        return $text;
    } catch (RuntimeException $e) { $log('error', null, $e->getMessage()); throw $e; }
}

/** The JSON object in an AI answer (it may be wrapped in ``` fences or have words around it). */
function mkt_ai_json(string $text): ?array
{
    $t = preg_replace('/^```(?:json)?\s*|\s*```$/m', '', trim($text));
    $a = strpos($t, '{'); $b = strrpos($t, '}');
    if ($a === false || $b === false || $b < $a) { return null; }
    $j = json_decode(substr($t, $a, $b - $a + 1), true);
    return is_array($j) ? $j : null;
}

/** The common instructions for every tool: RISE MOTIVE context, honesty, drafts only, privacy. */
function mkt_ai_system(mysqli $conn): string
{
    $offer = array_column(mysqli_fetch_all(mysqli_query($conn, "SELECT product_name, item_type FROM products WHERE is_active = 1 ORDER BY item_type DESC, product_name LIMIT 80"), MYSQLI_ASSOC), 'product_name');
    return "You are MY MOTIVE AI, the marketing copilot of RISE MOTIVE Ltd, a company in Kigali, Rwanda (TIN 122923513), which sells products and services to individuals, businesses, schools and institutions.\n"
        . "Products and services offered (RM Offerings): " . implode(', ', $offer) . ".\n"
        . "Currency: Rwandan franc (RWF). Market: Rwanda (consider local channels such as WhatsApp, MTN MoMo, radio, events, partnerships).\n"
        . "Rules: (1) You produce DRAFTS for a human to review; never claim anything was approved or done. (2) When figures are given, use ONLY those figures; never invent results; say clearly when data is missing or too little to conclude. "
        . "(3) Do not promise guaranteed results; give realistic, measurable suggestions. (4) Be concise and practical. (5) Respect Rwandan law and good business ethics; no misleading claims.";
}

/** Verified figures of one campaign, without any personal data. */
function mkt_ai_campaign_facts(mysqli $conn, array $c): array
{
    $a = mkt_actuals($conn, [(int) $c['id']]); $p = mkt_performance($c, $a);
    $src = array_column(mysqli_fetch_all(mysqli_query($conn, 'SELECT COALESCE(source, "Unknown") s, COUNT(*) n FROM mkt_leads WHERE campaign_id = ' . (int) $c['id'] . ' GROUP BY s'), MYSQLI_ASSOC), 'n', 's');
    $st = array_column(mysqli_fetch_all(mysqli_query($conn, 'SELECT status, COUNT(*) n FROM mkt_leads WHERE campaign_id = ' . (int) $c['id'] . ' GROUP BY status'), MYSQLI_ASSOC), 'n', 'status');
    $lost = array_column(mysqli_fetch_all(mysqli_query($conn, "SELECT lost_reason FROM mkt_leads WHERE campaign_id = " . (int) $c['id'] . " AND status = 'Lost' AND lost_reason IS NOT NULL LIMIT 20"), MYSQLI_ASSOC), 'lost_reason');
    return ['campaign' => $c['name'], 'status' => $c['status'], 'period' => $c['start_date'] . ' to ' . $c['end_date'], 'time_elapsed_pct' => $p['elapsed'], 'days_left' => $p['days_left'],
            'channels' => $c['channels'], 'target_audience' => $c['target_audience'], 'message' => $c['message'],
            'targets' => ['leads' => (int) $c['expected_leads'], 'paying_customers' => (int) $c['expected_customers'], 'revenue_rwf' => (float) $c['expected_revenue'], 'profit_rwf' => (float) $c['expected_profit'], 'budget_rwf' => (float) $c['budget']],
            'actuals' => ['reached' => $a['reached'], 'inquiries' => $a['inquiries'], 'leads' => $a['leads'], 'qualified' => $a['qualified'], 'demonstrations' => $a['demos'], 'proposals' => $a['proposals'],
                          'won' => $a['won'], 'lost' => $a['lost'], 'paying_customers' => $a['paying'], 'revenue_rwf' => $a['revenue'], 'eligible_profit_rwf' => $a['profit'], 'spending_rwf' => $a['expenses']],
            'indicators' => ['achievement_pct_by_target' => $p['achievement'], 'lead_conversion_pct' => $p['conversion'], 'roi_pct' => $p['roi'], 'cost_per_paying_customer_rwf' => $p['cac'], 'budget_used_pct' => $p['budget_used']],
            'leads_by_source' => $src, 'leads_by_stage' => $st, 'lost_reasons_sample' => $lost];
}

/** Company-level marketing figures for a period (no personal data). */
function mkt_ai_company_facts(mysqli $conn, string $from, string $to): array
{
    $ids = array_map('intval', array_column(mysqli_fetch_all(mysqli_query($conn, "SELECT id FROM mkt_campaigns WHERE start_date <= '" . mysqli_real_escape_string($conn, $to) . "' AND end_date >= '" . mysqli_real_escape_string($conn, $from) . "'"), MYSQLI_ASSOC), 'id'));
    $a = mkt_actuals($conn, $ids);
    $s = mysqli_prepare($conn, 'SELECT COALESCE(source, "Unknown") s, COUNT(*) n, SUM(status = "Won") won FROM mkt_leads WHERE DATE(created_at) BETWEEN ? AND ? GROUP BY s'); mysqli_stmt_bind_param($s, 'ss', $from, $to); mysqli_stmt_execute($s);
    $src = []; foreach (mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC) as $r) { $src[$r['s']] = ['leads' => (int) $r['n'], 'won' => (int) $r['won']]; }
    $s = mysqli_prepare($conn, 'SELECT p.product_name, COUNT(*) n, SUM(l.status = "Won") won FROM mkt_leads l JOIN products p ON p.id = l.product_id WHERE DATE(l.created_at) BETWEEN ? AND ? GROUP BY p.product_name ORDER BY n DESC LIMIT 15'); mysqli_stmt_bind_param($s, 'ss', $from, $to); mysqli_stmt_execute($s);
    $prod = []; foreach (mysqli_fetch_all(mysqli_stmt_get_result($s), MYSQLI_ASSOC) as $r) { $prod[$r['product_name']] = ['leads' => (int) $r['n'], 'won' => (int) $r['won']]; }
    $camps = []; foreach (array_slice($ids, 0, 15) as $id) { $c = mysqli_fetch_assoc(mysqli_query($conn, "SELECT * FROM mkt_campaigns WHERE id = $id")); $x = mkt_actuals($conn, [$id]); $p = mkt_performance($c, $x);
        $camps[] = ['campaign' => $c['name'], 'status' => $c['status'], 'leads' => $x['leads'], 'paying' => $x['paying'], 'revenue_rwf' => $x['revenue'], 'profit_rwf' => $x['profit'], 'spending_rwf' => $x['expenses'], 'roi_pct' => $p['roi'], 'achievement_pct' => $p['overall']]; }
    $m = mkt_monthly($conn, $from, $to);
    $overdue = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM mkt_leads WHERE status NOT IN ('Won', 'Lost') AND next_followup < CURDATE()"))[0];
    $open = (int) mysqli_fetch_row(mysqli_query($conn, "SELECT COUNT(*) FROM mkt_leads WHERE status NOT IN ('Won', 'Lost')"))[0];
    return ['period' => "$from to $to", 'totals' => ['leads' => $a['leads'], 'qualified' => $a['qualified'], 'won' => $a['won'], 'lost' => $a['lost'], 'paying_customers' => $a['paying'],
            'revenue_rwf' => $a['revenue'], 'eligible_profit_rwf' => $a['profit'], 'spending_rwf' => $a['expenses'], 'roi_pct' => mkt_pct($a['profit'] - $a['expenses'], $a['expenses'])],
            'open_leads' => $open, 'overdue_followups' => $overdue, 'by_month' => $m, 'leads_by_source' => $src, 'leads_by_offering' => $prod, 'campaigns' => $camps];
}