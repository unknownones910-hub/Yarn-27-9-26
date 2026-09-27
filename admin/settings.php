<?php
/**
 * Store settings.
 *
 * Everything the owner is expected to change without a developer. The
 * [bracketed] placeholders seeded at install are flagged loudly, because
 * shipping with them visible is a legal problem, not a cosmetic one.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-bootstrap.php';
require_once YN_ROOT . '/includes/payments/razorpay.php';
require_once YN_ROOT . '/includes/mailer.php';

$admin = admin_guard('manager');

/**
 * The About-page counters, one switch each, labelled with the live figure.
 *
 * Built here rather than written inline so each label can say what the number
 * actually is right now — "Orders shipped — currently 1" makes the decision
 * for you, where an abstract rule made you go and look it up.
 */
$statNow = [
    'stat_shades'   => ['Shades stocked', (int) db_val(
        'SELECT COUNT(*) FROM product_variants v JOIN products p ON p.id = v.product_id
          WHERE v.is_active = 1 AND p.is_active = 1')],
    'stat_products' => ['Yarns & tools', (int) db_val(
        'SELECT COUNT(*) FROM products WHERE is_active = 1')],
    'stat_orders'   => ['Orders shipped', (int) db_val(
        'SELECT COUNT(*) FROM orders WHERE payment_status = "paid"')],
    'stat_cities'   => ['Cities delivered to', (int) db_val(
        'SELECT COUNT(DISTINCT ship_city) FROM orders WHERE payment_status = "paid"')],
];

$STAT_TOGGLES = [];
foreach ($statNow as $key => [$label, $n]) {
    $STAT_TOGGLES[$key] = [
        $label . ' — currently ' . number_format($n),
        'bool',
        $n < 10
            ? 'A number this small makes the shop look emptier than showing nothing. '
              . 'Leave it off until it grows.'
            : 'Looks good — worth showing.',
    ];
}
$STAT_TOGGLES['feat_review_counts'] = [
    'Show how many reviews a product has',
    'bool',
    'The small "(3)" next to the stars on a product. Off means customers still see '
    . 'the stars and the score — ★★★★☆ 4.5 — just not how many people reviewed it. '
    . 'Nothing to do with the About page.',
];

/** key => [label, type, help] */
$GROUPS = [
    'Store identity' => [
        'store_name'         => ['Shop name', 'text', 'Shown in the header, emails and page titles.'],
        'store_legal_name'   => ['Registered business name', 'text', 'Appears in the footer and on invoices — a legal requirement.'],
        'store_address'      => ['Full postal address', 'textarea', 'Must be a real address. Required by the Consumer Protection (E-Commerce) Rules.'],
        'store_phone'        => ['Phone', 'text', 'Shown in the footer and on invoices.'],
        'store_whatsapp'     => ['WhatsApp number', 'text', 'Digits with country code, e.g. 919876543210. Leave blank to hide WhatsApp links.'],
        'store_email'        => ['Support email', 'email', ''],
        'store_gstin'        => ['GSTIN', 'text', 'Leave blank until you register.'],
    ],
    'Grievance officer' => [
        'grievance_name'     => ['Officer name', 'text', 'Publishing this is required by the e-commerce and IT rules.'],
        'grievance_email'    => ['Officer email', 'email', ''],
        'jurisdiction_city'  => ['Jurisdiction city', 'text', 'Used in the Terms.'],
        'jurisdiction_state' => ['Jurisdiction state', 'text', ''],
    ],
    'Delivery & returns' => [
        'ship_flat_rate'      => ['Flat delivery charge (₹)', 'number', ''],
        'ship_free_threshold' => ['Free delivery above (₹)', 'number', 'Tested against the order value before any discount. 0 turns it off.'],
        'dispatch_days'       => ['Dispatch time', 'text', 'e.g. "1-2". Shown on the product page and in emails.'],
        'return_window_days'  => ['Exchange window (days)', 'number', 'Days after delivery a customer can raise a wrong/damaged/defective claim.'],
        'gift_wrap_fee'       => ['Gift wrap charge (₹)', 'number', ''],
    ],
    'Payments' => [
        'cod_enabled' => ['Offer cash on delivery', 'bool', 'Leave off unless you have an RTO plan.'],
        'gst_enabled' => ['Charge and show GST', 'bool', 'Only switch on once you are registered and HSN codes are filled in.'],
    ],
    'Storefront' => [
        'announcement_on'   => ['Show the announcement bar', 'bool', ''],
        'announcement_text' => ['Announcement text', 'text', ''],
        'feat_reviews'      => ['Reviews', 'bool', ''],
        'feat_blog'         => ['Blog', 'bool', ''],
        'feat_patterns'     => ['Free patterns', 'bool', ''],
        'feat_wholesale'    => ['Wholesale enquiries', 'bool', ''],
        'feat_gift_wrap'    => ['Gift wrap option', 'bool', ''],
        'feat_newsletter'   => ['Newsletter signup', 'bool', ''],
    ],
    'Numbers shown to customers' => $STAT_TOGGLES,
    'Social' => [
        'social_instagram' => ['Instagram URL', 'url', ''],
        'social_facebook'  => ['Facebook URL', 'url', ''],
        'social_youtube'   => ['YouTube URL', 'url', ''],
        'social_pinterest' => ['Pinterest URL', 'url', ''],
    ],
    'Search engines' => [
        'seo_title'       => ['Default page title', 'text', ''],
        'seo_description' => ['Default meta description', 'textarea', ''],
    ],
    'Notifications' => [
        'admin_notify_emails' => ['Send order alerts to', 'text', 'Comma-separated. Defaults to the support email.'],
    ],
];

if (is_post()) {
    csrf_verify();

    $saved = 0;
    foreach ($GROUPS as $fields) {
        foreach ($fields as $key => [$label, $type]) {
            if ($type === 'bool') {
                setting_set($key, empty($_POST[$key]) ? '0' : '1');
                $saved++;
                continue;
            }
            if (!array_key_exists($key, $_POST)) {
                continue;
            }
            $val = trim((string) $_POST[$key]);

            if ($type === 'number') {
                $val = (string) max(0, (float) $val);
                // Trailing zeros may only be stripped from a DECIMAL. The old
                // code ran rtrim($val,'0') unconditionally, so every whole
                // number ending in zero was silently mangled on save:
                // 10 -> 1, 20 -> 2, 100 -> 1, and "free delivery above 2500"
                // became 25.
                if (strpos($val, '.') !== false) {
                    $val = rtrim(rtrim($val, '0'), '.');
                }
                if ($val === '') {
                    $val = '0';
                }
            }
            if (($type === 'email' || $type === 'url') && $val !== '') {
                $filter = $type === 'email' ? FILTER_VALIDATE_EMAIL : FILTER_VALIDATE_URL;
                if (!filter_var($val, $filter)) {
                    flash('"' . $label . '" did not look valid and was left unchanged.', 'warn');
                    continue;
                }
            }
            setting_set($key, mb_substr($val, 0, 2000));
            $saved++;
        }
    }

    admin_log('settings_update', 'settings', null, $saved . ' values');
    flash('Settings saved.', 'success');
    redirect('admin/settings.php');
}

// Anything still holding a seeded placeholder.
$placeholders = [];
foreach ($GROUPS as $fields) {
    foreach ($fields as $key => [$label]) {
        $v = setting($key);
        if ($v !== '' && preg_match('/\[[a-z ]+\]|example\.com|00000 00000/i', $v)) {
            $placeholders[$key] = $label;
        }
    }
}

$pageTitle    = 'Settings';
$adminSection = 'settings';

require_once YN_ROOT . '/includes/admin-header.php';
?>

<div class="admin-head">
  <div class="min0"><h1>Settings</h1><p>Everything you can change without a developer.</p></div>
</div>

<?php if ($placeholders): ?>
  <div class="alert alert-warn mb4">
    <?= icon('alert', 18) ?>
    <div>
      <strong>These still hold placeholder text.</strong> The shop must not go live until they
      are real — your address, phone and grievance officer are legally required on every page.
      <br><span class="text-sm"><?= e(implode(' · ', $placeholders)) ?></span>
    </div>
  </div>
<?php endif; ?>

<div class="kpi-grid mb5">
  <div class="kpi<?= rzp_configured() ? '' : ' kpi--warn' ?>">
    <p class="kpi__label"><?= icon('lock', 14) ?> Razorpay</p>
    <p class="kpi__value" style="font-size:1.125rem">
      <?= rzp_configured() ? (rzp_is_live() ? 'Live' : 'Test mode') : 'Not set up' ?></p>
    <p class="kpi__sub">config/razorpay-config.local.php</p>
  </div>
  <div class="kpi<?= brevo_configured() ? '' : ' kpi--warn' ?>">
    <p class="kpi__label"><?= icon('mail', 14) ?> Email (Brevo)</p>
    <p class="kpi__value" style="font-size:1.125rem">
      <?= brevo_configured() ? 'Connected' : 'Not set up' ?></p>
    <p class="kpi__sub">config/brevo-config.local.php</p>
  </div>
  <div class="kpi">
    <p class="kpi__label"><?= icon('check-circle', 14) ?> Emails sent (30d)</p>
    <p class="kpi__value"><?= (int) db_val('SELECT COUNT(*) FROM email_log
        WHERE status = "sent" AND created_at > (NOW() - INTERVAL 30 DAY)') ?></p>
  </div>
  <div class="kpi">
    <p class="kpi__label"><?= icon('alert', 14) ?> Email failures (30d)</p>
    <p class="kpi__value"><?= (int) db_val('SELECT COUNT(*) FROM email_log
        WHERE status = "failed" AND created_at > (NOW() - INTERVAL 30 DAY)') ?></p>
  </div>
</div>

<p class="text-sm muted mb5">
  API keys are not editable here on purpose — they live in
  <code>config/*-config.local.php</code>, outside the database and outside any backup or
  deploy zip. See <code>DEPLOY.md</code>.
</p>

<form method="post" action="<?= e(url('admin/settings.php')) ?>">
  <?= csrf_field() ?>

  <?php foreach ($GROUPS as $group => $fields): ?>
    <div class="panel">
      <h2><?= e($group) ?></h2>
      <?php foreach ($fields as $key => [$label, $type, $help]): ?>
        <?php $val = setting($key); ?>
        <?php if ($type === 'bool'): ?>
          <label class="check">
            <input type="checkbox" name="<?= e($key) ?>" value="1" <?= $val === '1' ? 'checked' : '' ?>>
            <span><?= e($label) ?>
              <?php if ($help): ?><br><span class="text-xs muted"><?= e($help) ?></span><?php endif; ?></span>
          </label>
        <?php else: ?>
          <div class="field">
            <label class="label" for="s-<?= e($key) ?>"><?= e($label) ?></label>
            <?php if ($type === 'textarea'): ?>
              <textarea class="textarea" id="s-<?= e($key) ?>" name="<?= e($key) ?>" rows="2"
                        style="min-height:70px"><?= e($val) ?></textarea>
            <?php else: ?>
              <input class="input" id="s-<?= e($key) ?>" name="<?= e($key) ?>"
                     type="<?= e($type === 'number' ? 'number' : ($type === 'email' ? 'email'
                            : ($type === 'url' ? 'url' : 'text'))) ?>"
                     <?= $type === 'number' ? 'step="0.01" min="0"' : '' ?>
                     value="<?= e($val) ?>">
            <?php endif; ?>
            <?php if ($help): ?><span class="help"><?= e($help) ?></span><?php endif; ?>
          </div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>

  <button class="btn btn-lg" type="submit">Save all settings</button>
</form>

<?php require_once YN_ROOT . '/includes/admin-footer.php'; ?>
