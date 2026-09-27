<?php
/**
 * Order fulfilment.
 *
 * Reached from two directions that can race each other:
 *   · the browser returning from Razorpay Checkout  (payment-verify.php)
 *   · Razorpay's server-to-server webhook           (webhooks/razorpay.php)
 *
 * Everything here is therefore idempotent. The order row is locked FOR UPDATE
 * and re-checked inside the transaction, so whichever path arrives second
 * finds the work already done and quietly agrees.
 */
declare(strict_types=1);

require_once __DIR__ . '/order-emails.php';

function order_by_no(string $orderNo): ?array
{
    return db_row('SELECT * FROM orders WHERE order_no = ? LIMIT 1', [$orderNo]);
}

function order_by_id(int $id): ?array
{
    return db_row('SELECT * FROM orders WHERE id = ? LIMIT 1', [$id]);
}

function order_items(int $orderId): array
{
    return db_all('SELECT * FROM order_items WHERE order_id = ? ORDER BY id', [$orderId]);
}

/** Allowed status moves. Anything not listed is rejected, including from admin. */
function order_allowed_transitions(): array
{
    return [
        'pending_payment'  => ['paid', 'failed', 'cancelled'],
        'failed'           => ['pending_payment', 'paid', 'cancelled'],
        'paid'             => ['processing', 'packed', 'cancelled', 'refunded'],
        'processing'       => ['packed', 'cancelled', 'refunded'],
        'packed'           => ['shipped', 'cancelled', 'refunded'],
        'shipped'          => ['out_for_delivery', 'delivered', 'returned'],
        'out_for_delivery' => ['delivered', 'returned'],
        'delivered'        => ['returned', 'refunded'],
        'cancelled'        => [],
        'refunded'         => [],
        'returned'         => ['refunded'],
    ];
}

function order_can_transition(string $from, string $to): bool
{
    return in_array($to, order_allowed_transitions()[$from] ?? [], true);
}

function order_set_status(int $orderId, string $to, string $note = '', string $by = 'system', ?int $adminId = null): bool
{
    $order = order_by_id($orderId);
    if (!$order) {
        return false;
    }
    $from = (string) $order['status'];
    if ($from === $to) {
        return true;
    }
    if (!order_can_transition($from, $to)) {
        error_log("Rejected order status move {$from} -> {$to} on order {$orderId}");
        return false;
    }

    $stamps = [
        'shipped'   => 'shipped_at',
        'delivered' => 'delivered_at',
        'cancelled' => 'cancelled_at',
    ];

    $sql = 'UPDATE orders SET status = ?';
    $params = [$to];
    if (isset($stamps[$to])) {
        $sql .= ', ' . $stamps[$to] . ' = NOW()';
    }
    $sql .= ' WHERE id = ?';
    $params[] = $orderId;

    db_exec($sql, $params);
    db_exec(
        'INSERT INTO order_status_history (order_id, from_status, to_status, note, changed_by, admin_id)
         VALUES (?,?,?,?,?,?)',
        [$orderId, $from, $to, $note !== '' ? mb_substr($note, 0, 255) : null, $by, $adminId]
    );

    return true;
}

/**
 * Mark an order paid and take the stock down.
 *
 * @param array $payment  provider_payment_id, provider_order_id, signature,
 *                        method, amount, raw
 * @return array{ok:bool, already:bool, shortfall:array, error?:string}
 */
