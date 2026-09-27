<?php
/**
 * Blog index — tutorials and project posts.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/components/pagination.php';

if (!setting_bool('feat_blog', true)) {
    http_response_code(404);
    require_once YN_ROOT . '/404.php';
    exit;
}

$tag = get_str('tag', 60);

$where  = ['status = "published"', 'published_at <= NOW()'];
$params = [];
if ($tag !== '') {
    $where[]  = 'tags LIKE ?';
    $params[] = '%' . $tag . '%';
}
$whereSql = implode(' AND ', $where);

$total = (int) db_val("SELECT COUNT(*) FROM blog_posts WHERE {$whereSql}", $params);
$per   = 9;
$pages = max(1, (int) ceil($total / $per));
$page  = max(1, min(get_int('page', 1), $pages));
$off   = ($page - 1) * $per;

$posts = db_all(
    "SELECT * FROM blog_posts WHERE {$whereSql}
     ORDER BY published_at DESC LIMIT {$per} OFFSET {$off}",
    $params
);

$allTags = [];
foreach (db_col('SELECT tags FROM blog_posts WHERE status = "published" AND tags IS NOT NULL') as $t) {
    foreach (explode(',', (string) $t) as $one) {
        $one = trim($one);
        if ($one !== '') {
            $allTags[$one] = true;
        }
    }
}
ksort($allTags);

$pageTitle = 'Blog — patterns, tutorials and projects';
$pageDesc  = 'How-to guides, stitch tutorials and project ideas from ' . setting('store_name') . '.';
$canonical = '/blog.php';

require_once YN_ROOT . '/includes/header.php';
?>

<div class="container section-sm">
  <header class="mb6" style="max-width:60ch">
    <p class="eyebrow">Journal</p>
    <h1 class="mb2">Things worth knowing</h1>
    <p class="lede mb0">
      Tutorials, project write-ups and the answers to the questions we get asked most.
    </p>
  </header>

  <?php if ($allTags): ?>
    <div class="chips mb6">
      <a class="chip <?= $tag === '' ? 'is-on' : '' ?>" href="<?= e(url('blog.php')) ?>">All</a>
      <?php foreach (array_keys($allTags) as $t): ?>
        <a class="chip <?= $tag === $t ? 'is-on' : '' ?>"
           href="<?= e(url('blog.php?tag=' . rawurlencode($t))) ?>"><?= e($t) ?></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!$posts): ?>
    <div class="empty">
      <div class="empty__art"><?= icon('edit', 40) ?></div>
      <h2>Nothing published yet</h2>
      <p style="max-width:44ch">
        The first posts are being written. In the meantime, the
        <a href="<?= e(url('yarn-guide.php')) ?>">yarn guide</a> covers most of what a
        tutorial would.
      </p>
      <a class="btn mt4" href="<?= e(url('yarn-guide.php')) ?>">Read the yarn guide</a>
    </div>

  <?php else: ?>
    <div class="grid grid-3">
      <?php foreach ($posts as $p): ?>
        <article class="min0">
          <a href="<?= e(url('blog/' . $p['slug'])) ?>" class="tile" style="aspect-ratio:16/10">
            <img src="<?= e(upload_url((string) $p['cover'])) ?>" alt=""
                 width="480" height="300" loading="lazy">
          </a>
          <div class="mt3">
            <?php if (!empty($p['tags'])): ?>
              <p class="eyebrow mb1"><?= e(explode(',', (string) $p['tags'])[0]) ?></p>
            <?php endif; ?>
            <h2 style="font-size:1.125rem;margin:0 0 6px">
              <a href="<?= e(url('blog/' . $p['slug'])) ?>" style="color:var(--ink)">
                <?= e((string) $p['title']) ?></a>
            </h2>
            <?php if (!empty($p['excerpt'])): ?>
              <p class="text-sm soft clamp-3 mb2"><?= e((string) $p['excerpt']) ?></p>
            <?php endif; ?>
            <p class="text-xs muted mb0">
              <?= e(fmt_date((string) $p['published_at'])) ?>
              <?php if (!empty($p['author'])): ?> · <?= e((string) $p['author']) ?><?php endif; ?>
            </p>
          </div>
        </article>
      <?php endforeach; ?>
    </div>

    <?php pagination($page, $pages); ?>
  <?php endif; ?>
</div>

<?php require_once YN_ROOT . '/includes/footer.php'; ?>
