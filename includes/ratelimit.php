<?php
/**
 * Database-backed rate limiting.
 *
 * Used by: OTP requests, OTP verification, contact form, review submission,
 * guest order tracking, and newsletter signup. A fixed window is good enough
 * here and is far simpler than a sliding log.
 *
 * Buckets are namespaced strings, e.g. "otp:req:manoj@gmail.com" or
 * "track:ip:203.0.113.4".
 */
declare(strict_types=1);

/**
 * Record a hit and report whether the caller is still within the limit.
 *
 * @return bool true if allowed, false if the limit has been exceeded
 */
function rate_hit(string $bucket, int $limit, int $windowSeconds): bool
{
    $bucket = substr($bucket, 0, 190);
    $now    = time();

    $row = db_row(
        'SELECT id, hits, UNIX_TIMESTAMP(window_start) AS started FROM rate_limits WHERE bucket = ? LIMIT 1',
        [$bucket]
    );

    if (!$row) {
        db_exec(
            'INSERT INTO rate_limits (bucket, hits, window_start) VALUES (?, 1, NOW())
             ON DUPLICATE KEY UPDATE hits = hits + 1',
            [$bucket]
        );
        return true;
    }

    // Window expired: reset it.
    if (($now - (int) $row['started']) >= $windowSeconds) {
        db_exec('UPDATE rate_limits SET hits = 1, window_start = NOW() WHERE id = ?', [(int) $row['id']]);
        return true;
    }

    if ((int) $row['hits'] >= $limit) {
        return false;
    }

    db_exec('UPDATE rate_limits SET hits = hits + 1 WHERE id = ?', [(int) $row['id']]);
    return true;
}

/** Check the limit without consuming a hit. */
function rate_check(string $bucket, int $limit, int $windowSeconds): bool
{
    $row = db_row(
        'SELECT hits, UNIX_TIMESTAMP(window_start) AS started FROM rate_limits WHERE bucket = ? LIMIT 1',
        [substr($bucket, 0, 190)]
    );
    if (!$row) {
        return true;
    }
    if ((time() - (int) $row['started']) >= $windowSeconds) {
        return true;
    }
    return (int) $row['hits'] < $limit;
}

/** Seconds until the window resets. Drives "try again in 4 minutes" copy. */
function rate_retry_after(string $bucket, int $windowSeconds): int
{
    $started = db_val(
        'SELECT UNIX_TIMESTAMP(window_start) FROM rate_limits WHERE bucket = ? LIMIT 1',
        [substr($bucket, 0, 190)]
    );
    if (!$started) {
        return 0;
    }
    return max(0, $windowSeconds - (time() - (int) $started));
}

/** Clear a bucket — call after a successful login so a user isn't punished. */
function rate_clear(string $bucket): void
{
    db_exec('DELETE FROM rate_limits WHERE bucket = ?', [substr($bucket, 0, 190)]);
}

/** Human "4 minutes" / "45 seconds" for retry messaging. */
function rate_wait_text(int $seconds): string
{
    if ($seconds <= 60) {
        return $seconds . ' seconds';
    }
    $mins = (int) ceil($seconds / 60);
    return $mins . ' minute' . ($mins === 1 ? '' : 's');
}

/** Housekeeping — called opportunistically (1 in 200 requests) by bootstrap. */
function rate_gc(): void
{
    db_exec('DELETE FROM rate_limits WHERE window_start < (NOW() - INTERVAL 1 DAY)');
    db_exec('DELETE FROM otp_codes  WHERE created_at   < (NOW() - INTERVAL 3 DAY)');
}
