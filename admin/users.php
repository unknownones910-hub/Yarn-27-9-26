<?php
/**
 * Admin accounts.
 *
 * A row here is only half the key: the address must ALSO appear in
 * config/admin-config.php. That file is not editable from the web on purpose —
 * it means a database compromise alone cannot mint an admin.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-bootstrap.php';
$admin = admin_guard('owner');

require YN_ROOT . '/config/admin-config.php';
$allowlist = $ADMIN['allowlist'] ?? [];

$errors = [];

if (is_post()) {
    csrf_verify();
    $action = post_str('action', 20);
    $id     = post_int('id');

    if ($action === 'create') {
        $email = post_str('email', 190);
        $key   = normalize_gmail($email);
        $name  = post_str('name', 120);
        $role  = post_str('role', 20);

        if ($key === null) {
            $errors[] = 'Admin accounts need a Gmail address.';
        }
        if (mb_strlen($name) < 2) {
            $errors[] = 'Enter their name.';
        }
        if (!in_array($role, ['owner', 'manager', 'staff'], true)) {
            $role = 'staff';
        }
        if ($key !== null && db_val('SELECT id FROM admin_users WHERE email_key = ?', [$key])) {
            $errors[] = 'That address already has an admin account.';
        }

        if (!$errors) {
            $newId = db_insert(
                'INSERT INTO admin_users (email, email_key, name, role, is_active) VALUES (?,?,?,?,1)',
                [trim($email), $key, $name, $role]
            );
            admin_log('admin_create', 'admin_user', $newId, $key . ' as ' . $role);
            flash('Account created. They cannot sign in until you add ' . $key
                . ' to the allowlist in config/admin-config.php.', 'warn');
        }
    }

    if ($action === 'toggle' && $id !== (int) $admin['id']) {
        db_exec('UPDATE admin_users SET is_active = 1 - is_active WHERE id = ?', [$id]);
        admin_log('admin_toggle', 'admin_user', $id);
        flash('Account updated.', 'success');
    }

    if ($action === 'role' && $id !== (int) $admin['id']) {
        $role = post_str('role', 20);
        if (in_array($role, ['owner', 'manager', 'staff'], true)) {
            db_exec('UPDATE admin_users SET role = ? WHERE id = ?', [$role, $id]);
            admin_log('admin_role', 'admin_user', $id, $role);
            flash('Role updated.', 'success');
        }
    }

    if ($action === 'delete' && $id !== (int) $admin['id']) {
        db_exec('DELETE FROM admin_users WHERE id = ?', [$id]);
        admin_log('admin_delete', 'admin_user', $id);
        flash('Account removed. Take the address out of the allowlist too.', 'warn');
    }

    if (!$errors) {
        redirect('admin/users.php');
    }
}

$users = db_all('SELECT * FROM admin_users ORDER BY role, name');

$pageTitle    = 'Admin users';
$adminSection = 'users';

require_once YN_ROOT . '/includes/admin-header.php';
?>

<div class="admin-head">
  <div class="min0"><h1>Admin users</h1>
    <p>Who can get into this panel.</p></div>
</div>

<div class="alert alert-info mb4">
  <?= icon('shield', 18) ?>
  <div>
    <strong>Two locks, on purpose.</strong> An account needs a row here <em>and</em> its address
    in <code>$ADMIN['allowlist']</code> inside <code>config/admin-config.php</code>. That file
    is not web-editable, so someone who gets into the database still cannot make themselves
    an admin.
  </div>
</div>

<?php if ($errors): ?>
  <div class="alert alert-error mb4"><?= icon('alert', 18) ?>
    <div><?= e(implode(' ', $errors)) ?></div></div>
<?php endif; ?>

<div class="panel panel--flush">
  <h2>Accounts</h2>
  <div class="scroll-x">
    <table class="admin-table" style="min-width:640px">
      <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Allowlisted</th>
        <th>Last sign-in</th><th>State</th><th></th></tr></thead>
      <tbody>
        <?php foreach ($users as $u): ?>
          <?php $listed = in_array((string) $u['email_key'], $allowlist, true); ?>
          <tr>
            <td><strong><?= e((string) $u['name']) ?></strong>
              <?php if ((int) $u['id'] === (int) $admin['id']): ?>
                <span class="pill pill-info">You</span><?php endif; ?></td>
            <td class="text-xs" style="overflow-wrap:anywhere"><?= e((string) $u['email']) ?></td>
            <td>
              <?php if ((int) $u['id'] === (int) $admin['id']): ?>
                <span class="pill pill-muted"><?= e((string) $u['role']) ?></span>
              <?php else: ?>
                <form method="post" action="<?= e(url('admin/users.php')) ?>">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="role">
                  <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                  <select class="select" name="role" onchange="this.form.submit()"
                          style="min-height:34px;padding:4px 28px 4px 8px;font-size:var(--fs-xs);width:auto">
                    <?php foreach (['staff', 'manager', 'owner'] as $r): ?>
                      <option value="<?= e($r) ?>" <?= $u['role'] === $r ? 'selected' : '' ?>>
                        <?= e($r) ?></option>
                    <?php endforeach; ?>
                  </select>
                </form>
              <?php endif; ?>
            </td>
            <td><?= $listed
                  ? '<span class="pill pill-ok">Yes</span>'
                  : '<span class="pill pill-bad">No — cannot sign in</span>' ?></td>
            <td class="text-xs muted"><?= $u['last_login_at']
                  ? e(time_ago((string) $u['last_login_at'])) : 'never' ?></td>
            <td><?= (int) $u['is_active'] === 1
                  ? '<span class="pill pill-ok">Active</span>'
                  : '<span class="pill pill-muted">Disabled</span>' ?></td>
            <td class="tight">
              <?php if ((int) $u['id'] !== (int) $admin['id']): ?>
                <div class="row gap2">
                  <form method="post" action="<?= e(url('admin/users.php')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                    <button class="btn-link" type="submit">
                      <?= (int) $u['is_active'] === 1 ? 'Disable' : 'Enable' ?></button>
                  </form>
                  <form method="post" action="<?= e(url('admin/users.php')) ?>"
                        data-confirm="Remove this admin account?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                    <button class="btn-link is-danger" type="submit">Remove</button>
                  </form>
                </div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="panel">
  <h2>Add an admin</h2>
  <form method="post" action="<?= e(url('admin/users.php')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create">
    <div class="field-row field-row--3">
      <div class="field">
        <label class="label" for="name">Name</label>
        <input class="input" id="name" name="name" required>
      </div>
      <div class="field">
        <label class="label" for="email">Gmail address</label>
        <input class="input" id="email" name="email" type="email" required placeholder="them@gmail.com">
      </div>
      <div class="field">
        <label class="label" for="role">Role</label>
        <select class="select" id="role" name="role">
          <option value="staff">Staff — orders, inventory, messages</option>
          <option value="manager">Manager — everything except admin users</option>
          <option value="owner">Owner — full access</option>
        </select>
      </div>
    </div>
    <button class="btn" type="submit">Create account</button>
    <p class="help mt3">
      Then add their normalized Gmail to <code>config/admin-config.php</code> and upload it.
      Until you do, they will be turned away at the sign-in screen.
    </p>
  </form>
</div>

<?php require_once YN_ROOT . '/includes/admin-footer.php'; ?>
