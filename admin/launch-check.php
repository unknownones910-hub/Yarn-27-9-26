<?php
/**
 * Pre-launch readiness check.
 *
 * Everything that can be verified automatically, verified automatically. The
 * things that cannot — a real payment, a real email landing in a real inbox —
 * are listed at the bottom as manual steps rather than quietly assumed.
 *
 * PLAN.md §17 is the source of this list.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-bootstrap.php';
require_once YN_ROOT . '/includes/payments/razorpay.php';
require_once YN_ROOT . '/includes/mailer.php';
require_once YN_ROOT . '/includes/invoice.php';

$admin = admin_guard('manager');

$checks = [];

/** @param string $level pass | warn | fail */
$add = static function (string $group, string $label, string $level,
                        string $detail = '', string $fix = '', string $fixUrl = '') use (&$checks): void {
    $checks[$group][] = compact('label', 'level', 'detail', 'fix', 'fixUrl');
};

// ---------------------------------------------------------------------
//  1. Legal and identity — the things that make the shop lawful to run
// ---------------------------------------------------------------------
$identityKeys = [
    'store_legal_name' => 'Registered business name',
    'store_address'    => 'Postal address',
    'store_phone'      => 'Phone number',
    'store_email'      => 'Support email',
    'grievance_name'   => 'Grievance officer name',
    'grievance_email'  => 'Grievance officer email',
    'jurisdiction_city'=> 'Jurisdiction city',
];

$placeholders = [];
foreach ($identityKeys as $k => $label) {
    $v = setting($k);
    if ($v === '' || preg_match('/\[[a-z ]+\]|example\.com|00000 00000/i', $v)) {
        $placeholders[] = $label;
    }
}

$add('Legal & identity', 'Business details are real',
    $placeholders ? 'fail' : 'pass',
    $placeholders
        ? 'Still placeholder: ' . implode(', ', $placeholders)
        : 'Name, address, phone, email and grievance officer are all set.',
    'Fill these in', url('admin/settings.php'));

$activePolicies = (int) db_val('SELECT COUNT(*) FROM pages WHERE is_active = 1');
$add('Legal & identity', 'All eight policy pages are live',
    $activePolicies >= 8 ? 'pass' : 'fail',
    $activePolicies . ' of 8 published.',
    'Review the pages', url('admin/pages.php'));

// Do any rendered policies still carry unresolved tokens or placeholders?
$badPolicy = [];
foreach (db_all('SELECT slug, title, body FROM pages WHERE is_active = 1') as $p) {
    if (preg_match('/\{\{[a-z_]+\}\}/', (string) $p['body'])) {
        // A token is fine in storage — it resolves at render. Only flag if the
        // setting behind it is itself a placeholder, which the check above
        // already covers. Look instead for literal bracket text in the body.
    }
    if (preg_match('/\[[a-z ]{3,}\]/i', (string) $p['body'])) {
        $badPolicy[] = (string) $p['title'];
    }
}
$add('Legal & identity', 'No placeholder text left in policies',
    $badPolicy ? 'warn' : 'pass',
    $badPolicy ? 'Check: ' . implode(', ', $badPolicy) : 'Clean.',
    'Edit pages', url('admin/pages.php'));

