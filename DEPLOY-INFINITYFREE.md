# Deploying to InfinityFree

Read the warning first. It is not a formality — two of the limitations below can
lose you money silently, and a payments site is the worst place for that.

---

## Read this before you commit to InfinityFree

InfinityFree is genuinely good free hosting. It is a **poor fit for a shop that
takes payments**, for three specific reasons.

### 1. No cron jobs — this is the serious one

Cron is a paid feature. Your site has four scheduled scripts, and one of them is
not optional:

`cron/expire-pending-orders.php` is the **safety net for payments the webhook
missed**. It asks Razorpay directly about every still-pending order and marks the
paid ones paid.

Now consider what happens without it. A customer pays. Razorpay's webhook fails to
reach you — network blip, host hiccup, anything. Nothing marks the order paid.
Normally the cron catches it within the hour. With no cron, **the customer has been
charged and you have no order**. They contact you angry, or they don't and you
simply never ship. You would find out from a chargeback.

There is a workaround (below) using a free external cron service, and it does work.
But you are adding a moving part to the part of the system that handles money.

### 2. The bot check can eat your webhooks

InfinityFree puts a JavaScript/cookie challenge in front of free-hosted sites to
block bots. A browser runs the JS, gets a cookie, and passes. **Razorpay's webhook
is not a browser.** It POSTs JSON and cannot execute JavaScript, so it can receive
the challenge page instead of reaching your PHP — and your webhook silently never
fires.

Combined with point 1, both the primary path and the backup for recording payments
are compromised at once. Test this specifically before taking a real order.

### 3. Free hosting is not meant for commercial use

Check InfinityFree's current Terms of Service yourself before you launch a shop on
it. Free hosts commonly restrict commercial and e-commerce use, and having your
account suspended mid-trading is a bad way to find out.

### What I would actually do

Use InfinityFree as a **staging site** — to check the deploy works, click through
every page on a real domain over real HTTPS, and confirm emails arrive. That is
genuinely valuable and costs nothing.

