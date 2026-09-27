# What you need to do

Everything that could be built is built and verified. What's left needs real-world
things I can't create: accounts, credentials, your business details, and photographs.

Work top to bottom. **Part 1 is the critical path** — the shop cannot legally or
technically open until those are done. Everything after it improves the shop but
doesn't block it.

Check your progress at any time: **Admin → System → Launch check**. It re-runs
every automated test against your live site.

---

## Part 1 · Blocks launch

These are the six red items on the Launch check right now.

### 1. Your real business details

**Admin → Settings.** Every `[bracketed]` value is a placeholder.

| Setting | Currently |
|---|---|
| Postal address | `[Street address], [City], [State] [PIN], India` |
| Phone | `+91 00000 00000` |
| Support email | `hello@example.com` |
| Grievance officer name | `[Grievance Officer Name]` |
| Grievance officer email | `grievance@example.com` |
| Jurisdiction city / state | `[City]` / `[State]` |

Not optional. Under the Consumer Protection (E-Commerce) Rules 2020 an Indian
online seller must publish a contact address, phone, email and a named grievance
officer. These same values flow into all eight policy pages automatically — you
edit them once here, not in eight documents.

### 2. Razorpay

1. Create the account and complete KYC (needs PAN, bank account, business proof).
2. Copy **live** Key ID and Key Secret into `config/razorpay-config.local.php`.
3. In the Razorpay dashboard add a webhook:
   - URL: `https://YOURDOMAIN/webhooks/razorpay.php`
   - Events: `payment.captured`, `payment.failed`, `order.paid`, `refund.processed`
   - Copy the webhook secret into the same config file.

The webhook secret matters more than it looks. Without it the site rejects every
webhook, and a customer whose browser closed mid-payment has been charged with no
order recorded.

### 3. Brevo (all email)

1. Create a Brevo account.
2. Verify your **sending domain** — add the DKIM and SPF records they give you at
   your DNS host, plus a DMARC record.
3. Create an API key, put it in `config/brevo-config.local.php`.
4. Set the from-address to something on your own domain (`orders@yourdomain.com`),
   not Gmail — a Gmail from-address fails DMARC at most providers and lands in spam.

**Without this nobody can sign in at all**, including you. Sign-in codes, order
confirmations, invoices and admin login codes all go through Brevo.

### 4. Change the OTP pepper

**Done on this machine** — a random 64-character value now lives in
`config/site-config.local.php`. You must **recreate that file on the live server**,
because it is gitignored and excluded from deploy zips exactly like your Brevo and
Razorpay credentials.

```bash
php -r "echo bin2hex(random_bytes(32));"
```

Put the result in `config/site-config.local.php` on the server:

```php
<?php
return ['otp_pepper' => '…the value…', 'domain' => 'yourdomain.com'];
```

Why: sign-in codes are stored only as `sha256(code + pepper)`. A 6-digit code has
a million possibilities and brute-forces in under a second, so if your database
ever leaked and the pepper were the published default, every pending code could be
reversed. The pepper lives in a file the database backup never touches.

If you forget this file on the server the site falls back to the shipped default —
the Launch check catches that and marks it blocking.

### 5. HTTPS

