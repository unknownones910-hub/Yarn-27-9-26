<?php
/**
 * Product card.
 *
 * Expects a row from catalog_query() / related_products() — i.e. one that has
 * already been through catalog_attach_variants(), so `variants`, `stock_total`
 * and `shade_count` are present.
 *
 * The whole card is a link; the wishlist heart is a nested button, so its
 * click handler must stopPropagation (main.js does this via delegation).
 */
declare(strict_types=1);

function product_card(array $p, array $opts = []): void
{
    $lazy      = $opts['lazy'] ?? true;
    $showWish  = $opts['wishlist'] ?? true;

    $url      = url('product/' . $p['slug']);
    $price    = (float) $p['price'];
    $mrp      = (float) ($p['mrp'] ?? 0);
    $off      = discount_pct($mrp, $price);
    $variants = $p['variants'] ?? [];
    $stock    = (int) ($p['stock_total'] ?? 0);
    $inStock  = $stock > 0;
    $lowStock = $inStock && $stock <= 5;
    $meta     = product_meta_line($p);
    ?>
    <article class="product-card">
      <div class="product-card__media">
        <a href="<?= e($url) ?>" tabindex="-1" aria-hidden="true">
          <img src="<?= e(product_image_src($p)) ?>"
               alt="" width="400" height="500"
               <?= $lazy ? 'loading="lazy" decoding="async"' : '' ?>>
        </a>

        <div class="badges">
          <?php if (!$inStock): ?>
            <span class="badge badge-out">Out of stock</span>
          <?php else: ?>
            <?php if (!empty($p['is_new'])): ?><span class="badge badge-new">New</span><?php endif; ?>
            <?php if ($off > 0): ?><span class="badge badge-sale"><?= $off ?>% off</span><?php endif; ?>
            <?php if ($lowStock): ?><span class="badge badge-low">Only <?= $stock ?> left</span><?php endif; ?>
          <?php endif; ?>
        </div>

        <?php if ($showWish && $variants): ?>
          <?php // The wishlist is shade-level, so a card saves its first shade.
                // The PDP saves whichever shade is actually selected. ?>
          <button class="wish-btn" type="button" data-wish="<?= (int) $variants[0]['id'] ?>"
                  aria-label="Save <?= e($p['name']) ?> to wishlist">
            <?= icon('heart', 18) ?>
          </button>
        <?php endif; ?>
      </div>

      <div class="product-card__body">
        <h3 class="product-card__name"><a href="<?= e($url) ?>"><?= e($p['name']) ?></a></h3>

        <?php if ($meta !== ''): ?>
          <p class="product-card__meta" title="<?= e($meta) ?>"><?= e($meta) ?></p>
        <?php endif; ?>

        <?php if ($variants): ?>
          <div class="swatch-dots mb3">
            <?php foreach (array_slice($variants, 0, 5) as $v): ?>
              <span class="swatch-dot" style="background:<?= e($v['color_hex']) ?>"
                    title="<?= e($v['shade_name']) ?>"></span>
            <?php endforeach; ?>
            <?php if (count($variants) > 5): ?>
              <span class="swatch-more">+<?= count($variants) - 5 ?></span>
            <?php endif; ?>
          </div>
        <?php endif; ?>

        <div class="price">
          <span class="price__now"><?= e(money($price)) ?></span>
          <?php if ($off > 0): ?>
            <span class="price__mrp"><?= e(money($mrp)) ?></span>
          <?php endif; ?>
        </div>

        <?php if ((int) $p['rating_count'] > 0): ?>
          <div class="mt2"><?= stars_html((float) $p['rating_avg'], (int) $p['rating_count']) ?></div>
        <?php endif; ?>
      </div>
    </article>
    <?php
}

/** Render a grid of cards, or an empty state. */
function product_grid(array $rows, array $opts = []): void
{
    if (!$rows) {
        return;
    }
    echo '<div class="grid-products">';
    foreach ($rows as $i => $p) {
        product_card($p, $opts + ['lazy' => $i >= 4]);
    }
    echo '</div>';
}
