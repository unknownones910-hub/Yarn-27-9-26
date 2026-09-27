<?php
/**
 * Email preferences.
 *
 * Transactional mail is shown but not switchable: we cannot ship an order and
 * refuse to tell the customer it shipped. Marketing is a real, honest toggle
 * with no dark pattern between the customer and "off".
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/account-nav.php';

$customer = require_login();
$cid      = (int) $customer['id'];

if (is_post()) {
    csrf_verify();

    $optIn = empty($_POST['marketing']) ? 0 : 1;
    db_exec('UPDATE customers SET marketing_opt_in = ? WHERE id = ?', [$optIn, $cid]);

    // Keep the newsletter list in step with the account setting.
    if ($optIn) {
        db_exec(
            'INSERT INTO newsletter_subscribers (email, source, status, confirmed_at)
             VALUES (?, "account", "confirmed", NOW())
             ON DUPLICATE KEY UPDATE status = "confirmed", confirmed_at = NOW()',
            [(string) $customer['email']]
        );
    } else {
        db_exec('UPDATE newsletter_subscribers SET status = "unsubscribed" WHERE email = ?',
                [(string) $customer['email']]);
    }

    flash($optIn ? 'You are subscribed. Thank you!' : 'Unsubscribed. You will still get order updates.',
          'success');
    redirect('account/notifications.php');
}

$customer  = db_row('SELECT * FROM customers WHERE id = ?', [$cid]) ?? $customer;
$pageTitle = 'Notifications';
$noindex   = true;

$transactional = [
    ['Order confirmation',  'Sent the moment your payment goes through, with your invoice attached.'],
    ['Dispatch & tracking', 'The courier name and tracking number when your parcel leaves us.'],
    ['Delivery updates',    'Out for delivery, and delivered.'],
    ['Cancellations & refunds', 'Whenever money moves back to you.'],
    ['Sign-in codes',       'The one-time code you use to sign in.'],
];

require_once YN_ROOT . '/includes/header.php';
?>

<div class="container section-sm">
  <h1 class="mb5">Notifications</h1>

  <div class="account-layout">
    <?php account_nav('notifications'); ?>

    <div class="min0">

      <div class="card mb5">
        <h2 style="font-family:var(--font-sans);font-size:1rem">Marketing email</h2>
        <p class="text-sm soft">New shades, free patterns and the occasional sale. Never more than
           twice a month, and never your data sold on.</p>

        <form method="post" action="<?= e(url('account/notifications.php')) ?>" class="mt4">
          <?= csrf_field() ?>
          <label class="check">
            <input type="checkbox" name="marketing" value="1"
                   <?= (int) $customer['marketing_opt_in'] === 1 ? 'checked' : '' ?>>
            <span>Yes, email me new shades and offers</span>
          </label>
          <button class="btn mt4" type="submit">Save preference</button>
        </form>

        <p class="text-xs muted mt4 mb0">
          Every marketing email has a one-click unsubscribe link too — you never have to
          come back here to opt out.
        </p>
      </div>

      <div class="card">
        <h2 style="font-family:var(--font-sans);font-size:1rem">Order emails</h2>
        <p class="text-sm soft">
          These are part of buying something, so they cannot be switched off. They only ever
          concern an order you placed.
        </p>
        <ul class="stack-sm mt4">
          <?php foreach ($transactional as [$title, $desc]): ?>
            <li class="row" style="gap:var(--s3);align-items:flex-start">
              <span style="color:var(--success);flex:none;line-height:0;margin-top:3px">
                <?= icon('check-circle', 16) ?></span>
              <span class="min0">
                <strong class="text-sm"><?= e($title) ?></strong><br>
                <span class="text-xs muted"><?= e($desc) ?></span>
              </span>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>

      <p class="text-sm muted mt5">
        How we handle your data is set out in our
        <a href="<?= e(url('privacy-policy')) ?>">privacy policy</a>.
      </p>
    </div>
  </div>
</div>

<?php require_once YN_ROOT . '/includes/footer.php'; ?>
