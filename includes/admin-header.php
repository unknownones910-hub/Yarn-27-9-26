<?php
/**
 * Admin chrome. Pages set $pageTitle and $adminSection first.
 */
declare(strict_types=1);

$admin        = $admin        ?? current_admin();
$pageTitle    = $pageTitle    ?? 'Admin';
$adminSection = $adminSection ?? '';

/** Counts that deserve a badge — things waiting on the owner. */
function admin_badges(): array
{
    static $b = null;
    if ($b !== null) {
        return $b;
    }
    try {
        return $b = [
            'orders'   => (int) db_val('SELECT COUNT(*) FROM orders
                             WHERE status IN ("paid","processing")'),
            'reviews'  => (int) db_val('SELECT COUNT(*) FROM reviews WHERE status = "pending"'),
            'messages' => (int) db_val('SELECT COUNT(*) FROM contact_messages WHERE status = "new"'),
            'support'  => (int) db_val('SELECT COUNT(*) FROM support_tickets
                             WHERE status IN ("open","awaiting_customer")'),
            'returns'  => (int) db_val('SELECT COUNT(*) FROM return_requests WHERE status = "requested"'),
            'stock'    => (int) db_val('SELECT COUNT(*) FROM product_variants
                             WHERE is_active = 1 AND stock <= low_stock_at'),
        ];
    } catch (Throwable $e) {
        return $b = [];
    }
}

function admin_menu(): array
{
    return [
        'Sell' => [
            ['', 'Dashboard', 'grid', 'admin/', null],
            ['orders', 'Orders', 'package', 'admin/orders.php', 'orders'],
            ['returns', 'Returns', 'refresh', 'admin/returns.php', 'returns'],
            ['customers', 'Customers', 'user', 'admin/customers.php', null],
        ],
        'Catalog' => [
            ['products', 'Products', 'yarn', 'admin/products.php', null],
            ['inventory', 'Inventory', 'tag', 'admin/inventory.php', 'stock'],
            ['categories', 'Categories', 'sliders', 'admin/categories.php', null],
            ['coupons', 'Coupons', 'gift', 'admin/coupons.php', null],
        ],
        'Engage' => [
            ['reviews', 'Reviews', 'star', 'admin/reviews.php', 'reviews'],
            ['support', 'Support', 'chat', 'admin/support.php', 'support'],
            ['messages', 'Messages', 'mail', 'admin/messages.php', 'messages'],
        ],
        'Content' => [
            ['pages', 'Policy pages', 'shield', 'admin/pages.php', null],
            ['faq', 'FAQs', 'info', 'admin/faq.php', null],
            ['blog', 'Blog', 'edit', 'admin/blog.php', null],
        ],
        'System' => [
            ['reports', 'Reports', 'sort', 'admin/reports.php', null],
            ['settings', 'Settings', 'settings', 'admin/settings.php', null],
            ['users', 'Admin users', 'shield', 'admin/users.php', null],
            ['logs', 'Logs', 'clock', 'admin/logs.php', null],
            ['launch', 'Launch check', 'check-circle', 'admin/launch-check.php', null],
        ],
    ];
}

$badges = admin_badges();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($pageTitle) ?> · Admin</title>
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<link rel="icon" href="data:image/svg+xml,<?= rawurlencode(
    '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32">'
    . '<rect width="32" height="32" rx="8" fill="#2E2A26"/>'
    . '<circle cx="16" cy="16" r="8" fill="none" stroke="#B4552F" stroke-width="2"/></svg>'
) ?>">
<!-- Same anti-FOUC theme script as the storefront, and for the same reason:
     it must run BEFORE the stylesheets so the first paint is already in the
     right theme. The admin previously had no script at all, so it ignored the
     saved preference entirely and only ever followed the OS — choosing light
     on the shop and then opening the admin gave you a dark panel. -->
<script nonce="<?= e(csp_nonce()) ?>">
(function () {
  try {
    var saved = localStorage.getItem('yn-theme');
    if (saved === 'dark' || saved === 'light') {
      document.documentElement.setAttribute('data-theme', saved);
    }
  } catch (e) { /* private mode — the OS preference still applies */ }
})();
</script>
<link rel="stylesheet" href="<?= e(asset('css/style.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/admin.css')) ?>">
<script defer src="<?= e(asset('js/main.js')) ?>"></script>
<script defer src="<?= e(asset('js/admin.js')) ?>"></script>
</head>
<body class="admin">

<div class="admin-topbar">
  <button class="icon-btn" type="button" data-admin-nav aria-label="Menu" aria-expanded="false">
    <?= icon('menu', 22) ?></button>
  <span class="admin-topbar__title"><?= e($pageTitle) ?></span>
  <a class="icon-btn" href="<?= e(url('')) ?>" style="margin-left:auto" title="View shop" target="_blank" rel="noopener">
    <?= icon('external', 19) ?></a>
</div>

<div class="admin-shell">
  <nav class="admin-nav" id="admin-nav" aria-label="Admin sections">
    <a class="admin-nav__brand" href="<?= e(url('admin/')) ?>">
      <span><?= e(setting('store_name', 'The Yarn Nest')) ?></span>
      <span class="admin-nav__tag">Admin</span>
    </a>

    <div class="admin-nav__list">
      <?php
      /* Every variable here is prefixed, because this file is `require`d INTO
         the page's own scope. It used to loop `as $group => $items`, which
         silently overwrote $items on any page that had one — order-detail.php
         then rendered five blank rows from the System menu instead of the
         order's line items. Same class of bug as the $rawBody collision in the
         Razorpay webhook. */
      foreach (admin_menu() as $navGroup => $navItems): ?>
        <p class="admin-nav__group"><?= e($navGroup) ?></p>
        <?php foreach ($navItems as [$navKey, $navLabel, $navIco, $navHref, $navBadge]): ?>
          <?php $navCount = $navBadge !== null ? (int) ($badges[$navBadge] ?? 0) : 0; ?>
          <a href="<?= e(url($navHref)) ?>" <?= $navKey === $adminSection ? 'aria-current="page"' : '' ?>>
            <?= icon($navIco, 17) ?>
            <span><?= e($navLabel) ?></span>
            <?php if ($navCount > 0): ?>
              <span class="admin-nav__count"><?= $navCount > 99 ? '99+' : $navCount ?></span>
            <?php endif; ?>
          </a>
        <?php endforeach; ?>
      <?php endforeach; ?>
    </div>

    <div class="admin-nav__foot">
      <a href="<?= e(url('admin/account.php')) ?>"><?= e((string) ($admin['name'] ?? '')) ?></a>
      · <?= e((string) ($admin['role'] ?? '')) ?><br>
      <a href="<?= e(url('')) ?>" target="_blank" rel="noopener">View shop</a> ·
      <form method="post" action="<?= e(url('admin/logout.php')) ?>" style="display:inline">
        <?= csrf_field() ?>
        <button class="btn-link" type="submit" style="color:#D9D2C9;font-size:var(--fs-xs)">Sign out</button>
      </form>
    </div>
  </nav>

  <main class="admin-main">
    <?php foreach (flash_take() as $f): ?>
      <div class="alert alert-<?= e($f['type'] === 'error' ? 'error' : ($f['type'] === 'warn' ? 'warn' : 'success')) ?> mb4">
        <?= icon($f['type'] === 'error' ? 'alert' : 'check-circle', 18) ?>
        <div><?= e($f['message']) ?></div>
      </div>
    <?php endforeach; ?>
