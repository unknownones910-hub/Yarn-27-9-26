<?php
/**
 * Review moderation.
 *
 * Approving or rejecting recalculates the product's rating from the approved
 * rows only — the number on the product page always matches what is visible.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-bootstrap.php';
$admin = admin_guard();

/** Recompute rating_avg / rating_count from approved reviews. */
function review_recount(int $productId): void
{
    $r = db_row('SELECT COUNT(*) n, COALESCE(AVG(rating),0) a FROM reviews
                 WHERE product_id = ? AND status = "approved"', [$productId]);
    db_exec('UPDATE products SET rating_count = ?, rating_avg = ? WHERE id = ?',
            [(int) ($r['n'] ?? 0), round((float) ($r['a'] ?? 0), 2), $productId]);
}

if (is_post()) {
    csrf_verify();
    $action = post_str('action', 20);
    $rid    = post_int('id');

    $review = $rid > 0 ? db_row('SELECT * FROM reviews WHERE id = ?', [$rid]) : null;

    if ($review) {
        $pid = (int) $review['product_id'];

        if ($action === 'approve' || $action === 'reject') {
            $to = $action === 'approve' ? 'approved' : 'rejected';
            db_exec('UPDATE reviews SET status = ? WHERE id = ?', [$to, $rid]);
            review_recount($pid);
            admin_log('review_' . $to, 'review', $rid);
            flash('Review ' . $to . '.', 'success');

        } elseif ($action === 'reply') {
            db_exec('UPDATE reviews SET admin_reply = ? WHERE id = ?',
                    [post_str('admin_reply', 600) ?: null, $rid]);
            admin_log('review_reply', 'review', $rid);
            flash('Reply saved.', 'success');

        } elseif ($action === 'delete' && admin_can('manager')) {
            db_exec('DELETE FROM reviews WHERE id = ?', [$rid]);
            review_recount($pid);
            admin_log('review_delete', 'review', $rid);
            flash('Review deleted.', 'success');
        }
    }
    redirect('admin/reviews.php?' . http_build_query($_GET));
}

$status = get_str('status', 20) ?: 'pending';
$where  = in_array($status, ['pending', 'approved', 'rejected'], true) ? 'r.status = ?' : '1=1';
$params = $where === '1=1' ? [] : [$status];

$total = (int) db_val("SELECT COUNT(*) FROM reviews r WHERE {$where}", $params);
$pg    = admin_paginate($total, 20);

$reviews = db_all(
    "SELECT r.*, p.name AS product_name, p.slug, c.email AS customer_email
     FROM reviews r
     JOIN products p ON p.id = r.product_id
     LEFT JOIN customers c ON c.id = r.customer_id
     WHERE {$where}
     ORDER BY r.created_at DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}",
    $params
);

$counts = [];
foreach (db_all('SELECT status, COUNT(*) n FROM reviews GROUP BY status') as $r) {
    $counts[$r['status']] = (int) $r['n'];
}

$pageTitle    = 'Reviews';
$adminSection = 'reviews';

require_once YN_ROOT . '/includes/admin-header.php';
?>

<div class="admin-head">
  <div class="min0">
    <h1>Reviews</h1>
    <p>Only verified buyers can leave one, so this queue is usually short.</p>
  </div>
</div>

<div class="chips mb4">
  <?php foreach (['pending' => 'Awaiting review', 'approved' => 'Published',
                  'rejected' => 'Rejected', 'all' => 'All'] as $k => $lbl): ?>
    <a class="chip <?= $status === $k ? 'is-on' : '' ?>"
       href="<?= e(url('admin/reviews.php?status=' . $k)) ?>">
      <?= e($lbl) ?><?php if (!empty($counts[$k])): ?>
        <span class="muted">· <?= (int) $counts[$k] ?></span><?php endif; ?></a>
  <?php endforeach; ?>
</div>

<?php if (!$reviews): ?>
  <div class="panel"><div class="admin-empty">
    <?= icon('star', 40) ?><p>Nothing here.</p></div></div>
<?php else: ?>
  <?php foreach ($reviews as $r): ?>
    <div class="panel">
      <div class="row row-between mb3">
        <div class="min0">
          <?= stars_html((float) $r['rating'], 0, false) ?>
          <span class="text-sm" style="margin-left:8px">
            <a href="<?= e(url('product/' . $r['slug'])) ?>" target="_blank" rel="noopener">
              <?= e((string) $r['product_name']) ?></a></span>
        </div>
        <span class="pill pill-<?= $r['status'] === 'approved' ? 'ok'
              : ($r['status'] === 'rejected' ? 'bad' : 'warn') ?>" style="flex:none">
          <?= e((string) $r['status']) ?></span>
      </div>

      <?php if (!empty($r['title'])): ?>
        <p class="mb1"><strong><?= e((string) $r['title']) ?></strong></p>
      <?php endif; ?>
      <p class="text-sm soft mb3"><?= nl2br(e((string) $r['body'])) ?></p>

      <p class="text-xs muted mb4">
        <?= e((string) ($r['author_name'] ?: 'Anonymous')) ?>
        <?= $r['customer_email'] ? '· ' . e((string) $r['customer_email']) : '' ?>
        · <?= e(fmt_datetime((string) $r['created_at'])) ?>
        <?php if ((int) $r['is_verified'] === 1): ?>
          · <span style="color:var(--success)">verified buyer</span><?php endif; ?>
      </p>

      <div class="row gap2 mb4">
        <?php if ($r['status'] !== 'approved'): ?>
          <form method="post" action="<?= e(url('admin/reviews.php?' . http_build_query($_GET))) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <input type="hidden" name="action" value="approve">
            <button class="btn btn-sm" type="submit"><?= icon('check', 14) ?> Publish</button>
          </form>
        <?php endif; ?>
        <?php if ($r['status'] !== 'rejected'): ?>
          <form method="post" action="<?= e(url('admin/reviews.php?' . http_build_query($_GET))) ?>">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <input type="hidden" name="action" value="reject">
            <button class="btn btn-sm btn-soft" type="submit">Reject</button>
          </form>
        <?php endif; ?>
        <?php if (admin_can('manager')): ?>
          <form method="post" action="<?= e(url('admin/reviews.php?' . http_build_query($_GET))) ?>"
                data-confirm="Delete this review permanently?">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
            <input type="hidden" name="action" value="delete">
            <button class="btn-link is-danger" type="submit">Delete</button>
          </form>
        <?php endif; ?>
      </div>

      <form method="post" action="<?= e(url('admin/reviews.php?' . http_build_query($_GET))) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
        <input type="hidden" name="action" value="reply">
        <div class="field mb0">
          <label class="label" for="reply<?= (int) $r['id'] ?>">Public reply</label>
          <textarea class="textarea" id="reply<?= (int) $r['id'] ?>" name="admin_reply" rows="2"
                    style="min-height:64px"
                    placeholder="Answering a criticism honestly builds more trust than deleting it."><?= e((string) $r['admin_reply']) ?></textarea>
          <button class="btn btn-sm btn-soft mt3" type="submit">Save reply</button>
        </div>
      </form>
    </div>
  <?php endforeach; ?>

  <?php if ($pg['pages'] > 1): ?>
    <?php require_once YN_ROOT . '/includes/components/pagination.php'; ?>
    <?php pagination($pg['page'], $pg['pages']); ?>
  <?php endif; ?>
<?php endif; ?>

<?php require_once YN_ROOT . '/includes/admin-footer.php'; ?>
