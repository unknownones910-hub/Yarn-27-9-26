<?php
/**
 * Single entry point for every request.
 *
 * The first line of every page in this project is:
 *     require_once __DIR__ . '/includes/bootstrap.php';
 *
 * It sets error handling, starts the correct session, connects the database,
 * loads settings into $CFG, pulls in the helper libraries, and resolves the
 * logged-in customer into $customer (null when signed out).
 */
declare(strict_types=1);

if (defined('YN_BOOTSTRAPPED')) {
    return;
}
define('YN_BOOTSTRAPPED', true);
define('YN_ROOT', dirname(__DIR__));
define('YN_T0', microtime(true));   // request start, for the ?debug=1 perf line

// ---------------------------------------------------------------- config --

/** @var array $SITE @var array $SETTINGS_DEFAULTS */
require YN_ROOT . '/config/site-config.php';

date_default_timezone_set($SITE['timezone']);
mb_internal_encoding('UTF-8');

require_once YN_ROOT . '/includes/helpers.php';

// ------------------------------------------------------- error reporting --

if (is_local_dev()) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
}

// Turn fatal errors into the friendly 500 page instead of a white screen.
if (!defined('YN_NO_ERROR_PAGE')) {
    set_exception_handler(static function (Throwable $e): void {
        error_log('Uncaught: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        if (is_local_dev()) {
            http_response_code(500);
            echo '<pre style="padding:24px;font:14px/1.6 monospace;white-space:pre-wrap">';
            echo e($e->getMessage()) . "\n\n" . e($e->getTraceAsString());
            echo '</pre>';
            exit;
        }
        http_response_code(500);
        if (is_file(YN_ROOT . '/500.php')) {
            require YN_ROOT . '/500.php';
        }
        exit;
    });
}

// ----------------------------------------------------- security headers --

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    // Stop announcing the exact PHP build to anyone scanning for known CVEs.
    // expose_php cannot be set at runtime, so remove the header instead.
    header_remove('X-Powered-By');

    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=(), interest-cohort=()');
    header('Cross-Origin-Opener-Policy: same-origin');

    // script-src is nonce-based, NOT 'unsafe-inline'. Every inline <script> in
    // the codebase carries csp_nonce(); anything injected does not, and will
    // not run. Browsers ignore 'unsafe-inline' once a nonce is present, so the
    // two must never be listed together — see csp_allow_inline_scripts().
    //
    // style-src keeps 'unsafe-inline' deliberately: the templates use inline
    // style="" attributes throughout, and there is no nonce mechanism for those.
    $csp = "default-src 'self'; "
         . "script-src 'self' 'nonce-" . csp_nonce() . "' https://checkout.razorpay.com; "
         . "style-src 'self' 'unsafe-inline'; "
         . "img-src 'self' data: blob: https:; "
         . "font-src 'self' data:; "
         . "connect-src 'self' https://api.razorpay.com https://lumberjack.razorpay.com; "
         . "frame-src https://api.razorpay.com https://checkout.razorpay.com; "
         . "form-action 'self'; base-uri 'self'; frame-ancestors 'self'; "
         . "object-src 'none'";
    header('Content-Security-Policy: ' . $csp);

    if (!is_local_dev()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/**
 * Re-issue the CSP with 'unsafe-inline' restored for scripts.
 *
 * Only pay.php calls this. Razorpay's checkout.js is a third-party bundle we
 * do not control, and if a future version injects an inline script the nonce
 * policy would block it — silently killing the payment modal on the one page
 * where a failure costs an order. Everywhere else keeps the strict policy.
 */
function csp_allow_inline_scripts(): void
{
    if (PHP_SAPI === 'cli' || headers_sent()) {
        return;
    }
    foreach (headers_list() as $h) {
        if (stripos($h, 'Content-Security-Policy:') === 0) {
            header(str_replace(
                "script-src 'self' 'nonce-" . csp_nonce() . "'",
                "script-src 'self' 'unsafe-inline'",
                $h
            ), true);
            return;
        }
    }
}

// ---------------------------------------------------------------- session --

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    // A distinct session name per portal. localhost is shared with other
    // projects, and a generic PHPSESSID collides and randomly signs people out.
    $sessionName = defined('YN_ADMIN') ? $SITE['admin_session_name'] : $SITE['session_name'];
    session_name($sessionName);

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => !is_local_dev(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.gc_maxlifetime', '2592000');   // 30 days for shoppers

    session_start();
}

// ------------------------------------------------------------ JSON body --

/**
 * PHP only fills $_POST for form-encoded bodies. Our fetch() helper posts
 * JSON, so without this every AJAX endpoint would read an empty $_POST and
 * reject perfectly good input. Decoding it here means handlers can use
 * post_int()/post_str() without caring how the request was encoded.
 */
/**
 * Wrapped in a function on purpose: bootstrap.php is `require`d into the
 * caller's scope, so any variable created here would collide with the
 * including script's own. A bare $rawBody here silently blanked the identically
 * named variable in webhooks/razorpay.php and broke webhook verification.
 */
(static function (): void {
    if (
        PHP_SAPI === 'cli'
        || ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
        || $_POST
        || !str_contains(strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')
    ) {
        return;
    }

    $body = file_get_contents('php://input');
    if (!is_string($body) || $body === '' || strlen($body) > 262144) {
        return;
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return;
    }

    // Scalars only — nested structures are not something our handlers expect,
    // and flattening them here would hide bugs.
    foreach ($decoded as $k => $v) {
        if (is_scalar($v) || $v === null) {
            $_POST[$k] = $v === null ? '' : (string) $v;
        }
    }
})();

// -------------------------------------------------------------- database --

require_once YN_ROOT . '/includes/db.php';
require_once YN_ROOT . '/includes/csrf.php';
require_once YN_ROOT . '/includes/ratelimit.php';

// -------------------------------------------------------------- settings --

/** @var array<string,string> $CFG */
$CFG = [];
$YN_INSTALLED = false;

try {
    if (db_table_exists('settings')) {
        $YN_INSTALLED = true;
        foreach (db_all('SELECT k, v FROM settings') as $r) {
            $CFG[$r['k']] = (string) $r['v'];
        }
    }
} catch (Throwable $e) {
    error_log('Settings load failed: ' . $e->getMessage());
}

// Not installed yet: send everything except the installer to the installer.
if (!$YN_INSTALLED && PHP_SAPI !== 'cli') {
    $self = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($self !== 'install.php') {
        redirect('install.php');
    }
}

// ------------------------------------------------------------------ auth --

require_once YN_ROOT . '/includes/auth.php';

/** @var array|null $customer */
$customer = current_customer();

// ------------------------------------------------------- view helpers --
// Loaded here, not in header.php: pages routinely call nav_categories() or
// icon() while building $jsonLd and page data, i.e. before the header runs.
require_once YN_ROOT . '/includes/nav.php';
require_once YN_ROOT . '/includes/components/icons.php';
require_once YN_ROOT . '/includes/components/breadcrumb.php';
require_once YN_ROOT . '/includes/components/mascot.php';
require_once YN_ROOT . '/includes/cart.php';   // header needs cart_count()

// ------------------------------------------------------------ housekeeping --

if ($YN_INSTALLED && random_int(1, 200) === 1) {
    try {
        rate_gc();
    } catch (Throwable $e) {
        error_log('GC failed: ' . $e->getMessage());
    }
}
