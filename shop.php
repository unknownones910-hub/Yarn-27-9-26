<?php
/**
 * Shop grid.
 *
 * Serves three things with the same code path, because they differ only by
 * which filters are pre-applied:
 *   /shop.php                 all products
 *   /category/{slug}          a taxonomy page (craft, fibre, weight or other)
 *   /shop.php?sort=new        preset views linked from the header
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/catalog.php';
require_once YN_ROOT . '/includes/components/product-card.php';
require_once YN_ROOT . '/includes/components/pagination.php';
require_once YN_ROOT . '/includes/components/shop-filters.php';

$f    = catalog_filters_from_request();
$page = max(1, get_int('page', 1));
$cats = nav_categories();

// ---- Resolve the category page, if any -------------------------------
$category = null;
if ($f['category'] !== '') {
    $category = category_by_slug($f['category']);
    if (!$category) {
        http_response_code(404);
        require YN_ROOT . '/404.php';
        exit;
    }
}

$result = catalog_query($f, $page);

// ---- Page furniture --------------------------------------------------
if ($category) {
    $heading   = $category['name'];
    $intro     = (string) $category['description'];
    $pageTitle = $category['meta_title'] ?: ($category['name'] . ' Yarn Online in India');
    $pageDesc  = $category['meta_description']
        ?: str_excerpt($category['description'] ?: 'Shop ' . $category['name'] . ' yarn.', 155);
    $canonical = '/category/' . $category['slug'];
} elseif ($f['sort'] === 'new') {
    $heading   = 'New arrivals';
    $intro     = 'The shades and bases that landed most recently.';
    $pageTitle = 'New Arrivals';
    $pageDesc  = 'The newest yarn to arrive at ' . setting('store_name') . '.';
    $canonical = '/shop.php?sort=new';
} else {
    $heading   = 'All yarn';
    $intro     = 'Every base we stock, with the full fibre composition and meterage on each product page.';
    $pageTitle = 'Shop All Yarn';
    $pageDesc  = setting('seo_description');
    $canonical = '/shop.php';
}

// Filtered permutations must not compete with the clean category page in search.
$noindex = catalog_has_filters($f) || $page > 1;

$crumbs = [['Home', url('')]];
if ($category) {
    // Matches the trail on the PDP: Home > Shop > Category > Product.
    $crumbs[] = ['Shop', url('shop.php')];
    $crumbs[] = [$category['name'], null];
} else {
    $crumbs[] = [$heading, null];
}

$jsonLd = [breadcrumb_ld($crumbs)];

// The products actually on this page, in the order shown. Only worth emitting
// on a clean, indexable listing — a filtered permutation is noindexed anyway.
if (!$noindex && $result['rows']) {
    $jsonLd[] = catalog_item_list_ld($heading, $result['rows']);
}

require_once YN_ROOT . '/includes/header.php';
?>

<div class="container">

  <?= breadcrumb_nav($crumbs) ?>

  <header class="mb5">
    <h1><?= e($heading) ?></h1>
    <?php if ($intro !== ''): ?>
      <p class="lede" style="max-width:60ch"><?= e($intro) ?></p>
    <?php endif; ?>
  </header>

  <div class="shop-layout">
    <?php shop_filters($f, $cats); ?>

    <div class="min0">
      <div class="shop-toolbar">
        <p class="shop-count mb0">
          <?php if ($result['total'] === 0): ?>
            No yarns found
          <?php else: ?>
            Showing
            <strong><?= number_format(($result['page'] - 1) * $result['per_page'] + 1) ?>–<?=
              number_format(min($result['page'] * $result['per_page'], $result['total'])) ?></strong>
            of <?= number_format($result['total']) ?>
          <?php endif; ?>
        </p>

        <div class="row gap3" style="flex:none">
          <button class="btn btn-soft btn-sm filter-toggle" type="button"
                  data-drawer-open="#shop-filters" aria-controls="shop-filters">
            <?= icon('filter', 15) ?> Filters
            <?php if (catalog_has_filters($f)): ?><span class="badge badge-sale">on</span><?php endif; ?>
          </button>

          <form class="shop-sort" method="get" action="<?= e(strtok((string) $_SERVER['REQUEST_URI'], '?')) ?>" data-no-loading>
            <?php shop_carry_fields(['sort']); ?>
            <label class="sr-only" for="sort">Sort by</label>
            <select class="select" id="sort" name="sort" data-auto-submit>
              <?php foreach (catalog_sort_options() as $val => $label): ?>
                <option value="<?= e((string) $val) ?>" <?= $f['sort'] === $val ? 'selected' : '' ?>>
                  <?= e($label) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <noscript><button class="btn btn-sm" type="submit">Sort</button></noscript>
          </form>
        </div>
      </div>

      <?php active_filter_chips($f); ?>

      <?php if ($result['total'] === 0): ?>
        <div class="empty">
          <div class="empty__art"><?= icon('search', 40) ?></div>
          <h2>Nothing matches those filters</h2>
          <p style="max-width:44ch">
            Try widening the price range, or clearing the colour and weight filters —
            those two narrow things down fastest.
          </p>
          <a class="btn mt4" href="<?= e(shop_clear_url()) ?>">Clear all filters</a>
        </div>

        <?php $suggest = featured_products(4); ?>
        <?php if ($suggest): ?>
          <div class="mt7">
            <div class="sec-head"><div class="min0"><h2>Popular right now</h2></div></div>
            <?php product_grid($suggest); ?>
          </div>
        <?php endif; ?>

      <?php else: ?>
        <?php product_grid($result['rows']); ?>
        <?php pagination($result['page'], $result['pages']); ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require_once YN_ROOT . '/includes/footer.php'; ?>
