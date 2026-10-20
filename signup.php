<?php
/**
 * Public page: a company asks to join MY MOTIVE.
 * Nothing is created here except a pending request. You approve it in /platform/.
 */
require_once __DIR__ . '/includes/public_layout.php';
require_once __DIR__ . '/includes/signup.php';

platform_session_start('MM_SIGNUP');

$errors = [];
$done = null;
$old = [];
$cards = signup_plan_cards();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    platform_csrf_check();
    $ip = platform_client_ip();
    foreach (['company_name', 'owner_name', 'owner_email', 'owner_phone', 'tin', 'package_id', 'billing_period'] as $key) {
        $old[$key] = is_string($_POST[$key] ?? null) ? $_POST[$key] : '';
    }
    if (($_POST['website'] ?? '') !== '') {
        // the hidden "website" field is a trap for robots: pretend it worked, store nothing
        $done = ['reference' => 'SU-' . date('ymd') . '-' . strtoupper(substr(bin2hex(random_bytes(2)), 0, 4)), 'owner_email' => $old['owner_email'] ?: 'your email'];
    } elseif (platform_rate_count('signup_attempt', $ip, 3600) >= 30 || platform_rate_count('signup_created', $ip, 3600) >= 5) {
        $errors['_'] = 'Too many requests from your network. Please try again in an hour.';
    } else {
        platform_rate_hit('signup_attempt', $ip);
        $clean = signup_clean($_POST, $errors);
        if ($clean) {
            try {
                $done = signup_create($clean, $ip);
                platform_rate_hit('signup_created', $ip);
            } catch (SignupException $e) {
                $errors['_'] = $e->getMessage();
            }
        }
    }
}
$termsUrl = (string) platform_setting('terms_url', '');
$yearly = ($old['billing_period'] ?? '') === 'yearly';
$chosen = $old['package_id'] ?? '';
if ($chosen === '' && $cards) {
    $chosen = (string) $cards[0]['package']['id'];
}

mm_public_start('Open your business', [
    'width' => 'wide',
    'panel' => [
        'headline' => 'Open your business in minutes.',
        'lead'     => 'Tell us about your business, choose a plan, pay with Mobile Money, and start working. No setup fees, no paperwork.',
        'points'   => [
            ['shield', 'Your data stays yours: every business has its own private database'],
            ['users', 'Employees, attendance, leave, customers and sales from day one'],
            ['card', 'Pay with MTN or Airtel Mobile Money, right from your phone'],
        ],
    ],
]);

$err = static fn(string $k): string => isset($errors[$k]) ? '<div class="mm-err">' . mm_icon('alert', 14) . h($errors[$k]) . '</div>' : '';
$val = static fn(string $k): string => h($old[$k] ?? '');
$bad = static fn(string $k): string => isset($errors[$k]) ? ' mm-bad' : '';

