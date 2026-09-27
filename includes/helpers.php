<?php
/**
 * Shared helpers. Loaded by bootstrap.php on every request.
 */
declare(strict_types=1);

// ---------------------------------------------------------------- escaping --

/** Escape for HTML output. EVERY dynamic value in a template goes through this. */
function e(?string $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * The Content-Security-Policy nonce for THIS request.
 *
 * Every inline <script> must carry it, or the browser refuses to run it. That
 * is the point: with a nonce in script-src the browser ignores 'unsafe-inline'
 * entirely, so injected markup cannot execute even if it reaches the page.
 *
 * Generated once and reused — a second nonce would not match the header.
 */
function csp_nonce(): string
{
    static $nonce = null;
    if ($nonce === null) {
        $nonce = base64_encode(random_bytes(16));
    }
    return $nonce;
}

/** Escape for use inside a JS string / JSON blob. */
function ejs($v): string
{
    return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}

/** Escape for an HTML attribute that holds a URL. */
function eurl(string $url): string
{
    // Block javascript:, data:, vbscript: and friends.
    $u = trim($url);
    if (preg_match('~^\s*(javascript|data|vbscript|file)\s*:~i', $u)) {
        return '#';
    }
    return e($u);
}

// ------------------------------------------------------------ environment --

/** True on a developer machine. Gates on-screen OTPs and verbose errors. */
function is_local_dev(): bool
{
    static $local = null;
    if ($local !== null) {
        return $local;
    }

    if (PHP_SAPI === 'cli') {
        return $local = true;
    }

    $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $host = explode(':', $host)[0];

    $local = in_array($host, ['localhost', '127.0.0.1', '::1', '[::1]'], true)
        || str_ends_with($host, '.local')
        || str_ends_with($host, '.test')
        || str_starts_with($host, '192.168.')
        || str_starts_with($host, '10.');

    return $local;
}

/** Client IP, honouring a proxy header only when we are behind one. */
function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    // On shared hosting behind Cloudflare/cPanel proxies:
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'];
    }
    return substr((string) $ip, 0, 45);
}

function user_agent(): string
{
    return substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function is_ajax(): bool
{
    return strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
}

// ------------------------------------------------------------------- URLs --

/** Absolute-from-root URL for an app path. */
function url(string $path = ''): string
{
    return '/' . ltrim($path, '/');
}

/** Fully qualified URL (emails, canonical tags, OG tags). */
function abs_url(string $path = ''): string
{
    // Behind a proxy that terminates SSL, $_SERVER['HTTPS'] is empty even though
    // the request reached the user over https. .htaccess already trusts
    // X-Forwarded-Proto when deciding not to redirect, so this must agree with
    // it — otherwise every canonical and every sitemap URL says http://, Google
    // follows it, hits the 301 to https, and files the page under "redirect".
    $fwd    = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
           || strtolower($fwd) === 'https';
    $scheme = $secure ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host . url($path);
}

/** Cache-busted asset URL. Bump $SITE['asset_v'] when CSS/JS changes. */
function asset(string $path): string
{
    global $SITE;
    return url('assets/' . ltrim($path, '/')) . '?v=' . (int) ($SITE['asset_v'] ?? 1);
}

/** URL for an uploaded file, with a placeholder fallback. */
function upload_url(?string $path, string $fallback = 'img/placeholder-yarn.svg'): string
{
    global $SITE;
    if ($path === null || $path === '') {
        return asset($fallback);
    }
    if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
        return $path;
    }
    $url = $SITE['upload_url'] . '/' . ltrim($path, '/');
    if (str_contains($url, '?')) {
        return $url;
    }
    return $url . '?v=' . (int) ($SITE['asset_v'] ?? 1);
}

function redirect(string $path, int $code = 302): void
{
    header('Location: ' . (str_starts_with($path, 'http') ? $path : url($path)), true, $code);
    exit;
}

