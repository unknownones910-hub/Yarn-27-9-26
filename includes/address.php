<?php
/**
 * Delivery addresses.
 *
 * Shared by checkout and (from Phase 5) the account address book, so the
 * validation rules can never diverge between the two.
 */
declare(strict_types=1);

function address_list(int $customerId): array
{
    if ($customerId <= 0) {
        return [];
    }
    return db_all(
        'SELECT * FROM customer_addresses WHERE customer_id = ? ORDER BY is_default DESC, id DESC',
        [$customerId]
    );
}

function address_get(int $id, int $customerId): ?array
{
    if ($id <= 0 || $customerId <= 0) {
        return null;
    }
    return db_row('SELECT * FROM customer_addresses WHERE id = ? AND customer_id = ? LIMIT 1',
                  [$id, $customerId]);
}

/**
 * Validate a posted address.
 * @return array{ok:bool, data:array, errors:array<string,string>}
 */
function address_validate(array $src): array
{
    $get = static fn(string $k, int $max = 180): string => mb_substr(trim((string) ($src[$k] ?? '')), 0, $max);

    $data = [
        'label'     => in_array($src['label'] ?? 'home', ['home', 'work', 'other'], true) ? $src['label'] : 'home',
        'full_name' => $get('full_name', 120),
        'phone'     => $get('phone', 15),
        'line1'     => $get('line1'),
        'line2'     => $get('line2'),
        'landmark'  => $get('landmark', 120),
        'city'      => $get('city', 80),
        'state'     => $get('state', 60),
        'pincode'   => $get('pincode', 6),
        'country'   => 'India',
    ];

    $errors = [];

    if (mb_strlen($data['full_name']) < 2) {
        $errors['full_name'] = 'Please enter the recipient’s full name.';
    }

    $phone = normalize_phone($data['phone']);
    if ($phone === null) {
        $errors['phone'] = 'Enter a valid 10-digit Indian mobile number.';
    } else {
        $data['phone'] = $phone;
    }

    if (mb_strlen($data['line1']) < 5) {
        $errors['line1'] = 'Please enter the house or flat number and street.';
    }
    if (mb_strlen($data['city']) < 2) {
        $errors['city'] = 'Please enter your city.';
    }
    if (!in_array($data['state'], india_states(), true)) {
        $errors['state'] = 'Please choose your state.';
    }

    $pin = valid_pincode($data['pincode']);
    if ($pin === null) {
        $errors['pincode'] = 'Enter a valid 6-digit pincode.';
    } else {
        $data['pincode'] = $pin;

        // A known-unserviceable pincode is a hard stop — better to say so now
        // than to take the money and cancel later.
        $svc = db_row('SELECT is_serviceable, city, state FROM pincodes WHERE pincode = ? LIMIT 1', [$pin]);
        if ($svc && (int) $svc['is_serviceable'] === 0) {
            $errors['pincode'] = 'We cannot deliver to this pincode yet. Please try another, or contact us.';
        }
    }

    return ['ok' => !$errors, 'data' => $data, 'errors' => $errors];
}

/** Insert or update. Returns the address id. */
function address_save(int $customerId, array $d, ?int $id = null, bool $makeDefault = false): int
{
    if ($id !== null && address_get($id, $customerId)) {
        db_exec(
            'UPDATE customer_addresses
             SET label=?, full_name=?, phone=?, line1=?, line2=?, landmark=?,
                 city=?, state=?, pincode=?, country=?
             WHERE id = ? AND customer_id = ?',
            [$d['label'], $d['full_name'], $d['phone'], $d['line1'], $d['line2'], $d['landmark'],
             $d['city'], $d['state'], $d['pincode'], $d['country'], $id, $customerId]
        );
        $newId = $id;
    } else {
        $newId = db_insert(
            'INSERT INTO customer_addresses
                (customer_id, label, full_name, phone, line1, line2, landmark, city, state, pincode, country)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)',
            [$customerId, $d['label'], $d['full_name'], $d['phone'], $d['line1'], $d['line2'],
             $d['landmark'], $d['city'], $d['state'], $d['pincode'], $d['country']]
        );
    }

    // First address is automatically the default.
    $count = (int) db_val('SELECT COUNT(*) FROM customer_addresses WHERE customer_id = ?', [$customerId]);
    if ($makeDefault || $count === 1) {
        db_exec('UPDATE customer_addresses SET is_default = 0 WHERE customer_id = ?', [$customerId]);
        db_exec('UPDATE customer_addresses SET is_default = 1 WHERE id = ? AND customer_id = ?',
                [$newId, $customerId]);
    }

    return $newId;
}

function address_delete(int $id, int $customerId): void
{
    db_exec('DELETE FROM customer_addresses WHERE id = ? AND customer_id = ?', [$id, $customerId]);
}

/** Single-line rendering for summaries. */
function address_one_line(array $a): string
{
    $bits = array_filter([
        $a['line1'] ?? '', $a['line2'] ?? '', $a['landmark'] ?? '',
        $a['city'] ?? '', ($a['state'] ?? '') . ' ' . ($a['pincode'] ?? ''),
    ]);
    return implode(', ', array_map('trim', $bits));
}

/** City + state for a pincode, used to auto-fill the address form. */
function pincode_lookup(string $pin): ?array
{
    $p = valid_pincode($pin);
    if ($p === null) {
        return null;
    }
    return db_row('SELECT city, state, is_serviceable, eta_days_min, eta_days_max
                   FROM pincodes WHERE pincode = ? LIMIT 1', [$p]);
}
