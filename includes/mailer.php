<?php
/**
 * Transactional email. Two transports behind one function.
 *
 *   smtp   PHPMailer over SMTP — currently Gmail. Selected by default.
 *   brevo  Brevo's HTTPS API. Kept, not deleted, because it is the escape
 *          hatch when SMTP is unavailable.
 *
 * Everything in the app calls send_mail() and neither knows nor cares which is
 * in use. Pick one with 'transport' in config/smtp-config.php.
 *
 * WHY BOTH EXIST — worth reading before you delete one:
 *
 * SMTP needs an outbound connection on port 25, 465 or 587. A great many shared
 * hosts block all three, InfinityFree among them, and there is no way round it
 * from inside PHP. Mail that works perfectly on a laptop then times out on the
 * server. Brevo is an HTTPS call on port 443, which those same hosts allow, so
 * it works where SMTP cannot. That is the entire reason it is still here.
 *
 * With 'fallback' => true the other transport is tried when the first fails,
 * which turns a blocked port from "no customer can sign in" into a logged
 * warning and a delivered email.
 *
 * This layer NEVER throws and never fatals a request. If mail cannot be sent,
 * it returns false, records the reason in `email_log`, and the caller decides
 * what to tell the customer. Losing an email must not lose an order.
 */
declare(strict_types=1);

require_once YN_ROOT . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception as PHPMailerException;

// ------------------------------------------------------------------ smtp --

function smtp_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }

    require YN_ROOT . '/config/smtp-config.php';   // defines $SMTP, merges .local
    $cfg = $SMTP;

    // Environment wins, for hosts that prefer env vars over files.
    $env = getenv('SMTP_PASSWORD');
    if (is_string($env) && $env !== '') {
        $cfg['password'] = $env;
    }

    // Gmail app passwords are shown in groups of four; people paste the spaces.
    $cfg['password'] = str_replace(' ', '', (string) $cfg['password']);

    return $cfg;
}

/** False until an app password is set — the SMTP transport is then inert. */
function smtp_configured(): bool
{
    $c = smtp_config();
    return $c['password'] !== '' && $c['username'] !== '' && $c['host'] !== '';
}

/**
 * Which transport handles this send.
 *
 * @return string 'smtp' | 'brevo' | 'none'
 */
function mail_transport(): string
{
    $want = (string) (smtp_config()['transport'] ?? 'auto');

    if ($want === 'smtp' && smtp_configured())  { return 'smtp'; }
    if ($want === 'brevo' && brevo_configured()) { return 'brevo'; }

    // 'auto', or the requested transport is not actually configured.
    if (smtp_configured())  { return 'smtp'; }
    if (brevo_configured()) { return 'brevo'; }

    return 'none';
}

function brevo_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }

    $cfg = ['api_key' => '', 'from_email' => '', 'from_name' => '', 'reply_to' => ''];

    $file = YN_ROOT . '/config/brevo-config.local.php';
    if (is_file($file)) {
        $local = require $file;
        if (is_array($local)) {
            $cfg = array_merge($cfg, $local);
        }
    }

    // Environment wins, for hosts that prefer env vars over files.
    $env = getenv('BREVO_API_KEY');
    if (is_string($env) && $env !== '') {
        $cfg['api_key'] = $env;
    }

    if ($cfg['from_email'] === '') {
        $cfg['from_email'] = setting('store_email', 'hello@example.com');
    }
    if ($cfg['from_name'] === '') {
        $cfg['from_name'] = setting('store_name', 'The Yarn Nest');
    }

    return $cfg;
}

/** False when no API key is set — the whole mail layer is then inert. */
function brevo_configured(): bool
{
    $c = brevo_config();
    return $c['api_key'] !== '';
}

function email_log(string $to, string $subject, string $template, string $status,
                   ?string $messageId = null, ?string $error = null): void
{
    try {
        db_exec(
            'INSERT INTO email_log (to_email, subject, template, status, provider_message_id, error)
             VALUES (?,?,?,?,?,?)',
            [
                mb_substr($to, 0, 190),
                mb_substr($subject, 0, 255),
                mb_substr($template, 0, 60),
                $status,
                $messageId !== null ? mb_substr($messageId, 0, 120) : null,
                $error !== null ? mb_substr($error, 0, 500) : null,
            ]
        );
    } catch (Throwable $e) {
        error_log('email_log write failed: ' . $e->getMessage());
    }
}

/**
 * Send one transactional email. The only mail entry point in the app.
 *
 * @param array $attachments  [['name' => 'invoice.pdf', 'content' => <raw bytes>], …]
 */