if ($done) { ?>
    <div class="mm-center">
        <div class="mm-bigicon ok"><?= mm_icon('check', 40); ?></div>
        <h1 class="mm-title">Request received</h1>
        <p class="mm-sub" style="margin-bottom:22px">Thank you! We will review it and email <b style="color:var(--mm-ink)"><?= h($done['owner_email']); ?></b> with the result.</p>
        <div class="mm-summary" style="text-align:left">
            <div class="row"><span>Your reference</span><span style="letter-spacing:.04em"><?= h($done['reference']); ?></span></div>
        </div>
        <div class="mm-howto">
            <b>What happens next</b>
            <ol>
                <li>We review your request (usually within one working day).</li>
                <li>If it is approved, you get an email with a secure <b>Pay</b> button.</li>
                <li>You pay with Mobile Money. Your business opens right after, and you choose your password.</li>
            </ol>
        </div>
        <a class="mm-link" href="<?= h(BASE_URL); ?>/login.php">Back to sign in</a>
    </div>
<?php } else { ?>
    <h1 class="mm-title">Open your business</h1>
    <p class="mm-sub">Takes about two minutes. You only pay after we approve your request.</p>
<?php if (!empty($errors['_'])) { ?><div class="mm-alert bad"><?= mm_icon('alert', 18); ?><div><?= h($errors['_']); ?></div></div><?php } ?>
<?php if ($errors && empty($errors['_'])) { ?><div class="mm-alert bad"><?= mm_icon('alert', 18); ?><div>Please check the fields marked in red.</div></div><?php } ?>

    <form method="POST" novalidate>
        <?= platform_csrf_field(); ?>
        <div style="position:absolute;left:-5000px" aria-hidden="true"><label>Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

        <div class="mm-section"><b>1</b>Your business</div>
        <div class="mm-grid">
            <div class="mm-field mm-full"><label class="mm-label" for="company_name">Business name</label>
                <input class="mm-input<?= $bad('company_name'); ?>" id="company_name" name="company_name" value="<?= $val('company_name'); ?>" maxlength="150" placeholder="e.g. Kigali Fresh Traders" autocomplete="organization"><?= $err('company_name'); ?></div>
            <div class="mm-field"><label class="mm-label" for="tin">TIN <i>(optional)</i></label>
                <input class="mm-input<?= $bad('tin'); ?>" id="tin" name="tin" value="<?= $val('tin'); ?>" maxlength="30" placeholder="Tax number"><?= $err('tin'); ?></div>
        </div>

        <div class="mm-section"><b>2</b>About you</div>
        <div class="mm-grid">
            <div class="mm-field mm-full"><label class="mm-label" for="owner_name">Your full name</label>
                <input class="mm-input<?= $bad('owner_name'); ?>" id="owner_name" name="owner_name" value="<?= $val('owner_name'); ?>" maxlength="150" placeholder="First and last name" autocomplete="name"><?= $err('owner_name'); ?></div>
            <div class="mm-field"><label class="mm-label" for="owner_email">Email address</label>
                <input class="mm-input<?= $bad('owner_email'); ?>" type="email" id="owner_email" name="owner_email" value="<?= $val('owner_email'); ?>" maxlength="150" placeholder="you@business.com" autocomplete="email"><?= $err('owner_email'); ?>
                <?php if (!isset($errors['owner_email'])) { ?><div class="mm-hint">We send the approval and your sign-in link here.</div><?php } ?></div>
            <div class="mm-field"><label class="mm-label" for="owner_phone">Mobile phone</label>
                <input class="mm-input<?= $bad('owner_phone'); ?>" type="tel" id="owner_phone" name="owner_phone" value="<?= $val('owner_phone'); ?>" maxlength="20" placeholder="0788 123 456" autocomplete="tel"><?= $err('owner_phone'); ?>
                <?php if (!isset($errors['owner_phone'])) { ?><div class="mm-hint">The number you will pay with.</div><?php } ?></div>
        </div>

        <div class="mm-section"><b>3</b>Choose your plan</div>
        <div class="mm-seg" role="radiogroup" aria-label="Billing">
            <label><input type="radio" name="billing_period" value="monthly"<?= $yearly ? '' : ' checked'; ?>><span>Monthly</span></label>
            <label><input type="radio" name="billing_period" value="yearly"<?= $yearly ? ' checked' : ''; ?>><span>Yearly<em>5% off</em></span></label>
        </div>
        <?= $err('billing_period'); ?>
        <div class="mm-plans">
<?php foreach ($cards as $i => $c) {
    $p = $c['package'];
    $m = signup_price($p, 'monthly');
    $y = signup_price($p, 'yearly'); ?>
            <label class="mm-plan" data-m="<?= $m; ?>" data-y="<?= $y; ?>">
                <input type="radio" name="package_id" value="<?= (int) $p['id']; ?>"<?= $chosen === (string) $p['id'] ? ' checked' : ''; ?>>
                <div class="mm-plan-body">
<?php if ($i === count($cards) - 1 && count($cards) > 1) { ?><span class="mm-plan-ribbon">MOST COMPLETE</span><?php } ?>
                    <div class="mm-plan-name"><?= h($p['name']); ?><span class="mm-plan-tick"><?= mm_icon('check', 13); ?></span></div>
                    <div class="mm-plan-price"><b data-price><?= h(number_format($m)); ?></b> <small>RWF / <span data-per>month</span></small></div>
                    <div class="mm-plan-alt" data-alt>or <?= h(number_format($y)); ?> RWF a year (<?= (int) $p['yearly_discount_percent']; ?>% less)</div>
<?php if ($c['base']) { ?><p class="mm-plus">Everything in <?= h($c['base']); ?>, plus:</p><?php } ?>
                    <ul>
<?php foreach ($c['items'] as $item) { ?><li><?= mm_icon('check', 15); ?><?= h($item); ?></li><?php } ?>
                    </ul>
                </div>
            </label>
<?php } ?>
        </div>
        <?= $err('package_id'); ?>

        <div class="mm-field" style="margin-top:24px">
            <label style="display:flex;gap:11px;align-items:flex-start;cursor:pointer;font-size:13.5px;color:var(--mm-text)">
                <input type="checkbox" name="terms" value="1" style="width:18px;height:18px;margin-top:2px;accent-color:var(--mm-primary)">
                <span>I accept the <?= $termsUrl !== '' ? '<a href="' . h($termsUrl) . '" target="_blank" rel="noopener">terms of service and privacy policy</a>' : 'terms of service and privacy policy'; ?>.</span>
            </label>
            <?= $err('terms'); ?>
        </div>
        <button class="mm-btn" type="submit">Send my request <?= mm_icon('arrow', 18); ?></button>
        <a class="mm-link" href="<?= h(BASE_URL); ?>/login.php">Already have an account? Sign in</a>
    </form>
<?php }
mm_public_end(<<<'JS'
(function(){
  var plans=document.querySelectorAll('.mm-plan'); if(!plans.length) return;
  function fmt(n){return n.toString().replace(/\B(?=(\d{3})+(?!\d))/g,',');}
  function update(){
    var yearly=document.querySelector('input[name=billing_period]:checked').value==='yearly';
    plans.forEach(function(p){
      var m=+p.dataset.m,y=+p.dataset.y;
      p.querySelector('[data-price]').textContent=fmt(yearly?y:m);
      p.querySelector('[data-per]').textContent=yearly?'year':'month';
      p.querySelector('[data-alt]').textContent=yearly?('that is about '+fmt(Math.round(y/12))+' RWF a month'):('or '+fmt(y)+' RWF a year ('+Math.round((1-y/(m*12))*100)+'% less)');
    });
  }
  document.querySelectorAll('input[name=billing_period]').forEach(function(r){r.addEventListener('change',update);});
  update();
})();
JS);
