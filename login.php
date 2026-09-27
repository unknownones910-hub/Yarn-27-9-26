<?php
/**
 * Sign in.
 *
 * Two steps, because the second one depends on the first:
 *
 *   1. Gmail address.
 *   2. If that account already has a password  -> ask for it.
 *      If it does not (new visitor, or an account from the old passwordless
 *      flow)                                   -> email a code, then
 *                                                 set-password.php.
 *
 * Verifying the address BEFORE a password exists is the important ordering. It
 * means an account can only ever be created against a Gmail the person actually
 * controls — nobody can register in someone else's name and sit on it.
 *
 * This does reveal whether an address has an account here, which is the normal
 * trade every consumer shop makes: the alternative is emailing a code on every
 * single sign-in, and that costs carts. What it never reveals is whether a
 * *password* was right — that failure is always the same sentence.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/otp.php';

if (is_logged_in()) {
    redirect(after_login_url());
}

/**
 * Which step to show.
 *
 * The password step lives at ?step=pw, NOT purely in the session. Deriving it
 * from the session alone meant that once you had typed an address, every later
 * visit to /login.php showed the password box for that account — go to the
 * homepage, come back, and there is no way to enter a different email.
 *
 * A plain /login.php is therefore always "start again", and it clears whatever
 * was pending. Only an explicit ?step=pw continues.
 */
$wantsPasswordStep = ($_GET['step'] ?? '') === 'pw';

if (!$wantsPasswordStep && !is_post()) {
    // NOT login_error. The redirect that carries an error back to this form is
    // itself a plain GET of /login.php, so clearing it here would wipe the
    // message before the read three lines below ever sees it — the form would
    // simply reappear blank, looking like the submit did nothing.
    unset($_SESSION['login_email'], $_SESSION['login_email_key'],
          $_SESSION['login_has_password'], $_SESSION['login_remember']);
}

// Read once and clear: an error belongs to the request that follows the POST,
// and must not survive into the one after that.
$error = (string) ($_SESSION['login_error'] ?? '');
unset($_SESSION['login_error']);

$email = $_SESSION['login_email'] ?? '';
$stage = ($wantsPasswordStep && $email !== '' && !empty($_SESSION['login_has_password']))
    ? 'password'
    : 'email';

// ?step=pw with nothing pending (session expired, or a bookmarked URL) is not
// an error, it is just a stale link. Send them to the beginning.
if ($wantsPasswordStep && $stage !== 'password' && !is_post()) {
    redirect('login.php');
}

/**
 * Finish a POST by redirecting, never by rendering.
 *
 * Without this, the password step was the direct response to the email POST, so
 * refreshing that page re-submitted the form — the browser's "confirm
 * resubmission" prompt, a spent CSRF token, and an error the customer did
 * nothing to deserve.
 */
$backTo = static function (string $where, string $err = ''): void {
    if ($err !== '') {
        $_SESSION['login_error'] = $err;
    }
    redirect($where);
};

/** Send a code and hand off to verify.php, which returns to set-password.php. */
$startEmailVerification = static function (string $emailKey, string $typed, bool $remember): void {
    $res = otp_issue($emailKey, $typed, 'login');
    if (!$res['ok']) {
        return;                                   // caller reports the failure
    }
    $_SESSION['otp_email_key'] = $emailKey;
    $_SESSION['otp_email']     = $typed;
    $_SESSION['otp_purpose']   = 'login';
    $_SESSION['otp_remember']  = $remember;
    $_SESSION['otp_next']      = 'set_password';
    if (!empty($res['dev_code'])) {
        $_SESSION['otp_dev_code'] = $res['dev_code'];
    }
    redirect('verify.php');
};

