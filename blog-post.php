<?php
/**
 * A single blog post.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/catalog.php';
require_once YN_ROOT . '/includes/components/product-card.php';

if (!setting_bool('feat_blog', true)) {
    http_response_code(404);
    require_once YN_ROOT . '/404.php';
    exit;
}

$slug = get_str('slug', 180);
$post = $slug !== ''
    ? db_row('SELECT * FROM blog_posts WHERE slug = ? AND status = "published" LIMIT 1', [$slug])
    : null;

if (!$post) {
    http_response_code(404);
    require_once YN_ROOT . '/404.php';
    exit;
}

$tags = array_values(array_filter(array_map('trim', explode(',', (string) $post['tags']))));

$related = $tags
    ? db_all(
        'SELECT slug, title, cover, excerpt, published_at FROM blog_posts
         WHERE status = "published" AND id <> ? AND tags LIKE ?
         ORDER BY published_at DESC LIMIT 3',
        [(int) $post['id'], '%' . $tags[0] . '%']
      )
    : [];

if (!$related) {
    $related = db_all(
        'SELECT slug, title, cover, excerpt, published_at FROM blog_posts
         WHERE status = "published" AND id <> ? ORDER BY published_at DESC LIMIT 3',
        [(int) $post['id']]
    );
}

$words    = str_word_count(strip_tags((string) $post['body']));
$readMins = max(1, (int) round($words / 200));

$pageTitle = (string) ($post['meta_title'] ?: $post['title']);
$pageDesc  = (string) ($post['meta_description'] ?: str_excerpt((string) $post['excerpt'], 155));
$canonical = '/blog/' . $post['slug'];
if (!empty($post['cover'])) {
    $ogImage = upload_url((string) $post['cover']);
}

$jsonLd = [[
    '@context'      => 'https://schema.org',
    '@type'         => 'Article',
    'headline'      => $post['title'],
    'datePublished' => date('c', strtotime((string) $post['published_at'])),
    'author'        => ['@type' => 'Organization', 'name' => $post['author'] ?: setting('store_name')],
    'publisher'     => ['@type' => 'Organization', 'name' => setting('store_name')],
    'mainEntityOfPage' => abs_url($canonical),
]];

$crumbs = [['Home', url('')], ['Blog', url('blog.php')],
           [str_excerpt((string) $post['title'], 40), null]];
$jsonLd[] = breadcrumb_ld($crumbs);

require_once YN_ROOT . '/includes/header.php';
?>

<div class="container section-sm">
  <?= breadcrumb_nav($crumbs) ?>

  <article style="width:min(760px,100%);margin-inline:auto">
    <header class="mb5">
      <?php if ($tags): ?>
        <div class="chips mb3">
          <?php foreach ($tags as $t): ?>
            <a class="chip" href="<?= e(url('blog.php?tag=' . rawurlencode($t))) ?>"><?= e($t) ?></a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <h1 class="mb3"><?= e((string) $post['title']) ?></h1>
      <p class="text-sm muted mb0">
        <?= e(fmt_date((string) $post['published_at'], 'j F Y')) ?>
        <?php if (!empty($post['author'])): ?> · <?= e((string) $post['author']) ?><?php endif; ?>
        · <?= $readMins ?> min read
      </p>
    </header>

    <?php if (!empty($post['cover'])): ?>
      <img src="<?= e(upload_url((string) $post['cover'])) ?>" alt=""
           width="760" height="430" style="border-radius:var(--r-lg);margin-bottom:var(--s6)">
    <?php endif; ?>

    <div class="prose">
      <?= $post['body'] ?>
    </div>

    <div class="card mt7">
      <h2 style="font-family:var(--font-sans);font-size:1rem">Yarn for this project</h2>
      <p class="text-sm soft">
        Not sure which yarn suits? The <a href="<?= e(url('yarn-guide.php')) ?>">yarn guide</a>
        explains the weights, and the
        <a href="<?= e(url('yarn-calculator.php')) ?>">calculator</a> works out how many balls.
      </p>
      <a class="btn btn-sm" href="<?= e(url('shop.php')) ?>">Browse yarn</a>
    </div>
  </article>

  <?php if ($related): ?>
    <?php /* Same 760px column as the article above it. It used to be 1100px,
             which left the two blocks with left edges 170px apart and read as
             a layout error rather than a deliberate step. */ ?>
    <section class="section-sm read-next">
      <div class="sec-head"><div class="min0"><h2>Read next</h2></div></div>
      <div class="grid grid-3">
        <?php foreach ($related as $r): ?>
          <article class="min0">
            <?php if (!empty($r['cover'])): ?>
              <a href="<?= e(url('blog/' . $r['slug'])) ?>" class="tile read-next__thumb">
                <img src="<?= e(upload_url((string) $r['cover'])) ?>" alt=""
                     width="360" height="225" loading="lazy">
              </a>
            <?php else: ?>
              <?php /* No cover: a tinted frame with an icon. Feeding the
                       "Photo coming soon" placeholder through object-fit:cover
                       in a 16:10 box crops its own caption in half. */ ?>
              <a href="<?= e(url('blog/' . $r['slug'])) ?>"
                 class="tile read-next__thumb read-next__thumb--empty" aria-hidden="true" tabindex="-1">
                <?= icon('hook', 26) ?>
              </a>
            <?php endif; ?>
            <h3 class="read-next__title">
              <a href="<?= e(url('blog/' . $r['slug'])) ?>"><?= e((string) $r['title']) ?></a></h3>
            <p class="text-xs muted mb0"><?= e(fmt_date((string) $r['published_at'])) ?></p>
          </article>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endif; ?>
</div>

<?php require_once YN_ROOT . '/includes/footer.php'; ?>
