<?php
/**
 * "How many balls do I need?"
 *
 * Pure client-side arithmetic over a documented coefficient table — no server
 * round-trip, works offline once loaded. This page exists to be genuinely
 * useful, which is also why it earns links.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/catalog.php';

$inUse = catalog_weights_in_use();

$pageTitle = 'Yarn calculator — how many balls do I need?';
$pageDesc  = 'Work out the metreage and number of balls for a scarf, blanket, sweater, '
           . 'beanie or amigurumi project. Free, no sign-up.';
$canonical = '/yarn-calculator.php';
$ogImage   = asset('img/hero-yarn.svg');

require_once YN_ROOT . '/includes/header.php';
?>

<div class="container section-sm">
  <nav class="breadcrumb" aria-label="Breadcrumb">
    <ol><li><a href="<?= e(url('')) ?>">Home</a></li>
      <li><span aria-current="page">Yarn calculator</span></li></ol>
  </nav>

  <header class="mb6" style="max-width:60ch">
    <p class="eyebrow">Free tool</p>
    <h1 class="mb2">How much yarn do I need?</h1>
    <p class="lede mb0">
      Buying too little is the worst outcome — the next batch is a different dye lot. Work it
      out here first, then buy the whole project in one go.
    </p>
  </header>

  <div class="grid grid-2" style="align-items:start;gap:var(--s7)">
    <div class="min0">
      <div class="card">
        <form id="calc" onsubmit="return false">
          <div class="field">
            <label class="label" for="project">What are you making?</label>
            <select class="select" id="project"></select>
          </div>

          <div class="field">
            <label class="label" for="size">Size</label>
            <select class="select" id="size"></select>
            <span class="help" id="size-note"></span>
          </div>

          <div class="field">
            <label class="label" for="weight">Yarn weight</label>
            <select class="select" id="weight">
              <?php foreach (yarn_weights() as $k => $w): ?>
                <option value="<?= e($k) ?>" <?= $k === 'light' ? 'selected' : '' ?>>
                  <?= e($w['label'] . ' · ' . $w['ply']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="field-row" style="display:grid;gap:var(--s4);grid-template-columns:1fr 1fr">
            <div class="field">
              <label class="label" for="ballw">Ball weight (g)</label>
              <input class="input" id="ballw" type="number" min="1" value="50" inputmode="numeric">
            </div>
            <div class="field">
              <label class="label" for="ballm">Metres per ball</label>
              <input class="input" id="ballm" type="number" min="1" value="125" inputmode="numeric">
              <span class="help">Both are on the ball band.</span>
            </div>
          </div>

          <label class="check">
            <input type="checkbox" id="safety" checked>
            <span>Add 10% safety margin <span class="muted">(recommended)</span></span>
          </label>
        </form>
      </div>

      <div class="alert alert-info mt4">
        <?= icon('info', 18) ?>
        <div class="text-sm">
          These are working estimates from typical stitch consumption. Your tension, stitch
          pattern and how loosely you work all change the answer. Cables and colourwork can
          use 30% more; open lace uses less.
        </div>
      </div>
    </div>

    <!-- ================================================ result -->
    <div class="min0">
      <div class="card card-lift" style="position:sticky;top:calc(var(--header-h) + var(--s4))">
        <p class="text-xs muted mb1">You will need about</p>
        <p style="font-family:var(--font-serif);font-size:clamp(2.5rem,2rem+3vw,3.5rem);
                  font-weight:600;line-height:1;margin:0 0 4px;color:var(--clay)"
           id="balls">—</p>
        <p class="soft mb4" id="balls-label">balls</p>

        <dl class="totals">
          <dt>Total yarn needed</dt><dd id="metres">—</dd>
          <dt>Approximate weight</dt><dd id="grams">—</dd>
          <dt>Per ball</dt><dd id="perball">—</dd>
        </dl>

        <div class="alert alert-warn mt4" style="padding:var(--s3) var(--s4)">
          <?= icon('info', 16) ?>
          <div class="text-xs">
            Buy all of it in one order so every ball comes from the same dye lot.
          </div>
        </div>

        <a class="btn btn-block mt4" id="shop-link" href="<?= e(url('shop.php')) ?>">
          Shop this weight</a>
        <p class="text-xs muted center mt3 mb0" id="stock-note"></p>
      </div>
    </div>
  </div>

  <section class="section-sm">
    <div class="sec-head"><div class="min0"><h2>How this works</h2></div></div>
    <div class="grid grid-3">
      <div class="card">
        <h3 style="font-family:var(--font-sans);font-size:1rem">1 · Area</h3>
        <p class="text-sm soft mb0">
          We start from the finished dimensions of the project at the size you pick.
        </p>
      </div>
      <div class="card">
        <h3 style="font-family:var(--font-sans);font-size:1rem">2 · Consumption</h3>
        <p class="text-sm soft mb0">
          Each yarn weight uses a typical number of metres per 100&nbsp;cm² of fabric.
          Thicker yarn covers more ground per metre.
        </p>
      </div>
      <div class="card">
        <h3 style="font-family:var(--font-sans);font-size:1rem">3 · Balls</h3>
        <p class="text-sm soft mb0">
          Total metres divided by the metres in your ball, rounded up, plus your safety margin.
        </p>
      </div>
    </div>
    <p class="help mt4">
      Prefer to do it yourself? The <a href="<?= e(url('yarn-guide.php')) ?>">yarn guide</a> has
      the substitution maths written out.
    </p>
  </section>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
(function () {
  /* Metres of yarn per 100 cm² of plain fabric, by CYC weight. Derived from
     typical stitch gauge — thicker yarn covers more area per metre. */
  var CONSUMPTION = {
    lace: 3.4, super_fine: 2.6, fine: 2.1, light: 1.6,
    medium: 1.2, bulky: 0.85, super_bulky: 0.6, jumbo: 0.4
  };

  /* Finished dimensions in cm. `factor` accounts for shaping and depth on
     three-dimensional pieces (a beanie is not a flat rectangle). */
  var PROJECTS = {
    scarf:     { label: 'Scarf',            factor: 1,    sizes: {
                   'Skinny (15 × 150 cm)': [15, 150], 'Classic (25 × 180 cm)': [25, 180],
                   'Wide wrap (40 × 200 cm)': [40, 200] } },
    blanket:   { label: 'Blanket',          factor: 1,    sizes: {
                   'Pram (75 × 100 cm)': [75, 100], 'Lap (100 × 130 cm)': [100, 130],
                   'Single bed (150 × 200 cm)': [150, 200], 'Double bed (200 × 220 cm)': [200, 220] } },
    sweater:   { label: 'Sweater',          factor: 2.15, sizes: {
                   'Child (chest 65 cm)': [65, 42], 'Adult S (chest 90 cm)': [90, 60],
                   'Adult M (chest 100 cm)': [100, 64], 'Adult L (chest 112 cm)': [112, 68],
                   'Adult XL (chest 124 cm)': [124, 70] } },
    beanie:    { label: 'Beanie',           factor: 1.15, sizes: {
                   'Baby (40 cm)': [40, 16], 'Child (48 cm)': [48, 19], 'Adult (56 cm)': [56, 22] } },
    shawl:     { label: 'Shawl',            factor: 0.55, sizes: {
                   'Shoulder (150 × 60 cm)': [150, 60], 'Large (190 × 85 cm)': [190, 85] } },
    amigurumi: { label: 'Amigurumi toy',    factor: 1.6,  sizes: {
                   'Tiny (10 cm)': [10, 10], 'Small (18 cm)': [18, 18],
                   'Medium (28 cm)': [28, 28], 'Large (40 cm)': [40, 40] } },
    bag:       { label: 'Bag or basket',    factor: 1.9,  sizes: {
                   'Small pouch (20 × 15 cm)': [20, 15], 'Tote (35 × 40 cm)': [35, 40],
                   'Basket (30 × 25 cm)': [30, 25] } },
    socks:     { label: 'Socks (pair)',     factor: 2.4,  sizes: {
                   'Child': [18, 14], 'Adult': [24, 22] } }
  };

  var $ = function (id) { return document.getElementById(id); };
  var project = $('project'), size = $('size'), weight = $('weight'),
      ballw = $('ballw'), ballm = $('ballm'), safety = $('safety');

  Object.keys(PROJECTS).forEach(function (k) {
    var o = document.createElement('option');
    o.value = k; o.textContent = PROJECTS[k].label;
    project.appendChild(o);
  });
  project.value = 'blanket';

  function fillSizes() {
    size.innerHTML = '';
    var p = PROJECTS[project.value];
    Object.keys(p.sizes).forEach(function (label) {
      var o = document.createElement('option');
      o.value = label; o.textContent = label;
      size.appendChild(o);
    });
    $('size-note').textContent = project.value === 'amigurumi'
      ? 'Height of the finished toy, worked at a tight amigurumi tension.'
      : 'Finished measurements, before blocking.';
  }

  function fmt(n) { return n.toLocaleString('en-IN'); }

  function calculate() {
    var p    = PROJECTS[project.value];
    var dims = p.sizes[size.value];
    if (!dims) return;

    var areaCm2 = dims[0] * dims[1] * p.factor;
    var perW    = CONSUMPTION[weight.value] || 1.6;
    var metres  = areaCm2 / 100 * perW;

    if (safety.checked) metres *= 1.10;

    var mPerBall = Math.max(1, Number(ballm.value) || 1);
    var gPerBall = Math.max(1, Number(ballw.value) || 1);
    var balls    = Math.ceil(metres / mPerBall);
    var grams    = Math.round(balls * gPerBall);

    $('balls').textContent       = fmt(balls);
    $('balls-label').textContent = balls === 1 ? 'ball' : 'balls';
    $('metres').textContent      = fmt(Math.round(metres)) + ' m';
    $('grams').textContent       = fmt(grams) + ' g';
    $('perball').textContent     = gPerBall + ' g · ' + mPerBall + ' m';

    var link = $('shop-link');
    link.href = '/shop.php?weight=' + encodeURIComponent(weight.value);
    link.textContent = 'Shop ' +
      weight.options[weight.selectedIndex].text.split(' · ')[0] + ' yarn';

    $('stock-note').textContent = balls > 12
      ? 'That is a lot of yarn — message us and we will check we have enough in one dye lot.'
      : '';
  }

  ['change', 'input'].forEach(function (ev) {
    [project, size, weight, ballw, ballm, safety].forEach(function (el) {
      el.addEventListener(ev, function () {
        if (el === project) fillSizes();
        calculate();
      });
    });
  });

  fillSizes();
  calculate();
})();
</script>

<?php require_once YN_ROOT . '/includes/footer.php'; ?>
