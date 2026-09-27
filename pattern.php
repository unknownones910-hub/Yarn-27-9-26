<?php
/**
 * One free pattern, with an email-gated PDF download.
 *
 * The gate is honest: the pattern really is free, we ask for an address so we
 * can send it, and the marketing list is a separate, explicit tick-box.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/mailer.php';
require_once YN_ROOT . '/includes/catalog.php';
require_once YN_ROOT . '/includes/components/product-card.php';

if (!setting_bool('feat_patterns', true)) {
    http_response_code(404);
    require_once YN_ROOT . '/404.php';
    exit;
}

$slug    = get_str('slug', 160);
$pattern = $slug !== ''
    ? db_row('SELECT * FROM patterns WHERE slug = ? AND is_active = 1 LIMIT 1', [$slug])
    : null;

if (!$pattern) {
    http_response_code(404);
    require_once YN_ROOT . '/404.php';
    exit;
}

$sent  = false;
$error = '';

if (is_post()) {
    csrf_verify();
    $email = post_str('email', 190);

    if (!honeypot_ok()) {
        $sent = true;
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address so we can send it.';
    } elseif (!rate_hit('pattern:' . client_ip(), 10, 3600)) {
        $error = 'That is a lot of downloads at once. Please try again later.';
    } else {
        db_exec('UPDATE patterns SET downloads = downloads + 1 WHERE id = ?', [(int) $pattern['id']]);

        // Only join the list if they actually asked to.
        if (!empty($_POST['subscribe'])) {
            db_exec(
                'INSERT INTO newsletter_subscribers (email, source, status, confirm_token)
                 VALUES (?, "pattern", "pending", ?)
                 ON DUPLICATE KEY UPDATE source = "pattern"',
                [$email, random_token(20)]
            );
        }

        $link = !empty($pattern['pdf_path']) ? abs_url(upload_url((string) $pattern['pdf_path'])) : '';

        try {
            send_mail($email, '',
                'Your pattern: ' . $pattern['title'],
                email_layout('Here is your pattern',
                    '<p style="margin:0 0 16px;">Thanks for downloading <strong>'
                    . e((string) $pattern['title']) . '</strong>. Happy making.</p>'
                    . ($link !== '' ? email_button('Download the PDF', $link) : '')
                    . '<p style="margin:0;color:#5D554C;font-size:14px;">If you get stuck, just '
                    . 'reply to this email — a real person reads it.</p>',
                    'Your free pattern is ready'),
                $link !== '' ? "Your pattern: {$link}\n" : null,
                'pattern_download');
        } catch (Throwable $e) {
            error_log('Pattern email failed: ' . $e->getMessage());
        }

        $sent = true;
    }
}

// Suggested yarn for this pattern.
$suggested = [];
$ids = array_values(array_filter(array_map('intval', explode(',', (string) $pattern['suggested_products']))));
if ($ids) {
    $suggested = db_all(
        'SELECT p.id, p.slug, p.name, p.subtitle, p.brand, p.fiber, p.yarn_weight,
                p.ball_weight_g, p.length_m, p.price, p.mrp, p.rating_avg, p.rating_count, p.is_new
         FROM products p WHERE p.is_active = 1 AND p.id IN (' . db_in($ids) . ')',
        $ids
    );
    catalog_attach_variants($suggested);
}

$pageTitle = (string) $pattern['title'] . ' — free pattern';
$pageDesc  = str_excerpt((string) $pattern['summary'], 155);
$canonical = '/pattern/' . $pattern['slug'];
if (!empty($pattern['cover'])) {
    $ogImage = upload_url((string) $pattern['cover']);
}

$crumbs = [['Home', url('')], ['Patterns', url('patterns.php')],
           [str_excerpt((string) $pattern['title'], 36), null]];
$jsonLd = [breadcrumb_ld($crumbs)];

require_once YN_ROOT . '/includes/header.php';
?>

<div class="container section-sm">
  <?= breadcrumb_nav($crumbs) ?>

  <div class="grid grid-2" style="align-items:start;gap:var(--s7)">
    <div class="min0">
      <?php if (!empty($pattern['cover'])): ?>
        <img src="<?= e(upload_url((string) $pattern['cover'])) ?>" alt=""
             width="600" height="450" style="border-radius:var(--r-lg);width:100%">
      <?php endif; ?>
    </div>

    <div class="min0">
      <div class="row gap2 mb3">
        <span class="pill pill-muted"><?= e(ucfirst((string) $pattern['craft'])) ?></span>
        <span class="pill pill-info"><?= e(ucfirst((string) $pattern['difficulty'])) ?></span>
        <?php if (!empty($pattern['yarn_weight'])): ?>
          <span class="pill pill-muted"><?= e(yarn_weight_label((string) $pattern['yarn_weight'])
                ?: (string) $pattern['yarn_weight']) ?></span>
        <?php endif; ?>
      </div>

      <h1 class="mb3"><?= e((string) $pattern['title']) ?></h1>

      <?php if (!empty($pattern['summary'])): ?>
        <p class="lede"><?= e((string) $pattern['summary']) ?></p>
      <?php endif; ?>

      <?php if ($sent): ?>
        <div class="card card-lift center mt5" style="padding-block:var(--s6)">
          <div class="empty__art" style="margin-inline:auto"><?= icon('check-circle', 32) ?></div>
          <h2 class="mt3" style="font-family:var(--font-sans);font-size:1.0625rem">Check your inbox</h2>
          <p class="soft text-sm mb0" style="margin-inline:auto;max-width:36ch">
            The pattern is on its way. If it has not arrived in a minute, look in your
            Promotions tab.
          </p>
        </div>

      <?php else: ?>
        <div class="card mt5">
          <h2 style="font-family:var(--font-sans);font-size:1.0625rem">Get the pattern free</h2>
          <p class="text-sm soft">
            Tell us where to send it. The pattern is free either way — we do not add you to
            anything unless you tick the box.
          </p>

          <?php if ($error !== ''): ?>
            <div class="alert alert-error mb4"><?= icon('alert', 18) ?><div><?= e($error) ?></div></div>
          <?php endif; ?>

          <form method="post" action="<?= e(url('pattern/' . $pattern['slug'])) ?>">
            <?= csrf_field() ?>
            <?= honeypot_field() ?>
            <div class="field">
              <label class="label" for="email">Your email <span class="req">*</span></label>
              <input class="input" type="email" id="email" name="email" required
                     autocomplete="email" placeholder="you@gmail.com">
            </div>
            <label class="check">
              <input type="checkbox" name="subscribe" value="1">
              <span class="text-sm">Also send me new patterns and offers
                <span class="muted">(twice a month at most)</span></span>
            </label>
            <button class="btn btn-block mt4" type="submit">
              <?= icon('download', 17) ?> Email me the pattern</button>
            <p class="help center mt3 mb0">
              <?= (int) $pattern['downloads'] ?> people have downloaded this.
            </p>
          </form>
        </div>
      <?php endif; ?>

      <div class="alert alert-info mt4">
        <?= icon('info', 18) ?>
        <div class="text-sm">
          Free for personal use. You may sell a small number of finished items with credit,
          but please do not redistribute the file. Full terms in our
          <a href="<?= e(url('terms-and-conditions')) ?>">terms</a>.
        </div>
      </div>
    </div>
  </div>

  <?php if ($suggested): ?>
    <section class="section-sm">
      <div class="sec-head">
        <div class="min0">
          <h2>Yarn for this pattern</h2>
          <p>The exact bases we used, so your finished piece matches the photo.</p>
        </div>
      </div>
      <?php product_grid($suggested); ?>
    </section>
  <?php endif; ?>

  <section class="card mt6">
    <h2 style="font-family:var(--font-sans);font-size:1rem">Not sure how much yarn to buy?</h2>
    <p class="text-sm soft">
      The <a href="<?= e(url('yarn-calculator.php')) ?>">yarn calculator</a> works out the
      metreage and ball count for the size you want.
    </p>
  </section>
</div>

<?php require_once YN_ROOT . '/includes/footer.php'; ?>
