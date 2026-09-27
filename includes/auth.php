<?php
/**
 * Session + identity.
 *
 * This file owns "who is making this request". Issuing and checking OTP codes
 * lives separately in includes/otp.php — this layer only cares about turning a
 * verified identity into a session, and reading it back on later requests.
 *
 * Customers and admins both sign in with a password plus an emailed code, but
 * they weigh the two differently, on purpose:
 *
 *   customer  code on FIRST sign-in only (it proves the Gmail is theirs and
 *             creates the account), password every time after. A shop that
 *             emails a code on every visit loses carts.
 *   admin     password AND a fresh code, every single time.
 *
 * The only long-lived credential is the rotating remember-me token below.
 */
declare(strict_types=1);

const YN_REMEMBER_COOKIE = 'yn_rmb';
const YN_REMEMBER_DAYS   = 30;

// -------------------------------------------------------------- passwords --

const YN_PW_MIN = 8;      // customers; short enough not to lose sign-ups
const YN_PW_MAX = 72;     // bcrypt stops reading here — see below

/**
 * Validate a new password. Returns an error string, or '' when it is fine.
 *
 * The maximum is not arbitrary: bcrypt silently ignores everything past 72
 * BYTES, so a longer password would appear to work and then also accept a
 * truncated version of itself. Refuse rather than quietly trim.
 */
function password_problem(string $password, string $confirm = null): string
{
    $len = strlen($password);          // bytes, not characters — bcrypt counts bytes

    if ($len < YN_PW_MIN) {
        return 'Use at least ' . YN_PW_MIN . ' characters.';
    }
    if ($len > YN_PW_MAX) {
        return 'Use ' . YN_PW_MAX . ' characters or fewer.';
    }
    if (preg_match('/^\d+$/', $password)) {
        return 'Digits alone are too easy to guess — add some letters.';
    }
    if ($confirm !== null && !hash_equals($password, $confirm)) {
        return 'The two passwords do not match.';
    }
    return '';
}

/** Store a new password for a customer and clear any lockout. */
function customer_set_password(int $customerId, string $password): void
{
    db_exec(
        'UPDATE customers
            SET password_hash = ?, password_set_at = NOW(), failed_logins = 0, locked_until = NULL
          WHERE id = ?',
        [password_hash($password, PASSWORD_DEFAULT), $customerId]
    );
}

/** True while the account is in its cool-off after too many wrong passwords. */
function customer_is_locked(?array $customer): bool
{
    return $customer
        && !empty($customer['locked_until'])
        && strtotime((string) $customer['locked_until']) > time();
}

/**
 * Record a wrong password. Locks the account for 15 minutes on the fifth.
 *
 * The lock is on the account rather than the IP because an attacker rotates
 * IPs and the account holder does not.
 */
function customer_note_failed_login(int $customerId, int $current): void
{
    if ($current + 1 >= 5) {
        db_exec('UPDATE customers SET failed_logins = 0,
                        locked_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE)
                  WHERE id = ?', [$customerId]);
    } else {
        db_exec('UPDATE customers SET failed_logins = failed_logins + 1 WHERE id = ?',
                [$customerId]);
    }
}

// ------------------------------------------------------------- customers --

/**
 * The signed-in customer for this request, or null.
 * Resolved once and cached — safe to call from anywhere.
 */
function current_customer(): ?array
{
    static $resolved = false;
    static $cache    = null;

    if ($resolved) {
        return $cache;
    }
    $resolved = true;

    if (PHP_SAPI === 'cli') {
        return $cache = null;
    }

    $id = (int) ($_SESSION['customer_id'] ?? 0);

    // No session? Try the remember-me cookie.
    if ($id <= 0) {
        $id = remember_resume();
    }

    if ($id <= 0) {
        return $cache = null;
    }

    // Bind the session to the browser that created it.
    $uaHash = substr(hash('sha256', user_agent()), 0, 32);
    if (!empty($_SESSION['ua_hash']) && !hash_equals((string) $_SESSION['ua_hash'], $uaHash)) {
        logout_customer();
        return $cache = null;
    }

    $row = db_row(
        'SELECT id, email, email_key, full_name, phone, status, marketing_opt_in,
                craft_prefs, skill_level, orders_count, total_spent, created_at
         FROM customers
         WHERE id = ? AND deleted_at IS NULL
         LIMIT 1',
        [$id]
    );

    if (!$row || $row['status'] !== 'active') {
        logout_customer();
        return $cache = null;
    }

    return $cache = $row;
}

function customer_id(): int
{
    $c = current_customer();
    return $c ? (int) $c['id'] : 0;
}

function is_logged_in(): bool
{
    return customer_id() > 0;
}

/**
 * First name for greetings, or '' when we genuinely do not know it.
 *
 * Deliberately does NOT fall back to the email local part: turning
 * "priyasharma@gmail.com" into "Priyasharma" reads worse than no name
 * at all. Callers greet generically when this returns ''.
 */
