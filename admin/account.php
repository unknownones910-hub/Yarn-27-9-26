<?php
/**
 * Your admin account — change your own password.
 *
 * Separate from users.php on purpose. users.php is about other people's
 * accounts; this one only ever touches the row you are signed in as, so there
 * is no id parameter to tamper with.
 *
 * Changing a password requires the current one. A stolen session should not be
 * enough to lock the real owner out of their own shop.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-bootstrap.php';
require_once YN_ROOT . '/includes/mailer.php';

$admin = admin_guard('staff');          // every admin may change their own password

$errors = [];

if (is_post()) {
    csrf_verify();

    $current = (string) ($_POST['current_password'] ?? '');
    $new     = (string) ($_POST['new_password'] ?? '');
    $confirm = (string) ($_POST['confirm_password'] ?? '');

    $row = db_row('SELECT id, email, name, password_hash FROM admin_users WHERE id = ?',
                  [(int) $admin['id']]);

    if (!rate_hit('adm:pw:' . (int) $admin['id'], 10, 3600)) {
        $errors['current_password'] = 'Too many attempts. Try again later.';
    } elseif (!$row || $row['password_hash'] === null
              || !password_verify($current, (string) $row['password_hash'])) {
        $errors['current_password'] = 'That is not your current password.';
    } elseif (strlen($new) < 12) {
        $errors['new_password'] = 'Use at least 12 characters.';
    } elseif (strlen($new) > 72) {
        // bcrypt stops reading at 72 bytes, so a longer password would also be
        // accepted in truncated form later. Refuse rather than silently trim.
        $errors['new_password'] = 'Use 72 characters or fewer.';
    } elseif ($new === $current) {
        $errors['new_password'] = 'That is the password you already have.';
    } elseif ($new !== $confirm) {
        $errors['confirm_password'] = 'The two passwords do not match.';
    } else {
        db_exec(
            'UPDATE admin_users
                SET password_hash = ?, password_set_at = NOW(), failed_logins = 0, locked_until = NULL
              WHERE id = ?',
            [password_hash($new, PASSWORD_DEFAULT), (int) $row['id']]
        );

        admin_log('admin_password_change', 'admin_user', (int) $row['id'],
                  'Password changed from ' . client_ip());

        // Tell the account holder out of band. If it was not them, this is the
        // only warning they get.
        try {
            send_mail(
                (string) $row['email'], (string) $row['name'],
                'Your admin password was changed',
                email_layout('Password changed',
                    '<p style="margin:0 0 16px;">The password for your '
                    . e(setting('store_name')) . ' admin account was just changed.</p>'
                    . '<p style="margin:0 0 16px;color:#5D554C;">Time: '
                    . e(date('j M Y, g:i a')) . '<br>From: ' . e(client_ip()) . '</p>'
                    . '<p style="margin:0;"><strong>If this was not you</strong>, your account '
                    . 'is compromised. Sign in and change it again immediately, and remove any '
                    . 'admin user you do not recognise.</p>'),
                null, 'admin_password_change'
            );
        } catch (Throwable $e) {
            error_log('Password-change notice failed: ' . $e->getMessage());
        }

        flash('Password changed.');
        redirect('admin/account.php');
    }
}

$me = db_row('SELECT email, name, role, password_set_at, last_login_at FROM admin_users WHERE id = ?',
             [(int) $admin['id']]);

$pageTitle    = 'Your account';
$adminSection = 'account';

require_once YN_ROOT . '/includes/admin-header.php';
?>

<div class="admin-head">
  <div class="min0">
    <h1>Your account</h1>
    <p>Signed in as <?= e((string) $me['email']) ?> · <?= e((string) $me['role']) ?></p>
  </div>
</div>

<div class="admin-cols">
  <div class="min0">
    <div class="panel">
      <h2>Change your password</h2>

      <form method="post" action="<?= e(url('admin/account.php')) ?>" class="mt4"
            style="max-width:420px">
        <?= csrf_field() ?>

        <div class="field <?= isset($errors['current_password']) ? 'has-error' : '' ?>">
          <label class="label" for="current_password">Current password</label>
          <input class="input" type="password" id="current_password" name="current_password"
                 required autocomplete="current-password"
                 <?= field_invalid($errors, 'current_password') ?>>
          <?= field_error($errors, 'current_password') ?>
        </div>

        <div class="field <?= isset($errors['new_password']) ? 'has-error' : '' ?>">
          <label class="label" for="new_password">New password</label>
          <input class="input" type="password" id="new_password" name="new_password"
                 required minlength="12" autocomplete="new-password"
                 <?= field_invalid($errors, 'new_password') ?>>
          <span class="help">At least 12 characters. A short phrase you will remember beats
            a short scramble you will not.</span>
          <?= field_error($errors, 'new_password') ?>
        </div>

        <div class="field <?= isset($errors['confirm_password']) ? 'has-error' : '' ?>">
          <label class="label" for="confirm_password">Confirm new password</label>
          <input class="input" type="password" id="confirm_password" name="confirm_password"
                 required autocomplete="new-password"
                 <?= field_invalid($errors, 'confirm_password') ?>>
          <?= field_error($errors, 'confirm_password') ?>
        </div>

        <button class="btn" type="submit">Change password</button>
      </form>
    </div>
  </div>

  <div class="min0">
    <div class="panel">
      <h2>How admin sign-in works</h2>
      <p class="text-sm">Four things are needed, and all four are checked every time:</p>
      <ul class="stack-sm text-sm mt3">
        <li>· An active row in <code>admin_users</code></li>
        <li>· Your address in the allowlist in <code>config/admin-config.php</code>,
            which is not editable from the web</li>
        <li>· This password</li>
        <li>· A 6-digit code emailed to your Gmail</li>
      </ul>
      <p class="text-sm muted mt4 mb0">
        Five wrong passwords locks the account for 15 minutes.
      </p>
    </div>

    <div class="panel">
      <h2>Details</h2>
      <table class="admin-table">
        <tbody>
          <tr><td class="muted">Name</td><td><?= e((string) $me['name']) ?></td></tr>
          <tr><td class="muted">Role</td><td><?= e((string) $me['role']) ?></td></tr>
          <tr><td class="muted">Password set</td>
              <td><?= $me['password_set_at']
                    ? e(fmt_date((string) $me['password_set_at'], 'j M Y')) : '—' ?></td></tr>
          <tr><td class="muted">Last sign-in</td>
              <td><?= $me['last_login_at']
                    ? e(fmt_date((string) $me['last_login_at'], 'j M Y, g:i a')) : '—' ?></td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once YN_ROOT . '/includes/admin-footer.php'; ?>
