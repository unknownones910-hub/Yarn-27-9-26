<?php
/**
 * Product list, with price and stock editable in place.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-bootstrap.php';
require_once YN_ROOT . '/includes/catalog.php';

$admin = admin_guard();

// ---- bulk -------------------------------------------------------------
if (is_post()) {
    csrf_verify();
    $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));
    $act = post_str('bulk_action', 20);

    if ($ids) {
        if ($act === 'activate' || $act === 'deactivate') {
            $on = $act === 'activate' ? 1 : 0;
            db_exec('UPDATE products SET is_active = ? WHERE id IN (' . db_in($ids) . ')',
                    array_merge([$on], $ids));
            admin_log('products_bulk', 'product', null, $act . ' × ' . count($ids));
            flash(count($ids) . ' product' . (count($ids) === 1 ? '' : 's') . ' ' . $act . 'd.', 'success');

        } elseif ($act === 'feature' || $act === 'unfeature') {
            $on = $act === 'feature' ? 1 : 0;
            db_exec('UPDATE products SET is_featured = ? WHERE id IN (' . db_in($ids) . ')',
                    array_merge([$on], $ids));
            flash('Featured flag updated.', 'success');
        }
    } else {
        flash('Select at least one product.', 'warn');
    }
    redirect('admin/products.php?' . http_build_query($_GET));
}

// ---- filters ----------------------------------------------------------
$q      = get_str('q', 80);
$cat    = get_str('category', 60);
$state  = get_str('state', 20);

$where  = ['1=1'];
$params = [];

if ($q !== '') {
    $where[]  = '(p.name LIKE ? OR p.sku_base LIKE ? OR p.brand LIKE ? OR p.fiber LIKE ?)';
    $like     = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}
if ($cat !== '') {
    $where[]  = 'EXISTS (SELECT 1 FROM product_categories pc JOIN categories c ON c.id = pc.category_id
                         WHERE pc.product_id = p.id AND c.slug = ?)';
    $params[] = $cat;
}
if ($state === 'inactive')  { $where[] = 'p.is_active = 0'; }
if ($state === 'active')    { $where[] = 'p.is_active = 1'; }
if ($state === 'oos') {
    $where[] = 'NOT EXISTS (SELECT 1 FROM product_variants v
                            WHERE v.product_id = p.id AND v.is_active = 1 AND v.stock > 0)';
}

$whereSql = implode(' AND ', $where);

if (get_str('export') === 'csv') {
    $rows = db_all(
        "SELECT p.sku_base, p.name, p.brand, p.fiber, p.yarn_weight, p.ball_weight_g, p.length_m,
                p.mrp, p.price, p.is_active,
                (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id) shades,
                (SELECT COALESCE(SUM(v.stock),0) FROM product_variants v WHERE v.product_id = p.id) stock
         FROM products p WHERE {$whereSql} ORDER BY p.name LIMIT 5000",
        $params
    );
    admin_csv_out('products-' . date('Y-m-d') . '.csv', $rows, [
        'sku_base' => 'SKU', 'name' => 'Name', 'brand' => 'Brand', 'fiber' => 'Fibre',
        'yarn_weight' => 'Weight', 'ball_weight_g' => 'Ball (g)', 'length_m' => 'Length (m)',
        'mrp' => 'MRP', 'price' => 'Price', 'is_active' => 'Active',
        'shades' => 'Shades', 'stock' => 'Total stock',
    ]);
}

$total = (int) db_val("SELECT COUNT(*) FROM products p WHERE {$whereSql}", $params);
$pg    = admin_paginate($total, 25);

$products = db_all(
    "SELECT p.*,
            (SELECT COUNT(*) FROM product_variants v WHERE v.product_id = p.id AND v.is_active = 1) shades,
            (SELECT COALESCE(SUM(v.stock),0) FROM product_variants v WHERE v.product_id = p.id AND v.is_active = 1) stock,
            (SELECT v.color_hex FROM product_variants v WHERE v.product_id = p.id ORDER BY v.sort_order, v.id LIMIT 1) hex,
            (SELECT v.image FROM product_variants v WHERE v.product_id = p.id AND v.image IS NOT NULL LIMIT 1) vimg
     FROM products p WHERE {$whereSql}
     ORDER BY p.name LIMIT {$pg['per']} OFFSET {$pg['offset']}",
    $params
);

$cats = nav_categories();

$pageTitle    = 'Products';
$adminSection = 'products';

require_once YN_ROOT . '/includes/admin-header.php';
?>

<div class="admin-head">
  <div class="min0">
    <h1>Products</h1>
    <p><?= number_format($total) ?> product<?= $total === 1 ? '' : 's' ?></p>
  </div>
  <div class="row gap2" style="flex:none">
    <a class="btn btn-sm btn-soft"
       href="<?= e(url('admin/products.php?' . http_build_query($_GET + ['export' => 'csv']))) ?>">
      <?= icon('download', 15) ?> Export</a>
    <a class="btn btn-sm" href="<?= e(url('admin/product-edit.php')) ?>">
      <?= icon('plus', 15) ?> New product</a>
  </div>
</div>

<div class="admin-toolbar">
  <form method="get" action="<?= e(url('admin/products.php')) ?>" style="flex:1">
    <div class="search admin-search">
      <?= icon('search', 16) ?>
      <input class="input" type="search" name="q" value="<?= e($q) ?>"
             placeholder="Name, SKU, brand or fibre">
    </div>
    <select class="select" name="category" onchange="this.form.submit()" style="width:auto">
      <option value="">All categories</option>
      <?php foreach (['craft' => 'Craft', 'fiber' => 'Fibre', 'other' => 'Other'] as $kind => $lbl): ?>
        <optgroup label="<?= e($lbl) ?>">
          <?php foreach (($cats[$kind] ?? []) as $c): ?>
            <option value="<?= e($c['slug']) ?>" <?= $cat === $c['slug'] ? 'selected' : '' ?>>
              <?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </optgroup>
      <?php endforeach; ?>
    </select>
    <select class="select" name="state" onchange="this.form.submit()" style="width:auto">
      <option value="">Any state</option>
      <option value="active"   <?= $state === 'active' ? 'selected' : '' ?>>Active</option>
      <option value="inactive" <?= $state === 'inactive' ? 'selected' : '' ?>>Hidden</option>
      <option value="oos"      <?= $state === 'oos' ? 'selected' : '' ?>>Out of stock</option>
    </select>
    <button class="btn btn-sm" type="submit">Filter</button>
    <?php if ($q || $cat || $state): ?>
      <a class="btn btn-sm btn-soft" href="<?= e(url('admin/products.php')) ?>">Clear</a>
    <?php endif; ?>
  </form>
</div>

<form method="post" action="<?= e(url('admin/products.php?' . http_build_query($_GET))) ?>">
  <?= csrf_field() ?>

  <div class="bulk-bar" data-bulk-bar>
    <strong><span data-bulk-count>0</span> selected</strong>
    <select class="select" name="bulk_action" style="min-height:38px;width:auto">
      <option value="activate">Show in shop</option>
      <option value="deactivate">Hide from shop</option>
      <option value="feature">Mark featured</option>
      <option value="unfeature">Remove featured</option>
    </select>
    <button class="btn btn-sm" type="submit">Apply</button>
  </div>

  <div class="panel panel--flush">
    <?php if (!$products): ?>
      <div class="admin-empty">
        <?= icon('yarn', 40) ?>
        <p>No products match.</p>
        <a class="btn btn-sm mt3" href="<?= e(url('admin/product-edit.php')) ?>">Add your first product</a>
      </div>
    <?php else: ?>
      <div class="scroll-x">
        <table class="admin-table">
          <thead><tr>
            <th class="tight"><input type="checkbox" data-bulk-all aria-label="Select all"></th>
            <th></th><th>Product</th><th>SKU</th>
            <th class="num">Price</th><th class="num">MRP</th>
            <th class="num">Shades</th><th class="num">Stock</th><th>State</th><th class="tight"></th>
          </tr></thead>
          <tbody>
            <?php foreach ($products as $p): ?>
              <tr>
                <td class="tight"><input type="checkbox" name="ids[]" value="<?= (int) $p['id'] ?>"
                    data-bulk-item aria-label="Select <?= e((string) $p['name']) ?>"></td>
                <td class="tight">
                  <img class="thumb" alt=""
                       src="<?= e($p['vimg'] ? upload_url((string) $p['vimg'])
                                             : yarn_ball_uri((string) ($p['hex'] ?: '#D8C3A9'))) ?>"></td>
                <td>
                  <a href="<?= e(url('admin/product-edit.php?id=' . (int) $p['id'])) ?>">
                    <strong><?= e((string) $p['name']) ?></strong></a>
                  <br><span class="text-xs muted"><?= e(product_meta_line($p)) ?></span>
                </td>
                <td class="text-xs"><?= e((string) $p['sku_base']) ?></td>
                <td class="num">
                  <input class="cell-edit" data-cell-edit data-entity="product"
                         data-id="<?= (int) $p['id'] ?>" data-field="price"
                         data-original="<?= e(number_format((float) $p['price'], 2, '.', '')) ?>"
                         value="<?= e(number_format((float) $p['price'], 2, '.', '')) ?>"
                         inputmode="decimal" aria-label="Price for <?= e((string) $p['name']) ?>"></td>
                <td class="num">
                  <input class="cell-edit" data-cell-edit data-entity="product"
                         data-id="<?= (int) $p['id'] ?>" data-field="mrp"
                         data-original="<?= e(number_format((float) $p['mrp'], 2, '.', '')) ?>"
                         value="<?= e(number_format((float) $p['mrp'], 2, '.', '')) ?>"
                         inputmode="decimal" aria-label="MRP for <?= e((string) $p['name']) ?>"></td>
                <td class="num"><?= (int) $p['shades'] ?></td>
                <td class="num">
                  <?php $st = (int) $p['stock']; ?>
                  <span class="pill <?= $st === 0 ? 'pill-bad' : ($st <= 10 ? 'pill-warn' : 'pill-muted') ?>">
                    <?= $st ?></span></td>
                <td>
                  <?php if ((int) $p['is_active'] === 1): ?>
                    <span class="pill pill-ok">Live</span>
                  <?php else: ?>
                    <span class="pill pill-muted">Hidden</span>
                  <?php endif; ?>
                  <?php if ((int) $p['is_featured'] === 1): ?>
                    <span class="pill pill-info">★</span>
                  <?php endif; ?>
                </td>
                <td class="tight">
                  <a class="btn-link" href="<?= e(url('admin/product-edit.php?id=' . (int) $p['id'])) ?>">Edit</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</form>

<p class="text-xs muted mt3">Price and MRP save as soon as you click away from the box.</p>

<?php if ($pg['pages'] > 1): ?>
  <?php require_once YN_ROOT . '/includes/components/pagination.php'; ?>
  <?php pagination($pg['page'], $pg['pages']); ?>
<?php endif; ?>

<?php require_once YN_ROOT . '/includes/admin-footer.php'; ?>