function customer_display_name(?array $c = null): string
{
    $c = $c ?? current_customer();
    if (!$c || empty($c['full_name'])) {
        return '';
    }
    return explode(' ', trim((string) $c['full_name']))[0];
}

/**
 * Find or create the customer for a verified Gmail address.
 * $emailKey must already be normalized by normalize_gmail().
 */
function customer_upsert(string $emailKey, string $emailAsTyped): array
{
    $existing = db_row('SELECT * FROM customers WHERE email_key = ? LIMIT 1', [$emailKey]);

    if ($existing) {
        // Restore a soft-deleted account if they come back.
        if ($existing['deleted_at'] !== null) {
            db_exec('UPDATE customers SET deleted_at = NULL, status = "active" WHERE id = ?', [(int) $existing['id']]);
        }
        db_exec(
            'UPDATE customers SET email = ?, email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?',
            [$emailAsTyped, (int) $existing['id']]
        );
        return db_row('SELECT * FROM customers WHERE id = ? LIMIT 1', [(int) $existing['id']]) ?? $existing;
    }

    $id = db_insert(
        'INSERT INTO customers (email, email_key, email_verified_at, created_at)
         VALUES (?, ?, NOW(), NOW())',
        [$emailAsTyped, $emailKey]
    );

    return db_row('SELECT * FROM customers WHERE id = ? LIMIT 1', [$id]) ?? [];
}

/**
 * Establish the session after a successful OTP verification.
 * Regenerating the id here is the defence against session fixation.
 */
function login_customer(int $customerId, bool $remember = false): void
{
    session_regenerate_id(true);

    $_SESSION['customer_id'] = $customerId;
    $_SESSION['ua_hash']     = substr(hash('sha256', user_agent()), 0, 32);
    $_SESSION['login_at']    = time();

    db_exec('UPDATE customers SET last_login_at = NOW() WHERE id = ?', [$customerId]);

    // Whatever they put in the cart before signing in comes with them.
    if (function_exists('cart_bind_to_customer')) {
        cart_bind_to_customer($customerId);
    }

    if ($remember) {
        remember_issue($customerId);
    }
}

function logout_customer(): void
{
    remember_revoke_current();

    $_SESSION = [];

    if (PHP_SAPI !== 'cli' && ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'],
            'domain'   => $p['domain'],
            'secure'   => $p['secure'],
            'httponly' => $p['httponly'],
            'samesite' => 'Lax',
        ]);
    }

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

/** Gate a page. Sends the visitor to login and returns them here afterwards. */
function require_login(): array
{
    $c = current_customer();
    if ($c) {
        return $c;
    }

    $next = $_SERVER['REQUEST_URI'] ?? url('account');
    $_SESSION['after_login'] = $next;

    if (is_ajax()) {
        json_out(['ok' => false, 'error' => 'login_required'], 401);
    }
    redirect('login.php');
}

/** Where to send someone after signing in. */
function after_login_url(): string
{
    $next = $_SESSION['after_login'] ?? null;
    unset($_SESSION['after_login']);

    // Only ever redirect within this site.
    if (is_string($next) && $next !== '' && str_starts_with($next, '/') && !str_starts_with($next, '//')) {
        return $next;
    }
    return url('account/');
}

// ----------------------------------------------------------- remember me --

/**
 * Split-token remember-me: the cookie holds "selector:validator". Only the
 * selector is stored in the clear, so a database leak cannot be replayed as a
 * login. The token rotates on every use.
 */
