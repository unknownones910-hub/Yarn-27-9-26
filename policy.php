<?php
/**
 * Renders any CMS page from the `pages` table — the eight policy documents.
 *
 * The stored text carries {{tokens}} which are resolved here from settings, so
 * the shop's address, phone number and grievance officer appear in eight
 * documents but are edited in exactly one place and can never drift apart.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$slug = get_str('p', 80);

$page = $slug !== ''
    ? db_row('SELECT * FROM pages WHERE slug = ? AND is_active = 1 LIMIT 1', [$slug])
    : null;

if (!$page) {
    http_response_code(404);
    require_once YN_ROOT . '/404.php';
    exit;
}

// policy_tokens() lives in includes/helpers.php — the announcement bar
// resolves the same tokens, and two copies would drift.

$body = policy_tokens((string) $page['body']);

// Warn staff (never customers) if the seeded placeholders survived.
$hasPlaceholders = is_local_dev() || current_admin();
$placeholderHit  = (bool) preg_match('/\[[a-z ]+\]|example\.com/i', $body);

// The meta description is generated from the body, so it carries tokens too —
// leaving them unresolved would put "{{legal_name}}" in search results.
$pageTitle = policy_tokens((string) ($page['meta_title'] ?: $page['title']), false);
$pageDesc  = policy_tokens((string) $page['meta_description'], false);
$canonical = '/' . $page['slug'];

// The policy set, for cross-links at the foot of each one.
$others = db_all(
    'SELECT slug, title FROM pages
     WHERE is_active = 1 AND slug <> ? ORDER BY title',
    [$slug]
);

$crumbs = [['Home', url('')], [(string) $page['title'], null]];
$jsonLd = [breadcrumb_ld($crumbs)];

require_once YN_ROOT . '/includes/header.php';
?>

<div class="container section-sm">
  <?= breadcrumb_nav($crumbs) ?>

  <div style="width:min(780px,100%);margin-inline:auto">
    <header class="mb6">
      <h1 class="mb2"><?= e((string) $page['title']) ?></h1>
      <p class="text-sm muted mb0">
        Last updated <?= e(fmt_date((string) $page['updated_at'], 'j F Y')) ?>
      </p>
    </header>

    <?php if ($hasPlaceholders && $placeholderHit): ?>
      <div class="alert alert-warn mb5">
        <?= icon('alert', 18) ?>
        <div>
          <strong>Staff notice.</strong> This page still shows placeholder text because
          some store details have not been filled in.
          <a href="<?= e(url('admin/settings.php')) ?>">Complete them in Settings</a>
          before going live — the address, phone and grievance officer are legally required.
          Customers do not see this box.
        </div>
      </div>
    <?php endif; ?>

    <div class="prose">
      <?= $body ?>
    </div>

    <div class="card mt7">
      <h2 style="font-family:var(--font-sans);font-size:1rem">Questions about this?</h2>
      <p class="text-sm soft">
        We would rather explain than have you guess. Email
        <a href="mailto:<?= e(setting('store_email')) ?>"><?= e(setting('store_email')) ?></a>,
        call <?= e(setting('store_phone')) ?>, or
        <a href="<?= e(url('contact.php')) ?>">send us a message</a>.
      </p>
    </div>

    <?php if ($others): ?>
      <div class="mt6">
        <p class="text-xs muted mb3" style="font-weight:700;letter-spacing:.1em;text-transform:uppercase">
          Our other policies</p>
        <div class="chips">
          <?php foreach ($others as $o): ?>
            <a class="chip" href="<?= e(url($o['slug'])) ?>"><?= e((string) $o['title']) ?></a>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once YN_ROOT . '/includes/footer.php'; ?>
