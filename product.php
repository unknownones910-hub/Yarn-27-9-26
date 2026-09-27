<?php
/**
 * Product detail (PDP).
 *
 * The shade selector is the spine of this page: choosing a shade updates the
 * gallery, the price, the stock line and the hidden variant id in one go. It
 * works without JavaScript too — each swatch is a link carrying ?shade=SKU,
 * so the server can render the correct state on first paint.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/catalog.php';
require_once YN_ROOT . '/includes/components/product-card.php';

$slug    = get_str('slug', 160);
$product = $slug !== '' ? product_by_slug($slug) : null;

if (!$product) {
    http_response_code(404);
    require YN_ROOT . '/404.php';
    exit;
}

$pid      = (int) $product['id'];
$variants = product_variants($pid);

// ---- Which shade is selected? ----------------------------------------
$wantSku  = get_str('shade', 50);
$selected = null;

if ($wantSku !== '') {
    foreach ($variants as $v) {
        if (strcasecmp((string) $v['sku'], $wantSku) === 0) {
            $selected = $v;
            break;
        }
    }
}
if (!$selected) {
    // Default to the first shade that can actually be bought.
    foreach ($variants as $v) {
        if ((int) $v['stock'] > 0) {
            $selected = $v;
            break;
        }
    }
}
$selected = $selected ?: ($variants[0] ?? null);

$price      = variant_price($product, $selected);
$mrp        = (float) $product['mrp'];
$off        = discount_pct($mrp, $price);
$stock      = $selected ? (int) $selected['stock'] : 0;
$lowAt      = $selected ? (int) $selected['low_stock_at'] : 5;
$inStock    = $stock > 0;
$stockTotal = array_sum(array_map(static fn($v) => max(0, (int) $v['stock']), $variants));

// ---- Gallery ----------------------------------------------------------
// Exactly one entry per shade, always — the gallery IS the shade list. There
// is no separate product photo set: it used to exist, and because it was
// checked first, uploading a single lifestyle shot hid every shade photo and
// left the shade switcher showing drawn placeholders.
//
// A shade with no photo yet falls back to a tinted yarn ball, so switching
// shades always changes the picture even before the photography is done.
$gallery = [];
foreach ($variants as $v) {
    $gallery[] = [
        'src'        => !empty($v['image'])
            ? upload_url($v['image'])
            : yarn_ball_uri((string) $v['color_hex']),
        'alt'        => $product['name'] . ' in ' . $v['shade_name'],
        'variant_id' => (int) $v['id'],
    ];
}
if (!$gallery) {
    $gallery[] = ['src' => asset('img/placeholder-yarn.svg'), 'alt' => $product['name'], 'variant_id' => null];
}

// Show the selected shade's image first.
$mainIndex = 0;
if ($selected) {
    foreach ($gallery as $i => $g) {
        if ($g['variant_id'] === (int) $selected['id']) {
            $mainIndex = $i;
            break;
        }
    }
}

$category = product_primary_category($product);
$related  = related_products($product, 4);
$addons   = accessory_products(4, $pid);

$reviews = setting_bool('feat_reviews', true)
    ? db_all(
        'SELECT r.*, c.full_name FROM reviews r
         LEFT JOIN customers c ON c.id = r.customer_id
         WHERE r.product_id = ? AND r.status = "approved"
         ORDER BY r.helpful_count DESC, r.created_at DESC
         LIMIT 6',
        [$pid]
    )
    : [];

// ---- SEO --------------------------------------------------------------
$pageTitle = $product['meta_title'] ?: ($product['name'] . ' — ' . product_meta_line($product));
$pageDesc  = $product['meta_description'] ?: str_excerpt($product['short_desc'] ?: $product['long_desc'], 155);
$canonical = '/product/' . $product['slug'];
$ogImage   = $gallery[$mainIndex]['src'];
// A data-URI placeholder is useless as an OG image.
if (str_starts_with($ogImage, 'data:')) {
    $ogImage = asset('img/hero-yarn.svg');
}

$crumbs = [['Home', url('')], ['Shop', url('shop.php')]];
if ($category) {
    $crumbs[] = [$category['name'], url('category/' . $category['slug'])];
}
$crumbs[] = [$product['name'], null];

$offer = [
    '@type'         => 'Offer',
    'url'           => abs_url($canonical),
    'price'         => number_format($price, 2, '.', ''),
    'priceCurrency' => 'INR',
    'availability'  => $stockTotal > 0
        ? 'https://schema.org/InStock'
        : 'https://schema.org/OutOfStock',
    'itemCondition' => 'https://schema.org/NewCondition',
];

$productLd = [
    '@context'    => 'https://schema.org',
    '@type'       => 'Product',
    'name'        => $product['name'],
    'description' => str_excerpt($product['short_desc'] ?: $product['long_desc'], 300),
    'sku'         => $product['sku_base'],
    'offers'      => $offer,
];
if (!empty($product['brand'])) {
    $productLd['brand'] = ['@type' => 'Brand', 'name' => $product['brand']];
}
if ((int) $product['rating_count'] > 0) {
    $productLd['aggregateRating'] = [
        '@type'       => 'AggregateRating',
        'ratingValue' => number_format((float) $product['rating_avg'], 1),
        'reviewCount' => (int) $product['rating_count'],
    ];
}

$jsonLd = [$productLd, breadcrumb_ld($crumbs)];

require_once YN_ROOT . '/includes/header.php';
?>

<div class="container">

  <?= breadcrumb_nav($crumbs) ?>

  <div class="pdp" data-pdp>

    <!-- ===================================================== gallery -->
    <div class="pdp__gallery">
      <div class="gallery__main<?= count($gallery) > 1 ? ' gallery__main--multi' : '' ?>"
           data-gallery-track
           role="region"
           aria-label="Product images"
           tabindex="0">
        <?php foreach ($gallery as $i => $g): ?>
          <div class="gallery__slide <?= $i === $mainIndex ? 'is-on' : '' ?>"
               data-gallery-slide
               <?= $g['variant_id'] ? 'data-variant="' . (int) $g['variant_id'] . '"' : '' ?>>
            <img src="<?= e($g['src']) ?>"
                 alt="<?= e($g['alt']) ?>"
                 width="800" height="800"
                 <?= $i === $mainIndex ? 'id="pdp-main-image" fetchpriority="high"' : 'loading="lazy"' ?>>
          </div>
        <?php endforeach; ?>
      </div>

      <?php if (count($gallery) > 1): ?>
        <div class="gallery__thumbs" role="group" aria-label="Product thumbnails">
          <?php foreach ($gallery as $i => $g): ?>
            <button type="button"
                    class="gallery__thumb <?= $i === $mainIndex ? 'is-on' : '' ?>"
                    data-gallery-thumb
                    data-src="<?= e($g['src']) ?>"
                    data-alt="<?= e($g['alt']) ?>"
                    <?= $g['variant_id'] ? 'data-variant="' . (int) $g['variant_id'] . '"' : '' ?>
                    aria-label="View <?= e($g['alt']) ?>">
              <img src="<?= e($g['src']) ?>" alt="" width="64" height="64" loading="lazy">
            </button>
          <?php endforeach; ?>
        </div>
        <div class="gallery__dots" aria-hidden="true">
          <?php foreach ($gallery as $i => $g): ?>
            <button type="button"
                    class="gallery__dot <?= $i === $mainIndex ? 'is-on' : '' ?>"
                    data-gallery-dot
                    tabindex="-1"
                    <?= $g['variant_id'] ? 'data-variant="' . (int) $g['variant_id'] . '"' : '' ?>
                    aria-current="<?= $i === $mainIndex ? 'true' : 'false' ?>"></button>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

    <!-- ===================================================== buy box -->
    <div class="min0" data-buy-anchor>
      <?php if (!empty($product['brand'])): ?>
        <p class="pdp__brand"><?= e($product['brand']) ?></p>
      <?php endif; ?>

      <h1 class="pdp__title"><?= e($product['name']) ?></h1>

      <?php if (!empty($product['subtitle'])): ?>
        <p class="pdp__subtitle"><?= e($product['subtitle']) ?></p>
      <?php endif; ?>

      <?php if ((int) $product['rating_count'] > 0): ?>
        <a href="#reviews" class="text-sm">
          <?= stars_html((float) $product['rating_avg'], (int) $product['rating_count']) ?>
        </a>
      <?php endif; ?>

      <div class="pdp__price price price-lg">
        <span class="price__now" data-price-now><?= e(money($price)) ?></span>
        <?php if ($off > 0): ?>
          <span class="price__mrp"><?= e(money($mrp)) ?></span>
          <span class="price__off"><?= $off ?>% off</span>
        <?php endif; ?>
      </div>
      <p class="pdp__tax">Inclusive of all taxes</p>

      <form method="post" action="<?= e(url('cart-add.php')) ?>" data-add-form class="mt5">
        <?= csrf_field() ?>
        <input type="hidden" name="variant_id" value="<?= $selected ? (int) $selected['id'] : '' ?>" data-variant-input>
        <input type="hidden" name="redirect" value="<?= e($canonical) ?>">

        <?php if ($variants): ?>
          <div class="shade-head">
            <span class="label">Shade</span>
            <span class="shade-name" data-shade-name>
              <?= $selected ? e($selected['shade_name']) : '' ?>
              <?php if ($selected && !empty($selected['shade_code'])): ?>
                <span class="muted">· <?= e($selected['shade_code']) ?></span>
              <?php endif; ?>
            </span>
          </div>

          <div class="swatches" role="group" aria-label="Choose a shade">
            <?php foreach ($variants as $v): ?>
              <?php
              $isSel  = $selected && (int) $v['id'] === (int) $selected['id'];
              $vStock = (int) $v['stock'];
              $vPrice = variant_price($product, $v);
              ?>
              <a class="swatch <?= $vStock < 1 ? 'is-out' : '' ?>"
                 href="<?= e(url('product/' . $product['slug']) . '?shade=' . rawurlencode((string) $v['sku'])) ?>"
                 style="background:<?= e($v['color_hex']) ?>"
                 data-shade
                 data-variant="<?= (int) $v['id'] ?>"
                 data-sku="<?= e($v['sku']) ?>"
                 data-name="<?= e($v['shade_name']) ?>"
                 data-code="<?= e((string) $v['shade_code']) ?>"
                 data-stock="<?= $vStock ?>"
                 data-low="<?= (int) $v['low_stock_at'] ?>"
                 data-price="<?= e(money($vPrice)) ?>"
                 data-img="<?= e(!empty($v['image']) ? upload_url($v['image']) : yarn_ball_uri((string) $v['color_hex'])) ?>"
                 data-dyelot="<?= e((string) $v['dye_lot']) ?>"
                 role="button"
                 aria-pressed="<?= $isSel ? 'true' : 'false' ?>"
                 aria-label="<?= e($v['shade_name']) ?><?= $vStock < 1 ? ' — out of stock' : '' ?>"></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>

        <p class="mt4">
          <span class="stock <?= !$inStock ? 'stock-out' : ($stock <= $lowAt ? 'stock-low' : 'stock-in') ?>"
                data-stock-line
                data-dispatch="<?= e(setting('dispatch_days', '1-2')) ?>">
            <?php if (!$inStock): ?>
              Out of stock in this shade
            <?php elseif ($stock <= $lowAt): ?>
              Only <?= $stock ?> ball<?= $stock === 1 ? '' : 's' ?> left
            <?php else: ?>
              In stock · ships in <?= e(setting('dispatch_days', '1-2')) ?> business days
            <?php endif; ?>
          </span>
        </p>

        <div class="qty mt5">
          <button type="button" data-step="down" aria-label="Decrease quantity">&minus;</button>
          <input type="number" name="qty" value="1" min="1"
                 max="<?= max(1, min($stock, 20)) ?>" aria-label="Quantity" data-qty-input>
          <button type="button" data-step="up" aria-label="Increase quantity">+</button>
        </div>

        <div class="pdp__actions">
          <button class="btn btn-lg" type="submit" data-add-btn <?= !$inStock ? 'disabled' : '' ?>>
            <?= icon('cart', 18) ?> <span><?= $inStock ? 'Add to cart' : 'Out of stock' ?></span>
          </button>
          <button class="icon-btn" type="button" data-wish-btn
                  data-wish="<?= $selected ? (int) $selected['id'] : '' ?>"
                  aria-label="Save this shade to wishlist"><?= icon('heart', 20) ?></button>
        </div>
      </form>

      <?php if ($selected && !empty($selected['dye_lot'])): ?>
        <div class="dye-note">
          <?= icon('info', 16) ?>
          <p class="mb0">
            <strong>Dye lot <span data-dyelot><?= e($selected['dye_lot']) ?></span>.</strong>
            Shades vary slightly between lots — order every ball for one project together
            so the colour matches all the way through.
          </p>
        </div>
      <?php endif; ?>

      <div class="pincode-check">
        <label class="sr-only" for="pincode">Delivery pincode</label>
        <input class="input" id="pincode" inputmode="numeric" maxlength="6"
               placeholder="Delivery pincode" data-pincode-input>
        <button class="btn btn-soft" type="button" data-pincode-check>Check</button>
      </div>
      <p class="help" data-pincode-result></p>

      <div class="pdp__trust">
        <div><?= icon('lock', 16) ?> Secure payment</div>
        <div><?= icon('refresh', 16) ?> <?= setting_int('return_window_days', 7) ?>-day exchange</div>
        <div><?= icon('truck', 16) ?> Free above <?= e(money(setting_money('ship_free_threshold', 2499))) ?></div>
      </div>

      <!-- ================================================== details -->
      <div class="accordion mt6">
        <details open>
          <summary>Yarn details</summary>
          <div class="acc-body">
            <dl class="dl">
              <?php if (!empty($product['fiber'])): ?>
                <dt>Fibre</dt><dd><?= e($product['fiber']) ?></dd>
              <?php endif; ?>
              <?php if (!empty($product['yarn_weight'])): ?>
                <dt>Weight</dt>
                <dd><?= e(yarn_weight_label($product['yarn_weight'])) ?>
                    <?php $w = yarn_weights()[$product['yarn_weight']] ?? null; ?>
                    <?php if ($w): ?><span class="muted">· CYC <?= e($w['cyc']) ?> · <?= e($w['ply']) ?></span><?php endif; ?>
                </dd>
              <?php endif; ?>
              <?php if (!empty($product['ball_weight_g'])): ?>
                <dt>Ball</dt>
                <dd><?= (int) $product['ball_weight_g'] ?> g<?php
                    if (!empty($product['length_m'])): ?> · <?= (int) $product['length_m'] ?> m<?php endif; ?></dd>
              <?php endif; ?>
              <?php if (!empty($product['hook_mm'])): ?>
                <dt>Hook</dt><dd><?= e(rtrim(rtrim((string) $product['hook_mm'], '0'), '.')) ?> mm</dd>
              <?php endif; ?>
              <?php if (!empty($product['needle_mm'])): ?>
                <dt>Needle</dt><dd><?= e(rtrim(rtrim((string) $product['needle_mm'], '0'), '.')) ?> mm</dd>
              <?php endif; ?>
              <?php if (!empty($product['gauge'])): ?>
                <dt>Gauge</dt><dd><?= e($product['gauge']) ?></dd>
              <?php endif; ?>
              <dt>Origin</dt><dd><?= e($product['country_of_origin']) ?></dd>
              <dt>SKU</dt><dd><?= e($selected['sku'] ?? $product['sku_base']) ?></dd>
            </dl>
          </div>
        </details>

        <?php if (!empty($product['care_text'])): ?>
          <details>
            <summary>Care instructions</summary>
            <div class="acc-body"><?= e($product['care_text']) ?></div>
          </details>
        <?php endif; ?>

        <?php if (!empty($product['long_desc'])): ?>
          <details>
            <summary>About this yarn</summary>
            <div class="acc-body"><?= nl2br(e($product['long_desc'])) ?></div>
          </details>
        <?php endif; ?>

        <details>
          <summary>Shipping &amp; returns</summary>
          <div class="acc-body">
            <p>Dispatched in <?= e(setting('dispatch_days', '1-2')) ?> business days.
               Flat <?= e(money(setting_money('ship_flat_rate', 69))) ?> delivery,
               free on orders above <?= e(money(setting_money('ship_free_threshold', 2499))) ?>.</p>
            <p class="mb0">No cancellations or returns. Wrong, damaged or defective items are
               exchanged free within <?= setting_int('return_window_days', 7) ?> days, with an
               unedited unboxing video. Full details in our
               <a href="<?= e(url('shipping-policy')) ?>">shipping</a> and
               <a href="<?= e(url('returns-and-refunds')) ?>">exchange</a> policies.</p>
          </div>
        </details>
      </div>

      <p class="help mt4">
        Colours may appear slightly different depending on lighting, screen settings and photography.
        For an exact match, order our <a href="<?= e(url('shade-card.php')) ?>">physical shade card</a>.
      </p>
    </div>
  </div>

  <!-- ===================================================== reviews -->
  <section class="section" id="reviews">
    <div class="sec-head"><div class="min0"><h2>Reviews</h2></div></div>

    <?php if ($reviews): ?>
      <div class="card rating-summary mb5">
        <div class="center">
          <p class="rating-big mb0"><?= number_format((float) $product['rating_avg'], 1) ?></p>
          <?= stars_html((float) $product['rating_avg'], 0, false) ?>
          <?php /* Spelled out here rather than via stars_html(), so it needs
                   the same gate — see Settings → Numbers shown to customers. */ ?>
          <?php if (setting_bool('feat_review_counts', true)): ?>
            <p class="text-sm muted mt2 mb0">
              <?= (int) $product['rating_count'] ?>
              review<?= (int) $product['rating_count'] === 1 ? '' : 's' ?></p>
          <?php endif; ?>
        </div>
        <div class="rating-bars">
          <?php
          $counts = db_all(
              'SELECT rating, COUNT(*) AS n FROM reviews
               WHERE product_id = ? AND status = "approved" GROUP BY rating',
              [$pid]
          );
          $byStar = [];
          foreach ($counts as $c) { $byStar[(int) $c['rating']] = (int) $c['n']; }
          $totalR = max(1, array_sum($byStar));
          for ($s = 5; $s >= 1; $s--):
              $n = $byStar[$s] ?? 0; ?>
            <div class="rating-bar">
              <span><?= $s ?>★</span>
              <span class="rating-bar__track">
                <span class="rating-bar__fill" style="width:<?= round($n / $totalR * 100) ?>%"></span>
              </span>
              <span><?= $n ?></span>
            </div>
          <?php endfor; ?>
        </div>
      </div>

      <div class="grid grid-2">
        <?php foreach ($reviews as $r): ?>
          <article class="card">
            <div class="row row-between">
              <?= stars_html((float) $r['rating'], 0, false) ?>
              <?php if ((int) $r['is_verified'] === 1): ?>
                <span class="pill pill-ok"><?= icon('check', 13) ?> Verified buyer</span>
              <?php endif; ?>
            </div>
            <?php if (!empty($r['title'])): ?>
              <h3 class="mt3" style="font-family:var(--font-sans);font-size:1rem"><?= e($r['title']) ?></h3>
            <?php endif; ?>
            <p class="text-sm soft mt2"><?= nl2br(e((string) $r['body'])) ?></p>
            <p class="text-xs muted mb0">
              <?= e($r['author_name'] ?: ($r['full_name'] ?: 'Verified customer')) ?>
              · <?= e(fmt_date($r['created_at'])) ?>
            </p>
          </article>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="card center" style="padding-block:var(--s7)">
        <p class="mb3"><strong>No reviews yet for this yarn.</strong></p>
        <p class="text-sm soft mb0" style="margin-inline:auto;max-width:42ch">
          Reviews only come from people who bought the yarn here, so this stays empty
          until the first order is delivered. We never write our own.
        </p>
      </div>
    <?php endif; ?>
  </section>

  <?php if ($addons): ?>
    <section class="section-sm">
      <div class="sec-head"><div class="min0"><h2>Goes well with</h2></div></div>
      <?php product_grid($addons); ?>
    </section>
  <?php endif; ?>

  <?php if ($related): ?>
    <section class="section-sm">
      <div class="sec-head">
        <div class="min0">
          <h2>Similar yarns</h2>
          <p>Same weight, so they substitute cleanly in a pattern.</p>
        </div>
      </div>
      <?php product_grid($related); ?>
    </section>
  <?php endif; ?>
</div>

<!-- sticky mobile buy bar -->
<div class="buy-bar" data-buy-bar>
  <img src="<?= e($gallery[$mainIndex]['src']) ?>" alt="" width="44" height="44"
       style="width:44px;height:44px;border-radius:var(--r-sm);object-fit:cover;flex:none"
       data-bar-image>
  <div class="min0 flex1">
    <p class="text-xs muted mb0 truncate"><?= e($product['name']) ?></p>
    <strong data-bar-price><?= e(money($price)) ?></strong>
  </div>
  <button class="btn" type="button" data-bar-add <?= !$inStock ? 'disabled' : '' ?>>
    <?= $inStock ? 'Add to cart' : 'Sold out' ?>
  </button>
</div>

<?php require_once YN_ROOT . '/includes/footer.php'; ?>
