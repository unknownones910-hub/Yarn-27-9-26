<?php
/**
 * Return requests across all orders.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-bootstrap.php';
$admin = admin_guard();

if (is_post()) {
    csrf_verify();
    $rid    = post_int('id');
    $decide = post_str('decision', 20);

    if (in_array($decide, ['approved', 'rejected', 'picked', 'received', 'refunded'], true)) {
        db_exec('UPDATE return_requests SET status = ?, admin_note = ? WHERE id = ?',
                [$decide, post_str('admin_note', 500) ?: null, $rid]);
        admin_log('return_' . $decide, 'return', $rid);
        flash('Return marked as ' . $decide . '.', 'success');
    }
    redirect('admin/returns.php?' . http_build_query($_GET));
}

$status = get_str('status', 20) ?: 'requested';
$where  = $status === 'all' ? '1=1' : 'r.status = ?';
$params = $status === 'all' ? [] : [$status];

$rows = db_all(
    "SELECT r.*, o.order_no, o.email, o.grand_total, o.delivered_at, o.id AS oid
     FROM return_requests r JOIN orders o ON o.id = r.order_id
     WHERE {$where} ORDER BY r.created_at DESC LIMIT 100",
    $params
);

$counts = [];
foreach (db_all('SELECT status, COUNT(*) n FROM return_requests GROUP BY status') as $r) {
    $counts[$r['status']] = (int) $r['n'];
}

$pageTitle    = 'Returns';
$adminSection = 'returns';

require_once YN_ROOT . '/includes/admin-header.php';
?>

<div class="admin-head">
  <div class="min0"><h1>Returns</h1>
    <p>Unopened yarn within <?= setting_int('return_window_days', 7) ?> days, per the return policy.</p></div>
</div>

<div class="chips mb4">
  <?php foreach (['requested' => 'New', 'approved' => 'Approved', 'picked' => 'Picked up',
                  'received' => 'Received', 'refunded' => 'Refunded', 'rejected' => 'Rejected',
                  'all' => 'All'] as $k => $lbl): ?>
    <a class="chip <?= $status === $k ? 'is-on' : '' ?>"
       href="<?= e(url('admin/returns.php?status=' . $k)) ?>">
      <?= e($lbl) ?><?php if (!empty($counts[$k])): ?>
        <span class="muted">· <?= (int) $counts[$k] ?></span><?php endif; ?></a>
  <?php endforeach; ?>
</div>

<?php if (!$rows): ?>
  <div class="panel"><div class="admin-empty">
    <?= icon('refresh', 40) ?><p>Nothing here — that is good news.</p></div></div>
<?php else: ?>
  <?php foreach ($rows as $r): ?>
    <div class="panel">
      <div class="row row-between mb3">
        <div class="min0">
          <p class="mb1"><strong>
            <a href="<?= e(url('admin/order-detail.php?id=' . (int) $r['oid'])) ?>">
              <?= e((string) $r['order_no']) ?></a></strong>
            <span class="muted">· <?= e(money((float) $r['grand_total'])) ?></span></p>
          <p class="text-xs muted mb0">
            <?= e((string) $r['email']) ?> ·
            delivered <?= e(fmt_date((string) $r['delivered_at'])) ?> ·
            raised <?= e(fmt_date((string) $r['created_at'])) ?>
          </p>
        </div>
        <span class="pill pill-<?= $r['status'] === 'requested' ? 'warn'
              : ($r['status'] === 'rejected' ? 'bad' : 'muted') ?>" style="flex:none">
          <?= e((string) $r['status']) ?></span>
      </div>

      <p class="text-sm mb2"><strong>Reason:</strong>
        <?= e(ucfirst(str_replace('_', ' ', (string) $r['reason']))) ?></p>

      <?php /* The video is the evidence the whole policy rests on — put it
               where it cannot be missed, above the customer's own words. */ ?>
      <?php if (!empty($r['video_url'])): ?>
        <p class="text-sm mb2"><strong>Unboxing video:</strong>
          <a href="<?= eurl((string) $r['video_url']) ?>" target="_blank" rel="noopener noreferrer">
            Open <?= icon('external', 13) ?></a></p>
      <?php else: ?>
        <p class="text-sm mb2"><span class="pill pill-warn">No video</span>
          <span class="muted">raised before the video rule — decide manually</span></p>
      <?php endif; ?>

      <?php if (!empty($r['comments'])): ?>
        <p class="text-sm soft mb3"><?= nl2br(e((string) $r['comments'])) ?></p>
      <?php endif; ?>

      <?php
      $itemIds = json_decode((string) $r['items_json'], true);
      if (is_array($itemIds) && $itemIds):
          $items = db_all('SELECT product_name, shade_name, qty FROM order_items
                           WHERE id IN (' . db_in($itemIds) . ')', $itemIds);
      ?>
        <ul class="text-sm stack-sm mb3">
          <?php foreach ($items as $it): ?>
            <li>· <?= e((string) $it['product_name']) ?>
              <span class="muted"><?= e((string) $it['shade_name']) ?> × <?= (int) $it['qty'] ?></span></li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>

      <form method="post" action="<?= e(url('admin/returns.php?' . http_build_query($_GET))) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int) $r['id'] ?>">
        <div class="status-row">
          <select class="select" name="decision" style="width:auto">
            <option value="approved">Approve</option>
            <option value="rejected">Reject</option>
            <option value="picked">Picked up</option>
            <option value="received">Received back</option>
            <option value="refunded">Refunded</option>
          </select>
          <input class="input" name="admin_note" placeholder="Note for the customer"
                 style="flex:1;min-width:160px" value="<?= e((string) $r['admin_note']) ?>">
          <button class="btn btn-sm" type="submit">Save</button>
          <a class="btn btn-sm btn-soft" href="<?= e(url('admin/order-detail.php?id=' . (int) $r['oid'])) ?>">
            Open order</a>
        </div>
      </form>
      <p class="text-xs muted mt3 mb0">
        Refunding money happens on the order page — this only tracks the physical return.
      </p>
    </div>
  <?php endforeach; ?>
<?php endif; ?>

<?php require_once YN_ROOT . '/includes/admin-footer.php'; ?>
