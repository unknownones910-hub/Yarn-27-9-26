<?php
/**
 * Wishlist heart (AJAX).
 *
 * Signed in  -> stored in `wishlists`, available on every device.
 * Signed out -> 401, and main.js keeps it in localStorage until they sign in,
 *               at which point `sync` migrates the lot.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (!is_post()) {
    json_out(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

csrf_verify();

$cid = customer_id();
if ($cid <= 0) {
    json_out(['ok' => false, 'error' => 'login_required'], 401);
}

$action = post_str('action', 10) ?: 'toggle';

// ---- migrate a guest wishlist after sign-in ---------------------------
if ($action === 'sync') {
    $ids = $_POST['variant_ids'] ?? [];
    if (is_string($ids)) {
        $ids = array_filter(explode(',', $ids));
    }
    $ids = array_slice(array_unique(array_map('intval', (array) $ids)), 0, 200);

    $merged = 0;
    foreach ($ids as $vid) {
        if ($vid <= 0) {
            continue;
        }
        // Only real, active shades.
        if (!db_val('SELECT id FROM product_variants WHERE id = ? AND is_active = 1', [$vid])) {
            continue;
        }
        $merged += db_exec(
            'INSERT IGNORE INTO wishlists (customer_id, variant_id) VALUES (?, ?)',
            [$cid, $vid]
        );
    }

    json_out([
        'ok'     => true,
        'merged' => $merged,
        'count'  => (int) db_val('SELECT COUNT(*) FROM wishlists WHERE customer_id = ?', [$cid]),
        'ids'    => array_map('intval', db_col('SELECT variant_id FROM wishlists WHERE customer_id = ?', [$cid])),
    ]);
}

// ---- toggle one shade -------------------------------------------------
$variantId = post_int('variant_id');
if ($variantId <= 0) {
    json_out(['ok' => false, 'error' => 'Please choose a shade first.'], 422);
}

if (!db_val('SELECT id FROM product_variants WHERE id = ? AND is_active = 1', [$variantId])) {
    json_out(['ok' => false, 'error' => 'That shade is no longer available.'], 422);
}

$exists = db_val('SELECT id FROM wishlists WHERE customer_id = ? AND variant_id = ? LIMIT 1',
                 [$cid, $variantId]);

if ($exists) {
    db_exec('DELETE FROM wishlists WHERE customer_id = ? AND variant_id = ?', [$cid, $variantId]);
    $on = false;
} else {
    db_exec('INSERT IGNORE INTO wishlists (customer_id, variant_id) VALUES (?, ?)', [$cid, $variantId]);
    $on = true;
}

json_out([
    'ok'    => true,
    'on'    => $on,
    'count' => (int) db_val('SELECT COUNT(*) FROM wishlists WHERE customer_id = ?', [$cid]),
]);
