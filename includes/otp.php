<?php
/**
 * One-time passcodes.
 *
 * There are no passwords in this project, so this file is the entire
 * authentication factor. It is written defensively:
 *
 *   · codes come from random_int(), never rand()/mt_rand()
 *   · only a SHA-256 hash (peppered) is stored — never the code itself
 *   · comparison is hash_equals(), constant time
 *   · issuing a new code invalidates every older unconsumed one
 *   · five wrong guesses burns the code; a new one must be requested
 *   · request limits per email AND per IP, plus a resend cooldown
 *   · a wrong code and an unknown email produce the SAME response, so this
 *     cannot be used to discover which addresses have accounts
 */
declare(strict_types=1);

require_once __DIR__ . '/mailer.php';

const OTP_TTL_SECONDS      = 600;   // 10 minutes
const OTP_MAX_ATTEMPTS     = 5;
const OTP_RESEND_COOLDOWN  = 60;    // seconds between sends
const OTP_MAX_PER_HOUR     = 5;     // per email address
const OTP_MAX_PER_IP_HOUR  = 15;
const OTP_VERIFY_MAX_HOUR  = 15;    // wrong-code attempts per email per hour

function otp_pepper(): string
{
    global $SITE;
    return (string) ($SITE['otp_pepper'] ?? 'yn_pepper');
}

function otp_hash(string $code): string
{
    return hash('sha256', $code . '|' . otp_pepper());
}

/** Seconds until this address may request another code (0 = now). */
function otp_resend_wait(string $emailKey, string $purpose = 'login'): int
{
    $last = db_val(
        'SELECT UNIX_TIMESTAMP(created_at) FROM otp_codes
         WHERE email_key = ? AND purpose = ?
         ORDER BY id DESC LIMIT 1',
        [$emailKey, $purpose]
    );
    if (!$last) {
        return 0;
    }
    return max(0, OTP_RESEND_COOLDOWN - (time() - (int) $last));
}

/**
 * Issue and send a code.
 *
 * @return array{ok:bool, error?:string, retry_after?:int, dev_code?:string, expires_in?:int}
 */
function otp_issue(string $emailKey, string $sendToAddress, string $purpose = 'login'): array
{
    // --- throttling ----------------------------------------------------
    $wait = otp_resend_wait($emailKey, $purpose);
    if ($wait > 0) {
        return ['ok' => false, 'error' => 'Please wait ' . $wait . ' seconds before requesting another code.',
                'retry_after' => $wait];
    }

    if (!rate_hit('otp:req:' . $emailKey, OTP_MAX_PER_HOUR, 3600)) {
        $retry = rate_retry_after('otp:req:' . $emailKey, 3600);
        return ['ok' => false, 'retry_after' => $retry,
                'error' => 'Too many codes requested for this address. Try again in '
                           . rate_wait_text($retry) . ', or contact us for help.'];
    }

    if (!rate_hit('otp:ip:' . client_ip(), OTP_MAX_PER_IP_HOUR, 3600)) {
        $retry = rate_retry_after('otp:ip:' . client_ip(), 3600);
        return ['ok' => false, 'retry_after' => $retry,
                'error' => 'Too many sign-in attempts from this connection. Try again in '
                           . rate_wait_text($retry) . '.'];
    }

    // --- issue ---------------------------------------------------------
    // Any code still outstanding for this address+purpose is now dead, so a
    // previously emailed code cannot be replayed after a resend.
    db_exec(
        'UPDATE otp_codes SET consumed_at = NOW()
         WHERE email_key = ? AND purpose = ? AND consumed_at IS NULL',
        [$emailKey, $purpose]
    );

    $code = (string) random_int(100000, 999999);

    db_insert(
        'INSERT INTO otp_codes (email_key, purpose, code_hash, max_attempts, expires_at, ip)
         VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND), ?)',
        [$emailKey, $purpose, otp_hash($code), OTP_MAX_ATTEMPTS, OTP_TTL_SECONDS, client_ip()]
    );

    $ttlMinutes = (int) (OTP_TTL_SECONDS / 60);

    // --- deliver -------------------------------------------------------
    // On a developer machine the code is shown on screen instead of emailed,
    // so the whole flow is testable before Brevo is configured. The guard is
    // is_local_dev(), so this can never fire in production.
    if (is_local_dev()) {
        email_log($sendToAddress, 'Your sign-in code (dev)', 'otp', 'skipped', null, 'local dev: shown on screen');
        return ['ok' => true, 'dev_code' => $code, 'expires_in' => OTP_TTL_SECONDS];
    }

    $sent = send_otp_email($sendToAddress, $code, $ttlMinutes);

    if (!$sent) {
        // Do not strand the customer on a dead end.
        return ['ok' => false,
                'error' => 'We could not send the code just now. Please try again in a minute, '
                           . 'or reach us on WhatsApp and we will help.'];
    }

    return ['ok' => true, 'expires_in' => OTP_TTL_SECONDS];
}

