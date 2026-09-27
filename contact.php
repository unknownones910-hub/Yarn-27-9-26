<?php
/**
 * Contact.
 *
 * Real contact details in public are the single biggest trust signal for an
 * Indian D2C shop — and publishing the address, phone and grievance officer is
 * required by the Consumer Protection (E-Commerce) Rules, 2020.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/mailer.php';

$errors = [];
$sent   = false;
$input  = ['name' => '', 'email' => '', 'phone' => '', 'subject' => '', 'order_no' => '', 'message' => ''];

$customer = current_customer();
if ($customer) {
    $input['name']  = (string) ($customer['full_name'] ?? '');
    $input['email'] = (string) $customer['email'];
    $input['phone'] = (string) ($customer['phone'] ?? '');
}

$subjects = [
    'Order enquiry', 'Delivery problem', 'Return or refund', 'Product question',
    'Wholesale enquiry', 'Website problem', 'Something else',
];

if (is_post()) {
    csrf_verify();

    foreach ($input as $k => $_) {
        $input[$k] = post_str($k, $k === 'message' ? 4000 : 190);
    }

    if (!honeypot_ok()) {
        // Bot. Show the same success it would see anyway; store nothing.
        $sent = true;
    } else {
        if (mb_strlen($input['name']) < 2) {
            $errors['name'] = 'Please tell us your name.';
        }
        if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'We need a valid email address to reply to.';
        }
        if ($input['phone'] !== '' && normalize_phone($input['phone']) === null) {
            $errors['phone'] = 'That does not look like a valid 10-digit mobile number.';
        }
        if (mb_strlen($input['message']) < 10) {
            $errors['message'] = 'Please tell us a little more so we can actually help.';
        }
        if (!$errors && !rate_hit('contact:' . client_ip(), 5, 3600)) {
            $errors['message'] = 'You have sent us several messages already. '
                               . 'Please give us a chance to reply first.';
        }

        if (!$errors) {
            $kind = $input['subject'] === 'Wholesale enquiry' ? 'wholesale' : 'contact';

            $id = db_insert(
                'INSERT INTO contact_messages
                    (kind, name, email, phone, subject, order_no, message, status, ip)
                 VALUES (?,?,?,?,?,?,?, "new", ?)',
                [
                    $kind, $input['name'], $input['email'],
                    $input['phone'] !== '' ? normalize_phone($input['phone']) : null,
                    $input['subject'] ?: null,
                    $input['order_no'] !== '' ? $input['order_no'] : null,
                    $input['message'], client_ip(),
                ]
            );

            // Tell the shop.
            try {
                require_once YN_ROOT . '/includes/order-emails.php';
                $body = '<p style="margin:0 0 16px;"><strong>' . e($input['name']) . '</strong> · '
                      . '<a href="mailto:' . e($input['email']) . '">' . e($input['email']) . '</a>'
                      . ($input['phone'] !== '' ? ' · ' . e($input['phone']) : '') . '</p>'
                      . ($input['subject'] !== ''
                          ? '<p style="margin:0 0 8px;"><strong>' . e($input['subject']) . '</strong></p>' : '')
                      . ($input['order_no'] !== ''
                          ? '<p style="margin:0 0 8px;">Order: ' . e($input['order_no']) . '</p>' : '')
                      . '<p style="margin:0 0 16px;color:#5D554C;">' . nl2br(e($input['message'])) . '</p>'
                      . email_button('Open in admin', abs_url('admin/messages.php'));

                foreach (order_admin_recipients() as $to) {
                    send_mail($to, 'Admin',
                        '[' . ucfirst($kind) . '] ' . ($input['subject'] ?: 'New message'),
                        email_layout('New enquiry', $body), null, 'admin_contact');
                }
            } catch (Throwable $e) {
                error_log('Contact notification failed: ' . $e->getMessage());
            }

            // Acknowledge to the sender, stating the SLA we publish.
            try {
                send_mail($input['email'], $input['name'],
                    'We have your message · ' . setting('store_name'),
                    email_layout('Thanks — we have your message',
                        '<p style="margin:0 0 16px;">Hello ' . e($input['name']) . ',</p>'
                        . '<p style="margin:0 0 16px;">Thank you for writing to us. A real person '
                        . 'reads every message and we reply within <strong>one working day</strong> '
                        . '(Monday to Saturday).</p>'
                        . '<p style="margin:0 0 8px;color:#8A8078;font-size:14px;">What you sent us:</p>'
                        . '<p style="margin:0 0 16px;padding:16px;background:#F5EEE4;border-radius:12px;'
                        . 'color:#5D554C;font-size:14px;">' . nl2br(e($input['message'])) . '</p>'
                        . '<p style="margin:0;">In a hurry? Call ' . e(setting('store_phone')) . '.</p>',
                        'We reply within one working day'),
                    null, 'contact_ack');
            } catch (Throwable $e) {
                error_log('Contact acknowledgement failed: ' . $e->getMessage());
            }

            admin_log_safe($id);
            $sent = true;
        }
    }
}

/** Contact messages are not admin actions, but a trace is still useful. */
function admin_log_safe(int $id): void
{
    error_log('New contact message #' . $id);
}

