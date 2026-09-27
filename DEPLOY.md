# Deployment

Target: cPanel shared hosting (GoDaddy-style), PHP 8.0+, MySQL, cURL, GD.

---

## 1. Before the first deploy — accounts to set up

### Brevo (transactional email)
Nothing sends until this is done, and OTP login depends on it.

1. Create a Brevo account and add your sending domain.
2. Add the DNS records Brevo gives you at your domain host:
   - **DKIM** (`mail._domainkey`) — the TXT record Brevo shows
   - **SPF** — include Brevo in your existing record, e.g.
     `v=spf1 include:spf.brevo.com ~all`
   - **DMARC** — `_dmarc` TXT, e.g. `v=DMARC1; p=none; rua=mailto:you@yourdomain`
3. Verify the sender address in Brevo.
4. Copy the API key (`xkeysib-…`).

> **Why the HTTPS API and not SMTP.** Shared hosts commonly firewall outbound
> SMTP to external servers, and mail relayed through the host's own Exim gets
> dropped when SPF is `-all` and MX points elsewhere. `api.brevo.com` is port
> 443, so it is never blocked, and Brevo signs with its own DKIM/SPF.

### Razorpay
1. Complete KYC on your Razorpay account.
2. Grab **test** keys now; swap to **live** keys at launch.
3. Register the webhook: `https://yourdomain/webhooks/razorpay.php`
   Events: `payment.captured`, `payment.failed`, `order.paid`, `refund.processed`
   Copy the webhook secret.

---

## 2. Package the site

Exclude these — they must never leave your machine:

```
config/*.local.php      secrets
sql/                    apply via phpMyAdmin instead
install.php             delete after use
_styleguide.php         internal
PLAN.md CLAUDE.md DEPLOY.md
.claude/ .git/ node_modules/
uploads/                upload real product images separately
```

Zip everything else and upload to `public_html` via cPanel File Manager.

---

## 3. Database

1. cPanel → **MySQL Databases** → create a database and a user, grant all privileges.
2. cPanel → **phpMyAdmin** → select the database → **Import** →
   run `sql/001_schema.sql`, then `sql/002_seed.sql`, in that order.
3. Any later `sql/0NN_*.sql` files: check `applied_migrations` and import
   whichever are missing. Migrations are additive — never edit an applied file.

---

## 4. Create the secret files on the server

These are excluded from the zip on purpose. Create them by hand in
`public_html/config/` every time you deploy fresh.

**`config/db-config.local.php`**
```php
<?php
return [
    'host' => 'localhost',
    'user' => 'cpaneluser_yarn',
    'pass' => 'THE-REAL-PASSWORD',
    'name' => 'cpaneluser_yarn_store',
];
```

**`config/brevo-config.local.php`**
```php
<?php
return [
    'api_key'    => 'xkeysib-...',
    'from_email' => 'hello@yourdomain.com',
    'from_name'  => 'The Yarn Nest',
    'reply_to'   => 'hello@yourdomain.com',
];
```

**`config/razorpay-config.local.php`**
```php
<?php
return [
    'key_id'         => 'rzp_live_...',
    'key_secret'     => '...',
    'webhook_secret' => '...',
    'mode'           => 'live',
];
```

---

## 5. Server-side settings

In `config/site-config.php`:
- `'domain'` → your real domain

**`config/site-config.local.php`** — create it on the server, like the other
`.local` files. It is merged over `site-config.php`, so list only what differs:

```php
<?php
return [
    'otp_pepper' => '…64 random hex characters…',
    'domain'     => 'yarnnest.in',
];
```

The **pepper is a secret and must live here, not in `site-config.php`**, because
that file ships in the deploy zip and goes into version control.

Why it matters: the site stores only `sha256(code . '|' . pepper)`, never the
code itself. A 6-digit code has a million possibilities and hashes in well under
a second, so if the database leaked and the pepper were the published default,
every pending code could be reversed instantly and any account signed into
inside the 10-minute window. Keeping the pepper in a file the database backup
never touches is what makes a database-only breach survivable.

Generate one with:

```bash
php -r "echo bin2hex(random_bytes(32));"
```

Set it **once, before go-live**. Changing it later invalidates in-flight codes
(harmless — they expire in 10 minutes), but anyone mid-sign-in has to request a
fresh one. If you forget to create this file on the server, the site silently
falls back to the shipped default — the Launch check flags that as blocking.

In `config/admin-config.php`:
- `'allowlist'` → your normalized Gmail (lowercase, dots and `+tags` stripped).
  It must also exist as an active row in `admin_users`. Both are required.

### Admin passwords

Signing in to `/admin` needs **four** things: an active `admin_users` row, the
address in that allowlist, a password, and a 6-digit code emailed to the Gmail.

`password_hash` is NULL until you set one, and NULL means the account cannot
sign in at all. So on a fresh deploy you must set a password for each admin:

