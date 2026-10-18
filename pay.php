<?php
/**
 * Public page: a company pays for its approved request. The link comes by email (the "Pay" button).
 * Payment is by mobile money: the customer enters a number and approves the prompt that appears on the phone.
 */
require_once __DIR__ . '/includes/public_layout.php';
require_once __DIR__ . '/includes/signup.php';

platform_session_start('MM_PAY');

$token = is_string($_GET['t'] ?? null) ? $_GET['t'] : (is_string($_POST['t'] ?? null) ? $_POST['t'] : '');
$req = payment_request_by_token($token);
$view = 'invalid';
$error = '';
$attempt = null;
$phoneTyped = '';

if ($req) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $req['status'] === 'approved') {
        platform_csrf_check();
        $phoneTyped = is_string($_POST['phone'] ?? null) ? $_POST['phone'] : '';
        $r = payment_start($req, $phoneTyped, platform_client_ip());
        if ($r['ok']) {
            $attempt = $r['attempt'];
        } else {
            $error = $r['message'];
        }
        $req = payment_request_by_token($token) ?? $req;
    }
    switch ($req['status']) {
        case 'approved':
            $attempt = $attempt ?: payment_open_attempt((int) $req['id']);
            $view = $attempt ? 'wait' : 'pay';
            break;
        case 'activating':
        case 'provisioning':
        case 'failed':
            $view = 'creating';
            break;
        case 'active':
            $view = 'done';
            break;
        default:
            $view = 'notready';
    }
}

$charge = $req ? payment_charge($req) : ['amount' => 0, 'list' => 0, 'test' => false];
$prefill = '';
if ($req) {
    $p = xentripay_phone($phoneTyped !== '' ? $phoneTyped : $req['owner_phone']);
    $prefill = $p ? trim(chunk_split(substr($p['local'], 1), 3, ' ')) : $phoneTyped;   // escaped when printed
}
$remaining = 0;
$maskedPhone = '';
$network = 'MTN';
if ($attempt) {
    $remaining = max(0, PAY_WAIT_SECONDS - (time() - strtotime($attempt['created_at'])));
    $m = $attempt['msisdn'];
    $maskedPhone = '+250 ' . substr($m, 3, 3) . ' *** ' . substr($m, -3);
    $network = in_array(substr($m, 3, 2), ['78', '79'], true) ? 'MTN' : 'Airtel';
}

mm_public_start('Complete your payment', [
    'width' => 'slim',
    'shell' => 'narrow',
    'panel' => [
        'headline' => 'One last step.',
        'lead'     => 'Pay securely with Mobile Money. Your company opens automatically as soon as the payment is confirmed.',
        'points'   => [
            ['phone', 'You approve on your own phone with your PIN'],
            ['lock', 'We never see or store your PIN'],
            ['clock', 'Your account is ready in about a minute'],
        ],
        'note'     => 'Payments are processed by XentriPay.',
    ],
]);

$step = static function (string $now): string {
    $items = [['approved', 'Approved'], ['pay', 'Pay'], ['ready', 'Account ready']];
    $order = ['approved' => 0, 'pay' => 1, 'ready' => 2];
    $html = '<ol class="mm-steps" id="steps">';
    foreach ($items as $i => [$key, $label]) {
        $cls = $i < $order[$now] ? 'done' : ($i === $order[$now] ? 'now' : '');
        $html .= '<li class="' . $cls . '"><span class="n">' . ($cls === 'done' ? mm_icon('check', 13) : $i + 1) . '</span><span class="t">' . h($label) . '</span></li>';
    }
    return $html . '</ol>';
};
$stepNow = $view === 'done' ? 'ready' : ($view === 'creating' ? 'ready' : 'pay');

