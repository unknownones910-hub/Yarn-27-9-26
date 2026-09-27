<?php
/**
 * Home.
 *
 * Section order is set out in PLAN.md §6.1. Sections that need catalog data
 * (bestsellers, new arrivals, customer projects, blog) are added in later
 * phases; everything present here is live and reads from the database.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/catalog.php';
require_once YN_ROOT . '/includes/components/product-card.php';

$pageTitle = setting('seo_title');
$pageDesc  = setting('seo_description');
$canonical = '/';

$cats     = nav_categories();
// Every craft, not a slice of four — the picker is meant to be the complete
// list of "what you make", and cutting it off hid Weaving entirely.
$crafts   = $cats['craft'] ?? [];
$fibers   = $cats['fiber'] ?? [];
$weights  = yarn_weights();

$bestsellers = featured_products(8);
$newest      = newest_products(8);

$topReviews = setting_bool('feat_reviews', true)
    ? db_all(
        'SELECT r.rating, r.title, r.body, r.author_name, r.created_at,
                p.name AS product_name, p.slug
         FROM reviews r JOIN products p ON p.id = r.product_id
         WHERE r.status = "approved" AND r.rating >= 4 AND CHAR_LENGTH(r.body) > 40
         ORDER BY r.helpful_count DESC, r.created_at DESC LIMIT 3'
      )
    : [];

$latestPosts = setting_bool('feat_blog', true)
    ? db_all(
        'SELECT slug, title, excerpt, cover, published_at FROM blog_posts
         WHERE status = "published" AND published_at <= NOW()
         ORDER BY published_at DESC LIMIT 3'
      )
    : [];

$jsonLd = [[
    '@context' => 'https://schema.org',
    '@type'    => 'Organization',
    'name'     => setting('store_legal_name'),
    'url'      => abs_url('/'),
    'email'    => setting('store_email'),
    'telephone'=> setting('store_phone'),
    'address'  => [
        '@type'          => 'PostalAddress',
        'streetAddress'  => setting('store_address'),
        'addressCountry' => 'IN',
    ],
], [
    '@context'        => 'https://schema.org',
    '@type'           => 'WebSite',
    'name'            => setting('store_name'),
    'url'             => abs_url('/'),
    'potentialAction' => [
        '@type'       => 'SearchAction',
        'target'      => abs_url('search.php') . '?q={search_term_string}',
        'query-input' => 'required name=search_term_string',
    ],
]];

require_once __DIR__ . '/includes/header.php';
?>

<!-- ============================================================ hero -->
<?php $greetings = mascot_greetings($customer ?? null); ?>
<section class="hero">
  <div class="container">
    <div class="hero__inner">
      <div class="min0">
        <p class="eyebrow"><span class="eyebrow__dot" aria-hidden="true"></span>Yarn for makers, from India</p>
        <h1 class="hero__title">
          Yarn that&rsquo;s a joy<br class="hide-xs">
          to <span class="hero__accent">work with</span>.
        </h1>
        <p class="hero__text">
          Softer than it looks, honest about what&rsquo;s inside. Colours may appear slightly
          different depending on lighting, screen settings and photography.
        </p>
        <div class="hero__cta">
          <a class="btn btn-lg" href="<?= e(url('shop.php')) ?>">Shop all yarn</a>
          <a class="btn btn-ghost btn-lg" href="<?= e(url('shop.php?sort=new')) ?>">New arrivals</a>
        </div>
        <ul class="hero__points">
          <li><?= icon('check', 15) ?>Free delivery over <?= e(money(setting_money('ship_free_threshold', 2499))) ?></li>
          <li><?= icon('check', 15) ?><?= (int) setting_int('return_window_days', 7) ?>-day exchange</li>
          <li><?= icon('check', 15) ?>Daylight shade photos</li>
        </ul>
      </div>

      <div class="hero__media min0">
        <div class="mascot" data-mascot data-greetings="<?= e(ejs($greetings)) ?>">
          <!-- Rendered visible and animated by CSS, so the greeting still
               appears with JavaScript unavailable. JS only cycles the lines. -->
          <p class="mascot__bubble" data-mascot-bubble role="status">
            <span data-mascot-text><?= e($greetings[0]) ?></span>
          </p>
          <button type="button" class="mascot__poke" data-mascot-poke
                  aria-label="Nesty, the shop mascot — select for another greeting">
            <?= mascot_svg() ?>
          </button>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ===================================================== trust strip -->
<section class="trust">
  <div class="container">
    <div class="trust__grid">
      <div class="trust__item">
        <span class="trust__ico"><?= icon('lock', 19) ?></span>
        <div class="min0">
          <h3>Secure payments</h3>
          <p>UPI, cards &amp; netbanking via Razorpay</p>
        </div>
      </div>
      <div class="trust__item">
        <span class="trust__ico"><?= icon('refresh', 19) ?></span>
        <div class="min0">
          <h3><?= (int) setting_int('return_window_days', 7) ?>-day exchange</h3>
          <p>Wrong or damaged items, replaced free</p>
        </div>
      </div>
      <div class="trust__item">
        <span class="trust__ico"><?= icon('truck', 19) ?></span>
        <div class="min0">
          <h3>Ships across India</h3>
          <p>Free above <?= e(money(setting_money('ship_free_threshold', 2499))) ?></p>
        </div>
      </div>
      <div class="trust__item">
        <span class="trust__ico"><?= icon('chat', 19) ?></span>
        <div class="min0">
          <h3>Real human support</h3>
          <p>Reply within one working day</p>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ================================================== shop by craft -->
<?php if ($crafts): ?>
<section class="section">
  <div class="container">
    <div class="picker">

      <div class="picker__intro">
        <div class="picker__art"><?= mascot_svg('picker__mascot', 'pick') ?></div>
        <h2 class="picker__title">Start with what you make</h2>
        <p class="picker__sub">Every yarn is tagged for the craft it actually suits.</p>
        <a class="more" href="<?= e(url('shop.php')) ?>">Browse everything <?= icon('arrow-right', 15) ?></a>
      </div>

      <ul class="picker__list">
        <?php foreach ($crafts as $c): ?>
          <li>
            <a class="picker__item" href="<?= e(url('category/' . $c['slug'])) ?>">
              <span class="picker__thumb">
                <img src="<?= e(upload_url($c['image'])) ?>" alt=""
                     width="400" height="400" loading="lazy" decoding="async">
              </span>
              <span class="picker__name"><?= e($c['name']) ?></span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>

    </div>
  </div>
</section>
<?php endif; ?>

<!-- =================================================== bestsellers -->
<?php if ($bestsellers): ?>
<section class="section">
  <div class="container">
    <div class="sec-head">
      <div class="min0">
        <h2>What people are buying</h2>
        <p>Our most-ordered yarns this month.</p>
      </div>
      <a class="more" href="<?= e(url('shop.php?sort=popular')) ?>">
        See all bestsellers <?= icon('arrow-right', 15) ?></a>
    </div>
    <?php product_grid($bestsellers); ?>
  </div>
</section>
<?php endif; ?>

<!-- ================================================= shop by weight -->
<section class="section section--sand">
  <div class="container">
    <div class="feature">

      <!-- promo panel -->
      <div class="feature__promo">
        <p class="eyebrow"><span class="eyebrow__dot" aria-hidden="true"></span>Weights explained</p>
        <h2 class="feature__title">Not sure which<br class="hide-xs"> weight you need?</h2>
        <p class="feature__text">
          &ldquo;Weight&rdquo; is thickness, not how much the ball weighs. India uses ply names,
          patterns use the CYC numbers — the guide puts them side by side.
        </p>
        <a class="btn" href="<?= e(url('yarn-guide.php')) ?>">Read the yarn guide</a>
      </div>

      <!-- weight rail -->
      <div class="feature__side min0">
        <div class="rail" data-rail role="group" aria-label="Yarn weights">
          <?php $barIndex = 0; foreach ($weights as $key => $w): $barIndex++; ?>
            <a class="wchip" href="<?= e(url('shop.php?weight=' . $key)) ?>">
              <span class="wchip__cyc" aria-hidden="true"><?= e($w['cyc']) ?></span>
              <span class="wchip__bars" aria-hidden="true">
                <?php for ($b = 0; $b < 3; $b++): ?>
                  <i style="height:<?= 6 + ($barIndex * 2.2) ?>px;width:<?= 2 + ($barIndex * .55) ?>px"></i>
                <?php endfor; ?>
              </span>
              <span class="wchip__name"><?= e($w['label']) ?></span>
              <span class="wchip__ply"><?= e($w['ply']) ?></span>
            </a>
          <?php endforeach; ?>
        </div>

        <div class="feature__foot">
          <div class="rail-nav">
            <button class="rail-nav__btn" type="button" data-rail-prev aria-label="Scroll weights left">
              <?= icon('arrow-left', 18) ?></button>
            <button class="rail-nav__btn" type="button" data-rail-next aria-label="Scroll weights right">
              <?= icon('arrow-right', 18) ?></button>
          </div>
          <a class="btn btn-soft" href="<?= e(url('shop.php')) ?>">Shop all yarn</a>
        </div>
      </div>

    </div>
  </div>
</section>

<!-- ================================================== shop by fibre -->
<?php if ($fibers): ?>
<section class="section">
  <div class="container">
    <div class="sec-head">
      <div class="min0">
        <h2>Shop by fibre</h2>
        <p>Every product page lists the exact composition — no vague &ldquo;soft blend&rdquo;.</p>
      </div>
    </div>

    <ul class="fibre-grid">
      <?php foreach ($fibers as $f): ?>
        <li>
          <a class="picker__item" href="<?= e(url('category/' . $f['slug'])) ?>">
            <span class="picker__thumb">
              <img src="<?= e(upload_url($f['image'])) ?>" alt=""
                   width="400" height="400" loading="lazy" decoding="async">
            </span>
            <span class="picker__name"><?= e($f['name']) ?></span>
          </a>
        </li>
      <?php endforeach; ?>
    </ul>
  </div>
</section>
<?php endif; ?>

<!-- =================================================== new arrivals -->
<?php if ($newest): ?>
<section class="section">
  <div class="container">
    <div class="sec-head">
      <div class="min0">
        <h2>Just arrived</h2>
        <p>The newest shades and bases on the shelf.</p>
      </div>
      <a class="more" href="<?= e(url('shop.php?sort=new')) ?>">
        All new arrivals <?= icon('arrow-right', 15) ?></a>
    </div>
    <?php product_grid($newest); ?>
  </div>
</section>
<?php endif; ?>

<!-- ==================================================== reviews -->
<?php if ($topReviews): ?>
<section class="section section--surface">
  <div class="container">
    <div class="sec-head">
      <div class="min0">
        <h2>In their words</h2>
        <p>Every review here comes from someone who actually bought the yarn.</p>
      </div>
      <a class="more" href="<?= e(url('reviews.php')) ?>">Read all <?= icon('arrow-right', 15) ?></a>
    </div>

    <div class="grid grid-3">
      <?php foreach ($topReviews as $r): ?>
        <figure class="card min0" style="margin:0">
          <?= stars_html((float) $r['rating'], 0, false) ?>
          <blockquote class="mt3 mb3" style="margin-inline:0">
            <?php if (!empty($r['title'])): ?>
              <p class="mb2"><strong><?= e((string) $r['title']) ?></strong></p>
            <?php endif; ?>
            <p class="text-sm soft clamp-3 mb0"><?= e(str_excerpt((string) $r['body'], 180)) ?></p>
          </blockquote>
          <figcaption class="text-xs muted">
            <?= e((string) ($r['author_name'] ?: 'A customer')) ?> ·
            <a href="<?= e(url('product/' . $r['slug'])) ?>"><?= e((string) $r['product_name']) ?></a>
          </figcaption>
        </figure>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<!-- ================================================ yarn calculator -->
<section class="section section--sage">
  <div class="container">
    <div class="grid grid-2" style="align-items:center">
      <div class="min0">
        <p class="eyebrow">Free tool</p>
        <h2>Not sure how much yarn you need?</h2>
        <p class="soft">
          Tell us the project and the size, and we&rsquo;ll work out the meterage and how many balls
          that comes to — so you buy once, from one dye lot, and never run out three rows from the end.
        </p>
        <a class="btn" href="<?= e(url('yarn-calculator.php')) ?>">Open the yarn calculator</a>
      </div>
      <div class="min0">
        <div class="card" style="background:var(--surface)">
          <h3 style="font-family:var(--font-sans);font-size:1rem">Also worth a look</h3>
          <ul class="stack-sm mt3">
            <li><a href="<?= e(url('yarn-guide.php')) ?>">Yarn weight chart &amp; hook conversions</a></li>
            <li><a href="<?= e(url('shade-card.php')) ?>">Full shade card — every colour we stock</a></li>
            <li><a href="<?= e(url('patterns.php')) ?>">Free patterns, with the exact yarn listed</a></li>
            <li><a href="<?= e(url('track-order.php')) ?>">Track an order without signing in</a></li>
          </ul>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ======================================================= blog -->
<?php if ($latestPosts): ?>
<section class="section">
  <div class="container">
    <div class="sec-head">
      <div class="min0">
        <h2>From the journal</h2>
        <p>Tutorials, projects and the answers to what we get asked most.</p>
      </div>
      <a class="more" href="<?= e(url('blog.php')) ?>">Read the blog <?= icon('arrow-right', 15) ?></a>
    </div>

    <div class="grid grid-3">
      <?php foreach ($latestPosts as $p): ?>
        <article class="min0">
          <a href="<?= e(url('blog/' . $p['slug'])) ?>" class="tile" style="aspect-ratio:16/10">
            <img src="<?= e(upload_url((string) $p['cover'])) ?>" alt=""
                 width="400" height="250" loading="lazy">
          </a>
          <h3 style="font-size:1.0625rem;margin:var(--s3) 0 4px">
            <a href="<?= e(url('blog/' . $p['slug'])) ?>" style="color:var(--ink)">
              <?= e((string) $p['title']) ?></a></h3>
          <p class="text-xs muted mb0"><?= e(fmt_date((string) $p['published_at'])) ?></p>
        </article>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
