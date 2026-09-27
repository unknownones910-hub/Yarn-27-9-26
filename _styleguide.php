<?php
/**
 * Design-system reference.
 *
 * Every component in assets/css/style.css is rendered here exactly once, so a
 * single page proves the system works — and proves it at 320px. This is the
 * Phase 0 gate artifact.
 *
 * Open with ?debug=1 to run the overflow checker; it outlines any element that
 * extends past the viewport and logs scrollWidth vs clientWidth.
 *
 * Not linked from anywhere. Delete before go-live, or leave it — it is
 * noindexed and harmless.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

$pageTitle = 'Design system';
$noindex   = true;

$swatches = [
    'Cream'      => '--cream',   'Surface' => '--surface', 'Sand' => '--sand',
    'Sand deep'  => '--sand-deep','Ink'    => '--ink',     'Ink soft' => '--ink-soft',
    'Ink muted'  => '--ink-muted','Clay'   => '--clay',    'Clay dark' => '--clay-dark',
    'Clay tint'  => '--clay-tint','Sage'   => '--sage',    'Sage tint' => '--sage-tint',
    'Gold'       => '--gold',     'Success'=> '--success', 'Warning' => '--warning',
    'Danger'     => '--danger',   'Info'   => '--info',
];

$demoShades = [
    ['Terracotta Rose', '#B4552F'], ['Sage Morning', '#63795A'], ['Warm Ivory', '#F3E6D2'],
    ['Indigo Dusk', '#4A5D7E'],     ['Mustard Field', '#C08A24'], ['Rosewood', '#8A4A4A'],
    ['Clay Pot', '#C4663C'],        ['Charcoal', '#3A3632'],
];

require_once __DIR__ . '/includes/header.php';
?>

<div class="container section">

  <p class="eyebrow">Internal</p>
  <h1>Design system</h1>
  <p class="lede">
    Every component, rendered once. Resize to 320px and open with
    <code>?debug=1</code> — the console must report <strong>offenders=0</strong>.
  </p>

  <div class="alert alert-info mt5">
    <?= icon('info', 18) ?>
    <div>
      <strong>Overflow gate.</strong> <code>scrollWidth</code> must equal <code>clientWidth</code> at
      320 / 360 / 414 / 768 / 1024 / 1440px. Any red outline is a build-breaking bug, not a style nit.
    </div>
  </div>

  <!-- ============================================================ colour -->
  <h2 class="mt7">Colour</h2>
  <div class="grid" style="grid-template-columns:repeat(auto-fill,minmax(min(130px,100%),1fr));gap:var(--s3)">
    <?php foreach ($swatches as $label => $var): ?>
      <div class="min0">
        <div style="height:56px;border-radius:var(--r-md);background:var(<?= e($var) ?>);border:1px solid var(--sand-deep)"></div>
        <p class="text-xs mt2 mb0"><strong><?= e($label) ?></strong><br><span class="muted"><?= e($var) ?></span></p>
      </div>
    <?php endforeach; ?>
  </div>

  <!-- ======================================================== typography -->
  <h2 class="mt7">Typography</h2>
  <div class="card">
    <p class="eyebrow">Eyebrow · uppercase, tracked</p>
    <p style="font-size:var(--fs-display);font-family:var(--font-serif);font-weight:600;line-height:1.1;margin:0 0 var(--s4)">Display</p>
    <h1>Heading 1 — yarn that behaves</h1>
    <h2>Heading 2 — shop by fibre</h2>
    <h3>Heading 3 — care instructions</h3>
    <h4>Heading 4 — a sans sub-head</h4>
    <p class="lede">Lede paragraph. Slightly larger, softer ink, used under a page title.</p>
    <p>Body copy at 16px with a 1.65 line height and a 68ch measure. This is the default reading
       size across the whole site; nothing smaller is used for body text.</p>
    <p class="text-sm soft">Small text — meta, helper copy, secondary detail.</p>
    <p class="text-xs muted mb0">Extra small — labels and captions only, never a paragraph.</p>
  </div>

  <!-- =========================================================== buttons -->
  <h2 class="mt7">Buttons</h2>
  <div class="card">
    <div class="row">
      <button class="btn">Add to cart</button>
      <button class="btn btn-ghost">Buy now</button>
      <button class="btn btn-soft">Save for later</button>
      <button class="btn btn-sage">Apply coupon</button>
      <button class="btn btn-danger">Cancel order</button>
      <button class="btn btn-quiet">Quiet action</button>
      <button class="btn" disabled>Disabled</button>
      <button class="btn is-loading"><span class="spinner"></span> Placing order</button>
    </div>
    <div class="row mt4">
      <button class="btn btn-sm">Small</button>
      <button class="btn">Default</button>
      <button class="btn btn-lg">Large</button>
    </div>
    <div class="row mt4">
      <span class="icon-btn"><?= icon('heart', 21) ?></span>
      <span class="icon-btn"><?= icon('search', 21) ?></span>
      <span class="icon-btn"><?= icon('cart', 21) ?></span>
      <span class="icon-btn"><?= icon('user', 21) ?></span>
    </div>
    <button class="btn btn-block mt4">Full width — used on mobile and in drawers</button>
  </div>

  <!-- ============================================================= forms -->
  <h2 class="mt7">Forms</h2>
  <div class="grid grid-2">
    <div class="card">
      <div class="field">
        <label class="label" for="sg-email">Gmail address <span class="req">*</span></label>
        <input class="input" type="email" id="sg-email" placeholder="you@gmail.com">
        <span class="help">We only accept Gmail addresses. No password needed.</span>
      </div>

      <div class="field">
        <label class="label" for="sg-phone">Phone <span class="req">*</span></label>
        <div class="input-group">
          <span class="addon">+91</span>
          <input class="input" type="tel" id="sg-phone" inputmode="numeric" placeholder="98765 43210">
        </div>
      </div>

      <div class="field">
        <label class="label" for="sg-state">State</label>
        <select class="select" id="sg-state">
          <option>Select your state</option>
          <?php foreach (array_slice(india_states(), 0, 6) as $s): ?>
            <option><?= e($s) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="field has-error">
        <label class="label" for="sg-pin">Pincode <span class="req">*</span></label>
        <input class="input" type="text" id="sg-pin" value="12345">
        <span class="error-text"><?= icon('alert', 14) ?> Enter a valid 6-digit pincode.</span>
      </div>

      <div class="field">
        <label class="label" for="sg-note">Order note <span class="opt">(optional)</span></label>
        <textarea class="textarea" id="sg-note" placeholder="Anything we should know?"></textarea>
      </div>
    </div>

    <div class="card">
      <p class="label">Checkbox &amp; radio</p>
      <label class="check"><input type="checkbox" checked> Billing address same as delivery</label>
      <label class="check"><input type="checkbox"> Add gift wrap (<?= e(money(setting_money('gift_wrap_fee', 49))) ?>)</label>
      <label class="check"><input type="radio" name="sg-r" checked> Home</label>
      <label class="check"><input type="radio" name="sg-r"> Work</label>

      <p class="label mt5">Selectable card</p>
      <div class="stack-sm">
        <label class="choice">
          <input type="radio" name="sg-addr" checked>
          <strong>Priya Sharma</strong>
          <p class="text-sm soft mb0">12 Rose Villa, Lane 4, Koregaon Park, Pune 411001</p>
        </label>
        <label class="choice">
          <input type="radio" name="sg-addr">
          <strong>Office</strong>
          <p class="text-sm soft mb0">3rd Floor, Tower B, Magarpatta City, Pune 411013</p>
        </label>
      </div>

      <p class="label mt5">Quantity</p>
      <div class="qty">
        <button type="button" data-step="down" aria-label="Decrease">&minus;</button>
        <input type="number" value="2" min="1" max="20" aria-label="Quantity">
        <button type="button" data-step="up" aria-label="Increase">+</button>
      </div>

      <p class="label mt5">OTP</p>
      <div class="otp" data-otp>
        <?php for ($i = 0; $i < 6; $i++): ?><input type="text"><?php endfor; ?>
      </div>
      <p class="help center mt3">Expires in <span data-countdown="600">10:00</span></p>
    </div>
  </div>

  <!-- ==================================================== product cards -->
  <h2 class="mt7">Product card</h2>
  <div class="grid-products">
    <?php
    $demo = [
      ['Milk Cotton DK', '100% Cotton · DK · 50g', 210, 260, 4.6, 128, 'new',  false],
      ['Velvet Chenille', '100% Polyester · Bulky · 100g', 320, 320, 4.8, 64, 'best', false],
      ['Merino Soft', '80% Wool 20% Nylon · Fine · 50g', 480, 599, 4.4, 39, 'sale', false],
      ['Bamboo Breeze', '70% Bamboo 30% Cotton · Sport · 50g', 290, 340, 4.2, 12, 'low', false],
      ['Jute Craft Cord', '100% Jute · Jumbo · 200g', 199, 199, 4.0, 8, '', true],
    ];
    foreach ($demo as [$name, $meta, $price, $mrp, $rating, $count, $badge, $out]): ?>
      <article class="product-card">
        <div class="product-card__media">
          <img src="<?= e(asset('img/placeholder-yarn.svg')) ?>" alt="<?= e($name) ?>" width="400" height="500" loading="lazy">
          <div class="badges">
            <?php if ($badge === 'new'):  ?><span class="badge badge-new">New</span><?php endif; ?>
            <?php if ($badge === 'best'): ?><span class="badge badge-best">Bestseller</span><?php endif; ?>
            <?php if ($badge === 'sale'): ?><span class="badge badge-sale"><?= discount_pct((float)$mrp, (float)$price) ?>% off</span><?php endif; ?>
            <?php if ($badge === 'low'):  ?><span class="badge badge-low">Only 3 left</span><?php endif; ?>
            <?php if ($out): ?><span class="badge badge-out">Out of stock</span><?php endif; ?>
          </div>
          <button class="wish-btn" type="button" aria-label="Add to wishlist"><?= icon('heart', 18) ?></button>
        </div>
        <div class="product-card__body">
          <h3 class="product-card__name"><a href="#"><?= e($name) ?></a></h3>
          <p class="product-card__meta"><?= e($meta) ?></p>
          <div class="swatch-dots mb3">
            <?php foreach (array_slice($demoShades, 0, 5) as [$sn, $hex]): ?>
              <span class="swatch-dot" style="background:<?= e($hex) ?>" title="<?= e($sn) ?>"></span>
            <?php endforeach; ?>
            <span class="swatch-more">+3</span>
          </div>
          <div class="price">
            <span class="price__now"><?= e(money((float) $price)) ?></span>
            <?php if ($mrp > $price): ?>
              <span class="price__mrp"><?= e(money((float) $mrp)) ?></span>
              <span class="price__off"><?= discount_pct((float) $mrp, (float) $price) ?>% off</span>
            <?php endif; ?>
          </div>
          <div class="mt2"><?= stars_html($rating, $count) ?></div>
        </div>
      </article>
    <?php endforeach; ?>
  </div>

  <!-- ========================================================== swatches -->
  <h2 class="mt7">Shade swatches</h2>
  <div class="card">
    <p class="label">Select a shade — selected, available, out of stock</p>
    <div class="swatches">
      <?php foreach ($demoShades as $i => [$sn, $hex]): ?>
        <button type="button" class="swatch<?= $i === 5 ? ' is-out' : '' ?>"
                style="background:<?= e($hex) ?>"
                aria-pressed="<?= $i === 0 ? 'true' : 'false' ?>"
                <?= $i === 5 ? 'disabled aria-label="' . e($sn) . ' — out of stock"' : 'aria-label="' . e($sn) . '"' ?>></button>
      <?php endforeach; ?>
    </div>
    <p class="text-sm soft mt4 mb0">
      Selected: <strong>Terracotta Rose</strong> · Dye lot 24B —
      order all balls together so the shade matches across your project.
    </p>
  </div>

  <!-- ============================================================ status -->
  <h2 class="mt7">Status &amp; stock</h2>
  <div class="card">
    <div class="row">
      <span class="stock stock-in">In stock</span>
      <span class="stock stock-low">Only 3 balls left</span>
      <span class="stock stock-out">Out of stock</span>
    </div>
    <div class="row mt4">
      <?php foreach (['pending_payment','paid','processing','shipped','delivered','cancelled','failed'] as $st):
        [$label, $mod] = order_status_meta($st); ?>
        <span class="pill pill-<?= e($mod) ?>"><?= e($label) ?></span>
      <?php endforeach; ?>
    </div>
    <div class="row mt4">
      <span class="badge badge-new">New</span>
      <span class="badge badge-sale">30% off</span>
      <span class="badge badge-best">Bestseller</span>
      <span class="badge badge-low">Only 2 left</span>
      <span class="badge badge-out">Out of stock</span>
    </div>
  </div>

  <!-- ============================================================= chips -->
  <h2 class="mt7">Filter chips</h2>
  <div class="chips">
    <span class="chip is-on">Cotton <?= icon('close', 13) ?></span>
    <span class="chip is-on">DK / 8-ply <?= icon('close', 13) ?></span>
    <span class="chip">Acrylic</span>
    <span class="chip">Wool</span>
    <span class="chip">Under ₹250</span>
    <span class="chip">In stock only</span>
    <span class="chip">4★ &amp; above</span>
  </div>

  <!-- ==================================================== shipping bar -->
  <h2 class="mt7">Free-shipping progress</h2>
  <div class="grid grid-2">
    <div class="ship-bar">
      <p class="text-sm mb0"><strong>₹212 away</strong> from free shipping</p>
      <div class="ship-bar__track"><div class="ship-bar__fill" style="width:78%"></div></div>
    </div>
    <div class="ship-bar is-done">
      <p class="text-sm mb0 row gap2" style="gap:6px"><?= icon('check-circle', 16) ?> <strong>Free shipping unlocked</strong></p>
      <div class="ship-bar__track"><div class="ship-bar__fill" style="width:100%"></div></div>
    </div>
  </div>

  <!-- ============================================================ alerts -->
  <h2 class="mt7">Alerts</h2>
  <div class="stack">
    <div class="alert alert-success"><?= icon('check-circle', 18) ?><div>Order placed. A confirmation is on its way to your inbox.</div></div>
    <div class="alert alert-info"><?= icon('info', 18) ?><div>Shades vary slightly between dye lots. Order all balls for one project together.</div></div>
    <div class="alert alert-warn"><?= icon('alert', 18) ?><div>Only 2 balls left in Terracotta Rose. Quantity reduced to match.</div></div>
    <div class="alert alert-error"><?= icon('alert', 18) ?><div>That code has expired. Request a new one to continue.</div></div>
  </div>

  <!-- ========================================================= accordion -->
  <h2 class="mt7">Accordion</h2>
  <div class="accordion">
    <details open>
      <summary>Yarn details</summary>
      <div class="acc-body">
        <dl class="dl">
          <dt>Fibre</dt><dd>100% mercerised cotton</dd>
          <dt>Weight</dt><dd>DK · CYC 3 · 8-ply</dd>
          <dt>Ball</dt><dd>50 g · 125 m</dd>
          <dt>Hook</dt><dd>3.5 – 4.0 mm</dd>
          <dt>Needle</dt><dd>4.0 mm</dd>
          <dt>Origin</dt><dd>India</dd>
        </dl>
      </div>
    </details>
    <details>
      <summary>Care instructions</summary>
      <div class="acc-body">Machine wash cold on a gentle cycle. Dry flat in shade. Do not bleach. Warm iron.</div>
    </details>
    <details>
      <summary>Shipping &amp; returns</summary>
      <div class="acc-body">Dispatched in 1–2 business days. Free delivery above ₹999. Unopened yarn can be returned within 7 days.</div>
    </details>
  </div>

  <!-- ========================================================== timeline -->
  <h2 class="mt7">Order timeline</h2>
  <div class="card">
    <ul class="timeline">
      <li class="done"><strong>Order placed</strong><br><span class="text-sm muted">2 Aug 2026, 4:12 PM</span></li>
      <li class="done"><strong>Payment received</strong><br><span class="text-sm muted">2 Aug 2026, 4:13 PM</span></li>
      <li class="current"><strong>Packed</strong><br><span class="text-sm muted">3 Aug 2026, 11:20 AM</span></li>
      <li><strong>Shipped</strong><br><span class="text-sm muted">Expected 4 Aug</span></li>
      <li><strong>Delivered</strong><br><span class="text-sm muted">Expected 7–9 Aug</span></li>
    </ul>
  </div>

  <!-- ============================================================= table -->
  <h2 class="mt7">Data table</h2>
  <p class="text-sm soft">Wrapped in <code>.scroll-x</code> — the table scrolls, the page never does.</p>
  <div class="scroll-x card" style="padding:0">
    <table class="table">
      <thead>
        <tr><th>Order</th><th>Date</th><th>Customer</th><th>Items</th><th class="num">Total</th><th>Status</th></tr>
      </thead>
      <tbody>
        <tr><td><a href="#">YN-2608-0142</a></td><td>2 Aug 2026</td><td>priya@gmail.com</td><td>4</td><td class="num">₹1,247</td><td><span class="pill pill-info">Shipped</span></td></tr>
        <tr><td><a href="#">YN-2608-0141</a></td><td>2 Aug 2026</td><td>arun@gmail.com</td><td>1</td><td class="num">₹320</td><td><span class="pill pill-ok">Delivered</span></td></tr>
        <tr><td><a href="#">YN-2608-0140</a></td><td>1 Aug 2026</td><td>meera@gmail.com</td><td>9</td><td class="num">₹2,890</td><td><span class="pill pill-warn">Payment pending</span></td></tr>
      </tbody>
    </table>
  </div>

  <!-- ======================================================== pagination -->
  <h2 class="mt7">Pagination &amp; tabs</h2>
  <div class="tabs mb5">
    <a href="#" aria-current="page">All orders</a>
    <a href="#">Active</a>
    <a href="#">Delivered</a>
    <a href="#">Cancelled</a>
    <a href="#">Returns</a>
  </div>
  <nav class="pagination" aria-label="Pagination">
    <a href="#"><?= icon('arrow-left', 15) ?></a>
    <a href="#">1</a>
    <span aria-current="page">2</span>
    <a href="#">3</a>
    <span class="gap">…</span>
    <a href="#">20</a>
    <a href="#"><?= icon('arrow-right', 15) ?></a>
  </nav>

  <!-- ========================================================= skeletons -->
  <h2 class="mt7">Loading skeletons</h2>
  <div class="grid-products">
    <?php for ($i = 0; $i < 4; $i++): ?>
      <div>
        <div class="skel skel-media"></div>
        <div class="skel skel-line w80"></div>
        <div class="skel skel-line w60"></div>
        <div class="skel skel-line w40"></div>
      </div>
    <?php endfor; ?>
  </div>

  <!-- ======================================================= empty state -->
  <h2 class="mt7">Empty state</h2>
  <div class="card">
    <div class="empty" style="padding-block:var(--s6)">
      <div class="empty__art"><?= icon('cart', 40) ?></div>
      <h2>Your cart is feeling a bit unravelled</h2>
      <p style="max-width:40ch">Nothing in here yet. Have a look at what people are buying this week.</p>
      <a class="btn mt4" href="<?= e(url('shop.php')) ?>">Shop bestsellers</a>
    </div>
  </div>

  <!-- ================================================ toasts / overlays -->
  <h2 class="mt7">Overlays</h2>
  <div class="card">
    <div class="row">
      <button class="btn btn-soft" type="button" onclick="ynToast('Added to your cart','success')">Success toast</button>
      <button class="btn btn-soft" type="button" onclick="ynToast('That shade just sold out','error')">Error toast</button>
      <button class="btn btn-soft" type="button" data-modal-open="#sg-modal">Open modal</button>
      <button class="btn btn-soft" type="button" data-drawer-open="#sg-drawer">Open drawer</button>
    </div>
  </div>

  <div class="modal" id="sg-modal" role="dialog" aria-modal="true" aria-labelledby="sg-modal-t" aria-hidden="true">
    <div class="modal__panel">
      <div class="modal__head">
        <h2 id="sg-modal-t">Check delivery</h2>
        <button class="icon-btn" type="button" data-modal-close aria-label="Close"><?= icon('close', 20) ?></button>
      </div>
      <div class="modal__body">
        <div class="field mb0">
          <label class="label" for="sg-modal-pin">Delivery pincode</label>
          <input class="input" id="sg-modal-pin" inputmode="numeric" placeholder="411001" data-autofocus>
          <span class="help">We&rsquo;ll show the expected delivery date for your area.</span>
        </div>
      </div>
      <div class="modal__foot">
        <button class="btn btn-soft" type="button" data-modal-close>Cancel</button>
        <button class="btn" type="button">Check</button>
      </div>
    </div>
  </div>

  <aside class="drawer drawer-right" id="sg-drawer" aria-hidden="true" aria-label="Cart">
    <div class="drawer-head">
      <h2>Your cart (2)</h2>
      <button class="icon-btn" type="button" data-drawer-close aria-label="Close"><?= icon('close', 20) ?></button>
    </div>
    <div class="drawer-body">
      <p class="text-sm soft">Cart drawer contents arrive in Phase 3.</p>
    </div>
    <div class="drawer-foot">
      <div class="row row-between mb3">
        <span class="text-sm soft">Subtotal</span>
        <strong>₹787</strong>
      </div>
      <a class="btn btn-block" href="#">Proceed to checkout</a>
    </div>
  </aside>

  <!-- ============================================================ icons -->
  <h2 class="mt7">Icons</h2>
  <div class="card">
    <div class="row">
      <?php foreach (['yarn','hook','needles','cart','heart','user','search','truck','shield','refresh',
                      'lock','mail','phone','pin','clock','tag','gift','package','star','check-circle',
                      'info','alert','chat','whatsapp','filter','sort','download','trash','edit','settings',
                      'sparkle','leaf'] as $ic): ?>
        <span class="icon-btn" title="<?= e($ic) ?>"><?= icon($ic, 21) ?></span>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="alert alert-success mt7">
    <?= icon('check-circle', 18) ?>
    <div>
      <strong>Gate check.</strong> Reload at 320px with <code>?debug=1</code>.
      Zero offenders means Phase 0 passes and Phase 1 can start.
    </div>
  </div>

</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
