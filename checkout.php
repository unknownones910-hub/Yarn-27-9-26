<?php
/**
 * Checkout — one page, three steps.
 *
 *   1  Contact   Gmail + OTP (inline, no page hop). Doubles as order verification.
 *   2  Delivery  address, with saved addresses for returning customers
 *   3  Review    server-recomputed totals, then pay
 *
 * Browsing and the cart need no account; identity is only required here,
 * where we are about to take money and ship something.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/pricing.php';
require_once YN_ROOT . '/includes/address.php';
require_once YN_ROOT . '/includes/otp.php';

// ---- the cart must be viable ------------------------------------------
$lines = cart_lines();
if (!$lines) {
    flash('Your cart is empty.', 'warn');
    redirect('cart.php');
}

$totals = pricing_totals(['lines' => $lines]);
if ($totals['has_issues']) {
    flash('Some items are no longer available. Please review your cart.', 'error');
    redirect('cart.php');
}

$errors    = [];
$formError = '';
$notice    = '';
$devCode   = $_SESSION['co_dev_code'] ?? '';
$addrInput = $_SESSION['checkout_address'] ?? [];

// ---- POST -------------------------------------------------------------
if (is_post()) {
    csrf_verify();
    $action = post_str('action', 24);

    // ---------- step 1: request a code --------------------------------
    if ($action === 'send_code' && !is_logged_in()) {
        $typed = post_str('email', 190);
        $key   = normalize_gmail($typed);

        if ($key === null) {
            $formError = str_contains($typed, '@') && !str_ends_with(strtolower(trim($typed)), '@gmail.com')
                ? 'We accept Gmail addresses only. Please use your @gmail.com address.'
                : 'That does not look like a valid Gmail address.';
        } else {
            $res = otp_issue($key, trim($typed), 'checkout');
            if ($res['ok']) {
                $_SESSION['co_email_key'] = $key;
                $_SESSION['co_email']     = trim($typed);
                if (!empty($res['dev_code'])) {
                    $_SESSION['co_dev_code'] = $res['dev_code'];
                    $devCode = $res['dev_code'];
                }
                $notice = 'Code sent to ' . mask_email(trim($typed));
            } else {
                $formError = $res['error'] ?? 'Could not send a code right now.';
            }
        }
    }

    // ---------- step 1: verify ----------------------------------------
    if ($action === 'verify_code' && !is_logged_in()) {
        $key   = $_SESSION['co_email_key'] ?? '';
        $typed = $_SESSION['co_email'] ?? '';

        if ($key === '') {
            $formError = 'Please request a code first.';
        } else {
            $res = otp_verify($key, post_str('code', 10), 'checkout');
            if ($res['ok']) {
                $customer = customer_upsert($key, $typed);
                if (!$customer || ($customer['status'] ?? '') === 'blocked') {
                    $formError = 'We could not verify that address. Please contact us.';
                } else {
                    unset($_SESSION['co_email_key'], $_SESSION['co_email'], $_SESSION['co_dev_code']);
                    login_customer((int) $customer['id'], true);
                    // Cart merged on login — re-read it and bounce to step 2.
                    redirect('checkout.php');
                }
            } else {
                $formError = $res['error'] ?? 'That code is not right.';
            }
        }
    }

    if ($action === 'change_email' && !is_logged_in()) {
        unset($_SESSION['co_email_key'], $_SESSION['co_email'], $_SESSION['co_dev_code']);
        $devCode = '';
    }

    // ---------- step 2: address ---------------------------------------
    if ($action === 'address' && is_logged_in()) {
        $savedId = post_int('saved_address_id');

        if ($savedId > 0) {
            $a = address_get($savedId, customer_id());
            if ($a) {
                $_SESSION['checkout_address']    = $a;
                $_SESSION['checkout_address_id'] = $savedId;
                redirect('checkout.php');
            }
            $formError = 'That address could not be found.';
        } else {
            $v = address_validate($_POST);
            if ($v['ok']) {
                $id = address_save(customer_id(), $v['data'], null, !empty($_POST['make_default']));
                $_SESSION['checkout_address']    = $v['data'] + ['id' => $id];
                $_SESSION['checkout_address_id'] = $id;
                redirect('checkout.php');
            }
            $errors    = $v['errors'];
            $addrInput = $v['data'];
        }
    }

    if ($action === 'edit_address') {
        unset($_SESSION['checkout_address'], $_SESSION['checkout_address_id']);
        redirect('checkout.php');
    }
}

// ---- which step are we on? --------------------------------------------
$address = $_SESSION['checkout_address'] ?? null;
$step    = 1;
if (is_logged_in()) {
    $step = $address ? 3 : 2;
}

$customer  = current_customer();
$savedList = is_logged_in() ? address_list(customer_id()) : [];
$pendingOtp = !is_logged_in() && !empty($_SESSION['co_email_key']);

// Totals again — the cart may have been merged on sign-in.
$lines  = cart_lines();
$totals = pricing_totals(['lines' => $lines]);

$pageTitle  = 'Checkout';
$noindex    = true;
$bodyClass  = 'is-checkout';

require_once YN_ROOT . '/includes/header.php';
?>

<div class="container section-sm">

  <!-- step indicator -->
  <ol class="steps mb6" aria-label="Checkout progress">
    <?php foreach ([1 => 'Contact', 2 => 'Delivery', 3 => 'Review & pay'] as $n => $label): ?>
      <li class="<?= $step > $n ? 'done' : ($step === $n ? 'current' : '') ?>">
        <span class="steps__num"><?= $step > $n ? icon('check', 14) : $n ?></span>
        <span class="steps__label"><?= e($label) ?></span>
      </li>
    <?php endforeach; ?>
  </ol>

  <?php if ($formError !== ''): ?>
    <div class="alert alert-error mb5" role="alert"><?= icon('alert', 18) ?><div><?= e($formError) ?></div></div>
  <?php endif; ?>
  <?php if ($notice !== ''): ?>
    <div class="alert alert-success mb5" role="status"><?= icon('check-circle', 18) ?><div><?= e($notice) ?></div></div>
  <?php endif; ?>

  <div class="checkout-layout">
    <div class="min0">

      <!-- ======================================== 1. contact -->
      <section class="card mb4">
        <div class="row row-between mb3">
          <h2 style="font-family:var(--font-sans);font-size:1.0625rem;margin:0">1 · Contact</h2>
          <?php if (is_logged_in()): ?><span class="pill pill-ok"><?= icon('check', 13) ?> Verified</span><?php endif; ?>
        </div>

        <?php if (is_logged_in()): ?>
          <p class="mb0 text-sm"><strong><?= e($customer['email']) ?></strong></p>
          <p class="text-xs muted mb0">Order updates go to this address.</p>

        <?php elseif ($pendingOtp): ?>
          <?php if ($devCode !== '' && is_local_dev()): ?>
            <div class="alert alert-warn mb4"><?= icon('info', 18) ?>
              <div><strong>Local development</strong> — no email sent. Code:
                <strong style="font:700 18px/1 'Courier New',monospace;letter-spacing:3px;color:var(--clay)">
                  <?= e($devCode) ?></strong></div></div>
          <?php endif; ?>

          <p class="text-sm soft">
            We sent a 6-digit code to <strong><?= e(mask_email((string) $_SESSION['co_email'])) ?></strong>.
          </p>

          <form method="post" action="<?= e(url('checkout.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="verify_code">
            <input type="hidden" name="code" data-otp-value>
            <div class="otp mt4" data-otp="autosubmit">
              <?php for ($i = 0; $i < 6; $i++): ?><input type="text" <?= $i === 0 ? 'autofocus' : '' ?>><?php endfor; ?>
            </div>
            <button class="btn btn-block mt4" type="submit">Verify &amp; continue</button>
          </form>

          <form method="post" action="<?= e(url('checkout.php')) ?>" class="center mt3" data-no-loading>
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="change_email">
            <button class="btn-link" type="submit">Use a different address</button>
          </form>

        <?php else: ?>
          <p class="text-sm soft">
            We verify your email with a one-time code — it is how you track the order later.
            You can set a password afterwards, so next time you just sign in.
          </p>
          <form method="post" action="<?= e(url('checkout.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="send_code">
            <div class="field">
              <label class="label" for="email">Your Gmail address <span class="req">*</span></label>
              <input class="input" type="email" id="email" name="email" required autofocus
                     autocomplete="email" placeholder="you@gmail.com">
            </div>
            <button class="btn btn-block" type="submit">Send code</button>
          </form>
        <?php endif; ?>
      </section>

      <!-- ======================================= 2. delivery -->
      <section class="card mb4 <?= $step < 2 ? 'is-locked' : '' ?>">
        <div class="row row-between mb3">
          <h2 style="font-family:var(--font-sans);font-size:1.0625rem;margin:0">2 · Delivery address</h2>
          <?php if ($step === 3): ?>
            <form method="post" action="<?= e(url('checkout.php')) ?>" data-no-loading>
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="edit_address">
              <button class="btn-link" type="submit">Change</button>
            </form>
          <?php endif; ?>
        </div>

        <?php if ($step === 3 && $address): ?>
          <p class="mb0 text-sm"><strong><?= e($address['full_name']) ?></strong> · <?= e($address['phone']) ?></p>
          <p class="text-sm soft mb0"><?= e(address_one_line($address)) ?></p>

        <?php elseif ($step === 2): ?>
          <?php if ($savedList): ?>
            <form method="post" action="<?= e(url('checkout.php')) ?>" class="mb5">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="address">
              <div class="stack-sm">
                <?php foreach ($savedList as $i => $a): ?>
                  <label class="choice">
                    <input type="radio" name="saved_address_id" value="<?= (int) $a['id'] ?>"
                           <?= $i === 0 ? 'checked' : '' ?>>
                    <strong><?= e($a['full_name']) ?></strong>
                    <span class="pill pill-muted" style="margin-left:6px"><?= e(ucfirst($a['label'])) ?></span>
                    <p class="text-sm soft mb0"><?= e(address_one_line($a)) ?></p>
                    <p class="text-xs muted mb0"><?= e($a['phone']) ?></p>
                  </label>
                <?php endforeach; ?>
              </div>
              <button class="btn btn-block mt4" type="submit">Deliver to this address</button>
            </form>
            <p class="center text-sm muted mb4">or add a new one</p>
          <?php endif; ?>

          <form method="post" action="<?= e(url('checkout.php')) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="address">

            <div class="field <?= isset($errors['full_name']) ? 'has-error' : '' ?>">
              <label class="label" for="full_name">Full name <span class="req">*</span></label>
              <input class="input" id="full_name"<?= field_invalid($errors, 'full_name') ?> name="full_name" required autocomplete="name"
                     value="<?= e($addrInput['full_name'] ?? '') ?>">
              <?= field_error($errors, 'full_name') ?>
            </div>

            <div class="field <?= isset($errors['phone']) ? 'has-error' : '' ?>">
              <label class="label" for="phone">Mobile number <span class="req">*</span></label>
              <div class="input-group">
                <span class="addon">+91</span>
                <input class="input" id="phone"<?= field_invalid($errors, 'phone') ?> name="phone" required inputmode="numeric" maxlength="10"
                       autocomplete="tel-national" value="<?= e($addrInput['phone'] ?? '') ?>">
              </div>
              <span class="help">The courier will call this number.</span>
              <?= field_error($errors, 'phone') ?>
            </div>

            <div class="field <?= isset($errors['pincode']) ? 'has-error' : '' ?>">
              <label class="label" for="pincode">Pincode <span class="req">*</span></label>
              <input class="input" id="pincode"<?= field_invalid($errors, 'pincode') ?> name="pincode" required inputmode="numeric" maxlength="6"
                     autocomplete="postal-code" value="<?= e($addrInput['pincode'] ?? '') ?>"
                     data-pincode-fill style="max-width:180px">
              <span class="help" data-pincode-note>We fill in your city and state automatically.</span>
              <?= field_error($errors, 'pincode') ?>
            </div>

            <div class="field <?= isset($errors['line1']) ? 'has-error' : '' ?>">
              <label class="label" for="line1">House / flat no. and street <span class="req">*</span></label>
              <input class="input" id="line1"<?= field_invalid($errors, 'line1') ?> name="line1" required autocomplete="address-line1"
                     value="<?= e($addrInput['line1'] ?? '') ?>">
              <?= field_error($errors, 'line1') ?>
            </div>

            <div class="field">
              <label class="label" for="line2">Area / locality <span class="opt">(optional)</span></label>
              <input class="input" id="line2" name="line2" autocomplete="address-line2"
                     value="<?= e($addrInput['line2'] ?? '') ?>">
            </div>

            <div class="field">
              <label class="label" for="landmark">Landmark <span class="opt">(optional)</span></label>
              <input class="input" id="landmark" name="landmark"
                     value="<?= e($addrInput['landmark'] ?? '') ?>">
            </div>

            <div class="grid grid-2" style="gap:var(--s4)">
              <div class="field <?= isset($errors['city']) ? 'has-error' : '' ?>">
                <label class="label" for="city">City <span class="req">*</span></label>
                <input class="input" id="city"<?= field_invalid($errors, 'city') ?> name="city" required autocomplete="address-level2"
                       value="<?= e($addrInput['city'] ?? '') ?>" data-city-field>
                <?= field_error($errors, 'city') ?>
              </div>
              <div class="field <?= isset($errors['state']) ? 'has-error' : '' ?>">
                <label class="label" for="state">State <span class="req">*</span></label>
                <select class="select" id="state"<?= field_invalid($errors, 'state') ?> name="state" required data-state-field>
                  <option value="">Choose your state</option>
                  <?php foreach (india_states() as $s): ?>
                    <option value="<?= e($s) ?>" <?= ($addrInput['state'] ?? '') === $s ? 'selected' : '' ?>>
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
                           <?= ($addrInput['label'] ?? 'home') === $val ? 'checked' : '' ?>>
                    <span><?= e($lbl) ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
            </div>

            <label class="check">
              <input type="checkbox" name="make_default" value="1">
              <span>Make this my default address</span>
            </label>

            <button class="btn btn-block btn-lg mt4" type="submit">Continue to review</button>
          </form>

        <?php else: ?>
          <p class="text-sm muted mb0">Verify your email first.</p>
        <?php endif; ?>
      </section>

      <!-- ========================================= 3. review -->
      <section class="card <?= $step < 3 ? 'is-locked' : '' ?>">
        <h2 style="font-family:var(--font-sans);font-size:1.0625rem;margin:0 0 var(--s3)">3 · Review &amp; pay</h2>

        <?php if ($step === 3): ?>
          <?php foreach ($lines as $l): ?>
            <div class="review-line">
              <img src="<?= e($l['image_src']) ?>" alt="" width="48" height="60" loading="lazy">
              <div class="min0">
                <p class="text-sm mb0 truncate"><strong><?= e($l['name']) ?></strong></p>
                <p class="text-xs muted mb0"><?= e($l['shade_name']) ?> · Qty <?= (int) $l['qty'] ?></p>
              </div>
              <strong class="text-sm"><?= e(money((float) $l['line_total'])) ?></strong>
            </div>
          <?php endforeach; ?>

          <form method="post" action="<?= e(url('checkout-init.php')) ?>" class="mt5">
            <?= csrf_field() ?>

            <div class="field">
              <label class="label" for="note">Order note <span class="opt">(optional)</span></label>
              <textarea class="textarea" id="note" name="customer_note" rows="2"
                        placeholder="Anything we should know?" style="min-height:70px"></textarea>
            </div>

            <div class="alert mb4" style="background:var(--sage-tint)">
              <?= icon('lock', 18) ?>
              <div class="text-sm">
                Pay securely by UPI, card, netbanking or wallet. We never see or store your card details.
              </div>
            </div>

            <p class="text-xs muted">
              By placing this order you agree to our
              <a href="<?= e(url('terms-and-conditions')) ?>">Terms</a>,
              <a href="<?= e(url('returns-and-refunds')) ?>">Return Policy</a> and
              <a href="<?= e(url('shipping-policy')) ?>">Shipping Policy</a>.
            </p>

            <button class="btn btn-block btn-lg mt3" type="submit">
              <?= icon('lock', 18) ?> Pay <?= e(money($totals['grand_total'])) ?>
            </button>
          </form>
        <?php else: ?>
          <p class="text-sm muted mb0">Complete the steps above to review your order.</p>
        <?php endif; ?>
      </section>
    </div>

    <!-- ======================================== order summary -->
    <aside class="checkout-summary">
      <div class="card">
        <h2 style="font-family:var(--font-sans);font-size:1.0625rem">Order summary</h2>

        <details class="mt3" <?= count($lines) <= 3 ? 'open' : '' ?>>
          <summary class="btn-link"><?= count($lines) ?> item<?= count($lines) === 1 ? '' : 's' ?> in this order</summary>
          <div class="mt3">
            <?php foreach ($lines as $l): ?>
              <div class="review-line">
                <img src="<?= e($l['image_src']) ?>" alt="" width="40" height="50" loading="lazy">
                <div class="min0">
                  <p class="text-xs mb0 truncate"><?= e($l['name']) ?></p>
                  <p class="text-xs muted mb0"><?= e($l['shade_name']) ?> × <?= (int) $l['qty'] ?></p>
                </div>
                <span class="text-xs"><?= e(money((float) $l['line_total'])) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        </details>

        <div class="mt4"><?php pricing_shipping_bar($totals); ?></div>
        <div class="mt4"><?php pricing_summary_rows($totals); ?></div>

        <p class="text-xs muted mt4 mb0">
          <a href="<?= e(url('cart.php')) ?>">Edit cart</a>
        </p>
      </div>
    </aside>
  </div>
</div>

<?php require_once YN_ROOT . '/includes/footer.php'; ?>
