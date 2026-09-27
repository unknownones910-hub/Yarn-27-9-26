<?php
/**
 * Back-in-stock alerts. Run every 30 minutes.
 *
 *   *\/30 * * * * /usr/local/bin/php /home/USER/public_html/cron/restock-alerts.php
 *
 * Two sources of interest: an explicit restock_alerts row, and a wishlist entry
 * with notify_restock set. Each person is told once per restock — notified_at
 * is stamped immediately so a slow mail queue cannot cause a double send.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

define('YN_NO_ERROR_PAGE', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/mailer.php';

$log = static fn(string $m) => print('[' . date('Y-m-d H:i:s') . '] ' . $m . PHP_EOL);

$log('Checking for restocked shades');

$sent = 0;

// ---- explicit "notify me" requests ------------------------------------
$alerts = db_all(
    'SELECT a.id, a.email, a.variant_id,
            v.sku, v.shade_name, v.color_hex, v.image, v.stock,
            p.name, p.slug
     FROM restock_alerts a
     JOIN product_variants v ON v.id = a.variant_id
     JOIN products p ON p.id = v.product_id
     WHERE a.notified_at IS NULL
       AND v.is_active = 1 AND p.is_active = 1 AND v.stock > 0
     LIMIT 200'
);

foreach ($alerts as $a) {
    // Claim it before sending, so a crash mid-run cannot re-notify.
    db_exec('UPDATE restock_alerts SET notified_at = NOW() WHERE id = ?', [(int) $a['id']]);

    $url = abs_url('product/' . $a['slug'] . '?shade=' . rawurlencode((string) $a['sku']));

    $body = '<p style="margin:0 0 16px;"><strong>' . e((string) $a['shade_name'])
          . '</strong> in ' . e((string) $a['name']) . ' is back in stock.</p>'
          . '<p style="margin:0 0 16px;color:#5D554C;">We only have '
          . (int) $a['stock'] . ' right now, and we are not holding any back — '
          . 'first come, first served.</p>'
          . email_button('Go to the shade', $url)
          . '<p style="margin:0;color:#8A8078;font-size:13px;">You asked us to tell you when this '
          . 'came back. We will not email you about it again.</p>';

    if (send_mail((string) $a['email'], '',
        'Back in stock: ' . $a['shade_name'],
        email_layout('It is back', $body,
                     $a['shade_name'] . ' is available again'),
        null, 'restock_alert')) {
        $sent++;
        $log('  + told ' . $a['email'] . ' about ' . $a['sku']);
    }
}

// ---- wishlist entries with the notify toggle on -----------------------
$wish = db_all(
    'SELECT w.customer_id, w.variant_id, c.email, c.full_name,
            v.sku, v.shade_name, v.stock, p.name, p.slug
     FROM wishlists w
     JOIN customers c ON c.id = w.customer_id
     JOIN product_variants v ON v.id = w.variant_id
     JOIN products p ON p.id = v.product_id
     WHERE w.notify_restock = 1
       AND c.deleted_at IS NULL AND c.status = "active"
       AND v.is_active = 1 AND p.is_active = 1 AND v.stock > 0
     LIMIT 200'
);

foreach ($wish as $w) {
    // Clearing the flag is what marks it as handled.
    db_exec('UPDATE wishlists SET notify_restock = 0 WHERE customer_id = ? AND variant_id = ?',
            [(int) $w['customer_id'], (int) $w['variant_id']]);

    $url = abs_url('product/' . $w['slug'] . '?shade=' . rawurlencode((string) $w['sku']));

    $body = '<p style="margin:0 0 16px;">The shade you saved is available again:</p>'
          . '<p style="margin:0 0 16px;"><strong>' . e((string) $w['shade_name'])
          . '</strong> &middot; ' . e((string) $w['name']) . '</p>'
          . email_button('View it now', $url)
          . '<p style="margin:0;color:#8A8078;font-size:13px;">From your '
          . '<a href="' . e(abs_url('account/wishlist.php')) . '" style="color:#8A8078;">wishlist</a>.</p>';

    if (send_mail((string) $w['email'], (string) ($w['full_name'] ?? ''),
        'Back in stock: ' . $w['shade_name'],
        email_layout('Back in stock', $body), null, 'restock_wishlist')) {
        $sent++;
        $log('  + told ' . $w['email'] . ' about wishlisted ' . $w['sku']);
    }
}

// Housekeeping: alerts for shades that no longer exist will never fire.
$stale = db_exec(
    'DELETE a FROM restock_alerts a
     LEFT JOIN product_variants v ON v.id = a.variant_id
     WHERE v.id IS NULL OR (a.notified_at IS NOT NULL AND a.notified_at < (NOW() - INTERVAL 60 DAY))'
);

$log("Done. sent={$sent} pruned={$stale}");