if ($view === 'invalid') { ?>
    <div class="mm-center">
        <div class="mm-bigicon bad"><?= mm_icon('alert', 40); ?></div>
        <h1 class="mm-title">This payment link is not valid</h1>
        <p class="mm-sub">It may have expired, or it was not copied completely. Please open the button from your latest approval email, or contact us and we will send a new link.</p>
        <a class="mm-btn mm-ghost mm-sm" href="<?= h(BASE_URL); ?>/login.php">Go to sign in</a>
    </div>
<?php } elseif ($view === 'notready') { ?>
    <div class="mm-center">
        <div class="mm-bigicon bad"><?= mm_icon('info', 40); ?></div>
        <h1 class="mm-title">Nothing to pay right now</h1>
        <p class="mm-sub">This request is not waiting for a payment. If you were expecting to pay, please contact us with your reference <b style="color:var(--mm-ink)"><?= h($req['reference']); ?></b>.</p>
    </div>
<?php } else { ?>
    <?= $step($stepNow); ?>

    <!-- ===== 1. choose the number and pay (only printed when it can be used) ===== -->
<?php if ($view === 'pay') { ?>
    <section id="v-pay">
        <h1 class="mm-title">Complete your payment</h1>
        <p class="mm-sub">Hello <?= h(explode(' ', trim($req['owner_name']))[0]); ?>, <?= h($req['company_name']); ?> is approved. Confirm the amount and your Mobile Money number.</p>
<?php if ($charge['test']) { ?>
        <div class="mm-alert warn"><?= mm_icon('info', 18); ?><div><b>Test mode.</b> You will really be charged <?= h(signup_money($charge['amount'])); ?> instead of <?= h(signup_money($charge['list'])); ?>.</div></div>
<?php } ?>
<?php if ($error !== '') { ?>
        <div class="mm-alert bad"><?= mm_icon('alert', 18); ?><div><?= h($error); ?></div></div>
<?php } ?>
        <div class="mm-summary">
            <div class="row"><span>Company</span><span><?= h($req['company_name']); ?></span></div>
            <div class="row"><span>Package</span><span><span class="mm-badge"><?= h($req['package_name']); ?></span> <?= h($req['billing_period'] === 'yearly' ? 'Yearly' : 'Monthly'); ?></span></div>
            <div class="row"><span>Reference</span><span><?= h($req['reference']); ?></span></div>
            <div class="row total"><span>Total to pay</span><span><?= h(signup_money($charge['amount'])); ?></span></div>
        </div>
        <form method="POST" id="payform" novalidate>
            <?= platform_csrf_field(); ?>
            <input type="hidden" name="t" value="<?= h($token); ?>">
            <div class="mm-field">
                <label class="mm-label" for="phone">Mobile Money number</label>
                <div class="mm-group">
                    <span class="mm-pre">+250</span>
                    <input class="mm-input" id="phone" name="phone" inputmode="tel" autocomplete="tel-national" placeholder="788 123 456" value="<?= h($prefill); ?>" maxlength="16" required>
                    <span class="mm-net" id="net" style="margin:auto 10px auto 0;display:none"></span>
                </div>
                <div class="mm-hint">MTN (078, 079) or Airtel (072, 073). You will get a prompt on this phone.</div>
            </div>
            <button class="mm-btn" type="submit" id="paybtn"><?= mm_icon('lock', 18); ?> Pay <?= h(signup_money($charge['amount'])); ?></button>
        </form>
        <div class="mm-secure"><?= mm_icon('shield', 18); ?><span>Secure payment by XentriPay. We never see your PIN and we do not store your number beyond this payment.</span></div>
    </section>
<?php } ?>

    <!-- ===== 2. waiting for the phone ===== -->
    <section id="v-wait" class="mm-center<?= $view === 'wait' ? '' : ' mm-hide'; ?>">
        <div class="mm-phone"><span class="ring"></span><span class="ring r2"></span><span class="core"><?= mm_icon('phone', 30); ?></span></div>
        <h1 class="mm-title">Check your phone</h1>
        <p class="mm-sub" style="margin-bottom:6px">We sent a request for <b style="color:var(--mm-ink)"><?= h(signup_money($charge['amount'])); ?></b> to <b style="color:var(--mm-ink)" id="shownphone"><?= h($maskedPhone); ?></b>.<br>Enter your PIN to approve it.</p>
        <div class="mm-timer" id="timer">3:00</div>
        <div class="mm-hint" id="timerhint">Waiting for your approval</div>
        <div class="mm-howto" id="howto">
            <b>No prompt on your phone?</b>
            <ol>
                <li id="how-mtn">MTN: dial <b>*182*7*1#</b> to see your pending approvals.</li>
                <li id="how-airtel">Airtel: open your Airtel Money menu and look at pending approvals.</li>
                <li>Make sure the number has enough money and Mobile Money is active.</li>
            </ol>
        </div>
        <a class="mm-btn mm-ghost mm-hide" id="retry" style="margin-top:18px" href="<?= h(BASE_URL . '/pay.php?t=' . $token); ?>">Try again</a>
    </section>

    <!-- ===== the payment did not go through ===== -->
    <section id="v-failed" class="mm-center mm-hide">
        <div class="mm-bigicon bad"><?= mm_icon('x', 40); ?></div>
        <h1 class="mm-title">Payment not completed</h1>
        <p class="mm-sub" id="failreason">The payment was not approved.</p>
        <a class="mm-btn" href="<?= h(BASE_URL . '/pay.php?t=' . $token); ?>">Try again</a>
    </section>

    <!-- ===== 3. payment received, company being created ===== -->
    <section id="v-creating" class="mm-center<?= $view === 'creating' ? '' : ' mm-hide'; ?>">
        <div class="mm-bigicon ok"><?= mm_icon('check', 40); ?></div>
        <h1 class="mm-title">Payment received. Thank you!</h1>
        <p class="mm-sub" style="margin-bottom:6px">We are creating <b style="color:var(--mm-ink)"><?= h($req['company_name']); ?></b> now. This usually takes less than a minute.</p>
        <div class="mm-progress"><i></i></div>
        <div class="mm-hint" id="creatinghint">Please keep this page open.</div>
    </section>

    <!-- ===== done ===== -->
    <section id="v-done" class="mm-center<?= $view === 'done' ? '' : ' mm-hide'; ?>">
        <div class="mm-bigicon ok"><?= mm_icon('check', 40); ?></div>
        <h1 class="mm-title">Your account is ready!</h1>
        <p class="mm-sub">We sent an email to <b style="color:var(--mm-ink)"><?= h($req['owner_email']); ?></b> with a secure link to choose your password.</p>
        <div class="mm-summary" style="text-align:left">
            <div class="row"><span>Company</span><span><?= h($req['company_name']); ?></span></div>
            <div class="row"><span>Company code</span><span style="letter-spacing:.06em;color:var(--mm-primary-dark)"><?= h($req['company_code']); ?></span></div>
        </div>
        <a class="mm-btn" href="<?= h(BASE_URL); ?>/login.php">Go to sign in <?= mm_icon('arrow', 18); ?></a>
        <p class="mm-hint" style="margin-top:14px">Open the email first: you choose your password with its link. Check your spam folder if you do not see it.</p>
    </section>
<?php }

