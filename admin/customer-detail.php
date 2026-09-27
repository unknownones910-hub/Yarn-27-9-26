<?php
/**
 * One customer: their orders, addresses and support history.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-bootstrap.php';
require_once YN_ROOT . '/includes/address.php';

$admin = admin_guard();

$id = get_int('id') ?: post_int('id');
$c  = $id > 0 ? db_row('SELECT * FROM customers WHERE id = ?', [$id]) : null;

if (!$c) {
    http_response_code(404);
    flash('That customer does not exist.', 'error');
    redirect('admin/customers.php');
}

if (is_post()) {
    csrf_verify();
    $action = post_str('action', 20);

    if ($action === 'block' || $action === 'unblock') {
        if (!admin_can('manager')) {
            flash('Only a manager or owner can block a customer.', 'error');
        } else {
            $to = $action === 'block' ? 'blocked' : 'active';
            db_exec('UPDATE customers SET status = ? WHERE id = ?', [$to, $id]);
            admin_log('customer_' . $action, 'customer', $id);
            flash('Customer ' . $to . '.', 'success');
        }
    }
    redirect('admin/customer-detail.php?id=' . $id);
}

$orders = db_all('SELECT * FROM orders WHERE customer_id = ? ORDER BY placed_at DESC', [$id]);
$addrs  = address_list($id);
$tickets = db_all('SELECT * FROM support_tickets WHERE customer_id = ? ORDER BY updated_at DESC', [$id]);
$reviews = db_all('SELECT r.*, p.name AS product_name FROM reviews r
                   JOIN products p ON p.id = r.product_id
                   WHERE r.customer_id = ? ORDER BY r.created_at DESC', [$id]);
$wish   = (int) db_val('SELECT COUNT(*) FROM wishlists WHERE customer_id = ?', [$id]);

$paidCount = 0;
$paidValue = 0.0;
foreach ($orders as $o) {
    if ($o['payment_status'] === 'paid') {
        $paidCount++;
        $paidValue += (float) $o['grand_total'];
    }
}

$pageTitle    = (string) ($c['full_name'] ?: $c['email']);
$adminSection = 'customers';

require_once YN_ROOT . '/includes/admin-header.php';
?>

<div class="admin-head">
  <div class="min0">
    <p class="text-sm mb1"><a href="<?= e(url('admin/customers.php')) ?>">
      <?= icon('arrow-left', 13) ?> All customers</a></p>
    <h1><?= e((string) ($c['full_name'] ?: 'Customer #' . $id)) ?>
      <?php if ($c['status'] === 'blocked'): ?><span class="pill pill-bad">Blocked</span><?php endif; ?>
      <?php if ($c['deleted_at']): ?><span class="pill pill-muted">Account closed</span><?php endif; ?>
    </h1>
    <p><?= e((string) $c['email']) ?> · joined <?= e(fmt_date((string) $c['created_at'])) ?></p>
  </div>
</div>

<div class="kpi-grid mb5">
  <div class="kpi"><p class="kpi__label">Paid orders</p><p class="kpi__value"><?= $paidCount ?></p></div>
  <div class="kpi"><p class="kpi__label">Lifetime value</p>
    <p class="kpi__value"><?= e(money($paidValue)) ?></p></div>
  <div class="kpi"><p class="kpi__label">Average order</p>
    <p class="kpi__value"><?= e(money($paidCount > 0 ? $paidValue / $paidCount : 0)) ?></p></div>
  <div class="kpi"><p class="kpi__label">Wishlist</p><p class="kpi__value"><?= $wish ?></p></div>
</div>

<div class="admin-cols">
  <div class="min0">
    <div class="panel panel--flush">
      <h2>Orders</h2>
      <?php if (!$orders): ?>
        <div class="admin-empty" style="padding:var(--s6)">
          <p class="mb0">No orders yet.</p></div>
      <?php else: ?>
        <div class="scroll-x">
          <table class="admin-table" style="min-width:520px">
            <thead><tr><th>Order</th><th>Date</th><th class="num">Total</th>
              <th>Payment</th><th>Status</th></tr></thead>
            <tbody>
              <?php foreach ($orders as $o): ?>
                <?php [$lbl, $mod] = order_status_meta((string) $o['status']); ?>
                <tr>
                  <td><a href="<?= e(url('admin/order-detail.php?id=' . (int) $o['id'])) ?>">
                    <?= e((string) $o['order_no']) ?></a></td>
                  <td class="text-xs"><?= e(fmt_date((string) $o['placed_at'], 'j M y')) ?></td>
                  <td class="num"><?= e(money((float) $o['grand_total'])) ?></td>
                  <td class="text-xs"><?= e((string) $o['payment_status']) ?></td>
                  <td><span class="pill pill-<?= e($mod) ?>"><?= e($lbl) ?></span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($tickets): ?>
      <div class="panel">
        <h2>Support tickets</h2>
        <?php foreach ($tickets as $t): ?>
          <div class="review-line">
            <div class="min0">
              <p class="text-sm mb0"><a href="<?= e(url('admin/support.php?id=' . (int) $t['id'])) ?>">
                <?= e((string) $t['subject']) ?></a></p>
              <p class="text-xs muted mb0">updated <?= e(time_ago((string) $t['updated_at'])) ?></p>
            </div>
            <span class="pill pill-<?= $t['status'] === 'closed' ? 'muted' : 'info' ?>">
              <?= e((string) $t['status']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($reviews): ?>
      <div class="panel">
        <h2>Reviews written</h2>
        <?php foreach ($reviews as $r): ?>
          <div class="review-line">
            <div class="min0">
              <p class="text-sm mb0"><?= e((string) $r['product_name']) ?></p>
              <p class="text-xs muted mb0"><?= str_excerpt((string) $r['body'], 70) ?></p>
            </div>
            <span class="pill pill-<?= $r['status'] === 'approved' ? 'ok' : 'warn' ?>">
              <?= (int) $r['rating'] ?>★</span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="admin-cols__side">
    <div class="panel">
      <h2>Contact</h2>
      <dl class="dl">
        <dt>Email</dt><dd style="overflow-wrap:anywhere">
          <a href="mailto:<?= e((string) $c['email']) ?>"><?= e((string) $c['email']) ?></a></dd>
        <?php if ($c['phone']): ?>
          <dt>Phone</dt><dd><a href="tel:+91<?= e((string) $c['phone']) ?>">
            +91 <?= e((string) $c['phone']) ?></a></dd>
        <?php endif; ?>
        <dt>Marketing</dt>
        <dd><?= (int) $c['marketing_opt_in'] === 1 ? 'Subscribed' : 'Not subscribed' ?></dd>
        <?php if ($c['craft_prefs']): ?>
          <dt>Makes</dt><dd><?= e(str_replace(',', ', ', (string) $c['craft_prefs'])) ?></dd>
        <?php endif; ?>
        <?php if ($c['skill_level']): ?>
          <dt>Skill</dt><dd><?= e((string) $c['skill_level']) ?></dd>
        <?php endif; ?>
        <dt>Last seen</dt>
        <dd><?= $c['last_login_at'] ? e(time_ago((string) $c['last_login_at'])) : 'never' ?></dd>
      </dl>
    </div>

    <?php if ($addrs): ?>
      <div class="panel">
        <h2>Addresses</h2>
        <?php foreach ($addrs as $a): ?>
          <p class="text-sm mb1"><strong><?= e((string) $a['full_name']) ?></strong>
            <?php if ((int) $a['is_default'] === 1): ?>
              <span class="pill pill-ok">Default</span><?php endif; ?></p>
          <p class="text-xs soft mb4"><?= e(address_one_line($a)) ?><br><?= e((string) $a['phone']) ?></p>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!$c['deleted_at']): ?>
      <div class="panel">
        <h2>Account</h2>
        <?php if (admin_can('manager')): ?>
          <form method="post" action="<?= e(url('admin/customer-detail.php')) ?>"
                data-confirm="<?= $c['status'] === 'blocked'
                    ? 'Restore this account?' : 'Block this customer from signing in and ordering?' ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="action" value="<?= $c['status'] === 'blocked' ? 'unblock' : 'block' ?>">
            <button class="btn btn-sm <?= $c['status'] === 'blocked' ? '' : 'btn-danger' ?>" type="submit">
              <?= $c['status'] === 'blocked' ? 'Unblock customer' : 'Block customer' ?></button>
          </form>
          <p class="text-xs muted mt3 mb0">
            Blocking stops new sign-ins and orders. Existing orders are unaffected.
          </p>
        <?php else: ?>
          <p class="text-sm muted mb0">Blocking needs a manager or owner account.</p>
        <?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once YN_ROOT . '/includes/admin-footer.php'; ?>
