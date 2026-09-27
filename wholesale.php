<?php
/**
 * Wholesale / bulk enquiries — boutiques, craft teachers and resellers.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/mailer.php';

if (!setting_bool('feat_wholesale', true)) {
    http_response_code(404);
    require_once YN_ROOT . '/404.php';
    exit;
}

$errors = [];
$sent   = false;
$in     = ['name' => '', 'business' => '', 'email' => '', 'phone' => '', 'city' => '',
           'gstin' => '', 'volume' => '', 'interest' => '', 'message' => ''];

if (is_post()) {
    csrf_verify();
    foreach ($in as $k => $_) {
        $in[$k] = post_str($k, $k === 'message' ? 3000 : 190);
    }

    if (!honeypot_ok()) {
        $sent = true;
    } else {
        if (mb_strlen($in['name']) < 2)      { $errors['name']     = 'Please tell us your name.'; }
        if (mb_strlen($in['business']) < 2)  { $errors['business'] = 'What is the business called?'; }
        if (!filter_var($in['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'We need a valid email to reply to.';
        }
        if (normalize_phone($in['phone']) === null) {
            $errors['phone'] = 'Enter a valid 10-digit mobile number.';
        }
        if (mb_strlen($in['city']) < 2)      { $errors['city']    = 'Which city are you in?'; }
        if (mb_strlen($in['message']) < 10)  { $errors['message'] = 'Tell us a bit about what you need.'; }

        if (!$errors && !rate_hit('wholesale:' . client_ip(), 5, 3600)) {
            $errors['message'] = 'You have sent several enquiries already. Give us a chance to reply.';
        }

        if (!$errors) {
            $extra = json_col([
                'business'        => $in['business'],
                'city'            => $in['city'],
                'gstin'           => $in['gstin'],
                'monthly_volume'  => $in['volume'],
                'interested_in'   => $in['interest'],
            ]);

            db_insert(
                'INSERT INTO contact_messages
                    (kind, name, email, phone, subject, message, extra_json, status, ip)
                 VALUES ("wholesale", ?,?,?,?,?,?, "new", ?)',
                [$in['name'], $in['email'], normalize_phone($in['phone']),
                 'Wholesale enquiry · ' . $in['business'], $in['message'], $extra, client_ip()]
            );

            try {
                require_once YN_ROOT . '/includes/order-emails.php';
                $body = '<p style="margin:0 0 16px;"><strong>' . e($in['business']) . '</strong> · '
                      . e($in['city']) . '</p>'
                      . '<p style="margin:0 0 16px;">' . e($in['name']) . ' · '
                      . e($in['email']) . ' · ' . e($in['phone']) . '</p>'
                      . '<p style="margin:0 0 8px;">Volume: ' . e($in['volume'] ?: 'not stated')
                      . ' · Interested in: ' . e($in['interest'] ?: 'not stated') . '</p>'
                      . ($in['gstin'] !== '' ? '<p style="margin:0 0 8px;">GSTIN: '
                            . e($in['gstin']) . '</p>' : '')
                      . '<p style="margin:16px 0 0;color:#5D554C;">' . nl2br(e($in['message'])) . '</p>';

                foreach (order_admin_recipients() as $to) {
                    send_mail($to, 'Admin', '[Wholesale] ' . $in['business'],
                        email_layout('Wholesale enquiry', $body), null, 'admin_wholesale');
                }
                send_mail($in['email'], $in['name'],
                    'We have your wholesale enquiry · ' . setting('store_name'),
                    email_layout('Thanks for getting in touch',
                        '<p style="margin:0 0 16px;">Hello ' . e($in['name']) . ',</p>'
                        . '<p style="margin:0 0 16px;">Thank you for your interest in stocking our '
                        . 'yarn. We reply to wholesale enquiries within <strong>two working '
                        . 'days</strong> with pricing and minimum order details.</p>'),
                    null, 'wholesale_ack');
            } catch (Throwable $e) {
                error_log('Wholesale notification failed: ' . $e->getMessage());
            }

            $sent = true;
        }
    }
}

$pageTitle = 'Wholesale & bulk orders';
$pageDesc  = 'Stock our yarn in your boutique, or buy in bulk for classes and workshops.';
$canonical = '/wholesale.php';

require_once YN_ROOT . '/includes/header.php';
?>

<div class="container section-sm">
  <header class="mb6" style="max-width:60ch">
    <p class="eyebrow">For the trade</p>
    <h1 class="mb2">Wholesale &amp; bulk orders</h1>
    <p class="lede mb0">
      If you run a craft boutique, teach classes, or make to sell, we would like to work
      with you.
    </p>
  </header>

  <div class="grid grid-3 mb6">
    <div class="card">
      <span class="trust__ico mb3"><?= icon('tag', 19) ?></span>
      <h2 style="font-family:var(--font-sans);font-size:1rem;margin:0 0 6px">Tiered pricing</h2>
      <p class="text-sm soft mb0">Discounts increase with volume. We will send the full sheet
        once we know roughly what you need.</p>
    </div>
    <div class="card">
      <span class="trust__ico mb3"><?= icon('package', 19) ?></span>
      <h2 style="font-family:var(--font-sans);font-size:1rem;margin:0 0 6px">Dye lot held</h2>
      <p class="text-sm soft mb0">We reserve a single lot for your order, so your shelf is
        consistent and repeat customers get a match.</p>
    </div>
    <div class="card">
      <span class="trust__ico mb3"><?= icon('chat', 19) ?></span>
      <h2 style="font-family:var(--font-sans);font-size:1rem;margin:0 0 6px">One contact</h2>
      <p class="text-sm soft mb0">You deal with a person, not a form queue. Reorders can be
        placed over WhatsApp.</p>
    </div>
  </div>

  <div class="grid grid-2" style="align-items:start;gap:var(--s7)">
    <div class="min0">
      <?php if ($sent): ?>
        <div class="card card-lift center" style="padding-block:var(--s7)">
          <div class="empty__art" style="margin-inline:auto"><?= icon('check-circle', 36) ?></div>
          <h2 class="mt4" style="font-family:var(--font-sans);font-size:1.125rem">Enquiry received</h2>
          <p class="soft" style="margin-inline:auto;max-width:40ch">
            Thank you. We reply to wholesale enquiries within two working days with pricing
            and minimum order quantities.
          </p>
          <a class="btn btn-soft mt4" href="<?= e(url('shop.php')) ?>">Browse the range</a>
        </div>

      <?php else: ?>
        <div class="card">
          <h2 style="font-family:var(--font-sans);font-size:1.0625rem">Tell us about your business</h2>

          <form method="post" action="<?= e(url('wholesale.php')) ?>" class="mt4">
            <?= csrf_field() ?>
            <?= honeypot_field() ?>

            <div class="field <?= isset($errors['business']) ? 'has-error' : '' ?>">
              <label class="label" for="business">Business name <span class="req">*</span></label>
              <input class="input" id="business"<?= field_invalid($errors, 'business') ?> name="business" required value="<?= e($in['business']) ?>">
              <?= field_error($errors, 'business') ?>
            </div>

            <div class="field <?= isset($errors['name']) ? 'has-error' : '' ?>">
              <label class="label" for="name">Your name <span class="req">*</span></label>
              <input class="input" id="name"<?= field_invalid($errors, 'name') ?> name="name" required autocomplete="name"
                     value="<?= e($in['name']) ?>">
              <?= field_error($errors, 'name') ?>
            </div>

            <div class="field <?= isset($errors['email']) ? 'has-error' : '' ?>">
              <label class="label" for="email">Email <span class="req">*</span></label>
              <input class="input" type="email" id="email"<?= field_invalid($errors, 'email') ?> name="email" required
                     autocomplete="email" value="<?= e($in['email']) ?>">
              <?= field_error($errors, 'email') ?>
            </div>

            <div class="field <?= isset($errors['phone']) ? 'has-error' : '' ?>">
              <label class="label" for="phone">Phone <span class="req">*</span></label>
              <div class="input-group">
                <span class="addon">+91</span>
                <input class="input" id="phone"<?= field_invalid($errors, 'phone') ?> name="phone" required inputmode="numeric"
                       maxlength="10" value="<?= e($in['phone']) ?>">
              </div>
              <?= field_error($errors, 'phone') ?>
            </div>

            <div class="field <?= isset($errors['city']) ? 'has-error' : '' ?>">
              <label class="label" for="city">City <span class="req">*</span></label>
              <input class="input" id="city"<?= field_invalid($errors, 'city') ?> name="city" required value="<?= e($in['city']) ?>">
              <?= field_error($errors, 'city') ?>
            </div>

            <div class="field">
              <label class="label" for="gstin">GSTIN <span class="opt">(optional)</span></label>
              <input class="input" id="gstin" name="gstin" value="<?= e($in['gstin']) ?>"
                     style="text-transform:uppercase">
            </div>

            <div class="field">
              <label class="label" for="volume">Roughly how much per month?</label>
              <select class="select" id="volume" name="volume">
                <option value="">Not sure yet</option>
                <?php foreach (['Under 50 balls', '50–200 balls', '200–500 balls',
                                '500+ balls'] as $v): ?>
                  <option value="<?= e($v) ?>" <?= $in['volume'] === $v ? 'selected' : '' ?>>
                    <?= e($v) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field">
              <label class="label" for="interest">Mainly interested in</label>
              <input class="input" id="interest" name="interest" value="<?= e($in['interest']) ?>"
                     placeholder="Cotton and acrylic DK, mostly">
            </div>

            <div class="field <?= isset($errors['message']) ? 'has-error' : '' ?>">
              <label class="label" for="message">Tell us more <span class="req">*</span></label>
              <textarea class="textarea" id="message"<?= field_invalid($errors, 'message') ?> name="message" rows="4" required
                        placeholder="What do you sell or teach? What are you looking for?"><?= e($in['message']) ?></textarea>
              <?= field_error($errors, 'message') ?>
            </div>

            <button class="btn btn-block btn-lg" type="submit">Send enquiry</button>
          </form>
        </div>
      <?php endif; ?>
    </div>

    <div class="min0">
      <div class="card mb4">
        <h2 style="font-family:var(--font-sans);font-size:1rem">Who this suits</h2>
        <ul class="stack-sm text-sm mt3">
          <li>· Craft and yarn boutiques</li>
          <li>· Crochet and knitting teachers buying for classes</li>
          <li>· Makers producing at volume for markets or online</li>
          <li>· Schools, NGOs and craft collectives</li>
          <li>· Studios running workshops</li>
        </ul>
      </div>

      <div class="card mb4">
        <h2 style="font-family:var(--font-sans);font-size:1rem">What happens next</h2>
        <ol class="stack-sm text-sm mt3" style="padding-left:18px">
          <li>We reply within two working days</li>
          <li>You get the price sheet and minimum order quantities</li>
          <li>We confirm dye-lot availability for what you want</li>
          <li>Order by email or WhatsApp; payment by bank transfer or UPI</li>
        </ol>
      </div>

      <div class="card">
        <h2 style="font-family:var(--font-sans);font-size:1rem">Prefer to talk?</h2>
        <p class="text-sm soft mb2">Call <?= e(setting('store_phone')) ?>, Mon–Sat 10am–6pm.</p>
        <?php $wa = whatsapp_link('Hi! I am interested in wholesale pricing.'); ?>
        <?php if ($wa !== ''): ?>
          <a class="btn btn-sm btn-soft" href="<?= eurl($wa) ?>" target="_blank" rel="noopener">
            <?= icon('whatsapp', 15) ?> WhatsApp us</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php require_once YN_ROOT . '/includes/footer.php'; ?>
