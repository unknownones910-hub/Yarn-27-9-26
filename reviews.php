<?php
/**
 * Every published review, in one place.
 *
 * Only verified buyers can post, and we never write our own — which is why
 * this page may be short. A short honest wall beats a long invented one.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/components/pagination.php';

if (!setting_bool('feat_reviews', true)) {
    http_response_code(404);
    require_once YN_ROOT . '/404.php';
    exit;
}

$stars = get_int('stars');
$where = ['r.status = "approved"'];
$params = [];

if ($stars >= 1 && $stars <= 5) {
    $where[]  = 'r.rating = ?';
    $params[] = $stars;
}
$whereSql = implode(' AND ', $where);

$total = (int) db_val("SELECT COUNT(*) FROM reviews r WHERE {$whereSql}", $params);
$per   = 12;
$pages = max(1, (int) ceil($total / $per));
$page  = max(1, min(get_int('page', 1), $pages));
$off   = ($page - 1) * $per;

$reviews = db_all(
    "SELECT r.*, p.name AS product_name, p.slug, v.shade_name, v.color_hex
     FROM reviews r
     JOIN products p ON p.id = r.product_id
     LEFT JOIN product_variants v ON v.id = r.variant_id
     WHERE {$whereSql}
     ORDER BY r.helpful_count DESC, r.created_at DESC
     LIMIT {$per} OFFSET {$off}",
    $params
);

$summary = db_row('SELECT COUNT(*) n, COALESCE(AVG(rating),0) avg FROM reviews WHERE status = "approved"') ?: [];
$byStar  = [];
foreach (db_all('SELECT rating, COUNT(*) n FROM reviews WHERE status = "approved" GROUP BY rating') as $r) {
    $byStar[(int) $r['rating']] = (int) $r['n'];
}
$allN = max(1, (int) ($summary['n'] ?? 0));

$pageTitle = 'Customer reviews';
$pageDesc  = 'What people actually thought of our yarn. Every review comes from a verified '
           . 'purchase — we do not write our own.';
$canonical = '/reviews.php';

require_once YN_ROOT . '/includes/header.php';
?>

<div class="container section-sm">
  <header class="mb6" style="max-width:60ch">
    <h1 class="mb2">What people say</h1>
    <p class="lede mb0">
      Every review below comes from someone who bought the yarn here and received it. We
      publish the critical ones too — they are the useful ones.
    </p>
  </header>

  <?php if (!(int) ($summary['n'] ?? 0)): ?>
    <div class="card center" style="padding-block:var(--s8)">
      <div class="empty__art" style="margin-inline:auto"><?= icon('star', 36) ?></div>
      <h2 class="mt4" style="font-family:var(--font-sans);font-size:1.125rem">No reviews yet</h2>
      <p class="soft" style="margin-inline:auto;max-width:44ch">
        Reviews only come from verified purchases, so this page stays empty until the first
        orders are delivered. We would rather show nothing than make something up.
      </p>
      <a class="btn mt4" href="<?= e(url('shop.php')) ?>">Browse the yarn</a>
    </div>

  <?php else: ?>
    <div class="card rating-summary mb6">
      <div class="center">
        <p class="rating-big mb0"><?= number_format((float) $summary['avg'], 1) ?></p>
        <?= stars_html((float) $summary['avg'], 0, false) ?>
        <p class="text-sm muted mt2 mb0"><?= (int) $summary['n'] ?>
          review<?= (int) $summary['n'] === 1 ? '' : 's' ?></p>
      </div>
      <div class="rating-bars">
        <?php for ($s = 5; $s >= 1; $s--): ?>
          <?php $n = $byStar[$s] ?? 0; ?>
          <a class="rating-bar" style="text-decoration:none"
             href="<?= e(url('reviews.php' . ($stars === $s ? '' : '?stars=' . $s))) ?>">
            <span><?= $s ?>★</span>
            <span class="rating-bar__track">
              <span class="rating-bar__fill" style="width:<?= round($n / $allN * 100) ?>%"></span>
            </span>
            <span><?= $n ?></span>
          </a>
        <?php endfor; ?>
      </div>
    </div>

    <?php if ($stars >= 1 && $stars <= 5): ?>
      <div class="chips mb5">
        <span class="chip is-on"><?= $stars ?> star only</span>
        <a class="chip" href="<?= e(url('reviews.php')) ?>">Show all</a>
      </div>
    <?php endif; ?>

    <div class="grid grid-2">
      <?php foreach ($reviews as $r): ?>
        <article class="card">
          <div class="row row-between mb3">
            <?= stars_html((float) $r['rating'], 0, false) ?>
            <?php if ((int) $r['is_verified'] === 1): ?>
              <span class="pill pill-ok" style="flex:none">
                <?= icon('check', 12) ?> Verified purchase</span>
            <?php endif; ?>
          </div>

          <?php if (!empty($r['title'])): ?>
            <h2 style="font-family:var(--font-sans);font-size:1rem;margin:0 0 6px">
              <?= e((string) $r['title']) ?></h2>
          <?php endif; ?>

          <p class="text-sm soft mb3"><?= nl2br(e((string) $r['body'])) ?></p>

          <p class="text-xs muted mb0">
            <a href="<?= e(url('product/' . $r['slug'])) ?>"><?= e((string) $r['product_name']) ?></a>
            <?php if (!empty($r['shade_name'])): ?>
              · <span class="swatch-dot" style="display:inline-block;vertical-align:-2px;
                     background:<?= e((string) $r['color_hex']) ?>"></span>
              <?= e((string) $r['shade_name']) ?>
            <?php endif; ?>
          </p>
          <p class="text-xs muted mb0">
            <?= e((string) ($r['author_name'] ?: 'A customer')) ?>
            · <?= e(fmt_date((string) $r['created_at'])) ?>
          </p>

          <?php if (!empty($r['admin_reply'])): ?>
            <div class="alert mt3" style="background:var(--sand)">
              <?= icon('chat', 16) ?>
              <div class="text-sm">
                <strong><?= e(setting('store_name')) ?> replied:</strong>
                <?= e((string) $r['admin_reply']) ?>
              </div>
            </div>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>

    <?php pagination($page, $pages); ?>
  <?php endif; ?>

  <div class="card mt7">
    <h2 style="font-family:var(--font-sans);font-size:1rem">How reviews work here</h2>
    <ul class="stack-sm text-sm mt3">
      <li>· Only customers who bought and received the item can review it.</li>
      <li>· We read every review before it appears, and we only reject abuse or spam —
            never criticism.</li>
      <li>· We reply publicly where a review raises something we should answer.</li>
      <li>· We have never written a review of our own product, and never will.</li>
    </ul>
    <p class="text-xs muted mt4 mb0">
      Bought something? Leave a review from
      <a href="<?= e(url('account/reviews.php')) ?>">your account</a>.
    </p>
  </div>
</div>

<?php require_once YN_ROOT . '/includes/footer.php'; ?>
