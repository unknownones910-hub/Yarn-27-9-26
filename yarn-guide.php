<?php
/**
 * Yarn guide.
 *
 * Reference content: the CYC weight chart with the ply names actually used in
 * India, a fibre comparison, hook/needle conversions and the care symbols.
 * This is the page that earns links and answers the questions support keeps
 * getting, so every row deep-links into the matching shop filter.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/catalog.php';

$inUse = catalog_weights_in_use();

/** CYC standard, with the Indian ply names people actually search for. */
$weights = [
    'lace'        => ['0', 'Lace',        '1–2 ply',           '32–42 sts', '1.5–2.25', '2.0–2.5',  'Shawls, doilies, fine lacework'],
    'super_fine'  => ['1', 'Super Fine',  '3 ply / Sock',      '27–32 sts', '2.25–3.5', '2.25–3.25','Socks, baby wear, fine gloves'],
    'fine'        => ['2', 'Fine',        '4 ply / Sport',     '23–26 sts', '3.5–4.5',  '3.25–3.75','Light garments, detailed colourwork'],
    'light'       => ['3', 'Light / DK',  '8 ply',             '21–24 sts', '4.5–5.5',  '3.75–4.5', 'Sweaters, amigurumi, accessories'],
    'medium'      => ['4', 'Medium',      '10 ply / Worsted',  '16–20 sts', '5.5–6.5',  '4.5–5.5',  'Everyday makes, blankets, bags'],
    'bulky'       => ['5', 'Bulky',       '12 ply / Chunky',   '12–15 sts', '6.5–9',    '5.5–8',    'Quick scarves, winter hats'],
    'super_bulky' => ['6', 'Super Bulky', '14 ply',            '7–11 sts',  '9–15',     '8–12.75',  'Throws, floor cushions'],
    'jumbo'       => ['7', 'Jumbo',       '16+ ply',           '6 sts',     '15+',      '12.75+',   'Arm knitting, rugs, baskets'],
];

$fibres = [
    ['Cotton',   'Breathable, strong, no stretch', 'Machine wash', 'Summer garments, amigurumi, bags, dishcloths', 'cotton'],
    ['Acrylic',  'Soft, light, very colourfast',   'Machine wash', 'Blankets, toys, learning to crochet',          'acrylic'],
    ['Wool',     'Warm, springy, holds its shape', 'Often hand wash', 'Winter wear, shawls, colourwork',           'wool'],
    ['Bamboo',   'Silky, cool, beautiful drape',   'Gentle wash',  'Summer tops, scarves, baby items',             'bamboo'],
    ['Chenille', 'Velvety and plush',              'Gentle wash',  'Cuddly toys, cushions, throws',                'chenille'],
    ['Jute',     'Coarse, rigid, very strong',     'Spot clean',   'Baskets, plant hangers, macramé',              'jute-cord'],
    ['Silk blend','Lustrous, drapey, luxurious',   'Hand wash',    'Special shawls, heirloom pieces',              'silk-blend'],
    ['Recycled', 'Flecked, characterful, kind',    'Machine wash', 'Bags, rugs, everyday projects',                'recycled'],
];

$hooks = [
    ['2.0', 'B/1', '14'], ['2.25', 'B/1', '13'], ['2.5', '—', '12'], ['3.0', 'D/3', '11'],
    ['3.5', 'E/4', '9'], ['4.0', 'G/6', '8'], ['4.5', '7', '7'], ['5.0', 'H/8', '6'],
    ['5.5', 'I/9', '5'], ['6.0', 'J/10', '4'], ['6.5', 'K/10½', '3'], ['8.0', 'L/11', '0'],
    ['9.0', 'M/13', '00'], ['10.0', 'N/15', '000'],
];

// [icon, title, description]. The icon is the real ISO 3758 symbol — a section
// explaining what the symbols mean is useless if it does not show them.
$care = [
    ['care-wash',       'Machine wash cold',   'Up to 30°C on a gentle cycle. Each dot in the tub is a step up: one is 30°, two 40°, three 60°.'],
    ['care-handwash',   'Hand wash only',      'Lukewarm water, mild detergent, no wringing.'],
    ['care-no-bleach',  'Do not bleach',       'Bleach destroys most natural fibres and yellows acrylic. A crossed-out symbol always means "never".'],
    ['care-dry-flat',   'Dry flat in shade',   'Hanging stretches knitted fabric permanently.'],
    ['care-no-tumble',  'Do not tumble dry',   'Heat felts wool and flattens chenille pile.'],
    ['care-iron',       'Warm iron / no iron', 'Dots are the heat setting. Press through a cloth, or steam from a distance.'],
];