$cfg = json_encode([
    'token'     => $token,
    'attempt'   => $attempt ? (int) $attempt['id'] : 0,
    'view'      => $view,
    'remaining' => $remaining,
    'network'   => $network,
    'status'    => BASE_URL . '/pay_status.php',
], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);

mm_public_end('var MM=' . $cfg . ';' . <<<'JS'
(function(){
  var $=function(id){return document.getElementById(id);};
  var views=['pay','wait','failed','creating','done'];
  function show(v){ views.forEach(function(x){var e=$('v-'+x); if(e) e.classList.toggle('mm-hide',x!==v);}); MM.view=v; steps(v); }
  function steps(v){
    var lis=document.querySelectorAll('#steps li'); if(!lis.length) return;
    var now=(v==='creating'||v==='done')?2:1;
    lis.forEach(function(li,i){ li.className=i<now?'done':(i===now?'now':''); li.querySelector('.n').innerHTML=i<now?'&#10003;':(i+1); });
    if(v==='done'){ lis[2].className='done'; lis[2].querySelector('.n').innerHTML='&#10003;'; }
  }
  /* the number box: show which network it is */
  var phone=$('phone'), net=$('net');
  function detect(){
    if(!phone||!net) return; var d=phone.value.replace(/\D/g,''); if(d.indexOf('250')===0) d=d.slice(3); if(d.charAt(0)==='0') d=d.slice(1);
    var p=d.slice(0,2), t=(p==='78'||p==='79')?'mtn':((p==='72'||p==='73')?'airtel':'');
    net.style.display=t?'':'none'; net.className='mm-net '+t; net.textContent=t==='mtn'?'MTN':(t==='airtel'?'Airtel':'');
  }
  if(phone){ phone.addEventListener('input',detect); detect(); }
  var form=$('payform'); if(form) form.addEventListener('submit',function(){ var b=$('paybtn'); b.disabled=true; b.innerHTML='<span class="mm-spinner"></span> Sending to your phone...'; });
  /* which "no prompt" tip to show */
  if($('how-mtn')) { $('how-mtn').style.display=MM.network==='MTN'?'':'none'; $('how-airtel').style.display=MM.network==='Airtel'?'':'none'; }

  /* the countdown while the phone prompt is open */
  var left=MM.remaining;
  function tick(){
    var t=$('timer'); if(!t) return;
    var m=Math.floor(left/60), s=left%60; t.textContent=m+':'+(s<10?'0':'')+s;
    if(left<=0){ $('timerhint').textContent='Still waiting. If you approved it, this page updates by itself.'; $('retry').classList.remove('mm-hide'); return; }
    left--; setTimeout(tick,1000);
  }
  if(MM.view==='wait') tick();

  /* ask our server (which asks XentriPay) every 3 seconds */
  var busy=false, started=Date.now();
  function poll(){
    if(busy||MM.view==='done'||MM.view==='failed'||MM.view==='pay'||MM.view==='notready') return; busy=true;
    fetch(MM.status+'?t='+encodeURIComponent(MM.token)+'&a='+MM.attempt,{cache:'no-store',credentials:'same-origin'})
      .then(function(r){return r.json();})
      .then(function(d){
        busy=false;
        if(d.activated){ show('done'); return; }
        if(d.paid){ if(MM.view!=='creating') show('creating'); if(Date.now()-started>240000) $('creatinghint').textContent='This is taking longer than usual. You will get an email as soon as your account is ready, so you can close this page.'; return; }
        if(d.state==='failed'||d.state==='expired'){ $('failreason').textContent=d.reason||'The payment was not approved.'; show('failed'); }
      })
      .catch(function(){ busy=false; });
  }
  if(MM.view==='wait'||MM.view==='creating'){ setInterval(poll,3000); poll(); }
})();
JS);
