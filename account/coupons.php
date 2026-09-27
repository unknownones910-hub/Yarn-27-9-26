<?php
/**
 * Coupons available to this customer, and what they have already used.
 *
 * Only codes they can genuinely redeem are listed — a first-order coupon
 * disappears once they have ordered, rather than teasing them with something
 * that will be refused at the cart.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/account-nav.php';
require_once YN_ROOT . '/includes/coupon.php';

$customer = require_login();
$cid      = (int) $customer['id'];

$paidOrders = (int) db_val(
    'SELECT COUNT(*) FROM orders WHERE customer_id = ? AND payment_status = "paid"', [$cid]
);

$all = db_all(
    'SELECT * FROM coupons
     WHERE is_active = 1
       AND (starts_at IS NULL OR starts_at <= NOW())
       AND (ends_at   IS NULL OR ends_at   >= NOW())
     ORDER BY min_order ASC, id DESC'
);

$available = [];
foreach ($all as $c) {
    if ($c['usage_limit'] !== null && (int) $c['used_count'] >= (int) $c['usage_limit']) {
        continue;
    }
    if ((int) $c['first_order_only'] === 1 && $paidOrders > 0) {
        continue;
    }
    $usedByMe = (int) db_val(
        'SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id = ? AND customer_id = ?',
        [(int) $c['id'], $cid]
    );
    if ((int) $c['per_customer_limit'] > 0 && $usedByMe >= (int) $c['per_customer_limit']) {
        continue;
    }
    $available[] = $c;
}

$used = db_all(
    'SELECT cr.*, c.code, c.type, c.value, o.order_no
     FROM coupon_redemptions cr
     JOIN coupons c ON c.id = cr.coupon_id
     LEFT JOIN orders o ON o.id = cr.order_id
     WHERE cr.customer_id = ?
     ORDER BY cr.created_at DESC',
    [$cid]
);

$pageTitle = 'My coupons';
$noindex   = true;

require_once YN_ROOT . '/includes/header.php';
?>

<div class="container section-sm">
  <h1 class="mb5">Coupons</h1>

  <div class="account-layout">
    <?php account_nav('coupons'); ?>

    <div class="min0">

      <?php if ($available): ?>
        <h2 style="font-family:var(--font-sans);font-size:1rem" class="mb3">Ready to use</h2>
        <div class="grid grid-2 mb6">
          <?php foreach ($available as $c): ?>
            <div class="card" style="border-style:dashed;border-color:var(--clay)">
              <div class="row row-between mb2">
                <strong style="font-family:var(--font-serif);font-size:1.25rem;letter-spacing:.04em">
                  <?= e($c['code']) ?></strong>
                <span class="pill pill-ok" style="flex:none"><?= e(coupon_label($c)) ?></span>
              </div>

              <?php if (!empty($c['description'])): ?>
                <p class="text-sm soft mb2"><?= e((string) $c['description']) ?></p>
              <?php endif; ?>

              <ul class="text-xs muted stack-sm">
                <?php if ((float) $c['min_order'] > 0): ?>
                  <li>· Minimum order <?= e(money((float) $c['min_order'])) ?></li>
                <?php endif; ?>
                <?php if ($c['max_discount'] !== null && (float) $c['max_discount'] > 0): ?>
                  <li>· Up to <?= e(money((float) $c['max_discount'])) ?> off</li>
                <?php endif; ?>
                <?php if ((int) $c['first_order_only'] === 1): ?>
                  <li>· First order only</li>
                <?php endif; ?>
                <?php if (!empty($c['ends_at'])): ?>
                  <li>· Expires <?= e(fmt_date((string) $c['ends_at'])) ?></li>
                <?php endif; ?>
              </ul>

              <a class="btn btn-sm btn-block mt4" href="<?= e(url('shop.php')) ?>">Shop &amp; apply at checkout</a>
            </div>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="card center mb6" style="padding-block:var(--s7)">
          <div class="empty__art" style="margin-bottom:var(--s4)"><?= icon('tag', 36) ?></div>
          <h2 style="font-family:var(--font-sans);font-size:1.0625rem">No coupons right now</h2>
          <p class="text-sm soft" style="margin-inline:auto;max-width:40ch">
            We email offers to subscribers a couple of times a month.
            <a href="<?= e(url('account/notifications.php')) ?>">Turn those on</a> if you would like them.</p>
        </div>
      <?php endif; ?>

      <?php if ($used): ?>
        <h2 style="font-family:var(--font-sans);font-size:1rem" class="mb3">Already used</h2>
        <div class="scroll-x card" style="padding:0">
          <table class="table">
            <thead>
              <tr><th>Code</th><th>Order</th><th>Date</th><th class="num">Saved</th></tr>
            </thead>
            <tbody>
              <?php foreach ($used as $u): ?>
                <tr>
                  <td><strong><?= e((string) $u['code']) ?></strong></td>
                  <td><?= $u['order_no'] ? e((string) $u['order_no']) : '—' ?></td>
                  <td><?= e(fmt_date((string) $u['created_at'])) ?></td>
                  <td class="num"><?= e(money((float) $u['amount'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <p class="text-sm muted mt4">
          Total saved:
          <strong><?= e(money(array_sum(array_map(static fn($u) => (float) $u['amount'], $used)))) ?></strong>
        </p>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require_once YN_ROOT . '/includes/footer.php'; ?>
