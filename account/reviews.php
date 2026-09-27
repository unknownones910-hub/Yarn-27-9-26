<?php
/**
 * Reviews the customer has written, plus prompts for what they can review.
 *
 * Only people who actually bought and received a product can review it. That
 * is why the product pages stay empty until real orders land — we do not
 * write our own.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/account-nav.php';

$customer = require_login();
$cid      = (int) $customer['id'];

$errors = [];

if (is_post()) {
    csrf_verify();

    $productId = post_int('product_id');
    $orderId   = post_int('order_id');
    $rating    = post_int('rating');
    $title     = post_str('title', 140);
    $body      = post_str('body', 2000);

    // The customer must genuinely have this product on that delivered order.
    $bought = db_row(
        'SELECT oi.variant_id FROM order_items oi
         JOIN orders o ON o.id = oi.order_id
         WHERE o.customer_id = ? AND o.id = ? AND oi.product_id = ? AND o.status = "delivered"
         LIMIT 1',
        [$cid, $orderId, $productId]
    );

    if (!$bought) {
        $errors['general'] = 'You can only review something you have received.';
    } elseif ($rating < 1 || $rating > 5) {
        $errors['rating'] = 'Please choose a star rating.';
    } elseif (mb_strlen($body) < 10) {
        $errors['body'] = 'Please write at least a sentence — it helps the next person.';
    } elseif (db_row('SELECT id FROM reviews WHERE customer_id = ? AND product_id = ? AND order_id = ? LIMIT 1',
                     [$cid, $productId, $orderId])) {
        $errors['general'] = 'You have already reviewed this item.';
    } elseif (!rate_hit('review:' . $cid, 10, 3600)) {
        $errors['general'] = 'That is a lot of reviews at once. Please try again later.';
    } else {
        db_insert(
            'INSERT INTO reviews
                (product_id, variant_id, customer_id, order_id, author_name, rating, title, body,
                 is_verified, status)
             VALUES (?,?,?,?,?,?,?,?,1,"pending")',
            [
                $productId,
                (int) $bought['variant_id'] ?: null,
                $cid,
                $orderId,
                $customer['full_name'] ?: null,
                $rating,
                $title !== '' ? $title : null,
                $body,
            ]
        );

        flash('Thank you — your review is with us and appears once it has been checked.', 'success');
        redirect('account/reviews.php');
    }
}

$mine = db_all(
    'SELECT r.*, p.name AS product_name, p.slug
     FROM reviews r JOIN products p ON p.id = r.product_id
     WHERE r.customer_id = ? ORDER BY r.created_at DESC',
    [$cid]
);

$toReview = account_reviewable_items($cid, 20);

// Deep link from the overview: /account/reviews.php?product=12&order=3
$writeProduct = get_int('product');
$writeOrder   = get_int('order');
$writeFor     = null;
foreach ($toReview as $t) {
    if ((int) $t['product_id'] === $writeProduct && (int) $t['order_id'] === $writeOrder) {
        $writeFor = $t;
        break;
    }
}

$pageTitle = 'My reviews';
$noindex   = true;

require_once YN_ROOT . '/includes/header.php';
?>

<div class="container section-sm">
  <h1 class="mb5">My reviews</h1>

  <div class="account-layout">
    <?php account_nav('reviews'); ?>

    <div class="min0">

      <?php if (!empty($errors['general'])): ?>
        <div class="alert alert-error mb4"><?= icon('alert', 18) ?><div><?= e($errors['general']) ?></div></div>
      <?php endif; ?>

      <?php if ($writeFor): ?>
        <div class="card mb5">
          <h2 style="font-family:var(--font-sans);font-size:1.0625rem">
            Review <?= e($writeFor['product_name']) ?></h2>
          <p class="text-sm muted"><?= e((string) $writeFor['shade_name']) ?>
            · from order <?= e((string) $writeFor['order_no']) ?></p>

          <form method="post" action="<?= e(url('account/reviews.php')) ?>" class="mt4">
            <?= csrf_field() ?>
            <input type="hidden" name="product_id" value="<?= (int) $writeFor['product_id'] ?>">
            <input type="hidden" name="order_id" value="<?= (int) $writeFor['order_id'] ?>">

            <!-- A radio group needs a fieldset, not a stray <span class="label">:
                 that is the only markup a screen reader reads as "these five
                 controls are one question". -->
            <fieldset class="field <?= isset($errors['rating']) ? 'has-error' : '' ?>"
                      <?= field_invalid($errors, 'rating') ?>>
              <legend class="label">Your rating <span class="req">*</span></legend>
              <div class="star-input">
                <?php for ($s = 5; $s >= 1; $s--): ?>
                  <input type="radio" name="rating" id="star<?= $s ?>" value="<?= $s ?>" required>
                  <label for="star<?= $s ?>">
                    <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                      <path d="M12 3.5l2.7 5.5 6 .9-4.3 4.2 1 6-5.4-2.8-5.4 2.8 1-6L3.3 9.9l6-.9z"/>
                    </svg>
                    <span class="sr-only"><?= $s ?> star<?= $s === 1 ? '' : 's' ?></span>
                  </label>
                <?php endfor; ?>
              </div>
              <?= field_error($errors, 'rating') ?>
            </fieldset>

            <div class="field">
              <label class="label" for="title">Headline <span class="opt">(optional)</span></label>
              <input class="input" id="title" name="title" maxlength="140"
                     placeholder="Softer than I expected">
            </div>

            <div class="field <?= isset($errors['body']) ? 'has-error' : '' ?>">
              <label class="label" for="body">Your review <span class="req">*</span></label>
              <textarea class="textarea" id="body"<?= field_invalid($errors, 'body') ?> name="body" required rows="4"
                        placeholder="How did it work up? Did the shade match? What did you make?"></textarea>
              <span class="help">Honest is more useful than kind — including what you did not like.</span>
              <?= field_error($errors, 'body') ?>
            </div>

            <div class="row">
              <button class="btn" type="submit">Post review</button>
              <a class="btn btn-soft" href="<?= e(url('account/reviews.php')) ?>">Cancel</a>
            </div>
          </form>
        </div>
      <?php endif; ?>

      <?php if ($toReview && !$writeFor): ?>
        <div class="card mb5">
          <h2 style="font-family:var(--font-sans);font-size:1rem">Waiting for your review</h2>
          <div class="mt3">
            <?php foreach ($toReview as $t): ?>
              <div class="review-line">
                <img src="<?= e($t['image'] ? upload_url($t['image']) : yarn_ball_uri((string) $t['color_hex'])) ?>"
                     alt="" width="44" height="55" loading="lazy">
                <div class="min0">
                  <p class="text-sm mb0 truncate"><?= e($t['product_name']) ?></p>
                  <p class="text-xs muted mb0"><?= e((string) $t['shade_name']) ?>
                    · <?= e((string) $t['order_no']) ?></p>
                </div>
                <a class="btn btn-sm btn-soft"
                   href="<?= e(url('account/reviews.php?product=' . (int) $t['product_id']
                                   . '&order=' . (int) $t['order_id'])) ?>">Write</a>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endif; ?>

      <?php if ($mine): ?>
        <h2 style="font-family:var(--font-sans);font-size:1rem" class="mb3">
          Reviews you have written</h2>
        <?php foreach ($mine as $r): ?>
          <div class="card mb4">
            <div class="row row-between mb2">
              <?= stars_html((float) $r['rating'], 0, false) ?>
              <?php if ($r['status'] === 'pending'): ?>
                <span class="pill pill-warn" style="flex:none">Awaiting moderation</span>
              <?php elseif ($r['status'] === 'rejected'): ?>
                <span class="pill pill-bad" style="flex:none">Not published</span>
              <?php else: ?>
                <span class="pill pill-ok" style="flex:none">Published</span>
              <?php endif; ?>
            </div>
            <p class="text-sm mb1">
              <a href="<?= e(url('product/' . $r['slug'])) ?>"><strong><?= e($r['product_name']) ?></strong></a>
            </p>
            <?php if (!empty($r['title'])): ?>
              <p class="text-sm mb1"><strong><?= e((string) $r['title']) ?></strong></p>
            <?php endif; ?>
            <p class="text-sm soft mb2"><?= nl2br(e((string) $r['body'])) ?></p>
            <p class="text-xs muted mb0"><?= e(fmt_date((string) $r['created_at'])) ?></p>

            <?php if (!empty($r['admin_reply'])): ?>
              <div class="alert mt3" style="background:var(--sand)">
                <?= icon('chat', 16) ?>
                <div class="text-sm"><strong>Our reply:</strong> <?= e((string) $r['admin_reply']) ?></div>
              </div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>

      <?php elseif (!$toReview): ?>
        <div class="card center" style="padding-block:var(--s7)">
          <div class="empty__art" style="margin-bottom:var(--s4)"><?= icon('star', 36) ?></div>
          <h2 style="font-family:var(--font-sans);font-size:1.0625rem">No reviews yet</h2>
          <p class="text-sm soft" style="margin-inline:auto;max-width:40ch">
            Once an order is delivered you will be able to review what you bought.</p>
          <a class="btn mt4" href="<?= e(url('shop.php')) ?>">Browse yarn</a>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require_once YN_ROOT . '/includes/footer.php'; ?>
