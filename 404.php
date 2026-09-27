<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

http_response_code(404);

$pageTitle = 'Page not found';
$pageDesc  = 'We could not find that page.';
$noindex   = true;

require_once __DIR__ . '/includes/header.php';
?>

<div class="container">
  <div class="empty">
    <div class="empty__art"><?= icon('yarn', 40) ?></div>
    <h1>This thread leads nowhere</h1>
    <p style="max-width:44ch">
      The page you were looking for has moved or never existed. Let&rsquo;s get you back to something soft.
    </p>

    <form class="mt5" action="<?= e(url('search.php')) ?>" method="get" data-no-loading
          style="max-width:420px;margin-inline:auto">
      <label class="sr-only" for="q404">Search the shop</label>
      <div class="search">
        <?= icon('search', 18) ?>
        <input class="input" type="search" id="q404" name="q" placeholder="Search yarn, shade or fibre…">
      </div>
    </form>

    <div class="row" style="justify-content:center;margin-top:var(--s5)">
      <a class="btn" href="<?= e(url('shop.php')) ?>">Shop all yarn</a>
      <a class="btn btn-soft" href="<?= e(url('')) ?>">Back to home</a>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
