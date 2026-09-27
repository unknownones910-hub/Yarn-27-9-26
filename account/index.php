<?php
/**
 * Account overview.
 *
 * The active order is the biggest thing on the page, because checking on a
 * delivery is what people actually sign in to do.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/account-nav.php';
require_once YN_ROOT . '/includes/order.php';
require_once YN_ROOT . '/includes/catalog.php';
require_once YN_ROOT . '/includes/components/product-card.php';

$customer = require_login();
$cid      = (int) $customer['id'];

$active = db_all(
    'SELECT * FROM orders
     WHERE customer_id = ?
       AND status IN ("pending_payment","paid","processing","packed","shipped","out_for_delivery")
     ORDER BY placed_at DESC LIMIT 3',
    [$cid]
);

$recent = db_all(
    'SELECT * FROM orders WHERE customer_id = ? ORDER BY placed_at DESC LIMIT 3',
    [$cid]
);

$wishCount   = (int) db_val('SELECT COUNT(*) FROM wishlists WHERE customer_id = ?', [$cid]);
$reviewCount = (int) db_val('SELECT COUNT(*) FROM reviews WHERE customer_id = ?', [$cid]);
$toReview    = account_reviewable_items($cid, 4);

// Profile completeness — a real nudge, not a vanity meter: each missing field
// costs us something concrete at delivery time.
$profileBits = [
    'name'    => !empty($customer['full_name']),
    'phone'   => !empty($customer['phone']),
    'address' => (int) db_val('SELECT COUNT(*) FROM customer_addresses WHERE customer_id = ?', [$cid]) > 0,
    'crafts'  => !empty($customer['craft_prefs']),
];
$done    = count(array_filter($profileBits));
$total   = count($profileBits);
$pct     = (int) round($done / $total * 100);

$name      = customer_display_name($customer);
$pageTitle = 'My account';
$noindex   = true;

require_once YN_ROOT . '/includes/header.php';
?>

<div class="container section-sm">
  <header class="mb5">
    <h1 class="mb1"><?= $name !== '' ? 'Hello, ' . e($name) : 'Hello there' ?> 👋</h1>
    <p class="soft mb0">
      <?= e($customer['email']) ?> · member since <?= e(fmt_date($customer['created_at'], 'F Y')) ?>
    </p>
  </header>

  <div class="account-layout">
    <?php account_nav(''); ?>

    <div class="min0">

      <?php if ($active): ?>
        <?php foreach ($active as $o): ?>
          <?php [$label, $mod] = order_status_meta((string) $o['status']);
                $items = order_items((int) $o['id']); ?>
          <div class="card card-lift mb5" style="border-color:var(--clay-tint)">
            <div class="row row-between mb4">
              <div class="min0">
                <p class="text-xs muted mb1">Order in progress</p>
                <h2 style="font-family:var(--font-sans);font-size:1.0625rem;margin:0">
                  <?= e((string) $o['order_no']) ?></h2>
              </div>
              <span class="pill pill-<?= e($mod) ?>" style="flex:none"><?= e($label) ?></span>
            </div>

            <div class="order-card__thumbs">
              <?php foreach (array_slice($items, 0, 5) as $it): ?>
                <img src="<?= e($it['image'] ? upload_url($it['image']) : yarn_ball_uri((string) $it['color_hex'])) ?>"
                     alt="" width="52" height="64" loading="lazy">
              <?php endforeach; ?>
              <?php if (count($items) > 5): ?>
                <span class="order-card__more">+<?= count($items) - 5 ?></span>
              <?php endif; ?>
            </div>

            <?php if ($o['payment_status'] === 'unpaid'): ?>
              <a class="btn btn-block"
                 href="<?= e(url('pay.php?order=' . rawurlencode((string) $o['order_no']))) ?>">
                Complete payment · <?= e(money((float) $o['grand_total'])) ?></a>
            <?php else: ?>
              <div class="order-card__actions">
                <a class="btn btn-sm" href="<?= e(url('account/order-detail.php?id=' . (int) $o['id'])) ?>">
                  View order</a>
                <a class="btn btn-sm btn-soft"
                   href="<?= e(url('track-order.php?order=' . rawurlencode((string) $o['order_no']))) ?>">
                  Track</a>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>

      <div class="stat-grid mb5">
        <div class="stat">
          <p class="stat__label">Orders</p>
          <p class="stat__value"><?= (int) $customer['orders_count'] ?></p>
        </div>
        <div class="stat">
          <p class="stat__label">Total spent</p>
          <p class="stat__value"><?= e(money((float) $customer['total_spent'])) ?></p>
        </div>
        <div class="stat">
          <p class="stat__label">Wishlist</p>
          <p class="stat__value"><?= $wishCount ?></p>
        </div>
        <div class="stat">
          <p class="stat__label">Reviews</p>
          <p class="stat__value"><?= $reviewCount ?></p>
        </div>
      </div>

      <?php if ($pct < 100): ?>
        <div class="card mb5">
          <div class="row row-between mb3">
            <h2 style="font-family:var(--font-sans);font-size:1rem;margin:0">Finish your profile</h2>
            <span class="text-sm muted"><?= $done ?>/<?= $total ?></span>
          </div>
          <div class="ship-bar__track mb4"><div class="ship-bar__fill" style="width:<?= $pct ?>%"></div></div>
          <ul class="stack-sm text-sm">
            <?php if (!$profileBits['name']): ?>
              <li>· <a href="<?= e(url('account/profile.php')) ?>">Add your name</a>
                  <span class="muted">so parcels are addressed properly</span></li>
            <?php endif; ?>
            <?php if (!$profileBits['phone']): ?>
              <li>· <a href="<?= e(url('account/profile.php')) ?>">Add a phone number</a>
                  <span class="muted">couriers call before delivery</span></li>
            <?php endif; ?>
            <?php if (!$profileBits['address']): ?>
              <li>· <a href="<?= e(url('account/addresses.php')) ?>">Save a delivery address</a>
                  <span class="muted">makes checkout one tap</span></li>
            <?php endif; ?>
            <?php if (!$profileBits['crafts']): ?>
              <li>· <a href="<?= e(url('account/profile.php')) ?>">Tell us what you make</a>
                  <span class="muted">we stop showing you the wrong yarn</span></li>
            <?php endif; ?>
          </ul>
        </div>
      <?php endif; ?>

      <?php if ($toReview): ?>
        <div class="card mb5">
          <h2 style="font-family:var(--font-sans);font-size:1rem">How did these work out?</h2>
          <p class="text-sm soft">A short review helps the next person choose.</p>
          <div class="mt3">
            <?php foreach ($toReview as $r): ?>
              <div class="review-line">
                <img src="<?= e($r['image'] ? upload_url($r['image']) : yarn_ball_uri((string) $r['color_hex'])) ?>"
                     alt="" width="44" height="55" loading="lazy">
                <div class="min0">
                  <p class="text-sm mb0 truncate"><?= e($r['product_name']) ?></p>
                  <p class="text-xs muted mb0"><?= e((string) $r['shade_name']) ?></p>
                </div>
                <a class="btn btn-sm btn-soft"
                   href="<?= e(url('account/reviews.php?product=' . (int) $r['product_id']
                                   . '&order=' . (int) $r['order_id'])) ?>">Review</a>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($recent): ?>
        <div class="row row-between mb3">
          <h2 style="font-family:var(--font-sans);font-size:1rem;margin:0">Recent orders</h2>
          <a class="text-sm" href="<?= e(url('account/orders.php')) ?>">View all</a>
        </div>
        <?php foreach ($recent as $o): ?>
          <?php [$label, $mod] = order_status_meta((string) $o['status']); ?>
          <a class="review-line" style="text-decoration:none"
             href="<?= e(url('account/order-detail.php?id=' . (int) $o['id'])) ?>">
            <div class="min0" style="flex:1">
              <p class="text-sm mb0"><strong><?= e((string) $o['order_no']) ?></strong></p>
              <p class="text-xs muted mb0"><?= e(fmt_date((string) $o['placed_at'])) ?></p>
            </div>
            <span class="pill pill-<?= e($mod) ?>"><?= e($label) ?></span>
            <strong class="text-sm"><?= e(money((float) $o['grand_total'])) ?></strong>
          </a>
        <?php endforeach; ?>
      <?php else: ?>
        <div class="card center" style="padding-block:var(--s7)">
          <div class="empty__art" style="margin-bottom:var(--s4)"><?= icon('package', 36) ?></div>
          <h2 style="font-family:var(--font-sans);font-size:1.0625rem">No orders yet</h2>
          <p class="text-sm soft" style="margin-inline:auto;max-width:38ch">
            When you order, it will show up here with tracking and your invoice.</p>
          <a class="btn mt4" href="<?= e(url('shop.php')) ?>">Start shopping</a>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require_once YN_ROOT . '/includes/footer.php'; ?>