```bash
printf '%s' 'your new password' | php tools/set-admin-password.php you@gmail.com "Your Name" owner
```

The password is read from STDIN so it does not land in your shell history, and
only a bcrypt hash is stored — nothing can recover it, so a forgotten password
is reset by running that command again. Minimum 12 characters, maximum 72
(bcrypt ignores anything past 72 bytes, so longer is refused rather than
silently trimmed).

After that, change it from **Admin → your name → Your account**, which asks for
the current password and emails the account when it changes. Five wrong
passwords locks the account for 15 minutes.

`/tools/` is blocked from the web in `.htaccess`, like `/sql/` and `/cron/`.
Verify that on the live host — `https://YOURDOMAIN/tools/set-admin-password.php`
must return 404.

Then in **Admin → Settings**, replace every `[bracketed]` placeholder:
store name, legal name, full postal address, phone, WhatsApp, email,
grievance officer name and email, and the jurisdiction city/state used in the
Terms. The address, phone and email in the footer are a legal requirement under
the Consumer Protection (E-Commerce) Rules 2020 — the site must not go live
with placeholders.

---

## 6. Post-deploy checklist

- [ ] `install.php` deleted from the server
- [ ] SSL certificate active; `http://` redirects to `https://` (the `.htaccess`
      rule does this automatically off localhost)
- [ ] Homepage, shop, a product page, cart and checkout all load
- [ ] Request an OTP — it arrives in Gmail's inbox, not Promotions or spam
- [ ] Place one real ₹1 order end-to-end, then refund it from the admin panel
- [ ] Kill the browser mid-payment — the webhook still marks the order paid
- [ ] `https://yourdomain/config/site-config.php` returns 403/404, not source
- [ ] `https://yourdomain/uploads/test.php` does not execute
- [ ] All eight policy pages load and contain no `[brackets]`
- [ ] Footer shows legal name, address, phone, email
- [ ] `?debug=1` reports zero overflow offenders at 320px on every page
- [ ] Submit `sitemap.xml` in Google Search Console

---

## 7. Backups

Set up in cPanel before launch, not after the first problem:

- **Daily** database dump, retained 30 days.
- **Weekly** full `public_html` backup — `/uploads` is the only irreplaceable
  directory; everything else can be redeployed from the zip.
- Download a copy off-server monthly. A backup that only lives on the server
  is not a backup.

---

## 8. Cron jobs

cPanel → **Cron Jobs**. Adjust the PHP path to match your host.

```bash
# release abandoned unpaid orders — hourly
0 * * * * /usr/local/bin/php /home/USER/public_html/cron/expire-pending-orders.php

# abandoned-cart reminders — every 6 hours
0 */6 * * * /usr/local/bin/php /home/USER/public_html/cron/abandoned-cart.php

# back-in-stock alerts — every 30 minutes
*/30 * * * * /usr/local/bin/php /home/USER/public_html/cron/restock-alerts.php

# review requests, 5 days after delivery — daily 10am
0 10 * * * /usr/local/bin/php /home/USER/public_html/cron/review-requests.php
```

All four are safe to run more often than listed and safe to run twice at once —
each one claims a row before it emails, so a slow mail queue cannot produce a
double send. Run any of them by hand first and read the output; they print what
they did and change nothing else.

`expire-pending-orders.php` is the important one. It is the safety net for a
payment the webhook never delivered: it asks Razorpay directly about every
still-pending order and marks the paid ones paid. Without it, a customer whose
webhook was lost has been charged and has no order.

---

## 9. Verify the deploy

Sign in to the admin and open **System → Launch check**. It re-runs every
automated check against the live environment: business details, policy pages,
gateway mode, webhook secret, email sender, catalog readiness, HTTPS, file
permissions, cron presence and compression. Nothing should be red.

Two things worth checking by hand because they fail silently:

**Compression.** The `.htaccess` compression block needs `mod_filter`, and where
it is missing the directive is skipped and every page ships uncompressed — about
ten times larger than it needs to be. There is a fallback for that case, but
confirm it took:

```bash
curl -sS -D - -H "Accept-Encoding: gzip" https://YOURDOMAIN/ -o /dev/null | grep -i content-encoding
```

You want `content-encoding: gzip`. Nothing means compression is off.

**Content-Security-Policy.** `script-src` is nonce-based, so an inline `<script>`
without the request's nonce will not run. If you or a developer later paste a
tracking snippet or a chat widget into a template, it will be silently blocked.
Give it `nonce="<?= e(csp_nonce()) ?>"`, or load it from a file and add its host
to the policy in `includes/bootstrap.php`. The browser console names the
directive that blocked it.

`pay.php` deliberately relaxes this back to `'unsafe-inline'` via
`csp_allow_inline_scripts()`, because Razorpay's checkout bundle is theirs, not
ours, and a blocked inline script there costs a real order.
