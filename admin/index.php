<?php
/**
 * Admin dashboard.
 *
 * Ordered by what needs a decision today, not by what is impressive.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-bootstrap.php';
$admin = admin_guard();

$range = get_str('range', 10);
$days  = match ($range) { 'today' => 1, '7' => 7, '90' => 90, default => 30 };
$range = match ($days) { 1 => 'today', 7 => '7', 90 => '90', default => '30' };

$since = $days === 1
    ? 'CURDATE()'
    : 'DATE_SUB(CURDATE(), INTERVAL ' . (int) $days . ' DAY)';

$paidWhere = "payment_status = 'paid' AND placed_at >= {$since}";

$revenue  = (float) db_val("SELECT COALESCE(SUM(grand_total),0) FROM orders WHERE {$paidWhere}");
$orders   = (int)   db_val("SELECT COUNT(*) FROM orders WHERE {$paidWhere}");
$aov      = $orders > 0 ? $revenue / $orders : 0.0;
$newCust  = (int)   db_val("SELECT COUNT(*) FROM customers
                            WHERE deleted_at IS NULL AND created_at >= {$since}");
$unitsSold = (int)  db_val("SELECT COALESCE(SUM(oi.qty),0) FROM order_items oi
                            JOIN orders o ON o.id = oi.order_id WHERE o.{$paidWhere}");

// Things waiting on a human.
$toShip     = (int) db_val('SELECT COUNT(*) FROM orders WHERE status IN ("paid","processing")');
$toPack     = (int) db_val('SELECT COUNT(*) FROM orders WHERE status = "packed"');
$unpaid     = (int) db_val('SELECT COUNT(*) FROM orders
                            WHERE status = "pending_payment" AND placed_at > (NOW() - INTERVAL 2 DAY)');
$failed24   = (int) db_val('SELECT COUNT(*) FROM payments
                            WHERE status = "failed" AND created_at > (NOW() - INTERVAL 1 DAY)');
$pendingRev = (int) db_val('SELECT COUNT(*) FROM reviews WHERE status = "pending"');
$openRet    = (int) db_val('SELECT COUNT(*) FROM return_requests WHERE status = "requested"');
$lowStock   = (int) db_val('SELECT COUNT(*) FROM product_variants
                            WHERE is_active = 1 AND stock <= low_stock_at');
$outStock   = (int) db_val('SELECT COUNT(*) FROM product_variants
                            WHERE is_active = 1 AND stock = 0');

// 30-day revenue sparkline.
$daily = db_all(
    'SELECT DATE(placed_at) d, SUM(grand_total) t
     FROM orders
     WHERE payment_status = "paid" AND placed_at >= DATE_SUB(CURDATE(), INTERVAL 29 DAY)
     GROUP BY DATE(placed_at)'
);
$byDay = [];
foreach ($daily as $r) {
    $byDay[$r['d']] = (float) $r['t'];
}
$spark = [];
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-{$i} days"));
    $spark[$d] = $byDay[$d] ?? 0.0;
}
$sparkMax = max(1.0, max($spark));

$recent = db_all('SELECT * FROM orders ORDER BY placed_at DESC LIMIT 8');

$topProducts = db_all(
    "SELECT oi.product_id, oi.product_name, SUM(oi.qty) units, SUM(oi.line_total) revenue
     FROM order_items oi JOIN orders o ON o.id = oi.order_id
     WHERE o.{$paidWhere}
     GROUP BY oi.product_id, oi.product_name
     ORDER BY units DESC LIMIT 5"
);

$lowList = db_all(
    'SELECT v.id, v.sku, v.shade_name, v.stock, v.low_stock_at, p.name, p.id AS pid
     FROM product_variants v JOIN products p ON p.id = v.product_id
     WHERE v.is_active = 1 AND p.is_active = 1 AND v.stock <= v.low_stock_at
     ORDER BY v.stock ASC, p.name LIMIT 8'
);

$pageTitle    = 'Dashboard';
$adminSection = '';

require_once YN_ROOT . '/includes/admin-header.php';
?>

<div class="admin-head">
  <div class="min0">
    <h1>Dashboard</h1>
    <p>Hello <?= e(explode(' ', (string) $admin['name'])[0]) ?> — here is where things stand.</p>
  </div>
  <div class="chips">
    <?php foreach (['today' => 'Today', '7' => '7 days', '30' => '30 days', '90' => '90 days'] as $k => $lbl): ?>
      <a class="chip <?= $range === $k ? 'is-on' : '' ?>"
         href="<?= e(url('admin/?range=' . $k)) ?>"><?= e($lbl) ?></a>
    <?php endforeach; ?>
  </div>
</div>

<!-- ============================================== needs attention -->
<?php if ($toShip || $unpaid || $openRet || $pendingRev || $outStock || $failed24): ?>
  <div class="kpi-grid mb5">
    <?php if ($toShip): ?>
      <a class="kpi kpi--warn" href="<?= e(url('admin/orders.php?status=paid')) ?>" style="text-decoration:none">
        <p class="kpi__label"><?= icon('package', 14) ?> Ready to pack</p>
        <p class="kpi__value"><?= $toShip ?></p>
        <p class="kpi__sub">paid, not yet packed</p>
      </a>
    <?php endif; ?>
    <?php if ($toPack): ?>
      <a class="kpi kpi--warn" href="<?= e(url('admin/orders.php?status=packed')) ?>" style="text-decoration:none">
        <p class="kpi__label"><?= icon('truck', 14) ?> Awaiting dispatch</p>
        <p class="kpi__value"><?= $toPack ?></p>
        <p class="kpi__sub">packed, needs an AWB</p>
      </a>
    <?php endif; ?>
    <?php if ($openRet): ?>
      <a class="kpi kpi--alert" href="<?= e(url('admin/returns.php')) ?>" style="text-decoration:none">
        <p class="kpi__label"><?= icon('refresh', 14) ?> Return requests</p>
        <p class="kpi__value"><?= $openRet ?></p>
        <p class="kpi__sub">awaiting your decision</p>
      </a>
    <?php endif; ?>
    <?php if ($outStock): ?>
      <a class="kpi kpi--alert" href="<?= e(url('admin/inventory.php?filter=out')) ?>" style="text-decoration:none">
        <p class="kpi__label"><?= icon('alert', 14) ?> Out of stock</p>
        <p class="kpi__value"><?= $outStock ?></p>
        <p class="kpi__sub">shades at zero</p>
      </a>
    <?php endif; ?>
    <?php if ($pendingRev): ?>
      <a class="kpi" href="<?= e(url('admin/reviews.php?status=pending')) ?>" style="text-decoration:none">
        <p class="kpi__label"><?= icon('star', 14) ?> Reviews to check</p>
        <p class="kpi__value"><?= $pendingRev ?></p>
      </a>
    <?php endif; ?>
    <?php if ($failed24): ?>
      <a class="kpi" href="<?= e(url('admin/orders.php?status=pending_payment')) ?>" style="text-decoration:none">
        <p class="kpi__label"><?= icon('alert', 14) ?> Failed payments</p>
        <p class="kpi__value"><?= $failed24 ?></p>
        <p class="kpi__sub">last 24 hours</p>
      </a>
    <?php endif; ?>
  </div>
<?php endif; ?>

<!-- ========================================================= money -->
<div class="kpi-grid mb5">
  <div class="kpi">
    <p class="kpi__label"><?= icon('tag', 14) ?> Revenue</p>
    <p class="kpi__value"><?= e(money($revenue)) ?></p>
    <p class="kpi__sub">paid orders, <?= $days === 1 ? 'today' : 'last ' . $days . ' days' ?></p>
  </div>
  <div class="kpi">
    <p class="kpi__label"><?= icon('package', 14) ?> Orders</p>
    <p class="kpi__value"><?= number_format($orders) ?></p>
    <p class="kpi__sub"><?= number_format($unitsSold) ?> balls sold</p>
  </div>
  <div class="kpi">
    <p class="kpi__label"><?= icon('sort', 14) ?> Average order</p>
    <p class="kpi__value"><?= e(money($aov)) ?></p>
  </div>
  <div class="kpi">
    <p class="kpi__label"><?= icon('user', 14) ?> New customers</p>
    <p class="kpi__value"><?= number_format($newCust) ?></p>
  </div>
</div>

<div class="panel">
  <h2>Revenue, last 30 days</h2>
  <div class="spark" role="img"
       aria-label="Daily revenue for the last 30 days, peak <?= e(money($sparkMax)) ?>">
    <?php foreach ($spark as $d => $v): ?>
      <i class="<?= $v > 0 ? 'has' : '' ?>"
         style="height:<?= max(3, (int) round($v / $sparkMax * 44)) ?>px"
         title="<?= e(date('j M', strtotime($d)) . ' · ' . money($v)) ?>"></i>
    <?php endforeach; ?>
  </div>
  <p class="text-xs muted mt3 mb0">
    Peak day <?= e(money($sparkMax)) ?> ·
    total <?= e(money(array_sum($spark))) ?> over 30 days
  </p>
</div>

<div class="admin-cols">
  <div class="min0">
    <div class="panel panel--flush">
      <h2>Latest orders</h2>
      <?php if (!$recent): ?>
        <div class="admin-empty"><?= icon('package', 40) ?><p>No orders yet.</p></div>
      <?php else: ?>
        <div class="scroll-x">
          <table class="admin-table">
            <thead><tr>
              <th>Order</th><th>Date</th><th>Customer</th>
              <th class="num">Total</th><th>Status</th>
            </tr></thead>
            <tbody>
              <?php foreach ($recent as $o): ?>
                <?php [$lbl, $mod] = order_status_meta((string) $o['status']); ?>
                <tr>
                  <td><a href="<?= e(url('admin/order-detail.php?id=' . (int) $o['id'])) ?>">
                    <?= e((string) $o['order_no']) ?></a></td>
                  <td class="tight"><?= e(fmt_date((string) $o['placed_at'], 'j M')) ?></td>
                  <td class="truncate" style="max-width:180px"><?= e((string) $o['email']) ?></td>
                  <td class="num"><?= e(money((float) $o['grand_total'])) ?></td>
                  <td><span class="pill pill-<?= e($mod) ?>"><?= e($lbl) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <div class="admin-cols__side">
    <div class="panel">
      <h2>Top sellers</h2>
      <?php if (!$topProducts): ?>
        <p class="text-sm muted mb0">Nothing sold in this period.</p>
      <?php else: ?>
        <?php foreach ($topProducts as $t): ?>
          <div class="review-line">
            <div class="min0">
              <p class="text-sm mb0 truncate"><?= e((string) $t['product_name']) ?></p>
              <p class="text-xs muted mb0"><?= (int) $t['units'] ?> balls</p>
            </div>
            <strong class="text-xs"><?= e(money((float) $t['revenue'])) ?></strong>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="panel">
      <h2>Running low</h2>
      <?php if (!$lowList): ?>
        <p class="text-sm muted mb0">Everything is comfortably stocked.</p>
      <?php else: ?>
        <?php foreach ($lowList as $l): ?>
          <div class="review-line">
            <div class="min0">
              <p class="text-sm mb0 truncate">
                <a href="<?= e(url('admin/product-edit.php?id=' . (int) $l['pid'])) ?>">
                  <?= e((string) $l['name']) ?></a></p>
              <p class="text-xs muted mb0"><?= e((string) $l['shade_name']) ?> · <?= e((string) $l['sku']) ?></p>
            </div>
            <span class="pill <?= (int) $l['stock'] === 0 ? 'pill-bad' : 'pill-warn' ?>">
              <?= (int) $l['stock'] ?></span>
          </div>
        <?php endforeach; ?>
        <a class="btn btn-sm btn-soft btn-block mt4" href="<?= e(url('admin/inventory.php')) ?>">
          Manage inventory</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require_once YN_ROOT . '/includes/admin-footer.php'; ?>
