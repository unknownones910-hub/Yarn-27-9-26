<?php
/**
 * CSRF protection.
 *
 * Rule: every POST handler in this project calls csrf_verify() as its FIRST
 * statement, before reading any other input. Every form calls csrf_field().
 * AJAX POSTs send the token in the X-CSRF-Token header.
 */
declare(strict_types=1);

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

/** Hidden input for a form. */
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_check(): bool
{
    $sent = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($sent) || $sent === '' || empty($_SESSION['_csrf'])) {
        return false;
    }
    return hash_equals($_SESSION['_csrf'], $sent);
}

/** Verify or die. Responds sensibly to both AJAX and normal form posts. */
function csrf_verify(): void
{
    if (csrf_check()) {
        return;
    }

    if (is_ajax()) {
        json_out(['ok' => false, 'error' => 'Your session expired. Please refresh the page.'], 419);
    }

    http_response_code(419);
    flash('Your session expired for security reasons. Please try again.', 'error');
    $back = $_SERVER['HTTP_REFERER'] ?? url('/');
    header('Location: ' . $back);
    exit;
}

/**
 * Simple bot trap. Renders a field a human never sees but a naive bot fills.
 * Pair with honeypot_ok() in the handler. Cheap, no CAPTCHA, no third party.
 */
function honeypot_field(): string
{
    return '<div class="hp-field" aria-hidden="true">'
         . '<label>Do not fill this in<input type="text" name="website" tabindex="-1" autocomplete="off"></label>'
         . '<input type="hidden" name="_ts" value="' . time() . '">'
         . '</div>';
}

function honeypot_ok(int $minSeconds = 3): bool
{
    if (!empty($_POST['website'])) {
        return false;                       // bot filled the invisible field
    }
    $ts = (int) ($_POST['_ts'] ?? 0);
    if ($ts <= 0 || (time() - $ts) < $minSeconds) {
        return false;                       // submitted impossibly fast
    }
    return true;
}
