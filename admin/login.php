<?php
/**
 * Admin sign-in — password, then an emailed code.
 *
 * Four locks, all required:
 *   1. an active row in `admin_users`
 *   2. the normalized Gmail in $ADMIN['allowlist'] (config, not web-editable)
 *   3. the account password
 *   4. a 6-digit code emailed to that Gmail
 *
 * The password is checked BEFORE any code is issued, so this cannot be used to
 * make us send mail to an address on demand. But every failure — unknown
 * address, wrong password, no password set, locked out — returns the identical
 * message and runs a dummy hash comparison, so response content and timing
 * leak nothing about which admin accounts exist.
 *
 * Five wrong passwords locks the account for 15 minutes. The lock is on the
 * account, not the IP, because an attacker rotates IPs and the owner does not.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-bootstrap.php';
require_once YN_ROOT . '/includes/otp.php';
require YN_ROOT . '/config/admin-config.php';

if (current_admin()) {
    redirect('admin/');
}

// Optional network lock applies to the login screen too.
$ips = $ADMIN['ip_allowlist'] ?? [];
if ($ips && !in_array(client_ip(), $ips, true)) {
    http_response_code(403);
    exit('Admin access is restricted to approved networks.');
}

$error   = '';
$stage   = !empty($_SESSION['adm_email_key']) ? 'code' : 'email';
$devCode = $_SESSION['adm_dev_code'] ?? '';

if (is_post()) {
    csrf_verify();
    $action = post_str('action', 20);

    if ($action === 'send') {
        $typed    = post_str('email', 190);
        $password = (string) ($_POST['password'] ?? '');
        $key      = normalize_gmail($typed);

        // One message for every failure below. Saying "wrong password" would
        // confirm the address is an admin account.
        $generic = 'That email and password combination is not right.';

        // Throttle on IP regardless of whether the address is an admin one,
        // so this cannot be used to enumerate admin accounts.
        if (!rate_hit('adm:ip:' . client_ip(), 10, 3600)) {
            $error = 'Too many attempts. Try again later.';
        } elseif ($key === null || $password === '') {
            $error = $generic;
        } else {
            $row = db_row(
                'SELECT id, email, password_hash, locked_until, failed_logins
                   FROM admin_users WHERE email_key = ? AND is_active = 1 LIMIT 1',
                [$key]
            );

            $allowlisted = in_array($key, $ADMIN['allowlist'] ?? [], true);
            $locked      = $row && $row['locked_until'] !== null
                           && strtotime((string) $row['locked_until']) > time();

            // Always run a comparison, even with no account, so a missing row
            // does not return measurably faster than a wrong password.
            $dummy = '$2y$10$usesomesillystringfoeediiaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
            $ok    = password_verify($password, (string) ($row['password_hash'] ?? $dummy))
                     && $row && $row['password_hash'] !== null && $allowlisted && !$locked;

            if (!$ok) {
                $error = $generic;

                if ($row && !$locked) {
                    // Count the miss and lock the account after five.
                    $fails = (int) $row['failed_logins'] + 1;
                    if ($fails >= 5) {
                        db_exec('UPDATE admin_users SET failed_logins = 0,
                                        locked_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE)
                                  WHERE id = ?', [(int) $row['id']]);
                        admin_log('admin_login_locked', 'admin_user', (int) $row['id'],
                                  'Locked for 15 minutes after 5 failed passwords from ' . client_ip());
                    } else {
                        db_exec('UPDATE admin_users SET failed_logins = ? WHERE id = ?',
                                [$fails, (int) $row['id']]);
                    }
                }

                error_log('Admin sign-in failed for ' . $key . ' from ' . client_ip()
                    . ($locked ? ' (locked)' : ''));
            } else {
                $res = otp_issue($key, (string) $row['email'], 'admin_login');
                if ($res['ok']) {
                    db_exec('UPDATE admin_users SET failed_logins = 0 WHERE id = ?',
                            [(int) $row['id']]);

                    // The password is now proven. Remember that for this session
                    // only — the code stage re-checks everything before signing in.
                    $_SESSION['adm_email_key'] = $key;
                    $_SESSION['adm_email']     = $typed;
                    $_SESSION['adm_pw_ok']     = true;
                    $_SESSION['adm_pw_at']     = time();

                    if (!empty($res['dev_code'])) {
                        $_SESSION['adm_dev_code'] = $res['dev_code'];
                        $devCode = $res['dev_code'];
                    }
                    $stage = 'code';
                } else {
                    $error = $res['error'] ?? 'Could not send a code.';
                }
            }
        }
    }

    if ($action === 'verify') {
        $key = $_SESSION['adm_email_key'] ?? '';

        // The password stage must have happened, in this session, recently.
        // Without this check a valid code alone would be enough, and the
        // password would be decorative.
        $pwOk = !empty($_SESSION['adm_pw_ok'])
             && (time() - (int) ($_SESSION['adm_pw_at'] ?? 0)) < 900;

        if ($key === '' || !$pwOk) {
            unset($_SESSION['adm_email_key'], $_SESSION['adm_email'],
                  $_SESSION['adm_dev_code'], $_SESSION['adm_pw_ok'], $_SESSION['adm_pw_at']);
            $error = 'Please start again.';
            $stage = 'email';
        } else {
            $res = otp_verify($key, post_str('code', 10), 'admin_login');

            if (!$res['ok']) {
                $error = 'That code is not right.';
                $stage = 'code';
            } else {
                $row = db_row('SELECT * FROM admin_users WHERE email_key = ? AND is_active = 1 LIMIT 1',
                              [$key]);

                if (!$row || !in_array($key, $ADMIN['allowlist'] ?? [], true)
                    || $row['password_hash'] === null) {
                    $error = 'That code is not right.';
                    $stage = 'code';
                } else {
                    unset($_SESSION['adm_email_key'], $_SESSION['adm_email'],
                          $_SESSION['adm_dev_code'], $_SESSION['adm_pw_ok'], $_SESSION['adm_pw_at']);
                    login_admin((int) $row['id']);
                    admin_log('admin_login', 'admin_user', (int) $row['id'], 'Signed in');
                    rate_clear('adm:ip:' . client_ip());

                    $next = $_SESSION['after_admin_login'] ?? url('admin/');
                    unset($_SESSION['after_admin_login']);
                    if (!is_string($next) || !str_starts_with($next, '/') || str_starts_with($next, '//')) {
                        $next = url('admin/');
                    }
                    redirect($next);
                }
            }
        }
    }

    if ($action === 'restart') {
        unset($_SESSION['adm_email_key'], $_SESSION['adm_email'],
              $_SESSION['adm_dev_code'], $_SESSION['adm_pw_ok'], $_SESSION['adm_pw_at']);
        redirect('admin/login.php');
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Admin sign-in</title>
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
<script defer src="<?= e(asset('js/main.js')) ?>"></script>
</head>
<body style="background:var(--ink);display:grid;place-items:center;min-height:100vh;padding:var(--s4)">

<div style="width:min(420px,100%)">
  <div class="center mb5">
    <span class="brand-mark" style="width:52px;height:52px;margin-inline:auto;background:var(--clay);color:#fff">
      <?= icon('lock', 26) ?></span>
    <h1 class="mt4" style="color:#F6F1E9">Admin sign-in</h1>
    <p style="color:#9C948A" class="mb0"><?= e(setting('store_name', 'The Yarn Nest')) ?></p>
  </div>

  <div class="card">
    <?php if ($error !== ''): ?>
      <div class="alert alert-error mb4" role="alert"><?= icon('alert', 18) ?><div><?= e($error) ?></div></div>
    <?php endif; ?>

    <?php if ($stage === 'email'): ?>
      <form method="post" action="<?= e(url('admin/login.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="send">
        <div class="field">
          <label class="label" for="email">Admin Gmail address</label>
          <input class="input" type="email" id="email" name="email" required autofocus
                 autocomplete="username" placeholder="you@gmail.com">
        </div>
        <div class="field">
          <label class="label" for="password">Password</label>
          <input class="input" type="password" id="password" name="password" required
                 autocomplete="current-password">
        </div>
        <button class="btn btn-block btn-lg" type="submit">Continue</button>
        <p class="text-xs muted center mt3 mb0">
          We will email a 6-digit code to confirm it is you.
        </p>
      </form>

    <?php else: ?>
      <?php if ($devCode !== '' && is_local_dev()): ?>
        <div class="alert alert-warn mb4"><?= icon('info', 18) ?>
          <div><strong>Local development</strong> — code:
            <strong style="font:700 18px/1 'Courier New',monospace;letter-spacing:3px;color:var(--clay)">
              <?= e($devCode) ?></strong></div></div>
      <?php endif; ?>

      <p class="text-sm soft">
        Password accepted. We have emailed a 6-digit code to
        <strong><?= e(mask_email((string) ($_SESSION['adm_email'] ?? ''))) ?></strong>.
        It expires in 10 minutes.
      </p>

      <form method="post" action="<?= e(url('admin/login.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="verify">
        <input type="hidden" name="code" data-otp-value>
        <div class="otp mt4" data-otp="autosubmit">
          <?php for ($i = 0; $i < 6; $i++): ?><input type="text" <?= $i === 0 ? 'autofocus' : '' ?>><?php endfor; ?>
        </div>
        <button class="btn btn-block btn-lg mt5" type="submit">Sign in</button>
      </form>

      <form method="post" action="<?= e(url('admin/login.php')) ?>" class="center mt3" data-no-loading>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="restart">
        <button class="btn-link" type="submit">Use a different address</button>
      </form>
    <?php endif; ?>
  </div>

  <p class="center mt5"><a href="<?= e(url('')) ?>" style="color:#9C948A;font-size:var(--fs-sm)">
    ← Back to the shop</a></p>
</div>
</body>
</html>
