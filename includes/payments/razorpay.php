<?php
/**
 * Razorpay.
 *
 * Raw cURL rather than the SDK: two endpoints and two HMACs do not justify a
 * dependency on shared hosting, and this way there is nothing to keep updated.
 *
 * Card details never touch this server. Razorpay's hosted checkout collects
 * them; we only ever see an order id, a payment id and a signature.
 */
declare(strict_types=1);

const RZP_API = 'https://api.razorpay.com/v1';

function rzp_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }

    $cfg = ['key_id' => '', 'key_secret' => '', 'webhook_secret' => '', 'mode' => 'test'];

    $file = YN_ROOT . '/config/razorpay-config.local.php';
    if (is_file($file)) {
        $local = require $file;
        if (is_array($local)) {
            $cfg = array_merge($cfg, $local);
        }
    }

    return $cfg;
}

/** False when keys are missing — checkout then degrades instead of erroring. */
function rzp_configured(): bool
{
    $c = rzp_config();
    return $c['key_id'] !== '' && $c['key_secret'] !== '';
}

function rzp_key_id(): string
{
    return rzp_config()['key_id'];
}

function rzp_is_live(): bool
{
    return rzp_config()['mode'] === 'live';
}

/**
 * Authenticated request to the Razorpay API.
 * @return array{ok:bool, status:int, data:array, error:?string}
 */
function rzp_request(string $method, string $path, array $payload = []): array
{
    $cfg = rzp_config();

    if (!rzp_configured()) {
        return ['ok' => false, 'status' => 0, 'data' => [], 'error' => 'Razorpay is not configured.'];
    }

    $ch = curl_init(RZP_API . $path);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_USERPWD        => $cfg['key_id'] . ':' . $cfg['key_secret'],
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ];

    if ($method === 'POST') {
        $opts[CURLOPT_POST]       = true;
        $opts[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_SLASHES);
    } elseif ($method !== 'GET') {
        $opts[CURLOPT_CUSTOMREQUEST] = $method;
        $opts[CURLOPT_POSTFIELDS]    = json_encode($payload, JSON_UNESCAPED_SLASHES);
    }

    curl_setopt_array($ch, $opts);

    $body   = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlEr = curl_error($ch);
    curl_close($ch);

    if ($body === false) {
        error_log('Razorpay cURL error: ' . $curlEr);
        return ['ok' => false, 'status' => 0, 'data' => [], 'error' => 'Could not reach the payment gateway.'];
    }

    $data = json_decode((string) $body, true);
    $data = is_array($data) ? $data : [];

    if ($status < 200 || $status >= 300) {
        $msg = $data['error']['description'] ?? ('HTTP ' . $status);
        error_log('Razorpay API ' . $path . ' failed: ' . $msg . ' | ' . substr((string) $body, 0, 300));
        return ['ok' => false, 'status' => $status, 'data' => $data, 'error' => $msg];
    }

    return ['ok' => true, 'status' => $status, 'data' => $data, 'error' => null];
}

/**
 * Create the gateway-side order.
 *
 * The amount comes from our own recomputed total and is converted to integer
 * paise exactly once, here. Never send a float.
 */
function rzp_create_order(array $order): array
{
    $amountPaise = to_paise((float) $order['grand_total']);

    if ($amountPaise < 100) {
        return ['ok' => false, 'error' => 'Order total is below the minimum the gateway accepts (₹1).'];
    }

    $res = rzp_request('POST', '/orders', [
        'amount'          => $amountPaise,
        'currency'        => 'INR',
        'receipt'         => (string) $order['order_no'],
        'payment_capture' => 1,          // auto-capture on authorisation
        'notes'           => [
            'order_id' => (string) $order['id'],
            'order_no' => (string) $order['order_no'],
            'email'    => (string) $order['email'],
        ],
    ]);

    if (!$res['ok']) {
        return ['ok' => false, 'error' => $res['error']];
    }

    return ['ok' => true, 'id' => $res['data']['id'] ?? '', 'amount' => $amountPaise, 'raw' => $res['data']];
}

/**
 * Verify the handshake Razorpay Checkout returns to the browser.
 * Constant-time, and computed from OUR secret — the browser cannot forge it.
 */
function rzp_verify_signature(string $orderId, string $paymentId, string $signature): bool
{
    if ($orderId === '' || $paymentId === '' || $signature === '') {
        return false;
    }
    $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, rzp_config()['key_secret']);
    return hash_equals($expected, $signature);
}

/**
 * Verify a webhook. MUST be given the raw request body — re-encoding a decoded
 * payload changes the bytes and the HMAC will never match.
 */
function rzp_verify_webhook(string $rawBody, string $signature): bool
{
    $secret = rzp_config()['webhook_secret'];
    if ($secret === '' || $signature === '') {
        return false;
    }
    return hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
}

function rzp_fetch_payment(string $paymentId): array
{
    return rzp_request('GET', '/payments/' . rawurlencode($paymentId));
}

/**
 * Refund, fully or partially.
 * @param float|null $amount null = full refund
 */
function rzp_refund(string $paymentId, ?float $amount = null, string $reason = ''): array
{
    $payload = ['speed' => 'normal'];
    if ($amount !== null) {
        $payload['amount'] = to_paise($amount);
    }
    if ($reason !== '') {
        $payload['notes'] = ['reason' => mb_substr($reason, 0, 200)];
    }

    return rzp_request('POST', '/payments/' . rawurlencode($paymentId) . '/refund', $payload);
}

/** Human label for the method Razorpay reports. */
function rzp_method_label(?string $method): string
{
    return match ($method) {
        'upi'        => 'UPI',
        'card'       => 'Card',
        'netbanking' => 'Netbanking',
        'wallet'     => 'Wallet',
        'emi'        => 'EMI',
        'paylater'   => 'Pay Later',
        default      => $method ? ucfirst($method) : 'Online',
    };
}
