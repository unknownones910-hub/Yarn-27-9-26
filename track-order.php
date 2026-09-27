<?php
/**
 * Track an order without signing in.
 *
 * Order number alone is not enough — the email address must match too, so a
 * guessed or sequential order number reveals nothing. Rate limited because
 * this is unauthenticated.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/order.php';

$orderNo = trim(get_str('order', 20));
$email   = '';
$order   = null;
$error   = '';

// A signed-in customer opening their own order skips the email check.
if ($orderNo !== '' && customer_id() > 0) {
    $candidate = order_by_no($orderNo);
    if ($candidate && (int) $candidate['customer_id'] === customer_id()) {
        $order = $candidate;
    }
}

if (is_post()) {
    csrf_verify();

    $orderNo = trim(post_str('order', 20));
    $email   = post_str('email', 190);

    if (!rate_hit('track:' . client_ip(), 15, 3600)) {
        $error = 'Too many lookups from this connection. Please try again later.';
    } elseif ($orderNo === '' || $email === '') {
        $error = 'Enter both your order number and the email you used.';
    } else {
        $key = normalize_gmail($email);
        $candidate = order_by_no($orderNo);

        // One message for every failure mode, so this cannot be used to test
        // whether an order number exists.
        $matches = $candidate && $key !== null
            && normalize_gmail((string) $candidate['email']) === $key;

        if ($matches) {
            $order = $candidate;
        } else {
            $error = 'We could not find an order with those details. Check the order number '
                   . 'and the email address you used at checkout.';
        }
    }
}

$items   = $order ? order_items((int) $order['id']) : [];
$history = $order
    ? db_all('SELECT * FROM order_status_history WHERE order_id = ? ORDER BY created_at, id',
             [(int) $order['id']])
    : [];

$pageTitle = 'Track your order';
$pageDesc  = 'Check where your ' . setting('store_name') . ' order has got to.';
$noindex   = true;

/** The fulfilment path a normal order walks. */
$flow = [
    'pending_payment'  => 'Order placed',
    'paid'             => 'Payment received',
    'processing'       => 'Being packed',
    'shipped'          => 'Shipped',
    'out_for_delivery' => 'Out for delivery',
    'delivered'        => 'Delivered',
];
$reached = [];
foreach ($history as $h) {
    $reached[$h['to_status']] = $h['created_at'];
}
$reached['pending_payment'] = $reached['pending_payment'] ?? ($order['placed_at'] ?? null);

require_once YN_ROOT . '/includes/header.php';
?>

