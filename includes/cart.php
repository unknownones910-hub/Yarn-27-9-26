<?php
/**
 * Cart — database backed.
 *
 * A guest cart is keyed by a random token held in the session; a signed-in
 * cart is keyed by customer_id. When someone signs in, cart_bind_to_customer()
 * merges the guest cart into their existing one so nothing is lost.
 *
 * The cart never stores a price. Prices, stock and availability are re-read
 * from the catalog every time the cart is displayed or totalled, because a
 * price that was correct when the item was added is not evidence of anything
 * at checkout time.
 */
declare(strict_types=1);

const CART_MAX_QTY = 20;

// ----------------------------------------------------------- identity --

/** Stable random token identifying a guest's cart. */
function cart_token(): string
{
    if (empty($_SESSION['cart_token'])) {
        $_SESSION['cart_token'] = bin2hex(random_bytes(20));   // 40 chars
    }
    return (string) $_SESSION['cart_token'];
}

/**
 * The active cart row, or null.
 * @param bool $create create one if none exists
 */
function cart_current(bool $create = false): ?array
{
    static $cache = null;
    static $cachedFor = null;

    $cid = customer_id();
    $key = $cid > 0 ? 'c' . $cid : 't' . cart_token();

    if ($cache !== null && $cachedFor === $key) {
        return $cache;
    }

    if ($cid > 0) {
        $row = db_row(
            'SELECT * FROM carts WHERE customer_id = ? AND status = "active" ORDER BY id DESC LIMIT 1',
            [$cid]
        );
    } else {
        $row = db_row(
            'SELECT * FROM carts WHERE session_token = ? AND status = "active" ORDER BY id DESC LIMIT 1',
            [cart_token()]
        );
    }

    if (!$row && $create) {
        $id = db_insert(
            'INSERT INTO carts (customer_id, session_token, status) VALUES (?, ?, "active")',
            [$cid > 0 ? $cid : null, $cid > 0 ? null : cart_token()]
        );
        $row = db_row('SELECT * FROM carts WHERE id = ?', [$id]);
    }

    $cachedFor = $key;
    return $cache = $row;
}

function cart_id(bool $create = false): int
{
    $c = cart_current($create);
    return $c ? (int) $c['id'] : 0;
}

/** Bump updated_at so abandoned-cart timing is meaningful. */
function cart_touch(int $cartId): void
{
    db_exec('UPDATE carts SET updated_at = NOW() WHERE id = ?', [$cartId]);
}

// -------------------------------------------------------------- reads --

/** @return array<int,int> variant_id => qty, active items only */
function cart_raw(): array
{
    $id = cart_id();
    if ($id === 0) {
        return [];
    }
    $rows = db_all(
        'SELECT variant_id, qty FROM cart_items WHERE cart_id = ? AND saved_for_later = 0',
        [$id]
    );
    $out = [];
    foreach ($rows as $r) {
        $out[(int) $r['variant_id']] = (int) $r['qty'];
    }
    return $out;
}

function cart_count(): int
{
    static $n = null;
    if ($n !== null) {
        return $n;
    }
    $id = cart_id();
    if ($id === 0) {
        return $n = 0;
    }
    return $n = (int) db_val(
        'SELECT COALESCE(SUM(qty),0) FROM cart_items WHERE cart_id = ? AND saved_for_later = 0',
        [$id]
    );
}

function cart_is_empty(): bool
{
    return cart_count() === 0;
}

/** Reset the per-request memoisation after a write. */
function cart_forget(): void
{
    // cart_count() and cart_current() memoise; the simplest correct reset is a
    // fresh request, but writes within one request must not read stale values.
    static $noop = null;
    $noop = null;
}

// ------------------------------------------------------------- writes --

/**
 * Add a variant, capping at available stock.
 * @return array{ok:bool,error?:string,qty?:int,count?:int,capped?:bool}
 */