/** Current path + query with some params replaced. Used by filters and sorting. */
function url_with(array $changes, ?string $base = null): string
{
    $base  = $base ?? strtok((string) ($_SERVER['REQUEST_URI'] ?? '/'), '?');
    $query = $_GET;
    foreach ($changes as $k => $v) {
        if ($v === null || $v === '') {
            unset($query[$k]);
        } else {
            $query[$k] = $v;
        }
    }
    unset($query['page']);   // any filter change resets pagination
    $qs = http_build_query($query);
    return $base . ($qs !== '' ? '?' . $qs : '');
}

// --------------------------------------------------------------- settings --

/**
 * Read a setting. DB value wins; falls back to $SETTINGS_DEFAULTS so a brand
 * new setting never breaks a page before it has been saved once.
 */
function setting(string $key, ?string $default = null): string
{
    global $CFG, $SETTINGS_DEFAULTS;
    if (isset($CFG[$key])) {
        return (string) $CFG[$key];
    }
    if ($default !== null) {
        return $default;
    }
    return (string) ($SETTINGS_DEFAULTS[$key] ?? '');
}

function setting_int(string $key, int $default = 0): int
{
    $v = setting($key, (string) $default);
    return $v === '' ? $default : (int) $v;
}

function setting_bool(string $key, bool $default = false): bool
{
    $v = setting($key, $default ? '1' : '0');
    return $v === '1' || $v === 'true';
}

function setting_money(string $key, float $default = 0.0): float
{
    $v = setting($key, (string) $default);
    return $v === '' ? $default : (float) $v;
}

/** Persist a setting. */
function setting_set(string $key, string $value): void
{
    global $CFG;
    db_exec(
        'INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)',
        [$key, $value]
    );
    $CFG[$key] = $value;
}

// ------------------------------------------------------------- formatting --

/** ₹1,299 — Indian digit grouping, no decimals when whole. */
function money(float $amount, bool $withSymbol = true): string
{
    $neg    = $amount < 0;
    $amount = abs($amount);

    $whole = (int) floor($amount);
    $paise = (int) round(($amount - $whole) * 100);

    // Indian grouping: last 3 digits, then pairs.
    $s = (string) $whole;
    if (strlen($s) > 3) {
        $last3 = substr($s, -3);
        $rest  = substr($s, 0, -3);
        $rest  = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
        $s     = $rest . ',' . $last3;
    }
    if ($paise > 0) {
        $s .= '.' . str_pad((string) $paise, 2, '0', STR_PAD_LEFT);
    }

    return ($neg ? '-' : '') . ($withSymbol ? '₹' : '') . $s;
}

/** Rupees -> integer paise, for payment gateway calls. Never use floats there. */
function to_paise(float $rupees): int
{
    return (int) round($rupees * 100);
}

function discount_pct(float $mrp, float $price): int
{
    if ($mrp <= 0 || $price >= $mrp) {
        return 0;
    }
    return (int) round((($mrp - $price) / $mrp) * 100);
}

function fmt_date(?string $sqlDate, string $format = 'j M Y'): string
{
    if (!$sqlDate) {
        return '';
    }
    $ts = strtotime($sqlDate);
    return $ts ? date($format, $ts) : '';
}

function fmt_datetime(?string $sqlDate): string
{
    return fmt_date($sqlDate, 'j M Y, g:i A');
}

/** "2 days ago" */
function time_ago(?string $sqlDate): string
{
    if (!$sqlDate) {
        return '';
    }
    $diff = time() - (int) strtotime($sqlDate);
    if ($diff < 60)     return 'just now';
    if ($diff < 3600)   return floor($diff / 60) . ' min ago';
    if ($diff < 86400)  return floor($diff / 3600) . ' hr ago';
    if ($diff < 172800) return 'yesterday';
    if ($diff < 2592000) return floor($diff / 86400) . ' days ago';
    return fmt_date($sqlDate);
}

/** Mask an email for display: ma••••@gmail.com */
function mask_email(string $email): string
{
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    $keep = min(2, max(1, strlen($local) - 1));
    return substr($local, 0, $keep) . str_repeat('•', 4) . '@' . $domain;
}

function slugify(string $text): string
{
    $text = preg_replace('~[^\pL\d]+~u', '-', $text) ?? '';
    $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
    $text = strtolower(trim(preg_replace('~[^-\w]+~', '', $text) ?? '', '-'));
    $text = preg_replace('~-+~', '-', $text) ?? '';
    return $text !== '' ? $text : 'item';
}

