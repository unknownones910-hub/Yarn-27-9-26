<?php
/**
 * A single order: timeline, items, money, and the actions the customer is
 * still allowed to take.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/account-nav.php';
require_once YN_ROOT . '/includes/order.php';
require_once YN_ROOT . '/includes/payments/razorpay.php';

$customer = require_login();
$cid      = (int) $customer['id'];

$orderId = get_int('id');
$order   = $orderId > 0 ? order_by_id($orderId) : null;

if (!$order || (int) $order['customer_id'] !== $cid) {
    http_response_code(404);
    require YN_ROOT . '/404.php';
    exit;
}

$items   = order_items($orderId);
$history = db_all('SELECT * FROM order_status_history WHERE order_id = ? ORDER BY created_at, id',
                  [$orderId]);
$payment = db_row('SELECT method, provider_payment_id, status FROM payments
                   WHERE order_id = ? AND status = "captured" ORDER BY id DESC LIMIT 1', [$orderId]);
$returnReq = db_row('SELECT * FROM return_requests WHERE order_id = ? ORDER BY id DESC LIMIT 1',
                    [$orderId]);

[$statusLabel, $statusMod] = order_status_meta((string) $order['status']);

// What can they still do?
// No cancellation — the shop does not accept it. The handler branch is gone
// too, so removing the button is not the only thing stopping it.
$windowDays  = setting_int('return_window_days', 7);
$deliveredAt = $order['delivered_at'] ? strtotime((string) $order['delivered_at']) : null;
$withinWindow = $deliveredAt !== null && (time() - $deliveredAt) <= ($windowDays * 86400);
$canReturn   = $order['status'] === 'delivered' && $withinWindow && !$returnReq;

$flow = [
    'pending_payment'  => 'Order placed',
    'paid'             => 'Payment received',
    'processing'       => 'Being packed',
    'shipped'          => 'Shipped',
    'out_for_delivery' => 'Out for delivery',
    'delivered'        => 'Delivered',
];
$reached = ['pending_payment' => $order['placed_at']];
foreach ($history as $h) {
    $reached[$h['to_status']] = $h['created_at'];
}

$pageTitle = 'Order ' . $order['order_no'];
$noindex   = true;

require_once YN_ROOT . '/includes/header.php';
?>

<div class="container section-sm">
  <nav class="breadcrumb" aria-label="Breadcrumb">
    <ol>
      <li><a href="<?= e(url('account/')) ?>">Account</a></li>
      <li><a href="<?= e(url('account/orders.php')) ?>">Orders</a></li>
      <li><span aria-current="page"><?= e((string) $order['order_no']) ?></span></li>
    </ol>
  </nav>

  <div class="row row-between mb5">
    <div class="min0">
      <h1 class="mb1"><?= e((string) $order['order_no']) ?></h1>
      <p class="soft mb0">Placed <?= e(fmt_datetime((string) $order['placed_at'])) ?></p>
    </div>
    <span class="pill pill-<?= e($statusMod) ?>" style="flex:none"><?= e($statusLabel) ?></span>
  </div>

  <div class="account-layout">
    <?php account_nav('orders'); ?>

    <div class="min0">

      <?php if ($order['payment_status'] === 'unpaid'
                && !in_array($order['status'], ['cancelled', 'refunded'], true)): ?>
        <div class="alert alert-warn mb4">
          <?= icon('clock', 18) ?>
          <div><strong>Waiting for payment.</strong> Nothing has been charged yet.</div>
        </div>
        <a class="btn btn-block mb5"
           href="<?= e(url('pay.php?order=' . rawurlencode((string) $order['order_no']))) ?>">
          Complete payment · <?= e(money((float) $order['grand_total'])) ?></a>
      <?php endif; ?>

      <?php if ($returnReq): ?>
        <div class="alert alert-info mb4">
          <?= icon('refresh', 18) ?>
          <div>
            <strong>Claim <?= e(str_replace('_', ' ', (string) $returnReq['status'])) ?>.</strong>
            Raised <?= e(fmt_date((string) $returnReq['created_at'])) ?>.
            <?php if (!empty($returnReq['admin_note'])): ?>
              <br><?= e((string) $returnReq['admin_note']) ?>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- timeline -->
      <?php if (!in_array($order['status'], ['cancelled', 'refunded', 'failed'], true)): ?>
        <div class="card mb4">
          <h2 style="font-family:var(--font-sans);font-size:1rem">Progress</h2>
          <ul class="timeline mt4">
            <?php $currentSeen = false;
            foreach ($flow as $key => $label):
                $at   = $reached[$key] ?? null;
                $cls  = '';
                if ($at !== null)      { $cls = 'done'; }
                elseif (!$currentSeen) { $cls = 'current'; $currentSeen = true; } ?>
              <li class="<?= e($cls) ?>">
                <strong><?= e($label) ?></strong><br>
                <span class="text-sm muted"><?= $at ? e(fmt_datetime((string) $at)) : 'Pending' ?></span>
              </li>
            <?php endforeach; ?>
          </ul>

          <?php if (!empty($order['awb'])): ?>
            <div class="alert alert-info mt4">
              <?= icon('truck', 18) ?>
              <div>
                <strong><?= e((string) $order['courier']) ?></strong> · <?= e((string) $order['awb']) ?>
                <?php if (!empty($order['tracking_url'])): ?>
                  <br><a href="<?= eurl((string) $order['tracking_url']) ?>" target="_blank" rel="noopener">
                    Track on the courier site <?= icon('external', 13) ?></a>
                <?php endif; ?>
              </div>
            </div>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="alert alert-warn mb4">
          <?= icon('info', 18) ?>
          <div>
            This order was <?= e(strtolower($statusLabel)) ?>
            <?php if (!empty($order['cancelled_at'])): ?>
              on <?= e(fmt_date((string) $order['cancelled_at'])) ?><?php endif; ?>.
            <?php if (!empty($order['cancel_reason'])): ?>
              <br>Reason: <?= e((string) $order['cancel_reason']) ?>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <!-- items -->
      <div class="card mb4">
        <h2 style="font-family:var(--font-sans);font-size:1rem">Items</h2>
        <div class="mt3">
          <?php foreach ($items as $it): ?>
            <div class="review-line">
              <img src="<?= e($it['image'] ? upload_url($it['image']) : yarn_ball_uri((string) $it['color_hex'])) ?>"
                   alt="" width="48" height="60" loading="lazy">
              <div class="min0">
                <p class="text-sm mb0">
                  <?php if (!empty($it['product_slug'])): ?>
                    <a href="<?= e(url('product/' . $it['product_slug'])) ?>"><?= e($it['product_name']) ?></a>
                  <?php else: ?><?= e($it['product_name']) ?><?php endif; ?>
                </p>
                <p class="text-xs muted mb0">
                  <?= e((string) $it['shade_name']) ?> · Qty <?= (int) $it['qty'] ?>
                  · <?= e($it['sku']) ?>
                </p>
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

      <div class="grid grid-2 mb4">
        <div class="card">
          <h2 style="font-family:var(--font-sans);font-size:1rem">Delivery address</h2>
          <p class="text-sm mb0 mt3"><strong><?= e((string) $order['ship_name']) ?></strong></p>
          <p class="text-sm soft mb0">
            <?= e(implode(', ', array_filter([
                $order['ship_line1'], $order['ship_line2'], $order['ship_landmark'],
                $order['ship_city'], $order['ship_state'] . ' ' . $order['ship_pincode'],
            ]))) ?>
          </p>
          <p class="text-sm soft mb0"><?= e((string) $order['ship_phone']) ?></p>
        </div>

        <div class="card">
          <h2 style="font-family:var(--font-sans);font-size:1rem">Payment</h2>
          <dl class="dl mt3">
            <dt>Status</dt><dd><?= e(ucfirst(str_replace('_', ' ', (string) $order['payment_status']))) ?></dd>
            <?php if ($payment): ?>
              <dt>Method</dt><dd><?= e(rzp_method_label($payment['method'])) ?></dd>
              <dt>Reference</dt><dd class="text-xs"><?= e((string) $payment['provider_payment_id']) ?></dd>
            <?php endif; ?>
            <?php if (!empty($order['paid_at'])): ?>
              <dt>Paid on</dt><dd><?= e(fmt_date((string) $order['paid_at'])) ?></dd>
            <?php endif; ?>
          </dl>
          <?php if ($order['payment_status'] === 'paid'): ?>
            <a class="btn btn-sm btn-soft mt4"
               href="<?= e(url('invoice.php?order=' . rawurlencode((string) $order['order_no']))) ?>">
              <?= icon('download', 15) ?> Download invoice</a>
          <?php endif; ?>
        </div>
      </div>

      <?php if (!empty($order['customer_note'])): ?>
        <div class="card mb4">
          <h2 style="font-family:var(--font-sans);font-size:1rem">Your note</h2>
          <p class="text-sm soft mt3 mb0"><?= nl2br(e((string) $order['customer_note'])) ?></p>
        </div>
      <?php endif; ?>

      <!-- actions -->
      <div class="card">
        <h2 style="font-family:var(--font-sans);font-size:1rem">Need something?</h2>
        <div class="order-card__actions mt4">
          <form method="post" action="<?= e(url('account/order-action.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="reorder">
            <input type="hidden" name="order_id" value="<?= $orderId ?>">
            <button class="btn btn-sm btn-soft" type="submit"><?= icon('refresh', 15) ?> Buy again</button>
          </form>

          <a class="btn btn-sm btn-soft" href="<?= e(url('account/support.php?order=' . $orderId)) ?>">
            <?= icon('chat', 15) ?> Ask about this order</a>

          <?php if ($canReturn): ?>
            <button class="btn btn-sm btn-soft" type="button" data-modal-open="#return-modal">
              <?= icon('refresh', 15) ?> Report a problem</button>
          <?php endif; ?>
        </div>

        <?php if ($order['status'] === 'delivered' && !$withinWindow && !$returnReq): ?>
          <p class="text-xs muted mt4 mb0">
            The <?= $windowDays ?>-day exchange window for this order has closed.
            <a href="<?= e(url('contact.php')) ?>">Contact us</a> if something is wrong — we will still help.
          </p>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- The cancel modal was removed with the policy change. -->

<!-- exchange claim -->
<?php if ($canReturn): ?>
<div class="modal" id="return-modal" role="dialog" aria-modal="true" aria-labelledby="rm-t" aria-hidden="true">
  <div class="modal__panel">
    <form method="post" action="<?= e(url('account/order-action.php')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="return">
      <input type="hidden" name="order_id" value="<?= $orderId ?>">

      <div class="modal__head">
        <h2 id="rm-t">Report a problem</h2>
        <button class="icon-btn" type="button" data-modal-close aria-label="Close"><?= icon('close', 20) ?></button>
      </div>
      <div class="modal__body">
        <p class="text-sm soft">
          We exchange items that arrived wrong, damaged or defective, within
          <?= $windowDays ?> days of delivery. We do not accept returns for change of mind.
          Full terms in our <a href="<?= e(url('returns-and-refunds')) ?>">exchange policy</a>.
        </p>

        <div class="alert alert-warn mb4"><?= icon('info', 18) ?>
          <div><strong>An unedited unboxing video is required.</strong>
            One continuous take, starting with the parcel sealed and the label visible,
            showing the fault clearly. We cannot accept a claim without it.</div>
        </div>

        <div class="field">
          <span class="label">Which items?</span>
          <?php foreach ($items as $it): ?>
            <label class="check">
              <input type="checkbox" name="items[]" value="<?= (int) $it['id'] ?>" checked>
              <span><?= e($it['product_name']) ?>
                <span class="muted">· <?= e((string) $it['shade_name']) ?> × <?= (int) $it['qty'] ?></span></span>
            </label>
          <?php endforeach; ?>
        </div>

        <div class="field">
          <label class="label" for="return_reason">What went wrong? <span class="req">*</span></label>
          <select class="select" id="return_reason" name="reason" required data-autofocus>
            <option value="">Choose one…</option>
            <option value="damaged">Arrived damaged</option>
            <option value="wrong_item">Wrong item or shade sent</option>
            <option value="defective">Defective — knots, split plies, dye transfer</option>
          </select>
          <p class="help">Dye lot variation between balls is normal and is not a defect.</p>
        </div>

        <div class="field">
          <label class="label" for="return_video">Link to your unboxing video <span class="req">*</span></label>
          <input class="input" type="url" id="return_video" name="video_url" required
                 inputmode="url" placeholder="https://drive.google.com/…"
                 aria-describedby="return_video_help">
          <p class="help" id="return_video_help">
            Upload it to Google Drive or YouTube (unlisted), set it so anyone with the link
            can view, then paste the link here.
          </p>
        </div>

        <div class="field mb0">
          <label class="label" for="return_comments">Tell us more <span class="opt">(optional)</span></label>
          <textarea class="textarea" id="return_comments" name="comments" rows="3"
                    placeholder="Where in the video is the fault visible?"></textarea>
        </div>
      </div>
      <div class="modal__foot">
        <button class="btn btn-soft" type="button" data-modal-close>Not now</button>
        <button class="btn" type="submit">Send claim</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require_once YN_ROOT . '/includes/footer.php'; ?>
