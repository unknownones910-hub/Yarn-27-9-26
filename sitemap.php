<?php
/**
 * XML sitemap, generated from what is actually live.
 *
 * Served at /sitemap.xml via the .htaccess rewrite. Never lists cart,
 * checkout, account or admin — those are noindex anyway, and pointing search
 * engines at them wastes crawl budget.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/xml; charset=utf-8');

$urls = [];

$add = static function (string $path, ?string $lastmod = null,
                        string $freq = 'weekly', string $priority = '0.5') use (&$urls): void {
    $urls[] = [
        'loc'      => abs_url($path),
        'lastmod'  => $lastmod ? date('Y-m-d', (int) strtotime($lastmod)) : null,
        'freq'     => $freq,
        'priority' => $priority,
    ];
};

// ---- static pages -----------------------------------------------------
$add('/',                    null, 'daily',   '1.0');
$add('/shop.php',            null, 'daily',   '0.9');
$add('/about.php',           null, 'monthly', '0.6');
$add('/contact.php',         null, 'monthly', '0.6');
$add('/faq.php',             null, 'monthly', '0.6');
$add('/yarn-guide.php',      null, 'monthly', '0.8');
$add('/yarn-calculator.php', null, 'monthly', '0.8');
$add('/shade-card.php',      null, 'weekly',  '0.7');
// Deliberately NOT listed: /track-order.php is noindex and Disallow-ed in
// robots.txt. Submitting a blocked URL is a Search Console coverage error.

if (setting_bool('feat_reviews', true))   { $add('/reviews.php',   null, 'weekly',  '0.6'); }
if (setting_bool('feat_blog', true))      { $add('/blog.php',      null, 'weekly',  '0.7'); }
if (setting_bool('feat_patterns', true))  { $add('/patterns.php',  null, 'weekly',  '0.7'); }
if (setting_bool('feat_wholesale', true)) { $add('/wholesale.php', null, 'monthly', '0.5'); }

// ---- catalog ----------------------------------------------------------
foreach (db_all('SELECT slug FROM categories WHERE is_active = 1 ORDER BY sort_order') as $c) {
    $add('/category/' . $c['slug'], null, 'daily', '0.8');
}

foreach (db_all('SELECT slug, updated_at FROM products WHERE is_active = 1 ORDER BY id') as $p) {
    $add('/product/' . $p['slug'], (string) $p['updated_at'], 'weekly', '0.9');
}

foreach (db_all('SELECT slug FROM collections WHERE is_active = 1') as $c) {
    $add('/collection/' . $c['slug'], null, 'weekly', '0.6');
}

// ---- content ----------------------------------------------------------
foreach (db_all('SELECT slug, updated_at FROM pages WHERE is_active = 1') as $p) {
    $add('/' . $p['slug'], (string) $p['updated_at'], 'yearly', '0.3');
}

if (setting_bool('feat_blog', true)) {
    foreach (db_all('SELECT slug, published_at FROM blog_posts
                     WHERE status = "published" AND published_at <= NOW()') as $b) {
        $add('/blog/' . $b['slug'], (string) $b['published_at'], 'monthly', '0.6');
    }
}

if (setting_bool('feat_patterns', true)) {
    foreach (db_all('SELECT slug FROM patterns WHERE is_active = 1') as $p) {
        $add('/pattern/' . $p['slug'], null, 'monthly', '0.6');
    }
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
<?php foreach ($urls as $u): ?>
  <url>
    <loc><?= e($u['loc']) ?></loc>
<?php if ($u['lastmod']): ?>
    <lastmod><?= e($u['lastmod']) ?></lastmod>
<?php endif; ?>
    <changefreq><?= e($u['freq']) ?></changefreq>
    <priority><?= e($u['priority']) ?></priority>
  </url>
<?php endforeach; ?>
</urlset>