/** Ensure a slug is unique within a table. */
function unique_slug(string $base, string $table, ?int $ignoreId = null): string
{
    $slug = slugify($base);
    $i    = 1;
    while (true) {
        $sql    = "SELECT id FROM `{$table}` WHERE slug = ?" . ($ignoreId ? ' AND id <> ?' : '') . ' LIMIT 1';
        $params = $ignoreId ? [$slug, $ignoreId] : [$slug];
        if (!db_row($sql, $params)) {
            return $slug;
        }
        $slug = slugify($base) . '-' . (++$i);
    }
}

function str_excerpt(?string $text, int $len = 140): string
{
    $t = trim(strip_tags((string) $text));
    if (mb_strlen($t) <= $len) {
        return $t;
    }
    return rtrim(mb_substr($t, 0, $len), " \t\n\r\0\x0B.,;:") . '…';
}

// ------------------------------------------------------------- validation --

/**
 * Gmail-only normalization.
 *
 * Returns the canonical form used as the account key, or null if the address
 * is not a usable Gmail address. Gmail ignores dots and everything after a
 * "+", so m.anoj+shop@gmail.com and manoj@gmail.com are the SAME inbox — they
 * must resolve to one account, or the customer ends up with two carts.
 */
function normalize_gmail(string $raw): ?string
{
    $email = strtolower(trim($raw));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return null;
    }

    $parts = explode('@', $email);
    if (count($parts) !== 2) {
        return null;
    }
    [$local, $domain] = $parts;

    if ($domain === 'googlemail.com') {
        $domain = 'gmail.com';
    }
    if ($domain !== 'gmail.com') {
        return null;
    }

    $local = explode('+', $local, 2)[0];
    $local = str_replace('.', '', $local);

    // Real Gmail usernames are 6-30 chars, letters/digits/dots only.
    if (!preg_match('/^[a-z0-9]{6,30}$/', $local)) {
        return null;
    }

    return $local . '@' . $domain;
}

/** 10-digit Indian mobile, normalized to bare digits. */
function normalize_phone(string $raw): ?string
{
    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
        $digits = substr($digits, 2);
    }
    if (strlen($digits) === 11 && str_starts_with($digits, '0')) {
        $digits = substr($digits, 1);
    }
    return preg_match('/^[6-9]\d{9}$/', $digits) ? $digits : null;
}

function valid_pincode(string $raw): ?string
{
    $p = preg_replace('/\D+/', '', $raw) ?? '';
    return preg_match('/^[1-9]\d{5}$/', $p) ? $p : null;
}

/** Trimmed POST string with a length cap. */
function post_str(string $key, int $max = 255): string
{
    $v = $_POST[$key] ?? '';
    if (!is_string($v)) {
        return '';
    }
    return mb_substr(trim($v), 0, $max);
}

function post_int(string $key, int $default = 0): int
{
    return isset($_POST[$key]) && is_numeric($_POST[$key]) ? (int) $_POST[$key] : $default;
}

function get_str(string $key, int $max = 255): string
{
    $v = $_GET[$key] ?? '';
    if (!is_string($v)) {
        return '';
    }
    return mb_substr(trim($v), 0, $max);
}

function get_int(string $key, int $default = 0): int
{
    return isset($_GET[$key]) && is_numeric($_GET[$key]) ? (int) $_GET[$key] : $default;
}

// ----------------------------------------------------------------- flash --

/** Queue a one-shot message for the next page render. */
function flash(string $message, string $type = 'success'): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

/** Drain queued messages. Called once by the footer. */
function flash_take(): array
{
    $items = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $items;
}

// ------------------------------------------------------------------ misc --

function random_token(int $bytes = 20): string
{
    return bin2hex(random_bytes($bytes));
}

/**
 * Encode a value for a JSON database column.
 *
 * json_encode() returns FALSE on malformed UTF-8, and our binder turns a bool
 * into an int — so a failed encode silently stored the string "0" over the
 * data. Substituting bad bytes means this can only ever return a string.
 */