function remember_issue(int $customerId): void
{
    $selector  = bin2hex(random_bytes(12));   // 24 chars
    $validator = bin2hex(random_bytes(32));

    db_exec(
        'INSERT INTO customer_remember_tokens (customer_id, selector, validator_hash, expires_at)
         VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY))',
        [$customerId, $selector, hash('sha256', $validator), YN_REMEMBER_DAYS]
    );

    setcookie(YN_REMEMBER_COOKIE, $selector . ':' . $validator, [
        'expires'  => time() + (YN_REMEMBER_DAYS * 86400),
        'path'     => '/',
        'secure'   => !is_local_dev(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** Validate the cookie and rotate it. Returns the customer id, or 0. */
function remember_resume(): int
{
    $raw = $_COOKIE[YN_REMEMBER_COOKIE] ?? '';
    if (!is_string($raw) || !str_contains($raw, ':')) {
        return 0;
    }

    [$selector, $validator] = explode(':', $raw, 2);
    if (strlen($selector) !== 24 || strlen($validator) !== 64) {
        return 0;
    }

    $row = db_row(
        'SELECT id, customer_id, validator_hash FROM customer_remember_tokens
         WHERE selector = ? AND expires_at > NOW() LIMIT 1',
        [$selector]
    );
    if (!$row) {
        remember_clear_cookie();
        return 0;
    }

    if (!hash_equals((string) $row['validator_hash'], hash('sha256', $validator))) {
        // Wrong validator for a known selector: treat as theft, kill every
        // token for that customer and force a fresh OTP.
        db_exec('DELETE FROM customer_remember_tokens WHERE customer_id = ?', [(int) $row['customer_id']]);
        remember_clear_cookie();
        return 0;
    }

    $customerId = (int) $row['customer_id'];

    // Rotate: one-time use.
    db_exec('DELETE FROM customer_remember_tokens WHERE id = ?', [(int) $row['id']]);
    remember_issue($customerId);

    $_SESSION['customer_id'] = $customerId;
    $_SESSION['ua_hash']     = substr(hash('sha256', user_agent()), 0, 32);
    $_SESSION['login_at']    = time();

    // Same merge as a fresh sign-in: someone can fill a cart as a guest and
    // then be resumed by the remember-me cookie on the next page load.
    if (function_exists('cart_bind_to_customer')) {
        cart_bind_to_customer($customerId);
    }

    return $customerId;
}

function remember_revoke_current(): void
{
    $raw = $_COOKIE[YN_REMEMBER_COOKIE] ?? '';
    if (is_string($raw) && str_contains($raw, ':')) {
        [$selector] = explode(':', $raw, 2);
        db_exec('DELETE FROM customer_remember_tokens WHERE selector = ?', [$selector]);
    }
    remember_clear_cookie();
}

/** Sign out of every device. Offered on the account security screen. */
function remember_revoke_all(int $customerId): void
{
    db_exec('DELETE FROM customer_remember_tokens WHERE customer_id = ?', [$customerId]);
    remember_clear_cookie();
}

function remember_clear_cookie(): void
{
    if (PHP_SAPI === 'cli') {
        return;
    }
    setcookie(YN_REMEMBER_COOKIE, '', [
        'expires'  => time() - 42000,
        'path'     => '/',
        'secure'   => !is_local_dev(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// --------------------------------------------------------------- admins --

function current_admin(): ?array
{
    static $resolved = false;
    static $cache    = null;

    if ($resolved) {
        return $cache;
    }
    $resolved = true;

    $id = (int) ($_SESSION['admin_id'] ?? 0);
    if ($id <= 0) {
        return $cache = null;
    }

    // Hard idle timeout, much shorter than the shopper session.
    require YN_ROOT . '/config/admin-config.php';
    $timeout = (int) ($ADMIN['session_timeout'] ?? 28800);
    if (time() - (int) ($_SESSION['admin_seen'] ?? 0) > $timeout) {
        logout_admin();
        return $cache = null;
    }
    $_SESSION['admin_seen'] = time();

    $row = db_row(
        'SELECT id, email, email_key, name, role, is_active FROM admin_users WHERE id = ? LIMIT 1',
        [$id]
    );

    if (!$row || (int) $row['is_active'] !== 1) {
        logout_admin();
        return $cache = null;
    }

    // The allowlist is checked on every request, not just at login, so
    // revoking access is immediate.
    if (!in_array($row['email_key'], $ADMIN['allowlist'] ?? [], true)) {
        logout_admin();
        return $cache = null;
    }

    return $cache = $row;
}

function login_admin(int $adminId): void
{
    session_regenerate_id(true);
    $_SESSION['admin_id']   = $adminId;
    $_SESSION['admin_seen'] = time();
    db_exec('UPDATE admin_users SET last_login_at = NOW() WHERE id = ?', [$adminId]);
}

function logout_admin(): void
{
    unset($_SESSION['admin_id'], $_SESSION['admin_seen']);
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

function require_admin(string $minRole = 'staff'): array
{
    $a = current_admin();
    if (!$a) {
        $_SESSION['after_admin_login'] = $_SERVER['REQUEST_URI'] ?? url('admin/');
        redirect('admin/login.php');
    }
    if (!admin_can($minRole, $a)) {
        http_response_code(403);
        exit('You do not have permission to view this page.');
    }
    return $a;
}

/** owner > manager > staff */
function admin_can(string $minRole, ?array $admin = null): bool
{
    $admin = $admin ?? current_admin();
    if (!$admin) {
        return false;
    }
    $rank = ['staff' => 1, 'manager' => 2, 'owner' => 3];
    return ($rank[$admin['role']] ?? 0) >= ($rank[$minRole] ?? 99);
}

/** Audit trail. Called by every mutating admin action. */
function admin_log(string $action, ?string $entity = null, ?int $entityId = null, ?string $detail = null): void
{
    $a = current_admin();
    db_exec(
        'INSERT INTO admin_activity_log (admin_id, action, entity, entity_id, detail, ip)
         VALUES (?, ?, ?, ?, ?, ?)',
        [$a ? (int) $a['id'] : null, $action, $entity, $entityId, $detail ? mb_substr($detail, 0, 500) : null, client_ip()]
    );
}
