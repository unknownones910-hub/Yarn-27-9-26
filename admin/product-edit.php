<?php
/**
 * Product editor — the screen the gate is measured against: a non-technical
 * person must be able to add a yarn with eight shades here without help.
 *
 * Shades are edited as repeatable rows in the same form, so adding eight of
 * them is eight clicks and no page reloads.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-bootstrap.php';
require_once YN_ROOT . '/includes/catalog.php';
require_once YN_ROOT . '/includes/upload.php';

$admin = admin_guard();

$id      = get_int('id') ?: post_int('id');
$product = $id > 0 ? db_row('SELECT * FROM products WHERE id = ?', [$id]) : null;
$isNew   = $product === null;

if ($id > 0 && !$product) {
    http_response_code(404);
    flash('That product does not exist.', 'error');
    redirect('admin/products.php');
}

$errors = [];

// ---------------------------------------------------------------- POST --
if (is_post()) {
    csrf_verify();
    $action = post_str('action', 20);

    // ---- delete ------------------------------------------------------
    if ($action === 'delete' && $id > 0) {
        if (!admin_can('manager')) {
            flash('Only a manager or owner can delete a product.', 'error');
            redirect('admin/product-edit.php?id=' . $id);
        }
        $sold = (int) db_val('SELECT COUNT(*) FROM order_items WHERE product_id = ?', [$id]);
        if ($sold > 0) {
            // Deleting would orphan invoice lines. Hide it instead.
            db_exec('UPDATE products SET is_active = 0 WHERE id = ?', [$id]);
            admin_log('product_hide', 'product', $id, 'has ' . $sold . ' order lines');
            flash('This product appears on ' . $sold . ' past order line'
                . ($sold === 1 ? '' : 's') . ', so it was hidden rather than deleted — '
                . 'old invoices must keep working.', 'warn');
        } else {
            foreach (db_col('SELECT image FROM product_variants WHERE product_id = ?', [$id]) as $img) {
                upload_delete($img);
            }
            foreach (db_col('SELECT path FROM product_images WHERE product_id = ?', [$id]) as $img) {
                upload_delete($img);
            }
            db_exec('DELETE FROM products WHERE id = ?', [$id]);
            admin_log('product_delete', 'product', $id);
            flash('Product deleted.', 'success');
            redirect('admin/products.php');
        }
        redirect('admin/product-edit.php?id=' . $id);
    }

    // ---- permanent delete, even when it has been ordered -------------
    // The safe default above hides instead, which is right after launch and
    // wrong while you are clearing out the test products you ordered during
    // setup. Owner only, and honest about the consequence: order_items keeps
    // its OWN copy of name, sku, shade, price and image — there is no foreign
    // key to products — so invoices are untouched. What is lost is the link
    // from an order line back to this page, and product-grouped reporting.
    if ($action === 'purge' && $id > 0) {
        if (!admin_can('owner')) {
            flash('Only the owner can permanently delete a product.', 'error');
            redirect('admin/product-edit.php?id=' . $id);
        }

        $lines = (int) db_val('SELECT COUNT(*) FROM order_items WHERE product_id = ?', [$id]);
        $pname = (string) ($product['name'] ?? '');

        foreach (db_col('SELECT image FROM product_variants WHERE product_id = ?', [$id]) as $img) {
            upload_delete($img);
        }
        foreach (db_col('SELECT path FROM product_images WHERE product_id = ?', [$id]) as $img) {
            upload_delete($img);
        }

        // Cut the dangling reference so nothing later tries to follow it, but
        // leave every denormalised column on the line exactly as it is.
        db_exec('UPDATE order_items SET product_id = NULL WHERE product_id = ?', [$id]);
        db_exec('DELETE FROM products WHERE id = ?', [$id]);

        admin_log('product_purge', 'product', $id,
                  $pname . ' — permanently deleted, ' . $lines . ' order line(s) detached');
        flash('“' . $pname . '” was permanently deleted. The ' . $lines . ' order line'
            . ($lines === 1 ? '' : 's') . ' referencing it keep their own record, so invoices '
            . 'are unaffected.', 'success');
        redirect('admin/products.php');
    }

    // ---- save --------------------------------------------------------
    $name = post_str('name', 180);
    if (mb_strlen($name) < 2) {
        $errors['name'] = 'Give the product a name.';
    }

    $skuBase = strtoupper(post_str('sku_base', 40));
    if ($skuBase === '') {
        $skuBase = 'YN-' . strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $name) ?: 'YARN', 0, 4));
    }

    $price = (float) post_str('price', 12);
    if ($price <= 0) {
        $errors['price'] = 'Enter a selling price.';
    }
    $mrp = (float) post_str('mrp', 12);
    if ($mrp > 0 && $mrp < $price) {
        $errors['mrp'] = 'MRP cannot be lower than the selling price.';
    }
    if ($mrp <= 0) {
        $mrp = $price;
    }

    $slug = post_str('slug', 160);
    $slug = $slug !== '' ? slugify($slug) : slugify($name);

    if (!$errors) {
        $weight = post_str('yarn_weight', 20);
        if (!array_key_exists($weight, yarn_weights())) {
            $weight = null;
        }

        $fields = [
            'category_id'       => post_int('category_id') ?: null,
            'sku_base'          => $skuBase,
            'name'              => $name,
            'subtitle'          => post_str('subtitle', 180) ?: null,
            'brand'             => post_str('brand', 80) ?: null,
            'short_desc'        => post_str('short_desc', 400) ?: null,
            'long_desc'         => post_str('long_desc', 6000) ?: null,
            'fiber'             => post_str('fiber', 140) ?: null,
            'yarn_weight'       => $weight,
            'ply'               => post_str('ply', 20) ?: null,
            'ball_weight_g'     => post_int('ball_weight_g') ?: null,
            'length_m'          => post_int('length_m') ?: null,
            'hook_mm'           => post_str('hook_mm', 5) !== '' ? (float) post_str('hook_mm', 5) : null,
            'needle_mm'         => post_str('needle_mm', 5) !== '' ? (float) post_str('needle_mm', 5) : null,
            'gauge'             => post_str('gauge', 80) ?: null,
            'care_text'         => post_str('care_text', 255) ?: null,
            'country_of_origin' => post_str('country_of_origin', 60) ?: 'India',
            'mrp'               => $mrp,
            'price'             => $price,
            'hsn_code'          => post_str('hsn_code', 12) ?: null,
            'is_active'         => empty($_POST['is_active']) ? 0 : 1,
            'is_featured'       => empty($_POST['is_featured']) ? 0 : 1,
            'is_new'            => empty($_POST['is_new']) ? 0 : 1,
            'meta_title'        => post_str('meta_title', 180) ?: null,
            'meta_description'  => post_str('meta_description', 320) ?: null,
        ];

        if ($isNew) {
            $fields['slug'] = unique_slug($slug, 'products');
            $cols = implode(',', array_keys($fields));
            $ph   = implode(',', array_fill(0, count($fields), '?'));
            $id   = db_insert("INSERT INTO products ({$cols}) VALUES ({$ph})", array_values($fields));
            admin_log('product_create', 'product', $id, $name);
        } else {
            $fields['slug'] = unique_slug($slug, 'products', $id);
            $set = implode(', ', array_map(static fn($k) => "{$k} = ?", array_keys($fields)));
            db_exec("UPDATE products SET {$set} WHERE id = ?", array_merge(array_values($fields), [$id]));
            admin_log('product_update', 'product', $id, $name);
        }

        // ---- categories ----------------------------------------------
        $catIds = array_values(array_filter(array_map('intval', (array) ($_POST['categories'] ?? []))));
        db_exec('DELETE FROM product_categories WHERE product_id = ?', [$id]);
        foreach ($catIds as $cid) {
            db_exec('INSERT IGNORE INTO product_categories (product_id, category_id) VALUES (?,?)',
                    [$id, $cid]);
        }
        // The primary category is always a member too.
        if (!empty($fields['category_id'])) {
            db_exec('INSERT IGNORE INTO product_categories (product_id, category_id) VALUES (?,?)',
                    [$id, (int) $fields['category_id']]);
        }

        // ---- shades ---------------------------------------------------
        $shades   = (array) ($_POST['shade'] ?? []);
        $sortIdx  = 0;
        $skuTaken = [];

        foreach ($shades as $key => $s) {
            $shadeName = trim((string) ($s['shade_name'] ?? ''));
            $remove    = ($s['remove'] ?? '0') === '1';
            $variantId = (int) ($s['id'] ?? 0);

            if ($shadeName === '' && $variantId === 0) {
                continue;   // blank new row — ignore
            }

            if ($remove && $variantId > 0) {
                $used = (int) db_val('SELECT COUNT(*) FROM order_items WHERE variant_id = ?', [$variantId]);
                if ($used > 0) {
                    // Keep the row so old orders still resolve; just retire it.
                    db_exec('UPDATE product_variants SET is_active = 0 WHERE id = ? AND product_id = ?',
                            [$variantId, $id]);
                } else {
                    $img = db_val('SELECT image FROM product_variants WHERE id = ?', [$variantId]);
                    upload_delete($img ? (string) $img : null);
                    db_exec('DELETE FROM product_variants WHERE id = ? AND product_id = ?', [$variantId, $id]);
                }
                continue;
            }
            if ($remove) {
                continue;
            }

            $hex = strtoupper(trim((string) ($s['color_hex'] ?? '')));
            if (!preg_match('/^#[0-9A-F]{6}$/', $hex)) {
                $hex = '#CCCCCC';
            }

            $family = (string) ($s['color_family'] ?? 'multi');
            if (!array_key_exists($family, color_families())) {
                $family = 'multi';
            }

            $sku = strtoupper(trim((string) ($s['sku'] ?? '')));
            if ($sku === '') {
                $sku = $skuBase . '-' . str_pad((string) ($sortIdx + 1), 2, '0', STR_PAD_LEFT);
            }
            // SKUs are globally unique; nudge duplicates rather than failing the save.
            $guard = 0;
            while (isset($skuTaken[$sku])
                   || db_val('SELECT id FROM product_variants WHERE sku = ? AND id <> ?',
                             [$sku, $variantId])) {
                $guard++;
                $sku = $skuBase . '-' . str_pad((string) ($sortIdx + 1 + $guard), 2, '0', STR_PAD_LEFT);
                if ($guard > 50) {
                    $sku = $skuBase . '-' . bin2hex(random_bytes(2));
                    break;
                }
            }
            $skuTaken[$sku] = true;

            // ---- this shade's own photo ------------------------------
            // Until one is uploaded the shop draws a yarn ball in color_hex,
            // which is a placeholder, not a product photo. A real picture of
            // the actual skein is what sells a colour.
            $existingImg = $variantId > 0
                ? (string) (db_val('SELECT image FROM product_variants WHERE id = ?', [$variantId]) ?? '')
                : '';
            $shadeImg = $existingImg;

            // $_FILES for shade[0][image] arrives shredded across five parallel
            // arrays, so reassemble one normal file array before using it.
            $up = $_FILES['shade']['name'][$key]['image'] ?? null;
            if ($up !== null && ($_FILES['shade']['error'][$key]['image'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $one = [
                    'name'     => $_FILES['shade']['name'][$key]['image'],
                    'type'     => $_FILES['shade']['type'][$key]['image'],
                    'tmp_name' => $_FILES['shade']['tmp_name'][$key]['image'],
                    'error'    => $_FILES['shade']['error'][$key]['image'],
                    'size'     => $_FILES['shade']['size'][$key]['image'],
                ];
                $res = upload_image($one, 'shades');
                if ($res['ok']) {
                    upload_delete($existingImg !== '' ? $existingImg : null);   // replace, do not orphan
                    $shadeImg = $res['path'];
                } else {
                    flash('Photo for “' . $shadeName . '” was not saved: ' . $res['error'], 'warn');
                }
            } elseif (!empty($s['image_remove'])) {
                upload_delete($existingImg !== '' ? $existingImg : null);
                $shadeImg = '';
            }

            $row = [
                'product_id'   => $id,
                'sku'          => $sku,
                'shade_name'   => $shadeName !== '' ? $shadeName : 'Shade ' . ($sortIdx + 1),
                'shade_code'   => trim((string) ($s['shade_code'] ?? '')) ?: null,
                'color_hex'    => $hex,
                'color_family' => $family,
                'dye_lot'      => trim((string) ($s['dye_lot'] ?? '')) ?: null,
                'image'        => $shadeImg !== '' ? $shadeImg : null,
                'stock'        => max(0, (int) ($s['stock'] ?? 0)),
                'low_stock_at' => max(0, (int) ($s['low_stock_at'] ?? 5)),
                'is_active'    => empty($s['is_active']) ? 0 : 1,
                'sort_order'   => $sortIdx,
            ];

            if ($variantId > 0) {
                // Stock edits from here are auditable like any other movement.
                $before = (int) db_val('SELECT stock FROM product_variants WHERE id = ?', [$variantId]);
                $set = implode(', ', array_map(static fn($k) => "{$k} = ?", array_keys($row)));
                db_exec("UPDATE product_variants SET {$set} WHERE id = ? AND product_id = ?",
                        array_merge(array_values($row), [$variantId, $id]));

                if ($before !== $row['stock']) {
                    db_exec('INSERT INTO stock_movements (variant_id, delta, reason, note, admin_id)
                             VALUES (?,?,"manual","Edited in product editor",?)',
                            [$variantId, $row['stock'] - $before, (int) $admin['id']]);
                }
            } else {
                $cols = implode(',', array_keys($row));
                $ph   = implode(',', array_fill(0, count($row), '?'));
                $newVid = db_insert("INSERT INTO product_variants ({$cols}) VALUES ({$ph})",
                                    array_values($row));
                if ($row['stock'] > 0) {
                    db_exec('INSERT INTO stock_movements (variant_id, delta, reason, note, admin_id)
                             VALUES (?,?,"manual","Opening stock",?)',
                            [$newVid, $row['stock'], (int) $admin['id']]);
                }
            }
            $sortIdx++;
        }

        // Product-level photo uploads were removed deliberately. One photo per
        // shade, uploaded on the shade row, is the whole model — see the Photos
        // panel. The `product_images` table is left alone rather than dropped so
        // nothing already uploaded is destroyed.

        // Say which shades still have no photograph. A warning, not a block:
        // you should be able to draft a product before the photo shoot.
        $missing = db_col(
            'SELECT shade_name FROM product_variants
              WHERE product_id = ? AND is_active = 1 AND (image IS NULL OR image = "")
              ORDER BY sort_order, id',
            [$id]
        );
        if ($missing) {
            flash(count($missing) . ' shade' . (count($missing) === 1 ? '' : 's')
                . ' still show a drawn placeholder instead of a photo: '
                . implode(', ', $missing) . '.', 'warn');
        }

        flash($isNew ? 'Product created.' : 'Changes saved.', 'success');
        redirect('admin/product-edit.php?id=' . $id);
    }
}

// ---------------------------------------------------------------- read --
$product  = $id > 0 ? db_row('SELECT * FROM products WHERE id = ?', [$id]) : null;
$variants = $id > 0 ? db_all('SELECT * FROM product_variants WHERE product_id = ? ORDER BY sort_order, id', [$id]) : [];
$images   = $id > 0 ? db_all('SELECT * FROM product_images WHERE product_id = ? ORDER BY sort_order, id', [$id]) : [];
$myCats   = $id > 0 ? array_map('intval', db_col('SELECT category_id FROM product_categories WHERE product_id = ?', [$id])) : [];

$allCats = db_all('SELECT id, name, slug, kind FROM categories WHERE is_active = 1 ORDER BY kind, sort_order, name');
$byKind  = [];
foreach ($allCats as $c) {
    $byKind[$c['kind']][] = $c;
}

$v = static fn(string $k, $d = '') => e((string) ($product[$k] ?? $d));

$pageTitle    = $isNew ? 'New product' : (string) $product['name'];
$adminSection = 'products';

require_once YN_ROOT . '/includes/admin-header.php';
?>

<div class="admin-head">
  <div class="min0">
    <p class="text-sm mb1"><a href="<?= e(url('admin/products.php')) ?>">
      <?= icon('arrow-left', 13) ?> All products</a></p>
    <h1><?= $isNew ? 'New product' : e((string) $product['name']) ?></h1>
    <?php if (!$isNew): ?>
      <p><a href="<?= e(url('product/' . $product['slug'])) ?>" target="_blank" rel="noopener">
        View in shop <?= icon('external', 12) ?></a></p>
    <?php endif; ?>
  </div>
</div>

<?php if ($errors): ?>
  <div class="alert alert-error mb4"><?= icon('alert', 18) ?>
    <div><?= e(implode(' ', $errors)) ?></div></div>
<?php endif; ?>

<form method="post" action="<?= e(url('admin/product-edit.php' . ($id ? '?id=' . $id : ''))) ?>"
      enctype="multipart/form-data">
  <?= csrf_field() ?>
  <?php if ($id): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>
  <input type="hidden" name="action" value="save">

  <div class="admin-cols">
    <div class="min0">

      <!-- basics -->
      <div class="panel">
        <h2>The basics</h2>
        <div class="field">
          <label class="label" for="name">Product name <span class="req">*</span> <?= help_tip('What the customer sees, e.g. Merino Wool Fingering. Include the fibre and weight if it helps them search.', 'Product name') ?></label>
          <input class="input" id="name" name="name" required data-slug-source
                 value="<?= $v('name') ?>" placeholder="Milk Cotton DK">
        </div>
        <div class="field">
          <label class="label" for="subtitle">One-line description <?= help_tip('One short line under the name on the product page, e.g. 100% superwash merino, 400m per ball.', 'One-line description') ?></label>
          <input class="input" id="subtitle" name="subtitle" value="<?= $v('subtitle') ?>"
                 placeholder="Soft, round and squishy — the everyday favourite">
        </div>
        <div class="field-row field-row--2">
          <div class="field">
            <label class="label" for="brand">Brand <?= help_tip('The mill or brand that made the yarn. Leave blank if it is unbranded or your own.', 'Brand') ?></label>
            <input class="input" id="brand" name="brand" value="<?= $v('brand') ?>"
                   placeholder="<?= e(setting('store_name')) ?>">
          </div>
          <div class="field">
            <label class="label" for="sku_base">SKU prefix <?= help_tip('A short code that starts every shade SKU for this product, e.g. YN-MER. Each shade then gets YN-MER-01 and so on.', 'SKU prefix') ?></label>
            <input class="input" id="sku_base" name="sku_base" value="<?= $v('sku_base') ?>"
                   placeholder="YN-MCD" style="text-transform:uppercase">
            <span class="help">Shade SKUs are built from this, e.g. YN-MCD-01.</span>
          </div>
        </div>
        <div class="field">
          <label class="label" for="short_desc">Short description <?= help_tip('Two or three sentences shown near the top of the product page and on category listings.', 'Short description') ?></label>
          <textarea class="textarea" id="short_desc" name="short_desc" rows="2"
                    style="min-height:70px"><?= $v('short_desc') ?></textarea>
          <span class="help">Shown near the top of the product page.</span>
        </div>
        <div class="field mb0">
          <label class="label" for="long_desc">Full description <?= help_tip('The full description further down the page. Say how it feels, what it suits, and how it behaves when washed.', 'Full description') ?></label>
          <textarea class="textarea" id="long_desc" name="long_desc" rows="6"><?= $v('long_desc') ?></textarea>
          <span class="help">What it is good for, and what it is not. Line breaks are kept.</span>
        </div>
      </div>

      <!-- yarn specs -->
      <div class="panel">
        <h2>Yarn details</h2>
        <p class="text-sm muted" style="margin-top:calc(var(--s4) * -1)">
          These show in the "Yarn details" table on the product page. Leave anything blank
          and it is simply not shown.
        </p>
        <div class="field">
          <label class="label" for="fiber">Fibre composition <?= help_tip('Exact composition, e.g. 80% cotton, 20% polyester. Customers with allergies and strict knitters both rely on this being accurate.', 'Fibre composition') ?></label>
          <input class="input" id="fiber" name="fiber" value="<?= $v('fiber') ?>"
                 placeholder="100% Mercerised Cotton">
        </div>
        <div class="field-row field-row--3">
          <div class="field">
            <label class="label" for="yarn_weight">Weight category <?= help_tip('The thickness category, e.g. DK or Aran. This drives the shop filter and the yarn calculator.', 'Weight category') ?></label>
            <select class="select" id="yarn_weight" name="yarn_weight">
              <option value="">Not specified</option>
              <?php foreach (yarn_weights() as $k => $w): ?>
                <option value="<?= e($k) ?>" <?= ($product['yarn_weight'] ?? '') === $k ? 'selected' : '' ?>>
                  <?= e($w['label'] . ' · CYC ' . $w['cyc']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field">
            <label class="label" for="ply">Ply <?= help_tip('The Indian or Australian ply number if you use one, e.g. 8. Optional, and it is only a naming convention.', 'Ply') ?></label>
            <input class="input" id="ply" name="ply" value="<?= $v('ply') ?>" placeholder="8-ply">
          </div>
          <div class="field">
            <label class="label" for="country_of_origin">Country of origin <?= help_tip('Where the yarn was made. Legally required on packaged goods sold in India, so it must not be blank.', 'Country of origin') ?></label>
            <input class="input" id="country_of_origin" name="country_of_origin"
                   value="<?= $v('country_of_origin', 'India') ?>">
            <span class="help">Legally required on the listing.</span>
          </div>
        </div>
        <div class="field-row field-row--2">
          <div class="field">
            <label class="label" for="ball_weight_g">Ball weight (g) <?= help_tip('Grams in one ball, e.g. 100. Used with length to work out how many balls a project needs.', 'Ball weight (g)') ?></label>
            <input class="input" id="ball_weight_g" name="ball_weight_g" type="number" min="0"
                   value="<?= $v('ball_weight_g') ?>" placeholder="50">
          </div>
          <div class="field">
            <label class="label" for="length_m">Length per ball (m) <?= help_tip('Metres in one ball, e.g. 250. This is the number that actually matters when substituting one yarn for another.', 'Length per ball (m)') ?></label>
            <input class="input" id="length_m" name="length_m" type="number" min="0"
                   value="<?= $v('length_m') ?>" placeholder="125">
          </div>
        </div>
        <div class="field-row field-row--3">
          <div class="field">
            <label class="label" for="hook_mm">Hook (mm) <?= help_tip('Recommended crochet hook size in millimetres, e.g. 4. Taken from the ball band.', 'Hook (mm)') ?></label>
            <input class="input" id="hook_mm" name="hook_mm" type="number" step="0.1" min="0"
                   value="<?= $v('hook_mm') ?>" placeholder="4.0">
          </div>
          <div class="field">
            <label class="label" for="needle_mm">Needle (mm) <?= help_tip('Recommended knitting needle size in millimetres, e.g. 4. Taken from the ball band.', 'Needle (mm)') ?></label>
            <input class="input" id="needle_mm" name="needle_mm" type="number" step="0.1" min="0"
                   value="<?= $v('needle_mm') ?>" placeholder="4.0">
          </div>
          <div class="field">
            <label class="label" for="gauge">Gauge <?= help_tip('Stitches and rows over 10cm at the recommended hook, e.g. 22 sts x 30 rows. Lets people check a pattern will work.', 'Gauge') ?></label>
            <input class="input" id="gauge" name="gauge" value="<?= $v('gauge') ?>"
                   placeholder="22 sts x 28 rows / 10 cm">
          </div>
        </div>
        <div class="field mb0">
          <label class="label" for="care_text">Care instructions <?= help_tip('How to wash and dry it, in words. The symbols are explained on the yarn guide page.', 'Care instructions') ?></label>
          <input class="input" id="care_text" name="care_text" value="<?= $v('care_text') ?>"
                 placeholder="Machine wash cold. Dry flat in shade.">
        </div>
      </div>

      <!-- shades -->
      <div class="panel">
        <h2>Shades</h2>
        <p class="text-sm muted" style="margin-top:calc(var(--s4) * -1)">
          One row per colour. Each gets its own SKU and stock count.
          Until you upload photos, the shop draws a yarn ball in the colour you pick here.
        </p>

        <!-- Column headings. The row below is nine controls wide and every
             placeholder disappears the moment something is typed, which left
             no way to tell which box was which. -->
        <div class="shade-head" aria-hidden="true">
          <span style="width:44px">Colour</span>
          <span style="flex:2">Shade name <?= help_tip('What the customer sees, e.g. "Terracotta Rose". Use the name, not a code.', 'Shade name') ?></span>
          <span style="flex:1">SKU <?= help_tip('Your own stock code for this exact shade. Must be unique across the shop — it is what appears on invoices and packing slips.', 'SKU') ?></span>
          <span style="width:90px">Stock <?= help_tip('How many balls of this shade you have right now. The shop stops selling it at zero.', 'Stock') ?></span>
        </div>

        <div class="shade-rows" data-shade-rows
             data-next-index="<?= count($variants) ?>"
             data-sku-base="<?= e((string) ($product['sku_base'] ?? 'YN')) ?>">
          <?php foreach ($variants as $i => $sv): ?>
            <div class="shade-row">
              <input type="hidden" name="shade[<?= $i ?>][id]" value="<?= (int) $sv['id'] ?>">
              <input type="hidden" name="shade[<?= $i ?>][remove]" value="0" data-remove-flag>
              <input type="color" name="shade[<?= $i ?>][color_hex]"
                     value="<?= e((string) $sv['color_hex']) ?>" aria-label="Colour">
              <input class="input shade-row__wide" type="text" name="shade[<?= $i ?>][shade_name]"
                     value="<?= e((string) $sv['shade_name']) ?>" placeholder="Shade name"
                     data-shade-name aria-label="Shade name">
              <input class="input" type="text" name="shade[<?= $i ?>][sku]"
                     value="<?= e((string) $sv['sku']) ?>" placeholder="SKU"
                     data-shade-sku aria-label="SKU">
              <input class="input" type="number" name="shade[<?= $i ?>][stock]" min="0"
                     value="<?= (int) $sv['stock'] ?>" placeholder="Stock" aria-label="Stock">
              <button class="icon-btn shade-row__del" type="button" data-remove-shade
                      aria-label="Remove shade"><?= icon('trash', 16) ?></button>

              <div class="shade-row__wide row gap2" style="grid-column:1/-1">
                <!-- This shade's own photo. Without it the shop falls back to a
                     drawn yarn ball in the colour above, which is a placeholder
                     and reads as one. -->
                <label class="shade-photo" title="Photo of this shade">
                  <?php if (!empty($sv['image'])): ?>
                    <img src="<?= e(upload_url((string) $sv['image'])) ?>" alt="" width="38" height="38">
                  <?php else: ?>
                    <span class="shade-photo__empty" style="background:<?= e((string) $sv['color_hex']) ?>">
                      <?= icon('plus', 13) ?></span>
                  <?php endif; ?>
                  <input type="file" name="shade[<?= $i ?>][image]" accept="image/*"
                         data-shade-photo aria-label="Photo for this shade">
                </label>
                <?= help_tip('A photo of THIS shade — the actual skein, not the range. It replaces the drawn colour swatch everywhere the shade appears: product page, cart and order emails. Shoot every shade the same way so the colours compare fairly.', 'Shade photo') ?>
                <?php if (!empty($sv['image'])): ?>
                  <label class="check" style="min-height:auto" title="Remove this photo on save">
                    <input type="checkbox" name="shade[<?= $i ?>][image_remove]" value="1">
                    <span class="text-xs">Clear</span>
                  </label>
                <?php endif; ?>

                <select class="select" name="shade[<?= $i ?>][color_family]"
                        style="width:auto;flex:1" aria-label="Colour family">
                  <?php foreach (color_families() as $k => $cf): ?>
                    <option value="<?= e($k) ?>" <?= $sv['color_family'] === $k ? 'selected' : '' ?>>
                      <?= e($cf['label']) ?></option>
                  <?php endforeach; ?>
                </select>
                <?= help_tip('Groups the shade for the colour filter in the shop. A customer picking Red in the sidebar sees every shade set to Red here, whatever you named it.', 'Colour family') ?>
                <input class="input" name="shade[<?= $i ?>][shade_code]" style="flex:1"
                       value="<?= e((string) $sv['shade_code']) ?>" placeholder="Code" aria-label="Shade code">
                <?= help_tip('The colour number the maker prints on the ball band, if the yarn has one (e.g. 2312). Leave blank if there is none.', 'Code') ?>
                <input class="input" name="shade[<?= $i ?>][dye_lot]" style="flex:1"
                       value="<?= e((string) $sv['dye_lot']) ?>" placeholder="Dye lot" aria-label="Dye lot">
                <?= help_tip('The batch number this stock was dyed in. Two balls from different lots can differ visibly, so record it — customers matching a project will ask.', 'Dye lot') ?>
                <input class="input" type="number" name="shade[<?= $i ?>][low_stock_at]" min="0"
                       style="width:90px" value="<?= (int) $sv['low_stock_at'] ?>"
                       placeholder="Low at" aria-label="Low stock threshold">
                <?= help_tip('Warn me when stock drops to this number. It shows as "Low stock" in the admin so you can reorder before it sells out.', 'Low at') ?>
                <label class="check" style="min-height:auto">
                  <input type="checkbox" name="shade[<?= $i ?>][is_active]" value="1"
                         <?= (int) $sv['is_active'] === 1 ? 'checked' : '' ?>>
                  <span class="text-xs">Live</span>
                </label>
                <?= help_tip('Untick to hide this shade from the shop without deleting it. Past orders keep working.', 'Live') ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>

        <template id="shade-template">
          <div class="shade-row">
            <input type="hidden" name="shade[__IDX__][id]" value="0">
            <input type="color" name="shade[__IDX__][color_hex]" value="#B4552F" aria-label="Colour">
            <input class="input shade-row__wide" type="text" name="shade[__IDX__][shade_name]"
                   placeholder="Shade name" data-shade-name aria-label="Shade name">
            <input class="input" type="text" name="shade[__IDX__][sku]" placeholder="SKU"
                   data-shade-sku aria-label="SKU">
            <input class="input" type="number" name="shade[__IDX__][stock]" min="0" value="0"
                   placeholder="Stock" aria-label="Stock">
            <button class="icon-btn shade-row__del" type="button" data-remove-shade
                    aria-label="Remove shade">&times;</button>
            <div class="shade-row__wide row gap2" style="grid-column:1/-1">
              <label class="shade-photo" title="Photo of this shade">
                <span class="shade-photo__empty" style="background:#B4552F"><?= icon('plus', 13) ?></span>
                <input type="file" name="shade[__IDX__][image]" accept="image/*"
                       data-shade-photo aria-label="Photo for this shade">
              </label>
              <?= help_tip('A photo of THIS shade — the actual skein, not the range. It replaces the drawn colour swatch everywhere the shade appears: product page, cart and order emails.', 'Shade photo') ?>
              <select class="select" name="shade[__IDX__][color_family]" style="width:auto;flex:1"
                      aria-label="Colour family">
                <?php foreach (color_families() as $k => $cf): ?>
                  <option value="<?= e($k) ?>"><?= e($cf['label']) ?></option>
                <?php endforeach; ?>
              </select>
              <?= help_tip('Groups the shade for the colour filter in the shop. A customer picking Red in the sidebar sees every shade set to Red here, whatever you named it.', 'Colour family') ?>
              <input class="input" name="shade[__IDX__][shade_code]" style="flex:1"
                     placeholder="Code" aria-label="Shade code">
              <?= help_tip('The colour number the maker prints on the ball band, if the yarn has one (e.g. 2312). Leave blank if there is none.', 'Code') ?>
              <input class="input" name="shade[__IDX__][dye_lot]" style="flex:1"
                     placeholder="Dye lot" aria-label="Dye lot">
              <?= help_tip('The batch number this stock was dyed in. Two balls from different lots can differ visibly, so record it — customers matching a project will ask.', 'Dye lot') ?>
              <input class="input" type="number" name="shade[__IDX__][low_stock_at]" min="0"
                     style="width:90px" value="5" placeholder="Low at" aria-label="Low stock threshold">
              <?= help_tip('Warn me when stock drops to this number. It shows as "Low stock" in the admin so you can reorder before it sells out.', 'Low at') ?>
              <label class="check" style="min-height:auto">
                <input type="checkbox" name="shade[__IDX__][is_active]" value="1" checked>
                <span class="text-xs">Live</span>
              </label>
              <?= help_tip('Untick to hide this shade from the shop without deleting it. Past orders keep working.', 'Live') ?>
            </div>
          </div>
        </template>

        <button class="btn btn-sm btn-soft mt4" type="button" data-add-shade>
          <?= icon('plus', 15) ?> Add a shade</button>
      </div>

      <!-- photos: one per shade, uploaded in the Shades panel above -->
      <div class="panel">
        <h2>Photos</h2>
        <p class="text-sm muted" style="margin-top:calc(var(--s4) * -1)">
          There is no separate photo gallery. Each shade carries exactly one photo, uploaded
          on its own row above, and those photos <em>are</em> the product gallery — the
          customer switches shade and the picture follows.
        </p>

        <?php if ($variants): ?>
          <div class="img-grid mt4">
            <?php foreach ($variants as $sv): ?>
              <div class="img-tile" title="<?= e((string) $sv['shade_name']) ?>">
                <?php if (!empty($sv['image'])): ?>
                  <img src="<?= e(upload_url((string) $sv['image'])) ?>" alt="" loading="lazy">
                <?php else: ?>
                  <img src="<?= e(yarn_ball_uri((string) $sv['color_hex'])) ?>" alt=""
                       style="opacity:.55">
                <?php endif; ?>
                <span class="img-tile__cap"><?= e((string) $sv['shade_name']) ?></span>
              </div>
            <?php endforeach; ?>
          </div>

          <?php
          $noPhoto = array_values(array_filter($variants, static fn($x) => empty($x['image'])));
          ?>
          <?php if ($noPhoto): ?>
            <div class="alert alert-warn mt4"><?= icon('alert', 18) ?>
              <div>
                <strong><?= count($noPhoto) ?></strong> shade<?= count($noPhoto) === 1 ? '' : 's' ?>
                still show the drawn placeholder rather than a photograph:
                <?= e(implode(', ', array_map(static fn($x) => (string) $x['shade_name'], $noPhoto))) ?>.
                <br>Scroll up and use the square at the start of each shade row.
              </div>
            </div>
          <?php else: ?>
            <p class="text-sm mt4 mb0" style="color:var(--success)">
              <?= icon('check-circle', 15) ?> Every shade has a photo.
            </p>
          <?php endif; ?>
        <?php else: ?>
          <p class="text-sm muted mb0">Add a shade above, then give it a photo.</p>
        <?php endif; ?>
      </div>
      <!-- seo -->
      <div class="panel">
        <h2>Search engine listing</h2>
        <div class="field">
          <label class="label" for="slug">URL <?= help_tip('The web address, e.g. merino-wool-fingering. Changing it after launch breaks any existing links to this product.', 'URL') ?></label>
          <div class="input-group">
            <span class="addon">/product/</span>
            <input class="input" id="slug" name="slug" data-slug-target value="<?= $v('slug') ?>">
          </div>
          <?php if (!$isNew): ?>
            <span class="help">Changing this breaks any existing link to the product.</span>
          <?php endif; ?>
        </div>
        <div class="field">
          <label class="label" for="meta_title">Page title <?= help_tip('The clickable headline in Google results. Around 60 characters. Leave blank to use the product name.', 'Page title') ?></label>
          <input class="input" id="meta_title" name="meta_title" maxlength="180"
                 value="<?= $v('meta_title') ?>" placeholder="<?= $v('name') ?>">
        </div>
        <div class="field mb0">
          <label class="label" for="meta_description">Meta description <?= help_tip('The grey summary under the headline in Google. Around 155 characters. Leave blank and one is generated.', 'Meta description') ?></label>
          <textarea class="textarea" id="meta_description" name="meta_description" rows="2"
                    maxlength="320" style="min-height:64px"><?= $v('meta_description') ?></textarea>
        </div>
      </div>
    </div>

    <!-- ============================================ side -->
    <div class="admin-cols__side">
      <div class="panel">
        <h2>Publish</h2>
        <label class="check">
          <input type="checkbox" name="is_active" value="1"
                 <?= ($product['is_active'] ?? 1) ? 'checked' : '' ?>>
          <span>Show in the shop</span>
        </label>
        <label class="check">
          <input type="checkbox" name="is_new" value="1" <?= ($product['is_new'] ?? 0) ? 'checked' : '' ?>>
          <span>Badge as “New”</span>
        </label>
        <label class="check">
          <input type="checkbox" name="is_featured" value="1"
                 <?= ($product['is_featured'] ?? 0) ? 'checked' : '' ?>>
          <span>Feature on the homepage</span>
        </label>

        <button class="btn btn-block mt4" type="submit">
          <?= $isNew ? 'Create product' : 'Save changes' ?></button>

        <?php if (!$isNew): ?>
          <p class="text-xs muted mt3 mb0">
            Added <?= e(fmt_date((string) $product['created_at'])) ?>
            · <?= (int) $product['sold_count'] ?> sold
          </p>
        <?php endif; ?>
      </div>

      <div class="panel">
        <h2>Pricing</h2>
        <div class="field">
          <label class="label" for="price">Selling price (₹) <span class="req">*</span> <?= help_tip('What the customer actually pays for one ball, in rupees.', 'Selling price (₹)') ?></label>
          <input class="input" id="price" name="price" type="number" step="0.01" min="0" required
                 value="<?= e(number_format((float) ($product['price'] ?? 0), 2, '.', '')) ?>">
        </div>
        <div class="field">
          <label class="label" for="mrp">MRP (₹) <?= help_tip('The higher was-price. Leave blank or equal to the selling price if there is no discount. Anything higher shows a struck-through price and a percentage off.', 'MRP (₹)') ?></label>
          <input class="input" id="mrp" name="mrp" type="number" step="0.01" min="0"
                 value="<?= e(number_format((float) ($product['mrp'] ?? 0), 2, '.', '')) ?>">
          <span class="help">Set above the selling price to show a discount badge. Leave equal for no discount.</span>
        </div>
        <div class="field mb0">
          <label class="label" for="hsn_code">HSN code <?= help_tip('The GST classification code for this product. Only needed once you are GST registered; leave blank until then.', 'HSN code') ?></label>
          <input class="input" id="hsn_code" name="hsn_code" value="<?= $v('hsn_code') ?>"
                 placeholder="5205">
          <span class="help">Only needed once you register for GST.</span>
        </div>
      </div>

      <div class="panel">
        <h2>Categories</h2>
        <div class="field">
          <label class="label" for="category_id">Primary category <?= help_tip('The main category. It sets the breadcrumb and the web address. A product can belong to more categories below.', 'Primary category') ?></label>
          <select class="select" id="category_id" name="category_id">
            <option value="">None</option>
            <?php foreach ($byKind as $kind => $list): ?>
              <optgroup label="<?= e(ucfirst($kind)) ?>">
                <?php foreach ($list as $c): ?>
                  <option value="<?= (int) $c['id'] ?>"
                          <?= (int) ($product['category_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>>
                    <?= e($c['name']) ?></option>
                <?php endforeach; ?>
              </optgroup>
            <?php endforeach; ?>
          </select>
          <span class="help">Used for the breadcrumb and the canonical URL.</span>
        </div>

        <p class="label">Also appears in</p>
        <p class="help" style="margin-top:0">Tick every craft, fibre and weight it suits.</p>
        <div style="max-height:280px;overflow-y:auto">
          <?php foreach ($byKind as $kind => $list): ?>
            <p class="text-xs muted mt3 mb1" style="font-weight:700;text-transform:uppercase">
              <?= e($kind) ?></p>
            <?php foreach ($list as $c): ?>
              <label class="check" style="min-height:34px">
                <input type="checkbox" name="categories[]" value="<?= (int) $c['id'] ?>"
                       <?= in_array((int) $c['id'], $myCats, true) ? 'checked' : '' ?>>
                <span class="text-sm"><?= e($c['name']) ?></span>
              </label>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </div>
      </div>

      <?php if (!$isNew && admin_can('manager')):
          $soldLines = (int) db_val('SELECT COUNT(*) FROM order_items WHERE product_id = ?', [$id]);
      ?>
        <div class="panel" style="border-color:#F0C9C5">
          <h2 style="color:var(--danger)">Danger zone</h2>

          <?php if ($soldLines === 0): ?>
            <p class="text-sm soft">
              This product has never been ordered, so deleting removes it and all its shades
              for good.
            </p>
            <button class="btn btn-sm btn-danger" type="submit" name="action" value="delete"
                    formnovalidate
                    onclick="return confirm('Delete this product and all its shades?')">
              Delete product</button>

          <?php else: ?>
            <p class="text-sm soft">
              This product is on <strong><?= $soldLines ?></strong> past order
              line<?= $soldLines === 1 ? '' : 's' ?>. <strong>Delete</strong> therefore hides it
              from the shop instead of removing it, which is almost always what you want:
              the product stops selling but everything else keeps working.
            </p>
            <button class="btn btn-sm btn-danger" type="submit" name="action" value="delete"
                    formnovalidate
                    onclick="return confirm('Hide this product from the shop?')">
              <?= (int) $product['is_active'] === 1 ? 'Hide from shop' : 'Already hidden' ?></button>

            <?php if (admin_can('owner')): ?>
              <hr style="border:0;border-top:1px solid var(--sand);margin:var(--s4) 0">
              <p class="text-sm soft">
                <strong>Or erase it completely.</strong> Each order line already stores its own
                copy of the name, shade, SKU and price, so <em>invoices and past orders are not
                damaged by this</em> — they keep printing exactly as before. What breaks is the
                link from a customer&rsquo;s order history to this product page, and the product
                disappears from sales reports that group by product.
                <br><br>Useful for clearing out test products you ordered while setting the shop
                up. Rarely right after launch.
              </p>
              <button class="btn btn-sm btn-danger" type="submit" name="action" value="purge"
                      formnovalidate
                      onclick="return confirm('Permanently erase this product and its <?= $soldLines ?> order line reference<?= $soldLines === 1 ? '' : 's' ?>?\n\nInvoices keep their own copy and will still print. This cannot be undone.')">
                Delete permanently</button>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>
</form>

<?php require_once YN_ROOT . '/includes/admin-footer.php'; ?>
