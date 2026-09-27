<?php
/**
 * Payment page.
 *
 * Opens Razorpay Checkout for an order that already exists in
 * `pending_payment`. Safe to reload and safe to come back to later from the
 * "payment failed" email — it creates a fresh gateway order if the previous
 * attempt was never completed.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/order.php';
require_once YN_ROOT . '/includes/payments/razorpay.php';

$orderNo = get_str('order', 20);
$order   = $orderNo !== '' ? order_by_no($orderNo) : null;

// Only the person who placed it, or the signed-in owner.
$owns = $order && (
    (isset($_SESSION['last_order_no']) && hash_equals((string) $_SESSION['last_order_no'], (string) $order['order_no']))
    || (customer_id() > 0 && (int) $order['customer_id'] === customer_id())
);

if (!$order || !$owns) {
    http_response_code(404);
    require YN_ROOT . '/404.php';
    exit;
}

// Already paid? Nothing to do here.
if ($order['payment_status'] === 'paid') {
    redirect('order-success/' . rawurlencode((string) $order['order_no']));
}

if (in_array($order['status'], ['cancelled', 'refunded'], true)) {
    flash('That order has been cancelled.', 'warn');
    redirect('');
}

$items    = order_items((int) $order['id']);
$gateway  = null;
$loadFail = '';

if (!rzp_configured()) {
    $loadFail = 'not_configured';
} else {
    // Reuse an outstanding gateway order; create one if there is none.
    $existing = db_row(
        'SELECT provider_order_id FROM payments
         WHERE order_id = ? AND status = "created" AND provider_order_id IS NOT NULL
         ORDER BY id DESC LIMIT 1',
        [(int) $order['id']]
    );

    if ($existing && !empty($existing['provider_order_id'])) {
        $gateway = $existing['provider_order_id'];
    } else {
        $rzp = rzp_create_order($order);
        if ($rzp['ok'] && $rzp['id'] !== '') {
            db_exec(
                'INSERT INTO payments (order_id, provider, provider_order_id, amount, status)
                 VALUES (?, "razorpay", ?, ?, "created")',
                [(int) $order['id'], $rzp['id'], (float) $order['grand_total']]
            );
            $gateway = $rzp['id'];
        } else {
            $loadFail = $rzp['error'] ?? 'gateway_error';
        }
    }
}

$customer  = current_customer();
$pageTitle = 'Complete payment · ' . $order['order_no'];
$noindex   = true;
$bodyClass = 'is-checkout';

// Razorpay's checkout.js is a third-party bundle. Relax script-src back to
// 'unsafe-inline' on this page only, so a future version of theirs that injects
// an inline script cannot be blocked by our nonce policy and kill the payment
// modal. Every other page keeps the strict CSP.
csp_allow_inline_scripts();

require_once YN_ROOT . '/includes/header.php';
?>

<div class="container section-sm">
  <div style="width:min(560px,100%);margin-inline:auto">

    <div class="center mb6">
      <span class="brand-mark" style="width:52px;height:52px;margin-inline:auto"><?= icon('lock', 26) ?></span>
      <h1 class="mt4">Complete your payment</h1>
      <p class="soft mb0">Order <strong><?= e((string) $order['order_no']) ?></strong></p>
    </div>

    <div class="card card-lift">
      <?php foreach ($items as $it): ?>
        <div class="review-line">
          <img src="<?= e($it['image'] ? upload_url($it['image']) : yarn_ball_uri((string) $it['color_hex'])) ?>"
               alt="" width="44" height="55" loading="lazy">
          <div class="min0">
            <p class="text-sm mb0 truncate"><?= e($it['product_name']) ?></p>
            <p class="text-xs muted mb0"><?= e((string) $it['shade_name']) ?> × <?= (int) $it['qty'] ?></p>
          </div>
          <strong class="text-sm"><?= e(money((float) $it['line_total'])) ?></strong>
        </div>
      <?php endforeach; ?>

      <dl class="totals mt4">
        <dt>Subtotal</dt><dd><?= e(money((float) $order['subtotal'])) ?></dd>
        <?php if ((float) $order['discount_total'] > 0): ?>
          <dt class="is-discount">Discount</dt>
          <dd class="is-discount">−<?= e(money((float) $order['discount_total'])) ?></dd>
        <?php endif; ?>
        <dt>Delivery</dt>
        <dd><?= (float) $order['shipping_total'] > 0
              ? e(money((float) $order['shipping_total']))
              : '<span class="free-tag">FREE</span>' ?></dd>
        <dt class="is-total">Amount to pay</dt>
        <dd class="is-total"><?= e(money((float) $order['grand_total'])) ?></dd>
      </dl>

      <?php if ($loadFail === 'not_configured'): ?>
        <div class="alert alert-warn mt5">
          <?= icon('info', 18) ?>
          <div>
            <strong>Online payment is not switched on yet.</strong>
            Your order is saved. We will contact you on <?= e((string) $order['ship_phone']) ?>
            to arrange payment.
          </div>
        </div>
        <a class="btn btn-soft btn-block mt4"
           href="<?= e(url('order-success/' . rawurlencode((string) $order['order_no']))) ?>">
          View order</a>

      <?php elseif ($loadFail !== ''): ?>
        <div class="alert alert-error mt5">
          <?= icon('alert', 18) ?>
          <div>We could not reach the payment gateway. Nothing has been charged.
               Please try again in a moment.</div>
        </div>
        <a class="btn btn-block mt4" href="<?= e(url('pay.php?order=' . rawurlencode((string) $order['order_no']))) ?>">
          Try again</a>

      <?php else: ?>
        <button class="btn btn-block btn-lg mt5" id="pay-now">
          <?= icon('lock', 18) ?> Pay <?= e(money((float) $order['grand_total'])) ?>
        </button>
        <p class="text-xs muted center mt3 mb0">
          UPI · Cards · Netbanking · Wallets — secured by Razorpay.<br>
          We never see or store your card details.
        </p>
      <?php endif; ?>
    </div>

    <p class="center text-sm muted mt5">
      Having trouble? <a href="<?= e(url('contact.php')) ?>">Contact us</a> quoting
      <?= e((string) $order['order_no']) ?>.
    </p>
  </div>
</div>

<?php if ($gateway): ?>
<form id="verify-form" method="post" action="<?= e(url('payment-verify.php')) ?>" class="hide">
  <?= csrf_field() ?>
  <input type="hidden" name="razorpay_order_id" id="f_order">
  <input type="hidden" name="razorpay_payment_id" id="f_payment">
  <input type="hidden" name="razorpay_signature" id="f_signature">
</form>

<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
(function () {
  var btn = document.getElementById('pay-now');

  var options = {
    key:      <?= ejs(rzp_key_id()) ?>,
    order_id: <?= ejs($gateway) ?>,
    amount:   <?= (int) to_paise((float) $order['grand_total']) ?>,
    currency: 'INR',
    name:     <?= ejs(setting('store_name')) ?>,
    description: <?= ejs('Order ' . $order['order_no']) ?>,
    image:    <?= ejs(abs_url(asset('img/hero-yarn.svg'))) ?>,
    prefill: {
      name:    <?= ejs($order['ship_name']) ?>,
      email:   <?= ejs($order['email']) ?>,
      contact: <?= ejs('+91' . $order['ship_phone']) ?>
    },
    notes: { order_no: <?= ejs($order['order_no']) ?> },
    theme: { color: '#B4552F' },

    handler: function (response) {
      document.getElementById('f_order').value     = response.razorpay_order_id;
      document.getElementById('f_payment').value   = response.razorpay_payment_id;
      document.getElementById('f_signature').value = response.razorpay_signature;
      document.getElementById('verify-form').submit();
    },

    modal: {
      ondismiss: function () {
        btn.classList.remove('is-loading');
        btn.disabled = false;
        if (window.ynToast) {
          window.ynToast('Payment cancelled — nothing has been charged.', 'warn');
        }
      }
    }
  };

  var rzp = new Razorpay(options);

  rzp.on('payment.failed', function (resp) {
    btn.classList.remove('is-loading');
    btn.disabled = false;
    var msg = (resp && resp.error && resp.error.description)
      ? resp.error.description
      : 'Payment failed. Nothing has been charged.';
    if (window.ynToast) window.ynToast(msg, 'error');
  });

  btn.addEventListener('click', function () {
    btn.classList.add('is-loading');
    btn.disabled = true;
    rzp.open();
  });
})();
</script>
<?php endif; ?>

<?php require_once YN_ROOT . '/includes/footer.php'; ?>
