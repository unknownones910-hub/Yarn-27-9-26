<?php
/**
 * Cart.
 *
 * Every price and stock figure here is re-read from the catalog on load, so a
 * shade that sold out since it was added is flagged and blocks checkout rather
 * than failing at the payment step.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/pricing.php';
require_once YN_ROOT . '/includes/catalog.php';
require_once YN_ROOT . '/includes/components/product-card.php';

$lines  = cart_lines();
$saved  = cart_lines(true);
$totals = pricing_totals(['lines' => $lines]);

// A coupon that has become invalid (expired, min-order no longer met) is
// dropped from the session rather than silently ignored.
if ($totals['coupon_error'] !== null) {
    unset($_SESSION['coupon_code']);
}

$pageTitle = 'Your cart';
$noindex   = true;

require_once YN_ROOT . '/includes/header.php';
?>

<div class="container section-sm">

  <h1 class="mb5">Your cart<?php if ($totals['item_count'] > 0): ?>
    <span class="muted" style="font-family:var(--font-sans);font-size:1rem;font-weight:400">
      · <?= (int) $totals['item_count'] ?> item<?= $totals['item_count'] === 1 ? '' : 's' ?></span>
  <?php endif; ?></h1>

  <?php if (!$lines): ?>

    <div class="empty">
      <div class="empty__art"><?= icon('cart', 40) ?></div>
      <h2>Your cart is feeling a bit unravelled</h2>
      <p style="max-width:42ch">Nothing in here yet. Have a look at what people are buying this week.</p>
      <a class="btn mt4" href="<?= e(url('shop.php')) ?>">Shop all yarn</a>
    </div>

    <?php $suggest = featured_products(4); ?>
    <?php if ($suggest): ?>
      <section class="section-sm">
        <div class="sec-head"><div class="min0"><h2>Popular right now</h2></div></div>
        <?php product_grid($suggest); ?>
      </section>
    <?php endif; ?>

  <?php else: ?>

    <?php if ($totals['coupon_error'] !== null): ?>
      <div class="alert alert-warn mb5"><?= icon('alert', 18) ?>
        <div><?= e($totals['coupon_error']) ?></div></div>
    <?php endif; ?>

    <?php if ($totals['has_issues']): ?>
      <div class="alert alert-error mb5"><?= icon('alert', 18) ?>
        <div>Some items need attention before you can check out — see the highlighted lines below.</div></div>
    <?php endif; ?>

    <div class="cart-layout">

      <!-- ============================================ line items -->
      <div class="min0">
        <?php foreach ($lines as $l): ?>
          <?php
          $issue = !$l['available'] || $l['over_stock'];
          $vid   = (int) $l['variant_id'];
          ?>
          <div class="cart-line <?= $issue ? 'has-issue' : '' ?>">
            <a class="cart-line__media" href="<?= e(url('product/' . $l['slug'])) ?>">
              <img src="<?= e($l['image_src']) ?>" alt="" width="96" height="120" loading="lazy">
            </a>

            <div class="cart-line__info min0">
              <h2 class="cart-line__name">
                <a href="<?= e(url('product/' . $l['slug'])) ?>"><?= e($l['name']) ?></a>
              </h2>
              <p class="cart-line__meta">
                <span class="swatch-dot" style="background:<?= e($l['color_hex']) ?>"></span>
                <?= e($l['shade_name']) ?>
                <?php if (!empty($l['dye_lot'])): ?>
                  <span class="muted">· lot <?= e($l['dye_lot']) ?></span>
                <?php endif; ?>
              </p>
              <p class="cart-line__meta muted"><?= e(product_meta_line($l)) ?></p>

              <?php if ($l['discontinued']): ?>
                <p class="text-xs" style="color:var(--danger)">
                  <?= icon('alert', 13) ?> No longer available — please remove this line.</p>
              <?php elseif (!$l['available']): ?>
                <p class="text-xs" style="color:var(--danger)">
                  <?= icon('alert', 13) ?> Out of stock — please remove this line.</p>
              <?php elseif ($l['over_stock']): ?>
                <p class="text-xs" style="color:var(--warning)">
                  <?= icon('alert', 13) ?> Only <?= (int) $l['stock'] ?> left; quantity will be reduced.</p>
              <?php elseif ((int) $l['stock'] <= 5): ?>
                <p class="text-xs" style="color:var(--warning)">Only <?= (int) $l['stock'] ?> left</p>
              <?php endif; ?>

              <div class="cart-line__actions">
                <form method="post" action="<?= e(url('cart-update.php')) ?>" class="row gap2" data-cart-form>
                  <?= csrf_field() ?>
                  <input type="hidden" name="variant_id" value="<?= $vid ?>">
                  <input type="hidden" name="action" value="set">
                  <div class="qty">
                    <button type="button" data-step="down" aria-label="Decrease quantity">&minus;</button>
                    <input type="number" name="qty" value="<?= (int) $l['qty'] ?>" min="0"
                           max="<?= max(1, min((int) $l['stock'], 20)) ?>"
                           aria-label="Quantity of <?= e($l['name']) ?>" data-cart-qty>
                    <button type="button" data-step="up" aria-label="Increase quantity">+</button>
                  </div>
                  <noscript><button class="btn btn-sm btn-soft" type="submit">Update</button></noscript>
                </form>

                <form method="post" action="<?= e(url('cart-update.php')) ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="variant_id" value="<?= $vid ?>">
                  <input type="hidden" name="action" value="save">
                  <button class="btn-link" type="submit"><?= icon('heart', 14) ?> Save for later</button>
                </form>

                <form method="post" action="<?= e(url('cart-update.php')) ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="variant_id" value="<?= $vid ?>">
                  <input type="hidden" name="action" value="remove">
                  <button class="btn-link is-danger" type="submit"><?= icon('trash', 14) ?> Remove</button>
                </form>
              </div>
            </div>

            <div class="cart-line__price">
              <?php if (!$l['available']): ?>
                <strong class="muted">—</strong>
                <span class="text-xs muted">not charged</span>
              <?php else: ?>
                <?php /* Always the billable amount, so the line agrees with
                          the summary even when stock capped the quantity. */ ?>
                <strong><?= e(money((float) $l['line_total'])) ?></strong>
                <?php if ((int) $l['billable_qty'] > 1): ?>
                  <span class="text-xs muted"><?= e(money((float) $l['unit_price'])) ?> each</span>
                <?php endif; ?>
                <?php if ($l['over_stock']): ?>
                  <span class="text-xs" style="color:var(--warning)">for <?= (int) $l['billable_qty'] ?> of <?= (int) $l['qty'] ?></span>
                <?php endif; ?>
              <?php endif; ?>
            </div>
          </div>
        <?php endforeach; ?>

        <p class="mt5">
          <a class="text-sm" href="<?= e(url('shop.php')) ?>">
            <?= icon('arrow-left', 14) ?> Continue shopping</a>
        </p>
      </div>

      <!-- =============================================== summary -->
      <aside class="cart-summary">
        <div class="card">
          <h2 style="font-family:var(--font-sans);font-size:1.0625rem">Order summary</h2>

          <div class="mt4"><?php pricing_shipping_bar($totals); ?></div>

          <!-- coupon -->
          <div class="mt4">
            <?php if ($totals['coupon']): ?>
              <div class="coupon-applied">
                <div class="min0">
                  <strong><?= e($totals['coupon']['code']) ?></strong>
                  <span class="text-xs muted">· <?= e(coupon_label($totals['coupon'])) ?></span>
                </div>
                <form method="post" action="<?= e(url('coupon.php')) ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="remove">
                  <button class="btn-link is-danger" type="submit">Remove</button>
                </form>
              </div>
            <?php else: ?>
              <form method="post" action="<?= e(url('coupon.php')) ?>" class="row gap2">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="apply">
                <label class="sr-only" for="coupon">Discount code</label>
                <input class="input flex1" type="text" id="coupon" name="code"
                       placeholder="Discount code" autocomplete="off"
                       style="text-transform:uppercase">
                <button class="btn btn-soft" type="submit" style="flex:none">Apply</button>
              </form>
            <?php endif; ?>
          </div>

          <?php if (setting_bool('feat_gift_wrap', true)): ?>
            <form method="post" action="<?= e(url('cart-update.php')) ?>" class="mt3">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="giftwrap">
              <label class="check">
                <input type="checkbox" name="gift_wrap" value="1"
                       <?= !empty($_SESSION['gift_wrap']) ? 'checked' : '' ?>
                       onchange="this.form.submit()">
                <span>Add gift wrap
                  (<?= e(money(setting_money('gift_wrap_fee', 49))) ?>)</span>
              </label>
              <noscript><button class="btn btn-sm btn-soft" type="submit">Update</button></noscript>
            </form>
          <?php endif; ?>

          <div class="mt4"><?php pricing_summary_rows($totals); ?></div>

          <?php if ($totals['has_issues']): ?>
            <button class="btn btn-block btn-lg mt4" disabled>Resolve items to continue</button>
          <?php else: ?>
            <a class="btn btn-block btn-lg mt4" href="<?= e(url('checkout.php')) ?>">
              Proceed to checkout</a>
          <?php endif; ?>

          <div class="row gap3 mt4" style="justify-content:center">
            <span class="text-xs muted row" style="gap:5px"><?= icon('lock', 13) ?> Secure payment</span>
            <span class="text-xs muted row" style="gap:5px"><?= icon('refresh', 13) ?>
              <?= setting_int('return_window_days', 7) ?>-day exchange</span>
          </div>
        </div>
      </aside>
    </div>

    <!-- ======================================== saved for later -->
    <?php if ($saved): ?>
      <section class="section-sm">
        <div class="sec-head"><div class="min0"><h2>Saved for later</h2></div></div>
        <?php foreach ($saved as $l): ?>
          <div class="cart-line">
            <a class="cart-line__media" href="<?= e(url('product/' . $l['slug'])) ?>">
              <img src="<?= e($l['image_src']) ?>" alt="" width="96" height="120" loading="lazy">
            </a>
            <div class="cart-line__info min0">
              <h2 class="cart-line__name">
                <a href="<?= e(url('product/' . $l['slug'])) ?>"><?= e($l['name']) ?></a>
              </h2>
              <p class="cart-line__meta">
                <span class="swatch-dot" style="background:<?= e($l['color_hex']) ?>"></span>
                <?= e($l['shade_name']) ?>
              </p>
              <div class="cart-line__actions">
                <form method="post" action="<?= e(url('cart-update.php')) ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="variant_id" value="<?= (int) $l['variant_id'] ?>">
                  <input type="hidden" name="action" value="unsave">
                  <button class="btn-link" type="submit"
                          <?= $l['available'] ? '' : 'disabled' ?>>
                    <?= icon('cart', 14) ?> Move to cart</button>
                </form>
                <form method="post" action="<?= e(url('cart-update.php')) ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="variant_id" value="<?= (int) $l['variant_id'] ?>">
                  <input type="hidden" name="action" value="remove">
                  <button class="btn-link is-danger" type="submit">Remove</button>
                </form>
              </div>
              <?php if (!$l['available']): ?>
                <p class="text-xs muted">Out of stock right now</p>
              <?php endif; ?>
            </div>
            <div class="cart-line__price">
              <strong><?= e(money((float) $l['unit_price'])) ?></strong>
            </div>
          </div>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>

  <?php endif; ?>
</div>

<?php require_once YN_ROOT . '/includes/footer.php'; ?>
