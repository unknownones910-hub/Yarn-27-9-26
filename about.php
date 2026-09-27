<?php
/**
 * About.
 *
 * A trust asset, not filler. The founder story and the numbers below are the
 * two things a first-time buyer actually reads before deciding whether a small
 * Indian shop is real.
 *
 * The [bracketed] copy is placeholder — replace it with the owner's own words
 * and a real photograph before launch. Real numbers are pulled from the
 * database so they can never become a lie.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/catalog.php';
require_once YN_ROOT . '/includes/components/product-card.php';

// Honest counters: computed, never typed in — but each one has its own switch,
// because a real number that is very small does more harm than no number.
// "Orders shipped: 1" reads worse than saying nothing at all.
// Admin → Settings → Numbers shown to customers.
//
// The queries only run for counters that are switched on: no point counting
// rows to throw the answer away.
$statSources = [
    'stat_shades'   => ['Shades stocked',
        'SELECT COUNT(*) FROM product_variants v JOIN products p ON p.id = v.product_id
          WHERE v.is_active = 1 AND p.is_active = 1'],
    'stat_products' => ['Yarns &amp; tools',
        'SELECT COUNT(*) FROM products WHERE is_active = 1'],
    'stat_orders'   => ['Orders shipped',
        'SELECT COUNT(*) FROM orders WHERE payment_status = "paid"'],
    'stat_cities'   => ['Cities delivered to',
        'SELECT COUNT(DISTINCT ship_city) FROM orders WHERE payment_status = "paid"'],
];

$stats = [];
foreach ($statSources as $key => [$label, $sql]) {
    if (setting_bool($key, true)) {
        $stats[$label] = (int) db_val($sql);
    }
}

$featured  = featured_products(4);
$pageTitle = 'About us';
$pageDesc  = 'Who we are, how we choose our yarn, and why we photograph every shade in daylight.';
$canonical = '/about.php';

require_once YN_ROOT . '/includes/header.php';
?>

<?php $aboutLines = mascot_greetings($customer ?? null, 'about'); ?>
<section class="hero">
  <div class="container">
    <div class="hero__inner">
      <div class="min0">
        <p class="eyebrow">Our story</p>
        <h1 class="hero__title">We sell the yarn we<br class="hide-xs"> wanted to buy.</h1>
        <p class="hero__text">
          Honest fibre labels, shade photos taken in real daylight, and someone at the other
          end of the phone who actually crochets.
        </p>
      </div>
      <div class="hero__media min0">
        <div class="mascot" data-mascot data-greetings="<?= e(ejs($aboutLines)) ?>">
          <!-- Visible and animated by CSS, so the greeting still appears with
               JavaScript unavailable. JS only cycles the lines. -->
          <p class="mascot__bubble" data-mascot-bubble role="status">
            <span data-mascot-text><?= e($aboutLines[0]) ?></span>
          </p>
          <button type="button" class="mascot__poke" data-mascot-poke
                  aria-label="Nesty, the shop mascot — select to hear more about us">
            <?= mascot_svg('mascot__art', 'about') ?>
          </button>
        </div>
      </div>
    </div>
  </div>
</section>

<div class="container section">
  <div style="max-width:70ch;margin-inline:auto">
    <div class="prose">
      <h2>How this started</h2>
      <p>
        [Replace this with your own story — two or three honest paragraphs. What made you
        start? What frustrated you about buying yarn in India? Buyers read this section more
        carefully than any other page on the site, and it is what makes a small shop feel
        like a person rather than a warehouse.]
      </p>
      <p>
        [A good structure: the problem you kept running into, the moment you decided to do
        something about it, and what you promised yourself the shop would always do.]
      </p>

      <h2>What we stand for</h2>
    </div>

    <div class="grid grid-3 mt5 mb6">
      <div class="card">
        <span class="trust__ico mb3"><?= icon('check-circle', 19) ?></span>
        <h3 style="font-family:var(--font-sans);font-size:1rem;margin:0 0 6px">Honest labels</h3>
        <p class="text-sm soft mb0">
          Exact fibre composition, ball weight and meterage on every product — never a vague
          “soft blend”. If we do not know something, we say so.
        </p>
      </div>
      <div class="card">
        <span class="trust__ico mb3"><?= icon('sparkle', 19) ?></span>
        <h3 style="font-family:var(--font-sans);font-size:1rem;margin:0 0 6px">Real shade photos</h3>
        <p class="text-sm soft mb0">
          Shot in natural daylight, unfiltered. We publish the current dye lot so you can buy
          a whole project from one batch.
        </p>
      </div>
      <div class="card">
        <span class="trust__ico mb3"><?= icon('tag', 19) ?></span>
        <h3 style="font-family:var(--font-sans);font-size:1rem;margin:0 0 6px">Fair, plain prices</h3>
        <p class="text-sm soft mb0">
          The price on the product page is the price you pay. Delivery is shown on the cart,
          never sprung on you at the last step.
        </p>
      </div>
    </div>

    <div class="prose">
      <h2>How we choose what to stock</h2>
      <p>
        Every base is worked up before it goes on the site — a swatch, and usually a small
        finished thing. We are looking for yarn that does not split under a sharp hook, keeps
        its colour through a wash, and feels the way the label claims. If it fails any of
        those, it does not get listed, however good the margin.
      </p>

      <h2>The dye lot thing</h2>
      <p>
        Yarn is dyed in batches and every batch is slightly different. Most shops stay quiet
        about it and deal with the complaints later. We print the current lot on the product
        page and tell you to buy a whole project at once, because finding out three rows from
        the end is genuinely miserable.
      </p>
    </div>
  </div>
</div>

<!-- honest numbers — the whole section disappears when nothing qualifies,
     rather than leaving a heading over an empty grid -->
<?php if ($stats): ?>
<section class="section section--sand">
  <div class="container">
    <div class="sec-head">
      <div class="min0">
        <h2>Where we are today</h2>
        <p>These update themselves. We would rather show a small real number than a made-up big one.</p>
      </div>
    </div>
    <div class="stat-grid">
      <?php foreach ($stats as $label => $n): ?>
        <div class="stat">
          <p class="stat__label"><?= $label ?></p>
          <p class="stat__value"><?= number_format($n) ?></p>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<div class="container section">
  <div class="grid grid-2" style="align-items:center;gap:var(--s7)">
    <div class="min0">
      <p class="eyebrow">The person behind it</p>
      <h2>Who you are actually buying from</h2>
      <p class="soft">
        [Your name, and a sentence or two about you. A real photograph belongs here — faces
        convert better than anything else on a page like this. It does not need to be a
        professional shoot; a clear photo of you in the studio is better than a stock image.]
      </p>
      <p class="soft">
        [If you teach, design or have been crafting for years, say so. Expertise is the reason
        someone trusts your yarn recommendations over a marketplace listing.]
      </p>
      <a class="btn" href="<?= e(url('contact.php')) ?>">Get in touch</a>
    </div>
    <div class="min0">
      <div class="ph-frame">
        <div>
          <span style="color:var(--clay)"><?= icon('user', 40) ?></span>
          <p class="text-sm muted mt3 mb0">Founder photo goes here</p>
          <p class="text-xs muted mb0">Upload it in the admin, or replace this block</p>
        </div>
      </div>
    </div>
  </div>
</div>

<?php if ($featured): ?>
  <div class="container section-sm">
    <div class="sec-head">
      <div class="min0"><h2>Some of our favourites</h2></div>
      <a class="more" href="<?= e(url('shop.php')) ?>">Shop all <?= icon('arrow-right', 15) ?></a>
    </div>
    <?php product_grid($featured); ?>
  </div>
<?php endif; ?>

<section class="section section--sage">
  <div class="container center">
    <h2>Questions before you order?</h2>
    <p class="soft" style="margin-inline:auto;max-width:46ch">
      Ask us anything about a yarn — which hook, how many balls, whether it suits your project.
      That is the part we enjoy most.
    </p>
    <div class="row mt5" style="justify-content:center">
      <a class="btn" href="<?= e(url('contact.php')) ?>">Contact us</a>
      <a class="btn btn-soft" href="<?= e(url('yarn-guide.php')) ?>">Read the yarn guide</a>
    </div>
  </div>
</section>

<?php require_once YN_ROOT . '/includes/footer.php'; ?>
