<?php
/**
 * Discount codes.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-bootstrap.php';
require_once YN_ROOT . '/includes/coupon.php';   // coupon_label() in the list
$admin = admin_guard();

$editId = get_int('edit');
$errors = [];

if (is_post()) {
    csrf_verify();
    $action = post_str('action', 20);
    $id     = post_int('id');

    if ($action === 'toggle') {
        db_exec('UPDATE coupons SET is_active = 1 - is_active WHERE id = ?', [$id]);
        admin_log('coupon_toggle', 'coupon', $id);
        flash('Coupon updated.', 'success');
        redirect('admin/coupons.php');
    }

    if ($action === 'delete' && admin_can('manager')) {
        $used = (int) db_val('SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id = ?', [$id]);
        if ($used > 0) {
            db_exec('UPDATE coupons SET is_active = 0 WHERE id = ?', [$id]);
            flash('This code has been used ' . $used . ' time' . ($used === 1 ? '' : 's')
                . ', so it was deactivated rather than deleted — the redemption history stays intact.',
                'warn');
        } else {
            db_exec('DELETE FROM coupons WHERE id = ?', [$id]);
            flash('Coupon deleted.', 'success');
        }
        admin_log('coupon_delete', 'coupon', $id);
        redirect('admin/coupons.php');
    }

    if ($action === 'save') {
        $code = strtoupper(preg_replace('/[^A-Za-z0-9_-]/', '', post_str('code', 40)) ?? '');
        $type = post_str('type', 20);

        if (mb_strlen($code) < 3) {
            $errors['code'] = 'Codes need at least 3 characters, letters and numbers only.';
        }
        if (!in_array($type, ['percent', 'fixed', 'free_shipping'], true)) {
            $errors['type'] = 'Choose a discount type.';
        }

        $value = (float) post_str('value', 12);
        if ($type === 'percent' && ($value <= 0 || $value > 90)) {
            $errors['value'] = 'A percentage discount must be between 1 and 90.';
        }
        if ($type === 'fixed' && $value <= 0) {
            $errors['value'] = 'Enter the amount to take off.';
        }

        $clash = db_val('SELECT id FROM coupons WHERE code = ? AND id <> ?', [$code, $id]);
        if ($clash) {
            $errors['code'] = 'That code already exists.';
        }

        if (!$errors) {
            $row = [
                'code'               => $code,
                'description'        => post_str('description', 180) ?: null,
                'type'               => $type,
                'value'              => $type === 'free_shipping' ? 0 : $value,
                'min_order'          => (float) post_str('min_order', 12),
                'max_discount'       => post_str('max_discount', 12) !== ''
                                        ? (float) post_str('max_discount', 12) : null,
                'usage_limit'        => post_int('usage_limit') ?: null,
                'per_customer_limit' => max(0, post_int('per_customer_limit', 1)),
                'first_order_only'   => empty($_POST['first_order_only']) ? 0 : 1,
                'starts_at'          => post_str('starts_at', 20) ?: null,
                'ends_at'            => post_str('ends_at', 20) ?: null,
                'is_active'          => empty($_POST['is_active']) ? 0 : 1,
            ];

            if ($id > 0) {
                $set = implode(', ', array_map(static fn($k) => "{$k} = ?", array_keys($row)));
                db_exec("UPDATE coupons SET {$set} WHERE id = ?",
                        array_merge(array_values($row), [$id]));
                admin_log('coupon_update', 'coupon', $id, $code);
            } else {
                $cols = implode(',', array_keys($row));
                $ph   = implode(',', array_fill(0, count($row), '?'));
                $id = db_insert("INSERT INTO coupons ({$cols}) VALUES ({$ph})", array_values($row));
                admin_log('coupon_create', 'coupon', $id, $code);
            }
            flash('Coupon saved.', 'success');
            redirect('admin/coupons.php');
        }
        $editId = $id ?: -1;
    }
}

$coupons = db_all(
    'SELECT c.*, (SELECT COUNT(*) FROM coupon_redemptions r WHERE r.coupon_id = c.id) redeemed,
            (SELECT COALESCE(SUM(r.amount),0) FROM coupon_redemptions r WHERE r.coupon_id = c.id) given
     FROM coupons c ORDER BY c.is_active DESC, c.id DESC'
);

$edit = $editId > 0 ? db_row('SELECT * FROM coupons WHERE id = ?', [$editId]) : null;
$ev   = static fn(string $k, $d = '') => e((string) ($_POST[$k] ?? $edit[$k] ?? $d));

$pageTitle    = 'Coupons';
$adminSection = 'coupons';

require_once YN_ROOT . '/includes/admin-header.php';
?>

<div class="admin-head">
  <div class="min0"><h1>Coupons</h1><p><?= count($coupons) ?> code<?= count($coupons) === 1 ? '' : 's' ?></p></div>
  <?php if ($editId === 0): ?>
    <a class="btn btn-sm" href="<?= e(url('admin/coupons.php?edit=-1')) ?>">
      <?= icon('plus', 15) ?> New coupon</a>
  <?php endif; ?>
</div>

<?php if ($editId !== 0): ?>
  <div class="panel">
    <h2><?= $edit ? 'Edit ' . e((string) $edit['code']) : 'New coupon' ?></h2>

    <?php if ($errors): ?>
      <div class="alert alert-error mb4"><?= icon('alert', 18) ?>
        <div><?= e(implode(' ', $errors)) ?></div></div>
    <?php endif; ?>

    <form method="post" action="<?= e(url('admin/coupons.php')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <?php if ($edit): ?><input type="hidden" name="id" value="<?= (int) $edit['id'] ?>"><?php endif; ?>

      <div class="field-row field-row--2">
        <div class="field">
          <label class="label" for="code">Code <span class="req">*</span> <?= help_tip('What the customer types at checkout, e.g. WELCOME10. Not case sensitive. Keep it short and easy to say out loud.', 'Code') ?></label>
          <input class="input" id="code" name="code" required value="<?= $ev('code') ?>"
                 style="text-transform:uppercase" placeholder="WELCOME10">
        </div>
        <div class="field">
          <label class="label" for="description">What it is for <?= help_tip('A note for you, not the customer. It only appears in this admin list so you remember what a code was for.', 'What it is for') ?></label>
          <input class="input" id="description" name="description" value="<?= $ev('description') ?>"
                 placeholder="10% off your first order">
        </div>
      </div>

      <div class="field-row field-row--3">
        <div class="field">
          <label class="label" for="type">Type <span class="req">*</span> <?= help_tip('Percentage takes a share off the order. Fixed takes a set number of rupees. Free shipping waives the delivery charge only.', 'Type') ?></label>
          <select class="select" id="type" name="type">
            <?php foreach (['percent' => 'Percentage off', 'fixed' => 'Fixed amount off',
                            'free_shipping' => 'Free delivery'] as $k => $l): ?>
              <option value="<?= e($k) ?>" <?= ($_POST['type'] ?? $edit['type'] ?? '') === $k ? 'selected' : '' ?>>
                <?= e($l) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="label" for="value">Value <?= help_tip('The number that goes with the type above: 10 for 10% off, or 200 for 200 rupees off.', 'Value') ?></label>
          <input class="input" id="value" name="value" type="number" step="0.01" min="0"
                 value="<?= $ev('value') ?>">
          <span class="help">Percent (e.g. 10) or rupees. Ignored for free delivery.</span>
        </div>
        <div class="field">
          <label class="label" for="max_discount">Cap the discount at (₹) <?= help_tip('Upper limit in rupees on a percentage discount, so 20% off a large order cannot cost you more than you intended. Leave blank for no cap.', 'Cap the discount at (₹)') ?></label>
          <input class="input" id="max_discount" name="max_discount" type="number" step="0.01" min="0"
                 value="<?= $ev('max_discount') ?>" placeholder="No cap">
        </div>
      </div>

      <div class="field-row field-row--3">
        <div class="field">
          <label class="label" for="min_order">Minimum order (₹) <?= help_tip('The cart must reach this amount before the code works. Leave blank to allow any order size.', 'Minimum order (₹)') ?></label>
          <input class="input" id="min_order" name="min_order" type="number" step="0.01" min="0"
                 value="<?= $ev('min_order', '0') ?>">
        </div>
        <div class="field">
          <label class="label" for="usage_limit">Total uses allowed <?= help_tip('How many times this code can be used in total, across all customers. Leave blank for unlimited.', 'Total uses allowed') ?></label>
          <input class="input" id="usage_limit" name="usage_limit" type="number" min="0"
                 value="<?= $ev('usage_limit') ?>" placeholder="Unlimited">
        </div>
        <div class="field">
          <label class="label" for="per_customer_limit">Uses per customer <?= help_tip('How many times one customer can use it. Set to 1 for a genuine one-per-person offer.', 'Uses per customer') ?></label>
          <input class="input" id="per_customer_limit" name="per_customer_limit" type="number" min="0"
                 value="<?= $ev('per_customer_limit', '1') ?>">
          <span class="help">0 means unlimited.</span>
        </div>
      </div>

      <div class="field-row field-row--2">
        <div class="field">
          <label class="label" for="starts_at">Starts <?= help_tip('The code does nothing before this moment. Leave blank to start straight away.', 'Starts') ?></label>
          <input class="input" id="starts_at" name="starts_at" type="datetime-local"
                 value="<?= e(($edit['starts_at'] ?? null) ? date('Y-m-d\TH:i', strtotime((string) $edit['starts_at'])) : '') ?>">
        </div>
        <div class="field">
          <label class="label" for="ends_at">Ends <?= help_tip('The code stops working after this moment. Leave blank and it never expires.', 'Ends') ?></label>
          <input class="input" id="ends_at" name="ends_at" type="datetime-local"
                 value="<?= e(($edit['ends_at'] ?? null) ? date('Y-m-d\TH:i', strtotime((string) $edit['ends_at'])) : '') ?>">
        </div>
      </div>

      <label class="check">
        <input type="checkbox" name="first_order_only" value="1"
               <?= ($_POST['first_order_only'] ?? $edit['first_order_only'] ?? 0) ? 'checked' : '' ?>>
        <span>First order only</span>
      </label>
      <label class="check">
        <input type="checkbox" name="is_active" value="1"
               <?= ($_POST['is_active'] ?? $edit['is_active'] ?? 1) ? 'checked' : '' ?>>
        <span>Active</span>
      </label>

      <div class="row mt4">
        <button class="btn" type="submit">Save coupon</button>
        <a class="btn btn-soft" href="<?= e(url('admin/coupons.php')) ?>">Cancel</a>
      </div>
    </form>
  </div>
<?php endif; ?>

<div class="panel panel--flush">
  <?php if (!$coupons): ?>
    <div class="admin-empty"><?= icon('gift', 40) ?><p>No coupons yet.</p></div>
  <?php else: ?>
    <div class="scroll-x">
      <table class="admin-table">
        <thead><tr><th>Code</th><th>Discount</th><th>Conditions</th>
          <th class="num">Used</th><th class="num">Given away</th><th>State</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($coupons as $c): ?>
            <?php
            $expired = $c['ends_at'] && strtotime((string) $c['ends_at']) < time();
            $spent   = $c['usage_limit'] !== null && (int) $c['redeemed'] >= (int) $c['usage_limit'];
            ?>
            <tr>
              <td><strong style="letter-spacing:.04em"><?= e((string) $c['code']) ?></strong>
                <?php if ($c['description']): ?>
                  <br><span class="text-xs muted"><?= e((string) $c['description']) ?></span><?php endif; ?></td>
              <td><?= e(coupon_label($c)) ?>
                <?php if ($c['max_discount']): ?>
                  <br><span class="text-xs muted">max <?= e(money((float) $c['max_discount'])) ?></span>
                <?php endif; ?></td>
              <td class="text-xs muted">
                <?php if ((float) $c['min_order'] > 0): ?>
                  min <?= e(money((float) $c['min_order'])) ?><br><?php endif; ?>
                <?php if ((int) $c['first_order_only'] === 1): ?>first order only<br><?php endif; ?>
                <?php if ($c['ends_at']): ?>
                  until <?= e(fmt_date((string) $c['ends_at'])) ?><?php endif; ?>
              </td>
              <td class="num"><?= (int) $c['redeemed'] ?><?= $c['usage_limit'] !== null
                    ? ' / ' . (int) $c['usage_limit'] : '' ?></td>
              <td class="num"><?= e(money((float) $c['given'])) ?></td>
              <td>
                <?php if ($expired): ?><span class="pill pill-muted">Expired</span>
                <?php elseif ($spent): ?><span class="pill pill-muted">Used up</span>
                <?php elseif ((int) $c['is_active'] === 1): ?><span class="pill pill-ok">Active</span>
                <?php else: ?><span class="pill pill-muted">Off</span><?php endif; ?>
              </td>
              <td class="tight">
                <div class="row gap2">
                  <a class="btn-link" href="<?= e(url('admin/coupons.php?edit=' . (int) $c['id'])) ?>">Edit</a>
                  <form method="post" action="<?= e(url('admin/coupons.php')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle">
                    <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                    <button class="btn-link" type="submit">
                      <?= (int) $c['is_active'] === 1 ? 'Turn off' : 'Turn on' ?></button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php require_once YN_ROOT . '/includes/admin-footer.php'; ?>
