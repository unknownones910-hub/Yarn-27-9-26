<?php
/**
 * Order history.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/account-nav.php';
require_once YN_ROOT . '/includes/order.php';
require_once YN_ROOT . '/includes/components/pagination.php';

$customer = require_login();
$cid      = (int) $customer['id'];

$filter = get_str('filter', 20);
$page   = max(1, get_int('page', 1));
$per    = 10;

$groups = [
    'active'    => ['paid', 'processing', 'packed', 'shipped', 'out_for_delivery', 'pending_payment'],
    'delivered' => ['delivered'],
    'cancelled' => ['cancelled', 'refunded', 'returned', 'failed'],
];

$where  = ['customer_id = ?'];
$params = [$cid];

if (isset($groups[$filter])) {
    $where[]  = 'status IN (' . db_in($groups[$filter]) . ')';
    foreach ($groups[$filter] as $s) {
        $params[] = $s;
    }
}

$whereSql = implode(' AND ', $where);
$total    = (int) db_val("SELECT COUNT(*) FROM orders WHERE {$whereSql}", $params);
$pages    = max(1, (int) ceil($total / $per));
$page     = min($page, $pages);
$offset   = ($page - 1) * $per;

$orders = db_all(
    "SELECT * FROM orders WHERE {$whereSql} ORDER BY placed_at DESC, id DESC
     LIMIT {$per} OFFSET {$offset}",
    $params
);

// One query for the thumbnails of every order on this page.
$itemsByOrder = [];
if ($orders) {
    $ids = array_map(static fn($o) => (int) $o['id'], $orders);
    foreach (db_all(
        'SELECT order_id, product_name, shade_name, color_hex, image, qty
         FROM order_items WHERE order_id IN (' . db_in($ids) . ') ORDER BY id',
        $ids
    ) as $it) {
        $itemsByOrder[(int) $it['order_id']][] = $it;
    }
}

$pageTitle = 'My orders';
$noindex   = true;

require_once YN_ROOT . '/includes/header.php';
?>

<div class="container section-sm">
  <h1 class="mb5">My orders</h1>

  <div class="account-layout">
    <?php account_nav('orders'); ?>

    <div class="min0">
      <div class="chips mb5">
        <?php foreach (['' => 'All', 'active' => 'Active', 'delivered' => 'Delivered',
                        'cancelled' => 'Cancelled'] as $key => $label): ?>
          <a class="chip <?= $filter === $key ? 'is-on' : '' ?>"
             href="<?= e(url('account/orders.php' . ($key !== '' ? '?filter=' . $key : ''))) ?>">
            <?= e($label) ?></a>
        <?php endforeach; ?>
      </div>

      <?php if (!$orders): ?>
        <div class="card center" style="padding-block:var(--s7)">
          <div class="empty__art" style="margin-bottom:var(--s4)"><?= icon('package', 36) ?></div>
          <h2 style="font-family:var(--font-sans);font-size:1.0625rem">
            <?= $filter !== '' ? 'Nothing in this group' : 'No orders yet' ?></h2>
          <p class="text-sm soft" style="margin-inline:auto;max-width:38ch">
            <?= $filter !== ''
                ? 'Try another filter, or view all your orders.'
                : 'When you order, it will show up here with tracking and your invoice.' ?></p>
          <a class="btn mt4" href="<?= e(url($filter !== '' ? 'account/orders.php' : 'shop.php')) ?>">
            <?= $filter !== '' ? 'View all orders' : 'Start shopping' ?></a>
        </div>

      <?php else: ?>
        <?php foreach ($orders as $o): ?>
          <?php
          [$label, $mod] = order_status_meta((string) $o['status']);
          $items  = $itemsByOrder[(int) $o['id']] ?? [];
          $count  = array_sum(array_map(static fn($i) => (int) $i['qty'], $items));
          $detail = url('account/order-detail.php?id=' . (int) $o['id']);
          ?>
          <article class="order-card">
            <div class="order-card__head">
              <div class="min0">
                <p class="text-sm mb1"><strong><a href="<?= e($detail) ?>"><?= e((string) $o['order_no']) ?></a></strong></p>
                <p class="text-xs muted mb0">
                  <?= e(fmt_date((string) $o['placed_at'])) ?> ·
                  <?= $count ?> item<?= $count === 1 ? '' : 's' ?> ·
                  <?= e(money((float) $o['grand_total'])) ?>
                </p>
              </div>
              <span class="pill pill-<?= e($mod) ?>" style="flex:none"><?= e($label) ?></span>
            </div>

            <div class="order-card__thumbs">
              <?php foreach (array_slice($items, 0, 6) as $it): ?>
                <img src="<?= e($it['image'] ? upload_url($it['image']) : yarn_ball_uri((string) $it['color_hex'])) ?>"
                     alt="<?= e($it['product_name']) ?>" width="52" height="64" loading="lazy"
                     title="<?= e($it['product_name'] . ' — ' . (string) $it['shade_name']) ?>">
              <?php endforeach; ?>
              <?php if (count($items) > 6): ?>
                <span class="order-card__more">+<?= count($items) - 6 ?></span>
              <?php endif; ?>
            </div>

            <div class="order-card__actions">
              <a class="btn btn-sm btn-soft" href="<?= e($detail) ?>">View details</a>

              <?php if ($o['payment_status'] === 'unpaid'
                        && !in_array($o['status'], ['cancelled', 'refunded'], true)): ?>
                <a class="btn btn-sm"
                   href="<?= e(url('pay.php?order=' . rawurlencode((string) $o['order_no']))) ?>">Pay now</a>
              <?php endif; ?>

              <?php if (in_array($o['status'], ['shipped', 'out_for_delivery'], true)): ?>
                <a class="btn btn-sm btn-soft"
                   href="<?= e(url('track-order.php?order=' . rawurlencode((string) $o['order_no']))) ?>">Track</a>
              <?php endif; ?>

              <?php if ($o['payment_status'] === 'paid'): ?>
                <a class="btn btn-sm btn-soft"
                   href="<?= e(url('invoice.php?order=' . rawurlencode((string) $o['order_no']))) ?>">Invoice</a>
              <?php endif; ?>

              <form method="post" action="<?= e(url('account/order-action.php')) ?>" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reorder">
                <input type="hidden" name="order_id" value="<?= (int) $o['id'] ?>">
                <button class="btn btn-sm btn-soft" type="submit">Buy again</button>
              </form>
            </div>
          </article>
        <?php endforeach; ?>

        <?php pagination($page, $pages); ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require_once YN_ROOT . '/includes/footer.php'; ?>