function order_mark_paid(int $orderId, array $payment): array
{
    $shortfall = [];

    try {
        $result = db_tx(static function () use ($orderId, $payment, &$shortfall) {

            // Lock the row so the browser callback and the webhook cannot both
            // decrement stock for the same order.
            $order = db_row('SELECT * FROM orders WHERE id = ? FOR UPDATE', [$orderId]);
            if (!$order) {
                return ['ok' => false, 'already' => false, 'error' => 'Order not found.'];
            }

            if ($order['payment_status'] === 'paid') {
                return ['ok' => true, 'already' => true];
            }

            // ---- record the payment ---------------------------------
            // Prefer the row for this exact payment; otherwise promote the
            // "created" row from the attempt that just succeeded, so the
            // payments table holds one row per attempt rather than an orphan
            // alongside every capture.
            $existing = !empty($payment['provider_payment_id'])
                ? db_row('SELECT id FROM payments WHERE provider_payment_id = ? LIMIT 1',
                         [$payment['provider_payment_id']])
                : null;

            if (!$existing && !empty($payment['provider_order_id'])) {
                $existing = db_row(
                    'SELECT id FROM payments
                     WHERE order_id = ? AND provider_order_id = ? AND status = "created"
                     ORDER BY id DESC LIMIT 1',
                    [$orderId, $payment['provider_order_id']]
                );
                if ($existing) {
                    db_exec('UPDATE payments SET provider_payment_id = ? WHERE id = ?',
                            [$payment['provider_payment_id'] ?? null, (int) $existing['id']]);
                }
            }

            if ($existing) {
                db_exec(
                    'UPDATE payments SET status = "captured", method = ?, provider_signature = ?,
                            raw_response = ? WHERE id = ?',
                    [$payment['method'] ?? null, $payment['signature'] ?? null,
                     $payment['raw'] ?? null, (int) $existing['id']]
                );
            } else {
                db_exec(
                    'INSERT INTO payments
                        (order_id, provider, provider_order_id, provider_payment_id, provider_signature,
                         method, amount, status, raw_response)
                     VALUES (?, "razorpay", ?, ?, ?, ?, ?, "captured", ?)',
                    [
                        $orderId,
                        $payment['provider_order_id'] ?? null,
                        $payment['provider_payment_id'] ?? null,
                        $payment['signature'] ?? null,
                        $payment['method'] ?? null,
                        (float) ($payment['amount'] ?? $order['grand_total']),
                        $payment['raw'] ?? null,
                    ]
                );
            }

            // ---- take the stock down --------------------------------
            // The guarded UPDATE is the whole concurrency story: if two orders
            // race for the last ball, only one gets affected_rows === 1.
            foreach (order_items($orderId) as $item) {
                $variantId = (int) $item['variant_id'];
                $qty       = (int) $item['qty'];
                if ($variantId <= 0 || $qty <= 0) {
                    continue;
                }

                $affected = db_exec(
                    'UPDATE product_variants SET stock = stock - ? WHERE id = ? AND stock >= ?',
                    [$qty, $variantId, $qty]
                );

                if ($affected === 1) {
                    db_exec(
                        'INSERT INTO stock_movements (variant_id, delta, reason, ref_type, ref_id, note)
                         VALUES (?, ?, "order", "order", ?, ?)',
                        [$variantId, -$qty, $orderId, 'Order ' . $order['order_no']]
                    );
                    db_exec('UPDATE products SET sold_count = sold_count + ? WHERE id = ?',
                            [$qty, (int) $item['product_id']]);
                } else {
                    // Payment is already captured. We must NOT roll back — that
                    // would keep the customer's money and cancel the record of
                    // it. Flag the line and let a human resolve it.
                    $shortfall[] = [
                        'sku'   => $item['sku'],
                        'name'  => $item['product_name'],
                        'shade' => $item['shade_name'],
                        'qty'   => $qty,
                    ];
                }
            }

            // ---- flip the order -------------------------------------
            db_exec(
                'UPDATE orders SET status = "paid", payment_status = "paid", paid_at = NOW() WHERE id = ?',
                [$orderId]
            );
            db_exec(
                'INSERT INTO order_status_history (order_id, from_status, to_status, note, changed_by)
                 VALUES (?, ?, "paid", ?, "system")',
                [$orderId, $order['status'], 'Payment captured']
            );

            if ($shortfall) {
                $names = array_map(static fn($s) => $s['sku'] . ' ×' . $s['qty'], $shortfall);
                db_exec(
                    'UPDATE orders SET admin_note = CONCAT(COALESCE(admin_note,""), ?) WHERE id = ?',
                    ["\n[STOCK SHORTFALL] Paid but not deducted: " . implode(', ', $names), $orderId]
                );
            }

            if ($order['customer_id']) {
                db_exec(
                    'UPDATE customers SET orders_count = orders_count + 1, total_spent = total_spent + ?
                     WHERE id = ?',
                    [(float) $order['grand_total'], (int) $order['customer_id']]
                );
            }

            return ['ok' => true, 'already' => false];
        });
    } catch (Throwable $e) {
        error_log('order_mark_paid failed for order ' . $orderId . ': ' . $e->getMessage());
        return ['ok' => false, 'already' => false, 'shortfall' => [],
                'error' => 'Could not finalise the order.'];
    }

    $result['shortfall'] = $shortfall;

    // Side effects only after the transaction commits. A mail failure must
    // never undo a paid order.
    if (($result['ok'] ?? false) && empty($result['already'])) {
        try {
            order_send_confirmation($orderId);
            if ($shortfall) {
                order_notify_admin_shortfall($orderId, $shortfall);
            } else {
                order_notify_admin_new($orderId);
            }
        } catch (Throwable $e) {
            error_log('Post-payment notifications failed for order ' . $orderId . ': ' . $e->getMessage());
        }
    }

    return $result;
}

