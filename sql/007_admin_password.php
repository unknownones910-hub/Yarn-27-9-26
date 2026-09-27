<?php
/**
 * Migration 007 — passwords for admin accounts.
 *
 * Admin sign-in becomes two factors: something you know (a password) and
 * something you have (a code emailed to the account's Gmail). Either one alone
 * is not enough, and both are checked on top of the two locks that already
 * existed — an active `admin_users` row AND an entry in the config allowlist.
 *
 * No credential is stored in this file. Passwords are set with
 * `tools/set-admin-password.php`, which writes a bcrypt hash straight to the
 * database, or from Admin → Your account once you are signed in.
 *
 * `password_hash` is nullable on purpose: an account with no password set
 * cannot sign in at all, so adding this column does not silently leave a
 * back door open on an existing install.
 *
 * @var mysqli $conn  provided by install.php
 */
declare(strict_types=1);

$columns = [
    'password_hash'   => "VARCHAR(255) NULL DEFAULT NULL COMMENT 'bcrypt; NULL = cannot sign in'",
    'password_set_at' => 'DATETIME NULL DEFAULT NULL',
    'failed_logins'   => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
    'locked_until'    => 'DATETIME NULL DEFAULT NULL',
];

$have = [];
$res  = $conn->query(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_users'"
);
while ($row = $res->fetch_row()) {
    $have[] = $row[0];
}

$added = 0;
foreach ($columns as $name => $definition) {
    if (in_array($name, $have, true)) {
        continue;                       // re-runnable
    }
    $conn->query("ALTER TABLE admin_users ADD COLUMN `{$name}` {$definition}");
    $added++;
}

return $added;
