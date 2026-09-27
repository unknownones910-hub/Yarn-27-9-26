<?php
/**
 * Migration 009 — FAQ text for the password sign-in.
 *
 * Sign-in changed shape in 008, so three answers that described the old
 * passwordless flow are now wrong, and there was no answer at all for the most
 * predictable question a password introduces ("I forgot mine").
 *
 * Also repairs FAQ #1, which was overwritten during Phase 6 with a line proving
 * the admin editor writes through, and never put back.
 *
 * Matched on the question text, so it is safe to re-run and it will not clobber
 * a genuine edit made from the admin panel — only the exact strings below.
 *
 * @var mysqli $conn  provided by install.php
 */
declare(strict_types=1);

$updates = [
    'Do I need an account to order?' =>
        'You do not need to set one up in advance. At checkout we ask for your Gmail address '
        . 'and email you a 6-digit code — that confirms the order is really yours and gives you '
        . 'somewhere to track it. An account is created for you at that point, and you can add '
        . 'a password afterwards from your dashboard so that next time you simply sign in.',

    'I did not receive my sign-in code.' =>
        'Check your Promotions tab and spam folder first — that is where it usually is. Codes '
        . 'expire after 10 minutes, so request a fresh one if it has been a while. Note that we '
        . 'only email a code the first time you sign in, and when you reset a forgotten password; '
        . 'the rest of the time you just enter your password. Still stuck? Message us on WhatsApp.',
];

$new = [
    ['Account', 'How does signing in work?',
     'The first time, enter your Gmail address and we email you a 6-digit code. Once you have '
     . 'entered it you choose a password, and your account is ready. After that you sign in with '
     . 'your email and that password — no more codes unless you need to reset it. We ask for the '
     . 'code on that first sign-in so that an account can only ever be created by someone who '
     . 'genuinely controls the email address.'],

    ['Account', 'I forgot my password.',
     'On the sign-in page, enter your Gmail address, then choose "Forgot your password?". We '
     . 'email you a 6-digit code, and once you have entered it you can pick a new password. Your '
     . 'orders, addresses and wishlist are all still there. We cannot tell you your old password '
     . 'because we never store it — only a one-way hash of it.'],

    ['Account', 'Do I have to have a password?',
     'If you checked out as a guest we created an account for you without one, and you can carry '
     . 'on using an emailed code every time. Setting a password just makes signing in faster. '
     . 'You can add one from your account dashboard whenever you like.'],
];

$changed = 0;

$upd = $conn->prepare('UPDATE faqs SET answer = ? WHERE question = ?');
foreach ($updates as $question => $answer) {
    $upd->bind_param('ss', $answer, $question);
    $upd->execute();
    $changed += $upd->affected_rows > 0 ? 1 : 0;
}

$find = $conn->prepare('SELECT id FROM faqs WHERE question = ?');
$sort = (int) $conn->query('SELECT COALESCE(MAX(sort_order), 0) FROM faqs')->fetch_row()[0];
$ins  = $conn->prepare(
    'INSERT INTO faqs (category, question, answer, sort_order, is_active) VALUES (?,?,?,?,1)'
);

foreach ($new as [$cat, $q, $a]) {
    $find->bind_param('s', $q);
    $find->execute();
    if ($find->get_result()->fetch_row()) {
        continue;                        // already present — re-runnable
    }
    $sort++;
    $ins->bind_param('sssi', $cat, $q, $a, $sort);
    $ins->execute();
    $changed++;
}

return $changed;
