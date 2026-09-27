<?php
/**
 * Newsletter signup with double opt-in.
 *
 * Double opt-in is not optional politeness: a confirmed list is the difference
 * between landing in the inbox and landing in spam, and consent has to be
 * demonstrable under the DPDP Act.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/mailer.php';

// ---- confirmation link ------------------------------------------------
$token = get_str('confirm', 40);
if ($token !== '') {
    $row = db_row('SELECT * FROM newsletter_subscribers WHERE confirm_token = ? LIMIT 1', [$token]);

    if ($row) {
        db_exec(
            'UPDATE newsletter_subscribers
             SET status = "confirmed", confirmed_at = NOW(), confirm_token = NULL WHERE id = ?',
            [(int) $row['id']]
        );
        // Keep the account flag in step if they have one.
        db_exec('UPDATE customers SET marketing_opt_in = 1 WHERE email = ?', [(string) $row['email']]);
        flash('You are subscribed — thank you! Your 10% welcome code is on its way.', 'success');
    } else {
        flash('That confirmation link has already been used, or has expired.', 'warn');
    }
    redirect('');
}

// ---- unsubscribe link -------------------------------------------------
$off = get_str('unsubscribe', 190);
if ($off !== '') {
    db_exec('UPDATE newsletter_subscribers SET status = "unsubscribed" WHERE email = ?', [$off]);
    db_exec('UPDATE customers SET marketing_opt_in = 0 WHERE email = ?', [$off]);
    flash('Unsubscribed. You will still get emails about any orders you place.', 'success');
    redirect('');
}

// ---- signup -----------------------------------------------------------
if (!is_post()) {
    redirect('');
}

csrf_verify();

$back = $_SERVER['HTTP_REFERER'] ?? url('');
$back = (is_string($back) && str_starts_with($back, abs_url(''))) ? $back : url('');

if (!honeypot_ok()) {
    flash('Thanks! Please check your inbox to confirm.', 'success');
    redirect($back);
}

$email = post_str('email', 190);

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash('That does not look like a valid email address.', 'error');
    redirect($back);
}

if (!rate_hit('nl:' . client_ip(), 8, 3600)) {
    flash('Too many signup attempts. Please try again later.', 'error');
    redirect($back);
}

$existing = db_row('SELECT * FROM newsletter_subscribers WHERE email = ? LIMIT 1', [$email]);

if ($existing && $existing['status'] === 'confirmed') {
    flash('You are already on the list — thank you!', 'success');
    redirect($back);
}

$token = random_token(20);

db_exec(
    'INSERT INTO newsletter_subscribers (email, source, status, confirm_token)
     VALUES (?, ?, "pending", ?)
     ON DUPLICATE KEY UPDATE status = "pending", confirm_token = VALUES(confirm_token)',
    [$email, post_str('source', 40) ?: 'footer', $token]
);

$confirmUrl = abs_url('newsletter-subscribe.php?confirm=' . rawurlencode($token));

try {
    send_mail($email, '',
        'Confirm your subscription · ' . setting('store_name'),
        email_layout('One more click',
            '<p style="margin:0 0 16px;">Thanks for signing up. Confirm your email address and '
            . 'we will send your <strong>10% welcome code</strong> straight away.</p>'
            . email_button('Confirm my subscription', $confirmUrl)
            . '<p style="margin:0 0 16px;color:#5D554C;font-size:14px;">You will hear from us no '
            . 'more than twice a month — new shades, free patterns and the occasional sale. '
            . 'Every email has a one-click unsubscribe.</p>'
            . '<p style="margin:0;color:#8A8078;font-size:14px;">If you did not sign up, just '
            . 'ignore this email. Nothing happens without that button.</p>',
            'Confirm your subscription and get 10% off'),
        "Confirm your subscription:\n{$confirmUrl}\n",
        'newsletter_confirm');
} catch (Throwable $e) {
    error_log('Newsletter confirm email failed: ' . $e->getMessage());
}

flash(is_local_dev()
    ? 'Almost there — check your inbox. (Local dev: no email sent, confirm at ' . $confirmUrl . ')'
    : 'Almost there — check your inbox and click the confirmation link.', 'success');

redirect($back);
