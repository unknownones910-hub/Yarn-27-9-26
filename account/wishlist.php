<?php
/**
 * Wishlist.
 *
 * Saved at shade level, not product level — "I want Terracotta Rose", not
 * "I want that yarn in some colour". Out-of-stock shades get a back-in-stock
 * toggle rather than being hidden.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/account-nav.php';
require_once YN_ROOT . '/includes/catalog.php';

$customer = require_login();
$cid      = (int) $customer['id'];

if (is_post()) {
    csrf_verify();
    $action    = post_str('action', 20);
    $variantId = post_int('variant_id');

    if ($action === 'remove') {
        db_exec('DELETE FROM wishlists WHERE customer_id = ? AND variant_id = ?', [$cid, $variantId]);
        flash('Removed from your wishlist.', 'success');

    } elseif ($action === 'notify') {
        db_exec('UPDATE wishlists SET notify_restock = ? WHERE customer_id = ? AND variant_id = ?',
                [empty($_POST['notify']) ? 0 : 1, $cid, $variantId]);
        flash(empty($_POST['notify'])
            ? 'We will not email you about that shade.'
            : 'We will email you the moment it is back.', 'success');

    } elseif ($action === 'tocart') {
        $res = cart_add($variantId, 1);
        if ($res['ok']) {
            db_exec('DELETE FROM wishlists WHERE customer_id = ? AND variant_id = ?', [$cid, $variantId]);
            flash('Moved to your cart.', 'success');
            redirect('cart.php');
        }
        flash($res['error'] ?? 'Could not add that to your cart.', 'error');
    }

    redirect('account/wishlist.php');
}

$rows = db_all(
    'SELECT w.variant_id, w.notify_restock, w.created_at,
            v.sku, v.shade_name, v.color_hex, v.image AS variant_image, v.stock,
            v.price_override, v.is_active AS v_active,
            p.id AS product_id, p.slug, p.name, p.price, p.mrp, p.fiber,
            p.yarn_weight, p.ball_weight_g, p.is_active AS p_active
     FROM wishlists w
     JOIN product_variants v ON v.id = w.variant_id
     JOIN products p ON p.id = v.product_id
     WHERE w.customer_id = ?
     ORDER BY w.created_at DESC',
    [$cid]
);

$pageTitle = 'My wishlist';
$noindex   = true;

require_once YN_ROOT . '/includes/header.php';
?>

<div class="container section-sm">
  <h1 class="mb5">Wishlist</h1>

  <div class="account-layout">
    <?php account_nav('wishlist'); ?>

    <div class="min0">
      <?php if (!$rows): ?>
        <div class="card center" style="padding-block:var(--s7)">
          <div class="empty__art" style="margin-bottom:var(--s4)"><?= icon('heart', 36) ?></div>
          <h2 style="font-family:var(--font-sans);font-size:1.0625rem">Nothing saved yet</h2>
          <p class="text-sm soft" style="margin-inline:auto;max-width:40ch">
            Tap the heart on any shade to keep it here. We will tell you if it goes on sale
            or comes back in stock.</p>
          <a class="btn mt4" href="<?= e(url('shop.php')) ?>">Browse yarn</a>
        </div>

      <?php else: ?>
        <p class="text-sm muted mb4"><?= count($rows) ?>
          saved shade<?= count($rows) === 1 ? '' : 's' ?></p>

        <div class="grid-products">
          <?php foreach ($rows as $r): ?>
            <?php
            $price     = ($r['price_override'] !== null && $r['price_override'] !== '')
                ? (float) $r['price_override'] : (float) $r['price'];
            $available = (int) $r['v_active'] === 1 && (int) $r['p_active'] === 1 && (int) $r['stock'] > 0;
            $img       = !empty($r['variant_image'])
                ? upload_url($r['variant_image']) : yarn_ball_uri((string) $r['color_hex']);
            ?>
            <article class="product-card">
              <div class="product-card__media">
                <a href="<?= e(url('product/' . $r['slug'] . '?shade=' . rawurlencode((string) $r['sku']))) ?>">
                  <img src="<?= e($img) ?>" alt="" width="400" height="500" loading="lazy">
                </a>
                <?php if (!$available): ?>
                  <div class="badges"><span class="badge badge-out">Out of stock</span></div>
                <?php elseif ((int) $r['stock'] <= 5): ?>
                  <div class="badges"><span class="badge badge-low">Only <?= (int) $r['stock'] ?> left</span></div>
                <?php endif; ?>
              </div>

              <div class="product-card__body">
                <h2 class="product-card__name">
                  <a href="<?= e(url('product/' . $r['slug'] . '?shade=' . rawurlencode((string) $r['sku']))) ?>">
                    <?= e($r['name']) ?></a>
                </h2>
                <p class="product-card__meta">
                  <span class="swatch-dot" style="background:<?= e($r['color_hex']) ?>"></span>
                  <?= e($r['shade_name']) ?>
                </p>
                <div class="price">
                  <span class="price__now"><?= e(money($price)) ?></span>
                </div>

                <div class="wish-remove stack-sm">
                  <?php if ($available): ?>
                    <form method="post" action="<?= e(url('account/wishlist.php')) ?>">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="tocart">
                      <input type="hidden" name="variant_id" value="<?= (int) $r['variant_id'] ?>">
                      <button class="btn btn-sm btn-block" type="submit">Move to cart</button>
                    </form>
                  <?php else: ?>
                    <form method="post" action="<?= e(url('account/wishlist.php')) ?>">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="notify">
                      <input type="hidden" name="variant_id" value="<?= (int) $r['variant_id'] ?>">
                      <label class="check" style="min-height:auto">
                        <input type="checkbox" name="notify" value="1"
                               <?= (int) $r['notify_restock'] === 1 ? 'checked' : '' ?>
                               onchange="this.form.submit()">
                        <span class="text-xs">Email me when it is back</span>
                      </label>
                      <noscript><button class="btn btn-sm btn-soft" type="submit">Save</button></noscript>
                    </form>
                  <?php endif; ?>

                  <form method="post" action="<?= e(url('account/wishlist.php')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="remove">
                    <input type="hidden" name="variant_id" value="<?= (int) $r['variant_id'] ?>">
                    <button class="btn-link is-danger" type="submit"><?= icon('trash', 13) ?> Remove</button>
                  </form>
                </div>
              </div>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require_once YN_ROOT . '/includes/footer.php'; ?>
