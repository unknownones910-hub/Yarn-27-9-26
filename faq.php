<?php
/**
 * FAQ. Grouped, filterable client-side, and marked up as FAQPage so the
 * answers can appear directly in search results.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$faqs = db_all('SELECT * FROM faqs WHERE is_active = 1 ORDER BY category, sort_order, id');

$grouped = [];
foreach ($faqs as $f) {
    $grouped[$f['category']][] = $f;
}

$pageTitle = 'Frequently asked questions';
$pageDesc  = 'Delivery times, returns, payments and yarn questions, answered plainly.';
$canonical = '/faq.php';

$jsonLd = [];
if ($faqs) {
    $jsonLd[] = [
        '@context'   => 'https://schema.org',
        '@type'      => 'FAQPage',
        'mainEntity' => array_map(static fn($f) => [
            '@type'          => 'Question',
            'name'           => $f['question'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => strip_tags((string) $f['answer'])],
        ], $faqs),
    ];
}

require_once YN_ROOT . '/includes/header.php';
?>

<div class="container section-sm faq">
  <header class="faq__head">
    <p class="eyebrow"><span class="eyebrow__dot" aria-hidden="true"></span>Help centre</p>
    <h1 class="mb2">Questions, answered</h1>
    <p class="lede mb0">
      If the answer is not here, <a href="<?= e(url('contact.php')) ?>">just ask us</a> —
      we reply within one working day.
    </p>
  </header>

  <?php if (!$faqs): ?>
    <div class="empty">
      <div class="empty__art"><?= icon('info', 40) ?></div>
      <h2>Nothing here yet</h2>
      <p><a href="<?= e(url('contact.php')) ?>">Ask us anything</a> and we will add it.</p>
    </div>

  <?php else: ?>
    <div class="faq__tools">
      <label class="sr-only" for="faq-search">Search questions</label>
      <div class="search">
        <?= icon('search', 18) ?>
        <input class="input" type="search" id="faq-search" data-faq-search
               placeholder="Search — try “refund” or “dye lot”" autocomplete="off">
      </div>
      <p class="help mt2 mb0" data-faq-count></p>
    </div>

    <?php /* Counts make the categories scannable — you can see where the
             answers actually are before clicking anything. The filter matches
             on data-faq-cat, so the visible text is free to carry them. */ ?>
    <div class="chips faq__chips" data-faq-chips>
      <button class="chip is-on" type="button" data-faq-cat="">
        All <span class="chip__n"><?= count($faqs) ?></span></button>
      <?php foreach ($grouped as $cat => $items): ?>
        <button class="chip" type="button" data-faq-cat="<?= e($cat) ?>">
          <?= e($cat) ?> <span class="chip__n"><?= count($items) ?></span></button>
      <?php endforeach; ?>
    </div>

    <div class="faq__groups">
      <?php foreach ($grouped as $cat => $items): ?>
        <section class="faq__group" data-faq-group="<?= e($cat) ?>"
                 id="faq-<?= e(slugify($cat)) ?>">
          <h2 class="faq__group-title">
            <?= e($cat) ?>
            <span class="faq__group-n"><?= count($items) ?></span>
          </h2>
          <div class="accordion">
            <?php foreach ($items as $f): ?>
              <details data-faq-item
                       data-text="<?= e(mb_strtolower($f['question'] . ' ' . strip_tags((string) $f['answer']))) ?>">
                <summary><?= e((string) $f['question']) ?></summary>
                <div class="acc-body"><?= nl2br(e((string) $f['answer'])) ?></div>
              </details>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endforeach; ?>

      <p class="faq__none hide" data-faq-empty>
        <?= icon('search', 22) ?>
        Nothing matches that. <a href="<?= e(url('contact.php')) ?>">Ask us directly</a>.
      </p>
    </div>

    <div class="card faq__cta">
      <span class="faq__cta-ico"><?= icon('chat', 20) ?></span>
      <div class="min0">
        <h2 class="faq__cta-title">Still stuck?</h2>
        <p class="text-sm soft mb0">
          Call <?= e(setting('store_phone')) ?>, email
          <a href="mailto:<?= e(setting('store_email')) ?>"><?= e(setting('store_email')) ?></a>,
          or <a href="<?= e(url('contact.php')) ?>">send us a message</a>.
          A person replies within one working day.
        </p>
      </div>
    </div>
  <?php endif; ?>
</div>

<script nonce="<?= e(csp_nonce()) ?>">
/* Filtering is progressive enhancement — with JS off, every answer is still
   on the page and readable. */
(function () {
  var search = document.querySelector('[data-faq-search]');
  if (!search) return;

  var items  = [].slice.call(document.querySelectorAll('[data-faq-item]'));
  var groups = [].slice.call(document.querySelectorAll('[data-faq-group]'));
  var chips  = [].slice.call(document.querySelectorAll('[data-faq-cat]'));
  var count  = document.querySelector('[data-faq-count]');
  var none   = document.querySelector('[data-faq-empty]');
  var cat    = '';

  function apply() {
    var q = search.value.trim().toLowerCase();
    var shown = 0;

    groups.forEach(function (g) {
      var groupCat = g.getAttribute('data-faq-group');
      var visibleHere = 0;

      [].slice.call(g.querySelectorAll('[data-faq-item]')).forEach(function (it) {
        var matchCat  = !cat || cat === groupCat;
        var matchText = !q || it.getAttribute('data-text').indexOf(q) !== -1;
        var show = matchCat && matchText;
        it.hidden = !show;
        if (show) { visibleHere++; shown++; }
        if (q && show) it.open = true;
        if (!q) it.open = false;
      });

      g.hidden = visibleHere === 0;
    });

    if (count) {
      count.textContent = q || cat
        ? shown + (shown === 1 ? ' question' : ' questions') + ' match'
        : '';
    }
    if (none) none.classList.toggle('hide', shown > 0);
  }

  search.addEventListener('input', apply);
  chips.forEach(function (c) {
    c.addEventListener('click', function () {
      cat = c.getAttribute('data-faq-cat');
      chips.forEach(function (x) { x.classList.toggle('is-on', x === c); });
      apply();
    });
  });
})();
</script>

<?php require_once YN_ROOT . '/includes/footer.php'; ?>