$waLink    = whatsapp_link('Hi! I have a question.');
$pageTitle = 'Contact us';
$pageDesc  = 'Talk to a real person at ' . setting('store_name')
           . '. Phone, WhatsApp and email — we reply within one working day.';
$canonical = '/contact.php';

$jsonLd = [[
    '@context' => 'https://schema.org',
    '@type'    => 'ContactPage',
    'name'     => 'Contact ' . setting('store_name'),
    'url'      => abs_url('/contact.php'),
]];

require_once YN_ROOT . '/includes/header.php';
?>

<div class="container section-sm">
  <header class="mb6" style="max-width:60ch">
    <h1 class="mb2">Talk to us</h1>
    <p class="lede mb0">
      A real person reads everything and replies within one working day. If it is urgent,
      call or message us on WhatsApp — that is usually faster.
    </p>
  </header>

  <div class="grid grid-2" style="align-items:start;gap:var(--s7)">

    <!-- ================================================== form -->
    <div class="min0">
      <?php if ($sent): ?>
        <div class="card card-lift center" style="padding-block:var(--s7)">
          <div class="empty__art" style="margin-inline:auto"><?= icon('check-circle', 36) ?></div>
          <h2 class="mt4" style="font-family:var(--font-sans);font-size:1.125rem">Message received</h2>
          <p class="soft" style="margin-inline:auto;max-width:38ch">
            Thank you. We have emailed you a copy and will reply within one working day.
          </p>
          <a class="btn btn-soft mt4" href="<?= e(url('shop.php')) ?>">Back to shopping</a>
        </div>

      <?php else: ?>
        <div class="card">
          <h2 style="font-family:var(--font-sans);font-size:1.0625rem">Send a message</h2>

          <form method="post" action="<?= e(url('contact.php')) ?>" class="mt4">
            <?= csrf_field() ?>
            <?= honeypot_field() ?>

            <div class="field-row field-row--2" style="display:grid;gap:var(--s4);grid-template-columns:1fr">
              <div class="field <?= isset($errors['name']) ? 'has-error' : '' ?>">
                <label class="label" for="name">Your name <span class="req">*</span></label>
                <input class="input" id="name"<?= field_invalid($errors, 'name') ?> name="name" required autocomplete="name"
                       value="<?= e($input['name']) ?>">
                <?= field_error($errors, 'name') ?>
              </div>

              <div class="field <?= isset($errors['email']) ? 'has-error' : '' ?>">
                <label class="label" for="email">Email <span class="req">*</span></label>
                <input class="input" type="email" id="email"<?= field_invalid($errors, 'email') ?> name="email" required autocomplete="email"
                       value="<?= e($input['email']) ?>">
                <?= field_error($errors, 'email') ?>
              </div>
            </div>

            <div class="field <?= isset($errors['phone']) ? 'has-error' : '' ?>">
              <label class="label" for="phone">Phone <span class="opt">(optional)</span></label>
              <div class="input-group">
                <span class="addon">+91</span>
                <input class="input" id="phone"<?= field_invalid($errors, 'phone') ?> name="phone" inputmode="numeric" maxlength="10"
                       autocomplete="tel-national" value="<?= e($input['phone']) ?>">
              </div>
              <?= field_error($errors, 'phone') ?>
            </div>

            <div class="field">
              <label class="label" for="subject">What is it about?</label>
              <select class="select" id="subject" name="subject">
                <option value="">Choose one</option>
                <?php foreach ($subjects as $s): ?>
                  <option value="<?= e($s) ?>" <?= $input['subject'] === $s ? 'selected' : '' ?>>
                    <?= e($s) ?></option>
                <?php endforeach; ?>
              </select>
            </div>

            <div class="field">
              <label class="label" for="order_no">Order number <span class="opt">(if relevant)</span></label>
              <input class="input" id="order_no" name="order_no" style="max-width:240px"
                     placeholder="<?= e(setting('order_prefix', 'YN')) ?>-2608-0001"
                     value="<?= e($input['order_no']) ?>">
              <span class="help">It is in your confirmation email. Having it speeds things up a lot.</span>
            </div>

            <div class="field <?= isset($errors['message']) ? 'has-error' : '' ?>">
              <label class="label" for="message">Your message <span class="req">*</span></label>
              <textarea class="textarea" id="message"<?= field_invalid($errors, 'message') ?> name="message" rows="5" required
                        placeholder="Tell us what happened."><?= e($input['message']) ?></textarea>
              <?= field_error($errors, 'message') ?>
            </div>

            <button class="btn btn-block btn-lg" type="submit">Send message</button>

            <p class="text-xs muted center mt4 mb0">
              We use your details only to reply. See our
              <a href="<?= e(url('privacy-policy')) ?>">privacy policy</a>.
            </p>
          </form>
        </div>
      <?php endif; ?>
    </div>

    <!-- ============================================== details -->
    <div class="min0">
      <div class="card mb4">
        <h2 style="font-family:var(--font-sans);font-size:1.0625rem">Reach us directly</h2>

        <div class="stack mt4">
          <a class="row" style="gap:var(--s3);text-decoration:none;align-items:flex-start"
             href="tel:<?= e(preg_replace('/\s+/', '', setting('store_phone'))) ?>">
            <span class="trust__ico"><?= icon('phone', 18) ?></span>
            <span class="min0">
              <strong class="text-sm" style="color:var(--ink)"><?= e(setting('store_phone')) ?></strong><br>
              <span class="text-xs muted">Mon–Sat, 10am to 6pm</span>
            </span>
          </a>

          <?php if ($waLink !== ''): ?>
            <a class="row" style="gap:var(--s3);text-decoration:none;align-items:flex-start"
               href="<?= eurl($waLink) ?>" target="_blank" rel="noopener">
              <span class="trust__ico"><?= icon('whatsapp', 18) ?></span>
              <span class="min0">
                <strong class="text-sm" style="color:var(--ink)">WhatsApp</strong><br>
                <span class="text-xs muted">Usually the fastest way to reach us</span>
              </span>
            </a>
          <?php endif; ?>

          <a class="row" style="gap:var(--s3);text-decoration:none;align-items:flex-start"
             href="mailto:<?= e(setting('store_email')) ?>">
            <span class="trust__ico"><?= icon('mail', 18) ?></span>
            <span class="min0">
              <strong class="text-sm" style="color:var(--ink);overflow-wrap:anywhere">
                <?= e(setting('store_email')) ?></strong><br>
              <span class="text-xs muted">We reply within one working day</span>
            </span>
          </a>

          <div class="row" style="gap:var(--s3);align-items:flex-start">
            <span class="trust__ico"><?= icon('pin', 18) ?></span>
            <span class="min0">
              <strong class="text-sm">Our address</strong><br>
              <address class="text-xs muted" style="font-style:normal;line-height:1.7">
                <?= nl2br(e(setting('store_legal_name') . "\n" . setting('store_address'))) ?>
              </address>
            </span>
          </div>
        </div>
      </div>

      <div class="card mb4">
        <h2 style="font-family:var(--font-sans);font-size:1rem">Before you write</h2>
        <p class="text-sm soft">These answer most questions instantly:</p>
        <ul class="stack-sm text-sm">
          <li>· <a href="<?= e(url('track-order.php')) ?>">Track your order</a>
              — no sign-in needed</li>
          <li>· <a href="<?= e(url('faq.php')) ?>">Frequently asked questions</a></li>
          <li>· <a href="<?= e(url('shipping-policy')) ?>">Delivery times and charges</a></li>
          <li>· <a href="<?= e(url('returns-and-refunds')) ?>">How returns work</a></li>
        </ul>
      </div>

      <div class="card">
        <h2 style="font-family:var(--font-sans);font-size:1rem">Grievance officer</h2>
        <p class="text-sm soft">
          If normal support has not resolved something, escalate to
          <strong><?= e(setting('grievance_name')) ?></strong> at
          <a href="mailto:<?= e(setting('grievance_email')) ?>"><?= e(setting('grievance_email')) ?></a>.
        </p>
        <p class="text-xs muted mb0">
          We acknowledge within 48 hours and resolve within 30 days.
          <a href="<?= e(url('grievance-redressal')) ?>">Full escalation path</a>.
        </p>
      </div>
    </div>
  </div>
</div>

<?php require_once YN_ROOT . '/includes/footer.php'; ?>