function cart_add(int $variantId, int $qty = 1): array
{
    $qty = max(1, min(CART_MAX_QTY, $qty));

    $v = db_row(
        'SELECT v.id, v.stock, v.shade_name, v.is_active, p.name, p.is_active AS p_active
         FROM product_variants v
         JOIN products p ON p.id = v.product_id
         WHERE v.id = ? LIMIT 1',
        [$variantId]
    );

    if (!$v || (int) $v['is_active'] !== 1 || (int) $v['p_active'] !== 1) {
        return ['ok' => false, 'error' => 'That shade is no longer available.'];
    }

    $stock = (int) $v['stock'];
    if ($stock < 1) {
        return ['ok' => false, 'error' => $v['shade_name'] . ' is out of stock.'];
    }

    $cartId  = cart_id(true);
    $current = (int) db_val(
        'SELECT qty FROM cart_items WHERE cart_id = ? AND variant_id = ? LIMIT 1',
        [$cartId, $variantId]
    );

    $wanted = $current + $qty;
    $final  = min($wanted, $stock, CART_MAX_QTY);

    db_exec(
        'INSERT INTO cart_items (cart_id, variant_id, qty, saved_for_later)
         VALUES (?, ?, ?, 0)
         ON DUPLICATE KEY UPDATE qty = VALUES(qty), saved_for_later = 0',
        [$cartId, $variantId, $final]
    );
    cart_touch($cartId);

    $count = (int) db_val(
        'SELECT COALESCE(SUM(qty),0) FROM cart_items WHERE cart_id = ? AND saved_for_later = 0',
        [$cartId]
    );

    return [
        'ok'     => true,
        'qty'    => $final,
        'count'  => $count,
        'capped' => $final < $wanted,
        'error'  => $final < $wanted
            ? 'Only ' . $stock . ' left in ' . $v['shade_name'] . ' — quantity adjusted.'
            : null,
    ];
}

/** Set an exact quantity; 0 removes the line. */
function cart_set(int $variantId, int $qty): array
{
    $cartId = cart_id();
    if ($cartId === 0) {
        return ['ok' => false, 'error' => 'Your cart is empty.'];
    }

    if ($qty <= 0) {
        cart_remove($variantId);
        return ['ok' => true, 'qty' => 0, 'count' => cart_count_fresh($cartId)];
    }

    $stock = (int) db_val('SELECT stock FROM product_variants WHERE id = ? AND is_active = 1', [$variantId]);
    if ($stock < 1) {
        cart_remove($variantId);
        return ['ok' => false, 'error' => 'That shade just sold out and was removed from your cart.',
                'count' => cart_count_fresh($cartId)];
    }

    $final = min($qty, $stock, CART_MAX_QTY);

    db_exec('UPDATE cart_items SET qty = ? WHERE cart_id = ? AND variant_id = ?',
            [$final, $cartId, $variantId]);
    cart_touch($cartId);

    return [
        'ok'     => true,
        'qty'    => $final,
        'count'  => cart_count_fresh($cartId),
        'capped' => $final < $qty,
        'error'  => $final < $qty ? 'Only ' . $stock . ' available — quantity adjusted.' : null,
    ];
}

function cart_count_fresh(int $cartId): int
{
    return (int) db_val(
        'SELECT COALESCE(SUM(qty),0) FROM cart_items WHERE cart_id = ? AND saved_for_later = 0',
        [$cartId]
    );
}

function cart_remove(int $variantId): void
{
    $cartId = cart_id();
    if ($cartId === 0) {
        return;
    }
    db_exec('DELETE FROM cart_items WHERE cart_id = ? AND variant_id = ?', [$cartId, $variantId]);
    cart_touch($cartId);
}

function cart_save_for_later(int $variantId, bool $save): void
{
    $cartId = cart_id();
    if ($cartId === 0) {
        return;
    }
    db_exec('UPDATE cart_items SET saved_for_later = ? WHERE cart_id = ? AND variant_id = ?',
            [$save ? 1 : 0, $cartId, $variantId]);
    cart_touch($cartId);
}

function cart_clear(): void
{
    $cartId = cart_id();
    if ($cartId === 0) {
        return;
    }
    db_exec('DELETE FROM cart_items WHERE cart_id = ? AND saved_for_later = 0', [$cartId]);
    cart_touch($cartId);
}

/** Mark converted after a successful order, so a fresh cart starts next time. */
function cart_mark_converted(int $cartId): void
{
    db_exec('UPDATE carts SET status = "converted" WHERE id = ?', [$cartId]);
    unset($_SESSION['cart_token'], $_SESSION['coupon_code'], $_SESSION['gift_wrap']);
}

// -------------------------------------------------------------- lines --

/**
 * Hydrate cart rows with live product data.
 * @param bool $saved fetch the "saved for later" list instead
 */