function json_col($value): string
{
    $json = json_encode(
        $value,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        error_log('json_col: encode failed — ' . json_last_error_msg());
        return '{}';
    }
    return $json;
}

/** Send a JSON response and stop. */
function json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * A yarn-ball SVG rendered in an actual shade colour, as a data URI.
 *
 * Real photography replaces this the moment images are uploaded, but until
 * then the shop shows each shade in its true colour rather than a wall of
 * identical grey placeholders. Costs one inline string and zero requests.
 *
 * Lives here rather than in catalog.php because the cart renders shade
 * thumbnails too, and cart.php is loaded on every request.
 */
function yarn_ball_uri(string $hex): string
{
    $hex = preg_match('/^#[0-9A-Fa-f]{6}$/', $hex) ? strtoupper($hex) : '#CCCCCC';

    $r = (int) hexdec(substr($hex, 1, 2));
    $g = (int) hexdec(substr($hex, 3, 2));
    $b = (int) hexdec(substr($hex, 5, 2));

    // Darker wrap lines so even pale shades read; a near-white ground so the
    // ball sits on something rather than floating.
    $line = sprintf('#%02X%02X%02X', (int) ($r * 0.72), (int) ($g * 0.72), (int) ($b * 0.72));
    $bg   = sprintf(
        '#%02X%02X%02X',
        (int) min(255, $r + (255 - $r) * 0.86),
        (int) min(255, $g + (255 - $g) * 0.86),
        (int) min(255, $b + (255 - $b) * 0.86)
    );

    $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 500">'
         . '<rect width="400" height="500" fill="' . $bg . '"/>'
         . '<g transform="translate(200 250)">'
         . '<circle r="118" fill="' . $hex . '"/>'
         . '<g fill="none" stroke="' . $line . '" stroke-width="6" stroke-linecap="round" opacity="0.85">'
         . '<path d="M-84 -80c36 25 64 65 77 108M-41 -110c42 32 76 83 91 142'
         . 'M38 -112c27 28 49 70 60 113M-110 -23c23 19 44 45 56 75"/>'
         . '</g>'
         . '<path d="M110 -46c48 -21 88 -2 103 36" fill="none" stroke="' . $line
         . '" stroke-width="6" stroke-linecap="round"/>'
         . '</g></svg>';

    return 'data:image/svg+xml;charset=utf-8,' . rawurlencode($svg);
}

/** Indian states, for address dropdowns. */
function india_states(): array
{
    return [
        'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chhattisgarh', 'Goa',
        'Gujarat', 'Haryana', 'Himachal Pradesh', 'Jharkhand', 'Karnataka', 'Kerala',
        'Madhya Pradesh', 'Maharashtra', 'Manipur', 'Meghalaya', 'Mizoram', 'Nagaland',
        'Odisha', 'Punjab', 'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana', 'Tripura',
        'Uttar Pradesh', 'Uttarakhand', 'West Bengal',
        'Andaman and Nicobar Islands', 'Chandigarh',
        'Dadra and Nagar Haveli and Daman and Diu', 'Delhi', 'Jammu and Kashmir',
        'Ladakh', 'Lakshadweep', 'Puducherry',
    ];
}

/** Human labels for the CYC yarn weight scale. */
function yarn_weights(): array
{
    return [
        'lace'        => ['label' => 'Lace',        'cyc' => '0', 'ply' => '1–2 ply'],
        'super_fine'  => ['label' => 'Super Fine',  'cyc' => '1', 'ply' => '3 ply / Sock'],
        'fine'        => ['label' => 'Fine',        'cyc' => '2', 'ply' => '4 ply / Sport'],
        'light'       => ['label' => 'Light / DK',  'cyc' => '3', 'ply' => '8 ply'],
        'medium'      => ['label' => 'Medium',      'cyc' => '4', 'ply' => '10 ply / Worsted'],
        'bulky'       => ['label' => 'Bulky',       'cyc' => '5', 'ply' => '12 ply / Chunky'],
        'super_bulky' => ['label' => 'Super Bulky', 'cyc' => '6', 'ply' => '14 ply'],
        'jumbo'       => ['label' => 'Jumbo',       'cyc' => '7', 'ply' => '16+ ply'],
    ];
}

