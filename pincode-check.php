<?php
/**
 * Delivery estimate for a pincode.
 *
 * An unknown pincode is NOT treated as unserviceable — the starter table only
 * holds major cities. Unknown falls back to the default window so we never
 * turn away a real customer over missing reference data.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (!is_post()) {
    json_out(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

csrf_verify();

// bootstrap decodes a JSON body into $_POST, so this covers both encodings.
$pin = valid_pincode(post_str('pincode', 10));

if ($pin === null) {
    json_out(['ok' => false, 'error' => 'Enter a valid 6-digit pincode.'], 422);
}

// Cheap abuse guard — this endpoint is unauthenticated.
if (!rate_hit('pin:' . client_ip(), 40, 3600)) {
    json_out(['ok' => false, 'error' => 'Too many checks. Please try again later.'], 429);
}

$row = db_row(
    'SELECT city, state, is_serviceable, eta_days_min, eta_days_max FROM pincodes WHERE pincode = ? LIMIT 1',
    [$pin]
);

$nums = [];
 
if (preg_match_all('/\d+/', setting('dispatch_days', '1-2'), $m)) {
 
    $nums = array_map('intval', $m[0]);
 
}
 
$dispatchDays = $nums ? max(1, min(7, max($nums))) : 2;



if ($row && (int) $row['is_serviceable'] === 0) {
    json_out([
        'ok'          => true,
        'serviceable' => false,
        'message'     => 'We do not deliver to ' . $row['city'] . ' yet. Please try another pincode or contact us.',
    ]);
}

$min = $row ? (int) $row['eta_days_min'] : 4;
$max = $row ? (int) $row['eta_days_max'] : 8;

$minDays = min(20, max(1, $min + $dispatchDays));
 
$maxDays = min(20, max($minDays, $max + $dispatchDays));
 
 
 
$from = date('D, j M', strtotime('+' . $minDays . ' days'));
 
$to   = date('D, j M', strtotime('+' . $maxDays . ' days'));

// `city` / `state` are structured data the address form auto-fills from, so
// they are null when we genuinely do not know the pincode. Only `message` may
// contain the "your area" wording — writing that into a form field would put
// literal filler text into a delivery address.
$place = $row ? $row['city'] . ', ' . $row['state'] : 'your area';

json_out([
    'ok'          => true,
    'serviceable' => true,
    'known'       => (bool) $row,
    'city'        => $row ? $row['city'] : null,
    'state'       => $row ? $row['state'] : null,
    'message'     => 'Delivers to ' . $place . ' by ' . $from . ' – ' . $to . '.',
]);
