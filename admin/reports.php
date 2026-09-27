<?php
/**
 * Reports.
 *
 * Only figures the owner can act on: what sold, what did not, where the money
 * went and who is buying again.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-bootstrap.php';
$admin = admin_guard('manager');

$days  = max(1, min(365, get_int('days', 30)));
$since = 'DATE_SUB(CURDATE(), INTERVAL ' . $days . ' DAY)';
$paid  = "o.payment_status = 'paid' AND o.placed_at >= {$since}";

$byMonth = db_all(
    'SELECT DATE_FORMAT(placed_at, "%Y-%m") m, COUNT(*) orders,
            COALESCE(SUM(grand_total),0) revenue, COALESCE(SUM(discount_total),0) discounts,
            COALESCE(SUM(shipping_total),0) shipping
     FROM orders WHERE payment_status = "paid"
     GROUP BY DATE_FORMAT(placed_at, "%Y-%m") ORDER BY m DESC LIMIT 12'
);

$topProducts = db_all(
    "SELECT oi.product_name, oi.product_id, SUM(oi.qty) units, SUM(oi.line_total) revenue,
            COUNT(DISTINCT oi.order_id) orders
     FROM order_items oi JOIN orders o ON o.id = oi.order_id
     WHERE {$paid} GROUP BY oi.product_id, oi.product_name
     ORDER BY revenue DESC LIMIT 20"
);

$topShades = db_all(
    "SELECT oi.shade_name, oi.color_hex, SUM(oi.qty) units
     FROM order_items oi JOIN orders o ON o.id = oi.order_id
     WHERE {$paid} GROUP BY oi.shade_name, oi.color_hex ORDER BY units DESC LIMIT 12"
);

$neverSold = db_all(
    'SELECT p.id, p.name, p.price, p.created_at,
            (SELECT COALESCE(SUM(v.stock),0) FROM product_variants v WHERE v.product_id = p.id) stock
     FROM products p
     WHERE p.is_active = 1
       AND NOT EXISTS (SELECT 1 FROM order_items oi WHERE oi.product_id = p.id)
     ORDER BY p.created_at LIMIT 20'
);

$coupons = db_all(
    'SELECT c.code, COUNT(r.id) uses, COALESCE(SUM(r.amount),0) given
     FROM coupons c LEFT JOIN coupon_redemptions r ON r.coupon_id = c.id
     GROUP BY c.id, c.code HAVING uses > 0 ORDER BY given DESC LIMIT 15'
);

$states = db_all(
    "SELECT o.ship_state state, COUNT(*) orders, COALESCE(SUM(o.grand_total),0) revenue
     FROM orders o WHERE {$paid} GROUP BY o.ship_state ORDER BY revenue DESC LIMIT 15"
);

$repeat = db_row(
    'SELECT SUM(orders_count = 1) once, SUM(orders_count > 1) repeated
     FROM customers WHERE deleted_at IS NULL AND orders_count > 0'
) ?: [];

$abandoned = (int) db_val(
    'SELECT COUNT(*) FROM carts c
     WHERE c.status = "active" AND c.updated_at < (NOW() - INTERVAL 1 DAY)
       AND EXISTS (SELECT 1 FROM cart_items ci WHERE ci.cart_id = c.id)'
);

$funnel = [
    'carts'  => (int) db_val('SELECT COUNT(DISTINCT cart_id) FROM cart_items'),
    'orders' => (int) db_val('SELECT COUNT(*) FROM orders'),
    'paid'   => (int) db_val('SELECT COUNT(*) FROM orders WHERE payment_status = "paid"'),
];

$pageTitle    = 'Reports';
$adminSection = 'reports';

require_once YN_ROOT . '/includes/admin-header.php';
?>

<div class="admin-head">
  <div class="min0"><h1>Reports</h1><p>Last <?= $days ?> days, unless stated otherwise.</p></div>
  <div class="chips">
    <?php foreach ([7, 30, 90, 365] as $d): ?>
      <a class="chip <?= $days === $d ? 'is-on' : '' ?>"
         href="<?= e(url('admin/reports.php?days=' . $d)) ?>"><?= $d ?> days</a>
    <?php endforeach; ?>
  </div>
</div>

<div class="kpi-grid mb5">
  <div class="kpi"><p class="kpi__label">Repeat customers</p>
    <p class="kpi__value"><?= (int) ($repeat['repeated'] ?? 0) ?></p>
    <p class="kpi__sub">vs <?= (int) ($repeat['once'] ?? 0) ?> one-time</p></div>
  <div class="kpi"><p class="kpi__label">Orders placed</p>
    <p class="kpi__value"><?= number_format($funnel['orders']) ?></p>
    <p class="kpi__sub"><?= number_format($funnel['paid']) ?> paid</p></div>
  <div class="kpi<?= $abandoned > 0 ? ' kpi--warn' : '' ?>">
    <p class="kpi__label">Abandoned carts</p>
    <p class="kpi__value"><?= $abandoned ?></p>
    <p class="kpi__sub">idle over 24h with items</p></div>
  <div class="kpi"><p class="kpi__label">Payment completion</p>
    <p class="kpi__value"><?= $funnel['orders'] > 0
        ? round($funnel['paid'] / $funnel['orders'] * 100) : 0 ?>%</p>
    <p class="kpi__sub">of orders reach payment</p></div>
</div>

<div class="panel panel--flush">
  <h2>Monthly performance</h2>
  <div class="scroll-x">
    <table class="admin-table" style="min-width:560px">
      <thead><tr><th>Month</th><th class="num">Orders</th><th class="num">Revenue</th>
        <th class="num">Discounts</th><th class="num">Delivery</th><th class="num">Avg order</th></tr></thead>
      <tbody>
        <?php foreach ($byMonth as $m): ?>
          <tr>
            <td><?= e(date('F Y', strtotime($m['m'] . '-01'))) ?></td>
            <td class="num"><?= (int) $m['orders'] ?></td>
            <td class="num"><strong><?= e(money((float) $m['revenue'])) ?></strong></td>
            <td class="num" style="color:var(--sage)">−<?= e(money((float) $m['discounts'])) ?></td>
            <td class="num"><?= e(money((float) $m['shipping'])) ?></td>
            <td class="num"><?= e(money((int) $m['orders'] > 0
                  ? (float) $m['revenue'] / (int) $m['orders'] : 0)) ?></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$byMonth): ?>
          <tr><td colspan="6" class="center muted">No paid orders yet.</td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="admin-cols">
  <div class="min0">
    <div class="panel panel--flush">
      <h2>Best sellers</h2>
      <div class="scroll-x">
        <table class="admin-table" style="min-width:460px">
          <thead><tr><th>Product</th><th class="num">Orders</th>
            <th class="num">Balls</th><th class="num">Revenue</th></tr></thead>
          <tbody>
            <?php foreach ($topProducts as $t): ?>
              <tr>
                <td class="truncate" style="max-width:220px">
                  <?php if ($t['product_id']): ?>
                    <a href="<?= e(url('admin/product-edit.php?id=' . (int) $t['product_id'])) ?>">
                      <?= e((string) $t['product_name']) ?></a>
                  <?php else: ?><?= e((string) $t['product_name']) ?><?php endif; ?>
                </td>
                <td class="num"><?= (int) $t['orders'] ?></td>
                <td class="num"><?= (int) $t['units'] ?></td>
                <td class="num"><strong><?= e(money((float) $t['revenue'])) ?></strong></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$topProducts): ?>
              <tr><td colspan="4" class="center muted">Nothing sold in this period.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="panel panel--flush">
      <h2>Never sold</h2>
      <p class="text-sm muted" style="padding:0 var(--s5)">
        Live products that have never appeared on an order. Usually a photo or description problem,
        not a product problem.
      </p>
      <div class="scroll-x">
        <table class="admin-table" style="min-width:420px">
          <thead><tr><th>Product</th><th class="num">Price</th>
            <th class="num">Stock</th><th>Added</th></tr></thead>
          <tbody>
            <?php foreach ($neverSold as $n): ?>
              <tr>
                <td class="truncate" style="max-width:220px">
                  <a href="<?= e(url('admin/product-edit.php?id=' . (int) $n['id'])) ?>">
                    <?= e((string) $n['name']) ?></a></td>
                <td class="num"><?= e(money((float) $n['price'])) ?></td>
                <td class="num"><?= (int) $n['stock'] ?></td>
                <td class="text-xs muted"><?= e(fmt_date((string) $n['created_at'], 'j M y')) ?></td>
              </tr>
            <?php endforeach; ?>
            <?php if (!$neverSold): ?>
              <tr><td colspan="4" class="center muted">Everything has sold at least once.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>

    <div class="panel panel--flush">
      <h2>Where orders go</h2>
      <div class="scroll-x">
        <table class="admin-table" style="min-width:380px">
          <thead><tr><th>State</th><th class="num">Orders</th><th class="num">Revenue</th></tr></thead>
          <tbody>
            <?php foreach ($states as $s): ?>
              <tr><td><?= e((string) $s['state']) ?></td>
                <td class="num"><?= (int) $s['orders'] ?></td>
                <td class="num"><?= e(money((float) $s['revenue'])) ?></td></tr>
            <?php endforeach; ?>
            <?php if (!$states): ?>
              <tr><td colspan="3" class="center muted">No data yet.</td></tr>
            <?php endif; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="admin-cols__side">
    <div class="panel">
      <h2>Popular shades</h2>
      <?php foreach ($topShades as $s): ?>
        <div class="review-line">
          <span style="width:20px;height:20px;flex:none;border-radius:50%;
                       background:<?= e((string) $s['color_hex']) ?>;
                       box-shadow:0 0 0 1px var(--sand-deep)"></span>
          <div class="min0"><p class="text-sm mb0 truncate"><?= e((string) $s['shade_name']) ?></p></div>
          <strong class="text-xs"><?= (int) $s['units'] ?></strong>
        </div>
      <?php endforeach; ?>
      <?php if (!$topShades): ?>
        <p class="text-sm muted mb0">No sales yet.</p>
      <?php endif; ?>
    </div>

    <div class="panel">
      <h2>Coupon usage</h2>
      <?php foreach ($coupons as $c): ?>
        <div class="review-line">
          <div class="min0">
            <p class="text-sm mb0"><strong><?= e((string) $c['code']) ?></strong></p>
            <p class="text-xs muted mb0"><?= (int) $c['uses'] ?> uses</p>
          </div>
          <strong class="text-xs" style="color:var(--sage)">−<?= e(money((float) $c['given'])) ?></strong>
        </div>
      <?php endforeach; ?>
      <?php if (!$coupons): ?>
        <p class="text-sm muted mb0">No coupons redeemed yet.</p>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require_once YN_ROOT . '/includes/admin-footer.php'; ?>
