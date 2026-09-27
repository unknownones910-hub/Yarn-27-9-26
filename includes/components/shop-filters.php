<?php
/**
 * Shop filter panel.
 *
 * One <form method="get"> that works without JavaScript — every control is a
 * real checkbox/input and "Apply" is a real submit. On desktop the panel is a
 * sticky sidebar; below 1024px the same markup becomes a right-hand drawer.
 *
 * Filter state lives entirely in the URL, so results are shareable and the
 * back button behaves.
 */
declare(strict_types=1);

function shop_filters(array $f, array $cats): void
{
    $bounds  = catalog_price_bounds();
    $weights = yarn_weights();
    $inUseW  = catalog_weights_in_use();
    $inUseC  = catalog_color_families_in_use();
    $brands  = catalog_brands();
    $action  = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');

    // Anything not represented by a control must survive the submit.
    $carry = [];
    foreach (['q', 'category', 'collection', 'sort'] as $k) {
        if (!empty($_GET[$k]) && is_string($_GET[$k])) {
            $carry[$k] = $_GET[$k];
        }
    }
    ?>
    <aside class="shop-filters" id="shop-filters" data-responsive-panel>
      <div class="shop-filters__head">
        <h2>Filters</h2>
        <button type="button" class="icon-btn" data-drawer-close aria-label="Close filters">
          <?= icon('close', 20) ?>
        </button>
      </div>

      <form class="shop-filters__body" method="get" action="<?= e($action) ?>" data-no-loading
            data-filter-form>
        <?php foreach ($carry as $k => $v): ?>
          <input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>">
        <?php endforeach; ?>

        <?php if (!empty($cats['craft'])): ?>
        <details class="filter-group" <?= $f['craft'] ? 'open' : 'open' ?>>
          <summary>Craft</summary>
          <div class="filter-group__body">
            <?php foreach ($cats['craft'] as $c): ?>
              <label class="check">
                <input type="checkbox" name="craft[]" value="<?= e($c['slug']) ?>"
                       <?= in_array($c['slug'], $f['craft'], true) ? 'checked' : '' ?>>
                <span><?= e($c['name']) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </details>
        <?php endif; ?>

        <?php if (!empty($cats['fiber'])): ?>
        <details class="filter-group" open>
          <summary>Fibre</summary>
          <div class="filter-group__body">
            <?php foreach ($cats['fiber'] as $c): ?>
              <label class="check">
                <input type="checkbox" name="fiber[]" value="<?= e($c['slug']) ?>"
                       <?= in_array($c['slug'], $f['fiber'], true) ? 'checked' : '' ?>>
                <span><?= e($c['name']) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </details>
        <?php endif; ?>

        <details class="filter-group" open>
          <summary>Yarn weight</summary>
          <div class="filter-group__body">
            <?php foreach ($weights as $key => $w): ?>
              <?php if (!in_array($key, $inUseW, true)) continue; ?>
              <label class="check">
                <input type="checkbox" name="weight[]" value="<?= e($key) ?>"
                       <?= in_array($key, $f['weight'], true) ? 'checked' : '' ?>>
                <span><?= e($w['label']) ?> <span class="muted">· <?= e($w['ply']) ?></span></span>
              </label>
            <?php endforeach; ?>
          </div>
        </details>

        <details class="filter-group" open>
          <summary>Colour</summary>
          <div class="filter-group__body">
            <div class="color-grid">
              <?php foreach (color_families() as $key => $cf): ?>
                <?php if (!in_array($key, $inUseC, true)) continue; ?>
                <label class="color-opt">
                  <input type="checkbox" name="color[]" value="<?= e($key) ?>"
                         <?= in_array($key, $f['color'], true) ? 'checked' : '' ?>>
                  <span class="color-opt__dot" style="background:<?= e($cf['hex']) ?>"></span>
                  <span class="color-opt__label"><?= e($cf['label']) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>
        </details>

        <details class="filter-group" <?= ($f['price_min'] || $f['price_max']) ? 'open' : '' ?>>
          <summary>Price</summary>
          <div class="filter-group__body">
            <div class="price-row">
              <input class="input" type="number" name="price_min" inputmode="numeric"
                     min="<?= $bounds['min'] ?>" max="<?= $bounds['max'] ?>"
                     placeholder="<?= $bounds['min'] ?>"
                     value="<?= $f['price_min'] > 0 ? (int) $f['price_min'] : '' ?>"
                     aria-label="Minimum price">
              <span>to</span>
              <input class="input" type="number" name="price_max" inputmode="numeric"
                     min="<?= $bounds['min'] ?>" max="<?= $bounds['max'] ?>"
                     placeholder="<?= $bounds['max'] ?>"
                     value="<?= $f['price_max'] > 0 ? (int) $f['price_max'] : '' ?>"
                     aria-label="Maximum price">
            </div>
          </div>
        </details>

        <?php if (count($brands) > 1): ?>
        <details class="filter-group" <?= $f['brand'] ? 'open' : '' ?>>
          <summary>Brand</summary>
          <div class="filter-group__body">
            <?php foreach ($brands as $b): ?>
              <label class="check">
                <input type="checkbox" name="brand[]" value="<?= e($b) ?>"
                       <?= in_array($b, $f['brand'], true) ? 'checked' : '' ?>>
                <span><?= e($b) ?></span>
              </label>
            <?php endforeach; ?>
          </div>
        </details>
        <?php endif; ?>

        <details class="filter-group" open>
          <summary>More</summary>
          <div class="filter-group__body">
            <label class="check">
              <input type="checkbox" name="in_stock" value="1" <?= $f['in_stock'] ? 'checked' : '' ?>>
              <span>In stock only</span>
            </label>
            <label class="check">
              <input type="checkbox" name="on_sale" value="1" <?= $f['on_sale'] ? 'checked' : '' ?>>
              <span>On sale</span>
            </label>
            <label class="check">
              <input type="checkbox" name="rated" value="1" <?= $f['rated'] ? 'checked' : '' ?>>
              <span>4 stars &amp; above</span>
            </label>
          </div>
        </details>

        <!-- On the desktop sidebar JS applies each tick immediately and hides
             this, so it is the no-JS fallback. The drawer below 1024px keeps
             its own "Show results" button: reloading on every tick would close
             the drawer each time. -->
        <div class="row mt5 hide-lg-down" data-filter-apply>
          <button class="btn btn-block" type="submit">Apply filters</button>
        </div>
        <?php if (catalog_has_filters($f)): ?>
          <p class="center mt3 hide-lg-down">
            <a class="text-sm" href="<?= e(shop_clear_url()) ?>">Clear all filters</a>
          </p>
        <?php endif; ?>

        <div class="shop-filters__foot">
          <a class="btn btn-soft" href="<?= e(shop_clear_url()) ?>">Clear</a>
          <button class="btn" type="submit">Show results</button>
        </div>
      </form>
    </aside>
    <?php
}

