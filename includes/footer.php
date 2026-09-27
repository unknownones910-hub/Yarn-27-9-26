<?php
/**
 * Storefront footer, mobile tab bar, and flash messages.
 *
 * The legal block (business name, full postal address, phone, email) is a
 * requirement of the Consumer Protection (E-Commerce) Rules, 2020 — it is not
 * decoration and must not be removed to "clean up" the design.
 */
declare(strict_types=1);

$waLink = $waLink ?? whatsapp_link('Hi! I have a question.');
$year   = date('Y');
$socials = array_filter([
    'instagram' => setting('social_instagram'),
    'facebook'  => setting('social_facebook'),
    'youtube'   => setting('social_youtube'),
    'pinterest' => setting('social_pinterest'),
]);
?>
</main>

<footer class="site-footer">
  <div class="container">
    <div class="footer-grid">

      <div>
        <a class="brand mb3" href="<?= e(url('')) ?>">
          <span class="brand-mark"><?= icon('yarn', 20) ?></span>
          <span class="brand-text"><?= e(setting('store_name', $SITE['brand'])) ?></span>
        </a>
        <p class="text-sm soft" style="max-width:38ch">
          Soft, honest yarn for people who make things. Real shade photos, clear fibre labels,
          and no surprises at checkout.
        </p>
        <?php if ($socials): ?>
          <div class="social">
            <?php foreach ($socials as $name => $link): ?>
              <a href="<?= eurl($link) ?>" target="_blank" rel="noopener me"
                 aria-label="<?= e(ucfirst($name)) ?>"><?= icon($name, 18) ?></a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div>
        <h2 class="footer-title">Shop</h2>
        <ul class="footer-links">
          <li><a href="<?= e(url('shop.php')) ?>">All yarn</a></li>
          <li><a href="<?= e(url('category/cotton')) ?>">Cotton yarn</a></li>
          <li><a href="<?= e(url('category/acrylic')) ?>">Acrylic yarn</a></li>
          <li><a href="<?= e(url('category/wool')) ?>">Wool yarn</a></li>
          <li><a href="<?= e(url('category/kits-and-bundles')) ?>">Kits &amp; bundles</a></li>
          <li><a href="<?= e(url('category/tools-and-accessories')) ?>">Hooks &amp; tools</a></li>
          <li><a href="<?= e(url('category/sale')) ?>">Sale</a></li>
        </ul>
      </div>

      <div>
        <h2 class="footer-title">Help</h2>
        <ul class="footer-links">
          <li><a href="<?= e(url('track-order.php')) ?>">Track your order</a></li>
          <li><a href="<?= e(url('shipping-policy')) ?>">Shipping &amp; delivery</a></li>
          <li><a href="<?= e(url('returns-and-refunds')) ?>">Exchange policy</a></li>
          <li><a href="<?= e(url('faq.php')) ?>">FAQ</a></li>
          <li><a href="<?= e(url('yarn-guide.php')) ?>">Yarn guide</a></li>
          <li><a href="<?= e(url('about.php')) ?>">About us</a></li>
          <li><a href="<?= e(url('contact.php')) ?>">Contact us</a></li>
          <?php if (setting_bool('feat_wholesale', true)): ?>
            <li><a href="<?= e(url('wholesale.php')) ?>">Bulk &amp; wholesale</a></li>
          <?php endif; ?>
        </ul>
      </div>

      <div>
        <?php if (setting_bool('feat_newsletter', true)): ?>
          <h2 class="footer-title">Stay in the loop</h2>
          <p class="text-sm soft mb3">
            New shades, free patterns and 10% off your first order. No spam — unsubscribe any time.
          </p>
          <form action="<?= e(url('newsletter-subscribe.php')) ?>" method="post" class="stack-sm">
            <?= csrf_field() ?>
            <?= honeypot_field() ?>
            <label class="sr-only" for="nl-email">Your Gmail address</label>
            <input class="input" type="email" id="nl-email" name="email" required
                   placeholder="you@gmail.com" autocomplete="email">
            <button class="btn btn-block" type="submit">Subscribe</button>
            <p class="help">By subscribing you agree to our
              <a href="<?= e(url('privacy-policy')) ?>">Privacy Policy</a>.</p>
          </form>
        <?php endif; ?>

        <h2 class="footer-title mt5">Policies</h2>
        <ul class="footer-links">
          <li><a href="<?= e(url('privacy-policy')) ?>">Privacy policy</a></li>
          <li><a href="<?= e(url('terms-and-conditions')) ?>">Terms &amp; conditions</a></li>
          <li><a href="<?= e(url('cancellation-policy')) ?>">Cancellation policy</a></li>
          <li><a href="<?= e(url('cookie-policy')) ?>">Cookie policy</a></li>
          <li><a href="<?= e(url('grievance-redressal')) ?>">Grievance redressal</a></li>
        </ul>
      </div>
    </div>
  </div>

  <div class="footer-legal">
    <div class="container">
      <div class="row row-between">
        <div class="min0" style="flex:1 1 300px">
          <address>
            <strong><?= e(setting('store_legal_name')) ?></strong><br>
            <?= e(setting('store_address')) ?><br>
            <a href="tel:<?= e(preg_replace('/\s+/', '', setting('store_phone'))) ?>"><?= e(setting('store_phone')) ?></a>
            &nbsp;·&nbsp;
            <a href="mailto:<?= e(setting('store_email')) ?>"><?= e(setting('store_email')) ?></a>
            <?php if (setting('store_gstin') !== ''): ?>
              <br>GSTIN: <?= e(setting('store_gstin')) ?>
            <?php endif; ?>
          </address>
          <p class="mt3 mb0">&copy; <?= $year ?> <?= e(setting('store_legal_name')) ?>. All rights reserved.</p>
        </div>

        <div class="min0" style="flex:0 1 auto">
          <p class="mb3 mt0">We accept</p>
          <div class="pay-logos">
            <span class="pay-logo">UPI</span>
            <span class="pay-logo">VISA</span>
            <span class="pay-logo">Mastercard</span>
            <span class="pay-logo">RuPay</span>
            <span class="pay-logo">Netbanking</span>
          </div>
          <p class="mt3 mb0 row gap2" style="gap:6px">
            <?= icon('lock', 14) ?> Secured by Razorpay
          </p>
        </div>
      </div>
    </div>
  </div>