$pageTitle = 'Yarn guide — weights, fibres, hooks and care';
$pageDesc  = 'The CYC yarn weight chart with Indian ply names, a fibre comparison, '
           . 'hook and needle conversions, and what the care symbols mean.';
$canonical = '/yarn-guide.php';

$crumbs = [['Home', url('')], ['Yarn guide', null]];
$jsonLd = [breadcrumb_ld($crumbs)];

require_once YN_ROOT . '/includes/header.php';
?>

<div class="container section-sm">
  <?= breadcrumb_nav($crumbs) ?>

  <header class="mb6" style="max-width:62ch">
    <p class="eyebrow">Reference</p>
    <h1 class="mb2">The yarn guide</h1>
    <p class="lede mb0">
      Everything the ball band assumes you already know — weights, ply names, hook sizes and
      what those little washing symbols mean.
    </p>
  </header>

  <div class="chips mb6">
    <a class="chip" href="#weights">Weights</a>
    <a class="chip" href="#fibres">Fibres</a>
    <a class="chip" href="#hooks">Hook sizes</a>
    <a class="chip" href="#gauge">Gauge</a>
    <a class="chip" href="#substitute">Substituting yarn</a>
    <a class="chip" href="#care">Care symbols</a>
  </div>

  <!-- ===================================================== weights -->
  <section id="weights" class="mb7">
    <h2>Yarn weights</h2>
    <p class="soft" style="max-width:62ch">
      "Weight" means thickness, not how much the ball weighs. India uses ply names, the US uses
      the CYC numbers, and patterns use both — so here they are side by side.
    </p>

    <div class="scroll-x card mt4" style="padding:0">
      <table class="table" style="min-width:720px">
        <thead>
          <tr><th>CYC</th><th>Weight</th><th>Also called</th><th>Gauge / 10cm</th>
            <th>Hook (mm)</th><th>Needle (mm)</th><th>Typically used for</th><th></th></tr>
        </thead>
        <tbody>
          <?php foreach ($weights as $key => [$cyc, $label, $ply, $gauge, $hook, $needle, $use]): ?>
            <tr>
              <td><span class="pill pill-muted"><?= e($cyc) ?></span></td>
              <td><strong><?= e($label) ?></strong></td>
              <td><?= e($ply) ?></td>
              <td class="nowrap"><?= e($gauge) ?></td>
              <td class="nowrap"><?= e($hook) ?></td>
              <td class="nowrap"><?= e($needle) ?></td>
              <td class="text-sm soft"><?= e($use) ?></td>
              <td class="nowrap">
                <?php if (in_array($key, $inUse, true)): ?>
                  <a class="btn-link" href="<?= e(url('shop.php?weight=' . $key)) ?>">Shop →</a>
                <?php else: ?>
                  <span class="text-xs muted">—</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="help mt3">
      Gauge figures are for stocking stitch on the recommended needle. Your own tension will
      vary — which is exactly why you swatch.
    </p>
  </section>

  <!-- ====================================================== fibres -->
  <section id="fibres" class="mb7">
    <h2>Choosing a fibre</h2>
    <div class="scroll-x card mt4" style="padding:0">
      <table class="table" style="min-width:680px">
        <thead><tr><th>Fibre</th><th>How it behaves</th><th>Care</th><th>Best for</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($fibres as [$name, $feel, $careTxt, $best, $slug]): ?>
            <tr>
              <td><strong><?= e($name) ?></strong></td>
              <td class="text-sm soft"><?= e($feel) ?></td>
              <td class="text-sm soft nowrap"><?= e($careTxt) ?></td>
              <td class="text-sm soft"><?= e($best) ?></td>
              <td class="nowrap"><a class="btn-link" href="<?= e(url('category/' . $slug)) ?>">Shop →</a></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- ======================================================= hooks -->
  <section id="hooks" class="mb7">
    <h2>Hook and needle conversions</h2>
    <p class="soft" style="max-width:62ch">
      Indian and European patterns use millimetres. American patterns use letters or numbers,
      and old British patterns use a scale that runs backwards.
    </p>
    <div class="scroll-x card mt4" style="padding:0">
      <table class="table" style="min-width:420px">
        <thead><tr><th>Metric (mm)</th><th>US</th><th>UK / old</th></tr></thead>
        <tbody>
          <?php foreach ($hooks as [$mm, $us, $uk]): ?>
            <tr><td><strong><?= e($mm) ?> mm</strong></td><td><?= e($us) ?></td><td><?= e($uk) ?></td></tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>

  <!-- ======================================================= gauge -->
  <section id="gauge" class="mb7">
    <h2>How to check your gauge</h2>
    <div class="card">
      <ol class="prose" style="padding-left:20px">
        <li>Work a square at least <strong>15 cm across</strong> using the hook the band suggests.</li>
        <li>Wash and dry it the way you will wash the finished item. Cotton in particular moves.</li>
        <li>Lay it flat without stretching. Measure <strong>10 cm</strong> across the middle and
            count the stitches, then do the same vertically for rows.</li>
        <li>More stitches than the pattern wants? Your tension is tight — go up a hook size.
            Fewer? Go down.</li>
      </ol>
      <div class="alert alert-info mt4">
        <?= icon('info', 18) ?>
        <div class="text-sm">
          Ten minutes swatching saves a sweater that fits nobody. It is the single most common
          reason a finished garment comes out the wrong size.
        </div>
      </div>
    </div>
  </section>

  <!-- ================================================= substitute -->
  <section id="substitute" class="mb7">
    <h2>Substituting one yarn for another</h2>
    <div class="grid grid-2">
      <div class="card">
        <h3 style="font-family:var(--font-sans);font-size:1rem">Match these</h3>
        <ul class="stack-sm text-sm mt3">
          <li>· <strong>Weight category</strong> — the single most important match</li>
          <li>· <strong>Meterage per 100g</strong>, within about 10%</li>
          <li>· <strong>Gauge</strong> on the ball band</li>
          <li>· <strong>Fibre behaviour</strong> — cotton will not stretch like wool</li>
        </ul>
      </div>
      <div class="card">
        <h3 style="font-family:var(--font-sans);font-size:1rem">Work out how much</h3>
        <p class="text-sm soft mt3">
          Multiply the number of balls the pattern asks for by its metres per ball to get the
          total metreage. Divide that by the metres in your substitute. Round up, then add
          one ball for safety.
        </p>
        <a class="btn btn-sm btn-soft mt3" href="<?= e(url('yarn-calculator.php')) ?>">
          Use the calculator instead</a>
      </div>
    </div>
  </section>

  <!-- ======================================================== care -->
  <section id="care" class="mb7">
    <h2>What the care symbols mean</h2>
    <div class="grid grid-2 mt4">
      <?php foreach ($care as [$ico, $title, $desc]): ?>
        <div class="row care-item" style="gap:var(--s3);align-items:flex-start">
          <span class="care-sym" style="flex:none"><?= icon($ico, 26) ?></span>
          <span class="min0">
            <strong class="text-sm"><?= e($title) ?></strong><br>
            <span class="text-xs muted"><?= e($desc) ?></span>
          </span>
        </div>
      <?php endforeach; ?>
    </div>
    <p class="help mt4">
      The care instructions for each specific yarn are on its product page — always follow
      those over a general rule.
    </p>
  </section>

  <section class="card center" style="padding-block:var(--s7)">
    <h2>Ready to pick a yarn?</h2>
    <p class="soft" style="margin-inline:auto;max-width:44ch">
      Filter the shop by weight, fibre and colour — or ask us and we will suggest something
      for your project.
    </p>
    <div class="row mt5" style="justify-content:center">
      <a class="btn" href="<?= e(url('shop.php')) ?>">Browse all yarn</a>
      <a class="btn btn-soft" href="<?= e(url('contact.php')) ?>">Ask us</a>
    </div>
  </section>
</div>

<?php require_once YN_ROOT . '/includes/footer.php'; ?>
