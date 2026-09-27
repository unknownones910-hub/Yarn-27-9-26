# The Yarn Nest — working notes

Yarn e-commerce store. Plain PHP 8.0 + mysqli, no framework, no build step.
**[PLAN.md](PLAN.md) is the specification.** Read the relevant section before
building anything; this file is only the short version.

## Run it

```bash
C:\xampp\php\php.exe install.php
```
Creates `yarn_store`, applies every `sql/*.sql` not yet in `applied_migrations`.
Idempotent. Then <http://localhost/>. Style guide: `/_styleguide.php`.

## Non-negotiables

1. **Every query is a prepared statement** via `db_all` / `db_row` / `db_val` /
   `db_exec` / `db_insert` in [includes/db.php](includes/db.php). Types are
   derived automatically; placeholder/param mismatches throw. Never concatenate
   a value into SQL.
2. **Every output goes through `e()`.** URLs through `eurl()`, JSON through `ejs()`.
3. **Every POST handler calls `csrf_verify()` first.** Every form calls `csrf_field()`.
4. **Money is `DECIMAL(10,2)`**, never float. Razorpay takes integer paise —
   convert once with `to_paise()`.
5. **Recompute cart totals server-side** before creating a payment. The browser
   is never the source of truth.
6. **Stock decrements use the guarded update:**
   `UPDATE product_variants SET stock = stock - ? WHERE id = ? AND stock >= ?`
   then check `affected_rows === 1`. Wrap in `db_tx()`.
7. **Bump `$SITE['asset_v']`** in [config/site-config.php](config/site-config.php)
   whenever `style.css` or `main.js` changes — it busts every asset URL at once.
8. **PHP 8.0 only.** No enums, readonly props, `never`, or fibers. `match`,
   named args, `?->` are fine.
9. **Secrets live in `config/*-config.local.php`** — gitignored, excluded from
   deploy zips, recreated by hand on each environment. All ship inert (empty
   keys) so the site runs without them.

## Layout

```
includes/bootstrap.php   first require in EVERY page — session, db, $CFG, $customer
includes/db.php          prepared-statement helpers + db_tx()
includes/helpers.php     e(), money(), normalize_gmail(), setting(), url()…
includes/auth.php        sessions, remember-me, admin gate. OTP lives in otp.php
includes/nav.php         nav_categories(), whatsapp_link()
includes/components/     icons.php (icon(), stars_html()) + shared partials
includes/header.php      <head> + chrome. Page sets $pageTitle/$pageDesc/$noindex first
includes/footer.php      footer + mobile tab bar + flash toasts
config/                  site/db/admin config + *.local.php secrets (deny-all .htaccess)
sql/                     ordered additive migrations — never edit an applied file
```

## The overflow rule

The client's hard requirement is **zero horizontal overflow**. `PLAN.md` §3.3
has the full contract. The short version:

- never `100vw` (it includes the scrollbar) — use `100%`
- every flex/grid child holding text gets `min-width:0` (utility: `.min0`)
- wide things (tables, rails) live inside `.scroll-x` or `.rail`
- product grids use `minmax(min(158px,100%), 1fr)` — the inner `min()` is what
  stops the blowout at 320px

**Verify:** open any page with `?debug=1` and read the console. It outlines
offenders and logs `scrollWidth` vs `clientWidth`. They must be equal at
320 / 360 / 414 / 768 / 1024 / 1440.

The checker deliberately ignores five legitimate cases: left overflow (never
creates a scrollbar in LTR), closed off-canvas panels, children of a
self-scrolling ancestor, `position:fixed` (never extends document scrollWidth)
and `visibility:hidden`.

No breakpoint exists between 320 and 560, so 360 and 414 render the identical
branch as 320 — a 320-clean page is clean at both. The widths worth spending a
pass on are 320, 600, 768, 1024 and 1440.

## Gotchas already paid for

- **Session name is `YARNNEST_SESS`** (admin: `YARNNEST_ADM`). `localhost` is
  shared with other projects — a generic `PHPSESSID` collides and randomly
  signs users out.
