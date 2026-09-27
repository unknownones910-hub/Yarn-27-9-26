<?php
/**
 * Contact-form and wholesale enquiries.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-bootstrap.php';
$admin = admin_guard();

if (is_post()) {
    csrf_verify();
    $mid = post_int('id');
    $act = post_str('action', 20);

    if (in_array($act, ['read', 'replied', 'closed', 'new'], true)) {
        db_exec('UPDATE contact_messages SET status = ? WHERE id = ?', [$act, $mid]);
        admin_log('message_' . $act, 'message', $mid);
    } elseif ($act === 'delete' && admin_can('manager')) {
        db_exec('DELETE FROM contact_messages WHERE id = ?', [$mid]);
        admin_log('message_delete', 'message', $mid);
        flash('Message deleted.', 'success');
    }
    redirect('admin/messages.php?' . http_build_query($_GET));
}

$kind   = get_str('kind', 20);
$status = get_str('status', 20);

$where  = ['1=1'];
$params = [];
if (in_array($kind, ['contact', 'wholesale'], true))              { $where[] = 'kind = ?';   $params[] = $kind; }
if (in_array($status, ['new', 'read', 'replied', 'closed'], true)) { $where[] = 'status = ?'; $params[] = $status; }

$whereSql = implode(' AND ', $where);
$total    = (int) db_val("SELECT COUNT(*) FROM contact_messages WHERE {$whereSql}", $params);
$pg       = admin_paginate($total, 25);

$rows = db_all("SELECT * FROM contact_messages WHERE {$whereSql}
                ORDER BY created_at DESC LIMIT {$pg['per']} OFFSET {$pg['offset']}", $params);

$pageTitle    = 'Messages';
$adminSection = 'messages';

require_once YN_ROOT . '/includes/admin-header.php';
?>

<div class="admin-head">
  <div class="min0"><h1>Messages</h1>
    <p><?= number_format($total) ?> enquir<?= $total === 1 ? 'y' : 'ies' ?></p></div>
</div>

<div class="chips mb4">
  <a class="chip <?= $kind === '' && $status === '' ? 'is-on' : '' ?>"
     href="<?= e(url('admin/messages.php')) ?>">All</a>
  <a class="chip <?= $status === 'new' ? 'is-on' : '' ?>"
     href="<?= e(url('admin/messages.php?status=new')) ?>">Unread</a>
  <a class="chip <?= $kind === 'contact' ? 'is-on' : '' ?>"
     href="<?= e(url('admin/messages.php?kind=contact')) ?>">Contact form</a>
  <a class="chip <?= $kind === 'wholesale' ? 'is-on' : '' ?>"
     href="<?= e(url('admin/messages.php?kind=wholesale')) ?>">Wholesale</a>
</div>

<?php if (!$rows): ?>
  <div class="panel"><div class="admin-empty">
    <?= icon('mail', 40) ?><p>No messages yet.</p>
    <p class="text-xs">The contact form arrives in Phase 7 — this inbox is ready for it.</p></div></div>
<?php else: ?>
  <?php foreach ($rows as $m): ?>
    <div class="panel" <?= $m['status'] === 'new' ? 'style="border-left:3px solid var(--clay)"' : '' ?>>
      <div class="row row-between mb3">
        <div class="min0">
          <p class="mb1"><strong><?= e((string) $m['name']) ?></strong>
            <span class="muted">· <a href="mailto:<?= e((string) $m['email']) ?>">
              <?= e((string) $m['email']) ?></a></span></p>
          <p class="text-xs muted mb0">
            <?= e(fmt_datetime((string) $m['created_at'])) ?>
            <?php if ($m['phone']): ?> · <?= e((string) $m['phone']) ?><?php endif; ?>
            <?php if ($m['order_no']): ?> · order <?= e((string) $m['order_no']) ?><?php endif; ?>
          </p>
        </div>
        <div class="row gap2" style="flex:none">
          <span class="pill pill-<?= $m['kind'] === 'wholesale' ? 'info' : 'muted' ?>">
            <?= e((string) $m['kind']) ?></span>
          <span class="pill pill-<?= $m['status'] === 'new' ? 'warn' : 'muted' ?>">
            <?= e((string) $m['status']) ?></span>
        </div>
      </div>

      <?php if ($m['subject']): ?>
        <p class="text-sm mb2"><strong><?= e((string) $m['subject']) ?></strong></p>
      <?php endif; ?>
      <p class="text-sm soft mb3"><?= nl2br(e((string) $m['message'])) ?></p>

      <?php if (!empty($m['extra_json'])): ?>
        <?php $extra = json_decode((string) $m['extra_json'], true); ?>
        <?php if (is_array($extra)): ?>
          <dl class="dl mb3">
            <?php foreach ($extra as $k => $v): ?>
              <?php if (is_scalar($v)): ?>
                <dt><?= e(ucfirst(str_replace('_', ' ', (string) $k))) ?></dt>
                <dd><?= e((string) $v) ?></dd>
              <?php endif; ?>
            <?php endforeach; ?>
          </dl>
        <?php endif; ?>
      <?php endif; ?>

      <div class="row gap2">
        <a class="btn btn-sm" href="mailto:<?= e((string) $m['email']) ?>?subject=<?=
          rawurlencode('Re: ' . ((string) $m['subject'] ?: 'Your enquiry')) ?>">
          <?= icon('mail', 14) ?> Reply by email</a>
        <?php foreach (['read' => 'Mark read', 'replied' => 'Mark replied', 'closed' => 'Close'] as $a => $l): ?>
          <?php if ($m['status'] !== $a): ?>
            <form method="post" action="<?= e(url('admin/messages.php?' . http_build_query($_GET))) ?>">
              <?= csrf_field() ?>
              <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
              <input type="hidden" name="action" value="<?= e($a) ?>">
              <button class="btn-link" type="submit"><?= e($l) ?></button>
            </form>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endforeach; ?>

  <?php if ($pg['pages'] > 1): ?>
    <?php require_once YN_ROOT . '/includes/components/pagination.php'; ?>
    <?php pagination($pg['page'], $pg['pages']); ?>
  <?php endif; ?>
<?php endif; ?>

<?php require_once YN_ROOT . '/includes/admin-footer.php'; ?>
