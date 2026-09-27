<?php
/**
 * Add to cart.
 *
 * Answers both AJAX (JSON) and a plain form POST (redirect + flash), so the
 * PDP works with JavaScript disabled.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (!is_post()) {
    redirect('shop.php');
}

csrf_verify();

$variantId = post_int('variant_id');
$qty       = max(1, min(CART_MAX_QTY, post_int('qty', 1)));

if ($variantId <= 0) {
    $result = ['ok' => false, 'error' => 'Please choose a shade first.'];
} else {
    $result = cart_add($variantId, $qty);
}

if (is_ajax()) {
    json_out([
        'ok'    => $result['ok'],
        'error' => $result['error'] ?? null,
        'count' => $result['count'] ?? cart_count(),
    ], $result['ok'] ? 200 : 422);
}

// Non-JS path.
if ($result['ok']) {
    flash($result['error'] ?? 'Added to your cart.', $result['error'] ? 'warn' : 'success');
} else {
    flash($result['error'] ?? 'Could not add that to your cart.', 'error');
}

$back = post_str('redirect', 300);
if ($back === '' || !str_starts_with($back, '/') || str_starts_with($back, '//')) {
    $back = '/shop.php';
}
redirect($back);