/**
 * Hidden inputs for the current query, minus $except and page.
 * Used by the sort form so changing order keeps every active filter.
 */
function shop_carry_fields(array $except = []): void
{
    $q = $_GET;
    foreach ($except as $k) {
        unset($q[$k]);
    }
    unset($q['page']);

    foreach ($q as $k => $v) {
        $name = (string) $k;
        if (!preg_match('/^[a-z_]+$/', $name)) {
            continue;
        }
        if (is_array($v)) {
            foreach ($v as $item) {
                if (is_scalar($item) && (string) $item !== '') {
                    echo '<input type="hidden" name="' . e($name) . '[]" value="' . e((string) $item) . '">';
                }
            }
            continue;
        }
        if (is_scalar($v) && (string) $v !== '') {
            echo '<input type="hidden" name="' . e($name) . '" value="' . e((string) $v) . '">';
        }
    }
}

/** Current page minus every filter, keeping the category/search context. */
function shop_clear_url(): string
{
    $keep = [];
    foreach (['q', 'category', 'collection', 'sort'] as $k) {
        if (!empty($_GET[$k]) && is_string($_GET[$k])) {
            $keep[$k] = $_GET[$k];
        }
    }
    $base = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');
    $qs   = http_build_query($keep);
    return $base . ($qs !== '' ? '?' . $qs : '');
}

/** Remove one value from a multi-value filter — powers the chip ✕ buttons. */
function filter_remove_url(string $key, string $value): string
{
    $q = $_GET;
    if (isset($q[$key]) && is_array($q[$key])) {
        $q[$key] = array_values(array_filter($q[$key], static fn($v) => (string) $v !== $value));
        if (!$q[$key]) {
            unset($q[$key]);
        }
    } else {
        unset($q[$key]);
    }
    unset($q['page']);
    $base = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');
    $qs   = http_build_query($q);
    return $base . ($qs !== '' ? '?' . $qs : '');
}

/** The chips above the grid describing what is currently filtered. */
function active_filter_chips(array $f): void
{
    if (!catalog_has_filters($f)) {
        return;
    }

    $weights = yarn_weights();
    $colors  = color_families();
    $chips   = [];

    foreach ($f['craft'] as $v)  { $chips[] = ['craft',  $v, ucfirst(str_replace('-', ' ', $v))]; }
    foreach ($f['fiber'] as $v)  { $chips[] = ['fiber',  $v, ucfirst(str_replace('-', ' ', $v))]; }
    foreach ($f['weight'] as $v) { $chips[] = ['weight', $v, $weights[$v]['label'] ?? $v]; }
    foreach ($f['color'] as $v)  { $chips[] = ['color',  $v, $colors[$v]['label'] ?? $v]; }
    foreach ($f['brand'] as $v)  { $chips[] = ['brand',  $v, $v]; }

    if ($f['price_min'] > 0 || $f['price_max'] > 0) {
        $lo = $f['price_min'] > 0 ? money((float) $f['price_min']) : 'Any';
        $hi = $f['price_max'] > 0 ? money((float) $f['price_max']) : 'Any';
        $chips[] = ['price_min', '', $lo . ' – ' . $hi];
    }
    if ($f['in_stock']) { $chips[] = ['in_stock', '', 'In stock']; }
    if ($f['on_sale'])  { $chips[] = ['on_sale',  '', 'On sale']; }
    if ($f['rated'])    { $chips[] = ['rated',    '', '4★ & above']; }
    ?>
    <div class="active-filters">
      <?php foreach ($chips as [$key, $value, $label]): ?>
        <?php
        $removeUrl = $key === 'price_min'
            ? filter_remove_url('price_max', '')   // drop both bounds together
            : filter_remove_url($key, $value);
        if ($key === 'price_min') {
            $q = $_GET; unset($q['price_min'], $q['price_max'], $q['page']);
            $base = strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');
            $qs = http_build_query($q);
            $removeUrl = $base . ($qs !== '' ? '?' . $qs : '');
        }
        ?>
        <a class="chip is-on" href="<?= e($removeUrl) ?>">
          <?= e($label) ?> <?= icon('close', 13) ?>
          <span class="sr-only">Remove filter</span>
        </a>
      <?php endforeach; ?>
      <a class="text-sm" href="<?= e(shop_clear_url()) ?>">Clear all</a>
    </div>
    <?php
}
