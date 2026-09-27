<?php
/**
 * Razorpay webhook — the source of truth for payment state.
 *
 * The browser callback can be lost (closed tab, dead battery, flaky network).
 * This endpoint cannot, because Razorpay retries it. Everything it does is
 * idempotent, and `payment_webhooks.event_id` has a UNIQUE index so a replayed
 * delivery is recognised and skipped rather than double-processed.
 *
 * Register at: https://yourdomain/webhooks/razorpay.php
 * Events: payment.captured, payment.failed, order.paid, refund.processed
 */
declare(strict_types=1);

// The raw body must be read before anything can parse or mutate it — the HMAC
// is over exact bytes, and re-encoding a decoded array will never match.
$rawBody = file_get_contents('php://input');

define('YN_NO_ERROR_PAGE', true);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/order.php';
require_once YN_ROOT . '/includes/payments/razorpay.php';

header('Content-Type: application/json');

/** Always answer 200 for anything we have handled or intentionally ignored. */
function hook_out(string $status, int $code = 200, string $detail = ''): void
{
    http_response_code($code);
    echo json_encode(['status' => $status] + ($detail !== '' ? ['detail' => $detail] : []));
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    hook_out('method_not_allowed', 405);
}

$signature = $_SERVER['HTTP_X_RAZORPAY_SIGNATURE'] ?? '';

if (!is_string($rawBody) || $rawBody === '') {
    hook_out('empty_body', 400);
}

if (!rzp_verify_webhook($rawBody, (string) $signature)) {
    error_log('Razorpay webhook: signature verification FAILED');
    hook_out('invalid_signature', 401);
}

$payload = json_decode($rawBody, true);
if (!is_array($payload)) {
    hook_out('bad_json', 400);
}

$eventType = (string) ($payload['event'] ?? '');

// Razorpay sends x-razorpay-event-id; fall back to a content hash so
// idempotency still holds if the header is absent.
$eventId = (string) ($_SERVER['HTTP_X_RAZORPAY_EVENT_ID'] ?? '');
if ($eventId === '') {
    $eventId = 'sha:' . hash('sha256', $rawBody);
}

// ---- idempotency -------------------------------------------------------
// The UNIQUE index does the work: a duplicate insert throws, and that means
// we have seen this exact event before.
try {
    db_exec(
        'INSERT INTO payment_webhooks (event_id, event_type, payload) VALUES (?,?,?)',
        [mb_substr($eventId, 0, 80), mb_substr($eventType, 0, 60), $rawBody]
    );
} catch (Throwable $e) {
    hook_out('duplicate_ignored');
}

$entities  = $payload['payload'] ?? [];
$paymentEn = $entities['payment']['entity'] ?? null;
$refundEn  = $entities['refund']['entity'] ?? null;
$orderEn   = $entities['order']['entity'] ?? null;

/** Resolve our order from whatever the event carries. */
function hook_find_order(?array $paymentEn, ?array $orderEn): ?array
{
    $providerOrderId = $paymentEn['order_id'] ?? ($orderEn['id'] ?? null);

    if ($providerOrderId) {
        $row = db_row('SELECT * FROM payments WHERE provider_order_id = ? ORDER BY id DESC LIMIT 1',
                      [$providerOrderId]);
        if ($row) {
            return order_by_id((int) $row['order_id']);
        }
    }

    // Fall back to the notes we attached when creating the gateway order.
    $orderNo = $paymentEn['notes']['order_no'] ?? ($orderEn['notes']['order_no'] ?? null);
    if ($orderNo) {
        return order_by_no((string) $orderNo);
    }

    return null;
}

$handled = 'ignored';

