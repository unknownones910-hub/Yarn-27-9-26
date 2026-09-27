<?php
/**
 * Order totals — the single source of truth.
 *
 * PLAN.md rule 6: the cart page, the checkout review step and the payment
 * request must all show the same number, and that number must be computed
 * here, from the database, on every request. Nothing about money is ever
 * taken from the browser.
 *
 * If you find yourself adding up line totals anywhere else in the codebase,
 * call this instead.
 */
declare(strict_types=1);

require_once __DIR__ . '/coupon.php';

/**
 * @param array $opts  coupon_code, gift_wrap, customer_id, lines (to avoid a re-query)
 * @return array{
 *   lines:array, item_count:int, subtotal:float, discount:float, coupon:?array,
 *   coupon_code:?string, coupon_error:?string, free_shipping:bool, shipping:float,
 *   gift_wrap:float, tax:float, grand_total:float, free_gap:float, has_issues:bool
 * }
 */
function pricing_totals(array $opts = []): array
{
    $lines      = $opts['lines'] ?? cart_lines();
    $couponCode = $opts['coupon_code'] ?? ($_SESSION['coupon_code'] ?? null);
    $giftWrap   = (bool) ($opts['gift_wrap'] ?? !empty($_SESSION['gift_wrap']));
    $customerId = $opts['customer_id'] ?? (customer_id() ?: null);

    // ---- subtotal, from live prices and billable quantities -----------
    $subtotal  = 0.0;
    $itemCount = 0;
    foreach ($lines as $l) {
        $subtotal  += (float) $l['line_total'];
        $itemCount += (int) $l['billable_qty'];
    }
    $subtotal = round($subtotal, 2);

    // ---- coupon --------------------------------------------------------
    $discount     = 0.0;
    $coupon       = null;
    $couponError  = null;
    $freeShipping = false;

    if (is_string($couponCode) && $couponCode !== '' && $subtotal > 0) {
        $res = coupon_validate($couponCode, $subtotal, $customerId);
        if ($res['ok']) {
            $coupon       = $res['coupon'];
            $discount     = (float) $res['discount'];
            $freeShipping = (bool) $res['free_shipping'];
        } else {
            $couponError = $res['error'];
            $couponCode  = null;
        }
    } else {
        $couponCode = null;
    }

    // ---- shipping ------------------------------------------------------
    // The free-shipping threshold is tested against the subtotal BEFORE any
    // discount. Testing it after would mean applying a coupon could silently
    // add a delivery charge, which reads as a penalty for using the coupon.
    $flat      = setting_money('ship_flat_rate', 69);
    $threshold = setting_money('ship_free_threshold', 2499);

    if ($subtotal <= 0) {
        $shipping = 0.0;
    } elseif ($freeShipping) {
        $shipping = 0.0;
    } elseif ($threshold > 0 && $subtotal >= $threshold) {
        $shipping = 0.0;
    } else {
        $shipping = $flat;
    }

    $freeGap = ($threshold > 0 && $subtotal > 0 && $subtotal < $threshold)
        ? round($threshold - $subtotal, 2)
        : 0.0;

    // ---- extras --------------------------------------------------------
    $giftWrapFee = ($giftWrap && setting_bool('feat_gift_wrap', true) && $subtotal > 0)
        ? setting_money('gift_wrap_fee', 49)
        : 0.0;

    // GST is off until the store registers. The column stays so switching it
    // on later is a settings change, not a schema change.
    $tax = 0.0;

    $grand = round($subtotal - $discount + $shipping + $giftWrapFee + $tax, 2);
    $grand = max(0.0, $grand);

    return [
        'lines'        => $lines,
        'item_count'   => $itemCount,
        'subtotal'     => $subtotal,
        'discount'     => round($discount, 2),
        'coupon'       => $coupon,
        'coupon_code'  => $coupon ? $coupon['code'] : null,
        'coupon_error' => $couponError,
        'free_shipping'=> $freeShipping,
        'shipping'     => round($shipping, 2),
        'gift_wrap'    => round($giftWrapFee, 2),
        'tax'          => round($tax, 2),
        'grand_total'  => $grand,
        'free_gap'     => $freeGap,
        'has_issues'   => cart_has_issues($lines),
    ];
}

/**
 * Order summary block, shared by the cart page and the checkout review step
 * so the two can never drift apart.
 */
function pricing_summary_rows(array $t): void
{
    ?>
    <dl class="totals">
      <dt>Subtotal <span class="muted">(<?= (int) $t['item_count'] ?> item<?= $t['item_count'] === 1 ? '' : 's' ?>)</span></dt>
      <dd><?= e(money($t['subtotal'])) ?></dd>

      <?php if ($t['discount'] > 0): ?>
        <dt class="is-discount">Discount
          <span class="muted"><?= e((string) $t['coupon_code']) ?></span></dt>
        <dd class="is-discount">−<?= e(money($t['discount'])) ?></dd>
      <?php endif; ?>

      <?php if ($t['gift_wrap'] > 0): ?>
        <dt>Gift wrap</dt><dd><?= e(money($t['gift_wrap'])) ?></dd>
      <?php endif; ?>

      <dt>Delivery</dt>
      <dd>
        <?php if ($t['shipping'] <= 0 && $t['subtotal'] > 0): ?>
          <span class="free-tag">FREE</span>
        <?php else: ?>
          <?= e(money($t['shipping'])) ?>
        <?php endif; ?>
      </dd>

      <dt class="is-total">Total</dt>
      <dd class="is-total"><?= e(money($t['grand_total'])) ?></dd>
    </dl>
    <p class="text-xs muted mt3 mb0">Inclusive of all taxes. No charges are added later.</p>
    <?php
}

/** Free-shipping progress bar — the highest-ROI element on the cart page. */
function pricing_shipping_bar(array $t): void
{
    if ($t['subtotal'] <= 0) {
        return;
    }

    $threshold = setting_money('ship_free_threshold', 2499);
    if ($threshold <= 0) {
        return;
    }

    $done = $t['free_gap'] <= 0;
    $pct  = $done ? 100 : max(4, min(100, ($t['subtotal'] / $threshold) * 100));
    ?>
    <div class="ship-bar <?= $done ? 'is-done' : '' ?>">
      <p class="text-sm mb0">
        <?php if ($done): ?>
          <span class="row" style="gap:6px"><?= icon('check-circle', 16) ?>
            <strong>Free delivery unlocked</strong></span>
        <?php else: ?>
          <strong><?= e(money($t['free_gap'])) ?> away</strong> from free delivery
        <?php endif; ?>
      </p>
      <div class="ship-bar__track">
        <div class="ship-bar__fill" style="width:<?= round($pct) ?>%"></div>
      </div>
    </div>
    <?php
}
