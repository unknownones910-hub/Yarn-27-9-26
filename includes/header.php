<?php
/**
 * Storefront <head> + site chrome.
 *
 * A page sets any of these BEFORE requiring this file:
 *   $pageTitle   string  full <title> (brand suffix added automatically)
 *   $pageDesc    string  meta description
 *   $bodyClass   string  extra classes on <body>
 *   $canonical   string  canonical path (defaults to the current path)
 *   $noindex     bool    true for cart / checkout / account / filtered lists
 *   $ogImage     string  absolute or app-relative image path
 *   $jsonLd      array   one or more JSON-LD blocks
 */
declare(strict_types=1);

$brand     = setting('store_name', $SITE['brand']);
$pageTitle = $pageTitle ?? setting('seo_title');
$pageDesc  = $pageDesc  ?? setting('seo_description');
$bodyClass = $bodyClass ?? '';
$noindex   = $noindex   ?? false;
$canonical = $canonical ?? strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');
$jsonLd    = $jsonLd    ?? [];

// Keep the brand out of the title when the page already says it.
$fullTitle = str_contains($pageTitle, $brand) ? $pageTitle : $pageTitle . ' · ' . $brand;

$cartCount = nav_cart_count();
$cats      = nav_categories();
$waLink    = whatsapp_link('Hi! I have a question about an order.');
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title><?= e($fullTitle) ?></title>
<meta name="description" content="<?= e($pageDesc) ?>">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="yn-auth" content="<?= is_logged_in() ? '1' : '0' ?>">
<meta name="theme-color" content="#FDFBF7" data-theme-color>
<link rel="canonical" href="<?= e(abs_url($canonical)) ?>">
<?php if ($noindex): ?>
<meta name="robots" content="noindex, follow">
<?php endif; ?>

<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= e($brand) ?>">
<meta property="og:title" content="<?= e($pageTitle) ?>">
<meta property="og:description" content="<?= e($pageDesc) ?>">
<meta property="og:url" content="<?= e(abs_url($canonical)) ?>">
<?php if (!empty($ogImage)): ?>
<meta property="og:image" content="<?= e(str_starts_with($ogImage, 'http') ? $ogImage : abs_url($ogImage)) ?>">
<meta name="twitter:card" content="summary_large_image">
<?php else: ?>
<meta name="twitter:card" content="summary">
<?php endif; ?>

<link rel="icon" href="data:image/svg+xml,<?= rawurlencode(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">'
    . '<rect width="32" height="32" rx="8" fill="#FBEFE8"/>'
    . '<g fill="none" stroke="#B4552F" stroke-width="1.8" stroke-linecap="round">'
    . '<circle cx="16" cy="16" r="9"/>'
    . '<path d="M9.6 9.9c2.6 1.8 4.8 4.7 5.8 8.1M12.9 7.2c2.9 2.2 5.2 5.7 6.2 9.7M18.7 7.6c2 2.1 3.6 4.8 4.2 7.7"/>'
    . '</g></svg>'
) ?>">
<!-- Runs BEFORE the stylesheet, deliberately. Applying the saved theme after
     paint gives a white flash on every page load for dark-theme users, which
     is the single most noticeable way to get this wrong. Not deferred, not at
     the end of body — it has to be here. -->
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
  try {
    var saved = localStorage.getItem('yn-theme');
    if (saved === 'dark' || saved === 'light') {
      document.documentElement.setAttribute('data-theme', saved);
    }
    // No saved choice: leave the attribute off and let the CSS
    // prefers-color-scheme block follow the operating system.
  } catch (e) { /* private mode — the OS preference still applies */ }
})();
</script>
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
<script defer src="<?= e(asset('js/main.js')) ?>"></script>

