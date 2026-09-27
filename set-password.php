<?php
/**
 * Choose a password — the last step of a first sign-in or a reset.
 *
 * Reached only from verify.php, and only with a customer id that a code has
 * just proved ownership of. There is no session yet: signing in happens at the
 * bottom of this page, after the password exists. If we logged them in first,
 * "set a password" would be a step anyone could skip by typing another URL.
 *
 * The handoff expires in 15 minutes so a shared or abandoned browser cannot be
 * walked back into halfway through sign-up.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/mailer.php';

if (is_logged_in()) {
    redirect(after_login_url());
}

$customerId = (int) ($_SESSION['pw_setup_customer'] ?? 0);
$startedAt  = (int) ($_SESSION['pw_setup_at'] ?? 0);
$isNew      = !empty($_SESSION['pw_setup_new']);

if ($customerId === 0 || (time() - $startedAt) > 900) {
    unset($_SESSION['pw_setup_customer'], $_SESSION['pw_setup_at'],
          $_SESSION['pw_setup_new'], $_SESSION['pw_setup_remember']);
    flash('That link has expired. Please start again.', 'warn');
    redirect('login.php');
}

$customer = db_row('SELECT * FROM customers WHERE id = ? AND deleted_at IS NULL LIMIT 1',
                   [$customerId]);

if (!$customer || $customer['status'] === 'blocked') {
    unset($_SESSION['pw_setup_customer'], $_SESSION['pw_setup_at'],
          $_SESSION['pw_setup_new'], $_SESSION['pw_setup_remember']);
    flash('We could not complete that. Please contact us for help.', 'error');
    redirect('login.php');
}

$errors = [];
$name   = (string) ($customer['full_name'] ?? '');

// Ask for a name only when we genuinely do not have one. A returning customer
// setting their first password already told us years ago, and re-asking looks
// like we lost their details.
$needsName = mb_strlen(trim($name)) < 2;

// Three different people arrive here and they must not be told the same story.
//   signup  brand new — this is the last step of creating the account
//   add     account exists (guest checkout, or pre-password era) but has none
//   reset   account has a password and they have forgotten it
$mode = $isNew
    ? 'signup'
    : ($customer['password_hash'] === null ? 'add' : 'reset');

[$heading, $lede, $subject, $button] = match ($mode) {
    'signup' => ['Almost there',
                 'Your email is verified. Pick a password and your account is ready.',
                 'Creating an account for',
                 'Create my account'],
    'add'    => ['Set your password',
                 'Your email is verified. Choose a password and you can sign straight in next time.',
                 'Adding a password to',
                 'Save my password'],
    'reset'  => ['Choose a new password',
                 'Your email is verified. Pick a new password to finish.',
                 'Resetting the password for',
                 'Save new password'],
};

if (is_post()) {
    csrf_verify();

    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['confirm'] ?? '');
    if ($needsName) {
        $name = post_str('full_name', 120);
    }

    $problem = password_problem($password, $confirm);

    if ($problem !== '') {
        // password_problem() reports the mismatch too; show it on the field
        // the customer needs to look at.
        $errors[str_contains($problem, 'match') ? 'confirm' : 'password'] = $problem;
    } elseif ($needsName && mb_strlen($name) < 2) {
        $errors['full_name'] = 'Please tell us your name.';
    } else {
        customer_set_password($customerId, $password);

        if ($name !== '' && $name !== (string) $customer['full_name']) {
            db_exec('UPDATE customers SET full_name = ? WHERE id = ?', [$name, $customerId]);
        }

        // Tell them out of band. On a reset this is the only warning they get
        // if it was not them who asked.
        try {
            $signedNote = '<p style="margin:0 0 16px;color:#5D554C;">From now on, sign in with '
                . 'your Gmail address and this password. We will only email you a code again '
                . 'if you need to reset it.</p>';
            $notYou = '<p style="margin:0 0 16px;color:#5D554C;">Time: '
                . e(date('j M Y, g:i a')) . '</p>'
                . '<p style="margin:0;"><strong>If this was not you</strong>, reset it straight '
                . 'away from the sign-in page and <a href="' . e(abs_url('contact.php'))
                . '">tell us</a>.</p>';

            [$mailSubject, $mailHeading, $mailBody, $mailTemplate] = match ($mode) {
                'signup' => ['Your account is ready', 'Welcome to ' . setting('store_name'),
                    '<p style="margin:0 0 16px;">Your account is set up and you are signed in.</p>'
                    . $signedNote . email_button('Start browsing', abs_url('shop.php')),
                    'welcome'],
                'add'    => ['A password was added to your account', 'Password added',
                    '<p style="margin:0 0 16px;">A password was just added to your '
                    . e(setting('store_name')) . ' account.</p>' . $signedNote . $notYou,
                    'password_added'],
                'reset'  => ['Your password was changed', 'Password changed',
                    '<p style="margin:0 0 16px;">The password for your ' . e(setting('store_name'))
                    . ' account was just changed.</p>' . $notYou,
                    'password_changed'],
            };

            send_mail(
                (string) $customer['email'], $name,
                $mailSubject, email_layout($mailHeading, $mailBody), null, $mailTemplate
            );
        } catch (Throwable $e) {
            error_log('Password-set notice failed: ' . $e->getMessage());
        }

        $remember = !empty($_SESSION['pw_setup_remember']);
        unset($_SESSION['pw_setup_customer'], $_SESSION['pw_setup_at'],
              $_SESSION['pw_setup_new'], $_SESSION['pw_setup_remember']);

        login_customer($customerId, $remember);

        flash(match ($mode) {
            'signup' => 'Welcome! Your account is ready.',
            'add'    => 'Password saved. You can sign straight in next time.',
            'reset'  => 'Password updated.',
        }, 'success');
        redirect(after_login_url());
    }
}

$pageTitle = $heading;
$noindex   = true;

require_once YN_ROOT . '/includes/header.php';
?>

<div class="container section">
  <div style="width:min(460px,100%);margin-inline:auto">

    <div class="center mb6">
      <span class="brand-mark" style="width:52px;height:52px;margin-inline:auto">
        <?= icon($mode === 'signup' ? 'check-circle' : 'lock', 26) ?></span>
      <h1 class="mt4"><?= e($heading) ?></h1>
      <p class="soft mb0"><?= e($lede) ?></p>
    </div>

    <div class="card card-lift">
      <p class="text-sm soft">
        <?= e($subject) ?>
        <strong><?= e((string) $customer['email']) ?></strong>
      </p>

      <form method="post" action="<?= e(url('set-password.php')) ?>" class="mt4">
        <?= csrf_field() ?>

        <?php if ($needsName): ?>
          <div class="field <?= isset($errors['full_name']) ? 'has-error' : '' ?>">
            <label class="label" for="full_name">Your name <span class="req">*</span></label>
            <input class="input" id="full_name" name="full_name" required autofocus
                   autocomplete="name" value="<?= e($name) ?>"
                   <?= field_invalid($errors, 'full_name') ?>>
            <span class="help">So we know what to call you, and what to put on the parcel.</span>
            <?= field_error($errors, 'full_name') ?>
          </div>
        <?php endif; ?>

        <div class="field <?= isset($errors['password']) ? 'has-error' : '' ?>">
          <label class="label" for="password">
            <?= $mode === 'reset' ? 'New password' : 'Choose a password' ?> <span class="req">*</span></label>
          <input class="input" type="password" id="password" name="password" required
                 minlength="<?= YN_PW_MIN ?>" autocomplete="new-password"
                 <?= $needsName ? '' : 'autofocus' ?>
                 <?= field_invalid($errors, 'password') ?>>
          <span class="help">At least <?= YN_PW_MIN ?> characters. A short phrase you will
            remember beats a short scramble you will not.</span>
          <?= field_error($errors, 'password') ?>
        </div>

        <div class="field <?= isset($errors['confirm']) ? 'has-error' : '' ?>">
          <label class="label" for="confirm">Confirm password <span class="req">*</span></label>
          <input class="input" type="password" id="confirm" name="confirm" required
                 autocomplete="new-password" <?= field_invalid($errors, 'confirm') ?>>
          <?= field_error($errors, 'confirm') ?>
        </div>

        <button class="btn btn-block btn-lg" type="submit">
          <?= e($button) ?>
        </button>
      </form>
    </div>

    <p class="center mt5 text-xs muted">
      Your password is stored only as a one-way hash. Nobody here can read it —
      not even us.
    </p>
  </div>
</div>

<?php require_once YN_ROOT . '/includes/footer.php'; ?>
