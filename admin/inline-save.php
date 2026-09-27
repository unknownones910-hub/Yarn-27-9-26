<?php
/**
 * Save one field edited in place in a grid.
 *
 * Deliberately narrow: an allowlist of entity+field pairs, each with its own
 * validation. A generic "update any column" endpoint would be a hole.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-bootstrap.php';

$admin = admin_guard();

if (!is_post()) {
    json_out(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

csrf_verify();

$entity = post_str('entity', 20);
$field  = post_str('field', 30);
$id     = post_int('id');
$raw    = post_str('value', 40);

if ($id <= 0) {
    json_out(['ok' => false, 'error' => 'Missing record.'], 422);
}

$allowed = [
    'product' => ['price', 'mrp'],
    'variant' => ['stock', 'low_stock_at', 'price_override'],
];

if (!isset($allowed[$entity]) || !in_array($field, $allowed[$entity], true)) {
    json_out(['ok' => false, 'error' => 'That field cannot be edited here.'], 422);
}

// ---------------------------------------------------------------- product --
if ($entity === 'product') {
    $value = (float) $raw;
    if ($value < 0) {
        json_out(['ok' => false, 'error' => 'Price cannot be negative.'], 422);
    }

    $p = db_row('SELECT price, mrp FROM products WHERE id = ?', [$id]);
    if (!$p) {
        json_out(['ok' => false, 'error' => 'Product not found.'], 404);
    }

    // MRP below the selling price would render a negative discount badge.
    if ($field === 'mrp' && $value > 0 && $value < (float) $p['price']) {
        json_out(['ok' => false, 'error' => 'MRP cannot be below the selling price.'], 422);
    }
    if ($field === 'price' && (float) $p['mrp'] > 0 && $value > (float) $p['mrp']) {
        json_out(['ok' => false, 'error' => 'Price cannot be above the MRP. Raise the MRP first.'], 422);
    }
    if ($field === 'price' && $value <= 0) {
        json_out(['ok' => false, 'error' => 'Price must be more than zero.'], 422);
    }

    db_exec("UPDATE products SET {$field} = ? WHERE id = ?", [$value, $id]);
    admin_log('product_inline', 'product', $id, $field . ' = ' . $value);

    json_out(['ok' => true, 'value' => number_format($value, 2, '.', '')]);
}

// ---------------------------------------------------------------- variant --
$v = db_row('SELECT id, stock, product_id, sku FROM product_variants WHERE id = ?', [$id]);
if (!$v) {
    json_out(['ok' => false, 'error' => 'Shade not found.'], 404);
}

if ($field === 'price_override') {
    $value = $raw === '' ? null : (float) $raw;
    if ($value !== null && $value < 0) {
        json_out(['ok' => false, 'error' => 'Price cannot be negative.'], 422);
    }
    db_exec('UPDATE product_variants SET price_override = ? WHERE id = ?', [$value, $id]);
    admin_log('variant_inline', 'product', (int) $v['product_id'], $v['sku'] . ' price_override');
    json_out(['ok' => true, 'value' => $value === null ? '' : number_format($value, 2, '.', '')]);
}

$value = (int) $raw;
if ($value < 0) {
    json_out(['ok' => false, 'error' => 'Stock cannot be negative.'], 422);
}

if ($field === 'stock') {
    $before = (int) $v['stock'];
    db_exec('UPDATE product_variants SET stock = ? WHERE id = ?', [$value, $id]);

    if ($before !== $value) {
        // Every stock change is auditable, however it was made.
        db_exec(
            'INSERT INTO stock_movements (variant_id, delta, reason, note, admin_id)
             VALUES (?,?,"manual","Inline edit",?)',
            [$id, $value - $before, (int) $admin['id']]
        );
    }
    admin_log('variant_stock', 'product', (int) $v['product_id'],
              $v['sku'] . ': ' . $before . ' -> ' . $value);
} else {
    db_exec('UPDATE product_variants SET low_stock_at = ? WHERE id = ?', [$value, $id]);
}

json_out(['ok' => true, 'value' => (string) $value]);
