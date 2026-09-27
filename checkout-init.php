<?php
/**
 * Turn a cart into an order.
 *
 * Phase 3 creates the order in `pending_payment` and hands off to the
 * confirmation page. Phase 4 inserts Razorpay between the two: the order row,
 * its immutable item snapshot and the server-side totals are already exactly
 * what the payment step needs.
 *
 * Stock is NOT decremented here. It comes down only when payment succeeds,
 * with the guarded UPDATE in PLAN.md rule 7 — otherwise an abandoned checkout
 * would quietly consume inventory.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/pricing.php';
require_once YN_ROOT . '/includes/address.php';

if (!is_post()) {
    redirect('checkout.php');
}

csrf_verify();

$customer = current_customer();
if (!$customer) {
    flash('Please verify your email to place the order.', 'warn');
    redirect('checkout.php');
}

$address = $_SESSION['checkout_address'] ?? null;
if (!$address) {
    flash('Please add a delivery address.', 'warn');
    redirect('checkout.php');
}

// ---- recompute everything from the database ---------------------------
// Nothing about price, quantity or shipping is taken from the submitted form.
$lines  = cart_lines();
if (!$lines) {
    flash('Your cart is empty.', 'warn');
    redirect('cart.php');
}

$totals = pricing_totals(['lines' => $lines]);

if ($totals['has_issues']) {
    flash('Some items sold out while you were checking out. Please review your cart.', 'error');
    redirect('cart.php');
}
if ($totals['grand_total'] <= 0) {
    flash('We could not total that order. Please try again.', 'error');
    redirect('cart.php');
}

$note   = post_str('customer_note', 500);
$cartId = cart_id();

try {
    $orderId = db_tx(static function () use ($customer, $address, $lines, $totals, $note) {

        // Placeholder order_no keeps the UNIQUE index satisfied inside the
        // transaction; it is replaced with the real sequence below.
        $tempNo = 'TMP-' . bin2hex(random_bytes(6));

        $orderId = db_insert(
            'INSERT INTO orders
                (order_no, customer_id, email, phone, status, payment_status, payment_method,
                 subtotal, discount_total, shipping_total, tax_total, gift_wrap_total, grand_total,
                 currency, coupon_code,
                 ship_name, ship_phone, ship_line1, ship_line2, ship_landmark,
                 ship_city, ship_state, ship_pincode, ship_country,
                 bill_same, customer_note, ip, user_agent)
             VALUES (?,?,?,?, "pending_payment", "unpaid", "razorpay",
                     ?,?,?,?,?,?, "INR", ?,
                     ?,?,?,?,?,?,?,?,?,
                     1,?,?,?)',
            [
                $tempNo,
                (int) $customer['id'],
                $customer['email'],
                $address['phone'],
                $totals['subtotal'],
                $totals['discount'],
                $totals['shipping'],
                $totals['tax'],
                $totals['gift_wrap'],
                $totals['grand_total'],
                $totals['coupon_code'],
                $address['full_name'],
                $address['phone'],
                $address['line1'],
                $address['line2'] ?: null,
                $address['landmark'] ?: null,
                $address['city'],
                $address['state'],
                $address['pincode'],
                $address['country'] ?? 'India',
                $note !== '' ? $note : null,
                client_ip(),
                user_agent(),
            ]
        );

        // Human-readable, sequential, collision-free: YN-2608-0042
        $orderNo = sprintf('%s-%s-%04d', setting('order_prefix', 'YN'), date('ym'), $orderId);
        db_exec('UPDATE orders SET order_no = ? WHERE id = ?', [$orderNo, $orderId]);

        // Immutable snapshot — products get renamed, repriced and deleted, and
        // an old invoice must still show what was actually bought.
        foreach ($lines as $l) {
            db_exec(
                'INSERT INTO order_items
                    (order_id, product_id, variant_id, sku, product_name, product_slug,
                     shade_name, color_hex, image, unit_price, qty, line_total)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
                [
                    $orderId,
                    (int) $l['product_id'],
                    (int) $l['variant_id'],
                    $l['sku'],
                    $l['name'],
                    $l['slug'],
                    $l['shade_name'],
                    $l['color_hex'],
                    $l['variant_image'] ?: null,
                    (float) $l['unit_price'],
                    (int) $l['billable_qty'],
                    (float) $l['line_total'],
                ]
            );
        }

        db_exec(
            'INSERT INTO order_status_history (order_id, from_status, to_status, note, changed_by)
             VALUES (?, NULL, "pending_payment", "Order created at checkout", "customer")',
            [$orderId]
        );

        return $orderId;
    });
} catch (Throwable $e) {
    error_log('Order creation failed: ' . $e->getMessage());
    flash('We could not place that order. Nothing has been charged — please try again.', 'error');
    redirect('checkout.php');
}

// ---- post-commit side effects ----------------------------------------
// Outside the transaction: a bookkeeping failure must never roll back a
// successfully created order.
$order = db_row('SELECT * FROM orders WHERE id = ?', [$orderId]);

if ($totals['coupon']) {
    try {
        coupon_redeem((int) $totals['coupon']['id'], (int) $customer['id'], $orderId, $totals['discount']);
    } catch (Throwable $e) {
        error_log('Coupon redemption failed for order ' . $orderId . ': ' . $e->getMessage());
    }
}

if ($cartId > 0) {
    cart_mark_converted($cartId);
}
unset($_SESSION['checkout_address'], $_SESSION['checkout_address_id']);

// Let the customer find the order again without signing in.
$_SESSION['last_order_no'] = $order['order_no'];

// ---- hand off to the gateway -----------------------------------------
// With no keys configured the order still exists and the confirmation page
// explains that payment is not wired up yet, so the site stays demoable.
require_once YN_ROOT . '/includes/payments/razorpay.php';

if (rzp_configured()) {
    $rzp = rzp_create_order($order);

    if ($rzp['ok'] && $rzp['id'] !== '') {
        db_exec(
            'INSERT INTO payments (order_id, provider, provider_order_id, amount, status)
             VALUES (?, "razorpay", ?, ?, "created")',
            [$orderId, $rzp['id'], (float) $order['grand_total']]
        );
        redirect('pay.php?order=' . rawurlencode((string) $order['order_no']));
    }

    // Gateway unreachable: keep the order, tell the truth, offer a retry.
    error_log('Razorpay order creation failed for ' . $order['order_no'] . ': ' . ($rzp['error'] ?? '?'));
    flash('We could not reach the payment gateway. Your order is saved — please try paying again.', 'error');
    redirect('pay.php?order=' . rawurlencode((string) $order['order_no']));
}

redirect('order-success/' . rawurlencode((string) $order['order_no']));