- **Gmail ignores dots and `+tags`.** `customers.email_key` holds the
  normalized form with a UNIQUE index; `customers.email` holds what they typed.
  Without this, `m.anoj@` and `manoj@` become two accounts with two carts.
- **`AddOutputFilterByType` needs mod_filter, not mod_deflate.** Guarding it
  with `<IfModule mod_deflate.c>` alone 500s on stock XAMPP.
- **Adding a class does not recalculate styles.** `modalOpen()` forces a reflow
  (`void el.offsetWidth`) before `.focus()`, otherwise focus silently no-ops on
  an element the browser still thinks is hidden.
- **`visibility` is discrete.** Transitioning it keeps the element hidden for
  the whole duration on open. `.modal` / `.scrim` use `visibility 0s linear 0s`
  when open and `…linear var(--dur)` when closing.
- **Page code runs before `header.php`.** `nav.php` and `icons.php` are loaded
  by bootstrap, not the header, because pages call `nav_categories()` and
  `icon()` while assembling `$jsonLd`.
- **Select every column you read.** `nav_categories()` needs `image` and
  `description`, not just `id, name, slug`.
- **`$_POST` is empty for JSON bodies.** PHP only populates it for
  form-encoded requests, so `bootstrap.php` decodes an `application/json`
  body into `$_POST` itself. Every AJAX endpoint can then use
  `post_int()`/`post_str()` without caring how the request was encoded.
- **A product needs many categories.** `products.category_id` is only the
  *primary* one (breadcrumbs, canonical URL). Category pages and filters go
  through `product_categories`, because a yarn is Crochet AND Cotton AND DK
  at the same time.
- **`pricing_totals()` is the only place money is added up.** Cart, checkout
  review and (from Phase 4) the Razorpay amount all call it. If you total
  line items anywhere else, the three will drift.
- **Free shipping is tested against the subtotal BEFORE discount.** Testing
  it after means applying a coupon can silently add a delivery charge, which
  reads as a penalty for using the coupon.
- **Cart lines bill `billable_qty`, not `qty`.** When stock drops below what
  is in the cart, the line is capped. Display the capped figure or the line
  total will disagree with the summary.
- **An endpoint's display copy is not form data.** `pincode-check.php`
  returns `city`/`state` as null when unknown and keeps the "your area"
  wording in `message` only — the autofill once wrote that phrase into a
  real delivery address.
- **`bootstrap.php` is `require`d into the caller's scope**, so any variable it
  creates collides with the including script's. Its JSON-body decoder is
  wrapped in a closure for exactly this reason — a bare `$rawBody` there once
  blanked the identically named variable in `webhooks/razorpay.php` and killed
  webhook verification silently.
- **Read `php://input` BEFORE requiring bootstrap** in the webhook. The HMAC is
  over exact bytes; re-encoding a decoded array never matches.
- **A captured payment is never rolled back.** If stock cannot be deducted,
  `order_mark_paid()` still marks the order paid, writes `[STOCK SHORTFALL]`
  to `admin_note` and sends an `[ACTION NEEDED]` email. Rolling back would keep
  the customer's money and erase the record of it.
- **Both payment paths are idempotent.** The browser callback and the webhook
  both call `order_mark_paid()`, which locks the order `FOR UPDATE` and
  re-checks `payment_status` inside the transaction.
- **FPDF is Latin-1.** Every string into a PDF goes through `pdf_txt()`, which
  turns `₹` into `Rs.` — the core fonts have no rupee glyph.
- **The wishlist is shade-level, not product-level.** `wishlists.variant_id`,
  because people want Terracotta Rose, not "that yarn in some colour". A
  product card saves its first shade; the PDP saves the selected one and the
  heart's `data-wish` is rewritten when the shade changes.
- **Guest wishlists live in `localStorage` under `yn_wishlist_v`** and are
  migrated by `wishlist-toggle.php?action=sync` on the next signed-in page
  load. `<meta name="yn-auth">` tells the JS which mode it is in.