<?php foreach ($jsonLd as $block): ?>
<script type="application/ld+json" nonce="<?= e(csp_nonce()) ?>"><?= json_encode($block, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?></script>
<?php endforeach; ?>
</head>

<body class="has-tabbar <?= e($bodyClass) ?>">

<a class="skip-link" href="#main">Skip to content</a>

<?php if (setting_bool('announcement_on', true) && setting('announcement_text') !== ''): ?>
<div class="announce">
  <div class="container">
    <p><?= policy_tokens(e(setting('announcement_text'))) ?></p>
    <button type="button" data-dismiss-announce aria-label="Dismiss announcement"><?= icon('close', 15) ?></button>
  </div>
</div>
<?php endif; ?>

<header class="site-header">
  <div class="container">
    <div class="header-main">

      <button type="button" class="icon-btn nav-toggle" data-drawer-open="#nav-drawer"
              aria-label="Open menu" aria-controls="nav-drawer" aria-expanded="false">
        <?= icon('menu', 22) ?>
      </button>

      <a class="brand" href="<?= e(url('')) ?>">
        <span class="brand-mark"><?= icon('yarn', 20) ?></span>
        <span class="brand-text"><?= e($brand) ?></span>
      </a>

      <form class="header-search" role="search" action="<?= e(url('search.php')) ?>" method="get" data-no-loading>
        <label class="sr-only" for="q-desktop">Search yarn</label>
        <div class="search">
          <?= icon('search', 18) ?>
          <input class="input" type="search" id="q-desktop" name="q" placeholder="Search yarn, shade or fibre…"
                 value="<?= e(get_str('q')) ?>" autocomplete="off">
        </div>
      </form>

      <div class="header-actions">
        <!-- hide-sm, like the wishlist and account icons: at 320px a fifth
             control pushed the cart button 26px past the viewport. Phones get
             it in the drawer instead. -->
        <button type="button" class="theme-toggle hide-sm" data-theme-toggle
                aria-label="Switch between light and dark theme">
          <span class="icon-moon"><?= icon('moon', 20) ?></span>
          <span class="icon-sun"><?= icon('sun', 20) ?></span>
        </button>
        <a class="icon-btn hide-sm" href="<?= e(url('account/wishlist.php')) ?>" aria-label="Wishlist">
          <?= icon('heart', 21) ?>
        </a>
        <a class="icon-btn hide-sm" href="<?= e(is_logged_in() ? url('account/') : url('login.php')) ?>"
           aria-label="<?= is_logged_in() ? 'My account' : 'Sign in' ?>">
          <?= icon('user', 21) ?>
        </a>
        <a class="icon-btn cart-btn" href="<?= e(url('cart.php')) ?>"
           aria-label="Cart<?= $cartCount ? ', ' . $cartCount . ' items' : ', empty' ?>">
          <?= icon('cart', 21) ?>
          <?php if ($cartCount > 0): ?>
            <span class="cart-count"><?= $cartCount > 99 ? '99+' : $cartCount ?></span>
          <?php endif; ?>
        </a>
      </div>
    </div>

    <form class="mobile-search" role="search" action="<?= e(url('search.php')) ?>" method="get" data-no-loading>
      <label class="sr-only" for="q-mobile">Search yarn</label>
      <div class="search">
        <?= icon('search', 18) ?>
        <input class="input" type="search" id="q-mobile" name="q" placeholder="Search yarn, shade or fibre…"
               value="<?= e(get_str('q')) ?>" autocomplete="off">
      </div>
    </form>
  </div>

  <!-- One row. Nine items is a lot, so the list scrolls sideways inside its
       own rail on narrow desktops rather than wrapping or pushing the page
       wide — the overflow checker ignores children of a self-scrolling
       ancestor, which is exactly what this is. -->
  <nav class="header-nav" aria-label="Main menu">
    <div class="container">
      <ul class="header-nav__list">
        <?php foreach (nav_primary() as $item): ?>
          <?php $current = nav_is_current($item['url']); ?>
          <li>
            <a href="<?= e($item['url']) ?>" class="<?= e($item['class'] ?? '') ?>"
               <?= $current ? 'aria-current="page"' : '' ?>>
              <span><?= e($item['label']) ?></span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  </nav>
</header>

<!-- mobile navigation drawer -->
<aside class="drawer" id="nav-drawer" aria-hidden="true" aria-label="Main menu">
  <div class="drawer-head">
    <h2>Menu</h2>
    <button type="button" class="icon-btn" data-drawer-close aria-label="Close menu"><?= icon('close', 20) ?></button>
  </div>

  <div class="drawer-body">
    <ul class="drawer-nav">
      <li><a href="<?= e(url('shop.php')) ?>">Shop all yarn <?= icon('chevron-right', 16) ?></a></li>
      <li><a href="<?= e(url('shop.php?sort=new')) ?>">New arrivals <?= icon('chevron-right', 16) ?></a></li>
      <li><a href="<?= e(url('category/sale')) ?>">Sale <?= icon('chevron-right', 16) ?></a></li>
    </ul>

    <?php
    $groups = [
        'craft'  => 'Shop by craft',
        'fiber'  => 'Shop by fibre',
        'weight' => 'Shop by weight',
    ];
    foreach ($groups as $kind => $title):
        if (empty($cats[$kind])) continue; ?>
      <p class="drawer-group-title"><?= e($title) ?></p>
      <ul class="drawer-nav">
        <?php foreach ($cats[$kind] as $c): ?>
          <li><a href="<?= e(url('category/' . $c['slug'])) ?>"><?= e($c['name']) ?> <?= icon('chevron-right', 16) ?></a></li>
        <?php endforeach; ?>
      </ul>
    <?php endforeach; ?>

    <p class="drawer-group-title">Helpful</p>
    <ul class="drawer-nav">
      <li><a href="<?= e(url('yarn-guide.php')) ?>">Yarn guide <?= icon('chevron-right', 16) ?></a></li>
      <li><a href="<?= e(url('yarn-calculator.php')) ?>">How much yarn do I need? <?= icon('chevron-right', 16) ?></a></li>
      <li><a href="<?= e(url('shade-card.php')) ?>">Shade card <?= icon('chevron-right', 16) ?></a></li>
      <li><a href="<?= e(url('track-order.php')) ?>">Track my order <?= icon('chevron-right', 16) ?></a></li>
    </ul>

    <!-- Mirrors the desktop utility row. Without this, About and FAQ were
         unreachable on a phone entirely. -->
    <p class="drawer-group-title">About the shop</p>
    <ul class="drawer-nav">
      <li><a href="<?= e(url('about.php')) ?>">About us <?= icon('chevron-right', 16) ?></a></li>
      <li><a href="<?= e(url('faq.php')) ?>">FAQ <?= icon('chevron-right', 16) ?></a></li>
      <li><a href="<?= e(url('contact.php')) ?>">Contact us <?= icon('chevron-right', 16) ?></a></li>
      <?php if (setting_bool('feat_wholesale', true)): ?>
        <li><a href="<?= e(url('wholesale.php')) ?>">Wholesale &amp; bulk <?= icon('chevron-right', 16) ?></a></li>
      <?php endif; ?>
    </ul>
  </div>

  <div class="drawer-foot">
    <!-- The header toggle is hidden below 768px, so this is the only way in
         on a phone. Same data attribute, so one handler drives both. -->
    <button type="button" class="btn btn-soft btn-block mb3" data-theme-toggle>
      <span class="icon-moon"><?= icon('moon', 18) ?></span>
      <span class="icon-sun"><?= icon('sun', 18) ?></span>
      <span class="theme-label-dark">Dark theme</span>
      <span class="theme-label-light">Light theme</span>
    </button>

    <?php if (is_logged_in()): ?>
      <a class="btn btn-block" href="<?= e(url('account/')) ?>"><?= icon('user', 18) ?> My account</a>
    <?php else: ?>
      <a class="btn btn-block" href="<?= e(url('login.php')) ?>"><?= icon('user', 18) ?> Sign in</a>
    <?php endif; ?>
    <?php if ($waLink !== ''): ?>
      <a class="btn btn-soft btn-block mt3" href="<?= eurl($waLink) ?>" target="_blank" rel="noopener">
        <?= icon('whatsapp', 18) ?> Chat on WhatsApp
      </a>
    <?php endif; ?>
  </div>
</aside>

<main id="main">
