<?php
/**
 * Abandoned-cart reminders. Run every 6 hours.
 *
 *   0 *\/6 * * * /usr/local/bin/php /home/USER/public_html/cron/abandoned-cart.php
 *
 * Deliberately restrained: ONE reminder per cart, only to people who have an
 * account (so we have consent to email them), only if the cart still has
 * buyable items, and never for a cart that has already converted. Nagging
 * people costs more goodwill than it recovers in orders.
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

$MIN_AGE_HOURS = 6;    // give them time to come back on their own first
$MAX_AGE_DAYS  = 7;    // after a week the cart is history, not a nudge

$log('Looking for abandoned carts');

$carts = db_all(
    'SELECT c.id, c.customer_id, cu.email, cu.full_name, cu.marketing_opt_in
     FROM carts c
     JOIN customers cu ON cu.id = c.customer_id
     WHERE c.status = "active"
       AND c.reminder_sent_at IS NULL
       AND c.customer_id IS NOT NULL
       AND cu.deleted_at IS NULL
       AND cu.status = "active"
       AND c.updated_at < (NOW() - INTERVAL ? HOUR)
       AND c.updated_at > (NOW() - INTERVAL ? DAY)
       AND EXISTS (SELECT 1 FROM cart_items ci WHERE ci.cart_id = c.id AND ci.saved_for_later = 0)
     LIMIT 100',
    [$MIN_AGE_HOURS, $MAX_AGE_DAYS]
);

$sent    = 0;
$skipped = 0;

foreach ($carts as $c) {
    // Re-read the lines against live stock — reminding someone about a shade
    // that has since sold out is worse than saying nothing.
    $lines = db_all(
        'SELECT v.shade_name, v.color_hex, v.image, v.stock, v.price_override,
                p.name, p.slug, p.price, ci.qty
         FROM cart_items ci
         JOIN product_variants v ON v.id = ci.variant_id
         JOIN products p ON p.id = v.product_id
         WHERE ci.cart_id = ? AND ci.saved_for_later = 0
           AND v.is_active = 1 AND p.is_active = 1 AND v.stock > 0',
        [(int) $c['id']]
    );

    if (!$lines) {
        db_exec('UPDATE carts SET reminder_sent_at = NOW() WHERE id = ?', [(int) $c['id']]);
        $skipped++;
        continue;
    }

    $total = 0.0;
    $rows  = '';
    foreach ($lines as $l) {
        $price  = ($l['price_override'] !== null && $l['price_override'] !== '')
            ? (float) $l['price_override'] : (float) $l['price'];
        $total += $price * (int) $l['qty'];

        $rows .= '<tr>'
              . '<td style="padding:10px 0;border-bottom:1px solid #F5EEE4;'
              . 'font:14px/1.5 Arial,sans-serif;color:#2E2A26;">'
              . '<strong>' . e((string) $l['name']) . '</strong><br>'
              . '<span style="color:#8A8078;font-size:13px;">' . e((string) $l['shade_name'])
              . ' &middot; Qty ' . (int) $l['qty'] . '</span></td>'
              . '<td align="right" style="padding:10px 0;border-bottom:1px solid #F5EEE4;'
              . 'font:14px/1.5 Arial,sans-serif;white-space:nowrap;">'
              . e(money($price * (int) $l['qty'])) . '</td></tr>';
    }

    $body = '<p style="margin:0 0 16px;">Hello'
          . ($c['full_name'] ? ' ' . e(explode(' ', (string) $c['full_name'])[0]) : '')
          . ', you left some yarn in your cart.</p>'
          . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
          . $rows . '</table>'
          . '<p style="margin:16px 0;font:600 16px Arial,sans-serif;">Total: '
          . e(money($total)) . '</p>'
          . email_button('Finish your order', abs_url('cart.php'))
          . '<p style="margin:0 0 8px;color:#5D554C;font-size:14px;">Stock is not held, and shades '
          . 'do sell out — especially in a single dye lot.</p>'
          . '<p style="margin:0;color:#8A8078;font-size:13px;">This is the only reminder we will '
          . 'send about this cart. <a href="' . e(abs_url('account/notifications.php'))
          . '" style="color:#8A8078;">Manage your email preferences</a>.</p>';

    $ok = send_mail((string) $c['email'], (string) ($c['full_name'] ?? ''),
        'You left something in your cart',
        email_layout('Still thinking it over?', $body,
                     'Your cart is waiting — ' . money($total)),
        null, 'abandoned_cart');

    // Mark it either way: a mail failure must not turn into a nightly retry
    // that eventually spams them.
    db_exec('UPDATE carts SET reminder_sent_at = NOW() WHERE id = ?', [(int) $c['id']]);

    if ($ok) {
        $sent++;
        $log('  + reminded ' . $c['email'] . ' (' . money($total) . ')');
    } else {
        $skipped++;
    }
}

$log("Done. reminded={$sent} skipped={$skipped} examined=" . count($carts));
