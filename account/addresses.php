<?php
/**
 * Address book.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/account-nav.php';
require_once YN_ROOT . '/includes/address.php';

$customer = require_login();
$cid      = (int) $customer['id'];

$errors = [];
$input  = [];
$editId = get_int('edit');

if (is_post()) {
    csrf_verify();
    $action = post_str('action', 20);

    if ($action === 'delete') {
        address_delete(post_int('id'), $cid);
        flash('Address removed.', 'success');
        redirect('account/addresses.php');
    }

    if ($action === 'default') {
        $id = post_int('id');
        if (address_get($id, $cid)) {
            db_exec('UPDATE customer_addresses SET is_default = 0 WHERE customer_id = ?', [$cid]);
            db_exec('UPDATE customer_addresses SET is_default = 1 WHERE id = ? AND customer_id = ?',
                    [$id, $cid]);
            flash('Default address updated.', 'success');
        }
        redirect('account/addresses.php');
    }

    if ($action === 'save') {
        $id = post_int('id') ?: null;
        $v  = address_validate($_POST);

        if ($v['ok']) {
            address_save($cid, $v['data'], $id, !empty($_POST['make_default']));
            flash($id ? 'Address updated.' : 'Address saved.', 'success');
            redirect('account/addresses.php');
        }

        $errors = $v['errors'];
        $input  = $v['data'];
        $editId = $id ?: 0;
    }
}

$list = address_list($cid);

// Pre-fill the form when editing.
if ($editId > 0 && !$input) {
    $existing = address_get($editId, $cid);
    if ($existing) {
        $input = $existing;
    } else {
        $editId = 0;
    }
}

$showForm  = $editId > 0 || $errors || !$list || get_str('add') === '1';
$pageTitle = 'My addresses';
$noindex   = true;

require_once YN_ROOT . '/includes/header.php';
?>

<div class="container section-sm">
  <h1 class="mb5">Addresses</h1>

  <div class="account-layout">
    <?php account_nav('addresses'); ?>

    <div class="min0">

      <?php if ($list): ?>
        <div class="grid grid-2 mb5">
          <?php foreach ($list as $a): ?>
            <div class="card">
              <div class="row row-between mb2">
                <strong class="min0"><?= e($a['full_name']) ?></strong>
                <span class="pill pill-muted" style="flex:none"><?= e(ucfirst($a['label'])) ?></span>
              </div>
              <?php if ((int) $a['is_default'] === 1): ?>
                <span class="pill pill-ok mb3" style="display:inline-flex">
                  <?= icon('check', 12) ?> Default</span>
              <?php endif; ?>
              <p class="text-sm soft mb1"><?= e(address_one_line($a)) ?></p>
              <p class="text-sm muted mb0"><?= e($a['phone']) ?></p>

              <div class="order-card__actions mt4">
                <a class="btn-link" href="<?= e(url('account/addresses.php?edit=' . (int) $a['id'])) ?>">
                  <?= icon('edit', 14) ?> Edit</a>

                <?php if ((int) $a['is_default'] !== 1): ?>
                  <form method="post" action="<?= e(url('account/addresses.php')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="default">
                    <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                    <button class="btn-link" type="submit">Make default</button>
                  </form>

                  <form method="post" action="<?= e(url('account/addresses.php')) ?>"
                        onsubmit="return confirm('Remove this address?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $a['id'] ?>">
                    <button class="btn-link is-danger" type="submit"><?= icon('trash', 14) ?> Remove</button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (!$showForm): ?>
        <a class="btn" href="<?= e(url('account/addresses.php?add=1')) ?>">
          <?= icon('plus', 16) ?> Add a new address</a>

      <?php else: ?>
        <div class="card">
          <h2 style="font-family:var(--font-sans);font-size:1.0625rem">
            <?= $editId > 0 ? 'Edit address' : 'Add an address' ?></h2>

          <form method="post" action="<?= e(url('account/addresses.php')) ?>" class="mt4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="save">
            <?php if ($editId > 0): ?>
              <input type="hidden" name="id" value="<?= $editId ?>">
            <?php endif; ?>

            <div class="field <?= isset($errors['full_name']) ? 'has-error' : '' ?>">
              <label class="label" for="full_name">Full name <span class="req">*</span></label>
              <input class="input" id="full_name"<?= field_invalid($errors, 'full_name') ?> name="full_name" required autocomplete="name"
                     value="<?= e($input['full_name'] ?? $customer['full_name'] ?? '') ?>">
              <?= field_error($errors, 'full_name') ?>
            </div>

            <div class="field <?= isset($errors['phone']) ? 'has-error' : '' ?>">
              <label class="label" for="phone">Mobile number <span class="req">*</span></label>
              <div class="input-group">
                <span class="addon">+91</span>
                <input class="input" id="phone"<?= field_invalid($errors, 'phone') ?> name="phone" required inputmode="numeric" maxlength="10"
                       autocomplete="tel-national" value="<?= e($input['phone'] ?? $customer['phone'] ?? '') ?>">
              </div>
              <?= field_error($errors, 'phone') ?>
            </div>

            <div class="field <?= isset($errors['pincode']) ? 'has-error' : '' ?>">
              <label class="label" for="pincode">Pincode <span class="req">*</span></label>
              <input class="input" id="pincode"<?= field_invalid($errors, 'pincode') ?> name="pincode" required inputmode="numeric" maxlength="6"
                     autocomplete="postal-code" data-pincode-fill style="max-width:180px"
                     value="<?= e($input['pincode'] ?? '') ?>">
              <span class="help" data-pincode-note>We fill in city and state automatically.</span>
              <?= field_error($errors, 'pincode') ?>
            </div>

            <div class="field <?= isset($errors['line1']) ? 'has-error' : '' ?>">
              <label class="label" for="line1">House / flat no. and street <span class="req">*</span></label>
              <input class="input" id="line1"<?= field_invalid($errors, 'line1') ?> name="line1" required autocomplete="address-line1"
                     value="<?= e($input['line1'] ?? '') ?>">
              <?= field_error($errors, 'line1') ?>
            </div>

            <div class="field">
              <label class="label" for="line2">Area / locality <span class="opt">(optional)</span></label>
              <input class="input" id="line2" name="line2" autocomplete="address-line2"
                     value="<?= e($input['line2'] ?? '') ?>">
            </div>

            <div class="field">
              <label class="label" for="landmark">Landmark <span class="opt">(optional)</span></label>
              <input class="input" id="landmark" name="landmark" value="<?= e($input['landmark'] ?? '') ?>">
            </div>

            <div class="grid grid-2" style="gap:var(--s4)">
              <div class="field <?= isset($errors['city']) ? 'has-error' : '' ?>">
                <label class="label" for="city">City <span class="req">*</span></label>
                <input class="input" id="city"<?= field_invalid($errors, 'city') ?> name="city" required data-city-field
                       autocomplete="address-level2" value="<?= e($input['city'] ?? '') ?>">
                <?= field_error($errors, 'city') ?>
              </div>
              <div class="field <?= isset($errors['state']) ? 'has-error' : '' ?>">
                <label class="label" for="state">State <span class="req">*</span></label>
                <select class="select" id="state"<?= field_invalid($errors, 'state') ?> name="state" required data-state-field>
                  <option value="">Choose your state</option>
                  <?php foreach (india_states() as $s): ?>
                    <option value="<?= e($s) ?>" <?= ($input['state'] ?? '') === $s ? 'selected' : '' ?>>
                      <?= e($s) ?></option>
                  <?php endforeach; ?>
                </select>
                <?= field_error($errors, 'state') ?>
              </div>
            </div>

            <div class="field">
              <span class="label">Address type</span>
              <div class="row gap3">
                <?php foreach (['home' => 'Home', 'work' => 'Work', 'other' => 'Other'] as $val => $lbl): ?>
                  <label class="check" style="min-height:auto">
                    <input type="radio" name="label" value="<?= e($val) ?>"
                           <?= ($input['label'] ?? 'home') === $val ? 'checked' : '' ?>>
                    <span><?= e($lbl) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>

            <label class="check">
              <input type="checkbox" name="make_default" value="1"
                     <?= !empty($input['is_default']) ? 'checked' : '' ?>>
              <span>Make this my default address</span>
            </label>

            <div class="row mt4">
              <button class="btn" type="submit"><?= $editId > 0 ? 'Save changes' : 'Save address' ?></button>
              <?php if ($list): ?>
                <a class="btn btn-soft" href="<?= e(url('account/addresses.php')) ?>">Cancel</a>
              <?php endif; ?>
            </div>
          </form>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require_once YN_ROOT . '/includes/footer.php'; ?>