<div class="container section-sm">
  <div style="width:min(680px,100%);margin-inline:auto">

    <h1 class="mb2">Track your order</h1>
    <p class="lede mb6">No account needed — just your order number and the email you used.</p>

    <?php if ($error !== ''): ?>
      <div class="alert alert-error mb5" role="alert"><?= icon('alert', 18) ?><div><?= e($error) ?></div></div>
    <?php endif; ?>

    <?php if (!$order): ?>
      <div class="card card-lift">
        <form method="post" action="<?= e(url('track-order.php')) ?>">
          <?= csrf_field() ?>
          <div class="field">
            <label class="label" for="order">Order number <span class="req">*</span></label>
            <input class="input" id="order" name="order" required autofocus
                   placeholder="<?= e(setting('order_prefix', 'YN')) ?>-2608-0001"
                   value="<?= e($orderNo) ?>" style="text-transform:uppercase">
            <span class="help">It is in your confirmation email, and starts with
              <?= e(setting('order_prefix', 'YN')) ?>-.</span>
          </div>
          <div class="field">
            <label class="label" for="email">Email used at checkout <span class="req">*</span></label>
            <input class="input" type="email" id="email" name="email" required
                   autocomplete="email" placeholder="you@gmail.com" value="<?= e($email) ?>">
          </div>
          <button class="btn btn-block btn-lg" type="submit">Track order</button>
        </form>
      </div>

      <p class="center text-sm muted mt5">
        Signed in? Your orders are in <a href="<?= e(url('account/orders.php')) ?>">your account</a>.
      </p>

    <?php else: ?>
      <?php [$statusLabel, $statusMod] = order_status_meta((string) $order['status']); ?>

      <div class="card mb5">
        <div class="row row-between mb4">
          <div class="min0">
            <p class="text-xs muted mb0">Order</p>
            <h2 style="font-family:var(--font-sans);font-size:1.125rem;margin:0">
              <?= e((string) $order['order_no']) ?></h2>
            <p class="text-xs muted mb0">Placed <?= e(fmt_date((string) $order['placed_at'])) ?></p>
          </div>
          <span class="pill pill-<?= e($statusMod) ?>" style="flex:none"><?= e($statusLabel) ?></span>
        </div>

        <?php if (in_array($order['status'], ['cancelled', 'refunded'], true)): ?>
          <div class="alert alert-warn">
            <?= icon('info', 18) ?>
            <div>This order was <?= e(strtolower($statusLabel)) ?>.
              <?php if (!empty($order['cancel_reason'])): ?>
                <br>Reason: <?= e((string) $order['cancel_reason']) ?>
              <?php endif; ?>
            </div>
          </div>
        <?php else: ?>
          <ul class="timeline">
            <?php
            $currentSeen = false;
            foreach ($flow as $key => $label):
                $at   = $reached[$key] ?? null;
                $done = $at !== null;
                $cls  = '';
                if ($done) {
                    $cls = 'done';
                } elseif (!$currentSeen) {
                    $cls = 'current';
                    $currentSeen = true;
                }
                ?>
              <li class="<?= e($cls) ?>">
                <strong><?= e($label) ?></strong><br>
                <span class="text-sm muted">
                  <?= $at ? e(fmt_datetime((string) $at)) : 'Pending' ?>
                </span>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>

        <?php if (!empty($order['awb'])): ?>
          <div class="alert alert-info mt4">
            <?= icon('truck', 18) ?>
            <div>
              <strong><?= e((string) $order['courier']) ?></strong> ·
              Tracking number <strong><?= e((string) $order['awb']) ?></strong>
              <?php if (!empty($order['tracking_url'])): ?>
                <br><a href="<?= eurl((string) $order['tracking_url']) ?>" target="_blank" rel="noopener">
                  Track on the courier site <?= icon('external', 13) ?></a>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($order['payment_status'] === 'unpaid'
                  && !in_array($order['status'], ['cancelled', 'refunded'], true)): ?>
          <a class="btn btn-block mt4"
             href="<?= e(url('pay.php?order=' . rawurlencode((string) $order['order_no']))) ?>">
            Complete payment</a>
        <?php endif; ?>
      </div>

      <div class="card mb5">
        <h2 style="font-family:var(--font-sans);font-size:1rem">Items</h2>
        <div class="mt3">
          <?php foreach ($items as $it): ?>
            <div class="review-line">
              <img src="<?= e($it['image'] ? upload_url($it['image']) : yarn_ball_uri((string) $it['color_hex'])) ?>"
                   alt="" width="44" height="55" loading="lazy">
              <div class="min0">
                <p class="text-sm mb0">
                  <?php if (!empty($it['product_slug'])): ?>
                    <a href="<?= e(url('product/' . $it['product_slug'])) ?>"><?= e($it['product_name']) ?></a>
                  <?php else: ?>
                    <?= e($it['product_name']) ?>
                  <?php endif; ?>
                </p>
                <p class="text-xs muted mb0"><?= e((string) $it['shade_name']) ?> × <?= (int) $it['qty'] ?></p>
              </div>
              <strong class="text-sm"><?= e(money((float) $it['line_total'])) ?></strong>
            </div>
          <?php endforeach; ?>
        </div>

        <dl class="totals mt4">
          <dt class="is-total">Total</dt>
          <dd class="is-total"><?= e(money((float) $order['grand_total'])) ?></dd>
        </dl>
      </div>

      <div class="card">
        <h2 style="font-family:var(--font-sans);font-size:1rem">Delivering to</h2>
        <p class="text-sm mb0 mt3"><strong><?= e((string) $order['ship_name']) ?></strong>
          · <?= e((string) $order['ship_phone']) ?></p>
        <p class="text-sm soft mb0">
          <?= e(implode(', ', array_filter([
              $order['ship_line1'], $order['ship_line2'], $order['ship_landmark'],
              $order['ship_city'], $order['ship_state'] . ' ' . $order['ship_pincode'],
          ]))) ?>
        </p>
      </div>

      <div class="row mt5" style="justify-content:center">
        <?php if ($order['payment_status'] === 'paid'): ?>
          <a class="btn btn-soft" href="<?= e(url('invoice.php?order=' . rawurlencode((string) $order['order_no']))) ?>">
            <?= icon('download', 16) ?> Download invoice</a>
        <?php endif; ?>
        <a class="btn btn-soft" href="<?= e(url('contact.php')) ?>">Need help?</a>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once YN_ROOT . '/includes/footer.php'; ?>