function yarn_weight_label(?string $key): string
{
    $all = yarn_weights();
    return $all[$key]['label'] ?? '';
}

/** Colour families used by the shade filter, with a representative swatch. */
function color_families(): array
{
    return [
        'red'        => ['label' => 'Red',        'hex' => '#C0392B'],
        'pink'       => ['label' => 'Pink',       'hex' => '#E48AA6'],
        'orange'     => ['label' => 'Orange',     'hex' => '#D97A33'],
        'peach'      => ['label' => 'Peach',      'hex' => '#E8A882'],
        'coral'      => ['label' => 'Coral',      'hex' => '#E07A6A'],
        'yellow'     => ['label' => 'Yellow',     'hex' => '#E3B23C'],
        'gold'       => ['label' => 'Gold',       'hex' => '#C9A227'],
        'green'      => ['label' => 'Green',      'hex' => '#6B8E5A'],
        'mint'       => ['label' => 'Mint',       'hex' => '#8FBFB0'],
        'olive'      => ['label' => 'Olive',      'hex' => '#6B7A3A'],
        'teal'       => ['label' => 'Teal',       'hex' => '#2A7A6E'],
        'turquoise'  => ['label' => 'Turquoise',  'hex' => '#3AA8A4'],
        'blue'       => ['label' => 'Blue',       'hex' => '#4A7392'],
        'navy'       => ['label' => 'Navy',       'hex' => '#2C3E6B'],
        'purple'     => ['label' => 'Purple',     'hex' => '#7D6396'],
        'lavender'   => ['label' => 'Lavender',   'hex' => '#B39BC8'],
        'maroon'     => ['label' => 'Maroon',     'hex' => '#7A2E3A'],
        'brown'      => ['label' => 'Brown',      'hex' => '#8A6244'],
        'beige'      => ['label' => 'Beige',      'hex' => '#D4C4A8'],
        'cream'      => ['label' => 'Cream',      'hex' => '#EEE2CE'],
        'white'      => ['label' => 'White',      'hex' => '#FAFAF8'],
        'grey'       => ['label' => 'Grey',       'hex' => '#8C8C8C'],
        'silver'     => ['label' => 'Silver',     'hex' => '#A8B0B8'],
        'black'      => ['label' => 'Black',      'hex' => '#2E2A26'],
        'rust'       => ['label' => 'Rust',       'hex' => '#B85A32'],
        'multi'      => ['label' => 'Multi',      'hex' => '#B4552F'],
    ];
}

/**
 * Widen color_family so Gold / Silver / Beige etc. can be stored.
 * Safe to call on every save: no-ops once the column is a VARCHAR.
 */
