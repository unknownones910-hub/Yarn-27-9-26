<?php
/**
 * Site-wide configuration.
 * Safe to commit. Real secrets live in config/*-config.local.php (gitignored).
 */
declare(strict_types=1);

$SITE = [
    // ---- Brand -----------------------------------------------------------
    // Change the brand name HERE ONLY. It is never hardcoded anywhere else.
    'brand'        => 'The Yarn Nest',
    'tagline'      => 'Yarn that is a joy to work with',
    'legal_name'   => 'The Yarn Nest',              // registered business name
    'domain'       => 'localhost',                   // production: yarnnest.in

    // ---- Asset cache busting --------------------------------------------
    // Bump this whenever assets/css/style.css or assets/js/main.js changes.
    'asset_v'      => 90,

    // ---- Paths -----------------------------------------------------------
    'upload_dir'   => __DIR__ . '/../uploads',
    'upload_url'   => '/uploads',

    // ---- Locale ----------------------------------------------------------
    'currency'     => 'INR',
    'currency_sym' => '₹',
    'timezone'     => 'Asia/Kolkata',

    // ---- Security --------------------------------------------------------
    // DO NOT put the real pepper here. This file ships in the deploy zip and
    // goes into version control, so a secret written here is a published one.
    //
    // The real value belongs in config/site-config.local.php, which is
    // gitignored and never leaves the machine. That file is merged over this
    // one, so whatever it sets wins and this line is never used.
    //
    // This placeholder exists only so the site still runs if the .local file is
    // missing. It is deliberately recognisable: admin → System → Launch check
    // reports "OTP pepper changed from the shipped default" as BLOCKING while
    // this value is the one in effect.
    'otp_pepper'   => '2376bfd161eaa63818ea04f7bac0fa364b711df2982baf25efbef8cae91a0ace',

    'session_name'       => 'YARNNEST_SESS',
    'admin_session_name' => 'YARNNEST_ADM',
];

/**
 * Defaults for everything stored in the `settings` DB table.
 * The DB value always wins; this array only fills the gaps so the site never
 * breaks when a new setting is introduced before it has been saved once.
 */
$SETTINGS_DEFAULTS = [
    // Store identity (shown in footer / policies — legal requirement)
    'store_name'        => 'The Yarn Nest',
    'store_legal_name'  => 'The Yarn Nest',
    'store_address'     => '[Street address], [City], [State] [PIN], India',
    'store_phone'       => '+91 00000 00000',
    'store_whatsapp'    => '910000000000',
    'store_email'       => 'hello@example.com',
    'store_gstin'       => '',
    'grievance_name'    => '[Grievance Officer Name]',
    'grievance_email'   => 'grievance@example.com',
    'jurisdiction_city' => '[City]',
    'jurisdiction_state'=> '[State]',

    // Commerce
    'ship_flat_rate'      => '69',
    'ship_free_threshold' => '2499',
    'gift_wrap_fee'       => '49',
    'cod_enabled'         => '0',
    'gst_enabled'         => '0',
    'return_window_days'  => '7',
    'dispatch_days'       => '1-2',
    'order_prefix'        => 'YN',

    // Feature flags
    'feat_reviews'    => '1',
    'feat_blog'       => '1',
    'feat_patterns'   => '1',
    'feat_wholesale'  => '1',
    'feat_gift_wrap'  => '1',
    'feat_newsletter' => '1',

    // Numbers shown to customers — one switch per About counter, plus the
    // review count. See sql/015_stat_toggles.php.
    'stat_shades'        => '1',
    'stat_products'      => '1',
    'stat_orders'        => '1',
    'stat_cities'        => '1',
    'feat_review_counts' => '1',   // the "(n)" beside a star rating

    // Content
    'announcement_text' => 'Free shipping on orders above {{free_threshold}} · Ships across India',
    'announcement_on'   => '1',

    // Social
    'social_instagram' => '',
    'social_facebook'  => '',
    'social_youtube'   => '',
    'social_pinterest' => '',

    // SEO
    'seo_title'       => 'The Yarn Nest — Cotton, Acrylic & Wool Yarn Online in India',
    'seo_description' => 'Soft, honest yarn for crochet and knitting. Accurate shade photos, clear fibre labels, fast delivery across India.',
];

// ---------------------------------------------------------------------------
//  Per-environment overrides — config/site-config.local.php
//
//  THIS file ships in the deploy zip and belongs in version control, so it must
//  not hold secrets. Anything sensitive (otp_pepper) goes in the .local file,
//  which is gitignored and excluded from deploys, exactly like the db, Brevo and
//  Razorpay credentials.
//
//  Handy for the harmless things too — 'domain' differs between your laptop and
//  the live server.
// ---------------------------------------------------------------------------
$localSite = __DIR__ . '/site-config.local.php';
if (is_file($localSite)) {
    $override = require $localSite;
    if (is_array($override)) {
        $SITE = array_merge($SITE, $override);
    }
}