function send_mail(
    string $to,
    string $toName,
    string $subject,
    string $html,
    ?string $text = null,
    string $template = 'generic',
    array $attachments = []
): bool {
    $transport = mail_transport();

    if ($transport === 'none') {
        // Expected on a fresh install and in local dev — not an error.
        email_log($to, $subject, $template, 'skipped', null, 'No mail transport configured');
        if (is_local_dev()) {
            error_log("[mail skipped: no transport] to={$to} subject={$subject}");
        }
        return false;
    }

    $order = [$transport];
    if (!empty(smtp_config()['fallback'])) {
        // The other one, if it is actually usable. This is what rescues a host
        // that silently blocks outbound SMTP ports.
        $other = $transport === 'smtp' ? 'brevo' : 'smtp';
        if (($other === 'brevo' && brevo_configured()) || ($other === 'smtp' && smtp_configured())) {
            $order[] = $other;
        }
    }

    $lastError = '';
    foreach ($order as $i => $via) {
        $res = $via === 'smtp'
            ? smtp_send($to, $toName, $subject, $html, $text, $attachments)
            : brevo_send($to, $toName, $subject, $html, $text, $attachments);

        if ($res['error'] === '') {
            // Record which transport actually delivered it, and say so when it
            // was the fallback — otherwise a host silently limping along on the
            // backup looks identical to one that is working properly.
            email_log(
                $to, $subject, $template, 'sent',
                $res['id'],
                $i === 0 ? null : 'Sent via ' . $via . ' after ' . $order[0] . ' failed: ' . $lastError
            );
            return true;
        }

        $lastError = $via . ': ' . $res['error'];
        error_log('Mail failed via ' . $lastError);
    }

    email_log($to, $subject, $template, 'failed', null, $lastError);
    return false;
}

/**
 * Deliver over SMTP.
 *
 * PHPMailer rather than a hand-rolled socket client: invoices go out as PDF
 * attachments, and hand-built MIME is exactly where that quietly breaks.
 *
 * @return array{error:string,id:?string} error is '' on success
 */
function smtp_send(string $to, string $toName, string $subject, string $html,
                   ?string $text, array $attachments): array
{
    $cfg  = smtp_config();
    $mail = new PHPMailer(true);          // true = throw instead of returning false

    try {
        $mail->isSMTP();
        $mail->Host       = $cfg['host'];
        $mail->Port       = (int) $cfg['port'];
        $mail->SMTPAuth   = true;
        $mail->Username   = $cfg['username'];
        $mail->Password   = $cfg['password'];
        $mail->SMTPSecure = ($cfg['secure'] === 'ssl')
            ? PHPMailer::ENCRYPTION_SMTPS          // 465
            : PHPMailer::ENCRYPTION_STARTTLS;      // 587
        $mail->Timeout    = (int) $cfg['timeout'];
        $mail->CharSet    = PHPMailer::CHARSET_UTF8;

        // Without this PHPMailer builds the Message-ID from the machine's
        // hostname — "…@DESKTOP-SVVHCDA" on a laptop, or a shared host's
        // internal name in production. Spam filters read that domain, so give
        // them the site's own.
        global $SITE;
        $host = (string) ($SITE['domain'] ?? '');
        if ($host !== '' && $host !== 'localhost') {
            $mail->Hostname = $host;
        }

        // Gmail rewrites From: to the authenticated account regardless, so
        // sending anything else here just creates a mismatch.
        $mail->setFrom($cfg['from_email'], $cfg['from_name']);
        $mail->addAddress($to, $toName !== '' ? $toName : $to);
        if (!empty($cfg['reply_to'])) {
            $mail->addReplyTo($cfg['reply_to'], $cfg['from_name']);
        }

        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html;
        $mail->AltBody = ($text !== null && $text !== '')
            ? $text
            : trim(html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8'));

        foreach ($attachments as $a) {
            if (!empty($a['name']) && isset($a['content'])) {
                $mail->addStringAttachment((string) $a['content'], (string) $a['name']);
            }
        }

        $mail->send();
        // PHPMailer generates the Message-ID; keep it so a bounce can be traced.
        return ['error' => '', 'id' => trim($mail->getLastMessageID()) ?: null];
    } catch (PHPMailerException $e) {
        // ErrorInfo is more specific than the exception message.
        return ['error' => $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage(), 'id' => null];
    } catch (Throwable $e) {
        return ['error' => $e->getMessage(), 'id' => null];
    }
}

/**
 * Deliver through the Brevo HTTPS API. Returns '' on success, else the reason.
 *
 * Not dead code: this is the transport that still works on hosts which block
 * outbound SMTP ports.
 */
/** @return array{error:string,id:?string} */
function brevo_send(string $to, string $toName, string $subject, string $html,
                    ?string $text, array $attachments): array
{
    $cfg = brevo_config();

    $payload = [
        'sender'      => ['name' => $cfg['from_name'], 'email' => $cfg['from_email']],
        'to'          => [['email' => $to, 'name' => $toName !== '' ? $toName : $to]],
        'subject'     => $subject,
        'htmlContent' => $html,
    ];

    if ($text !== null && $text !== '') {
        $payload['textContent'] = $text;
    }
    if (!empty($cfg['reply_to'])) {
        $payload['replyTo'] = ['email' => $cfg['reply_to'], 'name' => $cfg['from_name']];
    }
    foreach ($attachments as $a) {
        if (!empty($a['name']) && isset($a['content'])) {
            $payload['attachment'][] = [
                'name'    => $a['name'],
                'content' => base64_encode((string) $a['content']),
            ];
        }
    }

    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_HTTPHEADER     => [
            'accept: application/json',
            'content-type: application/json',
            'api-key: ' . $cfg['api_key'],
        ],
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);

    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlEr = curl_error($ch);
    curl_close($ch);

    if ($body === false || $status < 200 || $status >= 300) {
        return ['error' => $curlEr !== '' ? $curlEr
                    : ('HTTP ' . $status . ' ' . substr((string) $body, 0, 300)), 'id' => null];
    }

    $decoded = json_decode((string) $body, true);
    return ['error' => '', 'id' => is_array($decoded) ? ($decoded['messageId'] ?? null) : null];
}