function color_family_ensure_column(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    try {
        $col = db_row("SHOW COLUMNS FROM product_variants LIKE 'color_family'");
        $type = strtolower((string) ($col['Type'] ?? ''));
        if ($type === '' || str_starts_with($type, 'varchar')) {
            return;
        }
        db_exec("ALTER TABLE product_variants
                 MODIFY color_family VARCHAR(20) NOT NULL DEFAULT 'multi'");
    } catch (Throwable $e) {
        error_log('color_family_ensure_column: ' . $e->getMessage());
    }
}

/** Order status -> [label, css modifier]. Single source of truth for pills. */
function order_status_meta(string $status): array
{
    return match ($status) {
        'pending_payment'  => ['Payment pending', 'warn'],
        'paid'             => ['Payment received', 'ok'],
        'processing'       => ['Processing', 'info'],
        'packed'           => ['Packed', 'info'],
        'shipped'          => ['Shipped', 'info'],
        'out_for_delivery' => ['Out for delivery', 'info'],
        'delivered'        => ['Delivered', 'ok'],
        'cancelled'        => ['Cancelled', 'muted'],
        'refunded'         => ['Refunded', 'muted'],
        'returned'         => ['Returned', 'muted'],
        'failed'           => ['Payment failed', 'bad'],
        default            => [ucfirst(str_replace('_', ' ', $status)), 'muted'],
    };
}

/* ---------------------------------------------------------------------
 *  Form field errors
 *
 *  A red message under an input is invisible to a screen reader unless the
 *  input points at it. These two helpers keep the markup and the association
 *  in one place so a form can never grow an error message that only sighted
 *  users can read.
 *
 *      <input class="input" id="phone" name="phone"<?= field_invalid($errors, 'phone') ?>>
 *      <?= field_error($errors, 'phone') ?>
 * ------------------------------------------------------------------- */

/** Attributes for the input itself. Empty string when the field is fine. */
function field_invalid(array $errors, string $key): string
{
    return isset($errors[$key])
        ? ' aria-invalid="true" aria-describedby="err-' . e($key) . '"'
        : '';
}

/** The message, carrying the id the input points at. */
function field_error(array $errors, string $key): string
{
    if (!isset($errors[$key])) {
        return '';
    }

    return '<span class="error-text" id="err-' . e($key) . '">'
         . icon('alert', 13) . ' ' . e((string) $errors[$key]) . '</span>';
}

/* ---------------------------------------------------------------------
 *  Admin help tips
 *
 *  A "?" the admin can hover or tab to. Every field whose meaning is not
 *  obvious from its label should carry one — placeholders vanish the moment
 *  something is typed, which is exactly when you most want to know what a
 *  box was for.
 *
 *  Not a `title=""` attribute: those never appear on touch, take ~1s to show,
 *  cannot be styled, and are skipped by several screen readers. This is a real
 *  button, so it works on hover, on keyboard focus, and on tap.
 * ------------------------------------------------------------------- */

/**
 * @param string $text  what the field is for, in plain words
 * @param string $label the field's name, used for the accessible button name
 */
function help_tip(string $text, string $label = ''): string
{
    static $seq = 0;
    $id = 'tip-' . (++$seq);

    return '<span class="tip">'
         . '<button type="button" class="tip__btn" aria-describedby="' . $id . '" '
         // type="button" matters: inside a form, a bare <button> submits it.
         . 'aria-label="' . e($label !== '' ? 'What is “' . $label . '” for?' : 'More information') . '">'
         . '?</button>'
         . '<span class="tip__bubble" role="tooltip" id="' . $id . '">' . e($text) . '</span>'
         . '</span>';
}

/** A field label with its help tip already attached. */
function label_tip(string $for, string $label, string $help, bool $required = false): string
{
    return '<label class="label" for="' . e($for) . '">' . e($label)
         . ($required ? ' <span class="req">*</span>' : '')
         . ' ' . help_tip($help, $label) . '</label>';
}

/**
 * Resolve {{tokens}} against live settings.
 *
 * Lives here rather than in policy.php because the announcement bar needs it
 * too: its text used to spell out "orders above ₹999", which silently went
 * stale the moment the threshold changed in Admin → Settings. Anything that
 * quotes a configurable figure should quote the token, not the number.
 *
 * @param bool $escape true for HTML body content (a setting could contain an
 *                     angle bracket); false for plain-text contexts such as the
 *                     meta description, which header.php escapes itself.
 */
function policy_tokens(string $html, bool $escape = true): string
{
    $map = [
        'store_name'   => setting('store_name'),
        'legal_name'   => setting('store_legal_name'),
        'address'      => setting('store_address'),
        'phone'        => setting('store_phone'),
        'email'        => setting('store_email'),
        'gstin'        => setting('store_gstin'),
        'grievance_name'  => setting('grievance_name'),
        'grievance_email' => setting('grievance_email'),
        'city'         => setting('jurisdiction_city'),
        'state'        => setting('jurisdiction_state'),
        'return_days'  => (string) setting_int('return_window_days', 7),
        'dispatch_days'=> setting('dispatch_days', '1-2'),
        'flat_rate'    => money(setting_money('ship_flat_rate', 69)),
        'free_threshold' => money(setting_money('ship_free_threshold', 2499)),
    ];

    foreach ($map as $key => $value) {
        $html = str_replace('{{' . $key . '}}', $escape ? e($value) : $value, $html);
    }
    return $html;
}
