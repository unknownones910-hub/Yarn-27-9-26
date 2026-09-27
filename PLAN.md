# Yarn Store — Master Build Plan

**Status:** Plan v1.0 — approved for execution
**Date:** 2026-08-04
**Project root:** `C:\xampp\htdocs\` (site lives at the web root; deploy = zip root → cPanel `public_html`)
**Local URL:** `http://localhost/`

---

## 0. Decisions Already Locked (do not re-litigate)

| Area | Decision |
|---|---|
| Stack | Plain PHP 8.0 + mysqli (prepared statements), no framework, no JS framework |
| Login | **Gmail-only email + 6-digit OTP.** No passwords anywhere, ever |
| Email transport | **Brevo HTTPS API** (`api.brevo.com/v3/smtp/email`) via cURL — not SMTP ports |
| Payments | **Razorpay** (UPI / cards / netbanking / wallets). COD built but **OFF** by default |
| Shipping | **Flat rate + FREE above threshold** (default ₹69 flat, free ≥ ₹999) |
| GST | **Not registered yet** → plain bill. Schema keeps GST fields ready; one settings flag turns it on |
| Design | **Warm & cozy craft** — cream/ivory, terracotta + sage, rounded, photo-led |
| Responsive | Mobile-first, **zero horizontal overflow at 320px**, verified by a QA script |
| Hosting | XAMPP local → GoDaddy-style cPanel shared hosting (PHP 8.0+, MySQL, cURL, GD) |

**Brand name placeholder:** `The Yarn Nest`. It exists in exactly one place — `config/site-config.php` → `$SITE['brand']`. Never hardcode the brand anywhere else. Alternative names to consider: *Sootra*, *Loop & Ply*, *Woolworks India*, *Knot & Nest*, *Tangle Co.*

---

## 1. Environment (verified 2026-08-04)

```
PHP        8.0.30 (ZTS, x64)
Extensions curl ✓  gd ✓  mbstring ✓  mysqli ✓  openssl ✓  zip ✓   (intl ✗ — do not use)
MySQL      C:\xampp\mysql\bin\mysql.exe  (root / no password)
Composer   C:\ProgramData\ComposerSetup\bin\composer
htdocs     EMPTY — clean slate
```

**Composer usage:** minimal. Only pull `setasign/fpdf` for PDF invoices. Razorpay is done with raw cURL + `hash_hmac` (no SDK — fewer moving parts on shared hosting). Brevo is raw cURL. QR codes are not needed (Razorpay renders its own).

**Database:** `yarn_store`, charset `utf8mb4`, collation `utf8mb4_unicode_ci`, engine InnoDB everywhere.

---

## 2. Non-Negotiable Engineering Rules

Read these before writing a single line. Every one of them comes from a real bug.

1. **Every SQL query uses `mysqli` prepared statements with `bind_param`.** Count the `?` placeholders and count the type-string characters and count the bound variables — all three must match. Verify by eye before moving on. String concatenation into SQL is an automatic rejection.
2. **Every output goes through `e()`** (`htmlspecialchars($v, ENT_QUOTES|ENT_SUBSTITUTE, 'UTF-8')`). No raw echo of DB or user data.
3. **Every POST form carries a CSRF token** (`csrf_field()` in the form, `csrf_verify()` as the first line of the handler). Includes admin, includes AJAX (send token in a header).
4. **Session name is unique to this site:** `session_name('YARNNEST_SESS')` before `session_start()`. Multiple projects share `localhost` — a generic `PHPSESSID` will collide and randomly log users out. Set the customer session and admin session to **different session names** so an admin can be logged into both without one clobbering the other.
5. **Money is `DECIMAL(10,2)` in MySQL and integer paise in Razorpay calls.** Never float. Convert once, at the boundary: `(int) round($rupees * 100)`.
6. **Totals are always recomputed server-side** from the DB at checkout. The browser's cart is a hint, never a source of truth. Price, discount, shipping, and grand total are all derived server-side immediately before creating the Razorpay order.
7. **Stock decrements happen inside a transaction with a guarded UPDATE:**
   `UPDATE product_variants SET stock = stock - ? WHERE id = ? AND stock >= ?` → then check `affected_rows === 1`. If it's 0, the shade sold out mid-checkout; roll back and tell the user.
8. **Cache-busting:** every `<link>`/`<script>` tag ends in `?v=N`. When `style.css` or `main.js` changes, bump `N` in `config/site-config.php` (`$SITE['asset_v']`) — one constant, referenced by every include. Do not hardcode version numbers per-file.
9. **Secrets live in `*.local.php`**, which is gitignored and excluded from deploy zips: `config/brevo-config.local.php`, `config/razorpay-config.local.php`, `config/db-config.local.php`. Each ships as an inert template with empty keys so the site runs locally without them.
10. **Never trust `$_FILES`.** Validate with `getimagesize()` + finfo MIME + extension allowlist (`jpg/jpeg/png/webp`) + size cap (5 MB) + regenerate the filename randomly. Drop an `.htaccess` in `/uploads/` that disables PHP execution.
11. **PHP 8.0 only.** No enums, no readonly properties, no `never` return type, no fibers. `match`, named args, constructor promotion, and nullsafe `?->` are fine.

---

## 3. Design System — "Warm & Cozy Craft"

The feeling to hit: a bright, sunlit craft studio. Soft, tactile, generous with whitespace, photography does the selling. Nothing shouty, nothing "marketplace".

### 3.1 Color tokens (`:root` in `assets/css/style.css`)

```css
:root{
  /* surfaces */
  --cream:      #FDFBF7;   /* page background */
  --surface:    #FFFFFF;   /* cards, header */
  --sand:       #F5EEE4;   /* alternating sections, chips */
  --sand-deep:  #EADFD0;   /* borders, dividers */

  /* ink */
  --ink:        #2E2A26;   /* headings, body */
  --ink-soft:   #5D554C;   /* secondary text */
  --ink-muted:  #8A8078;   /* captions, meta */

  /* brand */
  --clay:       #B4552F;   /* PRIMARY — CTAs, links, active states */
  --clay-dark:  #8E3F1F;   /* hover/pressed */
  --clay-tint:  #FBEFE8;   /* soft primary background */
  --sage:       #63795A;   /* secondary accent */
  --sage-tint:  #EDF2EA;
  --gold:       #C08A24;   /* rating stars, "bestseller" */

  /* status */
  --success:    #3B7A50;  --success-tint:#E9F4EC;
  --warning:    #A9700F;  --warning-tint:#FCF3E2;
  --danger:     #A82B22;  --danger-tint: #FBEDEC;

  /* elevation */
  --sh-1: 0 1px 2px rgba(46,42,38,.06), 0 1px 3px rgba(46,42,38,.05);
  --sh-2: 0 4px 12px rgba(46,42,38,.07);
  --sh-3: 0 12px 32px rgba(46,42,38,.10);

  /* geometry */
  --r-sm:8px; --r-md:14px; --r-lg:20px; --r-xl:28px; --r-pill:999px;

  /* spacing scale — use ONLY these */
  --s1:4px; --s2:8px; --s3:12px; --s4:16px; --s5:24px;
  --s6:32px; --s7:48px; --s8:64px; --s9:96px;

  --container: 1200px;
  --header-h: 68px;
}
```

Contrast is already checked: `--clay` on `--surface` = 4.9:1, `--ink` on `--cream` = 13.1:1, `--ink-soft` on `--cream` = 7.2:1. **Never** put `--ink-muted` on `--sand` for anything smaller than 16px.

Storefront is **light-only** in v1 (photography-led design; dark mode washes out yarn colors). Tokens are structured so a `[data-theme="dark"]` block can be added later without touching components.

### 3.2 Typography

- **Headings:** *Fraunces* (soft serif, "Soft" optical axis) — warm, handmade, high trust.
- **Body/UI:** *Inter* — clean, excellent at small sizes.
- **Self-host both.** Download woff2 subsets (latin + latin-ext) into `assets/fonts/`, declare with `@font-face { font-display: swap; }`, and `<link rel="preload" as="font" crossorigin>` the two body weights. **No Google Fonts CDN** — it's a third-party dependency, a privacy/DPDP liability, and a render-blocking request.
- Fallbacks: `Fraunces, "Iowan Old Style", Georgia, serif` and `Inter, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif`.
- Weights to ship: Fraunces 500/600, Inter 400/500/600. Nothing else.

Fluid scale (never fixed px for headings):

```css
--fs-display: clamp(2rem, 1.35rem + 3.2vw, 3.5rem);   /* hero */
--fs-h1:      clamp(1.75rem, 1.3rem + 2.2vw, 2.75rem);
--fs-h2:      clamp(1.4rem, 1.15rem + 1.3vw, 2rem);
--fs-h3:      clamp(1.15rem, 1.05rem + .5vw, 1.375rem);
--fs-body:    1rem;      /* 16px minimum — never smaller for body copy */
--fs-sm:      .9375rem;
--fs-xs:      .8125rem;  /* meta/labels only */
```

Line heights: headings `1.15`, body `1.65`. Body measure capped at `68ch`.

### 3.3 The Overflow & Responsive Contract

This is an explicit requirement from the client. Treat a horizontal scrollbar as a build-breaking bug.

**Global reset (top of `style.css`):**

```css
*,*::before,*::after{ box-sizing:border-box; }
html{ overflow-x:clip; }            /* clip, NOT hidden — hidden breaks position:sticky */
body{ max-width:100%; overflow-x:clip; -webkit-text-size-adjust:100%; }
img,svg,video,canvas,iframe{ max-width:100%; height:auto; display:block; }
input,select,textarea,button{ max-width:100%; font:inherit; }
h1,h2,h3,p,a,li,td{ overflow-wrap:anywhere; }   /* long SKUs, URLs, shade codes */
.container{ width:min(var(--container), 100% - var(--s5)); margin-inline:auto; }
```

**Hard rules:**
1. **Never use `100vw`.** On Windows/desktop it includes the scrollbar and overflows by 15px. Use `100%`. If a full-bleed section is needed, use the safe pattern: `width:100%; margin-inline:calc(50% - 50vw)` is **banned** — instead put the full-bleed background on the section and the `.container` inside it.
2. **Every flex/grid child that contains text gets `min-width:0`.** This is the #1 cause of grid blowout. Add a utility `.min0{min-width:0}` and use it.
3. **Tables, code blocks, and the admin data grids live inside `.scroll-x { overflow-x:auto; -webkit-overflow-scrolling:touch; }`** — the wrapper scrolls, the page never does.
4. **No fixed pixel width above 300px** on any element. Use `max-width` + `%` + `clamp()`.
5. **Product grid** uses `grid-template-columns: repeat(auto-fill, minmax(min(160px,100%), 1fr))` — the `min(...,100%)` is what stops the blowout at 320px.
6. **Horizontal carousels** (recently viewed, related products, shade chips) use `overflow-x:auto; scroll-snap-type:x mandatory;` on a **wrapper**, and the wrapper itself must be inside `.container` with `overflow:clip` on the parent section.
7. Long shade names truncate with `text-overflow:ellipsis; white-space:nowrap; overflow:hidden;` **only** where a tooltip/title attribute carries the full text.
8. Modals: `width:min(560px, calc(100% - 32px))`.