/**
 * Check a submitted code.
 *
 * @return array{ok:bool, error?:string, attempts_left?:int}
 */
function otp_verify(string $emailKey, string $code, string $purpose = 'login'): array
{
    $code = preg_replace('/\D+/', '', $code) ?? '';

    if (strlen($code) !== 6) {
        return ['ok' => false, 'error' => 'Enter the 6-digit code from your email.'];
    }

    // Brute-force ceiling that survives requesting fresh codes.
    if (!rate_hit('otp:vfy:' . $emailKey, OTP_VERIFY_MAX_HOUR, 3600)) {
        $retry = rate_retry_after('otp:vfy:' . $emailKey, 3600);
        return ['ok' => false,
                'error' => 'Too many incorrect codes. Please try again in ' . rate_wait_text($retry) . '.'];
    }

    $row = db_row(
        'SELECT id, code_hash, attempts, max_attempts
         FROM otp_codes
         WHERE email_key = ? AND purpose = ? AND consumed_at IS NULL AND expires_at > NOW()
         ORDER BY id DESC LIMIT 1',
        [$emailKey, $purpose]
    );

    if (!$row) {
        return ['ok' => false, 'error' => 'That code has expired. Request a new one to continue.'];
    }

    if ((int) $row['attempts'] >= (int) $row['max_attempts']) {
        db_exec('UPDATE otp_codes SET consumed_at = NOW() WHERE id = ?', [(int) $row['id']]);
        return ['ok' => false, 'error' => 'Too many incorrect attempts. Request a new code to continue.'];
    }

    if (!hash_equals((string) $row['code_hash'], otp_hash($code))) {
        db_exec('UPDATE otp_codes SET attempts = attempts + 1 WHERE id = ?', [(int) $row['id']]);

        $left = (int) $row['max_attempts'] - ((int) $row['attempts'] + 1);
        if ($left <= 0) {
            db_exec('UPDATE otp_codes SET consumed_at = NOW() WHERE id = ?', [(int) $row['id']]);
            return ['ok' => false, 'error' => 'Too many incorrect attempts. Request a new code to continue.'];
        }

        return ['ok' => false, 'attempts_left' => $left,
                'error' => 'That code is not right. ' . $left . ' attempt' . ($left === 1 ? '' : 's') . ' left.'];
    }

    // Single use.
    db_exec('UPDATE otp_codes SET consumed_at = NOW() WHERE id = ?', [(int) $row['id']]);
    rate_clear('otp:vfy:' . $emailKey);

    return ['ok' => true];
}

/** Seconds left on the newest live code, for the on-screen countdown. */
function otp_seconds_remaining(string $emailKey, string $purpose = 'login'): int
{
    $exp = db_val(
        'SELECT UNIX_TIMESTAMP(expires_at) FROM otp_codes
         WHERE email_key = ? AND purpose = ? AND consumed_at IS NULL AND expires_at > NOW()
         ORDER BY id DESC LIMIT 1',
        [$emailKey, $purpose]
    );
    return $exp ? max(0, (int) $exp - time()) : 0;
}