- **Deleting an account keeps the orders.** Tax and consumer-protection rules
  require a seller to retain invoice records, so `account/delete.php`
  anonymises rather than deletes them: personal fields become `[deleted]`,
  reviews become "A customer", everything else goes. Blocked while an order is
  still in flight, and gated behind an OTP.
- **CSP `frame-src` does not include `'self'`.** Same-origin iframes are
  blocked. Phase 7's contact-page map will need that directive widened to the
  map host explicitly.
- **Library includes are `require_once`, always.** `admin-bootstrap.php` pulls in
  `order.php` → `order-emails.php` → `mailer.php`, so a page that then does a
  plain `require` of `mailer.php` fatals on redeclare — and because that happens
  *before* `admin_guard()`, the page returned 200 to anonymous visitors. Two
  admin screens were briefly unauthenticated because of it.
- **Customers sign in with a password; the code is only for the first time.**
  `login.php` is two steps: address, then either the password (account has one)
  or an emailed code (brand new, or an account predating passwords). The code
  path lands on `set-password.php`, which is where the account is finished.
  Verifying the address *before* a password exists is the point — it means an
  account can only be created against a Gmail the person actually controls.
  Admin is the opposite trade: password **and** a fresh code, every time.
- **No session is established until the password exists.** `verify.php` hands
  `set-password.php` a `pw_setup_customer` id and nothing else; if it called
  `login_customer()` first, "choose a password" would be a step you could skip
  by typing any other URL. The handoff expires after 15 minutes.
- **`set-password.php` serves three different people** — signup, add (guest
  checkout left them without one), and reset — and `$mode` picks the copy for
  each. Telling a five-year customer "Creating an account for you" reads as
  having lost their details. `$isNew` is decided in `verify.php` by checking for
  the row *before* `customer_upsert()` creates it; afterwards everyone looks
  like a returning customer.
- **Checkout stays OTP-only, deliberately.** `checkout.php` verifies by code and
  signs the buyer in without a password — forcing account creation mid-checkout
  is a reliable way to lose the order. Those accounts get `password_hash = NULL`
  and are invited to set one from **account/password.php**.
- **Admin needs four keys.** A row in `admin_users`, the address in
  `$ADMIN['allowlist']` (config/admin-config.php, not web-editable), the
  account password, and an emailed OTP. The allowlist is re-checked on every
  request, so revoking is immediate. `password_hash` is nullable and NULL means
  *cannot sign in* — adding the column did not open a back door on the existing
  account, it closed it, so every admin needs a password set explicitly.
- **The admin password is verified BEFORE the OTP is issued**, so login cannot
  be used to make the shop send mail to an address on demand. Every failure
  (unknown address, wrong password, no password, locked) returns one identical
  message and runs a dummy `password_verify` against a fixed hash, so neither
  the response nor its timing reveals which admin accounts exist. The code
  stage re-checks `adm_pw_ok` in the session — without that, a valid code alone
  would be enough and the password would be decorative.
- **Set admin passwords with `tools/set-admin-password.php`**, which reads from
  STDIN so the password never reaches shell history or the process list, and
  writes only a bcrypt hash. No credential is ever committed. `/tools/` is
  blocked in `.htaccess` alongside `/sql/` and `/cron/`. Admins rotate their own
  password at **admin/account.php**, which requires the current one — a stolen
  session must not be able to lock the real owner out.
- **bcrypt silently truncates at 72 bytes.** Both the CLI tool and the
  change-password form refuse anything longer rather than accept a password
  whose tail is ignored.
- **`flex:none` on a wrapping container clips it.** `.admin-head > *` has
  `min-width:0`, but an inline `flex:none` overrides the shrink and the chips
  inside never wrap — with `overflow-x:clip` there is no scrollbar to reveal
  what got cut off. Let containers shrink; let their children wrap.
- **Deleting a product that has been ordered hides it instead.** Same for
  variants and coupons with redemptions. Old invoices must keep resolving.
- **`inline-save.php` is an allowlist, not a generic updater** — entity+field
  pairs are enumerated, each with its own validation. Stock edits there write a
  `stock_movements` row like any other change.