if (is_post()) {
    csrf_verify();
    $action   = post_str('action', 20);
    $remember = isset($_POST['remember']);

    // ---- start again with a different address -------------------------
    if ($action === 'restart') {
        unset($_SESSION['login_email'], $_SESSION['login_email_key'],
              $_SESSION['login_has_password']);
        redirect('login.php');
    }

    // ---- step 1: the address ------------------------------------------
    if ($action === 'email') {
        $email = post_str('email', 190);

        if (!honeypot_ok()) {
            $backTo('login.php', 'Something went wrong. Please try again.');
        } elseif (!rate_hit('login:ip:' . client_ip(), 20, 3600)) {
            $backTo('login.php', 'Too many attempts. Please try again later.');
        } else {
            $emailKey = normalize_gmail($email);

            if ($emailKey === null) {
                $backTo('login.php', str_contains($email, '@')
                      && !str_ends_with(strtolower(trim($email)), '@gmail.com')
                    ? 'We currently accept Gmail addresses only. Please use your @gmail.com '
                      . 'address — or message us on WhatsApp and we will place the order for you.'
                    : 'That does not look like a valid Gmail address.');
            } else {
                $row = db_row(
                    'SELECT id, status, password_hash FROM customers
                      WHERE email_key = ? AND deleted_at IS NULL LIMIT 1',
                    [$emailKey]
                );

                if ($row && $row['status'] === 'blocked') {
                    $backTo('login.php', 'We could not sign you in. Please contact us for help.');
                } elseif ($row && $row['password_hash'] !== null) {
                    // Known account with a password — ask for it.
                    $_SESSION['login_email']        = trim($email);
                    $_SESSION['login_email_key']    = $emailKey;
                    $_SESSION['login_has_password'] = true;
                    $_SESSION['login_remember']     = $remember;
                    $backTo('login.php?step=pw');
                } else {
                    // Brand new, or an account from before passwords existed.
                    $startEmailVerification($emailKey, trim($email), $remember);
                    $backTo('login.php', 'Could not send a code right now. Please try again.');
                }
            }
        }
    }

    // ---- step 2: the password -----------------------------------------
    if ($action === 'password') {
        $emailKey = $_SESSION['login_email_key'] ?? '';
        $email    = $_SESSION['login_email'] ?? '';
        $password = (string) ($_POST['password'] ?? '');
        $stage    = 'password';

        // One sentence for every failure. "Wrong password" versus "no such
        // account" is exactly the distinction an attacker is fishing for.
        $generic = 'That password is not right.';

        if ($emailKey === '') {
            redirect('login.php');
        } elseif (!rate_hit('login:pw:' . $emailKey, 10, 900)) {
            $backTo('login.php?step=pw', 'Too many attempts. Please wait a few minutes and try again.');
        } else {
            $row = db_row(
                'SELECT * FROM customers WHERE email_key = ? AND deleted_at IS NULL LIMIT 1',
                [$emailKey]
            );

            $locked = customer_is_locked($row);

            // Always compare against something, so a missing row does not
            // return measurably faster than a wrong password.
            $dummy = '$2y$10$usesomesillystringfoeediiaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
            $ok    = password_verify($password, (string) ($row['password_hash'] ?? $dummy))
                     && $row && $row['password_hash'] !== null
                     && $row['status'] !== 'blocked' && !$locked;

            if (!$ok) {
                if ($row && !$locked) {
                    customer_note_failed_login((int) $row['id'], (int) $row['failed_logins']);
                }
                $backTo('login.php?step=pw', $locked
                    ? 'Too many wrong passwords. Try again in 15 minutes, or reset it below.'
                    : $generic);
            } else {
                $remember = !empty($_SESSION['login_remember']);
                db_exec('UPDATE customers SET failed_logins = 0 WHERE id = ?', [(int) $row['id']]);

                unset($_SESSION['login_email'], $_SESSION['login_email_key'],
                      $_SESSION['login_has_password'], $_SESSION['login_remember']);

                login_customer((int) $row['id'], $remember);
                rate_clear('login:pw:' . $emailKey);

                flash('Welcome back' . (($n = customer_display_name($row)) !== '' ? ', ' . $n : '') . '.',
                      'success');
                redirect(after_login_url());
            }
        }
    }

    // ---- forgotten password -------------------------------------------
    if ($action === 'forgot') {
        $emailKey = $_SESSION['login_email_key'] ?? '';
        $email    = $_SESSION['login_email'] ?? '';
        $stage    = 'password';

        if ($emailKey === '') {
            redirect('login.php');
        }
        $startEmailVerification($emailKey, $email, !empty($_SESSION['login_remember']));
        $backTo('login.php?step=pw', 'Could not send a code right now. Please try again.');
    }
}