Buy the domain and hosting, enable SSL (free via Let's Encrypt in cPanel). Payment
pages and sign-in must not run over plain HTTP.

### 6. Delete `install.php`

Right after the site is up on the live server. It creates and migrates the database;
it must not stay reachable.

---

## Part 2 · The one thing that decides whether you sell anything

**Photographs. All 29 products currently show a generated placeholder.**

I flagged this in the original plan and it's still the single biggest gap. Yarn is
bought on colour and texture. A grey placeholder converts at roughly zero, no matter
how good the rest of the site is.

For each yarn you need:
- 3+ shots on a consistent light background
- 1 shot of the yarn held in a hand, for scale and texture
- 1 photo per shade

Plus 3–5 lifestyle/hero images for the homepage.

You don't need a studio. Daylight near a window, a white sheet, a phone on a stable
surface, and the same setup every time will beat inconsistent professional shots.
Shoot every shade in one session so the colours are comparable — that consistency is
what makes a shade grid look trustworthy.

Upload at **Admin → Products → [product] → Images**. The site generates the
320/640/1200px versions itself.

---

## Part 3 · By phase

### Phase 0 — Foundation
- [ ] Domain, hosting, SSL, cPanel access
- [ ] Final store name (currently "The Yarn Nest") and registered legal name
- [ ] Logo as SVG + PNG — there's a text wordmark now, which is fine but plain
- [ ] Confirm the warm-craft colour palette or tell me to change it

### Phase 1 — Catalog
- [ ] Real product data for each yarn: fibre composition, weight category, ply,
      ball weight, length in metres, hook/needle size, care instructions, price, MRP
- [ ] Every shade with its name, code and photo
- [ ] Country of origin per product (legally required on packaged goods)
- [ ] The 29 seeded products are realistic examples — replace or edit them at
      **Admin → Products**

### Phase 2 — Sign-in
- [ ] Brevo verified (Part 1 item 3)
- [ ] Request a code on your live site and confirm it lands in the Gmail **inbox**,
      not Promotions or spam. If it lands in Promotions, your DNS records need work.
- [ ] Sign up once yourself end to end: address → emailed code → choose a password
      → signed in. Then sign out and back in with just the password.

Customers now sign in with **email and password**. The emailed code appears only
on the very first sign-in — it proves the Gmail is theirs before the account
exists — and again if they forget the password. Guest checkout is unchanged and
still uses a code only, so nobody is forced to create a password mid-order; those
customers can add one later from their dashboard.

### Phase 3 — Cart & checkout
- [ ] Confirm the delivery charges: currently **flat ₹69, free above ₹999**
- [ ] Confirm the return window: currently **7 days** from delivery
- [ ] Decide who pays return shipping on a change of mind (policy currently says
      the customer, deducted from the refund)
- [ ] Decide whether you want Cash on Delivery later — not built, and it needs
      pincode rules and an advance-payment policy if you do

### Phase 4 — Payments
- [ ] Razorpay live keys + webhook (Part 1 item 2)
- [ ] **Place one real ₹1 order on live keys, then refund it from the order page.**
      This is the last item on the build's own gate and the only way to know the
      whole chain works. Do it before you advertise the shop anywhere.
- [ ] Test the recovery path: start a payment, close the browser mid-way, and
      confirm the webhook still marks the order paid within a minute.

### Phase 5 — Customer dashboard
- [ ] Nothing required. Place a test order and click through My Orders, invoice
      download, wishlist and address book to satisfy yourself.

### Phase 6 — Admin
- [ ] Set a password for any admin who needs one:
      `printf '%s' 'password' | php tools/set-admin-password.php you@gmail.com "Name" owner`
- [ ] `unknownones910@gmail.com` is now the only admin. No admin account ships with
      the code any more — a fresh install creates the first one through
      `install.php`, or via `tools/set-admin-password.php` where there is a shell.
- [ ] Change the password for `unknownones910@gmail.com` — it was shared in plain
      text in our chat. **Admin → your name → Your account.**
- [ ] Confirm `https://YOURDOMAIN/tools/set-admin-password.php` returns 404 on the
      live host.

### Phase 7 — Content & trust
- [ ] **Have a lawyer read the eight policy pages.** They are thorough, current
      drafts aligned to the Consumer Protection (E-Commerce) Rules 2020, Legal
      Metrology Rules 2011, the IT Act/SPDI Rules and the DPDP Act 2023 — but they
      are not legal advice and I am not a lawyer.
- [ ] Your About story and a founder photo — the current text is a placeholder
      narrative
- [ ] WhatsApp business number (empty; the floating WhatsApp button is hidden
      without it)
- [ ] Social handles — Instagram, Facebook, Pinterest, YouTube (all empty; the
      footer icons hide themselves until filled)
- [ ] Which courier partners you use, for the shipping policy
- [ ] Four Journal posts are written and live. Add your own over time at
      **Admin → Blog**.
- [ ] The Patterns page is empty by design — add free patterns when you have them,
      they're a strong reason for people to come back

### Phase 8 — Launch
- [ ] Schedule the four cron jobs in cPanel (exact lines in `DEPLOY.md` §8).
      `expire-pending-orders.php` is the important one — it's the safety net for
      payments the webhook missed.
- [ ] Verify compression is on:
      `curl -sS -D - -H "Accept-Encoding: gzip" https://YOURDOMAIN/ -o /dev/null | grep -i content-encoding`
      You want `gzip`. Nothing means every page is going out ~10× too large.
- [ ] Confirm `/config/` is not served and an uploaded `.php` file cannot execute
- [ ] Set up **daily database backups** in cPanel, then download one and confirm it
      restores. An untested backup is not a backup.
- [ ] Submit `https://YOURDOMAIN/sitemap.xml` in Google Search Console
- [ ] Open the shop on a real phone and buy something. Emulators miss things.

---

## Part 4 · Later, not now

- **GST.** The schema is GST-ready but nothing is calculated or shown. When you
  cross the registration threshold, tell me and I'll wire up HSN codes, CGST/SGST/IGST
  split by state, and GST-compliant invoices.
- **COD**, if you decide you want it.
- **A physical shade card** as a paid product or a free-with-first-order item —
  the page exists, the decision doesn't.

---

## The order I'd do it in

1. Domain + hosting + SSL — everything else needs somewhere to live
2. Brevo, and confirm a code reaches your inbox — without it you can't even sign in
3. Business details in Admin → Settings — the legal minimum to be open
4. Photography — the long pole; start it early and let it run in the background
5. Razorpay, then the ₹1 live test order
6. Crons, backups, Search Console
7. Lawyer reads the policies

Steps 1–3 are perhaps a day. Step 4 is the one that takes real time and makes the
real difference.
