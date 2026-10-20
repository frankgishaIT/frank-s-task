<?php
/**
 * The emails MY MOTIVE sends, in one consistent design.
 *
 * Email programs ignore most modern web styling, so this uses the one layout every one of them understands:
 * tables with inline styles. Each builder returns [subject, html].
 */

require_once __DIR__ . '/platform.php';

function mm_e($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function mm_mail_money(int $rwf): string
{
    return number_format($rwf, 0, '.', ',') . ' RWF';
}

/**
 * The frame.
 *
 * @param array{preheader?:string, title:string, intro?:string, rows?:array<int,array{0:string,1:string}>, cta?:array{0:string,1:string}, after?:string, tone?:string} $o
 *   rows = a summary box of [label, value] pairs;  cta = [button text, address];  tone = 'default' | 'success'
 */
function mm_email_layout(array $o): string
{
    $base = platform_public_url();
    $logo = $base !== '' ? $base . '/assets/images/logo-badge.png' : '';
    $accent = ($o['tone'] ?? 'default') === 'success' ? '#12A06B' : '#3B4FE0';
    $pre = mm_e($o['preheader'] ?? '');

    $h = '<div style="margin:0;padding:0;background:#F4F6FB">';
    $h .= '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:#F4F6FB;font-size:1px;line-height:1px">' . $pre . '&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;</div>';
    $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F4F6FB"><tr><td align="center" style="padding:32px 14px">';
    $h .= '<table role="presentation" width="600" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;font-family:Inter,Arial,Helvetica,sans-serif">';

    // header band
    $h .= '<tr><td style="background:' . $accent . ';background-image:linear-gradient(135deg,#2E3FC0 0%,' . $accent . ' 55%,#6C5CE7 100%);border-radius:18px 18px 0 0;padding:26px 34px">'
        . '<table role="presentation" cellpadding="0" cellspacing="0"><tr>'
        . ($logo !== '' ? '<td style="padding-right:12px"><img src="' . mm_e($logo) . '" width="40" height="40" alt="" style="display:block;border-radius:20px;border:2px solid #ffffff"></td>' : '')
        . '<td style="color:#fff;font-size:18px;font-weight:800;letter-spacing:.03em">MY MOTIVE</td></tr></table></td></tr>';

    // body card
    $h .= '<tr><td style="background:#fff;padding:36px 34px 30px;color:#3A4156;font-size:15px;line-height:1.65">';
    $h .= '<div style="font-size:24px;line-height:1.25;font-weight:800;color:#1E2333;margin:0 0 14px;letter-spacing:-.01em">' . mm_e($o['title']) . '</div>';
    if (!empty($o['intro'])) {
        $h .= '<div style="margin:0 0 22px">' . $o['intro'] . '</div>';
    }
    if (!empty($o['rows'])) {
        $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#FAFBFE;border:1px solid #E4E7F0;border-radius:14px;margin:0 0 26px"><tr><td style="padding:8px 20px">';
        $h .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0">';
        $n = count($o['rows']);
        foreach ($o['rows'] as $i => [$label, $value]) {
            $border = $i < $n - 1 ? 'border-bottom:1px solid #EDEFF5;' : '';
            $h .= '<tr><td style="padding:11px 0;' . $border . 'color:#6B7285;font-size:13.5px">' . mm_e($label) . '</td>'
                . '<td align="right" style="padding:11px 0;' . $border . 'color:#1E2333;font-size:14.5px;font-weight:700">' . $value . '</td></tr>';
        }
        $h .= '</table></td></tr></table>';
    }
    if (!empty($o['cta'])) {
        [$label, $url] = $o['cta'];
        $h .= '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:6px 0 24px"><tr><td style="border-radius:13px;background:' . $accent . '">'
            . '<a href="' . mm_e($url) . '" style="display:inline-block;padding:15px 34px;color:#ffffff;font-size:16px;font-weight:700;text-decoration:none;border-radius:13px">' . mm_e($label) . '</a>'
            . '</td></tr></table>';
        $h .= '<div style="font-size:12.5px;color:#8A90A3;margin:0 0 18px">Button not working? Copy this address into your browser:<br>'
            . '<span style="color:#3B4FE0;word-break:break-all">' . mm_e($url) . '</span></div>';
    }
    if (!empty($o['after'])) {
        $h .= '<div style="margin:0">' . $o['after'] . '</div>';
    }
    $h .= '</td></tr>';

    // footer
    $h .= '<tr><td style="background:#fff;border-top:1px solid #EDEFF5;border-radius:0 0 18px 18px;padding:20px 34px 26px;color:#8A90A3;font-size:12.5px;line-height:1.6">'
        . 'You received this email because of a request made with this address on MY MOTIVE. If this was not you, you can safely ignore it.'
        . '</td></tr></table></td></tr></table></div>';
    return $h;
}

function mm_mail_p(string $text): string
{
    return '<p style="margin:0 0 14px">' . $text . '</p>';
}

function mm_mail_received(array $req): array
{
    return ['We received your request ' . $req['reference'], mm_email_layout([
        'preheader' => 'Your request ' . $req['reference'] . ' is with us. We will reply soon.',
        'title'     => 'We received your request',
        'intro'     => mm_mail_p('Hello ' . mm_e($req['owner_name']) . ',')
            . mm_mail_p('Thank you for choosing MY MOTIVE for <b>' . mm_e($req['company_name']) . '</b>. We are reviewing your request and will email you the result.'),
        'rows'      => [
            ['Reference', mm_e($req['reference'])],
            ['Package', mm_e($req['package_name']) . ' (' . mm_e($req['billing_period']) . ')'],
            ['Amount', mm_e(mm_mail_money((int) $req['amount_due']))],
        ],
        'after'     => mm_mail_p('Nothing to pay yet. If we approve your request, the next email has a secure payment button.'),
    ])];
}

function mm_mail_approved(array $req, string $payUrl, string $validUntil): array
{
    $alt = trim((string) platform_setting('payment_instructions', ''));
    return ['Approved: complete your payment (' . $req['reference'] . ')', mm_email_layout([
        'preheader' => 'Good news! Pay ' . mm_mail_money((int) $req['amount_due']) . ' to open ' . $req['company_name'] . '.',
        'title'     => 'Your request is approved',
        'intro'     => mm_mail_p('Hello ' . mm_e($req['owner_name']) . ',')
            . mm_mail_p('Good news: <b>' . mm_e($req['company_name']) . '</b> is approved. One last step: pay the amount below with Mobile Money. You will get a prompt on your phone to confirm it with your PIN. Your account opens by itself right after.'),
        'rows'      => [
            ['Package', mm_e($req['package_name']) . ' (' . mm_e($req['billing_period']) . ')'],
            ['Business code', mm_e($req['company_code'])],
            ['Reference', mm_e($req['reference'])],
            ['Amount to pay', '<span style="font-size:18px;color:#2E3FC0">' . mm_e(mm_mail_money((int) $req['amount_due'])) . '</span>'],
        ],
        'cta'       => ['Pay ' . mm_mail_money((int) $req['amount_due']) . ' now', $payUrl],
        'after'     => mm_mail_p('<span style="color:#6B7285;font-size:13.5px">This secure link works until ' . mm_e($validUntil) . '. It is only for you: please do not share it.</span>')
            . ($alt !== '' ? mm_mail_p('<span style="color:#6B7285;font-size:13.5px">Prefer another way to pay? ' . nl2br(mm_e($alt)) . '</span>') : ''),
    ])];
}

function mm_mail_rejected(array $req): array
{
    return ['About your request ' . $req['reference'], mm_email_layout([
        'preheader' => 'We could not approve your request this time.',
        'title'     => 'We could not approve your request',
        'intro'     => mm_mail_p('Hello ' . mm_e($req['owner_name']) . ',')
            . mm_mail_p('We are sorry: we could not approve the request <b>' . mm_e($req['reference']) . '</b> for ' . mm_e($req['company_name']) . '.'),
        'rows'      => [['Reason', mm_e($req['reject_reason'])]],
        'after'     => mm_mail_p('You are welcome to apply again after fixing this. If you think this is a mistake, simply reply to this email.'),
    ])];
}

function mm_mail_payment_received(array $req, int $amount, string $paymentRef): array
{
    return ['Payment received (' . $req['reference'] . ')', mm_email_layout([
        'tone'      => 'success',
        'preheader' => 'Thank you! We are creating your business now.',
        'title'     => 'Payment received. Thank you!',
        'intro'     => mm_mail_p('Hello ' . mm_e($req['owner_name']) . ',')
            . mm_mail_p('We received your payment. We are creating <b>' . mm_e($req['company_name']) . '</b> right now. In a minute you will get one more email with the link to choose your password.'),
        'rows'      => [
            ['Reference', mm_e($req['reference'])],
            ['Paid', mm_e(mm_mail_money($amount))],
            ['Payment reference', mm_e($paymentRef)],
            ['Package', mm_e($req['package_name']) . ' (' . mm_e($req['billing_period']) . ')'],
        ],
    ])];
}

function mm_mail_welcome(array $req, array $company, string $link): array
{
    return ['Your MY MOTIVE account is ready', mm_email_layout([
        'tone'      => 'success',
        'preheader' => 'Choose your password and sign in. Your business code is ' . $company['company_code'] . '.',
        'title'     => 'Your account is ready',
        'intro'     => mm_mail_p('Hello ' . mm_e($req['owner_name']) . ',')
            . mm_mail_p('<b>' . mm_e($company['name']) . '</b> is open on MY MOTIVE. Choose your own password to get started. The link below works once and stays valid for 7 days.'),
        'rows'      => [
            ['Business code', '<span style="font-size:18px;letter-spacing:.04em;color:#2E3FC0">' . mm_e($company['company_code']) . '</span>'],
            ['Your email', mm_e($req['owner_email'])],
        ],
        'cta'       => ['Choose my password', $link],
        'after'     => mm_mail_p('<span style="color:#6B7285;font-size:13.5px">You and your staff sign in with the <b>business code</b>, an email and a password. Keep the code handy: share it only with your team.</span>')
            . mm_mail_p('<span style="color:#6B7285;font-size:13.5px">Sign in page: ' . mm_e(platform_public_url()) . '/login.php</span>'),
    ])];
}

function mm_mail_password_reset(string $companyName, string $personName, string $link): array
{
    return ['Reset your MY MOTIVE password', mm_email_layout([
        'preheader' => 'Use this link within 1 hour to choose a new password.',
        'title'     => 'Reset your password',
        'intro'     => mm_mail_p('Hello ' . mm_e($personName) . ',')
            . mm_mail_p('Someone asked to reset the password of your account at <b>' . mm_e($companyName) . '</b>. If it was you, choose a new password with the button below. The link works once and expires in 1 hour.'),
        'cta'       => ['Choose a new password', $link],
        'after'     => mm_mail_p('<span style="color:#6B7285;font-size:13.5px">If you did not ask for this, ignore this email: your password stays exactly as it is.</span>'),
    ])];
}

function mm_mail_test(string $name, bool $dryRun): array
{
    return ['MY MOTIVE test email', mm_email_layout([
        'tone'      => 'success',
        'preheader' => 'If you can read this, email works.',
        'title'     => 'Email works',
        'intro'     => mm_mail_p('Hello ' . mm_e($name) . ',')
            . mm_mail_p('This is a test message from the MY MOTIVE platform. If you are reading it in your inbox, your mail settings are correct and customers will receive their emails.'),
        'rows'      => [['Mode', $dryRun ? 'Test mode (not really sent)' : 'Live'], ['Sent at', mm_e(date('Y-m-d H:i'))]],
    ])];
}


// ---------------------------------------------------------------- subscriptions

/** "Your subscription ends soon" (or "ended"). $days = days left; 0 or less = ends today / already ended. */
function mm_mail_subscription_notice(array $company, string $packageName, string $endsOn, int $days, int $amount, string $payHelp): array
{
    $when = $days > 1 ? 'in ' . $days . ' days' : ($days === 1 ? 'tomorrow' : ($days === 0 ? 'today' : 'on ' . $endsOn));
    return ['Your MY MOTIVE subscription ends ' . $when, mm_email_layout([
        'preheader' => 'Renew ' . $company['name'] . ' to keep working without interruption.',
        'title'     => $days > 0 ? 'Your subscription ends ' . $when : 'Your subscription has ended',
        'intro'     => mm_mail_p('Hello,')
            . mm_mail_p($days > 0
                ? 'The <b>' . mm_e($packageName) . '</b> subscription of <b>' . mm_e($company['name']) . '</b> ends ' . mm_e($when) . ' (' . mm_e($endsOn) . '). Renew it to keep working without interruption.'
                : 'The <b>' . mm_e($packageName) . '</b> subscription of <b>' . mm_e($company['name']) . '</b> ended on ' . mm_e($endsOn) . '. Renew it now: after a few days of grace the account is paused, and all your data is kept safely.'),
        'rows'      => [['Business code', mm_e($company['company_code'])], ['Package', mm_e($packageName)], ['Renewal', mm_e(mm_mail_money($amount))]],
        'after'     => $payHelp !== '' ? mm_mail_p('<span style="color:#6B7285;font-size:13.5px">' . nl2br(mm_e($payHelp)) . '</span>') : '',
    ])];
}

function mm_mail_subscription_suspended(array $company, string $endedOn): array
{
    return ['Your MY MOTIVE account is paused', mm_email_layout([
        'preheader' => 'Renew to open it again. Your data is safe.',
        'title'     => 'Your account is paused',
        'intro'     => mm_mail_p('Hello,')
            . mm_mail_p('The subscription of <b>' . mm_e($company['name']) . '</b> ended on ' . mm_e($endedOn) . ' and was not renewed, so the account is paused. <b>Nothing was deleted</b>: as soon as you renew, everything is back exactly as you left it.'),
        'rows'      => [['Business code', mm_e($company['company_code'])]],
    ])];
}

function mm_mail_renewed(array $company, string $packageName, string $newEnd, int $amount): array
{
    return ['Subscription renewed until ' . $newEnd, mm_email_layout([
        'tone'      => 'success',
        'preheader' => 'Thank you! ' . $company['name'] . ' is active until ' . $newEnd . '.',
        'title'     => 'Subscription renewed. Thank you!',
        'intro'     => mm_mail_p('Hello,') . mm_mail_p('We received your payment. <b>' . mm_e($company['name']) . '</b> is active until <b>' . mm_e($newEnd) . '</b>.'),
        'rows'      => [['Package', mm_e($packageName)], ['Paid', mm_e(mm_mail_money($amount))], ['Active until', mm_e($newEnd)]],
    ])];
}
