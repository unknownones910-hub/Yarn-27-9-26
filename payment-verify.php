<?php
/**
 * The browser's return from Razorpay Checkout.
 *
 * This is a convenience path, not the authority: the customer can close the
 * tab before it runs. The webhook is what guarantees a paid order is marked
 * paid. Both call the same idempotent order_mark_paid().
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/order.php';
require_once YN_ROOT . '/includes/payments/razorpay.php';

if (!is_post()) {
    redirect('');
}

csrf_verify();

$rzpOrderId   = post_str('razorpay_order_id', 60);
$rzpPaymentId = post_str('razorpay_payment_id', 60);
$rzpSignature = post_str('razorpay_signature', 255);

// Which of our orders does this gateway order belong to?
$payment = $rzpOrderId !== ''
    ? db_row('SELECT * FROM payments WHERE provider_order_id = ? ORDER BY id DESC LIMIT 1', [$rzpOrderId])
    : null;

if (!$payment) {
    error_log('payment-verify: unknown razorpay order ' . $rzpOrderId);
    flash('We could not match that payment to an order. Please contact us.', 'error');
    redirect('');
}

$order = order_by_id((int) $payment['order_id']);
if (!$order) {
    flash('We could not find that order.', 'error');
    redirect('');
}

// ---- the signature is the whole security check -------------------------
// It is an HMAC over "order_id|payment_id" using our secret, so a browser
// cannot fabricate a successful payment by posting to this endpoint.
if (!rzp_verify_signature($rzpOrderId, $rzpPaymentId, $rzpSignature)) {
    error_log('payment-verify: BAD SIGNATURE for order ' . $order['order_no']
              . ' payment ' . $rzpPaymentId);

    order_record_failure((int) $order['id'], [
        'provider_order_id'   => $rzpOrderId,
        'provider_payment_id' => $rzpPaymentId,
    ], 'Signature verification failed');

    flash('We could not verify that payment. If money has left your account it will be '
        . 'refunded automatically — please contact us with your order number.', 'error');
    redirect('pay.php?order=' . rawurlencode((string) $order['order_no']));
}

// Signature is good. Confirm the amount with Razorpay before fulfilling, so a
// tampered or partial capture cannot mark a big order paid.
$method = null;
$raw    = null;
$paidOk = true;

$fetched = rzp_fetch_payment($rzpPaymentId);
if ($fetched['ok']) {
    $p       = $fetched['data'];
    $method  = $p['method'] ?? null;
    $raw     = json_col($p);
    $expects = to_paise((float) $order['grand_total']);
    $got     = (int) ($p['amount'] ?? 0);
    $status  = (string) ($p['status'] ?? '');

    if ($got < $expects) {
        $paidOk = false;
        error_log("payment-verify: amount mismatch on {$order['order_no']} expected {$expects} got {$got}");
    }
    if (!in_array($status, ['captured', 'authorized'], true)) {
        $paidOk = false;
        error_log("payment-verify: unexpected payment status '{$status}' on {$order['order_no']}");
    }
}
// If the fetch itself failed we still trust the signature — the webhook will
// reconcile. Never strand a customer who has genuinely paid.

if (!$paidOk) {
    order_record_failure((int) $order['id'], [
        'provider_order_id'   => $rzpOrderId,
        'provider_payment_id' => $rzpPaymentId,
        'raw'                 => $raw,
    ], 'Amount or status mismatch');

    flash('There was a problem confirming your payment. Please contact us with your order number.', 'error');
    redirect('pay.php?order=' . rawurlencode((string) $order['order_no']));
}

$result = order_mark_paid((int) $order['id'], [
    'provider_order_id'   => $rzpOrderId,
    'provider_payment_id' => $rzpPaymentId,
    'signature'           => $rzpSignature,
    'method'              => $method,
    'amount'              => (float) $order['grand_total'],
    'raw'                 => $raw,
]);

if (!$result['ok']) {
    flash('Your payment went through but we hit a problem recording it. '
        . 'We have been alerted — please contact us with order ' . $order['order_no'] . '.', 'error');
    redirect('order-success/' . rawurlencode((string) $order['order_no']));
}

// Clear the cart only once the money is actually in.
$cartId = cart_id();
if ($cartId > 0) {
    cart_mark_converted($cartId);
}

$_SESSION['last_order_no'] = $order['order_no'];

if (empty($result['already'])) {
    flash('Payment received — thank you!', 'success');
}

redirect('order-success/' . rawurlencode((string) $order['order_no']));