**QA gate — paste in the console on every page, at 320 / 360 / 414 / 768 / 1024 / 1440:**

```js
[...document.querySelectorAll('*')]
  .filter(el => el.getBoundingClientRect().right > document.documentElement.clientWidth + 1)
  .forEach(el => { console.warn('OVERFLOW:', el); el.style.outline='2px solid red'; });
console.log('scrollWidth', document.documentElement.scrollWidth,
            'clientWidth', document.documentElement.clientWidth);
```
`scrollWidth` must equal `clientWidth`. Save this as `assets/js/overflow-check.js` and load it only when `?debug=1` is present.

**Breakpoints** (mobile-first `min-width` only — never `max-width` as the primary):
`480px` (large phone) · `640px` (small tablet) · `768px` (tablet) · `1024px` (laptop) · `1280px` (desktop).

**Touch targets:** minimum `44×44px`. Quantity steppers, shade swatches, and filter chips must all clear this.

### 3.4 Component inventory (build once, in `includes/components/`)

| Component | Notes |
|---|---|
| Button | `.btn` + `.btn-primary` (clay), `.btn-ghost`, `.btn-soft` (sand), `.btn-sm/lg`, `.btn-block`. Loading state with inline spinner + `disabled` |
| Product card | Image 4:5 ratio, shade dots (max 5 + "+3"), name, price + struck MRP, rating, wishlist heart, "Only 3 left" badge |
| Shade swatch | Round `36px` chip with `background:var(--hex)`, `aria-label` = shade name, selected = 2px clay ring + checkmark |
| Price block | `₹499` + `<s>₹699</s>` + `28% OFF` pill in sage |
| Qty stepper | −/+ with 44px targets, `inputmode="numeric"` |
| Rating stars | SVG, gold, half-star support, `aria-label="4.5 out of 5"` |
| Trust strip | 4-icon row, wraps to 2×2 on mobile |
| Section header | Serif h2 + short subhead + optional "View all →" |
| Empty state | Illustration + one line + one CTA. Used in cart/wishlist/orders/search |
| Toast | Bottom-right desktop, bottom-center mobile above the tab bar, auto-dismiss 4s, `role="status"` |
| Skeleton | Shimmer blocks for grid + PDP |
| Breadcrumb | Home / Category / Product, with JSON-LD |
| Accordion | Policy pages, FAQ, PDP details — `<details>`-based for zero-JS resilience |
| Modal/drawer | Cart drawer, filter drawer, quick view. Focus trap, ESC, scroll lock, restore focus |
| Pagination | Numbered + prev/next, collapses to "1 … 4 5 6 … 20" |
| Form field | Label above, helper below, error in danger with icon, `aria-describedby` |
| OTP input | 6 separate boxes, auto-advance, paste-6-digits support, backspace goes back |
| Sticky mobile bar | PDP: price + "Add to Cart". Global: bottom tab nav |

### 3.5 Layout chrome

**Header (sticky, 68px):**
- Row 1 (desktop): logo left · search center (expands on focus) · wishlist / account / cart right, cart shows item-count bubble.
- Row 2 (desktop): category nav with a mega-menu on "Shop by Fiber" and "Shop by Weight".
- Mobile: hamburger · logo · cart. Search collapses into a full-width bar below the header. Nav opens as a left drawer.
- **Announcement bar** above the header (dismissible, remembered in `localStorage`): *"Free shipping on orders above ₹999 · Ships across India"*.

**Bottom tab bar (mobile only, `< 768px`, fixed):** Home · Shop · Search · Wishlist · Account. Height 60px + `env(safe-area-inset-bottom)`. Page bottom padding must account for it, otherwise the last row of products is unreachable.

**Footer:** 4 columns → 2 on tablet → 1 accordion on mobile.
Col 1 = brand + one-line story + social. Col 2 = Shop links. Col 3 = Help (Track Order, Shipping, Returns, FAQ, Contact). Col 4 = Policies + newsletter signup.
Bottom bar: **legal business name, full postal address, phone, email, GSTIN (when applicable)** — this is a legal requirement under the Consumer Protection (E-Commerce) Rules, 2020, not decoration. Payment method logos (UPI, Visa, Mastercard, RuPay, Razorpay Secure).

---

## 4. Building Instant Trust (client's explicit goal)

Trust is engineered, not decorated. Every item below is a build task.

1. **Above the fold:** real product photography (not stock illustrations), a clear value line, and a visible price range. No mystery.
2. **Trust strip immediately under the hero:** 🔒 Secure Razorpay Payments · 🔁 7-Day Easy Returns · 🚚 Ships Across India · 💬 Real Human Support.
3. **Real contact details in the footer and on `/contact`** — a working phone number, a WhatsApp link, and a physical address. The single biggest trust signal for Indian D2C.
4. **Photo reviews** from real buyers showing finished projects. Verified-purchase badge.
5. **Honest stock and dye-lot info** on the PDP — "Current dye lot: 24B. Buy all balls together for a perfect color match." Nobody fakes this; customers notice it and it reads as expertise.
6. **Color-accuracy disclaimer** next to swatches, with a link to order a physical shade card.
7. **Order tracking without login** — order number + email is enough.
8. **Transparent totals** — no surprise fee at the last step. Shipping is stated on the cart page, before checkout.
9. **Named grievance officer** with email and response SLA (48h acknowledgement, 30-day resolution).
10. **No dark patterns.** No fake countdown timers, no invented "12 people viewing". If it isn't true, it doesn't ship.
11. **About page with a real founder photo and story.** Faces convert.
12. **Fast site.** Slow = suspicious. Targets in §14.

---

## 5. Sitemap — Every Page

### 5.1 Public storefront

| # | Route | File | Purpose |
|---|---|---|---|
| 1 | `/` | `index.php` | Home |
| 2 | `/shop` | `shop.php` | All products + filters + sort |
| 3 | `/category/{slug}` | `shop.php` | Category-filtered shop |
| 4 | `/collection/{slug}` | `collection.php` | Curated collections |
| 5 | `/product/{slug}` | `product.php` | Product detail (PDP) |
| 6 | `/search?q=` | `search.php` | Search results + "no results" recovery |
| 7 | `/new-arrivals` | `shop.php` | Preset filter |
| 8 | `/bestsellers` | `shop.php` | Preset filter |
| 9 | `/sale` | `shop.php` | Discounted items |
| 10 | `/cart` | `cart.php` | Cart |
| 11 | `/checkout` | `checkout.php` | Address → review → pay |
| 12 | `/order-success/{order_no}` | `order-success.php` | Thank-you + next steps |
| 13 | `/track-order` | `track-order.php` | Guest tracking (order no + email) |
| 14 | `/about` | `about.php` | Story, founder, values |
| 15 | `/contact` | `contact.php` | Form + phone + WhatsApp + map |
| 16 | `/faq` | `faq.php` | Grouped, searchable, FAQPage JSON-LD |
| 17 | `/yarn-guide` | `yarn-guide.php` | Weight chart, hook/needle conversion, fiber guide |
| 18 | `/yarn-calculator` | `yarn-calculator.php` | "How many balls do I need?" |
| 19 | `/shade-card` | `shade-card.php` | All shades, filter by color family, order physical card |
| 20 | `/patterns` | `patterns.php` | Free pattern library (email-gated PDFs) |
| 21 | `/blog` `/blog/{slug}` | `blog.php` `blog-post.php` | Tutorials & projects |
| 22 | `/wholesale` | `wholesale.php` | Bulk enquiry for boutiques/teachers |
| 23 | `/reviews` | `reviews.php` | All reviews with photos |
| 24 | `/login` | `login.php` | Gmail entry → OTP |
| 25 | `/verify` | `verify.php` | OTP entry |
| 26 | `/logout` | `logout.php` | Destroy session |
| 27 | `/newsletter/confirm` | `newsletter-confirm.php` | Double opt-in |
| 28 | `/404` `/500` | `404.php` `500.php` | Friendly errors with a search box |
| 29 | `/sitemap.xml` `/robots.txt` | generated | SEO |

### 5.2 Policy pages (India-specific — full content in §12)

| Route | File |
|---|---|
| `/privacy-policy` | `policy.php?p=privacy` |
| `/terms-and-conditions` | `policy.php?p=terms` |
| `/shipping-policy` | `policy.php?p=shipping` |
| `/returns-and-refunds` | `policy.php?p=returns` |
| `/cancellation-policy` | `policy.php?p=cancellation` |
| `/cookie-policy` | `policy.php?p=cookies` |
| `/disclaimer` | `policy.php?p=disclaimer` |
| `/grievance-redressal` | `policy.php?p=grievance` |

All eight render from the `pages` table so the admin can edit them without a developer. Seeded with the drafted text.

### 5.3 Customer account (`/account/*`, login required)

| Route | Purpose |
|---|---|
| `/account` | Overview: next delivery, recent orders, points, quick links |
| `/account/orders` | Order list with status pills + filters |
| `/account/orders/{order_no}` | Detail: timeline, items, invoice, track, reorder, cancel/return |
| `/account/addresses` | Address book, set default |
| `/account/wishlist` | Saved shades, back-in-stock toggle, move to cart |
| `/account/profile` | Name, phone, birthday, craft preferences (crochet/knit/macrame), skill level |
| `/account/reviews` | Reviews written + "awaiting your review" prompts |
| `/account/coupons` | Available + used coupons |
| `/account/support` | Tickets + replies |
| `/account/notifications` | Email preferences (order = always on, marketing = opt-in) |
| `/account/delete` | Account deletion request — **required by DPDP Act 2023** |

### 5.4 Admin panel (`/admin/*`)

| Route | Purpose |
|---|---|
| `/admin/login` | Email OTP (admin emails allowlisted in config) |
| `/admin` | KPI dashboard |
| `/admin/orders` | List, filters, bulk actions, CSV export |
| `/admin/orders/{id}` | Detail, status change, refund, invoice, packing slip, AWB entry |
| `/admin/products` | List, search, quick stock/price edit, bulk activate |
| `/admin/products/edit/{id}` | Full editor: info, yarn specs, shades/variants, images, SEO |
| `/admin/categories` | CRUD, reorder, image |
| `/admin/collections` | Curated groupings |
| `/admin/inventory` | Low-stock report, bulk stock update, stock movement audit trail |
| `/admin/coupons` | CRUD, usage stats |
| `/admin/customers` | List, detail, order history, block/unblock |
| `/admin/reviews` | Moderation queue |
| `/admin/messages` | Contact form + wholesale enquiries |
| `/admin/support` | Ticket inbox |
| `/admin/content/banners` | Home hero slides + promo strips |
| `/admin/content/blog` | Blog CRUD |
| `/admin/content/pages` | Policy page editor |
| `/admin/content/faq` | FAQ CRUD |
| `/admin/reports` | Sales, top products, low stock, coupons, abandoned carts |
| `/admin/settings` | Store info, shipping, payments, email, SEO, feature flags |
| `/admin/users` | Admin accounts + roles |
| `/admin/logs` | Admin activity log + email log + webhook log |