Then move to cheap paid hosting before you accept a rupee. Indian shared hosting
with cron, real SSL and no bot-check runs roughly **₹100–250/month** (Hostinger,
MilesWeb, Hostinger's India plans, etc.). Against a single lost order, it pays for
itself immediately.

If you want to go ahead on InfinityFree anyway, everything below works.

---

## What the site needs

| Requirement | InfinityFree | Notes |
|---|---|---|
| PHP 8.0+ | yes — selectable | The code is PHP 8.0. Do not pick 7.4. |
| MySQL | yes, via phpMyAdmin | No remote MySQL access |
| Outbound HTTPS (cURL) | usually yes | Brevo and Razorpay APIs both need it — **verify early** |
| Free SSL | yes | Required for payments |
| Cron | **no** | Needs the workaround below |
| Shell/SSH | **no** | Hence the web installer |
| Storage | ~2.5 MB of code | Not a problem |
| Files | ~280 including vendor | Watch the inode limit as uploads grow |

---

## Step 1 · Prepare locally

**a. Set your domain.** In `config/site-config.local.php`:

```php
<?php
return [
    'otp_pepper'    => '…your 64-char value…',
    'domain'        => 'yarnnest.rf.gd',   // your InfinityFree subdomain
    'install_token' => '…a second random string…',
];
```

Generate both with:

```bash
php -r "echo bin2hex(random_bytes(32));"
```

`install_token` is what lets the installer run on a real domain. You delete it
afterwards.

**b. Confirm your admin address is allowlisted.** `config/admin-config.php` must
contain the normalized Gmail you will sign in with. This file is **not** editable
from the web, by design.

**c. Build the upload set.** Take everything EXCEPT:

```
sql/                 (applied by the installer — but see step 4)
.git/ .claude/
PLAN.md CLAUDE.md DEPLOY.md DEPLOY-INFINITYFREE.md YOUR-CHECKLIST.md
_styleguide.php
```

Keep `install.php` for now — you delete it in step 7.

Keep `config/*.local.php`? **No.** Upload them separately in step 3 so you never
have them sitting in a zip on a shared machine.

---

## Step 2 · Create the hosting

1. Sign up at InfinityFree, create a hosting account.
2. Note the free subdomain (e.g. `yarnnest.rf.gd`) or attach your own domain.
3. **Control Panel → PHP Configuration → set PHP 8.0 or higher.**
4. **Control Panel → SSL/TLS → issue a free certificate.** Wait for it to go
   active; it can take an hour or two. Do not skip this.

---

## Step 3 · Upload

Use FTP (FileZilla) — the online File Manager is slow for hundreds of files.

- Host: your FTP hostname from the control panel
- Upload everything into **`htdocs/`**, not the account root

Then create the three secrets files directly on the server, via File Manager:

- `config/site-config.local.php` — pepper, domain, install token
- `config/brevo-config.local.php` — your Brevo API key and sender
- `config/razorpay-config.local.php` — live keys and webhook secret

Confirm each returns **404** in a browser. If any of them displays content, stop
and fix `.htaccess` before going further.

---

## Step 4 · Database

**Do not import the `sql/` folder through phpMyAdmin.** It will not work, and the
half-built result is worse than nothing. See the box below.

1. **Control Panel → MySQL Databases → create one.** Note the host, name, user and
   password — InfinityFree prefixes everything, e.g. `if0_42589813_db_yarnnest`.
   The installer does **not** try to create the database; it expects the one you
   made here, because a shared-hosting MySQL user has no privilege to create one.
2. Create `config/db-config.local.php` on the server with those values. The `name`
   must be the **full prefixed name** exactly as the control panel lists it.
3. Upload the `sql/` folder temporarily.
4. Visit `https://yourdomain/install.php?token=YOUR_INSTALL_TOKEN`
5. Press **Run installer**, then create your admin on the next screen.
6. **Delete `install.php` and the `sql/` folder.**

### Why phpMyAdmin cannot do this

Of the ten migrations, **only four are `.sql`**. The other six are PHP, because
they do things SQL files cannot do safely:

| Migration | Why it is PHP |
|---|---|
| `005_content.php` | ~3,500 words of policy prose through prepared statements — escaping that into SQL literals invites silent truncation |
| `006_journal.php` | Four Journal articles, same reason |
| `007_admin_password.php` | Adds password columns to `admin_users`, checking first so it is re-runnable |
| `008_customer_password.php` | The same for `customers` |
| `009_password_faqs.php` | Rewrites FAQ text, matched on content |
| `010_remove_seeded_admin.php` | Retires the old hardcoded admin row |

Import only the four `.sql` files and you get a database with **no policy pages,
no FAQs, no Journal, and — critically — no password columns**. Sign-in would fatal
on the first attempt, because the code queries a column that does not exist.

The installer runs all ten in order and records what it has applied, so it is safe
to run twice.

---

## Step 5 · Create your admin

No admin account ships with the code. After the migrations run, the installer shows
a **Create the first admin** form. Enter the Gmail address already in your
allowlist, plus a password of at least 12 characters.

If it refuses, it will tell you the address is not allowlisted — fix
`config/admin-config.php` and reload.

---

## Step 6 · Cron replacement

This is the workaround for the missing cron. Do not skip it.

The scripts are CLI-only and `/cron/` is blocked in `.htaccess`, so they need a
web-callable trigger guarded by a secret. **Ask me and I will build
`cron-run.php`** — a single endpoint that takes `?token=…&job=…`, refuses
everything else, and runs the same code.

Then register the jobs free at **cron-job.org**:

| URL | How often |
|---|---|
| `…/cron-run.php?token=…&job=expire-pending-orders` | hourly — **the important one** |
| `…/cron-run.php?token=…&job=abandoned-cart` | every 6 hours |
| `…/cron-run.php?token=…&job=restock-alerts` | every 30 minutes |
| `…/cron-run.php?token=…&job=review-requests` | daily |

Then confirm it actually runs — InfinityFree's bot check may block cron-job.org
exactly as it can block webhooks. Check `email_log` and the order list after the
first hour rather than assuming.

---

## Step 7 · Lock it down

1. **Delete `install.php`.**
2. **Delete the `sql/` folder** from the server.
3. Remove `install_token` from `config/site-config.local.php`.
4. Confirm `https://yourdomain/install.php` returns 404.

---

## Step 8 · Verify

Sign in and open **Admin → System → Launch check**. Work down anything red.

Then the checks that matter most on this host specifically:

- **Outbound HTTPS works.** Request a sign-in code on the live site. If it never
  arrives and `email_log` shows a failure, InfinityFree is blocking outbound cURL
  and **the site cannot function** — nobody can even sign in. Find out now.
- **Compression.** `curl -sS -D - -H "Accept-Encoding: gzip" https://yourdomain/ -o /dev/null | grep -i content-encoding`
- **The webhook reaches PHP.** In the Razorpay dashboard, send a test webhook to
  `https://yourdomain/webhooks/razorpay.php`. Then check the `payment_webhooks`
  table. **If the row is missing, the bot check ate it** — and you must not take
  real payments until that is solved.
- **One real ₹1 order** on live keys, then refund it.

---

## If it goes wrong

| Symptom | Likely cause |
|---|---|
| Blank white page | PHP version set below 8.0 |
| "Installer disabled" | `install_token` missing or `?token=` does not match |
| 500 on every page | `.htaccess` — comment out the compression block and retest |
| No emails at all | Outbound cURL blocked, or Brevo key wrong |
| Webhook never arrives | The bot check. This is the blocker described above |
| Orders stuck "pending payment" | Cron replacement not running |
