<?php
/**
 * Free pattern library.
 *
 * Downloads are email-gated, which is the honest kind of lead magnet: the
 * pattern is genuinely free, and we ask for an address so we can send it and
 * (only with consent) tell them about new ones.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/mailer.php';

if (!setting_bool('feat_patterns', true)) {
    http_response_code(404);
    require_once YN_ROOT . '/404.php';
    exit;
}

$craft      = get_str('craft', 20);
$difficulty = get_str('difficulty', 20);

$where  = ['is_active = 1'];
$params = [];
if (in_array($craft, ['crochet', 'knitting', 'macrame', 'other'], true)) {
    $where[]  = 'craft = ?';
    $params[] = $craft;
}
if (in_array($difficulty, ['beginner', 'easy', 'intermediate', 'advanced'], true)) {
    $where[]  = 'difficulty = ?';
    $params[] = $difficulty;
}
$whereSql = implode(' AND ', $where);

$patterns = db_all("SELECT * FROM patterns WHERE {$whereSql} ORDER BY id DESC LIMIT 60", $params);
$total    = (int) db_val('SELECT COUNT(*) FROM patterns WHERE is_active = 1');

$pageTitle = 'Free crochet & knitting patterns';
$pageDesc  = 'Free downloadable patterns with the exact yarn and ball count listed for each one.';
$canonical = '/patterns.php';

require_once YN_ROOT . '/includes/header.php';
?>

<div class="container section-sm">
  <header class="mb6" style="max-width:60ch">
    <p class="eyebrow">Free to download</p>
    <h1 class="mb2">Patterns</h1>
    <p class="lede mb0">
      Every pattern lists the exact yarn, shade and number of balls it needs — so you can
      order once and start straight away.
    </p>
  </header>

  <?php if ($total > 0): ?>
    <div class="chips mb6">
      <a class="chip <?= $craft === '' ? 'is-on' : '' ?>" href="<?= e(url('patterns.php')) ?>">All</a>
      <?php foreach (['crochet' => 'Crochet', 'knitting' => 'Knitting',
                      'macrame' => 'Macramé'] as $k => $lbl): ?>
        <a class="chip <?= $craft === $k ? 'is-on' : '' ?>"
           href="<?= e(url('patterns.php?craft=' . $k)) ?>"><?= e($lbl) ?></a>
      <?php endforeach; ?>
      <?php foreach (['beginner' => 'Beginner', 'intermediate' => 'Intermediate'] as $k => $lbl): ?>
        <a class="chip <?= $difficulty === $k ? 'is-on' : '' ?>"
           href="<?= e(url('patterns.php?difficulty=' . $k)) ?>"><?= e($lbl) ?></a>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (!$patterns): ?>
    <div class="empty">
      <div class="empty__art"><?= icon('hook', 40) ?></div>
      <h2>Patterns are on the way</h2>
      <p style="max-width:46ch">
        We are writing and testing the first set. In the meantime, the
        <a href="<?= e(url('yarn-calculator.php')) ?>">yarn calculator</a> will tell you how
        much you need for a project of your own.
      </p>
      <div class="row mt5" style="justify-content:center">
        <a class="btn" href="<?= e(url('yarn-calculator.php')) ?>">Open the calculator</a>
        <a class="btn btn-soft" href="<?= e(url('yarn-guide.php')) ?>">Read the yarn guide</a>
      </div>
    </div>

  <?php else: ?>
    <div class="grid grid-3">
      <?php foreach ($patterns as $p): ?>
        <article class="card min0">
          <a href="<?= e(url('pattern/' . $p['slug'])) ?>" class="tile mb3" style="aspect-ratio:4/3">
            <img src="<?= e(upload_url((string) $p['cover'])) ?>" alt=""
                 width="400" height="300" loading="lazy">
          </a>
          <div class="row gap2 mb2">
            <span class="pill pill-muted"><?= e(ucfirst((string) $p['craft'])) ?></span>
            <span class="pill pill-info"><?= e(ucfirst((string) $p['difficulty'])) ?></span>
          </div>
          <h2 style="font-size:1.0625rem;margin:0 0 6px">
            <a href="<?= e(url('pattern/' . $p['slug'])) ?>" style="color:var(--ink)">
              <?= e((string) $p['title']) ?></a></h2>
          <?php if (!empty($p['summary'])): ?>
            <p class="text-sm soft clamp-2 mb3"><?= e((string) $p['summary']) ?></p>
          <?php endif; ?>
          <a class="btn btn-sm btn-soft btn-block" href="<?= e(url('pattern/' . $p['slug'])) ?>">
            <?= icon('download', 14) ?> Get the pattern</a>
        </article>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <section class="card mt7">
    <div class="grid grid-2" style="align-items:center;gap:var(--s5)">
      <div class="min0">
        <h2 style="font-size:var(--fs-h3)">New patterns, as we publish them</h2>
        <p class="soft text-sm">
          Join the list and we will send each new free pattern as it goes up — plus 10% off
          your first order. Two emails a month at most, and one-click unsubscribe.
        </p>
      </div>
      <div class="min0">
        <form method="post" action="<?= e(url('newsletter-subscribe.php')) ?>" class="stack-sm">
          <?= csrf_field() ?>
          <?= honeypot_field() ?>
          <input type="hidden" name="source" value="patterns">
          <label class="sr-only" for="pat-email">Your email</label>
          <input class="input" type="email" id="pat-email" name="email" required
                 placeholder="you@gmail.com" autocomplete="email">
          <button class="btn btn-block" type="submit">Send me new patterns</button>
          <p class="help mb0">
            By subscribing you agree to our <a href="<?= e(url('privacy-policy')) ?>">privacy policy</a>.
          </p>
        </form>
      </div>
    </div>
  </section>
</div>

<?php require_once YN_ROOT . '/includes/footer.php'; ?>
