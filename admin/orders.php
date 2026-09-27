<?php
/**
 * Order list — the screen the owner spends most of their day in.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-bootstrap.php';
$admin = admin_guard();

// ---- bulk actions -----------------------------------------------------
if (is_post()) {
    csrf_verify();

    $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
    $act = post_str('bulk_action', 30);

    if (!$ids) {
        flash('Select at least one order first.', 'warn');
        redirect('admin/orders.php?' . http_build_query($_GET));
    }

    $done = 0;
    foreach ($ids as $id) {
        $o = order_by_id($id);
        if (!$o) {
            continue;
        }

        if ($act === 'processing' || $act === 'packed') {
            if (order_set_status($id, $act, 'Bulk update', 'admin', (int) $admin['id'])) {
                $done++;
            }
        } elseif ($act === 'shipped') {
            // Shipping needs a courier + AWB per order, so bulk only moves
            // orders that already have one — otherwise the customer gets a
            // "shipped" email with nothing to track.
            if (!empty($o['awb']) && order_set_status($id, 'shipped', 'Bulk dispatch', 'admin', (int) $admin['id'])) {
                try { order_send_shipped($id); } catch (Throwable $e) { error_log($e->getMessage()); }
                $done++;
            }
        }
    }

    admin_log('orders_bulk', 'order', null, $act . ' × ' . $done);

    if ($act === 'shipped' && $done < count($ids)) {
        flash($done . ' dispatched. The rest need a courier and tracking number first — '
            . 'open each one and add it.', 'warn');
    } else {
        flash($done . ' order' . ($done === 1 ? '' : 's') . ' updated.', 'success');
    }
    redirect('admin/orders.php?' . http_build_query($_GET));
}

// ---- filters ----------------------------------------------------------
$q       = get_str('q', 80);
$status  = get_str('status', 30);
$payment = get_str('payment', 20);
$from    = get_str('from', 10);
$to      = get_str('to', 10);

$where  = ['1=1'];
$params = [];

if ($q !== '') {
    $where[]  = '(o.order_no LIKE ? OR o.email LIKE ? OR o.phone LIKE ? OR o.awb LIKE ? OR o.ship_name LIKE ?)';
    $like     = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
if ($status !== '' && in_array($status, array_keys(order_allowed_transitions()), true)) {
    $where[]  = 'o.status = ?';
    $params[] = $status;
}
if (in_array($payment, ['unpaid', 'paid', 'refunded', 'partially_refunded', 'failed'], true)) {
    $where[]  = 'o.payment_status = ?';
    $params[] = $payment;
}
if ($from !== '' && strtotime($from)) {
    $where[]  = 'o.placed_at >= ?';
    $params[] = date('Y-m-d 00:00:00', strtotime($from));
}
if ($to !== '' && strtotime($to)) {
    $where[]  = 'o.placed_at <= ?';
    $params[] = date('Y-m-d 23:59:59', strtotime($to));
}

$whereSql = implode(' AND ', $where);

// ---- CSV export -------------------------------------------------------
if (get_str('export') === 'csv') {
    $rows = db_all(
        "SELECT o.order_no, o.placed_at, o.status, o.payment_status, o.email, o.phone,
                o.ship_name, o.ship_city, o.ship_state, o.ship_pincode,
                o.subtotal, o.discount_total, o.shipping_total, o.grand_total,
                o.coupon_code, o.courier, o.awb
         FROM orders o WHERE {$whereSql} ORDER BY o.placed_at DESC LIMIT 5000",
        $params
    );
    admin_log('orders_export', 'order', null, count($rows) . ' rows');
    admin_csv_out('orders-' . date('Y-m-d') . '.csv', $rows, [
        'order_no' => 'Order', 'placed_at' => 'Placed', 'status' => 'Status',
        'payment_status' => 'Payment', 'email' => 'Email', 'phone' => 'Phone',
        'ship_name' => 'Ship to', 'ship_city' => 'City', 'ship_state' => 'State',
        'ship_pincode' => 'Pincode', 'subtotal' => 'Subtotal', 'discount_total' => 'Discount',
        'shipping_total' => 'Delivery', 'grand_total' => 'Total',
        'coupon_code' => 'Coupon', 'courier' => 'Courier', 'awb' => 'AWB',
    ]);
}

$total = (int) db_val("SELECT COUNT(*) FROM orders o WHERE {$whereSql}", $params);
$pg    = admin_paginate($total, 25);

$orders = db_all(
    "SELECT o.*, (SELECT COALESCE(SUM(qty),0) FROM order_items WHERE order_id = o.id) AS units
     FROM orders o WHERE {$whereSql}
     ORDER BY o.placed_at DESC, o.id DESC
     LIMIT {$pg['per']} OFFSET {$pg['offset']}",
    $params
);

$statusCounts = [];
foreach (db_all('SELECT status, COUNT(*) n FROM orders GROUP BY status') as $r) {
    $statusCounts[$r['status']] = (int) $r['n'];
}

$pageTitle    = 'Orders';
$adminSection = 'orders';

require_once YN_ROOT . '/includes/admin-header.php';
?>

<div class="admin-head">
  <div class="min0">
    <h1>Orders</h1>
    <p><?= number_format($total) ?> order<?= $total === 1 ? '' : 's' ?><?= $q !== '' || $status !== '' ? ' matching' : '' ?></p>
  </div>
  <a class="btn btn-sm btn-soft" style="flex:none"
     href="<?= e(url('admin/orders.php?' . http_build_query($_GET + ['export' => 'csv']))) ?>">
    <?= icon('download', 15) ?> Export CSV</a>
</div>

<div class="chips mb4">
  <a class="chip <?= $status === '' ? 'is-on' : '' ?>" href="<?= e(url('admin/orders.php')) ?>">
    All</a>
  <?php foreach (['paid' => 'To pack', 'packed' => 'To dispatch', 'shipped' => 'In transit',
                  'delivered' => 'Delivered', 'pending_payment' => 'Unpaid',
                  'cancelled' => 'Cancelled'] as $k => $lbl): ?>
    <a class="chip <?= $status === $k ? 'is-on' : '' ?>"
       href="<?= e(url('admin/orders.php?status=' . $k)) ?>">
      <?= e($lbl) ?><?php if (!empty($statusCounts[$k])): ?>
        <span class="muted">· <?= (int) $statusCounts[$k] ?></span><?php endif; ?></a>
  <?php endforeach; ?>
</div>

<div class="admin-toolbar">
  <form method="get" action="<?= e(url('admin/orders.php')) ?>" style="flex:1">
    <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
    <div class="search admin-search">
      <?= icon('search', 16) ?>
      <input class="input" type="search" name="q" value="<?= e($q) ?>"
             placeholder="Order no, email, phone, AWB or name">
    </div>
    <select class="select" name="payment" onchange="this.form.submit()">
      <option value="">Any payment</option>
      <?php foreach (['paid' => 'Paid', 'unpaid' => 'Unpaid', 'refunded' => 'Refunded',
                      'partially_refunded' => 'Part refunded', 'failed' => 'Failed'] as $k => $l): ?>
        <option value="<?= e($k) ?>" <?= $payment === $k ? 'selected' : '' ?>><?= e($l) ?></option>
      <?php endforeach; ?>
    </select>
    <input class="input" type="date" name="from" value="<?= e($from) ?>" aria-label="From date" style="width:150px">
    <input class="input" type="date" name="to" value="<?= e($to) ?>" aria-label="To date" style="width:150px">
    <button class="btn btn-sm" type="submit">Filter</button>
    <?php if ($q || $status || $payment || $from || $to): ?>
      <a class="btn btn-sm btn-soft" href="<?= e(url('admin/orders.php')) ?>">Clear</a>
    <?php endif; ?>
  </form>
</div>

<form method="post" action="<?= e(url('admin/orders.php?' . http_build_query($_GET))) ?>">
  <?= csrf_field() ?>

  <div class="bulk-bar" data-bulk-bar>
    <strong><span data-bulk-count>0</span> selected</strong>
    <select class="select" name="bulk_action" style="min-height:38px;width:auto">
      <option value="processing">Mark as processing</option>
      <option value="packed">Mark as packed</option>
      <option value="shipped">Mark as shipped (needs AWB)</option>
    </select>
    <button class="btn btn-sm" type="submit">Apply</button>
  </div>

  <div class="panel panel--flush">
    <?php if (!$orders): ?>
      <div class="admin-empty">
        <?= icon('package', 40) ?>
        <p>No orders match those filters.</p>
        <a class="btn btn-sm btn-soft mt3" href="<?= e(url('admin/orders.php')) ?>">Clear filters</a>
      </div>
    <?php else: ?>
      <div class="scroll-x">
        <table class="admin-table">
          <thead>
            <tr>
              <th class="tight"><input type="checkbox" data-bulk-all aria-label="Select all"></th>
              <th>Order</th><th>Date</th><th>Customer</th><th>Ship to</th>
              <th class="num">Items</th><th class="num">Total</th>
              <th>Payment</th><th>Status</th><th class="tight"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($orders as $o): ?>
              <?php
              [$lbl, $mod] = order_status_meta((string) $o['status']);
              $payMod = match ($o['payment_status']) {
                  'paid' => 'ok', 'unpaid' => 'warn',
                  'failed' => 'bad', default => 'muted',
              };
              ?>
              <tr>
                <td class="tight">
                  <input type="checkbox" name="ids[]" value="<?= (int) $o['id'] ?>" data-bulk-item
                         aria-label="Select <?= e((string) $o['order_no']) ?>"></td>
                <td><a href="<?= e(url('admin/order-detail.php?id=' . (int) $o['id'])) ?>">
                  <strong><?= e((string) $o['order_no']) ?></strong></a></td>
                <td class="tight"><?= e(fmt_date((string) $o['placed_at'], 'j M y')) ?></td>
                <td class="truncate" style="max-width:180px"><?= e((string) $o['email']) ?></td>
                <td class="truncate" style="max-width:150px">
                  <?= e((string) $o['ship_name']) ?>
                  <span class="muted">· <?= e((string) $o['ship_city']) ?></span></td>
                <td class="num"><?= (int) $o['units'] ?></td>
                <td class="num"><strong><?= e(money((float) $o['grand_total'])) ?></strong></td>
                <td><span class="pill pill-<?= e($payMod) ?>">
                  <?= e(ucfirst(str_replace('_', ' ', (string) $o['payment_status']))) ?></span></td>
                <td><span class="pill pill-<?= e($mod) ?>"><?= e($lbl) ?></span></td>
                <td class="tight">
                  <a class="btn-link" href="<?= e(url('admin/order-detail.php?id=' . (int) $o['id'])) ?>">
                    Open</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</form>

<?php if ($pg['pages'] > 1): ?>
  <?php require_once YN_ROOT . '/includes/components/pagination.php'; ?>
  <?php pagination($pg['page'], $pg['pages']); ?>
<?php endif; ?>

<?php require_once YN_ROOT . '/includes/admin-footer.php'; ?>