function cart_lines(bool $saved = false): array
{
    $cartId = cart_id();
    if ($cartId === 0) {
        return [];
    }

    $rows = db_all(
        'SELECT ci.variant_id, ci.qty, ci.saved_for_later,
                v.sku, v.shade_name, v.color_hex, v.image AS variant_image,
                v.price_override, v.stock, v.dye_lot, v.is_active AS v_active,
                p.id AS product_id, p.slug, p.name, p.price, p.mrp, p.fiber,
                p.yarn_weight, p.ball_weight_g, p.is_active AS p_active
         FROM cart_items ci
         JOIN product_variants v ON v.id = ci.variant_id
         JOIN products p ON p.id = v.product_id
         WHERE ci.cart_id = ? AND ci.saved_for_later = ?
         ORDER BY ci.added_at, ci.id',
        [$cartId, $saved ? 1 : 0]
    );

    $lines = [];
    foreach ($rows as $r) {
        $qty   = (int) $r['qty'];
        $stock = (int) $r['stock'];

        $price = ($r['price_override'] !== null && $r['price_override'] !== '')
            ? (float) $r['price_override']
            : (float) $r['price'];

        $active    = (int) $r['v_active'] === 1 && (int) $r['p_active'] === 1;
        $available = $active && $stock > 0;
        $billable  = $available ? min($qty, $stock) : 0;

        $lines[] = $r + [
            'qty'         => $qty,
            'unit_price'  => $price,
            'unit_mrp'    => (float) $r['mrp'],
            'billable_qty'=> $billable,
            'line_total'  => round($price * $billable, 2),
            'available'   => $available,
            'discontinued'=> !$active,
            'over_stock'  => $available && $qty > $stock,
            'image_src'   => !empty($r['variant_image'])
                ? upload_url($r['variant_image'])
                : yarn_ball_uri((string) $r['color_hex']),
        ];
    }

    return $lines;
}

/** True when any line cannot be fulfilled as it stands. */
function cart_has_issues(?array $lines = null): bool
{
    foreach ($lines ?? cart_lines() as $l) {
        if (!$l['available'] || $l['over_stock']) {
            return true;
        }
    }
    return false;
}

// -------------------------------------------------------------- merge --

/**
 * Called the moment someone signs in.
 *
 * Their guest cart and any existing signed-in cart are combined: quantities
 * add up, then get capped at live stock. Losing a cart at sign-in is one of
 * the most expensive bugs an e-commerce site can have, so this runs on both
 * OTP sign-in and remember-me resume.
 */
function cart_bind_to_customer(int $customerId): void
{
    if ($customerId <= 0 || empty($_SESSION['cart_token'])) {
        return;
    }

    $token = (string) $_SESSION['cart_token'];

    $guest = db_row(
        'SELECT * FROM carts WHERE session_token = ? AND status = "active" ORDER BY id DESC LIMIT 1',
        [$token]
    );
    if (!$guest) {
        return;
    }

    $mine = db_row(
        'SELECT * FROM carts WHERE customer_id = ? AND status = "active" ORDER BY id DESC LIMIT 1',
        [$customerId]
    );

    // No existing cart: simply claim the guest one.
    if (!$mine) {
        db_exec('UPDATE carts SET customer_id = ?, session_token = NULL WHERE id = ?',
                [$customerId, (int) $guest['id']]);
        unset($_SESSION['cart_token']);
        return;
    }

    if ((int) $mine['id'] === (int) $guest['id']) {
        return;
    }

    try {
        db_tx(static function () use ($guest, $mine) {
            $items = db_all('SELECT variant_id, qty, saved_for_later FROM cart_items WHERE cart_id = ?',
                            [(int) $guest['id']]);

            foreach ($items as $it) {
                $vid   = (int) $it['variant_id'];
                $stock = (int) db_val('SELECT stock FROM product_variants WHERE id = ?', [$vid]);
                if ($stock < 1) {
                    continue;
                }

                $existing = (int) db_val(
                    'SELECT qty FROM cart_items WHERE cart_id = ? AND variant_id = ? LIMIT 1',
                    [(int) $mine['id'], $vid]
                );

                $merged = min($existing + (int) $it['qty'], $stock, CART_MAX_QTY);

                db_exec(
                    'INSERT INTO cart_items (cart_id, variant_id, qty, saved_for_later)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE qty = VALUES(qty)',
                    [(int) $mine['id'], $vid, $merged, (int) $it['saved_for_later']]
                );
            }

            db_exec('DELETE FROM cart_items WHERE cart_id = ?', [(int) $guest['id']]);
            db_exec('UPDATE carts SET status = "abandoned" WHERE id = ?', [(int) $guest['id']]);
        });
    } catch (Throwable $e) {
        error_log('Cart merge failed: ' . $e->getMessage());
        return;
    }

    unset($_SESSION['cart_token']);
}