---

## 6. Page-by-Page Specification

### 6.1 Home (`/`)

Sections, top to bottom. Each is a separate include in `includes/home/`.

1. **Announcement bar** — free-shipping threshold, dismissible.
2. **Hero** — full-width warm photo (yarn baskets in daylight), left-aligned copy on desktop, centered on mobile with a scrim for contrast. `H1: "Yarn that's a joy to work with."` Sub: one honest line. Two CTAs: *Shop All Yarn* (primary) / *New Arrivals* (ghost). Admin-managed slides; ships with 1 slide, supports up to 3 with an accessible carousel (pause on hover, arrow keys, no auto-advance under 8s).
   - Image: `<picture>` with WebP + AVIF, `fetchpriority="high"`, explicit width/height to prevent CLS. Separate mobile crop (portrait) and desktop crop (landscape) via `<source media>`.
3. **Trust strip** — 4 items, 2×2 on mobile.
4. **Shop by Craft** — 4 big tiles: Crochet · Knitting · Amigurumi · Macramé.
5. **Shop by Yarn Weight** — horizontal scroll-snap row of 8 weight chips with a thickness illustration each. Links into filtered shop.
6. **Bestsellers** — 8 product cards, 2-col mobile / 3 tablet / 4 desktop.
7. **Featured collection banner** — e.g. "Monsoon Cotton Edit", split image/text, reverses on mobile.
8. **Shop by Fiber** — Cotton · Acrylic · Wool · Bamboo · Chenille · Jute · Silk Blend · Recycled.
9. **New Arrivals** — 8 cards.
10. **"Not sure how much yarn you need?"** — sage band promoting `/yarn-calculator`. Utility = trust.
11. **Customer projects** — 6 photo reviews in a masonry-ish grid, links to `/reviews`.
12. **From the blog** — 3 latest posts.
13. **Newsletter** — "Get 10% off your first order + free patterns." Double opt-in. One field + button, inline success, no modal popup.
14. **Footer.**

Performance: only the hero image is eager; everything below is `loading="lazy" decoding="async"`.

### 6.2 Shop (`/shop`, `/category/{slug}`)

- **Layout:** desktop = 260px sticky filter sidebar + grid. Mobile/tablet = "Filters" button opening a bottom drawer with an "Apply (24)" sticky footer button, plus a sort `<select>` in the toolbar.
- **Filters** (all reflected in the URL query so results are shareable and back-button-safe):
  `craft` · `fiber` · `weight` (CYC 0–7) · `color_family` (visual swatch grid) · `brand` · `price` (min/max + preset ranges) · `ball_weight` · `in_stock_only` · `on_sale` · `rating_4plus`
- **Sort:** Relevance · Newest · Price ↑ · Price ↓ · Rating · Bestselling.
- **Active filter chips** above the grid with individual ✕ and a "Clear all".
- **Result count** + range: "Showing 1–24 of 137 yarns".
- **Grid:** `repeat(auto-fill, minmax(min(160px,100%), 1fr))` — 2 columns at 320px, up to 4 at 1280px. Never 1 column (2-up feels like a real shop on mobile).
- **Card:** image (4:5, hover swaps to second image on desktop), badges (New / Bestseller / Only N left / % OFF), name, weight+fiber meta line, shade dots, price block, star rating, wishlist heart. Whole card is a link; the heart is a nested button with `stopPropagation`.
- **Pagination:** 24/page, server-side, numbered. Plus a "Load more" enhancement that pushes state — but the numbered links must work with JS off.
- **Empty state:** "No yarns match these filters" + the 3 filters most likely to be the culprit + "Clear all" + 4 bestseller suggestions.
- **SEO:** category pages get unique H1, intro paragraph (150–250 words, admin-editable), meta description, canonical. Filter combinations get `noindex,follow`.

### 6.3 Product Detail (`/product/{slug}`) — the money page

**Left / top: gallery**
- Main image 1:1, pinch-zoom on mobile, hover-zoom lens on desktop, click → lightbox.
- Thumbnail rail: vertical on desktop, horizontal scroll-snap on mobile with dot indicators.
- Supports video (a short "yarn in hand" clip builds enormous trust for texture).
- Gallery updates when a shade is selected.

**Right / bottom: buy box**
1. Breadcrumb.
2. Brand (small) → **H1 product name** → subtitle ("100% Mercerised Cotton · DK / 8-ply · 50g").
3. Rating: stars + "4.6 (128 reviews)" → anchors to reviews.
4. Price block: current, struck MRP, % off, "Inclusive of all taxes".
5. **Shade selector** — swatch grid, each showing `color_hex`. Selected shade name shown above the grid. Out-of-stock shades are dimmed with a diagonal line and become a **"Notify me"** button. `aria-pressed` on each.
6. **Quantity stepper** + a "Buy a set" nudge: "Most blankets need 8–10 balls — [Calculate for my project]".
7. **Stock line:** "In stock · Ships in 1–2 business days" / "Only 3 balls left in this shade" / "Out of stock".
8. **Dye lot notice:** "Current dye lot 24B — for a single project, order all balls now so the shade matches exactly."
9. **CTAs:** `Add to Cart` (primary, full width) + `Buy Now` (ghost, skips to checkout) + wishlist heart.
10. **Delivery check:** pincode input → estimated delivery date + serviceability. Remembered in a cookie.
11. **Trust row:** Secure payment · 7-day returns · Ships across India.
12. **Accordions** (`<details>`, first one open):
    - **Yarn Details** — fiber composition, weight category (CYC number), ply, ball weight, length (m/yd), recommended hook (mm), recommended needle (mm), gauge, country of origin, SKU.
    - **Care Instructions** — wash/dry/iron symbols + plain text.
    - **Shipping & Returns** — pulls from settings, links to policies.
    - **About this yarn** — long description, what it's good for, what it isn't.
13. **Color accuracy note** + link to `/shade-card`.
14. **Sticky mobile bar** appears once the buy box scrolls out: thumbnail + price + shade + `Add to Cart`.

**Below the fold**
- **Reviews** — summary bar (avg, count, 5→1 histogram, "with photos" filter), sort, photo grid, individual reviews with verified badge + shade purchased + helpful counter. Write-review CTA visible only to verified purchasers.
- **Q&A** (v2 flag).
- **"Goes well with"** — hooks, needles, markers, project bags.
- **"Similar yarns"** — same weight ± same fiber, algorithmic.
- **"Recently viewed"** — from `localStorage`.

**Structured data:** `Product` + `Offer` (price, availability, priceCurrency INR, shippingDetails) + `AggregateRating` + `Review` + `BreadcrumbList`.

### 6.4 Cart (`/cart`) + drawer

- Line items: image, name, shade swatch + name, unit price, qty stepper, line total, remove, "Save for later".
- Live stock revalidation on load — if a shade went out of stock, flag that line in danger and block checkout until resolved.
- Coupon field with inline validation and clear error reasons.
- **Free-shipping progress bar:** "You're ₹212 away from FREE shipping" with a filling bar. Turns sage on completion. This is the single highest-ROI element on the page.
- Summary: Subtotal · Discount · Shipping · **Total**. Shipping shown here, never revealed later.
- Gift wrap checkbox (+₹49) if enabled.
- `Proceed to Checkout` (sticky at the bottom on mobile).
- "Saved for later" section below.
- Empty state: illustration + "Your cart is feeling a bit unravelled" + Shop CTA + 4 bestsellers.
- Cart persists in the DB for logged-in customers (merges with the guest cart on login — sum quantities, cap at stock) and in the session for guests.

### 6.5 Checkout (`/checkout`) — one page, three steps

Browsing and cart require **no login**. Checkout requires Gmail + OTP — this doubles as order verification and keeps conversion healthy.

**Step 1 — Contact**
- Gmail input → "Send OTP" → 6-box OTP → verified ✓. If the customer already has a session, this step is pre-collapsed showing their email + "Not you?".
- Phone (10-digit, `+91` prefix shown) — required for courier delivery.

**Step 2 — Delivery address**
- Full name · Phone · Pincode (auto-fills city + state from a bundled pincode table) · House/Flat · Area/Street · Landmark · City · State (select) · Address type (Home/Work).
- Saved addresses appear as selectable cards for returning customers.
- "Billing address same as delivery" checkbox.

**Step 3 — Review & Pay**
- Collapsed order summary, expandable, sticky in a right column on desktop.
- Order notes textarea.
- **Payment: Razorpay only.** Copy: "Pay securely via UPI, Card, Netbanking or Wallet — powered by Razorpay." Logos row. COD block is coded but hidden behind `settings.cod_enabled`.
- Consent line above the button: "By placing this order you agree to our Terms and Return Policy."
- `Pay ₹1,247` button → server creates the order → Razorpay Checkout modal opens.

**Rules:**
- Progress indicator 1-2-3 at the top.
- Header is stripped down (logo + secure padlock + "Back to cart"); no nav, no footer links — minimize exits.
- All validation is server-side; client-side is only a convenience layer.
- On payment failure the customer returns to checkout with the cart intact and a clear retry.

### 6.6 Order Success (`/order-success/{order_no}`)

Order number (large, copyable) · what happens next (3-step timeline) · items · delivery address · expected delivery window · Track Order button · Download Invoice · "Continue shopping" · WhatsApp support link. Fires the confirmation email + admin notification.

### 6.7 About (`/about`)

