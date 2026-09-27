<?php
/**
 * Coupons.
 *
 * Validation is always done server-side against the live cart subtotal. A
 * coupon the browser claims to have applied means nothing.
 */
declare(strict_types=1);

function coupon_find(string $code): ?array
{
    $code = strtoupper(trim($code));
    if ($code === '') {
        return null;
    }
    return db_row('SELECT * FROM coupons WHERE code = ? LIMIT 1', [$code]);
}

/**
 * Can this coupon be used, by this customer, on this subtotal?
 *
 * @return array{ok:bool, coupon?:array, discount?:float, free_shipping?:bool, error?:string}
 */
function coupon_validate(string $code, float $subtotal, ?int $customerId = null): array
{
    $c = coupon_find($code);

    // Deliberately identical message for "no such code" and "disabled", so the
    // form cannot be used to enumerate which codes exist.
    if (!$c || (int) $c['is_active'] !== 1) {
        return ['ok' => false, 'error' => 'That code is not valid.'];
    }

    $now = time();

    if (!empty($c['starts_at']) && strtotime((string) $c['starts_at']) > $now) {
        return ['ok' => false, 'error' => 'That code is not active yet.'];
    }
    if (!empty($c['ends_at']) && strtotime((string) $c['ends_at']) < $now) {
        return ['ok' => false, 'error' => 'That code has expired.'];
    }

    if ($c['usage_limit'] !== null && (int) $c['used_count'] >= (int) $c['usage_limit']) {
        return ['ok' => false, 'error' => 'That code has been fully redeemed.'];
    }

    $minOrder = (float) $c['min_order'];
    if ($minOrder > 0 && $subtotal < $minOrder) {
        $short = $minOrder - $subtotal;
        return ['ok' => false,
                'error' => 'Add ' . money($short) . ' more to use this code (minimum '
                           . money($minOrder) . ').'];
    }

    if ($customerId !== null && $customerId > 0) {
        $perLimit = (int) $c['per_customer_limit'];
        if ($perLimit > 0) {
            $used = (int) db_val(
                'SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id = ? AND customer_id = ?',
                [(int) $c['id'], $customerId]
            );
            if ($used >= $perLimit) {
                return ['ok' => false, 'error' => 'You have already used this code.'];
            }
        }

        if ((int) $c['first_order_only'] === 1) {
            $orders = (int) db_val(
                'SELECT COUNT(*) FROM orders WHERE customer_id = ? AND payment_status = "paid"',
                [$customerId]
            );
            if ($orders > 0) {
                return ['ok' => false, 'error' => 'That code is for first orders only.'];
            }
        }
    }

    return [
        'ok'            => true,
        'coupon'        => $c,
        'discount'      => coupon_discount($c, $subtotal),
        'free_shipping' => $c['type'] === 'free_shipping',
    ];
}

/** Money off the subtotal. Free-shipping coupons discount nothing here. */
function coupon_discount(array $coupon, float $subtotal): float
{
    $value = (float) $coupon['value'];

    $discount = match ($coupon['type']) {
        'percent' => $subtotal * ($value / 100),
        'fixed'   => $value,
        default   => 0.0,          // free_shipping is handled in the shipping line
    };

    if ($coupon['max_discount'] !== null && (float) $coupon['max_discount'] > 0) {
        $discount = min($discount, (float) $coupon['max_discount']);
    }

    // Never discount more than the cart is worth.
    return round(min($discount, $subtotal), 2);
}

/** Short human description shown next to the applied code. */
function coupon_label(array $coupon): string
{
    return match ($coupon['type']) {
        'percent'       => rtrim(rtrim(number_format((float) $coupon['value'], 2, '.', ''), '0'), '.') . '% off',
        'fixed'         => money((float) $coupon['value']) . ' off',
        'free_shipping' => 'Free shipping',
        default         => 'Discount',
    };
}

/** Record redemption when an order is placed. */
function coupon_redeem(int $couponId, ?int $customerId, int $orderId, float $amount): void
{
    db_exec(
        'INSERT INTO coupon_redemptions (coupon_id, customer_id, order_id, amount) VALUES (?,?,?,?)',
        [$couponId, $customerId ?: null, $orderId, $amount]
    );
    db_exec('UPDATE coupons SET used_count = used_count + 1 WHERE id = ?', [$couponId]);
}
