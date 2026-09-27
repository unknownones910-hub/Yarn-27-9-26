<?php
/**
 * Close your account.
 *
 * A DPDP Act 2023 obligation, not a courtesy: the customer must be able to do
 * this themselves, without emailing support and waiting.
 *
 * What actually happens:
 *   · the profile is anonymised and soft-deleted, so they can never be
 *     identified from it again
 *   · every session, remember-me token, cart and wishlist is destroyed
 *   · order and invoice rows are KEPT, because tax law and the consumer
 *     protection rules require a seller to retain them — but the personal
 *     fields on them are replaced with a redaction marker
 *
 * Confirmed with an OTP, because an unattended session must not be able to
 * erase someone's account.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/account-nav.php';
require_once YN_ROOT . '/includes/otp.php';

$customer = require_login();
$cid      = (int) $customer['id'];

$error   = '';
$stage   = !empty($_SESSION['del_otp_sent']) ? 'confirm' : 'start';
$devCode = $_SESSION['del_dev_code'] ?? '';

$openOrders = (int) db_val(
    'SELECT COUNT(*) FROM orders
     WHERE customer_id = ?
       AND status IN ("pending_payment","paid","processing","packed","shipped","out_for_delivery")',
    [$cid]
);

if (is_post()) {
    csrf_verify();
    $action = post_str('action', 20);

    if ($action === 'send_code') {
        if ($openOrders > 0) {
            $error = 'You have an order still on its way. We cannot close the account until it '
                   . 'is delivered — otherwise we lose the ability to help if something goes wrong.';
        } else {
            $res = otp_issue((string) $customer['email_key'], (string) $customer['email'], 'email_change');
            if ($res['ok']) {
                $_SESSION['del_otp_sent'] = 1;
                if (!empty($res['dev_code'])) {
                    $_SESSION['del_dev_code'] = $res['dev_code'];
                    $devCode = $res['dev_code'];
                }
                $stage = 'confirm';
            } else {
                $error = $res['error'] ?? 'Could not send a confirmation code.';
            }
        }
    }

    if ($action === 'confirm') {
        if ($openOrders > 0) {
            $error = 'You still have an order in progress.';
        } elseif (post_str('understand') !== '1') {
            $error = 'Please tick the box to confirm you understand this cannot be undone.';
            $stage = 'confirm';
        } else {
            $res = otp_verify((string) $customer['email_key'], post_str('code', 10), 'email_change');

            if (!$res['ok']) {
                $error = $res['error'] ?? 'That code is not right.';
                $stage = 'confirm';
            } else {
                $email = (string) $customer['email'];

                try {
                    db_tx(static function () use ($cid) {
                        $marker = 'deleted-' . $cid . '@removed.invalid';

                        // Keep the order rows (legally required) but strip the
                        // personal data off them.
                        db_exec(
                            'UPDATE orders
                             SET email = ?, phone = "0000000000", ship_name = "[deleted]",
                                 ship_phone = "0000000000", ship_line1 = "[deleted]",
                                 ship_line2 = NULL, ship_landmark = NULL,
                                 customer_note = NULL, ip = NULL, user_agent = NULL
                             WHERE customer_id = ?',
                            [$marker, $cid]
                        );

                        db_exec('DELETE FROM customer_addresses WHERE customer_id = ?', [$cid]);
                        db_exec('DELETE FROM customer_remember_tokens WHERE customer_id = ?', [$cid]);
                        db_exec('DELETE FROM wishlists WHERE customer_id = ?', [$cid]);
                        db_exec('DELETE FROM cart_items WHERE cart_id IN
                                 (SELECT id FROM carts WHERE customer_id = ?)', [$cid]);
                        db_exec('DELETE FROM carts WHERE customer_id = ?', [$cid]);

                        // Reviews stay (they are useful to other shoppers) but
                        // stop being attributable to a person.
                        db_exec('UPDATE reviews SET customer_id = NULL, author_name = "A customer"
                                 WHERE customer_id = ?', [$cid]);

                        db_exec('UPDATE support_tickets SET status = "closed" WHERE customer_id = ?', [$cid]);

                        db_exec(
                            'UPDATE customers
                             SET email = ?, email_key = ?, full_name = NULL, phone = NULL,
                                 birthday = NULL, craft_prefs = NULL, skill_level = NULL,
                                 marketing_opt_in = 0, status = "blocked", deleted_at = NOW()
                             WHERE id = ?',
                            [$marker, $marker, $cid]
                        );
                    });

                    db_exec('UPDATE newsletter_subscribers SET status = "unsubscribed" WHERE email = ?',
                            [$email]);

                    logout_customer();
                    session_start();
                    session_regenerate_id(true);
                    flash('Your account has been closed. Thank you for shopping with us.', 'success');
                    redirect('');

                } catch (Throwable $e) {
                    error_log('Account deletion failed for ' . $cid . ': ' . $e->getMessage());
                    $error = 'We could not close the account just now. Please contact us and we will do it by hand.';
                    $stage = 'confirm';
                }
            }
        }
    }

    if ($action === 'cancel') {
        unset($_SESSION['del_otp_sent'], $_SESSION['del_dev_code']);
        redirect('account/profile.php');
    }
}

$pageTitle = 'Delete my account';
$noindex   = true;

require_once YN_ROOT . '/includes/header.php';
?>

<div class="container section-sm">
  <h1 class="mb5">Delete my account</h1>

  <div class="account-layout">
    <?php account_nav('profile'); ?>

    <div class="min0">
      <?php if ($error !== ''): ?>
        <div class="alert alert-error mb4" role="alert"><?= icon('alert', 18) ?><div><?= e($error) ?></div></div>
      <?php endif; ?>

      <div class="danger-zone">
        <h2 style="font-family:var(--font-sans);font-size:1.0625rem;margin-top:0">
          This cannot be undone</h2>

        <p class="text-sm mb4">Here is exactly what happens when you confirm:</p>

        <div class="grid grid-2" style="gap:var(--s4)">
          <div>
            <p class="text-sm mb2"><strong>Deleted for good</strong></p>
            <ul class="text-sm stack-sm">
              <li>· Your name, phone number and birthday</li>
              <li>· Every saved delivery address</li>
              <li>· Your wishlist and cart</li>
              <li>· Craft preferences</li>
              <li>· Sign-in access to this account</li>
            </ul>
          </div>
          <div>
            <p class="text-sm mb2"><strong>Kept, with your details removed</strong></p>
            <ul class="text-sm stack-sm">
              <li>· Order and invoice records</li>
              <li>· Reviews you posted, shown as “A customer”</li>
            </ul>
            <p class="text-xs muted mt3 mb0">
              Indian tax and consumer-protection rules require a seller to keep invoice
              records. We strip your name, address and phone number from them.
            </p>
          </div>
        </div>

        <?php if ($openOrders > 0): ?>
          <div class="alert alert-warn mt5">
            <?= icon('truck', 18) ?>
            <div>
              You have <strong><?= $openOrders ?></strong>
              order<?= $openOrders === 1 ? '' : 's' ?> still in progress.
              We cannot close the account until
              <?= $openOrders === 1 ? 'it has' : 'they have' ?> been delivered — otherwise we
              lose the ability to help if something goes wrong.
            </div>
          </div>
          <a class="btn btn-soft mt4" href="<?= e(url('account/orders.php')) ?>">View my orders</a>

        <?php elseif ($stage === 'start'): ?>
          <form method="post" action="<?= e(url('account/delete.php')) ?>" class="mt5">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="send_code">
            <p class="text-sm mb4">
              We will email a confirmation code to
              <strong><?= e(mask_email((string) $customer['email'])) ?></strong> first.
            </p>
            <div class="row">
              <button class="btn btn-danger" type="submit">Email me a confirmation code</button>
              <a class="btn btn-soft" href="<?= e(url('account/profile.php')) ?>">Keep my account</a>
            </div>
          </form>

        <?php else: ?>
          <?php if ($devCode !== '' && is_local_dev()): ?>
            <div class="alert alert-warn mt4"><?= icon('info', 18) ?>
              <div><strong>Local development</strong> — code:
                <strong style="font:700 18px/1 'Courier New',monospace;letter-spacing:3px">
                  <?= e($devCode) ?></strong></div></div>
          <?php endif; ?>

          <form method="post" action="<?= e(url('account/delete.php')) ?>" class="mt5">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="confirm">
            <input type="hidden" name="code" data-otp-value>

            <p class="label">Enter the 6-digit code we emailed you</p>
            <div class="otp" data-otp>
              <?php for ($i = 0; $i < 6; $i++): ?><input type="text" <?= $i === 0 ? 'autofocus' : '' ?>><?php endfor; ?>
            </div>

            <label class="check mt5">
              <input type="checkbox" name="understand" value="1" required>
              <span>I understand my account and personal data will be permanently deleted.</span>
            </label>

            <div class="row mt4">
              <button class="btn btn-danger" type="submit">Delete my account permanently</button>
              <button class="btn btn-soft" type="submit" name="action" value="cancel" formnovalidate>
                Keep my account</button>
            </div>
          </form>
        <?php endif; ?>
      </div>

      <p class="text-sm muted mt5">
        Prefer to talk to someone first? <a href="<?= e(url('contact.php')) ?>">Contact us</a> —
        we would rather fix a problem than lose you.
      </p>
    </div>
  </div>
</div>

<?php require_once YN_ROOT . '/includes/footer.php'; ?>