Founder photo + first-person story (why yarn, what's different) · What we stand for (3 values: honest fiber labels, accurate shade photos, fair prices) · How we source · Our studio (photos) · Numbers we're proud of (orders shipped, shades stocked, cities delivered — real numbers only) · Team · CTA to shop. This page is a trust asset; give it real design attention.

### 6.8 Contact (`/contact`)

Two columns → stacked on mobile. Left: form (Name, Gmail, Phone, Subject select, Order number optional, Message) with CSRF, honeypot, rate limit (3/hour/IP), success inline. Right: phone (click-to-call), WhatsApp (click-to-chat with prefilled text), email, **registered address**, business hours, **grievance officer name + email + SLA**, embedded map (lazy-loaded `<iframe>` only after a click on a static map image — no third-party load on page open).

### 6.9 FAQ (`/faq`)

Groups: Ordering · Shipping · Returns & Refunds · Payments · Yarn & Products · Account. Client-side search box filters questions. `<details>` accordions. FAQPage JSON-LD. Sticky group nav on desktop.

### 6.10 Yarn Guide (`/yarn-guide`) — SEO + authority

Full CYC yarn weight chart (0 Lace → 7 Jumbo) with ply names used in India (2-ply, 4-ply, 8-ply…), typical gauge, recommended hook and needle sizes — as a responsive table inside `.scroll-x`, and re-rendered as stacked cards below 640px. Plus: fiber comparison (cotton vs acrylic vs wool vs bamboo vs chenille — feel, drape, warmth, care, best for, price), how to read a yarn label, hook/needle mm↔US↔UK conversion, gauge swatch how-to, yarn substitution rules, care symbol glossary. Every section deep-links to the matching shop filter.

### 6.11 Yarn Calculator (`/yarn-calculator`)

Pick project (Scarf · Blanket · Sweater · Beanie · Amigurumi · Shawl · Bag) → size/dimensions → yarn weight → returns estimated meterage and **"≈ 9 balls of a 50g/125m DK"**, with a ±10% safety note. Then: "Shop DK yarn" button carrying the filter. Pure client-side JS with a documented coefficient table in `assets/js/yarn-calc.js`. This page will earn backlinks — build it properly and give it its own OG image.

### 6.12 Shade Card (`/shade-card`)

Every shade across all products in one wall of swatches, filterable by color family and yarn base. Click a swatch → jumps to that product+shade. Also sells a physical shade card as a ₹99 product (refundable against the first order over ₹999) — a genuine trust and conversion device for color-anxious buyers.

### 6.13 Patterns (`/patterns`)

Free downloadable PDF patterns, tagged by craft, difficulty, and yarn weight. Email required for download (feeds the newsletter with real consent). Each pattern lists the exact yarn + shade + ball count needed, with an "Add all to cart" button. This turns content directly into orders.

### 6.14 Blog (`/blog`)

Tutorial/project posts. Categories, tags, reading time, author, related posts, share buttons (plain links — no third-party widgets), inline product cards. Article JSON-LD.

### 6.15 Track Order (`/track-order`)

Order number + email → status timeline (Placed → Paid → Packed → Shipped → Out for delivery → Delivered) with dates, courier name + AWB + a deep link to the courier's tracking page, items, and delivery address. No login needed. Rate-limited to 10 lookups/hour/IP.

### 6.16 Wholesale (`/wholesale`)

For boutiques, craft teachers, and resellers. Benefits, MOQ, indicative tier discounts, enquiry form (Business name, GSTIN optional, City, Monthly volume, Interested categories, Phone, Gmail). Lands in `/admin/messages` tagged `wholesale`.

### 6.17 Auth pages

**`/login`** — centered card on a soft cream background. One field: "Your Gmail address". Helper: "We only accept Gmail addresses. We'll email you a 6-digit code — no password to remember." Button "Send OTP". Below: trust line + links to Terms/Privacy. A returning-customer hint if a remember-cookie exists.

**`/verify`** — shows the masked email ("ma••••@gmail.com") with a "change" link, 6-box OTP input, a live countdown ("Code expires in 9:47"), "Resend code" disabled for 60s with a visible timer, attempts-remaining warning after 2 failures, and a "Didn't get it? Check spam / Promotions tab" hint. Auto-submits on the 6th digit.

**Error states to design explicitly:** non-Gmail address · wrong code · expired code · too many attempts (locked 30 min) · too many requests (locked 1 hour) · blocked account · email send failure ("We couldn't send the code right now — please try again in a minute or WhatsApp us").

---

## 7. Authentication — Gmail + OTP (no passwords)

### 7.1 Gmail-only rule

```php
function normalize_gmail(string $raw): ?string {
    $e = strtolower(trim($raw));
    if (!filter_var($e, FILTER_VALIDATE_EMAIL)) return null;
    [$local, $domain] = explode('@', $e, 2);
    if ($domain === 'googlemail.com') $domain = 'gmail.com';
    if ($domain !== 'gmail.com') return null;          // Gmail only
    $local = explode('+', $local, 2)[0];               // strip +tags
    $local = str_replace('.', '', $local);             // Gmail ignores dots
    if (strlen($local) < 6) return null;               // real Gmail minimum
    return $local . '@' . $domain;
}
```

Store **both**: `customers.email` = exactly what they typed (used for sending, looks familiar to them) and `customers.email_key` = the normalized value with a **UNIQUE index** — this is what prevents `m.anoj@gmail.com` and `manoj@gmail.com` becoming two accounts with two carts.

Rejection copy must be helpful, never blaming: *"We currently accept Gmail addresses only. Please use your @gmail.com address — or WhatsApp us and we'll place the order for you."*

### 7.2 OTP lifecycle

| Property | Value |
|---|---|
| Length | 6 digits, `random_int(100000, 999999)` — **never `rand()`/`mt_rand()`** |
| Storage | `hash('sha256', $code . OTP_PEPPER)` — never the plaintext |
| TTL | 10 minutes |
| Verify attempts | 5, then the code is consumed and a new one is required |
| Resend cooldown | 60 seconds |
| Requests per email | 5 per hour |
| Requests per IP | 15 per hour |
| Lockout | 30 min after 5 failed verifies; 60 min after hitting the request cap |
| Single-use | `consumed_at` set on success; all older unconsumed codes for that `email_key`+`purpose` are invalidated when a new one is issued |
| Comparison | `hash_equals()` — constant time |

Purposes: `login`, `checkout`, `admin_login`, `email_change`.

### 7.3 Local development

Mirror the proven pattern: `is_local_dev()` returns true for `localhost`, `127.0.0.1`, `::1`, `*.local`, `*.test`, `192.168.*`. When true, **display the OTP on screen** in a dev banner and skip the Brevo call entirely. This keeps local work fast and means the site is fully testable before the Brevo key exists. Guard it with `if (!is_local_dev())` so it can never fire in production.

### 7.4 Session handling

```php
session_name('YARNNEST_SESS');                     // customer; admin uses 'YARNNEST_ADM'
session_set_cookie_params([
  'lifetime' => 0, 'path' => '/', 'domain' => '',
  'secure'   => !is_local_dev(),                   // true in production (HTTPS)
  'httponly' => true, 'samesite' => 'Lax',
]);
session_start();
```

- `session_regenerate_id(true)` immediately after successful OTP verification (fixation defence).
- Store `customer_id`, `email`, `name`, `login_at`, plus a `ua_hash` — mismatch forces re-login.
- Idle timeout 30 days for customers (shopping shouldn't log you out), **8 hours for admins**.
- **Remember me:** 30-day cookie `yn_rmb` = `selector:validator`. Store the selector plainly and `hash('sha256', validator)` in `customer_remember_tokens`. On use, rotate the token. Delete all tokens on logout-everywhere.

### 7.5 Brevo integration

`includes/mailer.php`:
- `brevo_config()` — reads `config/brevo-config.local.php` (returns `['api_key'=>'', 'from_email'=>'', 'from_name'=>'']`) or falls back to `getenv('BREVO_API_KEY')`.
- `brevo_configured()` — false when the key is empty → the whole mail layer is inert, and every caller degrades gracefully.
- `brevo_send($to, $toName, $subject, $html, $text = null, $replyTo = null, $attachments = [])` — cURL POST to `https://api.brevo.com/v3/smtp/email`, header `api-key`, 15s timeout, **never throws**; returns `bool` and writes failures to `email_log` + `error_log`.
- Every send is recorded in `email_log` (to, subject, template, status, provider message id, error).

**Sender setup the client must do once:** add and verify the sending domain in Brevo (DKIM + Brevo's SPF include + a DMARC record), and verify the from-address. Until that's done, mail lands in spam. Document this in `DEPLOY.md`.

**Transactional templates** (HTML, table-based, 600px max, inline CSS, dark-mode-safe, plain-text alternative for each):
1. OTP code
2. Order confirmation (with items + invoice PDF attached)
3. Payment failed / retry link
4. Order shipped (courier + AWB + tracking link)
5. Out for delivery
6. Delivered + review request (48h later)
7. Order cancelled
8. Refund initiated / completed
9. Back-in-stock alert
10. Abandoned cart (6h, then 24h with a coupon)
11. Welcome + first-order coupon
12. Contact form auto-acknowledgement (states the 48h SLA)
13. Admin: new order
14. Admin: low stock
15. Pattern download delivery

---

## 8. Database Schema

Full DDL lives in `sql/001_schema.sql`. Migrations are **ordered, additive files** — `sql/002_*.sql`, `003_*.sql` … never edit an applied file. Keep an `applied_migrations` table.

### 8.1 Core tables

```sql
CREATE TABLE settings (
  k VARCHAR(64) PRIMARY KEY,
  v TEXT NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customers (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(190) NOT NULL,              -- as typed
  email_key     VARCHAR(190) NOT NULL,              -- normalized gmail
  full_name     VARCHAR(120) NULL,
  phone         VARCHAR(15)  NULL,
  email_verified_at DATETIME NULL,
  status        ENUM('active','blocked') NOT NULL DEFAULT 'active',
  marketing_opt_in TINYINT(1) NOT NULL DEFAULT 0,
  craft_prefs   VARCHAR(120) NULL,                  -- csv: crochet,knitting,macrame
  skill_level   ENUM('beginner','intermediate','advanced') NULL,
  birthday      DATE NULL,
  orders_count  INT UNSIGNED NOT NULL DEFAULT 0,
  total_spent   DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  last_login_at DATETIME NULL,
  deleted_at    DATETIME NULL,                      -- soft delete for DPDP requests
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_email_key (email_key),
  KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customer_addresses (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  label ENUM('home','work','other') NOT NULL DEFAULT 'home',
  full_name VARCHAR(120) NOT NULL,
  phone VARCHAR(15) NOT NULL,
  line1 VARCHAR(180) NOT NULL,
  line2 VARCHAR(180) NULL,
  landmark VARCHAR(120) NULL,
  city VARCHAR(80) NOT NULL,
  state VARCHAR(60) NOT NULL,
  pincode CHAR(6) NOT NULL,
  country VARCHAR(60) NOT NULL DEFAULT 'India',
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_cust (customer_id),
  CONSTRAINT fk_addr_cust FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE otp_codes (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email_key VARCHAR(190) NOT NULL,
  purpose ENUM('login','checkout','admin_login','email_change') NOT NULL DEFAULT 'login',
  code_hash CHAR(64) NOT NULL,
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts TINYINT UNSIGNED NOT NULL DEFAULT 5,
  expires_at DATETIME NOT NULL,
  consumed_at DATETIME NULL,
  ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_lookup (email_key, purpose, consumed_at, expires_at),
  KEY idx_rate (email_key, created_at),
  KEY idx_ip_rate (ip, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE customer_remember_tokens (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  selector CHAR(24) NOT NULL,
  validator_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_selector (selector),
  KEY idx_cust (customer_id),
  CONSTRAINT fk_rmb_cust FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 8.2 Catalog

```sql
CREATE TABLE categories (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  parent_id INT UNSIGNED NULL,
  name VARCHAR(100) NOT NULL,
  slug VARCHAR(120) NOT NULL,
  kind ENUM('craft','fiber','weight','other') NOT NULL DEFAULT 'other',
  description TEXT NULL,           -- SEO intro shown on the category page
  image VARCHAR(255) NULL,
  meta_title VARCHAR(180) NULL,
  meta_description VARCHAR(320) NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_slug (slug),
  KEY idx_parent (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE products (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category_id INT UNSIGNED NULL,
  sku_base VARCHAR(40) NOT NULL,
  slug VARCHAR(160) NOT NULL,
  name VARCHAR(180) NOT NULL,
  subtitle VARCHAR(180) NULL,
  brand VARCHAR(80) NULL,
  short_desc VARCHAR(400) NULL,
  long_desc MEDIUMTEXT NULL,
  -- yarn specs
  fiber VARCHAR(140) NULL,                 -- '100% Mercerised Cotton'
  yarn_weight ENUM('lace','super_fine','fine','light','medium','bulky','super_bulky','jumbo') NULL,
  ply VARCHAR(20) NULL,                    -- '8-ply'
  ball_weight_g SMALLINT UNSIGNED NULL,
  length_m SMALLINT UNSIGNED NULL,
  hook_mm DECIMAL(3,1) NULL,
  needle_mm DECIMAL(3,1) NULL,
  gauge VARCHAR(80) NULL,
  care_text VARCHAR(255) NULL,
  country_of_origin VARCHAR(60) NOT NULL DEFAULT 'India',
  -- commerce
  mrp DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  price DECIMAL(10,2) NOT NULL,
  hsn_code VARCHAR(12) NULL,               -- ready for GST
  tax_rate DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  -- state
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  is_featured TINYINT(1) NOT NULL DEFAULT 0,
  is_new TINYINT(1) NOT NULL DEFAULT 0,
  rating_avg DECIMAL(3,2) NOT NULL DEFAULT 0.00,
  rating_count INT UNSIGNED NOT NULL DEFAULT 0,
  sold_count INT UNSIGNED NOT NULL DEFAULT 0,
  meta_title VARCHAR(180) NULL,
  meta_description VARCHAR(320) NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_slug (slug),
  KEY idx_cat (category_id), KEY idx_active (is_active, created_at),
  KEY idx_weight (yarn_weight), KEY idx_price (price),
  FULLTEXT KEY ft_search (name, subtitle, short_desc, fiber, brand)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_variants (          -- one row per SHADE
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  sku VARCHAR(50) NOT NULL,
  shade_name VARCHAR(80) NOT NULL,       -- 'Terracotta Rose'
  shade_code VARCHAR(20) NULL,           -- '214'
  color_hex CHAR(7) NOT NULL DEFAULT '#CCCCCC',
  color_family ENUM('red','pink','orange','yellow','green','blue','purple','brown','grey','black','white','cream','multi') NOT NULL DEFAULT 'multi',
  dye_lot VARCHAR(30) NULL,
  image VARCHAR(255) NULL,
  price_override DECIMAL(10,2) NULL,
  stock INT NOT NULL DEFAULT 0,
  low_stock_at INT NOT NULL DEFAULT 5,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_sku (sku),
  KEY idx_prod (product_id, is_active),
  KEY idx_family (color_family),
  CONSTRAINT fk_var_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_images (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  variant_id INT UNSIGNED NULL,
  path VARCHAR(255) NOT NULL,
  alt VARCHAR(200) NULL,
  kind ENUM('image','video') NOT NULL DEFAULT 'image',
  sort_order SMALLINT NOT NULL DEFAULT 0,
  KEY idx_prod (product_id, sort_order),
  CONSTRAINT fk_img_prod FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE collections (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(120) NOT NULL, slug VARCHAR(140) NOT NULL,
  description TEXT NULL, banner VARCHAR(255) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1, sort_order SMALLINT NOT NULL DEFAULT 0,
  UNIQUE KEY uq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE collection_products (
  collection_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  sort_order SMALLINT NOT NULL DEFAULT 0,
  PRIMARY KEY (collection_id, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE stock_movements (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  variant_id INT UNSIGNED NOT NULL,
  delta INT NOT NULL,                    -- negative = out
  reason ENUM('order','cancel','return','manual','import','correction') NOT NULL,
  ref_type VARCHAR(20) NULL, ref_id INT UNSIGNED NULL,
  note VARCHAR(200) NULL,
  admin_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_var (variant_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 8.3 Cart, wishlist, coupons

```sql
CREATE TABLE carts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NULL,
  session_token CHAR(40) NULL,          -- guest carts
  status ENUM('active','converted','abandoned') NOT NULL DEFAULT 'active',
  reminder_sent_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_cust (customer_id), KEY idx_token (session_token),
  KEY idx_abandon (status, updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE cart_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  cart_id INT UNSIGNED NOT NULL,
  variant_id INT UNSIGNED NOT NULL,
  qty SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  saved_for_later TINYINT(1) NOT NULL DEFAULT 0,
  added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_cart_variant (cart_id, variant_id),
  CONSTRAINT fk_ci_cart FOREIGN KEY (cart_id) REFERENCES carts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE wishlists (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL,
  variant_id INT UNSIGNED NOT NULL,
  notify_restock TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_c_v (customer_id, variant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE coupons (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code VARCHAR(40) NOT NULL,
  type ENUM('percent','fixed','free_shipping') NOT NULL,
  value DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  min_order DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  max_discount DECIMAL(10,2) NULL,
  usage_limit INT UNSIGNED NULL,
  per_customer_limit SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  used_count INT UNSIGNED NOT NULL DEFAULT 0,
  first_order_only TINYINT(1) NOT NULL DEFAULT 0,
  starts_at DATETIME NULL, ends_at DATETIME NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE coupon_redemptions (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  coupon_id INT UNSIGNED NOT NULL,
  customer_id INT UNSIGNED NULL,
  order_id INT UNSIGNED NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_coupon (coupon_id), KEY idx_cust (customer_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 8.4 Orders & payments

```sql
CREATE TABLE orders (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_no VARCHAR(20) NOT NULL,               -- YN-2608-0001
  customer_id INT UNSIGNED NULL,
  email VARCHAR(190) NOT NULL,
  phone VARCHAR(15) NOT NULL,
  status ENUM('pending_payment','paid','processing','packed','shipped','out_for_delivery',
              'delivered','cancelled','refunded','returned','failed') NOT NULL DEFAULT 'pending_payment',
  payment_status ENUM('unpaid','paid','partially_refunded','refunded','failed') NOT NULL DEFAULT 'unpaid',
  payment_method ENUM('razorpay','cod') NOT NULL DEFAULT 'razorpay',
  subtotal DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  discount_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  shipping_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  tax_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  gift_wrap_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  grand_total DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  currency CHAR(3) NOT NULL DEFAULT 'INR',
  coupon_code VARCHAR(40) NULL,
  ship_name VARCHAR(120) NOT NULL, ship_phone VARCHAR(15) NOT NULL,
  ship_line1 VARCHAR(180) NOT NULL, ship_line2 VARCHAR(180) NULL,
  ship_landmark VARCHAR(120) NULL, ship_city VARCHAR(80) NOT NULL,
  ship_state VARCHAR(60) NOT NULL, ship_pincode CHAR(6) NOT NULL,
  ship_country VARCHAR(60) NOT NULL DEFAULT 'India',
  bill_same TINYINT(1) NOT NULL DEFAULT 1,
  bill_json TEXT NULL,
  customer_note VARCHAR(500) NULL, admin_note VARCHAR(500) NULL,
  courier VARCHAR(80) NULL, awb VARCHAR(60) NULL, tracking_url VARCHAR(255) NULL,
  placed_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  paid_at DATETIME NULL, shipped_at DATETIME NULL, delivered_at DATETIME NULL,
  cancelled_at DATETIME NULL, cancel_reason VARCHAR(255) NULL,
  ip VARCHAR(45) NULL, user_agent VARCHAR(255) NULL,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_order_no (order_no),
  KEY idx_cust (customer_id, placed_at),
  KEY idx_status (status, placed_at),
  KEY idx_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_items (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NULL, variant_id INT UNSIGNED NULL,
  -- immutable snapshot: the product may be renamed or deleted later
  sku VARCHAR(50) NOT NULL,
  product_name VARCHAR(180) NOT NULL,
  shade_name VARCHAR(80) NULL, color_hex CHAR(7) NULL,
  image VARCHAR(255) NULL,
  unit_price DECIMAL(10,2) NOT NULL,
  qty SMALLINT UNSIGNED NOT NULL,
  line_total DECIMAL(10,2) NOT NULL,
  KEY idx_order (order_id),
  CONSTRAINT fk_oi_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE order_status_history (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  from_status VARCHAR(30) NULL, to_status VARCHAR(30) NOT NULL,
  note VARCHAR(255) NULL, changed_by ENUM('system','admin','customer') NOT NULL DEFAULT 'system',
  admin_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_order (order_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payments (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  provider VARCHAR(20) NOT NULL DEFAULT 'razorpay',
  provider_order_id VARCHAR(60) NULL,
  provider_payment_id VARCHAR(60) NULL,
  provider_signature VARCHAR(255) NULL,
  method VARCHAR(30) NULL,                   -- upi / card / netbanking / wallet
  amount DECIMAL(10,2) NOT NULL,
  status ENUM('created','authorized','captured','failed','refunded') NOT NULL DEFAULT 'created',
  error_code VARCHAR(60) NULL, error_desc VARCHAR(255) NULL,
  raw_response MEDIUMTEXT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_order (order_id),
  UNIQUE KEY uq_pay (provider_payment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE payment_webhooks (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  event_id VARCHAR(80) NOT NULL,
  event_type VARCHAR(60) NOT NULL,
  payload MEDIUMTEXT NOT NULL,
  processed_at DATETIME NULL,
  error VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_event (event_id)                -- idempotency
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE refunds (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  provider_refund_id VARCHAR(60) NULL,
  amount DECIMAL(10,2) NOT NULL,
  reason VARCHAR(255) NULL,
  status ENUM('initiated','processed','failed') NOT NULL DEFAULT 'initiated',
  admin_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE return_requests (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id INT UNSIGNED NOT NULL,
  customer_id INT UNSIGNED NULL,
  items_json TEXT NOT NULL,
  reason ENUM('damaged','wrong_item','not_as_described','quality','changed_mind','other') NOT NULL,
  comments VARCHAR(600) NULL,
  photos_json TEXT NULL,
  status ENUM('requested','approved','rejected','picked','received','refunded') NOT NULL DEFAULT 'requested',
  admin_note VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_order (order_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 8.5 Content, engagement, ops

```sql
CREATE TABLE reviews (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  variant_id INT UNSIGNED NULL,
  customer_id INT UNSIGNED NULL,
  order_id INT UNSIGNED NULL,
  rating TINYINT UNSIGNED NOT NULL,
  title VARCHAR(140) NULL, body TEXT NULL,
  photos_json TEXT NULL,
  is_verified TINYINT(1) NOT NULL DEFAULT 0,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  helpful_count INT UNSIGNED NOT NULL DEFAULT 0,
  admin_reply VARCHAR(600) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_prod (product_id, status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pages (                    -- policy + static content, admin-editable
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(80) NOT NULL, title VARCHAR(180) NOT NULL,
  body MEDIUMTEXT NOT NULL,
  meta_title VARCHAR(180) NULL, meta_description VARCHAR(320) NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE blog_posts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(180) NOT NULL, title VARCHAR(200) NOT NULL,
  excerpt VARCHAR(400) NULL, body MEDIUMTEXT NOT NULL,
  cover VARCHAR(255) NULL, author VARCHAR(80) NULL,
  tags VARCHAR(200) NULL,
  meta_title VARCHAR(180) NULL, meta_description VARCHAR(320) NULL,
  status ENUM('draft','published') NOT NULL DEFAULT 'draft',
  published_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_slug (slug), KEY idx_pub (status, published_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE patterns (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  slug VARCHAR(160) NOT NULL, title VARCHAR(200) NOT NULL,
  craft ENUM('crochet','knitting','macrame','other') NOT NULL DEFAULT 'crochet',
  difficulty ENUM('beginner','easy','intermediate','advanced') NOT NULL DEFAULT 'beginner',
  yarn_weight VARCHAR(20) NULL,
  cover VARCHAR(255) NULL, pdf_path VARCHAR(255) NOT NULL,
  suggested_products VARCHAR(120) NULL,     -- csv of product ids
  downloads INT UNSIGNED NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  UNIQUE KEY uq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE faqs (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category VARCHAR(60) NOT NULL, question VARCHAR(300) NOT NULL,
  answer TEXT NOT NULL, sort_order SMALLINT NOT NULL DEFAULT 0,
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE banners (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  placement ENUM('hero','strip','collection') NOT NULL DEFAULT 'hero',
  title VARCHAR(180) NULL, subtitle VARCHAR(300) NULL,
  image_desktop VARCHAR(255) NULL, image_mobile VARCHAR(255) NULL,
  cta_text VARCHAR(60) NULL, cta_url VARCHAR(255) NULL,
  starts_at DATETIME NULL, ends_at DATETIME NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1, sort_order SMALLINT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE contact_messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  kind ENUM('contact','wholesale') NOT NULL DEFAULT 'contact',
  name VARCHAR(120) NOT NULL, email VARCHAR(190) NOT NULL,
  phone VARCHAR(15) NULL, subject VARCHAR(180) NULL,
  order_no VARCHAR(20) NULL, message TEXT NOT NULL,
  extra_json TEXT NULL,
  status ENUM('new','read','replied','closed') NOT NULL DEFAULT 'new',
  admin_reply TEXT NULL, ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE support_tickets (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id INT UNSIGNED NOT NULL, order_id INT UNSIGNED NULL,
  subject VARCHAR(180) NOT NULL,
  status ENUM('open','awaiting_customer','resolved','closed') NOT NULL DEFAULT 'open',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_cust (customer_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE support_messages (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ticket_id INT UNSIGNED NOT NULL,
  sender ENUM('customer','admin') NOT NULL,
  body TEXT NOT NULL, attachment VARCHAR(255) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ticket (ticket_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE newsletter_subscribers (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL,
  source VARCHAR(40) NULL,
  status ENUM('pending','confirmed','unsubscribed') NOT NULL DEFAULT 'pending',
  confirm_token CHAR(40) NULL, confirmed_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE restock_alerts (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  variant_id INT UNSIGNED NOT NULL, email VARCHAR(190) NOT NULL,
  notified_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_var_email (variant_id, email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE pincodes (
  pincode CHAR(6) PRIMARY KEY,
  city VARCHAR(80) NOT NULL, state VARCHAR(60) NOT NULL,
  is_serviceable TINYINT(1) NOT NULL DEFAULT 1,
  eta_days_min TINYINT UNSIGNED NOT NULL DEFAULT 3,
  eta_days_max TINYINT UNSIGNED NOT NULL DEFAULT 7
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admin_users (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL, email_key VARCHAR(190) NOT NULL,
  name VARCHAR(120) NOT NULL,
  role ENUM('owner','manager','staff') NOT NULL DEFAULT 'staff',
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  last_login_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_email_key (email_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE admin_activity_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_id INT UNSIGNED NULL, action VARCHAR(80) NOT NULL,
  entity VARCHAR(40) NULL, entity_id INT UNSIGNED NULL,
  detail VARCHAR(500) NULL, ip VARCHAR(45) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_admin (admin_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE email_log (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  to_email VARCHAR(190) NOT NULL, subject VARCHAR(255) NOT NULL,
  template VARCHAR(60) NULL,
  status ENUM('sent','failed','skipped') NOT NULL,
  provider_message_id VARCHAR(120) NULL, error VARCHAR(500) NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_created (created_at), KEY idx_to (to_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 8.6 Seed data

- **Settings:** store name/address/phone/email, `ship_flat_rate=69`, `ship_free_threshold=999`, `cod_enabled=0`, `gst_enabled=0`, `return_window_days=7`, `order_prefix=YN`, social URLs, WhatsApp number, grievance officer name/email.
- **Categories:** By Craft (Crochet, Knitting, Amigurumi, Macramé, Weaving) · By Fiber (Cotton, Acrylic, Wool, Bamboo, Chenille/Velvet, Jute, Silk Blend, Recycled) · By Weight (Lace 0 → Jumbo 7) · Kits & Bundles · Tools & Accessories · Sale.
- **8 admin-editable policy pages** with the drafted text from §12.
- **~24 demo products** with 6–12 shades each and real hex values, so every grid, filter, and PDP state can be tested on day one.
- **A demo customer + 4 orders** in different statuses (paid, shipped, delivered, cancelled) so the dashboard and admin views aren't empty during development.
- **Pincode table** — ship a starter CSV of major-city pincodes; full India dataset is optional.

---

## 9. Customer Dashboard (`/account`)

**Layout:** left sidebar (sticky, 240px) on desktop → horizontal scroll-snap tab strip on mobile. Content area on the right. Never a hamburger inside a hamburger.

**Overview page:**
- Greeting: "Hello, Priya 👋" + member-since.
- **Active order card** (if any) — the biggest element: order no, thumbnail stack, status pill, progress timeline, expected delivery, Track button. This is what people log in for.
- 4 stat tiles: Total Orders · Wishlist Items · Reviews Written · Coupons Available.
- Recent orders (3) with a "View all".
- "Complete your profile" nudge with a progress ring (phone, address, craft preferences) — improves data quality for shipping.
- Recommended for you — based on past purchases (same fiber/weight).
- Support shortcut card: WhatsApp + Raise a ticket.

**Orders:** filter pills (All / Active / Delivered / Cancelled), search by order no or product. Card per order: date, order no, status pill, item thumbnails, total, actions (View · Track · Invoice · Reorder · Cancel [if pre-dispatch] · Return [if within window]).

**Order detail:** vertical status timeline with timestamps · items with shade swatches · price breakdown · addresses · payment method + last 4 / UPI handle · courier + AWB with tracking link · Download Invoice · Need help? → creates a ticket pre-filled with the order.

**Addresses:** cards with default badge, add/edit in a modal, pincode auto-fill, delete confirmation.

**Wishlist:** grid of saved shades with price-drop indicator, stock status, "Notify when back" toggle, Move to Cart.

**Profile:** name, phone (OTP-verified on change), birthday (for a birthday coupon), craft preferences (chips), skill level. Email is shown read-only with a "Change email" flow requiring OTP on both addresses.

**Notifications:** transactional = always on and shown greyed out with an explanation; marketing = a real toggle. One-click unsubscribe honored.

**Delete account:** explains what is deleted vs what is legally retained (order/invoice records must be retained for tax and consumer-dispute purposes), requires OTP confirmation, soft-deletes and anonymizes the profile, cancels marketing. **This is a DPDP Act 2023 obligation, not a nice-to-have.**

**Mobile specifics:** the tab strip is scroll-snap with the active tab auto-scrolled into view; the bottom nav's Account icon shows a dot when an order is in transit.

---

## 10. Admin Panel (`/admin`)

Completely separate visual language: denser, functional, `--sand` chrome with the same brand colors so it still feels like one product. Own session name, own header/footer includes.

**Access:** email OTP against `admin_users`. Additionally require the email to be in an allowlist in `config/admin-config.php` — belt and braces. 8-hour session. Every mutating action writes to `admin_activity_log`. Roles: `owner` (all), `manager` (all except admin users + settings), `staff` (orders, inventory, messages only).

**Dashboard KPIs:** Today / 7d / 30d toggle → Revenue · Orders · AOV · New customers · Conversion. Plus: pending orders needing action (count, clickable), low-stock shades, unmoderated reviews, unread messages, failed payments in the last 24h, a 30-day revenue sparkline, and top 5 products.

**Orders** — the most-used screen, so build it first and build it well:
- Table inside `.scroll-x`: checkbox · Order # · Date · Customer · Items · Total · Payment · Status · Actions.
- Filters: status, payment status, date range, search (order no / email / phone / AWB).
- Bulk: mark packed, mark shipped (with a courier + AWB entry modal), export CSV, print packing slips.
- Detail page: two columns → single column under 1024px. Items, totals, customer, addresses, payment (with the Razorpay payment id linked to the Razorpay dashboard), status changer with a mandatory note, courier/AWB fields, internal notes, full status history, resend confirmation email, download invoice, print packing slip, initiate refund (full or partial, via the Razorpay refunds API).
- **Status transitions are validated server-side** by an explicit allowed-transitions map — no free-form status setting.

**Products:** list with an inline stock and price editor (save-on-blur AJAX), image thumb, status toggle, duplicate. Editor is tabbed: Basics · Yarn Specs · Shades (repeatable rows with a color picker writing `color_hex`, SKU, stock, image) · Images (drag-drop, reorder, alt text) · SEO · Related. **CSV import/export** for both products and variants — essential when adding 60 shades at once.

**Inventory:** low-stock table sorted by urgency, bulk "add stock" with a reason note, and the full `stock_movements` audit trail per shade.

**Coupons, Customers, Reviews, Messages, Support, Content, Reports** as listed in §5.4.

**Settings** (writes to `settings`): Store identity (name, legal name, address, phone, WhatsApp, email, GSTIN) · Shipping (flat rate, free threshold, dispatch days, ETA ranges) · Payments (Razorpay key id + secret + webhook secret, test/live toggle, COD flag) · Email (Brevo key, from name/address, admin notification recipients) · SEO defaults & OG image · Feature flags (reviews on/off, blog on/off, patterns on/off, wholesale on/off, gift wrap, loyalty).

**Every list screen** gets: search, filters, sort, pagination, per-page selector, empty state, and CSV export. Build these once as shared helpers, not per-screen.

---

## 11. Payments — Razorpay

**Files:** `includes/payments/razorpay.php`, `checkout-init.php`, `payment-verify.php`, `webhooks/razorpay.php`.

**Config** (`config/razorpay-config.local.php`): `key_id`, `key_secret`, `webhook_secret`, `mode` (`test`|`live`). Ships inert with empty strings; when empty, checkout shows "Payments are being set up — please WhatsApp us to order" instead of erroring.

**Flow:**
1. `POST /checkout-init.php` — CSRF check → validate address → re-read cart from DB → **recompute every total server-side** → verify stock for every line → create the `orders` row (`pending_payment`) and `order_items` snapshot → `POST https://api.razorpay.com/v1/orders` (Basic auth `key_id:key_secret`, `amount` in **paise**, `receipt` = order_no, `notes` = order id) → save `provider_order_id` in `payments` → return the JSON the front end needs.
2. Front end opens Razorpay Checkout (`https://checkout.razorpay.com/v1/checkout.js`) with prefill (name, email, contact) and brand theme color `#B4552F`. **This is the one permitted external script** — note it in the CSP and the cookie policy.
3. Success handler → `POST /payment-verify.php` with `razorpay_order_id`, `razorpay_payment_id`, `razorpay_signature`.
4. Server verifies: `hash_equals(hash_hmac('sha256', "$order_id|$payment_id", $key_secret), $signature)`. On success, in **one transaction**: set order `paid`/`processing`, `paid_at`, insert the payment row, decrement stock with the guarded UPDATE, log stock movements, increment coupon usage, mark the cart converted, bump customer totals. Commit → then send emails (outside the transaction, never let a mail failure roll back a paid order).
5. Failure/dismiss → order stays `pending_payment`, payment row records the error code, customer returns to checkout with the cart intact.
6. **Webhook** `POST /webhooks/razorpay.php` — read `php://input` **raw** (before any parsing), verify `X-Razorpay-Signature` with the webhook secret, insert into `payment_webhooks` (the UNIQUE `event_id` gives idempotency for free), then handle `payment.captured`, `payment.failed`, `refund.processed`, `order.paid`. **The webhook is the source of truth** — it catches customers who close the browser after paying. Always respond `200` quickly, even for events you ignore.
7. **Refunds:** admin action → `POST /v1/payments/{id}/refund` → record in `refunds` → update `payment_status` → email the customer.

**Reconciliation:** a `cron/expire-pending-orders.php` script (run hourly, or on-demand from admin) cancels `pending_payment` orders older than 60 minutes and releases nothing (stock was never decremented) — plus flags any order that has a captured Razorpay payment but isn't marked paid, for manual review.

**Amount safety:** the amount sent to Razorpay and the amount stored in `orders.grand_total` are computed by the *same* function, once. Never recompute independently in two places.

---

## 12. Policies for Indian Customers

> **Note for the client:** these are thorough, industry-standard drafts written for an Indian D2C store and aligned with the Consumer Protection (E-Commerce) Rules 2020, the Legal Metrology (Packaged Commodities) Rules 2011, the IT Act 2000 with the SPDI Rules 2011, and the DPDP Act 2023. They are **not legal advice** — have a lawyer review them before launch, and fill in every `[bracketed]` field.

All eight are seeded into the `pages` table and editable from `/admin/content/pages`. Each page shows a "Last updated" date. Each is written in plain English with short sections and an accordion layout on mobile.

### 12.1 Shipping & Delivery Policy
Dispatch: 1–2 business days (Mon–Sat, excl. public holidays). Delivery: metros 3–5 business days, rest of India 5–8, North-East / J&K / Ladakh / Andaman & Nicobar 7–12. Charges: **flat ₹69; FREE on orders above ₹999** (stated as pulled live from settings, so the page never contradicts the cart). Courier partners named. Tracking link emailed at dispatch and available at `/track-order`. Delivery attempts: 2–3, then RTO. Wrong/incomplete address = re-shipping charges. Not delivered? Report within 7 days of the expected date. We ship only within India (state that clearly). Partial shipments possible for large orders at no extra cost.

### 12.2 Returns, Refunds & Exchange Policy
- **7 days from delivery** to raise a return.
- **Eligible:** unused, unopened yarn in original packaging with the label/band intact; damaged, defective, or wrong item received.
- **Not eligible:** yarn that has been wound, cut, used, or washed; items missing the original band; clearance/final-sale items; physical shade cards; digital patterns; items damaged by misuse.
- **Damaged/wrong item:** report within **48 hours** with photos and, where possible, an **unboxing video** — this is standard practice in India and protects both sides. We arrange free pickup or a replacement.
- **Change of mind:** accepted within 7 days if unopened; return shipping (₹[X]) is deducted from the refund.
- **Dye lot variation is not a defect.** Shades vary between dye lots and across screens; we always state the current lot on the product page. Order all balls for one project together.
- **Refund timeline:** approved and processed within **2 business days of receiving the return**; the amount reaches the original payment method in **5–7 business days** (bank-dependent). Refunds go back to the original method only — no cash, no alternate account.
- **Exchanges:** for a different shade, subject to stock; the price difference is collected or refunded.
- **Cancelled/failed payments** are auto-reversed by the bank in 5–7 working days.
- How to raise a return: `/account/orders/{order}` → Return, or email/WhatsApp with the order number.

### 12.3 Cancellation Policy
Free cancellation any time **before dispatch**, from the account dashboard, with a full refund in 5–7 business days. After dispatch, cancellation isn't possible — refuse delivery or use the return process. We reserve the right to cancel an order (with a full refund) for stock errors, pricing errors, non-serviceable pincodes, or suspected fraud, and will always notify you by email first.

### 12.4 Privacy Policy (DPDP-aligned)
Who we are and how to reach us · what we collect (name, Gmail address, phone, delivery address, order history, device/usage data, and **never** card or UPI credentials — those go directly to Razorpay and never touch our servers) · why we collect it (fulfil orders, verify identity via OTP, provide support, prevent fraud, send transactional email, and — only with your consent — marketing) · lawful basis and consent, with a plain statement that you can withdraw consent at any time · who we share with (courier partners, Razorpay, Brevo for email — named, with purpose) · we never sell your data · cookies (see the cookie policy) · security measures (HTTPS, hashed OTPs, no stored passwords, access controls) · retention (account data until deletion; order and invoice records retained as required by tax and consumer-protection law) · **your rights** — access, correction, erasure, grievance redressal, and nomination · children under 18 · changes to the policy · **Grievance Officer: [Name], [email], response within 48 hours, resolution within 30 days**.

### 12.5 Terms & Conditions
Legal entity name and address · eligibility (18+, or with guardian consent) · account rules (Gmail-only login, OTP responsibility, one account per person) · product descriptions and color accuracy disclaimer · **pricing in INR, inclusive of taxes; we may correct pricing errors and cancel affected orders with a full refund** · order acceptance forms a contract only on our confirmation · payments handled by Razorpay under their terms · shipping/returns incorporated by reference · prohibited uses (scraping, resale without a wholesale agreement, fraud) · intellectual property (photos, patterns, and copy are ours; free patterns are for personal use — finished items may be sold in small quantities with credit, but the pattern file may not be redistributed) · user content licence for reviews and photos · limitation of liability capped at the order value · indemnity · force majeure · **governing law: India; exclusive jurisdiction: courts at [City], [State]** · changes to terms.

### 12.6 Cookie Policy
What cookies are · what we set: strictly necessary (`YARNNEST_SESS` session, CSRF token, cart token), functional (`yn_rmb` remember-me 30 days, pincode preference, dismissed announcement), and analytics **only if** analytics are enabled — with a consent banner in that case · third parties: Razorpay Checkout sets its own cookies during payment · how to control cookies in your browser · consequence of blocking (cart and login stop working). **If no analytics/marketing scripts are added, no consent banner is required** — that's the recommended launch state: fewer scripts, faster site, less compliance surface.

### 12.7 Disclaimer
Product photos are shot in natural daylight but screen calibration varies · dye lots vary · fiber content and meterage are as supplied by the manufacturer with a normal manufacturing tolerance · gauge, hook, and needle recommendations are guidance, not guarantees — always swatch · free patterns are provided as-is · not responsible for results from third-party patterns · external links are not endorsements.

### 12.8 Grievance Redressal
Mandated by the e-commerce and IT rules. Names the Grievance Officer, gives a direct email and phone, states the escalation ladder (support → grievance officer → National Consumer Helpline 1915 / consumer.gov.in), and commits to a 48-hour acknowledgement and a 30-day resolution.

### 12.9 Compliance checklist for the executor
- [ ] Legal name, registered address, phone, and email visible in the footer on **every** page.
- [ ] Grievance Officer name + contact published on the contact page and the grievance page.
- [ ] Every product shows **net quantity (g), MRP, selling price, country of origin, and consumer-care contact** — Legal Metrology requirement for pre-packed goods sold online.
- [ ] Total price breakdown shown before payment; no charge revealed after the pay button.
- [ ] Return, refund, cancellation, and delivery terms linked from the checkout page.
- [ ] No card data touches our server or database (Razorpay-hosted checkout only).
- [ ] Marketing email requires explicit opt-in with double confirmation; every marketing email carries a one-click unsubscribe.
- [ ] Account deletion available to the customer without contacting support.
- [ ] "Last updated" date on every policy page.

---

## 13. Feature Roster

**Phase 1 — launch critical**
Gmail+OTP auth · catalog with shade variants · filters & search · PDP · cart · Razorpay checkout · order emails · customer dashboard · admin (orders/products/inventory/customers/settings) · 8 policy pages · flat-rate shipping with free threshold · PDF invoice · guest order tracking · contact form · WhatsApp button · responsive + overflow-clean · SEO basics + sitemap.

**Phase 2 — growth (build the hooks in Phase 1, ship the features here)**
Reviews with photos · wishlist + back-in-stock alerts · coupons · abandoned-cart emails · yarn calculator · yarn guide · shade card · free pattern library · blog · newsletter with a first-order coupon · recently viewed · related/similar yarns · pincode delivery estimate · wholesale enquiry · gift wrap · quick view · support tickets.

**Phase 3 — retention**
Loyalty points (1% back as store credit) · referral coupons · project kits and bundles · "complete the project" upsells · birthday coupon · saved projects/stash tracker · PWA install · price-drop alerts · Hindi/Marathi language toggle · shade comparison tool · Q&A on PDP.

**Deliberately excluded (say no to these):** fake urgency timers, invented "N people viewing", forced account creation before browsing, popup modals on page load, auto-playing video with sound, infinite scroll without pagination fallback, third-party chat widgets that add 300KB.

---

## 14. Performance, SEO, Accessibility, Security

**Performance targets (mobile, 4G):** LCP < 2.5s · CLS < 0.05 · INP < 200ms · total page weight < 900KB on the home page.
- Images: WebP (AVIF where produced), `srcset` + `sizes` for every product image, explicit `width`/`height`, `loading="lazy"` below the fold, one `fetchpriority="high"` hero.
- Generate 3 sizes per product image on upload (320 / 640 / 1200) via GD. Never serve a 2MB original.
- One CSS file, one JS file, both minified, both `?v=`-busted, `defer` on JS.
- Preload the two body fonts, `font-display: swap`.
- Enable gzip/brotli + far-future cache headers for `/assets/` and `/uploads/` in `.htaccess`.
- Every list query is indexed and paginated. No `SELECT *` on list pages. No N+1 — fetch variants for a product page in one `WHERE product_id IN (...)`.

**SEO:** unique `<title>` + meta description per page · canonical on every page · `noindex` on cart/checkout/account/admin and on filtered shop URLs · OG + Twitter cards with a per-product image · JSON-LD for `Organization`, `WebSite` + `SearchAction`, `Product`+`Offer`+`AggregateRating`, `BreadcrumbList`, `FAQPage`, `Article` · clean URLs via `.htaccess` rewrites · auto-generated `sitemap.xml` (products, categories, blog, patterns, static) · `robots.txt` disallowing `/admin`, `/account`, `/checkout`, `/cart` · descriptive `alt` on every image · one `<h1>` per page · 301s for any slug change (keep a `slug_redirects` table).

**Accessibility (WCAG 2.1 AA):** semantic landmarks (`header/nav/main/footer`) · skip-to-content link · visible focus ring (2px clay, 2px offset) never removed · all interactive elements keyboard-reachable in a logical order · form labels always present (placeholders are not labels) · errors announced via `aria-live` · color never the only signal (out-of-stock shades get a line, not just dimming) · modal focus trap + ESC + focus restore · `prefers-reduced-motion` disables the carousel and transitions · contrast checked at every token pairing · shade swatches carry `aria-label` with the shade name.

**Security:** prepared statements everywhere · CSRF on every POST · `e()` on every output · session hardening per §7.4 · rate limits on OTP, login, contact, review, and track-order · upload validation + `.htaccess` in `/uploads` denying PHP execution · `config/` and `sql/` denied via `.htaccess` (and ideally moved above the web root in production) · security headers: `X-Frame-Options: SAMEORIGIN`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: strict-origin-when-cross-origin`, `Permissions-Policy`, and a CSP allowing only `self` + `checkout.razorpay.com` + `api.razorpay.com` · `display_errors=Off` and `log_errors=On` in production · admin panel behind a separate session with an IP-allowlist option · webhook signature verification · no secrets in the repo or the deploy zip · daily DB backup instructions in `DEPLOY.md`.

---

## 15. File & Folder Structure

```
C:\xampp\htdocs\
├─ index.php  shop.php  product.php  collection.php  search.php
├─ cart.php  checkout.php  checkout-init.php  payment-verify.php  order-success.php
├─ login.php  verify.php  logout.php
├─ about.php  contact.php  faq.php  policy.php  track-order.php
├─ yarn-guide.php  yarn-calculator.php  shade-card.php  patterns.php
├─ blog.php  blog-post.php  wholesale.php  reviews.php
├─ 404.php  500.php  sitemap.php  robots.txt  .htaccess
├─ account/
│   ├─ index.php  orders.php  order-detail.php  addresses.php  wishlist.php
│   ├─ profile.php  reviews.php  coupons.php  support.php  notifications.php  delete.php
├─ admin/
│   ├─ login.php  index.php  logout.php
│   ├─ orders.php  order-detail.php
│   ├─ products.php  product-edit.php  categories.php  collections.php  inventory.php
│   ├─ coupons.php  customers.php  customer-detail.php  reviews.php
│   ├─ messages.php  support.php  reports.php  settings.php  users.php  logs.php
│   └─ content/  (banners.php  blog.php  pages.php  faq.php  patterns.php)
├─ includes/
│   ├─ bootstrap.php        ← session, db, helpers, settings loader. FIRST require in every file
│   ├─ db.php  helpers.php  auth.php  csrf.php  ratelimit.php
│   ├─ cart-lib.php  order-lib.php  pricing.php  shipping.php  coupon-lib.php
│   ├─ mailer.php  email-templates/
│   ├─ payments/razorpay.php
│   ├─ invoice.php          ← FPDF invoice + packing slip
│   ├─ header.php  footer.php  nav.php  bottom-nav.php
│   ├─ admin-header.php  admin-footer.php
│   ├─ components/          ← product-card, price, rating, swatch, pagination, empty-state…
│   └─ home/                ← one file per home section
├─ webhooks/razorpay.php
├─ cron/  (expire-pending-orders.php  abandoned-cart.php  restock-alerts.php  review-requests.php)
├─ config/
│   ├─ site-config.php  admin-config.php  db-config.php
│   ├─ db-config.local.php  brevo-config.local.php  razorpay-config.local.php   (gitignored)
│   └─ .htaccess           ← Deny from all
├─ assets/ css/style.css  js/main.js  js/yarn-calc.js  fonts/  img/
├─ uploads/ products/  reviews/  banners/  patterns/  blog/   (+ .htaccess no-exec)
├─ sql/ 001_schema.sql  002_seed.sql  …
├─ vendor/               ← composer: setasign/fpdf only
├─ PLAN.md  DEPLOY.md  CLAUDE.md
```

`includes/bootstrap.php` is the single entry point: it sets error handling, starts the correct session, connects the DB, loads settings into `$CFG`, loads helpers, and exposes `$customer` when logged in. Every page's first line is `require __DIR__ . '/includes/bootstrap.php';`.

---

## 16. Build Phases

Each phase ends with a working, demoable site. Do not start a phase before the previous one passes its gate.

| Phase | Scope | Gate (must all pass) |
|---|---|---|
| **0. Foundation** | Folder structure, `bootstrap.php`, db layer, helpers, CSRF, rate limiter, config files, `.htaccess`, `sql/001_schema.sql` + seed, design tokens + reset + component CSS, header/footer/bottom-nav, 404/500 | Empty styled shell loads at every breakpoint; **overflow QA script reports zero** at 320px; DB imports clean from scratch |
| **1. Catalog** | Categories, products, variants, images, shop grid, filters, sort, pagination, search, PDP with gallery + shade selector + accordions, seed 24 products | Every filter combination returns correct results; PDP works with 1 shade and with 12; no overflow; Lighthouse mobile ≥ 90 on shop and PDP |
| **2. Auth** | Gmail normalization, OTP issue/verify, rate limits, lockouts, sessions, remember-me, login/verify pages, Brevo mailer + OTP template, local dev on-screen OTP | Non-Gmail rejected; `m.anoj@` and `manoj@` resolve to one account; expired/wrong/too-many-attempts all handled; works with the Brevo key empty |
| **3. Cart & Checkout** | Cart lib (guest + DB), cart page + drawer, guest→customer merge, coupons, shipping calc, free-shipping bar, 3-step checkout, address book, pincode autofill | Totals recomputed server-side match the display to the paisa; cart survives login; out-of-stock lines blocked |
| **4. Payments** | Razorpay order create, checkout modal, signature verify, webhook, transactional stock decrement, refunds, order emails, invoice PDF, order-success, guest tracking | Test-mode payment completes end-to-end; webhook is idempotent (fire the same event twice); closing the browser mid-payment still results in a paid order via webhook; two simultaneous buys of the last ball → exactly one succeeds |
| **5. Account** | Full customer dashboard, orders, addresses, profile, wishlist, notifications, delete account | Every screen works at 320px; a customer can cancel pre-dispatch and request a return post-delivery |
| **6. Admin** | Admin auth, dashboard, orders, products+variants editor, inventory, customers, coupons, settings, activity log, CSV import/export | A non-technical person can add a product with 8 shades and ship an order without help |
| **7. Content & Trust** | Home sections, about, contact, FAQ, 8 policy pages, blog, patterns, yarn guide, yarn calculator, shade card, wholesale, reviews + moderation, newsletter | Policies complete with no `[brackets]` left; contact form delivers; reviews moderate correctly |
| **8. Polish & Launch** | SEO/JSON-LD/sitemap, performance pass, accessibility audit, security headers, cron scripts, `DEPLOY.md`, backups, full test sweep | Full checklist in §17 green; live Razorpay keys tested with one real ₹1 order and refunded |

---

## 17. Test Checklist (run before launch)

**Responsive:** every page at 320 / 360 / 390 / 414 / 768 / 1024 / 1280 / 1920 — the overflow script reports zero on all of them. Test with a 40-character product name and a 12-shade product. Test the bottom nav doesn't cover the last element.

**Auth:** non-Gmail rejected · dotted/plus-tagged Gmail resolve to one account · OTP expiry · wrong code ×5 → lockout · resend cooldown · 6 requests in an hour → blocked · session survives a browser restart with remember-me · logout clears everything.

**Commerce:** add/update/remove cart lines · guest cart merges on login · coupon min-order, expiry, per-customer limit, and first-order-only all enforced · free-shipping threshold flips at exactly the boundary · **concurrent purchase of the last ball → one succeeds, one gets a clear message** · payment success, failure, and abandonment · webhook replay is idempotent · refund reflects in the customer's order view.

**Admin:** create a product with 10 shades · CSV import · stock edits write movement rows · every status transition + the invalid ones rejected · activity log captures each change.

**Email:** all 15 templates render in Gmail (web + Android + iOS) and Outlook · plain-text alternative present · unsubscribe works · `email_log` records every send.

**Legal:** every policy page reachable in ≤2 clicks from any page · footer shows name/address/phone/email · grievance officer published · MRP + net quantity + country of origin on every PDP.

**Accessibility:** full keyboard pass on home, shop, PDP, cart, checkout · screen-reader pass on checkout · focus visible everywhere · contrast audit clean.

**Security:** SQLi attempts on every input · XSS in review text, contact message, and address fields · CSRF token removed → request rejected · direct access to `/config/` and `/uploads/*.php` blocked · admin URLs unreachable when logged out.

---

## 18. What the Client Must Provide

1. **Brand:** final store name, logo (SVG + PNG), tagline.
2. **Legal:** registered business name, full address, phone, support email, grievance officer name + email, city/state for jurisdiction, GSTIN (when registered).
3. **Brevo:** account, verified sending domain (DKIM + SPF + DMARC records added at the DNS host), verified from-address, API key.
4. **Razorpay:** account with KYC complete, test keys now, live keys + webhook secret at launch.
5. **Product data:** for each yarn — name, fiber composition, weight category, ply, ball weight, length, hook/needle size, care, price, MRP, and every shade with its name, code, and a photo.
6. **Photography:** product shots on a consistent light background, at least 3 per product plus one shot of the yarn in hand, and 3–5 lifestyle/hero images. **This is the single biggest driver of trust and conversion — budget real effort here.**
7. **Content:** About story + founder photo, courier partners, WhatsApp business number, social handles.
8. **Hosting:** domain, SSL, cPanel access.

---

## 19. Open Items to Confirm Before Phase 4

- Exact free-shipping threshold and flat rate (plan assumes ₹999 / ₹69).
- Return window (plan assumes 7 days) and who pays return shipping on change-of-mind.
- Whether to enable COD later, and with what advance/pincode restrictions.
- City/state for legal jurisdiction in the Terms.
- Whether the physical shade card is a paid product or free with the first order.

---

*End of plan. Execute phases in order; each gate is a hard stop.*