- **Policy text uses `{{tokens}}`, not hardcoded details.** `policy.php` resolves
  them from settings at render, so the address and grievance officer live in one
  place across eight documents. The meta description is generated from the body,
  so it must go through the resolver too — otherwise `{{legal_name}}` appears in
  search results.
- **`json_encode()` returns FALSE on bad UTF-8, and the binder turns a bool into
  an int** — a failed encode silently stored the string `"0"` over the data. Use
  `json_col()` for anything going into a JSON column.
- **`install.php` runs `sql/*.php` as well as `sql/*.sql`.** Long prose (policy
  pages, FAQs) goes in a PHP migration using prepared statements; escaping it
  into SQL literals invites silent truncation. A PHP migration returns a row count.
- **A guarded Apache directive that never runs is worse than a 500.** Wrapping
  `AddOutputFilterByType` in `<IfModule mod_filter.c>` stopped the crash, but
  stock XAMPP has mod_filter commented out — so the block was skipped and the
  whole site shipped uncompressed for seven phases with nothing to warn us.
  `.htaccess` now has an `<IfModule !mod_filter.c>` fallback using
  `SetOutputFilter DEFLATE`, and the launch check does a loopback request to
  confirm. gzip is worth ~90% on every page here.
- **`script-src` is nonce-based, not `'unsafe-inline'`.** Every inline
  `<script>` carries `nonce="<?= e(csp_nonce()) ?>"`; there are only four
  (header.php's JSON-LD, faq.php, yarn-calculator.php, pay.php). A browser
  ignores `'unsafe-inline'` once a nonce is present, so the two can never be
  listed together. `pay.php` calls `csp_allow_inline_scripts()` to opt back
  out, because Razorpay's checkout bundle is not ours to audit and a blocked
  script there costs an order.
- **The overflow checker must also ignore `position:fixed` and
  `visibility:hidden`.** The closed `.shop-filters` panel is parked to the
  *right* of the viewport, so the old left-overflow exclusion did not cover it
  and three pages reported phantom offenders. A checker that cries wolf is one
  nobody reads.
- **A red message under an input is invisible without `aria-describedby`.**
  `field_invalid()` and `field_error()` in helpers.php emit the pair together
  so a form cannot grow an error only sighted users can read. A radio group
  needs a real `<fieldset>`/`<legend>` — `fieldset.field` is reset in CSS to
  look identical to a `div.field`.
- **`?debug=1` also prints a perf line** (`<!-- yn-perf {...} -->`) with query
  count, DB ms, PHP ms and peak memory, from `db_stats()`. Storefront pages run
  5–14 queries in under 20 ms; a sudden jump means an N+1 crept in.
- **Testing gotchas that are not bugs:** `curl -d` does not URL-encode, so a
  `+` in an email arrives as a space — use `--data-urlencode`. The Browser pane
  does not composite, so CSS transitions freeze at their from-value; inject
  `*{transition:none}` before measuring geometry. And `curl -I` sends HEAD,
  which Apache may not run the DEFLATE filter for — check compression with a
  real GET (`curl -D - ... -o /dev/null`), not a HEAD.

## Phase status

| Phase | State |
|---|---|
| 0 · Foundation, schema, design system | **done** — gate passed |
| 1 · Catalog | **done** — gate passed |
| 2 · Gmail + OTP auth | **done** — gate passed |
| 3 · Cart & checkout | **done** — gate passed |
| 4 · Razorpay payments | **done** — gate passed |
| 5 · Customer dashboard | **done** — gate passed |
| 6 · Admin panel | **done** — gate passed |
| 7 · Content & trust pages | **done** — gate passed |
| 8 · Polish & launch | **done** — everything automatable is verified. The gate's last item (one real ₹1 order on live keys, then refunded) needs the client's own Razorpay credentials and can only be done by them. |

Run **admin → System → Launch check** after every deploy. It re-runs every
automated check against the live environment and lists the handful that only a
human can do.
