<?php
/**
 * Search results.
 *
 * Same filter/sort machinery as the shop, plus a recovery path when a query
 * returns nothing — an empty search is the single most common moment a
 * shopper leaves, so it gets suggestions rather than a dead end.
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
$q    = $f['q'];

$result = ['rows' => [], 'total' => 0, 'pages' => 1, 'page' => 1, 'per_page' => CATALOG_PER_PAGE];
if ($q !== '') {
    if (!rate_hit('search:' . client_ip(), 120, 3600)) {
        flash('That is a lot of searching. Please slow down a little.', 'warn');
    } else {
        $result = catalog_query($f, $page);
    }
}

$pageTitle = $q !== '' ? 'Search: ' . $q : 'Search';
$pageDesc  = 'Search yarn by name, fibre, weight or shade at ' . setting('store_name') . '.';
$noindex   = true;   // search result pages never belong in the index
$canonical = '/search.php';

require_once YN_ROOT . '/includes/header.php';
?>

<div class="container section-sm">

  <header class="mb5">
    <h1><?= $q !== '' ? 'Results for &ldquo;' . e($q) . '&rdquo;' : 'Search' ?></h1>
    <?php if ($q !== ''): ?>
      <p class="lede"><?= number_format($result['total']) ?>
        <?= $result['total'] === 1 ? 'yarn' : 'yarns' ?> found</p>
    <?php endif; ?>
  </header>

  <form action="<?= e(url('search.php')) ?>" method="get" class="mb6" data-no-loading
        style="max-width:520px">
    <label class="sr-only" for="q-page">Search yarn</label>
    <div class="search">
      <?= icon('search', 18) ?>
      <input class="input" type="search" id="q-page" name="q" value="<?= e($q) ?>"
             placeholder="Try “cotton DK” or “chenille”" autofocus>
    </div>
  </form>

  <?php if ($q === ''): ?>
    <div class="empty">
      <div class="empty__art"><?= icon('search', 40) ?></div>
      <h2>What are you looking for?</h2>
      <p style="max-width:44ch">Search by yarn name, fibre, weight or shade.</p>
      <div class="chips" style="justify-content:center;margin-top:var(--s5)">
        <?php foreach (['cotton', 'chenille', 'DK', 'acrylic', 'wool', 'macrame'] as $term): ?>
          <a class="chip" href="<?= e(url('search.php?q=' . rawurlencode($term))) ?>"><?= e($term) ?></a>
        <?php endforeach; ?>
      </div>
    </div>

  <?php elseif ($result['total'] === 0): ?>
    <div class="empty">
      <div class="empty__art"><?= icon('yarn', 40) ?></div>
      <h2>No yarn matches &ldquo;<?= e($q) ?>&rdquo;</h2>
      <p style="max-width:46ch">
        Check the spelling, or try a broader word — &ldquo;cotton&rdquo; rather than
        &ldquo;mercerised cotton 8 ply&rdquo;.
      </p>
      <a class="btn mt4" href="<?= e(url('shop.php')) ?>">Browse all yarn</a>
    </div>

    <?php $suggest = featured_products(4); ?>
    <?php if ($suggest): ?>
      <section class="section-sm">
        <div class="sec-head"><div class="min0"><h2>People are buying</h2></div></div>
        <?php product_grid($suggest); ?>
      </section>
    <?php endif; ?>

  <?php else: ?>
    <div class="shop-layout">
      <?php shop_filters($f, $cats); ?>

      <div class="min0">
        <div class="shop-toolbar">
          <p class="shop-count mb0">
            Showing <strong><?= number_format(($result['page'] - 1) * $result['per_page'] + 1) ?>–<?=
              number_format(min($result['page'] * $result['per_page'], $result['total'])) ?></strong>
            of <?= number_format($result['total']) ?>
          </p>
          <div class="row gap3" style="flex:none">
            <button class="btn btn-soft btn-sm filter-toggle" type="button" data-drawer-open="#shop-filters">
              <?= icon('filter', 15) ?> Filters
            </button>
            <form class="shop-sort" method="get" action="<?= e(url('search.php')) ?>" data-no-loading>
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
        <?php product_grid($result['rows']); ?>
        <?php pagination($result['page'], $result['pages']); ?>
      </div>
    </div>
  <?php endif; ?>
</div>

<?php require_once YN_ROOT . '/includes/footer.php'; ?>
