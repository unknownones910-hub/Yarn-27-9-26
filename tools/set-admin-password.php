<?php
/**
 * Create or update an admin account's password.
 *
 *   php tools/set-admin-password.php you@gmail.com "Your Name" owner
 *
 * The password is read from STDIN, never from the command line, so it does not
 * end up in your shell history or in the process list:
 *
 *   printf '%s' 'the password' | php tools/set-admin-password.php you@gmail.com
 *
 * Only a bcrypt hash is ever written. Nothing here can recover the password,
 * including us — a lost password is reset by running this again.
 *
 * Remember the account still needs its normalized Gmail in $ADMIN['allowlist']
 * in config/admin-config.php. This script tells you if it is missing.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require YN_ROOT . '/config/admin-config.php';

$argv = $_SERVER['argv'];
$typed = $argv[1] ?? '';
$name  = $argv[2] ?? '';
$role  = $argv[3] ?? 'owner';

if ($typed === '') {
    fwrite(STDERR, "Usage: php tools/set-admin-password.php <email> [name] [role]\n"
        . "       Password is read from STDIN.\n");
    exit(1);
}

if (!in_array($role, ['owner', 'manager', 'staff'], true)) {
    fwrite(STDERR, "Role must be owner, manager or staff.\n");
    exit(1);
}

$key = normalize_gmail($typed);
if ($key === null) {
    fwrite(STDERR, "That is not a valid Gmail address. Admin sign-in is Gmail-only.\n");
    exit(1);
}

// ---- password from stdin ---------------------------------------------
if (stream_isatty(STDIN)) {
    fwrite(STDOUT, 'New password: ');
}
$password = rtrim((string) fgets(STDIN), "\r\n");

if (strlen($password) < 12) {
    fwrite(STDERR, "Password must be at least 12 characters.\n");
    exit(1);
}
if (strlen($password) > 72) {
    // bcrypt silently ignores everything past 72 BYTES — a longer password
    // would appear to work and then accept a truncated version too.
    fwrite(STDERR, "Password must be 72 bytes or fewer (bcrypt truncates past that).\n");
    exit(1);
}

$hash = password_hash($password, PASSWORD_DEFAULT);
if ($hash === false) {
    fwrite(STDERR, "Hashing failed.\n");
    exit(1);
}
unset($password);

// ---- write -----------------------------------------------------------
$existing = db_row('SELECT id, name, role FROM admin_users WHERE email_key = ?', [$key]);

if ($existing) {
    db_exec(
        'UPDATE admin_users
            SET password_hash = ?, password_set_at = NOW(), email = ?,
                failed_logins = 0, locked_until = NULL, is_active = 1
          WHERE id = ?',
        [$hash, $typed, (int) $existing['id']]
    );
    $id = (int) $existing['id'];
    echo "Updated admin #{$id} ({$existing['name']}, {$existing['role']}).\n";
} else {
    $id = db_insert(
        'INSERT INTO admin_users (email, email_key, name, role, is_active, password_hash, password_set_at, created_at)
         VALUES (?,?,?,?,1,?,NOW(),NOW())',
        [$typed, $key, $name !== '' ? $name : 'Admin', $role, $hash]
    );
    echo "Created admin #{$id} ({$role}).\n";
}

echo "  email      {$typed}\n";
echo "  email_key  {$key}\n";

// ---- the other lock --------------------------------------------------
$allowlist = $ADMIN['allowlist'] ?? [];
if (in_array($key, $allowlist, true)) {
    echo "  allowlist  present — this account can sign in.\n";
} else {
    echo "\n  !! NOT IN THE ALLOWLIST. This account still cannot sign in.\n";
    echo "     Add '{$key}' to \$ADMIN['allowlist'] in config/admin-config.php.\n";
    exit(2);
}