/** Record a failed attempt without touching the order's own status history. */
function order_record_failure(int $orderId, array $payment, string $reason = ''): void
{
    try {
        db_exec(
            'INSERT INTO payments
                (order_id, provider, provider_order_id, provider_payment_id, method, amount,
                 status, error_code, error_desc, raw_response)
             VALUES (?, "razorpay", ?, ?, ?, ?, "failed", ?, ?, ?)
             ON DUPLICATE KEY UPDATE status = "failed", error_desc = VALUES(error_desc)',
            [
                $orderId,
                $payment['provider_order_id'] ?? null,
                $payment['provider_payment_id'] ?? null,
                $payment['method'] ?? null,
                (float) ($payment['amount'] ?? 0),
                $payment['error_code'] ?? null,
                mb_substr($reason, 0, 255),
                $payment['raw'] ?? null,
            ]
        );
    } catch (Throwable $e) {
        error_log('order_record_failure: ' . $e->getMessage());
    }
}

/**
 * Put stock back — cancellation or refund.
 * Safe to call once per order; guarded by a marker in the history.
 */
function order_restock(int $orderId, string $reason = 'cancel'): void
{
    $already = (int) db_val(
        'SELECT COUNT(*) FROM stock_movements WHERE ref_type = "order" AND ref_id = ? AND delta > 0',
        [$orderId]
    );
    if ($already > 0) {
        return;
    }

    // Only restock what actually came out.
    $deducted = (int) db_val(
        'SELECT COUNT(*) FROM stock_movements WHERE ref_type = "order" AND ref_id = ? AND delta < 0',
        [$orderId]
    );
    if ($deducted === 0) {
        return;
    }

    db_tx(static function () use ($orderId, $reason) {
        foreach (order_items($orderId) as $item) {
            $variantId = (int) $item['variant_id'];
            $qty       = (int) $item['qty'];
            if ($variantId <= 0 || $qty <= 0) {
                continue;
            }
            db_exec('UPDATE product_variants SET stock = stock + ? WHERE id = ?', [$qty, $variantId]);
            db_exec(
                'INSERT INTO stock_movements (variant_id, delta, reason, ref_type, ref_id, note)
                 VALUES (?, ?, ?, "order", ?, ?)',
                [$variantId, $qty, $reason === 'return' ? 'return' : 'cancel', $orderId, 'Restocked']
            );
        }
    });
}

/** Cancel an unpaid or paid-but-unshipped order. */
function order_cancel(int $orderId, string $reason, string $by = 'customer', ?int $adminId = null): bool
{
    $order = order_by_id($orderId);
    if (!$order) {
        return false;
    }

    if (!order_can_transition((string) $order['status'], 'cancelled')) {
        return false;
    }

    if ($order['payment_status'] === 'paid') {
        order_restock($orderId, 'cancel');
    }

    db_exec('UPDATE orders SET cancel_reason = ? WHERE id = ?', [mb_substr($reason, 0, 255), $orderId]);
    order_set_status($orderId, 'cancelled', $reason, $by, $adminId);

    try {
        order_send_cancelled($orderId, $reason);
    } catch (Throwable $e) {
        error_log('Cancellation email failed: ' . $e->getMessage());
    }

    return true;
}

/** Can the customer still cancel this themselves? */
function order_customer_cancellable(array $order): bool
{
    return in_array($order['status'], ['pending_payment', 'paid', 'processing'], true);
}