$add('Legal & identity', 'Country of origin on every product',
    (int) db_val('SELECT COUNT(*) FROM products
                  WHERE is_active = 1 AND (country_of_origin IS NULL OR country_of_origin = "")') === 0
        ? 'pass' : 'fail',
    'Required by the Legal Metrology packaged-commodity rules.',
    'Check products', url('admin/products.php'));

// ---------------------------------------------------------------------
//  2. Payments
// ---------------------------------------------------------------------
$add('Payments', 'Razorpay keys configured',
    rzp_configured() ? 'pass' : 'fail',
    rzp_configured()
        ? 'Mode: ' . (rzp_is_live() ? 'LIVE' : 'test')
        : 'config/razorpay-config.local.php has no key_id.',
    'See DEPLOY.md');

$add('Payments', 'Running in live mode',
    !rzp_configured() ? 'fail' : (rzp_is_live() ? 'pass' : 'warn'),
    rzp_is_live() ? 'Live keys in use.' : 'Still on test keys — no real money will move.');

$hasWebhookSecret = rzp_config()['webhook_secret'] !== '';
$add('Payments', 'Webhook secret set',
    $hasWebhookSecret ? 'pass' : 'fail',
    $hasWebhookSecret
        ? 'Signature verification is active.'
        : 'Without this the webhook rejects everything, and paid orders will not be marked paid.');

$hookCount = (int) db_val('SELECT COUNT(*) FROM payment_webhooks');
$add('Payments', 'Webhook has been received at least once',
    $hookCount > 0 ? 'pass' : 'warn',
    $hookCount > 0 ? $hookCount . ' delivered.' : 'Nothing yet — register the endpoint and test it.',
    'View log', url('admin/logs.php?tab=webhook'));

$orphanPaid = (int) db_val(
    'SELECT COUNT(DISTINCT o.id) FROM orders o
     JOIN payments p ON p.order_id = o.id AND p.status = "captured"
     WHERE o.payment_status <> "paid"'
);
$add('Payments', 'No captured payments left unreconciled',
    $orphanPaid === 0 ? 'pass' : 'fail',
    $orphanPaid === 0 ? 'Every captured payment has a paid order.'
                      : $orphanPaid . ' order(s) captured at the gateway but not marked paid.',
    'Check orders', url('admin/orders.php'));

// ---------------------------------------------------------------------
//  3. Email
// ---------------------------------------------------------------------
$transport = mail_transport();
$add('Email', 'A mail transport is working',
    $transport === 'none' ? 'fail' : 'pass',
    $transport === 'none'
        ? 'Neither SMTP nor Brevo is configured. OTP sign-in and every order email '
          . 'will silently not send, and nobody — including you — can sign in.'
        : 'Sending via ' . strtoupper($transport) . '.',
    'See DEPLOY.md');

$smtp = smtp_config();
$add('Email', 'SMTP credentials set',
    smtp_configured() ? 'pass' : (($smtp['transport'] ?? '') === 'smtp' ? 'warn' : 'pass'),
    smtp_configured()
        ? $smtp['username'] . ' via ' . $smtp['host'] . ':' . $smtp['port']
        : 'No app password set, so SMTP is inert'
          . (brevo_configured() ? ' and mail is going out over Brevo instead.' : '.'));

// A host that blocks outbound SMTP is the single most common reason mail works
// locally and dies in production, so prove the port is actually open from here.
$smtpReachable = null;
if (smtp_configured() || ($smtp['transport'] ?? '') === 'smtp') {
    $fp = @fsockopen($smtp['host'], (int) $smtp['port'], $eno, $estr, 5);
    $smtpReachable = (bool) $fp;
    if ($fp) { fclose($fp); }
}
if ($smtpReachable !== null) {
    $add('Email', 'Outbound SMTP port is open',
        $smtpReachable ? 'pass' : 'fail',
        $smtpReachable
            ? 'This server can reach ' . $smtp['host'] . ':' . $smtp['port'] . '.'
            : 'This server CANNOT reach ' . $smtp['host'] . ':' . $smtp['port']
              . '. Many shared hosts block outbound SMTP and there is no way round it '
              . 'from PHP. Set transport to "brevo" — its HTTPS API is not blocked.');
}

$sender = $transport === 'smtp' ? ($smtp['from_email'] ?? '') : brevo_config()['from_email'];
$add('Email', 'Sender address is on your own domain',
    ($sender !== '' && !str_contains($sender, 'example.com')
        && !preg_match('/@(gmail|yahoo|outlook|hotmail)\./i', $sender)) ? 'pass' : 'warn',
    'From: ' . ($sender ?: 'not set')
        . '. A free-mail sender fails DMARC at most providers and lands in spam.');

$failed7 = (int) db_val('SELECT COUNT(*) FROM email_log
                         WHERE status = "failed" AND created_at > (NOW() - INTERVAL 7 DAY)');
$add('Email', 'No recent send failures',
    $failed7 === 0 ? 'pass' : 'warn',
    $failed7 === 0 ? 'Nothing failed in the last 7 days.' : $failed7 . ' failed in the last 7 days.',
    'View log', url('admin/logs.php?tab=email'));

// ---------------------------------------------------------------------
//  4. Catalog readiness
// ---------------------------------------------------------------------
$liveProducts = (int) db_val('SELECT COUNT(*) FROM products WHERE is_active = 1');
$add('Catalog', 'Products are live',
    $liveProducts > 0 ? 'pass' : 'fail', $liveProducts . ' active.',
    'Manage', url('admin/products.php'));

// Counted per SHADE, not per product: the gallery is one photo per shade, so a
// product with six shades and one photo still shows five placeholders.
$shadesNoPhoto = (int) db_val(
    'SELECT COUNT(*) FROM product_variants v
     JOIN products p ON p.id = v.product_id
     WHERE p.is_active = 1 AND v.is_active = 1
       AND (v.image IS NULL OR v.image = "")'
);
$shadesTotal = (int) db_val(
    'SELECT COUNT(*) FROM product_variants v
     JOIN products p ON p.id = v.product_id
     WHERE p.is_active = 1 AND v.is_active = 1'
);
$noPhoto = $shadesNoPhoto;
$add('Catalog', 'Every shade has a real photograph',
    $noPhoto === 0 ? 'pass' : 'warn',
    $noPhoto === 0
        ? $shadesTotal . ' shade(s), all photographed.'
        : $noPhoto . ' of ' . $shadesTotal . ' shade(s) still show the generated placeholder. '
          . 'Photography is the single biggest driver of yarn sales.',
    'Add photos', url('admin/products.php'));

$noShades = (int) db_val(
    'SELECT COUNT(*) FROM products p WHERE p.is_active = 1
     AND NOT EXISTS (SELECT 1 FROM product_variants v WHERE v.product_id = p.id AND v.is_active = 1)'
);
$add('Catalog', 'Every live product has at least one shade',
    $noShades === 0 ? 'pass' : 'fail',
    $noShades === 0 ? 'All good.' : $noShades . ' product(s) cannot be added to a cart.');

$allOut = (int) db_val(
    'SELECT COUNT(*) FROM products p WHERE p.is_active = 1
     AND NOT EXISTS (SELECT 1 FROM product_variants v
                     WHERE v.product_id = p.id AND v.is_active = 1 AND v.stock > 0)'
);
$add('Catalog', 'Products are actually buyable',
    $allOut === 0 ? 'pass' : 'warn',
    $allOut === 0 ? 'Everything has stock.' : $allOut . ' live product(s) are completely out of stock.',
    'Inventory', url('admin/inventory.php'));

// Demo data left behind?
$demo = (int) db_val('SELECT COUNT(*) FROM orders WHERE order_no LIKE "TEST-%"');
$add('Catalog', 'No test data left in the database',
    $demo === 0 ? 'pass' : 'warn',
    $demo === 0 ? 'Clean.' : $demo . ' order(s) with a TEST- number are still present.');

// ---------------------------------------------------------------------
//  5. Security & environment
// ---------------------------------------------------------------------
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
$add('Security', 'Served over HTTPS',
    $isHttps ? 'pass' : (is_local_dev() ? 'warn' : 'fail'),
    $isHttps ? 'Secure.' : (is_local_dev()
        ? 'Local development — expected. Must be HTTPS in production.'
        : 'Payment pages and sign-in MUST be over HTTPS.'));

$installerPresent = is_file(YN_ROOT . '/install.php');
$add('Security', 'Installer removed',
    $installerPresent ? (is_local_dev() ? 'warn' : 'fail') : 'pass',
    $installerPresent
        ? 'install.php still exists. Delete it before or immediately after deploying.'
        : 'Deleted.');

global $SITE;
$pepperDefault = str_contains((string) ($SITE['otp_pepper'] ?? ''), 'change_me');
$add('Security', 'OTP pepper changed from the shipped default',
    $pepperDefault ? 'fail' : 'pass',
    $pepperDefault
        ? "config/site-config.php still holds the default otp_pepper."
        : 'Custom value in use.');

require YN_ROOT . '/config/admin-config.php';
$allowlist   = $ADMIN['allowlist'] ?? [];
$adminRows   = db_col('SELECT email_key FROM admin_users WHERE is_active = 1');
$unlisted    = array_diff($adminRows, $allowlist);
$ghostListed = array_diff($allowlist, $adminRows);
$add('Security', 'Admin accounts and allowlist agree',
    (!$unlisted && !$ghostListed) ? 'pass' : 'warn',
    (!$unlisted && !$ghostListed)
        ? count($adminRows) . ' admin(s), all allowlisted.'
        : trim(($unlisted ? 'Active but not allowlisted (cannot sign in): '
                . implode(', ', $unlisted) . '. ' : '')
             . ($ghostListed ? 'Allowlisted with no active account: '
                . implode(', ', $ghostListed) . '.' : '')),
    'Admin users', url('admin/users.php'));

$noPassword = db_col('SELECT email FROM admin_users WHERE is_active = 1 AND password_hash IS NULL');
$add('Security', 'Every admin has a password set',
    $noPassword ? 'warn' : 'pass',
    $noPassword
        ? 'Cannot sign in (no password): ' . implode(', ', $noPassword)
          . '. Set one with tools/set-admin-password.php, or deactivate the account.'
        : count($adminRows) . ' admin(s), all with a password. Sign-in needs the password '
          . 'AND an emailed code.',
    'Admin users', url('admin/users.php'));

$uploadDir = $SITE['upload_dir'];
$add('Security', 'Uploads folder is writable and protected',
    (is_dir($uploadDir) && is_writable($uploadDir) && is_file($uploadDir . '/.htaccess'))
        ? 'pass' : 'fail',
    is_file($uploadDir . '/.htaccess')
        ? 'Writable, with PHP execution disabled.'
        : 'Missing /uploads/.htaccess — an uploaded script could be executed.');

$add('Security', 'Style guide removed',
    is_file(YN_ROOT . '/_styleguide.php') ? 'warn' : 'pass',
    is_file(YN_ROOT . '/_styleguide.php')
        ? 'Harmless (noindexed) but serves no purpose in production.' : 'Gone.');

$cspHeader = '';
foreach (headers_list() as $h) {
    if (stripos($h, 'Content-Security-Policy:') === 0) { $cspHeader = $h; }
}
$cspStrict = $cspHeader !== '' && str_contains($cspHeader, "'nonce-");
$add('Security', 'Script CSP is nonce-based',
    $cspStrict ? 'pass' : ($cspHeader === '' ? 'fail' : 'warn'),
    $cspStrict
        ? "Inline scripts need a matching nonce, so injected markup cannot run."
        : ($cspHeader === '' ? 'No CSP header at all.'
            : "script-src still allows 'unsafe-inline', which defeats most of the policy."));

// ---------------------------------------------------------------------
//  6. Performance
// ---------------------------------------------------------------------
// Ask our own front page for a compressed response. This is the only way to
// know: the .htaccess directive is silently skipped when mod_filter is missing,
// and the site then ships every page uncompressed with nothing to warn you.
$gzip = null;
if (function_exists('curl_init')) {
    $ch = curl_init(abs_url(''));
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_NOBODY => false,
        CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_ENCODING => '',                      // send Accept-Encoding: gzip
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => ['Accept-Encoding: gzip'],
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($resp !== false && $code === 200) {
        $gzip = (bool) preg_match('~^content-encoding:\s*(gzip|br|deflate)~mi', $resp);
    }
}
$add('Performance', 'Responses are compressed',
    $gzip === true ? 'pass' : ($gzip === false ? 'fail' : 'warn'),
    $gzip === true
        ? 'gzip is active — pages go out roughly 90% smaller.'
        : ($gzip === false
            ? 'The front page came back uncompressed. Every page is ~10x larger than it '
              . 'needs to be, which is felt hardest on Indian mobile data.'
            : 'Could not check from the server. Test it yourself with: '
              . 'curl -sSI -H "Accept-Encoding: gzip" ' . abs_url('')),
    $gzip === false ? 'See .htaccess' : '');

$add('Performance', 'Asset cache-busting version is set',
    (int) ($SITE['asset_v'] ?? 0) > 0 ? 'pass' : 'warn',
    'asset_v = ' . (int) ($SITE['asset_v'] ?? 0)
        . '. Bump it in config/site-config.php whenever style.css or main.js changes.');

// ---------------------------------------------------------------------
//  7. Operations
// ---------------------------------------------------------------------
$add('Operations', 'Invoice PDFs can be generated',
    invoice_available() ? 'pass' : 'fail',
    invoice_available() ? 'FPDF present in /vendor.'
                        : 'vendor/setasign/fpdf missing — invoices will not attach to emails.');

$crons = ['expire-pending-orders.php', 'abandoned-cart.php', 'restock-alerts.php', 'review-requests.php'];
$missing = array_values(array_filter($crons, static fn($f) => !is_file(YN_ROOT . '/cron/' . $f)));
$add('Operations', 'Cron scripts present',
    $missing ? 'fail' : 'pass',
    $missing ? 'Missing: ' . implode(', ', $missing)
             : '4 scripts. Schedule them per DEPLOY.md — the reconciliation job is the safety '
               . 'net for payments the webhook missed.');

$stalePending = (int) db_val(
    'SELECT COUNT(*) FROM orders
     WHERE status = "pending_payment" AND placed_at < (NOW() - INTERVAL 2 DAY)'
);
$add('Operations', 'Pending orders are being expired',
    $stalePending === 0 ? 'pass' : 'warn',
    $stalePending === 0 ? 'None stale.'
        : $stalePending . ' unpaid order(s) older than 2 days — is the hourly cron running?');

$shipFree = setting_money('ship_free_threshold', 0);
$add('Operations', 'Delivery charges configured',
    setting('ship_flat_rate') !== '' ? 'pass' : 'warn',
    'Flat ' . money(setting_money('ship_flat_rate', 0))
        . ($shipFree > 0 ? ', free above ' . money($shipFree) : ', no free-delivery threshold'),
    'Settings', url('admin/settings.php'));

$pincodes = (int) db_val('SELECT COUNT(*) FROM pincodes');
$add('Operations', 'Pincode data loaded',
    $pincodes > 50 ? 'pass' : 'warn',
    $pincodes . ' pincodes. Unknown ones fall back to the default ETA, so this is not blocking — '
        . 'but a fuller dataset gives better delivery estimates.');

// ---------------------------------------------------------------------
//  Tally
// ---------------------------------------------------------------------
$counts = ['pass' => 0, 'warn' => 0, 'fail' => 0];
foreach ($checks as $group) {
    foreach ($group as $c) {
        $counts[$c['level']]++;
    }
}
$ready = $counts['fail'] === 0;

$pageTitle    = 'Launch check';
$adminSection = 'launch';

require_once YN_ROOT . '/includes/admin-header.php';
?>

<div class="admin-head">
  <div class="min0">
    <h1>Launch check</h1>
    <p>Everything that can be verified automatically. Re-run this after every deploy.</p>
  </div>
</div>

<div class="kpi-grid mb5">
  <div class="kpi<?= $ready ? '' : ' kpi--alert' ?>">
    <p class="kpi__label"><?= icon($ready ? 'check-circle' : 'alert', 14) ?> Verdict</p>
    <p class="kpi__value" style="font-size:1.25rem">
      <?= $ready ? 'No blockers' : 'Not ready' ?></p>
    <p class="kpi__sub"><?= $ready
        ? 'Complete the manual steps below.'
        : $counts['fail'] . ' blocking issue' . ($counts['fail'] === 1 ? '' : 's') ?></p>
  </div>
  <div class="kpi"><p class="kpi__label">Passing</p>
    <p class="kpi__value" style="color:var(--success)"><?= $counts['pass'] ?></p></div>
  <div class="kpi<?= $counts['warn'] ? ' kpi--warn' : '' ?>"><p class="kpi__label">Worth a look</p>
    <p class="kpi__value"><?= $counts['warn'] ?></p></div>
  <div class="kpi<?= $counts['fail'] ? ' kpi--alert' : '' ?>"><p class="kpi__label">Blocking</p>
    <p class="kpi__value"><?= $counts['fail'] ?></p></div>
</div>

<?php foreach ($checks as $group => $items): ?>
  <div class="panel panel--flush">
    <h2><?= e($group) ?></h2>
    <div class="scroll-x">
      <table class="admin-table" style="min-width:620px">
        <tbody>
          <?php foreach ($items as $c): ?>
            <tr>
              <td class="tight" style="width:1%">
                <?php if ($c['level'] === 'pass'): ?>
                  <span style="color:var(--success);line-height:0"><?= icon('check-circle', 18) ?></span>
                <?php elseif ($c['level'] === 'warn'): ?>
                  <span style="color:var(--warning);line-height:0"><?= icon('alert', 18) ?></span>
                <?php else: ?>
                  <span style="color:var(--danger);line-height:0"><?= icon('alert', 18) ?></span>
                <?php endif; ?>
              </td>
              <td>
                <strong><?= e($c['label']) ?></strong>
                <?php if ($c['detail'] !== ''): ?>
                  <br><span class="text-xs muted"><?= e($c['detail']) ?></span>
                <?php endif; ?>
              </td>
              <td class="tight">
                <?php if ($c['level'] !== 'pass' && $c['fix'] !== ''): ?>
                  <?php if ($c['fixUrl'] !== ''): ?>
                    <a class="btn-link" href="<?= e($c['fixUrl']) ?>"><?= e($c['fix']) ?></a>
                  <?php else: ?>
                    <span class="text-xs muted"><?= e($c['fix']) ?></span>
                  <?php endif; ?>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endforeach; ?>

<div class="panel" style="background:var(--warning-tint);border-color:#EFDCB6">
  <h2>Only you can do these</h2>
  <p class="text-sm">
    No script can confirm these. Work through them before you take a real order.
  </p>
  <ul class="stack-sm text-sm mt4">
    <li>· <strong>Place one real ₹1 order with live keys</strong>, then refund it from the order
        page. This is the only way to know the whole chain works end to end.</li>
    <li>· <strong>Close the browser mid-payment</strong> on a test order and confirm the webhook
        still marks it paid.</li>
    <li>· <strong>Request a sign-in code</strong> and check it arrives in the Gmail
        <em>inbox</em>, not Promotions or spam.</li>
    <li>· <strong>Verify your sending domain in Brevo</strong> — DKIM, the SPF include and a
        DMARC record at your DNS host.</li>
    <li>· <strong>Register the webhook</strong> at
        <code>/webhooks/razorpay.php</code> for <code>payment.captured</code>,
        <code>payment.failed</code>, <code>order.paid</code> and <code>refund.processed</code>.</li>
    <li>· <strong>Confirm <code>/config/</code> and <code>/uploads/x.php</code> are not served</strong>
        on the live host.</li>
    <li>· <strong>Set up daily database backups</strong> in cPanel and download one to check it
        restores.</li>
    <li>· <strong>Submit the sitemap</strong> at
        <a href="<?= e(abs_url('sitemap.xml')) ?>" target="_blank" rel="noopener">/sitemap.xml</a>
        in Google Search Console.</li>
    <li>· <strong>Open the shop on a real phone</strong> and buy something. Emulators miss things.</li>
  </ul>
</div>

<p class="text-sm muted mt5">
  The full pre-launch list lives in <code>PLAN.md</code> §17, and deployment steps in
  <code>DEPLOY.md</code>.
</p>

<?php require_once YN_ROOT . '/includes/admin-footer.php'; ?>