</footer>

<!-- mobile tab bar -->
<nav class="tabbar" aria-label="Quick navigation">
  <a href="<?= e(url('')) ?>" <?= nav_is_current(url('')) ? 'aria-current="page"' : '' ?>>
    <span class="tab-ico"><?= icon('home', 21) ?></span><span>Home</span>
  </a>
  <a href="<?= e(url('shop.php')) ?>" <?= nav_is_current(url('shop.php')) ? 'aria-current="page"' : '' ?>>
    <span class="tab-ico"><?= icon('grid', 21) ?></span><span>Shop</span>
  </a>
  <a href="<?= e(url('search.php')) ?>" <?= nav_is_current(url('search.php')) ? 'aria-current="page"' : '' ?>>
    <span class="tab-ico"><?= icon('search', 21) ?></span><span>Search</span>
  </a>
  <a href="<?= e(url('account/wishlist.php')) ?>">
    <span class="tab-ico"><?= icon('heart', 21) ?></span><span>Wishlist</span>
  </a>
  <a href="<?= e(is_logged_in() ? url('account/') : url('login.php')) ?>">
    <span class="tab-ico"><?= icon('user', 21) ?></span><span>Account</span>
  </a>
</nav>

<?php foreach (flash_take() as $f): ?>
  <div class="sr-only" data-flash="<?= e($f['type']) ?>"><?= e($f['message']) ?></div>
<?php endforeach; ?>

<?php if (is_local_dev() && isset($_GET['debug'])):
    $s = db_stats(); ?>
  <!-- yn-perf <?= json_encode([
      'queries'    => $s['count'],
      'db_ms'      => round($s['seconds'] * 1000, 1),
      'php_ms'     => round((microtime(true) - YN_T0) * 1000, 1),
      'peak_kb'    => (int) round(memory_get_peak_usage(true) / 1024),
      'slowest_ms' => round($s['slowest_seconds'] * 1000, 1),
      'slowest'    => mb_substr($s['slowest'], 0, 160),
  ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?> -->
<?php endif; ?>

</body>
</html>
