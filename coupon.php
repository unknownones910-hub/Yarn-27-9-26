<?php
/**
 * Apply or remove a discount code.
 *
 * The code is only remembered in the session; the discount itself is
 * recalculated from scratch by pricing_totals() on every page, so a coupon
 * that stops being valid stops applying immediately.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/pricing.php';

if (!is_post()) {
    redirect('cart.php');
}

csrf_verify();

$action = post_str('action', 20);
$back   = post_str('redirect', 200);
if ($back === '' || !str_starts_with($back, '/') || str_starts_with($back, '//')) {
    $back = '/cart.php';
}

if ($action === 'remove') {
    unset($_SESSION['coupon_code']);
    flash('Discount code removed.', 'success');
    redirect($back);
}

$code = strtoupper(post_str('code', 40));

if ($code === '') {
    flash('Enter a discount code.', 'error');
    redirect($back);
}

// Guessing codes is cheap; make it slow.
if (!rate_hit('coupon:' . client_ip(), 20, 3600)) {
    flash('Too many code attempts. Please try again later.', 'error');
    redirect($back);
}

$subtotal = pricing_totals(['coupon_code' => null])['subtotal'];
$res      = coupon_validate($code, $subtotal, customer_id() ?: null);

if (!$res['ok']) {
    unset($_SESSION['coupon_code']);
    flash($res['error'], 'error');
    redirect($back);
}

$_SESSION['coupon_code'] = $res['coupon']['code'];

$saved = $res['free_shipping']
    ? 'Free delivery applied.'
    : money((float) $res['discount']) . ' off applied.';

flash($saved, 'success');
redirect($back);