$pageTitle = 'Sign in';
$pageDesc  = 'Sign in to ' . setting('store_name') . ' with your Gmail address.';
$noindex   = true;
$waLink    = whatsapp_link('Hi! I need help signing in.');

require_once YN_ROOT . '/includes/header.php';
?>

<div class="container section">
  <div style="width:min(460px,100%);margin-inline:auto">

    <div class="center mb6">
      <span class="brand-mark" style="width:52px;height:52px;margin-inline:auto"><?= icon('yarn', 28) ?></span>
      <h1 class="mt4">Sign in</h1>
      <p class="soft">
        <?= $stage === 'password'
            ? 'Enter your password to continue.'
            : 'New here? We will set your account up in a moment.' ?>
      </p>
    </div>

    <div class="card card-lift">
      <?php if ($error !== ''): ?>
        <div class="alert alert-error mb4" role="alert">
          <?= icon('alert', 18) ?><div><?= e($error) ?></div>
        </div>
      <?php endif; ?>

      <?php if ($stage === 'email'): ?>
        <form method="post" action="<?= e(url('login.php')) ?>">
          <?= csrf_field() ?>
          <?= honeypot_field() ?>
          <input type="hidden" name="action" value="email">

          <div class="field">
            <label class="label" for="email">Your Gmail address <span class="req">*</span></label>
            <input class="input" type="email" id="email" name="email"
                   value="<?= e($email) ?>" required autofocus
                   autocomplete="username" inputmode="email"
                   placeholder="you@gmail.com" spellcheck="false">
            <span class="help">We accept Gmail addresses only. Dots and +tags are ignored,
              so <em>you.name+shop@gmail.com</em> is the same account as
              <em>youname@gmail.com</em>.</span>
          </div>

          <label class="check">
            <input type="checkbox" name="remember" value="1" checked>
            <span>Keep me signed in on this device for 30 days</span>
          </label>

          <button class="btn btn-block btn-lg mt4" type="submit">Continue</button>
        </form>

      <?php else: ?>
        <p class="text-sm soft">
          Signing in as <strong><?= e($email) ?></strong>
        </p>

        <form method="post" action="<?= e(url('login.php')) ?>" class="mt4">
          <?= csrf_field() ?>
          <input type="hidden" name="action" value="password">

          <div class="field">
            <label class="label" for="password">Password <span class="req">*</span></label>
            <input class="input" type="password" id="password" name="password"
                   required autofocus autocomplete="current-password">
          </div>

          <button class="btn btn-block btn-lg" type="submit">Sign in</button>
        </form>

        <div class="row gap4 mt4" style="justify-content:space-between">
          <form method="post" action="<?= e(url('login.php')) ?>" data-no-loading>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="forgot">
            <button class="btn-link text-sm" type="submit">Forgot your password?</button>
          </form>
          <form method="post" action="<?= e(url('login.php')) ?>" data-no-loading>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="restart">
            <button class="btn-link text-sm" type="submit">Use a different address</button>
          </form>
        </div>
      <?php endif; ?>

      <p class="text-xs muted center mt5 mb0">
        By continuing you agree to our
        <a href="<?= e(url('terms-and-conditions')) ?>">Terms</a> and
        <a href="<?= e(url('privacy-policy')) ?>">Privacy Policy</a>.
      </p>
    </div>

    <div class="row gap4 mt5" style="justify-content:center">
      <span class="text-xs muted row" style="gap:6px"><?= icon('lock', 14) ?> Password never stored in plain text</span>
      <span class="text-xs muted row" style="gap:6px"><?= icon('shield', 14) ?> Email verified on sign-up</span>
    </div>

    <?php if ($waLink !== ''): ?>
      <p class="center mt5 text-sm">
        Trouble signing in?
        <a href="<?= eurl($waLink) ?>" target="_blank" rel="noopener">Message us on WhatsApp</a>
      </p>
    <?php endif; ?>
  </div>
</div>

<?php require_once YN_ROOT . '/includes/footer.php'; ?>