try {
    switch ($eventType) {

        case 'payment.captured':
        case 'order.paid':
            $order = hook_find_order($paymentEn, $orderEn);
            if (!$order) {
                $handled = 'order_not_found';
                break;
            }

            // Never mark paid for less than the order is worth.
            $paise = (int) ($paymentEn['amount'] ?? ($orderEn['amount_paid'] ?? 0));
            if ($paise > 0 && $paise < to_paise((float) $order['grand_total'])) {
                error_log('Razorpay webhook: underpayment on ' . $order['order_no']
                          . " ({$paise} paise)");
                $handled = 'amount_mismatch';
                break;
            }

            $res = order_mark_paid((int) $order['id'], [
                'provider_order_id'   => $paymentEn['order_id'] ?? ($orderEn['id'] ?? null),
                'provider_payment_id' => $paymentEn['id'] ?? null,
                'signature'           => null,
                'method'              => $paymentEn['method'] ?? null,
                'amount'              => $paise > 0 ? $paise / 100 : (float) $order['grand_total'],
                'raw'                 => json_col($paymentEn ?: $orderEn),
            ]);

            $handled = !empty($res['already']) ? 'already_paid' : 'marked_paid';
            break;

        case 'payment.failed':
            $order = hook_find_order($paymentEn, $orderEn);
            if (!$order) {
                $handled = 'order_not_found';
                break;
            }
            order_record_failure((int) $order['id'], [
                'provider_order_id'   => $paymentEn['order_id'] ?? null,
                'provider_payment_id' => $paymentEn['id'] ?? null,
                'method'              => $paymentEn['method'] ?? null,
                'amount'              => isset($paymentEn['amount']) ? $paymentEn['amount'] / 100 : 0,
                'error_code'          => $paymentEn['error_code'] ?? null,
                'raw'                 => json_col($paymentEn),
            ], (string) ($paymentEn['error_description'] ?? 'Payment failed'));

            // Only chase orders that are still genuinely unpaid.
            if ($order['payment_status'] === 'unpaid') {
                try {
                    order_send_payment_failed((int) $order['id']);
                } catch (Throwable $e) {
                    error_log('payment_failed email: ' . $e->getMessage());
                }
            }
            $handled = 'failure_recorded';
            break;

        case 'refund.processed':
        case 'refund.created':
            $paymentId = $refundEn['payment_id'] ?? null;
            if (!$paymentId) {
                $handled = 'no_payment_id';
                break;
            }
            $pay = db_row('SELECT * FROM payments WHERE provider_payment_id = ? LIMIT 1', [$paymentId]);
            if (!$pay) {
                $handled = 'payment_not_found';
                break;
            }

            $amount = isset($refundEn['amount']) ? ((int) $refundEn['amount']) / 100 : 0.0;

            db_exec(
                'INSERT INTO refunds (order_id, provider_refund_id, amount, reason, status)
                 VALUES (?,?,?,?,"processed")',
                [(int) $pay['order_id'], $refundEn['id'] ?? null, $amount,
                 $refundEn['notes']['reason'] ?? 'Refund processed by gateway']
            );

            $order = order_by_id((int) $pay['order_id']);
            if ($order) {
                $full = $amount >= (float) $order['grand_total'] - 0.01;
                db_exec('UPDATE orders SET payment_status = ? WHERE id = ?',
                        [$full ? 'refunded' : 'partially_refunded', (int) $order['id']]);
                if ($full) {
                    order_restock((int) $order['id'], 'return');
                    order_set_status((int) $order['id'], 'refunded', 'Refund processed', 'system');
                }
            }
            $handled = 'refund_recorded';
            break;

        default:
            $handled = 'unhandled_event';
    }

    db_exec('UPDATE payment_webhooks SET processed_at = NOW() WHERE event_id = ?',
            [mb_substr($eventId, 0, 80)]);

} catch (Throwable $e) {
    error_log('Razorpay webhook processing error: ' . $e->getMessage());
    db_exec('UPDATE payment_webhooks SET error = ? WHERE event_id = ?',
            [mb_substr($e->getMessage(), 0, 255), mb_substr($eventId, 0, 80)]);

    // 500 asks Razorpay to retry, which is what we want for a transient fault.
    hook_out('processing_error', 500, $e->getMessage());
}

hook_out($handled);
