<?php
/**
 * One order, everything the owner can do to it.
 *
 * Status changes go through order_set_status(), which enforces the allowed
 * transition map — the dropdown only ever offers legal moves, and the server
 * rejects anything else even if the form is tampered with.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-bootstrap.php';
require_once YN_ROOT . '/includes/payments/razorpay.php';
require_once YN_ROOT . '/includes/invoice.php';

$admin = admin_guard();

$id    = get_int('id') ?: post_int('id');
$order = $id > 0 ? order_by_id($id) : null;

if (!$order) {
    http_response_code(404);
    $pageTitle = 'Order not found';
    $adminSection = 'orders';
    require_once YN_ROOT . '/includes/admin-header.php';
    echo '<div class="admin-empty">' . icon('package', 40) . '<p>That order does not exist.</p>'
       . '<a class="btn btn-sm mt3" href="' . e(url('admin/orders.php')) . '">Back to orders</a></div>';
    require_once YN_ROOT . '/includes/admin-footer.php';
    exit;
}

// ---------------------------------------------------------------- POST --
if (is_post()) {
    csrf_verify();
    $action = post_str('action', 30);

    switch ($action) {

        case 'status':
            $to   = post_str('status', 30);
            $note = post_str('note', 255);

            if (!order_can_transition((string) $order['status'], $to)) {
                flash('An order cannot go from "' . $order['status'] . '" to "' . $to . '".', 'error');
                break;
            }
            if (order_set_status($id, $to, $note, 'admin', (int) $admin['id'])) {
                admin_log('order_status', 'order', $id, $order['status'] . ' -> ' . $to);

                // Cancelling by hand must put the stock back.
                if ($to === 'cancelled' && $order['payment_status'] === 'paid') {
                    order_restock($id, 'cancel');
                }
                if ($to === 'shipped') {
                    try { order_send_shipped($id); } catch (Throwable $e) { error_log($e->getMessage()); }
                }
                if ($to === 'cancelled') {
                    try { order_send_cancelled($id, $note); } catch (Throwable $e) { error_log($e->getMessage()); }
                }
                flash('Status updated to ' . $to . '.', 'success');
            } else {
                flash('Could not update the status.', 'error');
            }
            break;

        case 'shipping':
            $courier = post_str('courier', 80);
            $awb     = post_str('awb', 60);
            $url     = post_str('tracking_url', 255);

            db_exec('UPDATE orders SET courier = ?, awb = ?, tracking_url = ? WHERE id = ?',
                    [$courier ?: null, $awb ?: null, $url ?: null, $id]);
            admin_log('order_shipping', 'order', $id, $courier . ' ' . $awb);

            // Adding tracking to a packed order is the natural moment to dispatch it.
            if ($awb !== '' && $order['status'] === 'packed' && post_str('and_ship') === '1') {
                if (order_set_status($id, 'shipped', 'Dispatched with ' . $courier, 'admin', (int) $admin['id'])) {
                    try { order_send_shipped($id); } catch (Throwable $e) { error_log($e->getMessage()); }
                    flash('Tracking saved and the customer has been emailed.', 'success');
                    break;
                }
            }
            flash('Tracking details saved.', 'success');
            break;

        case 'note':
            db_exec('UPDATE orders SET admin_note = ? WHERE id = ?', [post_str('admin_note', 500) ?: null, $id]);
            admin_log('order_note', 'order', $id);
            flash('Internal note saved.', 'success');
            break;

        case 'resend':
            try {
                order_send_confirmation($id);
                flash('Confirmation email re-sent.', 'success');
            } catch (Throwable $e) {
                flash('Could not send that email: ' . $e->getMessage(), 'error');
            }
            admin_log('order_resend', 'order', $id);
            break;

        case 'refund':
            if (!admin_can('manager')) {
                flash('Only a manager or owner can issue refunds.', 'error');
                break;
            }
            if ($order['payment_status'] !== 'paid' && $order['payment_status'] !== 'partially_refunded') {
                flash('There is no captured payment to refund.', 'error');
                break;
            }

            $amount = (float) post_str('amount', 12);
            $reason = post_str('reason', 200);
            $maxRefundable = (float) $order['grand_total']
                - (float) db_val('SELECT COALESCE(SUM(amount),0) FROM refunds
                                  WHERE order_id = ? AND status <> "failed"', [$id]);

            if ($amount <= 0 || $amount > $maxRefundable + 0.01) {
                flash('Enter an amount between ₹1 and ' . money($maxRefundable) . '.', 'error');
                break;
            }

            $pay = db_row('SELECT provider_payment_id FROM payments
                           WHERE order_id = ? AND status = "captured"
                           ORDER BY id DESC LIMIT 1', [$id]);

            if (!$pay || empty($pay['provider_payment_id'])) {
                flash('No gateway payment reference on this order — refund it manually and add a note.', 'error');
                break;
            }

            if (!rzp_configured()) {
                flash('Razorpay is not configured, so a refund cannot be sent automatically.', 'error');
                break;
            }

            $res = rzp_refund((string) $pay['provider_payment_id'], $amount, $reason);

            if (!$res['ok']) {
                db_insert('INSERT INTO refunds (order_id, amount, reason, status, admin_id)
                           VALUES (?,?,?,"failed",?)',
                          [$id, $amount, $reason, (int) $admin['id']]);
                flash('The gateway refused the refund: ' . (string) $res['error'], 'error');
                break;
            }

            db_insert('INSERT INTO refunds (order_id, provider_refund_id, amount, reason, status, admin_id)
                       VALUES (?,?,?,?,"initiated",?)',
                      [$id, $res['data']['id'] ?? null, $amount, $reason, (int) $admin['id']]);

            $refundedSoFar = (float) db_val('SELECT COALESCE(SUM(amount),0) FROM refunds
                                             WHERE order_id = ? AND status <> "failed"', [$id]);
            $full = $refundedSoFar >= (float) $order['grand_total'] - 0.01;

            db_exec('UPDATE orders SET payment_status = ? WHERE id = ?',
                    [$full ? 'refunded' : 'partially_refunded', $id]);

            if ($full) {
                order_restock($id, 'return');
                if (order_can_transition((string) $order['status'], 'refunded')) {
                    order_set_status($id, 'refunded', 'Full refund issued', 'admin', (int) $admin['id']);
                }
            }

            admin_log('order_refund', 'order', $id, money($amount) . ' — ' . $reason);
            flash('Refund of ' . money($amount) . ' sent. It reaches the customer in 5–7 days.', 'success');
            break;

        case 'return':
            $rid    = post_int('return_id');
            $decide = post_str('decision', 20);
            $note   = post_str('admin_note', 500);

            if (in_array($decide, ['approved', 'rejected', 'picked', 'received', 'refunded'], true)) {
                db_exec('UPDATE return_requests SET status = ?, admin_note = ?
                         WHERE id = ? AND order_id = ?', [$decide, $note ?: null, $rid, $id]);
                admin_log('return_' . $decide, 'order', $id, 'return #' . $rid);
                flash('Return marked as ' . $decide . '.', 'success');
            }
            break;
    }

    redirect('admin/order-detail.php?id=' . $id);
}

// ---------------------------------------------------------------- read --
$order   = order_by_id($id);
$items   = order_items($id);
$history = db_all('SELECT h.*, a.name AS admin_name FROM order_status_history h
                   LEFT JOIN admin_users a ON a.id = h.admin_id
                   WHERE h.order_id = ? ORDER BY h.created_at DESC, h.id DESC', [$id]);
$payments = db_all('SELECT * FROM payments WHERE order_id = ? ORDER BY id DESC', [$id]);
$refunds  = db_all('SELECT * FROM refunds WHERE order_id = ? ORDER BY id DESC', [$id]);
$returns  = db_all('SELECT * FROM return_requests WHERE order_id = ? ORDER BY id DESC', [$id]);
$customer = $order['customer_id']
    ? db_row('SELECT * FROM customers WHERE id = ?', [(int) $order['customer_id']]) : null;

$refundedSoFar = (float) db_val('SELECT COALESCE(SUM(amount),0) FROM refunds
                                 WHERE order_id = ? AND status <> "failed"', [$id]);
$maxRefundable = max(0.0, (float) $order['grand_total'] - $refundedSoFar);

[$statusLabel, $statusMod] = order_status_meta((string) $order['status']);
$nextStatuses = order_allowed_transitions()[$order['status']] ?? [];

$pageTitle    = $order['order_no'];
$adminSection = 'orders';

require_once YN_ROOT . '/includes/admin-header.php';
?>

<div class="admin-head">
  <div class="min0">
    <p class="text-sm mb1"><a href="<?= e(url('admin/orders.php')) ?>">
      <?= icon('arrow-left', 13) ?> All orders</a></p>
    <h1><?= e((string) $order['order_no']) ?>
      <span class="pill pill-<?= e($statusMod) ?>" style="vertical-align:middle;font-size:var(--fs-xs)">
        <?= e($statusLabel) ?></span></h1>
    <p>Placed <?= e(fmt_datetime((string) $order['placed_at'])) ?>
       <?= $order['paid_at'] ? '· paid ' . e(fmt_datetime((string) $order['paid_at'])) : '' ?></p>
  </div>
  <div class="row gap2" style="flex:none">
    <?php if ($order['payment_status'] === 'paid'): ?>
      <a class="btn btn-sm btn-soft" target="_blank" rel="noopener"
         href="<?= e(url('admin/order-doc.php?id=' . $id . '&doc=invoice')) ?>">
        <?= icon('download', 15) ?> Invoice</a>
    <?php endif; ?>
    <a class="btn btn-sm btn-soft" target="_blank" rel="noopener"
       href="<?= e(url('admin/order-doc.php?id=' . $id . '&doc=slip')) ?>">
      <?= icon('package', 15) ?> Packing slip</a>
  </div>
</div>

<?php if (!empty($order['admin_note']) && str_contains((string) $order['admin_note'], 'STOCK SHORTFALL')): ?>
  <div class="alert alert-error mb4">
    <?= icon('alert', 18) ?>
    <div><strong>Stock shortfall.</strong> This order was paid but stock could not be deducted.
      Either source the items or refund the affected lines.
      <br><span class="text-xs"><?= nl2br(e((string) $order['admin_note'])) ?></span></div>
  </div>
<?php endif; ?>

<?php foreach ($returns as $r): ?>
  <?php if ($r['status'] === 'requested'): ?>
    <div class="alert alert-warn mb4">
      <?= icon('refresh', 18) ?>
      <div>
        <strong>Return requested</strong> on <?= e(fmt_date((string) $r['created_at'])) ?> —
        reason: <?= e(str_replace('_', ' ', (string) $r['reason'])) ?>.
        <?php if (!empty($r['comments'])): ?><br><em><?= e((string) $r['comments']) ?></em><?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
<?php endforeach; ?>

<div class="admin-cols">
  <div class="min0">

    <!-- items -->
    <div class="panel panel--flush">
      <h2>Items</h2>
      <div class="scroll-x">
        <table class="admin-table" style="min-width:560px">
          <thead><tr><th></th><th>Item</th><th>SKU</th>
            <th class="num">Price</th><th class="num">Qty</th><th class="num">Total</th></tr></thead>
          <tbody>
            <?php foreach ($items as $it): ?>
              <tr>
                <td class="tight">
                  <img class="thumb"
                       src="<?= e($it['image'] ? upload_url($it['image']) : yarn_ball_uri((string) $it['color_hex'])) ?>"
                       alt=""></td>
                <td>
                  <?php if (!empty($it['product_slug'])): ?>
                    <a href="<?= e(url('product/' . $it['product_slug'])) ?>" target="_blank" rel="noopener">
                      <?= e((string) $it['product_name']) ?></a>
                  <?php else: ?><?= e((string) $it['product_name']) ?><?php endif; ?>
                  <br><span class="text-xs muted"><?= e((string) $it['shade_name']) ?></span>
                </td>
                <td class="text-xs"><?= e((string) $it['sku']) ?></td>
                <td class="num"><?= e(money((float) $it['unit_price'])) ?></td>
                <td class="num"><?= (int) $it['qty'] ?></td>
                <td class="num"><strong><?= e(money((float) $it['line_total'])) ?></strong></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div style="padding:var(--s4) var(--s5)">
        <dl class="totals" style="max-width:320px;margin-left:auto">
          <dt>Subtotal</dt><dd><?= e(money((float) $order['subtotal'])) ?></dd>
          <?php if ((float) $order['discount_total'] > 0): ?>
            <dt class="is-discount">Discount <?= e((string) $order['coupon_code']) ?></dt>
            <dd class="is-discount">−<?= e(money((float) $order['discount_total'])) ?></dd>
          <?php endif; ?>
          <?php if ((float) $order['gift_wrap_total'] > 0): ?>
            <dt>Gift wrap</dt><dd><?= e(money((float) $order['gift_wrap_total'])) ?></dd>
          <?php endif; ?>
          <dt>Delivery</dt><dd><?= e(money((float) $order['shipping_total'])) ?></dd>
          <dt class="is-total">Total</dt>
          <dd class="is-total"><?= e(money((float) $order['grand_total'])) ?></dd>
          <?php if ($refundedSoFar > 0): ?>
            <dt style="color:var(--danger)">Refunded</dt>
            <dd style="color:var(--danger)">−<?= e(money($refundedSoFar)) ?></dd>
          <?php endif; ?>
        </dl>
      </div>
    </div>

    <!-- fulfilment -->
    <div class="panel">
      <h2>Fulfilment</h2>

      <?php if ($nextStatuses): ?>
        <form method="post" action="<?= e(url('admin/order-detail.php')) ?>" class="mb5">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= $id ?>">
          <input type="hidden" name="action" value="status">
          <div class="status-row">
            <select class="select" name="status" style="width:auto;min-width:180px">
              <?php foreach ($nextStatuses as $s): ?>
                <?php [$l] = order_status_meta($s); ?>
                <option value="<?= e($s) ?>"><?= e($l) ?></option>
              <?php endforeach; ?>
            </select>
            <input class="input" name="note" placeholder="Note (optional)" style="flex:1;min-width:140px">
            <button class="btn btn-sm" type="submit">Update status</button>
          </div>
          <p class="help mt2">
            Only moves that make sense from <strong><?= e($statusLabel) ?></strong> are offered.
          </p>
        </form>
      <?php else: ?>
        <p class="text-sm muted">This order is <?= e(strtolower($statusLabel)) ?> — no further changes.</p>
      <?php endif; ?>

      <form method="post" action="<?= e(url('admin/order-detail.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="action" value="shipping">
        <div class="field-row field-row--3">
          <div class="field">
            <label class="label" for="courier">Courier</label>
            <input class="input" id="courier" name="courier" list="couriers"
                   value="<?= e((string) $order['courier']) ?>" placeholder="Delhivery">
            <datalist id="couriers">
              <option>Delhivery</option><option>Blue Dart</option><option>DTDC</option>
              <option>India Post</option><option>Ekart</option><option>Xpressbees</option>
              <option>Shiprocket</option>
            </datalist>
          </div>
          <div class="field">
            <label class="label" for="awb">Tracking number</label>
            <input class="input" id="awb" name="awb" value="<?= e((string) $order['awb']) ?>">
          </div>
          <div class="field">
            <label class="label" for="tracking_url">Tracking link</label>
            <input class="input" id="tracking_url" name="tracking_url" type="url"
                   value="<?= e((string) $order['tracking_url']) ?>" placeholder="https://…">
          </div>
        </div>
        <?php if ($order['status'] === 'packed'): ?>
          <label class="check">
            <input type="checkbox" name="and_ship" value="1" checked>
            <span>Mark as shipped and email the customer their tracking link</span>
          </label>
        <?php endif; ?>
        <button class="btn btn-sm mt3" type="submit">Save tracking</button>
      </form>
    </div>

    <!-- returns -->
    <?php if ($returns): ?>
      <div class="panel">
        <h2>Return requests</h2>
        <?php foreach ($returns as $r): ?>
          <div class="mb4" style="padding-bottom:var(--s4);border-bottom:1px solid var(--sand)">
            <div class="row row-between mb2">
              <strong class="text-sm">#<?= (int) $r['id'] ?> ·
                <?= e(str_replace('_', ' ', (string) $r['reason'])) ?></strong>
              <span class="pill pill-<?= $r['status'] === 'requested' ? 'warn' : 'muted' ?>">
                <?= e((string) $r['status']) ?></span>
            </div>
            <?php if (!empty($r['comments'])): ?>
              <p class="text-sm soft mb2"><?= nl2br(e((string) $r['comments'])) ?></p>
            <?php endif; ?>
            <p class="text-xs muted mb3">Raised <?= e(fmt_datetime((string) $r['created_at'])) ?></p>

            <form method="post" action="<?= e(url('admin/order-detail.php')) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= $id ?>">
              <input type="hidden" name="action" value="return">
              <input type="hidden" name="return_id" value="<?= (int) $r['id'] ?>">
              <div class="status-row">
                <select class="select" name="decision" style="width:auto">
                  <option value="approved">Approve</option>
                  <option value="rejected">Reject</option>
                  <option value="picked">Picked up</option>
                  <option value="received">Received back</option>
                  <option value="refunded">Refunded</option>
                </select>
                <input class="input" name="admin_note" placeholder="Note to the customer"
                       style="flex:1;min-width:140px" value="<?= e((string) $r['admin_note']) ?>">
                <button class="btn btn-sm" type="submit">Save</button>
              </div>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <!-- history -->
    <div class="panel">
      <h2>History</h2>
      <?php foreach ($history as $h): ?>
        <div class="log-line">
          <time><?= e(fmt_date((string) $h['created_at'], 'j M, g:ia')) ?></time>
          <span>
            <strong><?= e(str_replace('_', ' ', (string) $h['to_status'])) ?></strong>
            <?php if ($h['from_status']): ?>
              <span class="muted">from <?= e(str_replace('_', ' ', (string) $h['from_status'])) ?></span>
            <?php endif; ?>
            <span class="muted">· <?= e((string) ($h['admin_name'] ?: $h['changed_by'])) ?></span>
            <?php if (!empty($h['note'])): ?><br><span class="text-xs muted"><?= e((string) $h['note']) ?></span><?php endif; ?>
          </span>
        </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- ============================================== side column -->
  <div class="admin-cols__side">

    <div class="panel">
      <h2>Customer</h2>
      <p class="text-sm mb1"><strong><?= e((string) $order['ship_name']) ?></strong></p>
      <p class="text-sm mb1"><a href="mailto:<?= e((string) $order['email']) ?>">
        <?= e((string) $order['email']) ?></a></p>
      <p class="text-sm mb3"><a href="tel:+91<?= e((string) $order['ship_phone']) ?>">
        +91 <?= e((string) $order['ship_phone']) ?></a></p>
      <?php if ($customer): ?>
        <p class="text-xs muted mb3">
          <?= (int) $customer['orders_count'] ?> orders ·
          <?= e(money((float) $customer['total_spent'])) ?> lifetime
        </p>
        <a class="btn btn-sm btn-soft btn-block"
           href="<?= e(url('admin/customer-detail.php?id=' . (int) $customer['id'])) ?>">
          View customer</a>
      <?php else: ?>
        <p class="text-xs muted mb0">Guest checkout</p>
      <?php endif; ?>
    </div>

    <div class="panel">
      <h2>Delivery address</h2>
      <p class="text-sm soft mb0" style="line-height:1.7">
        <?= nl2br(e(implode("\n", array_filter([
            $order['ship_name'],
            $order['ship_line1'],
            $order['ship_line2'],
            $order['ship_landmark'],
            $order['ship_city'] . ', ' . $order['ship_state'] . ' ' . $order['ship_pincode'],
            $order['ship_country'],
        ])))) ?>
      </p>
    </div>

    <?php if (!empty($order['customer_note'])): ?>
      <div class="panel" style="background:var(--warning-tint);border-color:#EFDCB6">
        <h2>Customer note</h2>
        <p class="text-sm mb0"><?= nl2br(e((string) $order['customer_note'])) ?></p>
      </div>
    <?php endif; ?>

    <div class="panel">
      <h2>Payment</h2>
      <dl class="dl">
        <dt>Status</dt>
        <dd><?= e(ucfirst(str_replace('_', ' ', (string) $order['payment_status']))) ?></dd>
        <dt>Method</dt><dd><?= e(ucfirst((string) $order['payment_method'])) ?></dd>
      </dl>
      <?php foreach ($payments as $p): ?>
        <div class="text-xs mt3" style="padding:var(--s2);background:var(--sand);border-radius:6px">
          <strong><?= e((string) $p['status']) ?></strong>
          <?= $p['method'] ? '· ' . e(rzp_method_label((string) $p['method'])) : '' ?>
          · <?= e(money((float) $p['amount'])) ?><br>
          <span class="muted" style="overflow-wrap:anywhere">
            <?= e((string) ($p['provider_payment_id'] ?: $p['provider_order_id'])) ?></span>
        </div>
      <?php endforeach; ?>

      <?php foreach ($refunds as $r): ?>
        <div class="text-xs mt2" style="padding:var(--s2);background:var(--danger-tint);border-radius:6px">
          Refund <?= e(money((float) $r['amount'])) ?> · <?= e((string) $r['status']) ?><br>
          <span class="muted"><?= e((string) $r['reason']) ?></span>
        </div>
      <?php endforeach; ?>

      <?php if ($maxRefundable > 0 && in_array($order['payment_status'], ['paid', 'partially_refunded'], true)): ?>
        <?php if (admin_can('manager')): ?>
          <button class="btn btn-sm btn-ghost btn-block mt4" type="button" data-modal-open="#refund-modal">
            Issue a refund</button>
        <?php else: ?>
          <p class="text-xs muted mt3 mb0">Refunds need a manager or owner account.</p>
        <?php endif; ?>
      <?php endif; ?>
    </div>

    <div class="panel">
      <h2>Internal note</h2>
      <form method="post" action="<?= e(url('admin/order-detail.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="action" value="note">
        <textarea class="textarea" name="admin_note" rows="3"
                  placeholder="Only staff see this"><?= e((string) $order['admin_note']) ?></textarea>
        <button class="btn btn-sm btn-soft mt3" type="submit">Save note</button>
      </form>
    </div>

    <div class="panel">
      <h2>Emails</h2>
      <form method="post" action="<?= e(url('admin/order-detail.php')) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="action" value="resend">
        <button class="btn btn-sm btn-soft btn-block" type="submit">
          <?= icon('mail', 15) ?> Re-send confirmation</button>
      </form>
      <?php
      $sent = db_all('SELECT subject, status, created_at FROM email_log
                      WHERE to_email = ? ORDER BY id DESC LIMIT 5', [(string) $order['email']]);
      ?>
      <?php foreach ($sent as $m): ?>
        <p class="text-xs muted mt2 mb0">
          <?= e(fmt_date((string) $m['created_at'], 'j M')) ?> ·
          <?= e((string) $m['status']) ?> · <?= e(str_excerpt((string) $m['subject'], 40)) ?>
        </p>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- refund modal -->
<?php if ($maxRefundable > 0 && admin_can('manager')): ?>
<div class="modal" id="refund-modal" role="dialog" aria-modal="true" aria-labelledby="rf-t" aria-hidden="true">
  <div class="modal__panel">
    <form method="post" action="<?= e(url('admin/order-detail.php')) ?>"
          data-confirm="Send this refund to the customer? This cannot be undone.">
      <?= csrf_field() ?>
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="action" value="refund">

      <div class="modal__head">
        <h2 id="rf-t">Issue a refund</h2>
        <button class="icon-btn" type="button" data-modal-close aria-label="Close"><?= icon('close', 20) ?></button>
      </div>
      <div class="modal__body">
        <p class="text-sm soft">
          Sent straight to Razorpay and back to the original payment method.
          Refundable now: <strong><?= e(money($maxRefundable)) ?></strong>.
        </p>
        <div class="field">
          <label class="label" for="amount">Amount (₹)</label>
          <input class="input" id="amount" name="amount" type="number" step="0.01"
                 min="1" max="<?= e(number_format($maxRefundable, 2, '.', '')) ?>"
                 value="<?= e(number_format($maxRefundable, 2, '.', '')) ?>" required data-autofocus>
        </div>
        <div class="field mb0">
          <label class="label" for="reason">Reason</label>
          <input class="input" id="reason" name="reason" placeholder="Damaged in transit" required>
        </div>
        <p class="text-xs muted mt3 mb0">
          A full refund also puts the stock back and marks the order refunded.
        </p>
      </div>
      <div class="modal__foot">
        <button class="btn btn-soft" type="button" data-modal-close>Cancel</button>
        <button class="btn btn-danger" type="submit">Send refund</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require_once YN_ROOT . '/includes/admin-footer.php'; ?>
