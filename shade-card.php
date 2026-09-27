<?php
/**
 * Shade card — every colour we stock, in one wall.
 *
 * Answers the question the PDP cannot: "what does this look like next to that?"
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/catalog.php';

$family = get_str('family', 20);
$base   = get_str('base', 160);

$where  = ['v.is_active = 1', 'p.is_active = 1'];
$params = [];

if ($family !== '' && array_key_exists($family, color_families())) {
    $where[]  = 'v.color_family = ?';
    $params[] = $family;
}
if ($base !== '') {
    $where[]  = 'p.slug = ?';
    $params[] = $base;
}

$whereSql = implode(' AND ', $where);

$shades = db_all(
    "SELECT v.id, v.sku, v.shade_name, v.shade_code, v.color_hex, v.color_family,
            v.stock, v.image, p.name AS product_name, p.slug
     FROM product_variants v JOIN products p ON p.id = v.product_id
     WHERE {$whereSql}
     ORDER BY v.color_family, p.name, v.sort_order",
    $params
);

$counts = [];
foreach (db_all('SELECT v.color_family f, COUNT(*) n
                 FROM product_variants v JOIN products p ON p.id = v.product_id
                 WHERE v.is_active = 1 AND p.is_active = 1 GROUP BY v.color_family') as $r) {
    $counts[$r['f']] = (int) $r['n'];
}

$bases = db_all('SELECT DISTINCT p.slug, p.name FROM products p
                 JOIN product_variants v ON v.product_id = p.id
                 WHERE p.is_active = 1 AND v.is_active = 1 ORDER BY p.name');

$total = (int) db_val('SELECT COUNT(*) FROM product_variants v JOIN products p ON p.id = v.product_id
                       WHERE v.is_active = 1 AND p.is_active = 1');

$pageTitle = 'Shade card — every colour we stock';
$pageDesc  = 'All ' . $total . ' shades in one place. Filter by colour family or yarn base, '
           . 'and see what is in stock right now.';
$canonical = '/shade-card.php';

require_once YN_ROOT . '/includes/header.php';
?>

<div class="container section-sm">
  <nav class="breadcrumb" aria-label="Breadcrumb">
    <ol><li><a href="<?= e(url('')) ?>">Home</a></li>
      <li><span aria-current="page">Shade card</span></li></ol>
  </nav>

  <header class="mb5" style="max-width:62ch">
    <p class="eyebrow">All <?= number_format($total) ?> shades</p>
    <h1 class="mb2">Shade card</h1>
    <p class="lede mb0">
      Every colour we stock, side by side. Screens vary, so treat these as a close guide —
      for an exact match, order the physical card below.
    </p>
  </header>

  <div class="chips mb4">
    <a class="chip <?= $family === '' ? 'is-on' : '' ?>"
       href="<?= e(url('shade-card.php' . ($base !== '' ? '?base=' . urlencode($base) : ''))) ?>">
      All colours</a>
    <?php foreach (color_families() as $key => $cf): ?>
      <?php if (empty($counts[$key])) continue; ?>
      <a class="chip <?= $family === $key ? 'is-on' : '' ?>"
         href="<?= e(url('shade-card.php?family=' . $key . ($base !== '' ? '&base=' . urlencode($base) : ''))) ?>">
        <span style="width:12px;height:12px;border-radius:50%;flex:none;
                     background:<?= e($cf['hex']) ?>;box-shadow:0 0 0 1px rgba(0,0,0,.12)"></span>
        <?= e($cf['label']) ?> <span class="muted">· <?= (int) $counts[$key] ?></span>
      </a>
    <?php endforeach; ?>
  </div>

  <form method="get" action="<?= e(url('shade-card.php')) ?>" class="mb6" data-no-loading>
    <?php if ($family !== ''): ?><input type="hidden" name="family" value="<?= e($family) ?>"><?php endif; ?>
    <label class="sr-only" for="base">Filter by yarn</label>
    <select class="select" id="base" name="base" onchange="this.form.submit()" style="max-width:320px">
      <option value="">Every yarn base</option>
      <?php foreach ($bases as $b): ?>
        <option value="<?= e($b['slug']) ?>" <?= $base === $b['slug'] ? 'selected' : '' ?>>
          <?= e($b['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </form>

  <?php if (!$shades): ?>
    <div class="empty">
      <div class="empty__art"><?= icon('sparkle', 40) ?></div>
      <h2>No shades match</h2>
      <a class="btn mt4" href="<?= e(url('shade-card.php')) ?>">Show every shade</a>
    </div>

  <?php else: ?>
    <p class="text-sm muted mb4">Showing <?= count($shades) ?> shades</p>

    <div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(min(130px,100%),1fr));gap:var(--s3)">
      <?php foreach ($shades as $s): ?>
        <?php $out = (int) $s['stock'] < 1; ?>
        <a href="<?= e(url('product/' . $s['slug'] . '?shade=' . rawurlencode((string) $s['sku']))) ?>"
           style="text-decoration:none;min-width:0">
          <span style="display:block;aspect-ratio:1/1;border-radius:var(--r-md);
                       background:<?= e((string) $s['color_hex']) ?>;
                       box-shadow:0 0 0 1px var(--sand-deep) inset;
                       position:relative;<?= $out ? 'opacity:.45' : '' ?>">
            <?php if ($out): ?>
              <span class="badge badge-out" style="position:absolute;bottom:6px;left:6px">Out</span>
            <?php endif; ?>
          </span>
          <span class="text-xs mt2" style="display:block;color:var(--ink);font-weight:600;
                                           overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
            <?= e((string) $s['shade_name']) ?></span>
          <span class="text-xs muted" style="display:block;overflow:hidden;
                                             text-overflow:ellipsis;white-space:nowrap">
            <?= e((string) $s['product_name']) ?></span>
        </a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <section class="card mt7">
    <div class="grid grid-2" style="align-items:center;gap:var(--s5)">
      <div class="min0">
        <p class="eyebrow">Colour-critical project?</p>
        <h2 style="font-size:var(--fs-h3)">Order a physical shade card</h2>
        <p class="soft text-sm">
          Real strands of every shade, mounted on card. No screen can show you how a colour
          behaves in your own light. If you order one and then spend over
          <?= e(money(setting_money('ship_free_threshold', 2499))) ?>, we refund what you paid
          for it — just mention it in the order notes.
        </p>
        <a class="btn" href="<?= e(url('contact.php?subject=Shade+card')) ?>">Request a shade card</a>
      </div>
      <div class="min0">
        <div class="grid" style="grid-template-columns:repeat(4,1fr);gap:6px">
          <?php foreach (array_slice($shades, 0, 12) as $s): ?>
            <span style="aspect-ratio:1/1;border-radius:6px;
                         background:<?= e((string) $s['color_hex']) ?>;
                         box-shadow:0 0 0 1px var(--sand-deep) inset"></span>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </section>

  <div class="alert alert-info mt5">
    <?= icon('info', 18) ?>
    <div class="text-sm">
      <strong>About dye lots.</strong> Shades vary slightly between dyeing batches. Each product
      page shows the current lot — buy all the yarn for one project together so it matches.
      <a href="<?= e(url('yarn-guide.php')) ?>">More in the yarn guide</a>.
    </div>
  </div>
</div>

<?php require_once YN_ROOT . '/includes/footer.php'; ?>
