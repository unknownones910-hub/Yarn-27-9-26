<?php
/**
 * Profile.
 *
 * Email is deliberately read-only. Changing the address on a passwordless
 * account is an account-takeover primitive, so it needs OTP confirmation on
 * both the old and the new address — that flow is not built yet, and a
 * half-built version would be worse than none. Support can do it by hand.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/account-nav.php';

$customer = require_login();
$cid      = (int) $customer['id'];

$errors = [];

if (is_post()) {
    csrf_verify();

    $name  = post_str('full_name', 120);
    $phone = post_str('phone', 15);
    $bday  = post_str('birthday', 10);
    $skill = post_str('skill_level', 20);

    $crafts = array_values(array_intersect(
        (array) ($_POST['craft_prefs'] ?? []),
        ['crochet', 'knitting', 'amigurumi', 'macrame', 'weaving']
    ));

    if ($name !== '' && mb_strlen($name) < 2) {
        $errors['full_name'] = 'Please enter your full name.';
    }

    $phoneNorm = null;
    if ($phone !== '') {
        $phoneNorm = normalize_phone($phone);
        if ($phoneNorm === null) {
            $errors['phone'] = 'Enter a valid 10-digit Indian mobile number.';
        }
    }

    $bdayVal = null;
    if ($bday !== '') {
        $ts = strtotime($bday);
        if ($ts === false || $ts > time()) {
            $errors['birthday'] = 'Please enter a valid date in the past.';
        } else {
            $bdayVal = date('Y-m-d', $ts);
        }
    }

    if (!in_array($skill, ['beginner', 'intermediate', 'advanced'], true)) {
        $skill = null;
    }

    if (!$errors) {
        db_exec(
            'UPDATE customers
             SET full_name = ?, phone = ?, birthday = ?, skill_level = ?, craft_prefs = ?
             WHERE id = ?',
            [
                $name !== '' ? $name : null,
                $phoneNorm,
                $bdayVal,
                $skill,
                $crafts ? implode(',', $crafts) : null,
                $cid,
            ]
        );
        flash('Profile updated.', 'success');
        redirect('account/profile.php');
    }
}

$customer   = db_row('SELECT * FROM customers WHERE id = ?', [$cid]) ?? $customer;
$myCrafts   = array_filter(explode(',', (string) $customer['craft_prefs']));
$pageTitle  = 'My profile';
$noindex    = true;

require_once YN_ROOT . '/includes/header.php';
?>

<div class="container section-sm">
  <h1 class="mb5">Profile</h1>

  <div class="account-layout">
    <?php account_nav('profile'); ?>

    <div class="min0">
      <div class="card">
        <form method="post" action="<?= e(url('account/profile.php')) ?>">
          <?= csrf_field() ?>

          <div class="field">
            <label class="label" for="email">Email address</label>
            <input class="input" id="email" type="email" value="<?= e((string) $customer['email']) ?>"
                   readonly style="background:var(--sand);cursor:not-allowed">
            <span class="help">
              This is how you sign in, so it cannot be changed here.
              <a href="<?= e(url('contact.php')) ?>">Contact us</a> and we will move your account
              and its order history across.
            </span>
          </div>

          <div class="field <?= isset($errors['full_name']) ? 'has-error' : '' ?>">
            <label class="label" for="full_name">Full name</label>
            <input class="input" id="full_name"<?= field_invalid($errors, 'full_name') ?> name="full_name" autocomplete="name"
                   value="<?= e((string) $customer['full_name']) ?>">
            <span class="help">Used on parcels and invoices.</span>
            <?= field_error($errors, 'full_name') ?>
          </div>

          <div class="field <?= isset($errors['phone']) ? 'has-error' : '' ?>">
            <label class="label" for="phone">Mobile number</label>
            <div class="input-group">
              <span class="addon">+91</span>
              <input class="input" id="phone"<?= field_invalid($errors, 'phone') ?> name="phone" inputmode="numeric" maxlength="10"
                     autocomplete="tel-national" value="<?= e((string) $customer['phone']) ?>">
            </div>
            <span class="help">Couriers call this number before delivery.</span>
            <?= field_error($errors, 'phone') ?>
          </div>

          <div class="field <?= isset($errors['birthday']) ? 'has-error' : '' ?>">
            <label class="label" for="birthday">Birthday <span class="opt">(optional)</span></label>
            <input class="input" id="birthday"<?= field_invalid($errors, 'birthday') ?> name="birthday" type="date" style="max-width:220px"
                   value="<?= e((string) $customer['birthday']) ?>" max="<?= date('Y-m-d') ?>">
            <span class="help">We send a small discount on the day. Nothing else.</span>
            <?= field_error($errors, 'birthday') ?>
          </div>

          <div class="field">
            <span class="label">What do you make?</span>
            <span class="help mb3" style="margin-top:0">So we stop showing you the wrong yarn.</span>
            <div class="row gap3">
              <?php foreach (['crochet' => 'Crochet', 'knitting' => 'Knitting', 'amigurumi' => 'Amigurumi',
                              'macrame' => 'Macramé', 'weaving' => 'Weaving'] as $val => $lbl): ?>
                <label class="check" style="min-height:auto">
                  <input type="checkbox" name="craft_prefs[]" value="<?= e($val) ?>"
                         <?= in_array($val, $myCrafts, true) ? 'checked' : '' ?>>
                  <span><?= e($lbl) ?></span>
                </label>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="field">
            <label class="label" for="skill_level">Skill level <span class="opt">(optional)</span></label>
            <select class="select" id="skill_level" name="skill_level" style="max-width:260px">
              <option value="">Prefer not to say</option>
              <?php foreach (['beginner' => 'Just starting out', 'intermediate' => 'Comfortable',
                              'advanced' => 'Very experienced'] as $val => $lbl): ?>
                <option value="<?= e($val) ?>" <?= $customer['skill_level'] === $val ? 'selected' : '' ?>>
                  <?= e($lbl) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <button class="btn mt4" type="submit">Save changes</button>
        </form>
      </div>

      <div class="card mt5">
        <h2 style="font-family:var(--font-sans);font-size:1rem">Security</h2>
        <p class="text-sm soft">
          Your account has no password — we email a one-time code instead, so there is
          nothing to leak or reuse.
        </p>
        <form method="post" action="<?= e(url('logout.php')) ?>">
          <?= csrf_field() ?>
          <input type="hidden" name="everywhere" value="1">
          <button class="btn btn-sm btn-ghost" type="submit">Sign out on all devices</button>
        </form>
      </div>

      <div class="card mt5">
        <h2 style="font-family:var(--font-sans);font-size:1rem">Your data</h2>
        <p class="text-sm soft mb3">
          You can close your account at any time. We keep only the invoice records that
          tax and consumer-protection law require us to keep.
        </p>
        <a class="btn-link is-danger" href="<?= e(url('account/delete.php')) ?>">
          <?= icon('trash', 14) ?> Delete my account</a>
      </div>
    </div>
  </div>
</div>

<?php require_once YN_ROOT . '/includes/footer.php'; ?>