// --------------------------------------------------------------- layout --

/**
 * Shared email shell: 600px, table-based, inline styles, light background.
 * Deliberately plain — clients strip most CSS, and a simple email that
 * renders everywhere beats a pretty one that breaks in Outlook.
 */
function email_layout(string $heading, string $bodyHtml, string $preheader = ''): string
{
    $brand   = e(setting('store_name', 'The Yarn Nest'));
    $site    = e(abs_url('/'));
    $address = e(setting('store_address'));
    $phone   = e(setting('store_phone'));
    $mail    = e(setting('store_email'));
    $year    = date('Y');

    return <<<HTML
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>{$heading}</title>
</head>
<body style="margin:0;padding:0;background:#FDFBF7;">
<div style="display:none;max-height:0;overflow:hidden;opacity:0;">{$preheader}</div>
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
       style="background:#FDFBF7;padding:24px 12px;">
  <tr><td align="center">
    <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0"
           style="width:100%;max-width:600px;background:#FFFFFF;border:1px solid #EADFD0;border-radius:16px;overflow:hidden;">
      <tr>
        <td style="padding:24px 32px;border-bottom:1px solid #F5EEE4;">
          <a href="{$site}" style="font:600 20px Georgia,serif;color:#2E2A26;text-decoration:none;">{$brand}</a>
        </td>
      </tr>
      <tr>
        <td style="padding:32px;font:16px/1.65 -apple-system,'Segoe UI',Roboto,Arial,sans-serif;color:#2E2A26;">
          <h1 style="margin:0 0 16px;font:600 24px/1.25 Georgia,serif;color:#2E2A26;">{$heading}</h1>
          {$bodyHtml}
        </td>
      </tr>
      <tr>
        <td style="padding:24px 32px;background:#F5EEE4;font:13px/1.6 -apple-system,'Segoe UI',Roboto,Arial,sans-serif;color:#6F675E;">
          <strong style="color:#2E2A26;">{$brand}</strong><br>
          {$address}<br>
          {$phone} &middot; <a href="mailto:{$mail}" style="color:#B4552F;">{$mail}</a>
          <div style="margin-top:12px;color:#8A8078;">&copy; {$year} {$brand}</div>
        </td>
      </tr>
    </table>
  </td></tr>
</table>
</body></html>
HTML;
}

/** Terracotta pill button that survives Outlook. */
function email_button(string $label, string $url): string
{
    $label = e($label);
    $url   = e($url);
    return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:24px 0;">'
         . '<tr><td style="background:#B4552F;border-radius:999px;">'
         . '<a href="' . $url . '" style="display:inline-block;padding:14px 28px;'
         . 'font:600 15px -apple-system,\'Segoe UI\',Roboto,Arial,sans-serif;color:#FFFFFF;text-decoration:none;">'
         . $label . '</a></td></tr></table>';
}

// ------------------------------------------------------------ OTP email --

function email_otp_html(string $code, int $ttlMinutes): string
{
    $codeHtml = '<div style="margin:24px 0;padding:20px;background:#FBEFE8;border-radius:14px;text-align:center;">'
              . '<div style="font:700 34px/1 \'Courier New\',monospace;letter-spacing:10px;color:#B4552F;">'
              . e($code) . '</div></div>';

    $body = '<p style="margin:0 0 16px;">Use this code to sign in:</p>'
          . $codeHtml
          . '<p style="margin:0 0 16px;color:#5D554C;">It expires in <strong>' . $ttlMinutes
          . ' minutes</strong> and can only be used once.</p>'
          . '<p style="margin:0;color:#8A8078;font-size:14px;">'
          . 'If you did not ask to sign in, you can ignore this email — nobody can access your '
          . 'account without this code.</p>';

    return email_layout('Your sign-in code', $body, 'Your code is ' . $code);
}

function email_otp_text(string $code, int $ttlMinutes): string
{
    return "Your sign-in code is {$code}\n\n"
         . "It expires in {$ttlMinutes} minutes and can only be used once.\n\n"
         . "If you did not ask to sign in, you can ignore this email.\n\n"
         . setting('store_name', 'The Yarn Nest');
}

function send_otp_email(string $to, string $code, int $ttlMinutes = 10): bool
{
    return send_mail(
        $to,
        '',
        'Your sign-in code: ' . $code,
        email_otp_html($code, $ttlMinutes),
        email_otp_text($code, $ttlMinutes),
        'otp'
    );
}
