<?php
/**
 * Migration 008 — passwords for customer accounts.
 *
 * Sign-in changes shape. Before: type your Gmail, get a code, you are in — the
 * account was created silently on first verification. Now:
 *
 *   first time   Gmail -> emailed code (proves the address is yours)
 *                      -> choose a password -> account created, signed in
 *   after that   Gmail + password -> signed in
 *   forgotten    Gmail -> emailed code -> choose a new password
 *
 * The code still comes first on signup, deliberately: it means an account can
 * only ever be created against an address the person actually controls, so
 * nobody can register using somebody else's Gmail.
 *
 * `password_hash` is nullable, and NULL means "verify by email, then set one".
 * That is the migration path for accounts created under the old passwordless
 * flow — they are not locked out, they are asked to choose a password the next
 * time they sign in.
 *
 * @var mysqli $conn  provided by install.php
 */
declare(strict_types=1);

$columns = [
    'password_hash'   => "VARCHAR(255) NULL DEFAULT NULL COMMENT 'bcrypt; NULL = set one after email verification'",
    'password_set_at' => 'DATETIME NULL DEFAULT NULL',
    'failed_logins'   => 'TINYINT UNSIGNED NOT NULL DEFAULT 0',
    'locked_until'    => 'DATETIME NULL DEFAULT NULL',
];

$have = [];
$res  = $conn->query(
    "SELECT COLUMN_NAME FROM information_schema.COLUMNS
     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'customers'"
);
while ($row = $res->fetch_row()) {
    $have[] = $row[0];
}

$added = 0;
foreach ($columns as $name => $definition) {
    if (in_array($name, $have, true)) {
        continue;                       // re-runnable
    }
    $conn->query("ALTER TABLE customers ADD COLUMN `{$name}` {$definition}");
    $added++;
}

return $added;
