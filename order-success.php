<?php
/**
 * Order confirmation.
 *
 * Readable by the customer who placed it (session) or by the signed-in owner.
 * Not guessable from the order number alone.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$orderNo = get_str('order', 20);
if ($orderNo === '') {
    redirect('');
}

$order = db_row('SELECT * FROM orders WHERE order_no = ? LIMIT 1', [$orderNo]);

$ownsIt = $order && (
    (isset($_SESSION['last_order_no']) && hash_equals((string) $_SESSION['last_order_no'], (string) $order['order_no']))
    || (customer_id() > 0 && (int) $order['customer_id'] === customer_id())
);

if (!$order || !$ownsIt) {
    http_response_code(404);
    require YN_ROOT . '/404.php';
    exit;
}

/** Address formatter for an order row (order columns, not an address row). */
function address_one_line_from_order(array $o): string
{
    $bits = array_filter([
        $o['ship_line1'], $o['ship_line2'], $o['ship_landmark'],
        $o['ship_city'], $o['ship_state'] . ' ' . $o['ship_pincode'], $o['ship_country'],
    ]);
    return implode(', ', array_map('trim', $bits));
}

require_once YN_ROOT . '/includes/payments/razorpay.php';

$items    = db_all('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [(int) $order['id']]);
$unpaid   = $order['payment_status'] === 'unpaid';
$dispatch = setting('dispatch_days', '1-2');

$paidWith = db_row(
    'SELECT method, provider_payment_id FROM payments
     WHERE order_id = ? AND status = "captured" ORDER BY id DESC LIMIT 1',
    [(int) $order['id']]
);

$pageTitle = 'Order ' . $order['order_no'];
$noindex   = true;

require_once YN_ROOT . '/includes/header.php';
?>

<div class="container section-sm">
  <div style="width:min(720px,100%);margin-inline:auto">

    <div class="center mb6">
      <span class="empty__art" style="margin-bottom:var(--s4)">
        <?= icon($unpaid ? 'clock' : 'check-circle', 40) ?>
      </span>
      <h1><?= $unpaid ? 'Order created' : 'Thank you — order confirmed' ?></h1>
      <p class="lede" style="margin-inline:auto">
        <?= $unpaid
            ? 'Your order is reserved and waiting for payment.'
            : 'We have your order and will start packing it shortly.' ?>
      </p>
      <p class="mt4 mb0">
        <span class="pill pill-muted" style="font-size:1rem;padding:8px 16px">
          <?= e($order['order_no']) ?></span>
      </p>
    </div>

    <?php if ($unpaid && rzp_configured()): ?>
      <div class="alert alert-warn mb5">
        <?= icon('clock', 18) ?>
        <div>
          <strong>We are still waiting for payment.</strong>
          Nothing has been charged yet and your items are held for the next hour.
        </div>
      </div>
      <a class="btn btn-block btn-lg mb5"
         href="<?= e(url('pay.php?order=' . rawurlencode((string) $order['order_no']))) ?>">
        <?= icon('lock', 18) ?> Complete payment · <?= e(money((float) $order['grand_total'])) ?>
      </a>

    <?php elseif ($unpaid): ?>
      <div class="alert alert-warn mb5">
        <?= icon('info', 18) ?>
        <div>
          <strong>Online payment is not switched on yet.</strong>
          Your order has been recorded with everything we need. We will contact
          you on <?= e($order['ship_phone']) ?> to arrange payment.
        </div>
      </div>

    <?php else: ?>
      <div class="alert alert-success mb5">
        <?= icon('check-circle', 18) ?>
        <div>
          <strong>Payment received<?= $paidWith ? ' by ' . e(rzp_method_label($paidWith['method'])) : '' ?>.</strong>
          A confirmation with your invoice is on its way to <?= e((string) $order['email']) ?>.
        </div>
      </div>
    <?php endif; ?>

    <div class="card mb5">
      <h2 style="font-family:var(--font-sans);font-size:1rem">What happens next</h2>
      <ul class="timeline mt4">
        <li class="done"><strong>Order placed</strong><br>
          <span class="text-sm muted"><?= e(fmt_datetime($order['placed_at'])) ?></span></li>
        <li class="<?= $unpaid ? 'current' : 'done' ?>"><strong>Payment</strong><br>
          <span class="text-sm muted"><?= $unpaid ? 'Awaiting payment' : 'Received' ?></span></li>
        <li><strong>Packed &amp; dispatched</strong><br>
          <span class="text-sm muted">Within <?= e($dispatch) ?> business days of payment</span></li>
        <li><strong>Delivered</strong><br>
          <span class="text-sm muted">You will get a tracking link by email</span></li>
      </ul>
    </div>

    <div class="card mb5">
      <h2 style="font-family:var(--font-sans);font-size:1rem">Your order</h2>
      <div class="mt4">
        <?php foreach ($items as $it): ?>
          <div class="review-line">
            <img src="<?= e($it['image'] ? upload_url($it['image']) : yarn_ball_uri((string) $it['color_hex'])) ?>"
                 alt="" width="48" height="60" loading="lazy">
            <div class="min0">
              <p class="text-sm mb0"><strong><?= e($it['product_name']) ?></strong></p>
              <p class="text-xs muted mb0"><?= e((string) $it['shade_name']) ?> · Qty <?= (int) $it['qty'] ?></p>
            </div>
            <strong class="text-sm"><?= e(money((float) $it['line_total'])) ?></strong>
          </div>
        <?php endforeach; ?>
      </div>

      <dl class="totals mt4">
        <dt>Subtotal</dt><dd><?= e(money((float) $order['subtotal'])) ?></dd>
        <?php if ((float) $order['discount_total'] > 0): ?>
          <dt class="is-discount">Discount <span class="muted"><?= e((string) $order['coupon_code']) ?></span></dt>
          <dd class="is-discount">−<?= e(money((float) $order['discount_total'])) ?></dd>
        <?php endif; ?>
        <?php if ((float) $order['gift_wrap_total'] > 0): ?>
          <dt>Gift wrap</dt><dd><?= e(money((float) $order['gift_wrap_total'])) ?></dd>
        <?php endif; ?>
        <dt>Delivery</dt>
        <dd><?= (float) $order['shipping_total'] > 0
              ? e(money((float) $order['shipping_total']))
              : '<span class="free-tag">FREE</span>' ?></dd>
        <dt class="is-total">Total</dt>
        <dd class="is-total"><?= e(money((float) $order['grand_total'])) ?></dd>
      </dl>
    </div>

    <div class="card mb5">
      <h2 style="font-family:var(--font-sans);font-size:1rem">Delivering to</h2>
      <p class="text-sm mb0 mt3"><strong><?= e($order['ship_name']) ?></strong> · <?= e($order['ship_phone']) ?></p>
      <p class="text-sm soft mb0">
        <?= e(address_one_line_from_order($order)) ?>
      </p>
    </div>

    <div class="row" style="justify-content:center">
      <a class="btn" href="<?= e(url('shop.php')) ?>">Continue shopping</a>
      <a class="btn btn-soft" href="<?= e(url('track-order.php?order=' . rawurlencode($order['order_no']))) ?>">
        Track this order</a>
      <?php if (!$unpaid): ?>
        <a class="btn btn-soft" href="<?= e(url('invoice.php?order=' . rawurlencode($order['order_no']))) ?>">
          <?= icon('download', 16) ?> Invoice</a>
      <?php endif; ?>
    </div>

    <p class="center text-sm muted mt5">
      A confirmation has been sent to <strong><?= e($order['email']) ?></strong>.
      Questions? <a href="<?= e(url('contact.php')) ?>">Contact us</a>.
    </p>
  </div>
</div>

<?php require_once YN_ROOT . '/includes/footer.php'; ?>
