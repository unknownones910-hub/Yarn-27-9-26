<?php
/**
 * Curated collection ("Monsoon Cotton Edit", "Beginner Starter Picks"…).
 * A hand-picked list rather than a taxonomy, so it gets its own banner and copy.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/catalog.php';
require_once YN_ROOT . '/includes/components/product-card.php';
require_once YN_ROOT . '/includes/components/pagination.php';

$slug       = get_str('slug', 140);
$collection = $slug !== '' ? collection_by_slug($slug) : null;

if (!$collection) {
    http_response_code(404);
    require YN_ROOT . '/404.php';
    exit;
}

$f               = catalog_filters_from_request();
$f['collection'] = $collection['slug'];
$page            = max(1, get_int('page', 1));
$result          = catalog_query($f, $page);

$pageTitle = $collection['name'];
$pageDesc  = str_excerpt($collection['description'], 155) ?: ('Shop the ' . $collection['name'] . ' collection.');
$canonical = '/collection/' . $collection['slug'];
$noindex   = $page > 1;

$crumbs = [['Home', url('')], ['Shop', url('shop.php')], [$collection['name'], null]];

$jsonLd = [breadcrumb_ld($crumbs)];

if (!$noindex && $result['rows']) {
    $jsonLd[] = catalog_item_list_ld((string) $collection['name'], $result['rows']);
}

require_once YN_ROOT . '/includes/header.php';
?>

<div class="container">
  <?= breadcrumb_nav($crumbs) ?>

  <header class="mb6">
    <p class="eyebrow">Collection</p>
    <h1><?= e($collection['name']) ?></h1>
    <?php if (!empty($collection['description'])): ?>
      <p class="lede" style="max-width:60ch"><?= e($collection['description']) ?></p>
    <?php endif; ?>
  </header>

  <?php if ($result['total'] === 0): ?>
    <div class="empty">
      <div class="empty__art"><?= icon('sparkle', 40) ?></div>
      <h2>This collection is being put together</h2>
      <p style="max-width:42ch">Nothing has been added to it yet. Have a look at everything else in the meantime.</p>
      <a class="btn mt4" href="<?= e(url('shop.php')) ?>">Shop all yarn</a>
    </div>
  <?php else: ?>
    <p class="shop-count mb5"><?= number_format($result['total']) ?>
      <?= $result['total'] === 1 ? 'yarn' : 'yarns' ?> in this collection</p>
    <?php product_grid($result['rows']); ?>
    <?php pagination($result['page'], $result['pages']); ?>
  <?php endif; ?>
</div>

<?php require_once YN_ROOT . '/includes/footer.php'; ?>
