<?php
/**
 * Migration 010 — remove the hardcoded seed admin.
 *
 * 002_seed.sql used to create an admin row for a specific Gmail address. That
 * has been taken out of the seed, but installs created before this migration
 * still carry the row, and an active admin_users row is one of the four things
 * that grants access. Removing it from the seed alone would leave those
 * installs unchanged.
 *
 * Anonymises rather than deletes, for the same reason account/delete.php does:
 * admin_activity_log rows reference the admin id, and an audit trail that
 * cannot resolve who did what is worse than a disabled row. The address itself
 * is overwritten, so it survives nowhere.
 *
 * Three things independently prevent sign-in afterwards — is_active = 0, a NULL
 * password_hash, and the address no longer being in the config allowlist.
 *
 * @var mysqli $conn  provided by install.php
 */
declare(strict_types=1);

$retired = 'drmanojpchavan@gmail.com';

$stmt = $conn->prepare(
    "UPDATE admin_users
        SET email         = CONCAT('retired-', id, '@removed.invalid'),
            email_key     = CONCAT('retired-', id, '@removed.invalid'),
            name          = '[removed]',
            is_active     = 0,
            password_hash = NULL,
            password_set_at = NULL
      WHERE email_key = ?"
);
$stmt->bind_param('s', $retired);
$stmt->execute();

return $stmt->affected_rows;
