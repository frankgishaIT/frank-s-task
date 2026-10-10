<?php
/**
 * NEW FILE: modules/sms/sms_compose.php
 * Send your own SMS to one person or a group:
 *   one customer, customers who owe money (credit), all customers,
 *   one employee, all employees, one supplier, all suppliers, or phone numbers you type.
 * Placeholders are filled in for each person: {name}, {balance} (what they owe), {points}.
 * Every message is recorded in sms_log (event MANUAL), like the automatic ones.
 *
 * Shortcut from anywhere, e.g. a customer's credit:
 *   sms_compose.php?group=customer&id=12&template=credit
 */
require '../../config/db.php';
require_once '../../includes/sms_messages.php';
require_role(['Admin', 'Manager']);

const SMS_COMPOSE_MAX_RECIPIENTS = 500; // safety limit per send

/* ---------- who can receive ---------- */

function compose_recipients(mysqli $conn): array {
    $out = ['customers' => [], 'employees' => [], 'suppliers' => []];

    // Customers, with what they owe (Credit / Partially Paid sales) and their Loyalty Points.
    $cPhone = sms_phone_column($conn, 'customers');
    $cPhoneSql = $cPhone ? "c.`$cPhone`" : 'NULL';
    $rows = mysqli_fetch_all(mysqli_query($conn, "SELECT c.id, c.name, $cPhoneSql AS phone, c.loyalty_points,
            COALESCE((SELECT SUM(s.total_amount - s.amount_paid) FROM sales s
                      WHERE s.customer_id = c.id AND s.status IN ('Credit', 'Partially Paid')), 0) AS balance
        FROM customers c WHERE c.is_active = 1 ORDER BY c.name"), MYSQLI_ASSOC);
    foreach ($rows as $r) {
        $out['customers'][] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'phone' => sms_normalize_phone($r['phone']),
            'balance' => round(max(0, (float) $r['balance']), 2), 'points' => (int) $r['loyalty_points']];
    }

    // Employees (active users).
    $uPhone = sms_phone_column($conn, 'users');
    $uPhoneSql = $uPhone ? "`$uPhone`" : 'NULL';
    $rows = mysqli_fetch_all(mysqli_query($conn, "SELECT id, names, $uPhoneSql AS phone FROM users WHERE is_active = 1 ORDER BY names"), MYSQLI_ASSOC);
    foreach ($rows as $r) {
        $out['employees'][] = ['id' => (int) $r['id'], 'name' => (string) $r['names'], 'phone' => sms_normalize_phone($r['phone']), 'balance' => 0, 'points' => 0];
    }

    // Suppliers (RM Suppliers in business_parties).
    if (!function_exists('business_parties_of_type')) {
        require_once __DIR__ . '/../../includes/business_party_helpers.php';
    }
    $suppliers = business_parties_of_type($conn, 'Supplier');
    $bPhone = sms_phone_column($conn, 'business_parties');
    $phones = [];
    if ($bPhone && $suppliers) {
        $ids = implode(',', array_map(function ($s) { return (int) $s['id']; }, $suppliers));
        foreach (mysqli_fetch_all(mysqli_query($conn, "SELECT id, `$bPhone` AS phone FROM business_parties WHERE id IN ($ids)"), MYSQLI_ASSOC) as $p) {
            $phones[(int) $p['id']] = $p['phone'];
        }
    }
    foreach ($suppliers as $s) {
        $name = ($s['business_name'] ?? '') !== '' ? $s['business_name'] : ($s['name'] ?? '');
        $out['suppliers'][] = ['id' => (int) $s['id'], 'name' => (string) $name, 'phone' => sms_normalize_phone($phones[(int) $s['id']] ?? null), 'balance' => 0, 'points' => 0];
    }
    return $out;
}

// Fills {name}, {balance}, {points} for one person.
function compose_render(string $template, array $person): string {
    $name = trim($person['name'] ?? '') !== '' ? mb_convert_case(trim($person['name']), MB_CASE_TITLE, 'UTF-8') : 'Mukiliya';
    return strtr($template, [
        '{name}' => $name,
        '{balance}' => sms_money($person['balance'] ?? 0),
        '{points}' => number_format((int) ($person['points'] ?? 0)),
    ]);
}

$groups = [
    'customer'  => 'One customer',
    'credit'    => 'Customers who owe money (credit)',
    'customers' => 'All customers',
    'employee'  => 'One employee',
    'employees' => 'All employees',
    'supplier'  => 'One supplier',
    'suppliers' => 'All suppliers',
    'numbers'   => 'Phone numbers I type',
];
$templates = [
    'credit' => 'Kuri {name}, ubereyemo RISE MOTIVE {balance}FRW. Ishyura nonaha kuri MTN MoMo Code: ' . SMS_MOMO_CODE
        . ', wongere Loyalty Points. EBM/Ikibazo: ' . SMS_HELP_PHONE . '. Murakoze!',
    'thanks' => 'Kuri {name}, murakoze guhitamo RISE MOTIVE! Ubufasha: ' . SMS_HELP_PHONE . '.',
    'points' => 'Dear {name}, You have {points} Loyalty Points earned from your purchases with us. Use them for eligible discounts. Keep earning with RISE MOTIVE!',
    'custom' => 'Kuri {name}, ',
    'custom_en' => 'Dear {name}, ',
];

$recipients = compose_recipients($conn);

/* ---------- send ---------- */

if (isset($_POST['send'])) {
    $group = $_POST['group'] ?? '';
    $personId = filter_input(INPUT_POST, 'person_id', FILTER_VALIDATE_INT) ?: 0;
    $message = trim($_POST['message'] ?? '');
    $numbers = trim($_POST['numbers'] ?? '');

    // Build the list on the server (never trust the browser's list).
    $list = [];
    $refType = null;
    switch ($group) {
        case 'customer':  $list = array_filter($recipients['customers'], function ($p) use ($personId) { return $p['id'] === $personId; }); $refType = 'CUSTOMER'; break;
        case 'credit':    $list = array_filter($recipients['customers'], function ($p) { return $p['balance'] > 0.009; }); $refType = 'CUSTOMER'; break;
        case 'customers': $list = $recipients['customers']; $refType = 'CUSTOMER'; break;
        case 'employee':  $list = array_filter($recipients['employees'], function ($p) use ($personId) { return $p['id'] === $personId; }); $refType = 'USER'; break;
        case 'employees': $list = $recipients['employees']; $refType = 'USER'; break;
        case 'supplier':  $list = array_filter($recipients['suppliers'], function ($p) use ($personId) { return $p['id'] === $personId; }); $refType = 'SUPPLIER'; break;
        case 'suppliers': $list = $recipients['suppliers']; $refType = 'SUPPLIER'; break;
        case 'numbers':
            foreach (preg_split('/[\s,;]+/', $numbers, -1, PREG_SPLIT_NO_EMPTY) as $n) {
                $list[] = ['id' => null, 'name' => '', 'phone' => sms_normalize_phone($n), 'raw' => $n, 'balance' => 0, 'points' => 0];
            }
            break;
    }
    $list = array_values($list);

    if (!isset($groups[$group])) {
        $error = 'Please choose who should receive the message.';
    } elseif (!$list) {
        $error = in_array($group, ['customer', 'employee', 'supplier'], true) ? 'Please choose the person.' : 'Nobody matches this group.';
    } elseif ($message === '') {
        $error = 'Please write the message.';
    } elseif (count($list) > SMS_COMPOSE_MAX_RECIPIENTS) {
        $error = 'Too many recipients (' . count($list) . '). The limit is ' . SMS_COMPOSE_MAX_RECIPIENTS . ' per send.';
    } else {
        @set_time_limit(0); // sending to many people can take a while
        $userId = current_user_id();
        $userId = $userId ? (int) $userId : null;
        $sent = 0; $failed = 0; $skipped = 0;
        foreach ($list as $person) {
            $text = compose_render($message, $person);
            $to = $person['phone'] ?? ($person['raw'] ?? null);
            if ($person['phone'] === null) {
                // Logged as skipped by sms_send (no valid number).
                sms_send($conn, $person['raw'] ?? null, $text, 'MANUAL', $refType, $person['id'], $userId);
                $skipped++;
                continue;
            }
            if (sms_send($conn, $to, $text, 'MANUAL', $refType, $person['id'], $userId)) { $sent++; } else { $failed++; }
        }
        header('Location: sms_test.php?success=' . urlencode('Message sent to ' . $sent . ' person(s).'
            . ($failed ? ' Failed: ' . $failed . '.' : '') . ($skipped ? ' Skipped (no valid phone): ' . $skipped . '.' : '')
            . ' See the list below for details.'));
        exit;
    }
}

/* ---------- form defaults (also from a shortcut link) ---------- */

$group = $_POST['group'] ?? ($_GET['group'] ?? 'customer');
if (!isset($groups[$group])) { $group = 'customer'; }
$personId = (int) ($_POST['person_id'] ?? ($_GET['id'] ?? 0));
$templateKey = $_GET['template'] ?? 'custom';
$message = $_POST['message'] ?? ($templates[$templateKey] ?? $templates['custom']);
$numbers = $_POST['numbers'] ?? '';

include '../../includes/header.php'; include '../../includes/sidebar.php';
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <h2>Send SMS</h2>
    <a href="sms_test.php" class="rm-btn rm-btn-light">SMS History</a>
</div>

<?php if (isset($error)) { ?><div class="alert alert-danger"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php } ?>

<div class="card border-0 shadow-sm">
    <div class="card-body p-4">
        <form method="POST" id="composeForm">
            <div class="row g-3 mb-3">
                <div class="col-md-5">
                    <label class="form-label small fw-semibold text-muted">Send to</label>
                    <select name="group" id="groupSelect" class="form-select rm-input">
                        <?php foreach ($groups as $k => $label) { ?>
                        <option value="<?= $k; ?>" <?= $group === $k ? 'selected' : ''; ?>><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php } ?>
                    </select>
                </div>
                <div class="col-md-7" id="personBox">
                    <label class="form-label small fw-semibold text-muted">Person</label>
                    <select name="person_id" id="personSelect" class="form-select rm-input"></select>
                </div>
                <div class="col-md-7" id="numbersBox">
                    <label class="form-label small fw-semibold text-muted">Phone numbers (separate with commas)</label>
                    <input type="text" name="numbers" id="numbersInput" class="form-control rm-input" placeholder="0788123456, 0798123456" value="<?= htmlspecialchars($numbers, ENT_QUOTES, 'UTF-8'); ?>">
                </div>
            </div>

            <div class="mb-2 d-flex flex-wrap gap-2 align-items-center">
                <span class="small text-muted">Templates:</span>
                <button type="button" class="btn btn-sm btn-outline-secondary tpl" data-key="credit">Credit reminder</button>
                <button type="button" class="btn btn-sm btn-outline-secondary tpl" data-key="thanks">Thank you</button>
                <button type="button" class="btn btn-sm btn-outline-secondary tpl" data-key="points">Loyalty Points</button>
                <button type="button" class="btn btn-sm btn-outline-secondary tpl" data-key="custom">Blank (Kinyarwanda)</button>
                <button type="button" class="btn btn-sm btn-outline-secondary tpl" data-key="custom_en">Blank (English)</button>
            </div>

            <label class="form-label small fw-semibold text-muted">Message</label>
            <textarea name="message" id="messageInput" class="form-control rm-input" rows="4" maxlength="<?= SMS_MAX_LENGTH; ?>" style="height:auto;" required><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></textarea>
            <div class="d-flex justify-content-between small text-muted mt-1 mb-3">
                <span>Placeholders: <code>{name}</code> <code>{balance}</code> (what a customer owes) <code>{points}</code> (Loyalty Points)</span>
                <span id="charCount"></span>
            </div>

            <div class="alert alert-light border small mb-3" style="border-radius:10px;">
                <div><strong>Preview</strong> <span class="text-muted" id="previewWho"></span></div>
                <div id="previewText" class="mt-1"></div>
            </div>

            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="small" id="recipientCount"></span>
                <button type="submit" name="send" value="1" class="rm-btn rm-btn-primary"><i class="bi bi-send-fill me-2"></i>Send SMS</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const R = <?= json_encode($recipients, $jsonFlags); ?>;
    const TEMPLATES = <?= json_encode($templates, $jsonFlags); ?>;
    const SELECTED_ID = <?= (int) $personId; ?>;
    const groupSelect = document.getElementById('groupSelect');
    const personBox = document.getElementById('personBox');
    const personSelect = document.getElementById('personSelect');
    const numbersBox = document.getElementById('numbersBox');
    const numbersInput = document.getElementById('numbersInput');
    const messageInput = document.getElementById('messageInput');

    const listFor = { customer: 'customers', credit: 'customers', customers: 'customers', employee: 'employees', employees: 'employees', supplier: 'suppliers', suppliers: 'suppliers' };
    const single = ['customer', 'employee', 'supplier'];
    const fmt = n => Math.round(n).toLocaleString('en-US');
    const titleCase = s => (s || '').toLowerCase().replace(/(^|\s)\S/g, c => c.toUpperCase());

    function currentList() {
        const g = groupSelect.value;
        if (g === 'numbers') {
            return numbersInput.value.split(/[\s,;]+/).filter(Boolean).map(n => ({ name: '', phone: n, balance: 0, points: 0 }));
        }
        let list = R[listFor[g]] || [];
        if (g === 'credit') { list = list.filter(p => p.balance > 0.009); }
        if (single.includes(g)) { list = list.filter(p => String(p.id) === personSelect.value); }
        return list;
    }

    function render(tpl, p) {
        return tpl.split('{name}').join(p && p.name ? titleCase(p.name) : 'Mukiliya')
                  .split('{balance}').join(fmt(p ? p.balance : 0))
                  .split('{points}').join(fmt(p ? p.points : 0));
    }

    function fillPeople() {
        const g = groupSelect.value;
        personBox.style.display = single.includes(g) ? '' : 'none';
        numbersBox.style.display = g === 'numbers' ? '' : 'none';
        if (!single.includes(g)) { return; }
        const list = R[listFor[g]] || [];
        personSelect.innerHTML = '';
        list.forEach(function (p) {
            const opt = document.createElement('option');
            opt.value = p.id;
            opt.textContent = p.name + (p.phone ? '' : ' (no phone)') + (g === 'customer' && p.balance > 0 ? ' · owes ' + fmt(p.balance) + ' FRW' : '');
            if (p.id === SELECTED_ID) { opt.selected = true; }
            personSelect.appendChild(opt);
        });
    }

    function refresh() {
        const list = currentList();
        const withPhone = groupSelect.value === 'numbers' ? list.length : list.filter(p => p.phone).length;
        const sample = list.find(p => p.phone) || list[0] || null;
        const text = render(messageInput.value, sample);
        const len = text.length;
        const parts = len <= 160 ? 1 : Math.ceil(len / 153);
        document.getElementById('charCount').textContent = len + ' characters · ' + parts + ' SMS part' + (parts > 1 ? 's' : '') + ' per person';
        document.getElementById('previewText').textContent = text;
        document.getElementById('previewWho').textContent = sample ? '(as ' + (sample.name ? titleCase(sample.name) : sample.phone) + ' will see it)' : '';
        const noPhone = list.length - withPhone;
        document.getElementById('recipientCount').innerHTML = '<strong>' + withPhone + '</strong> recipient(s) · about <strong>' + (withPhone * parts) + '</strong> SMS'
            + (noPhone > 0 ? ' <span class="text-muted">(' + noPhone + ' without a phone number will be skipped)</span>' : '');
    }

    document.querySelectorAll('.tpl').forEach(function (b) {
        b.addEventListener('click', function () { messageInput.value = TEMPLATES[b.dataset.key] || ''; messageInput.focus(); refresh(); });
    });
    groupSelect.addEventListener('change', function () { fillPeople(); refresh(); });
    personSelect.addEventListener('change', refresh);
    numbersInput.addEventListener('input', refresh);
    messageInput.addEventListener('input', refresh);

    document.getElementById('composeForm').addEventListener('submit', function (e) {
        const list = currentList();
        const n = groupSelect.value === 'numbers' ? list.length : list.filter(p => p.phone).length;
        if (n === 0) { e.preventDefault(); alert('Nobody in this selection has a phone number.'); return; }
        if (!confirm('Send this SMS to ' + n + ' person(s)? This uses your Pindo credit.')) { e.preventDefault(); }
    });

    fillPeople();
    refresh();
})();
</script>
<?php include '../../includes/footer.php'; ?>