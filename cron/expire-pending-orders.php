<?php
/**
 * Housekeeping + payment reconciliation. Run hourly.
 *
 *   C:\xampp\php\php.exe cron/expire-pending-orders.php
 *   0 * * * * /usr/local/bin/php /home/USER/public_html/cron/expire-pending-orders.php
 *
 * Two jobs:
 *
 *  1. Cancel orders that were never paid for. No stock was reserved, so this
 *     is pure tidying — but it stops the order list filling with dead rows and
 *     it frees the coupon redemption.
 *
 *  2. Catch orders that ARE paid at the gateway but were never marked paid
 *     here — a webhook that never arrived and a browser that closed. This is
 *     the safety net that stops a customer paying and hearing nothing.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

define('YN_NO_ERROR_PAGE', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/order.php';
require_once YN_ROOT . '/includes/payments/razorpay.php';

$EXPIRE_AFTER_MINUTES = 60;

$log = static function (string $msg): void {
    echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
};

$log('Starting reconciliation');

// ---------------------------------------------------------------------
// 1. Reconcile before expiring — never cancel something that was paid.
// ---------------------------------------------------------------------
$rescued = 0;

if (rzp_configured()) {
    $candidates = db_all(
        'SELECT o.id, o.order_no, o.grand_total, p.provider_order_id
         FROM orders o
         JOIN payments p ON p.order_id = o.id
         WHERE o.payment_status = "unpaid"
           AND o.status = "pending_payment"
           AND p.provider_order_id IS NOT NULL
           AND o.placed_at < (NOW() - INTERVAL 10 MINUTE)
           AND o.placed_at > (NOW() - INTERVAL 7 DAY)
         GROUP BY o.id
         LIMIT 100'
    );

    foreach ($candidates as $c) {
        $res = rzp_request('GET', '/orders/' . rawurlencode((string) $c['provider_order_id']) . '/payments');
        if (!$res['ok']) {
            $log('  ! could not query gateway for ' . $c['order_no'] . ': ' . (string) $res['error']);
            continue;
        }

        foreach ($res['data']['items'] ?? [] as $p) {
            if (($p['status'] ?? '') !== 'captured') {
                continue;
            }

            $paise = (int) ($p['amount'] ?? 0);
            if ($paise < to_paise((float) $c['grand_total'])) {
                $log('  ! ' . $c['order_no'] . ' has a captured payment for less than the total — skipping');
                continue;
            }

            $log('  + RESCUED ' . $c['order_no'] . ' — captured at the gateway but unpaid here');
            order_mark_paid((int) $c['id'], [
                'provider_order_id'   => $c['provider_order_id'],
                'provider_payment_id' => $p['id'] ?? null,
                'signature'           => null,
                'method'              => $p['method'] ?? null,
                'amount'              => $paise / 100,
                'raw'                 => json_col($p),
            ]);
            $rescued++;
            break;
        }
    }
} else {
    $log('  (gateway not configured — skipping reconciliation)');
}

// ---------------------------------------------------------------------
// 2. Expire the genuinely abandoned ones.
// ---------------------------------------------------------------------
$stale = db_all(
    'SELECT id, order_no FROM orders
     WHERE payment_status = "unpaid"
       AND status = "pending_payment"
       AND placed_at < (NOW() - INTERVAL ? MINUTE)
     LIMIT 200',
    [$EXPIRE_AFTER_MINUTES]
);

$expired = 0;
foreach ($stale as $o) {
    // No stock was ever taken for an unpaid order, so there is nothing to
    // put back — order_cancel() checks that itself.
    if (order_cancel((int) $o['id'], 'Payment not completed', 'system')) {
        $log('  - expired ' . $o['order_no']);
        $expired++;
    }
}

// ---------------------------------------------------------------------
// 3. Flag anything that looks wrong for a human.
// ---------------------------------------------------------------------
$mismatched = db_all(
    'SELECT o.order_no
     FROM orders o
     JOIN payments p ON p.order_id = o.id AND p.status = "captured"
     WHERE o.payment_status <> "paid"
       AND o.placed_at > (NOW() - INTERVAL 30 DAY)
     GROUP BY o.id
     LIMIT 50'
);
foreach ($mismatched as $m) {
    $log('  !! ' . $m['order_no'] . ' has a captured payment but is not marked paid — CHECK MANUALLY');
}

// Old abandoned carts, so the table does not grow forever.
$carts = db_exec(
    'UPDATE carts SET status = "abandoned"
     WHERE status = "active" AND updated_at < (NOW() - INTERVAL 30 DAY)'
);

$log("Done. rescued={$rescued} expired={$expired} carts_abandoned={$carts} "
   . 'mismatched=' . count($mismatched));
