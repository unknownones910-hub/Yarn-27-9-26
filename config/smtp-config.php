<?php
/**
 * SMTP defaults. Safe to commit — no password here.
 *
 * Real credentials go in config/smtp-config.local.php, which is gitignored and
 * never leaves the machine, exactly like the db, Brevo and Razorpay files.
 *
 * Ships INERT: with an empty password, smtp_configured() returns false and the
 * mail layer falls back to whatever else is set up (or degrades gracefully and
 * shows OTPs on screen in local dev).
 *
 * ---------------------------------------------------------------------------
 * Gmail specifics, because they trip everyone up:
 *
 *   1. 'password' is NOT your Gmail password. Google turned off plain password
 *      sign-in for SMTP. You need an APP PASSWORD: turn on 2-Step Verification
 *      on the account, then Google Account → Security → App passwords. It is 16
 *      characters; paste it with or without spaces, both work.
 *
 *   2. 'from_email' must be the same address as 'username'. Gmail rewrites the
 *      From: header to the authenticated account anyway, so a different value
 *      silently does nothing.
 *
 *   3. Roughly 500 messages a day on a free Gmail account, 2,000 on Workspace.
 *
 *   4. Many shared hosts — InfinityFree included — block outbound ports 25, 465
 *      and 587, which is every port SMTP can use. If mail works locally and
 *      fails on the server with a connection timeout, that is what happened,
 *      and no amount of credential fiddling will fix it. Set
 *      'transport' => 'brevo' in that case; the Brevo path is an HTTPS API call
 *      on port 443, which those hosts do allow.
 * ---------------------------------------------------------------------------
 */
declare(strict_types=1);

$SMTP = [
    // 'smtp' | 'brevo' | 'auto'
    //   auto  = use SMTP when it is configured, otherwise Brevo.
    'transport' => 'smtp',

    // Try the other transport if the first one fails outright. Costs one
    // wasted connection attempt on a blocked host and saves the email.
    'fallback'  => true,

    'host'      => 'smtp.gmail.com',
    'port'      => 587,
    'secure'    => 'tls',              // 'tls' for 587, 'ssl' for 465
    'username'  => 'yarnnestsite@gmail.com',
    'password'  => '',                 // App Password — set in the .local file

    'from_email' => 'yarnnestsite@gmail.com',
    'from_name'  => 'The Yarn Nest',
    'reply_to'   => 'yarnnestsite@gmail.com',

    'timeout'   => 15,
];

$localSmtp = __DIR__ . '/smtp-config.local.php';
if (is_file($localSmtp)) {
    $override = require $localSmtp;
    if (is_array($override)) {
        $SMTP = array_merge($SMTP, $override);
    }
}
