<?php
/**
 * Set or change your password.
 *
 * Two shapes, depending on where the account came from:
 *
 *   has one already   ask for the current password, then the new one
 *   has none yet      (guest checkout, or an account from the passwordless
 *                     era) — just set one. The session already proves they
 *                     control the address; a code was emailed to reach here.
 *
 * Requiring the current password matters: a session left open on a shared
 * machine must not be enough to change the credential and lock the real owner
 * out of their own orders.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/account-nav.php';
require_once YN_ROOT . '/includes/mailer.php';

$customer = require_login();

$row   = db_row('SELECT * FROM customers WHERE id = ? LIMIT 1', [(int) $customer['id']]);
$hasPw = $row && $row['password_hash'] !== null;

$errors = [];

if (is_post()) {
    csrf_verify();

    $current  = (string) ($_POST['current_password'] ?? '');
    $password = (string) ($_POST['password'] ?? '');
    $confirm  = (string) ($_POST['confirm'] ?? '');

    if (!rate_hit('pw:change:' . (int) $customer['id'], 10, 3600)) {
        $errors['current_password'] = 'Too many attempts. Please try again later.';
    } elseif ($hasPw && !password_verify($current, (string) $row['password_hash'])) {
        $errors['current_password'] = 'That is not your current password.';
    } else {
        $problem = password_problem($password, $confirm);

        if ($problem !== '') {
            $errors[str_contains($problem, 'match') ? 'confirm' : 'password'] = $problem;
        } elseif ($hasPw && hash_equals($current, $password)) {
            $errors['password'] = 'That is the password you already have.';
        } else {
            customer_set_password((int) $customer['id'], $password);

            try {
                send_mail(
                    (string) $row['email'], (string) ($row['full_name'] ?? ''),
                    $hasPw ? 'Your password was changed' : 'A password was added to your account',
                    email_layout(
                        $hasPw ? 'Password changed' : 'Password added',
                        '<p style="margin:0 0 16px;">'
                        . ($hasPw
                            ? 'The password for your ' . e(setting('store_name')) . ' account was just changed.'
                            : 'A password was just added to your ' . e(setting('store_name'))
                              . ' account. You can now sign in with it instead of waiting for a code.')
                        . '</p>'
                        . '<p style="margin:0 0 16px;color:#5D554C;">Time: '
                        . e(date('j M Y, g:i a')) . '</p>'
                        . '<p style="margin:0;"><strong>If this was not you</strong>, reset your '
                        . 'password from the sign-in page straight away and '
                        . '<a href="' . e(abs_url('contact.php')) . '">tell us</a>.</p>'
                    ),
                    null, 'password_changed'
                );
            } catch (Throwable $e) {
                error_log('Password-change notice failed: ' . $e->getMessage());
            }

            flash($hasPw ? 'Password updated.' : 'Password set. You can now sign in with it.',
                  'success');
            redirect('account/password.php');
        }
    }
}

$pageTitle = 'Password';
$noindex   = true;

require_once YN_ROOT . '/includes/header.php';
?>

<div class="container section-sm">
  <h1 class="mb5"><?= $hasPw ? 'Change your password' : 'Set a password' ?></h1>

  <div class="account-layout">
    <?php account_nav('password'); ?>

    <div class="min0">
      <p class="soft mb5" style="max-width:52ch">
        <?= $hasPw
            ? 'You will stay signed in on this device.'
            : 'Your account was created from a checkout, so it does not have one yet. '
              . 'Adding a password means you can sign in straight away instead of waiting '
              . 'for a code each time.' ?>
      </p>

      <div class="card" style="max-width:460px">
        <form method="post" action="<?= e(url('account/password.php')) ?>">
          <?= csrf_field() ?>

          <?php if ($hasPw): ?>
            <div class="field <?= isset($errors['current_password']) ? 'has-error' : '' ?>">
              <label class="label" for="current_password">Current password <span class="req">*</span></label>
              <input class="input" type="password" id="current_password" name="current_password"
                     required autocomplete="current-password"
                     <?= field_invalid($errors, 'current_password') ?>>
              <?= field_error($errors, 'current_password') ?>
            </div>
          <?php endif; ?>

          <div class="field <?= isset($errors['password']) ? 'has-error' : '' ?>">
            <label class="label" for="password">
              <?= $hasPw ? 'New password' : 'Choose a password' ?> <span class="req">*</span></label>
            <input class="input" type="password" id="password" name="password" required
                   minlength="<?= YN_PW_MIN ?>" autocomplete="new-password"
                   <?= $hasPw ? '' : 'autofocus' ?> <?= field_invalid($errors, 'password') ?>>
            <span class="help">At least <?= YN_PW_MIN ?> characters.</span>
            <?= field_error($errors, 'password') ?>
          </div>

          <div class="field <?= isset($errors['confirm']) ? 'has-error' : '' ?>">
            <label class="label" for="confirm">Confirm password <span class="req">*</span></label>
            <input class="input" type="password" id="confirm" name="confirm" required
                   autocomplete="new-password" <?= field_invalid($errors, 'confirm') ?>>
            <?= field_error($errors, 'confirm') ?>
          </div>

          <button class="btn" type="submit">
            <?= $hasPw ? 'Change password' : 'Set password' ?>
          </button>
        </form>
      </div>

      <p class="text-sm muted mt5">
        We store only a one-way hash of your password — it cannot be read back by anyone here,
        which is why a reset gives you a new one rather than telling you the old one.
        <?php if ($row['password_set_at']): ?>
          Last changed <?= e(fmt_date((string) $row['password_set_at'], 'j M Y')) ?>.
        <?php endif; ?>
      </p>
    </div>
  </div>
</div>

<?php require_once YN_ROOT . '/includes/footer.php'; ?>
