<?php
/**
 * Cart mutations: quantity, remove, save for later, gift wrap.
 * Answers AJAX with JSON and a plain form POST with a redirect.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/pricing.php';

if (!is_post()) {
    redirect('cart.php');
}

csrf_verify();

$action    = post_str('action', 20);
$variantId = post_int('variant_id');

$result = ['ok' => true, 'error' => null];

switch ($action) {
    case 'set':
        $result = cart_set($variantId, post_int('qty', 1));
        break;

    case 'remove':
        cart_remove($variantId);
        $result = ['ok' => true, 'error' => null];
        break;

    case 'save':
        cart_save_for_later($variantId, true);
        $result = ['ok' => true, 'error' => 'Saved for later.'];
        break;

    case 'unsave':
        // Only move it back if it can actually be bought.
        $stock = (int) db_val('SELECT stock FROM product_variants WHERE id = ? AND is_active = 1', [$variantId]);
        if ($stock < 1) {
            $result = ['ok' => false, 'error' => 'That shade is out of stock.'];
        } else {
            cart_save_for_later($variantId, false);
            $result = ['ok' => true, 'error' => 'Moved to your cart.'];
        }
        break;

    case 'giftwrap':
        if (!empty($_POST['gift_wrap'])) {
            $_SESSION['gift_wrap'] = 1;
        } else {
            unset($_SESSION['gift_wrap']);
        }
        break;

    case 'clear':
        cart_clear();
        break;

    default:
        $result = ['ok' => false, 'error' => 'Unknown action.'];
}

if (is_ajax()) {
    $totals = pricing_totals();
    json_out([
        'ok'      => $result['ok'],
        'error'   => $result['error'] ?? null,
        'count'   => cart_count_fresh(cart_id()),
        'totals'  => [
            'subtotal'    => money($totals['subtotal']),
            'discount'    => money($totals['discount']),
            'shipping'    => $totals['shipping'] > 0 ? money($totals['shipping']) : 'FREE',
            'grand_total' => money($totals['grand_total']),
            'free_gap'    => $totals['free_gap'],
        ],
    ], $result['ok'] ? 200 : 422);
}

if (!empty($result['error'])) {
    flash($result['error'], $result['ok'] ? 'success' : 'error');
}

redirect('cart.php');
