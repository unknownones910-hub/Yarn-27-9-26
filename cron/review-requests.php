<?php
/**
 * Ask for a review a few days after delivery. Run once daily.
 *
 *   0 10 * * * /usr/local/bin/php /home/USER/public_html/cron/review-requests.php
 *
 * Waits until the yarn has actually been used, asks once, and never asks about
 * something the customer has already reviewed. One email per order, ever.
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

if (!setting_bool('feat_reviews', true)) {
    $log('Reviews are switched off — nothing to do.');
    exit(0);
}

$WAIT_DAYS   = 5;    // long enough to have wound a ball and started
$WINDOW_DAYS = 30;   // after a month the moment has passed

$log('Looking for delivered orders to follow up');

$orders = db_all(
    'SELECT o.id, o.order_no, o.email, o.ship_name, o.customer_id, o.delivered_at
     FROM orders o
     WHERE o.status = "delivered"
       AND o.customer_id IS NOT NULL
       AND o.delivered_at IS NOT NULL
       AND o.delivered_at < (NOW() - INTERVAL ? DAY)
       AND o.delivered_at > (NOW() - INTERVAL ? DAY)
       AND NOT EXISTS (
             SELECT 1 FROM email_log el
             WHERE el.to_email = o.email AND el.template = "review_request"
               AND el.subject LIKE CONCAT("%", o.order_no, "%")
           )
     LIMIT 50',
    [$WAIT_DAYS, $WINDOW_DAYS]
);

$sent = 0;

foreach ($orders as $o) {
    // Only the items they have not already reviewed.
    $items = db_all(
        'SELECT DISTINCT oi.product_id, oi.product_name, oi.shade_name, oi.color_hex, oi.image
         FROM order_items oi
         WHERE oi.order_id = ?
           AND oi.product_id IS NOT NULL
           AND NOT EXISTS (
                 SELECT 1 FROM reviews r
                 WHERE r.customer_id = ? AND r.product_id = oi.product_id AND r.order_id = ?
               )',
        [(int) $o['id'], (int) $o['customer_id'], (int) $o['id']]
    );

    if (!$items) {
        continue;   // already reviewed everything — leave them alone
    }

    $rows = '';
    foreach ($items as $it) {
        $rows .= '<tr><td style="padding:8px 0;border-bottom:1px solid #F5EEE4;'
              . 'font:14px/1.5 Arial,sans-serif;color:#2E2A26;">'
              . '<strong>' . e((string) $it['product_name']) . '</strong>'
              . ($it['shade_name'] ? '<br><span style="color:#8A8078;font-size:13px;">'
                    . e((string) $it['shade_name']) . '</span>' : '')
              . '</td></tr>';
    }

    $body = '<p style="margin:0 0 16px;">Hello '
          . e(explode(' ', (string) $o['ship_name'])[0]) . ',</p>'
          . '<p style="margin:0 0 16px;">Your order <strong>' . e((string) $o['order_no'])
          . '</strong> arrived a few days ago. How has the yarn worked up?</p>'
          . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
          . $rows . '</table>'
          . email_button('Leave a review', abs_url('account/reviews.php'))
          . '<p style="margin:0 0 16px;color:#5D554C;font-size:14px;">Two honest sentences help '
          . 'the next person more than a hundred five-star ratings. Tell us what you did not '
          . 'like too — we publish that.</p>'
          . '<p style="margin:0;color:#8A8078;font-size:13px;">Something wrong with the order? '
          . '<a href="' . e(abs_url('contact.php')) . '" style="color:#8A8078;">Tell us instead</a> '
          . 'and we will fix it.</p>';

    if (send_mail((string) $o['email'], (string) $o['ship_name'],
        'How did it work up? · ' . $o['order_no'],
        email_layout('How was your yarn?', $body,
                     'A quick review of order ' . $o['order_no']),
        null, 'review_request')) {
        $sent++;
        $log('  + asked ' . $o['email'] . ' about ' . $o['order_no']);
    }
}

$log("Done. requested={$sent} examined=" . count($orders));
