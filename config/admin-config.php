<?php
/**
 * Admin access control.
 *
 * Belt and braces: an admin must BOTH exist as an active row in `admin_users`
 * AND have their normalized Gmail listed here. Adding a row to the database is
 * therefore not enough on its own to grant admin access.
 */
declare(strict_types=1);

$ADMIN = [
    // Normalized Gmail addresses (lowercase, no dots, no +tags).
    // Use normalize_gmail() semantics: "dr.manoj.p+shop@gmail.com" -> "drmanojp@gmail.com"
    'allowlist' => [
        'unknownones910@gmail.com',
        'yarnnestsite@gmail.com',
    ],

    // Optional IP allowlist for /admin. Empty array = allow any IP.
    // Example: ['203.0.113.4', '203.0.113.5']
    'ip_allowlist' => [],

    // Admin session idle timeout in seconds (8 hours).
    'session_timeout' => 8 * 3600,
];

$localAdmin = __DIR__ . '/admin-config.local.php';
if (is_file($localAdmin)) {
    $override = require $localAdmin;
    if (is_array($override)) {
        $ADMIN = array_merge($ADMIN, $override);
    }
}
