<?php
/**
 * Inventory: every shade, its stock, and an audit trail of how it got there.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-bootstrap.php';
$admin = admin_guard();

// ---- bulk restock -----------------------------------------------------
if (is_post()) {
    csrf_verify();

    if (post_str('action', 20) === 'bulk_add') {
        $add = post_int('amount');
        $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? []))));

        if ($add === 0 || !$ids) {
            flash('Choose some shades and an amount to add or remove.', 'warn');
        } else {
            $n = 0;
            foreach ($ids as $vid) {
                $before = db_val('SELECT stock FROM product_variants WHERE id = ?', [$vid]);
                if ($before === null) {
                    continue;
                }
                $after = max(0, (int) $before + $add);
                db_exec('UPDATE product_variants SET stock = ? WHERE id = ?', [$after, $vid]);
                db_exec('INSERT INTO stock_movements (variant_id, delta, reason, note, admin_id)
                         VALUES (?,?,"manual",?,?)',
                        [$vid, $after - (int) $before, post_str('note', 200) ?: 'Bulk adjustment',
                         (int) $admin['id']]);
                $n++;
            }
            admin_log('stock_bulk', 'variant', null, ($add > 0 ? '+' : '') . $add . ' × ' . $n);
            flash($n . ' shade' . ($n === 1 ? '' : 's') . ' adjusted by '
                . ($add > 0 ? '+' : '') . $add . '.', 'success');
        }
    }
    redirect('admin/inventory.php?' . http_build_query($_GET));
}

// ---- filters ----------------------------------------------------------
$q      = get_str('q', 80);
$filter = get_str('filter', 20);

$where  = ['p.is_active = 1'];
$params = [];

if ($q !== '') {
    $where[]  = '(p.name LIKE ? OR v.sku LIKE ? OR v.shade_name LIKE ?)';
    $like     = '%' . $q . '%';
    array_push($params, $like, $like, $like);
}
if ($filter === 'low')  { $where[] = 'v.stock > 0 AND v.stock <= v.low_stock_at'; }
if ($filter === 'out')  { $where[] = 'v.stock = 0'; }
if ($filter === 'over') { $where[] = 'v.stock > 100'; }

$whereSql = implode(' AND ', $where);

if (get_str('export') === 'csv') {
    $rows = db_all(
        "SELECT v.sku, p.name AS product, v.shade_name, v.color_hex, v.stock, v.low_stock_at,
                v.dye_lot, v.is_active
         FROM product_variants v JOIN products p ON p.id = v.product_id
         WHERE {$whereSql} ORDER BY p.name, v.sort_order LIMIT 5000",
        $params
    );
    admin_csv_out('inventory-' . date('Y-m-d') . '.csv', $rows, [
        'sku' => 'SKU', 'product' => 'Product', 'shade_name' => 'Shade', 'color_hex' => 'Hex',
        'stock' => 'Stock', 'low_stock_at' => 'Low at', 'dye_lot' => 'Dye lot', 'is_active' => 'Active',
    ]);
}

$total = (int) db_val("SELECT COUNT(*) FROM product_variants v
                       JOIN products p ON p.id = v.product_id WHERE {$whereSql}", $params);
$pg    = admin_paginate($total, 40);

$rows = db_all(
    "SELECT v.*, p.name AS product_name, p.id AS pid
     FROM product_variants v JOIN products p ON p.id = v.product_id
     WHERE {$whereSql}
     ORDER BY (v.stock = 0) DESC, (v.stock <= v.low_stock_at) DESC, p.name, v.sort_order
     LIMIT {$pg['per']} OFFSET {$pg['offset']}",
    $params
);

$stats = db_row(
    'SELECT COUNT(*) total,
            SUM(v.stock = 0) out_of_stock,
            SUM(v.stock > 0 AND v.stock <= v.low_stock_at) low,
            COALESCE(SUM(v.stock),0) units
     FROM product_variants v JOIN products p ON p.id = v.product_id
     WHERE v.is_active = 1 AND p.is_active = 1'
) ?: [];

$moves = db_all(
    'SELECT m.*, v.sku, v.shade_name, p.name AS product_name, a.name AS admin_name
     FROM stock_movements m
     JOIN product_variants v ON v.id = m.variant_id
     JOIN products p ON p.id = v.product_id
     LEFT JOIN admin_users a ON a.id = m.admin_id
     ORDER BY m.created_at DESC, m.id DESC LIMIT 25'
);

$pageTitle    = 'Inventory';
$adminSection = 'inventory';

require_once YN_ROOT . '/includes/admin-header.php';
?>

<div class="admin-head">
  <div class="min0">
    <h1>Inventory</h1>
    <p><?= number_format((int) ($stats['units'] ?? 0)) ?> balls across
       <?= number_format((int) ($stats['total'] ?? 0)) ?> shades</p>
  </div>
  <a class="btn btn-sm btn-soft" style="flex:none"
     href="<?= e(url('admin/inventory.php?' . http_build_query($_GET + ['export' => 'csv']))) ?>">
    <?= icon('download', 15) ?> Export</a>
</div>

<div class="kpi-grid mb4">
  <div class="kpi<?= (int) ($stats['out_of_stock'] ?? 0) > 0 ? ' kpi--alert' : '' ?>">
    <p class="kpi__label">Out of stock</p>
    <p class="kpi__value"><?= (int) ($stats['out_of_stock'] ?? 0) ?></p>
  </div>
  <div class="kpi<?= (int) ($stats['low'] ?? 0) > 0 ? ' kpi--warn' : '' ?>">
    <p class="kpi__label">Running low</p>
    <p class="kpi__value"><?= (int) ($stats['low'] ?? 0) ?></p>
  </div>
  <div class="kpi">
    <p class="kpi__label">Shades stocked</p>
    <p class="kpi__value"><?= (int) ($stats['total'] ?? 0) ?></p>
  </div>
  <div class="kpi">
    <p class="kpi__label">Total balls</p>
    <p class="kpi__value"><?= admin_short_num((float) ($stats['units'] ?? 0)) ?></p>
  </div>
</div>

<div class="chips mb4">
  <?php foreach (['' => 'All', 'out' => 'Out of stock', 'low' => 'Running low',
                  'over' => 'Overstocked'] as $k => $lbl): ?>
    <a class="chip <?= $filter === $k ? 'is-on' : '' ?>"
       href="<?= e(url('admin/inventory.php' . ($k !== '' ? '?filter=' . $k : ''))) ?>"><?= e($lbl) ?></a>
  <?php endforeach; ?>
</div>

<div class="admin-toolbar">
  <form method="get" action="<?= e(url('admin/inventory.php')) ?>" style="flex:1">
    <?php if ($filter !== ''): ?><input type="hidden" name="filter" value="<?= e($filter) ?>"><?php endif; ?>
    <div class="search admin-search">
      <?= icon('search', 16) ?>
      <input class="input" type="search" name="q" value="<?= e($q) ?>" placeholder="Product, shade or SKU">
    </div>
    <button class="btn btn-sm" type="submit">Search</button>
  </form>
</div>

<form method="post" action="<?= e(url('admin/inventory.php?' . http_build_query($_GET))) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="action" value="bulk_add">

  <div class="bulk-bar" data-bulk-bar>
    <strong><span data-bulk-count>0</span> selected</strong>
    <label class="text-sm">Adjust by
      <input class="input" type="number" name="amount" value="10" style="width:90px;min-height:38px"
             aria-label="Amount to add or remove"></label>
    <input class="input" name="note" placeholder="Note, e.g. new delivery"
           style="min-height:38px;flex:1;min-width:140px">
    <button class="btn btn-sm" type="submit">Apply</button>
  </div>

  <div class="panel panel--flush">
    <?php if (!$rows): ?>
      <div class="admin-empty"><?= icon('tag', 40) ?><p>Nothing matches.</p></div>
    <?php else: ?>
      <div class="scroll-x">
        <table class="admin-table">
          <thead><tr>
            <th class="tight"><input type="checkbox" data-bulk-all aria-label="Select all"></th>
            <th></th><th>Product</th><th>Shade</th><th>SKU</th><th>Dye lot</th>
            <th class="num">Stock</th><th class="num">Low at</th><th></th>
          </tr></thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <?php $st = (int) $r['stock']; ?>
              <tr>
                <td class="tight"><input type="checkbox" name="ids[]" value="<?= (int) $r['id'] ?>"
                    data-bulk-item aria-label="Select <?= e((string) $r['sku']) ?>"></td>
                <td class="tight">
                  <span style="display:block;width:22px;height:22px;border-radius:50%;
                               background:<?= e((string) $r['color_hex']) ?>;
                               box-shadow:0 0 0 1px var(--sand-deep)"></span></td>
                <td class="truncate" style="max-width:200px">
                  <a href="<?= e(url('admin/product-edit.php?id=' . (int) $r['pid'])) ?>">
                    <?= e((string) $r['product_name']) ?></a></td>
                <td><?= e((string) $r['shade_name']) ?></td>
                <td class="text-xs"><?= e((string) $r['sku']) ?></td>
                <td class="text-xs muted"><?= e((string) $r['dye_lot']) ?></td>
                <td class="num">
                  <input class="cell-edit" data-cell-edit data-entity="variant"
                         data-id="<?= (int) $r['id'] ?>" data-field="stock"
                         data-original="<?= $st ?>" value="<?= $st ?>"
                         inputmode="numeric" aria-label="Stock for <?= e((string) $r['sku']) ?>"></td>
                <td class="num">
                  <input class="cell-edit" data-cell-edit data-entity="variant"
                         data-id="<?= (int) $r['id'] ?>" data-field="low_stock_at"
                         data-original="<?= (int) $r['low_stock_at'] ?>"
                         value="<?= (int) $r['low_stock_at'] ?>"
                         inputmode="numeric" aria-label="Low stock threshold"></td>
                <td>
                  <?php if ($st === 0): ?><span class="pill pill-bad">Out</span>
                  <?php elseif ($st <= (int) $r['low_stock_at']): ?><span class="pill pill-warn">Low</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
</form>

<p class="text-xs muted mt3">Stock saves as soon as you click away. Every change is logged below.</p>

<?php if ($pg['pages'] > 1): ?>
  <?php require_once YN_ROOT . '/includes/components/pagination.php'; ?>
  <?php pagination($pg['page'], $pg['pages']); ?>
<?php endif; ?>

<div class="panel mt5">
  <h2>Recent stock movements</h2>
  <?php if (!$moves): ?>
    <p class="text-sm muted mb0">Nothing recorded yet.</p>
  <?php else: ?>
    <?php foreach ($moves as $m): ?>
      <div class="log-line">
        <time><?= e(fmt_date((string) $m['created_at'], 'j M, g:ia')) ?></time>
        <span>
          <strong style="color:<?= (int) $m['delta'] < 0 ? 'var(--danger)' : 'var(--success)' ?>">
            <?= (int) $m['delta'] > 0 ? '+' : '' ?><?= (int) $m['delta'] ?></strong>
          <?= e((string) $m['product_name']) ?>
          <span class="muted">· <?= e((string) $m['shade_name']) ?> · <?= e((string) $m['sku']) ?></span>
          <br><span class="text-xs muted">
            <?= e((string) $m['reason']) ?><?= $m['note'] ? ' — ' . e((string) $m['note']) : '' ?>
            <?= $m['admin_name'] ? ' · ' . e((string) $m['admin_name']) : '' ?></span>
        </span>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>

<?php require_once YN_ROOT . '/includes/admin-footer.php'; ?>
