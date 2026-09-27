<?php
/**
 * Sign in — step 2: the 6-digit code.
 *
 * Reached only with a pending address in the session, so the code cannot be
 * brute-forced against an arbitrary email from this page.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/otp.php';

if (is_logged_in()) {
    redirect(after_login_url());
}

$emailKey = $_SESSION['otp_email_key'] ?? '';
$emailRaw = $_SESSION['otp_email']     ?? '';
$purpose  = $_SESSION['otp_purpose']   ?? 'login';

if ($emailKey === '' || $emailRaw === '') {
    flash('Please enter your email address to get a code.', 'warn');
    redirect('login.php');
}

$error   = '';
$notice  = '';
$devCode = $_SESSION['otp_dev_code'] ?? '';

if (is_post()) {
    csrf_verify();

    $action = post_str('action', 20);

    // ---- resend ------------------------------------------------------
    if ($action === 'resend') {
        $result = otp_issue($emailKey, $emailRaw, $purpose);
        if ($result['ok']) {
            $notice = 'A new code is on its way.';
            if (!empty($result['dev_code'])) {
                $_SESSION['otp_dev_code'] = $result['dev_code'];
                $devCode = $result['dev_code'];
            }
        } else {
            $error = $result['error'] ?? 'Could not send a new code right now.';
        }

    // ---- verify ------------------------------------------------------
    } else {
        $code   = post_str('code', 10);
        $result = otp_verify($emailKey, $code, $purpose);

        if ($result['ok']) {
            // Ask BEFORE the upsert, because the upsert creates the row and
            // then everything looks like a returning customer. This is the only
            // moment we can still tell a genuine sign-up from an existing
            // account that simply predates passwords.
            $existedBefore = (bool) db_val(
                'SELECT id FROM customers WHERE email_key = ? LIMIT 1', [$emailKey]
            );

            $customer = customer_upsert($emailKey, $emailRaw);

            if (!$customer || ($customer['status'] ?? '') === 'blocked') {
                $error = 'We could not sign you in. Please contact us for help.';
            } else {
                $remember = !empty($_SESSION['otp_remember']);
                rate_clear('otp:req:' . $emailKey);

                // The address is now proven. Where that leads depends on why we
                // sent the code: a first sign-in or a reset still owes us a
                // password, and until it is set we must NOT establish a session
                // — otherwise "set a password" would be a step you could skip
                // by typing the URL of any other page.
                if (($_SESSION['otp_next'] ?? '') === 'set_password'
                    || $customer['password_hash'] === null) {

                    $_SESSION['pw_setup_customer'] = (int) $customer['id'];
                    $_SESSION['pw_setup_at']       = time();
                    $_SESSION['pw_setup_new']      = !$existedBefore;
                    $_SESSION['pw_setup_remember'] = $remember;

                    unset(
                        $_SESSION['otp_email_key'], $_SESSION['otp_email'],
                        $_SESSION['otp_purpose'], $_SESSION['otp_dev_code'],
                        $_SESSION['otp_remember'], $_SESSION['otp_next']
                    );
                    redirect('set-password.php');
                }

                // Clear the pending-OTP state before establishing the session.
                unset(
                    $_SESSION['otp_email_key'], $_SESSION['otp_email'],
                    $_SESSION['otp_purpose'], $_SESSION['otp_dev_code'],
                    $_SESSION['otp_remember'], $_SESSION['otp_next']
                );

                login_customer((int) $customer['id'], $remember);

                flash('Signed in as ' . $customer['email'], 'success');
                redirect(after_login_url());
            }
        } else {
            $error = $result['error'] ?? 'That code is not right.';
        }
    }
}

$secondsLeft = otp_seconds_remaining($emailKey, $purpose);
$resendWait  = otp_resend_wait($emailKey, $purpose);

$pageTitle = 'Enter your code';
$noindex   = true;
$waLink    = whatsapp_link('Hi! I am not receiving my sign-in code.');

require_once YN_ROOT . '/includes/header.php';
?>

<div class="container section">
  <div style="width:min(460px,100%);margin-inline:auto">

    <div class="center mb6">
      <span class="brand-mark" style="width:52px;height:52px;margin-inline:auto"><?= icon('mail', 26) ?></span>
      <h1 class="mt4">Check your email</h1>
      <p class="soft">
        We sent a 6-digit code to <strong><?= e(mask_email($emailRaw)) ?></strong>
        <br><a class="text-sm" href="<?= e(url('login.php')) ?>">Use a different address</a>
      </p>
    </div>

    <div class="card card-lift">

      <?php if ($devCode !== '' && is_local_dev()): ?>
        <div class="alert alert-warn mb4">
          <?= icon('info', 18) ?>
          <div>
            <strong>Local development</strong> — email is not sent on this machine.<br>
            Your code is
            <strong style="font:700 20px/1 'Courier New',monospace;letter-spacing:4px;color:var(--clay)">
              <?= e($devCode) ?></strong>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($notice !== ''): ?>
        <div class="alert alert-success mb4" role="status">
          <?= icon('check-circle', 18) ?><div><?= e($notice) ?></div>
        </div>
      <?php endif; ?>

      <?php if ($error !== ''): ?>
        <div class="alert alert-error mb4" role="alert">
          <?= icon('alert', 18) ?><div><?= e($error) ?></div>
        </div>
      <?php endif; ?>

      <form method="post" action="<?= e(url('verify.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="code" data-otp-value>

        <p class="label center">Enter your 6-digit code</p>

        <div class="otp <?= $error !== '' ? 'has-error' : '' ?>" data-otp="autosubmit">
          <?php for ($i = 0; $i < 6; $i++): ?>
            <input type="text" <?= $i === 0 ? 'autofocus' : '' ?>>
          <?php endfor; ?>
        </div>

        <p class="help center mt4">
          <?php if ($secondsLeft > 0): ?>
            Expires in <span data-countdown="<?= $secondsLeft ?>"
                             data-expired-text="expired">--:--</span>
          <?php else: ?>
            Your code has expired — request a new one below.
          <?php endif; ?>
        </p>

        <button class="btn btn-block btn-lg mt4" type="submit">Verify &amp; sign in</button>
      </form>

      <form method="post" action="<?= e(url('verify.php')) ?>" class="center mt4" data-no-loading>
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="resend">
        <button class="btn btn-quiet" type="submit"
                data-resend data-seconds="<?= (int) $resendWait ?>" data-label="Resend code">
          Resend code
        </button>
      </form>

      <p class="text-xs muted center mt4 mb0">
        Not in your inbox? Check the <strong>Promotions</strong> tab and your spam folder.
      </p>
    </div>

    <?php if ($waLink !== ''): ?>
      <p class="center mt5 text-sm">
        Still stuck? <a href="<?= eurl($waLink) ?>" target="_blank" rel="noopener">Message us on WhatsApp</a>
      </p>
    <?php endif; ?>
  </div>
</div>

<?php require_once YN_ROOT . '/includes/footer.php'; ?>
