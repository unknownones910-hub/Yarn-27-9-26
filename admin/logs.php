<?php
/**
 * Audit trail: who did what, and what the mail and webhook layers have been up to.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-bootstrap.php';
$admin = admin_guard('manager');

$tab = get_str('tab', 20) ?: 'activity';

$pageTitle    = 'Logs';
$adminSection = 'logs';

require_once YN_ROOT . '/includes/admin-header.php';
?>

<div class="admin-head">
  <div class="min0"><h1>Logs</h1><p>Everything is recorded, including your own actions.</p></div>
</div>

<div class="chips mb4">
  <?php foreach (['activity' => 'Admin activity', 'email' => 'Email', 'webhook' => 'Payment webhooks',
                  'stock' => 'Stock movements'] as $k => $lbl): ?>
    <a class="chip <?= $tab === $k ? 'is-on' : '' ?>"
       href="<?= e(url('admin/logs.php?tab=' . $k)) ?>"><?= e($lbl) ?></a>
  <?php endforeach; ?>
</div>

<?php if ($tab === 'activity'): ?>
  <?php $rows = db_all('SELECT l.*, a.name AS admin_name FROM admin_activity_log l
                        LEFT JOIN admin_users a ON a.id = l.admin_id
                        ORDER BY l.id DESC LIMIT 200'); ?>
  <div class="panel panel--flush">
    <h2>Last 200 admin actions</h2>
    <div class="scroll-x">
      <table class="admin-table" style="min-width:620px">
        <thead><tr><th>When</th><th>Who</th><th>Action</th><th>Target</th><th>Detail</th><th>IP</th></tr></thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td class="text-xs tight"><?= e(fmt_date((string) $r['created_at'], 'j M, g:ia')) ?></td>
              <td class="text-xs"><?= e((string) ($r['admin_name'] ?: 'system')) ?></td>
              <td><span class="pill pill-muted"><?= e((string) $r['action']) ?></span></td>
              <td class="text-xs muted"><?= e((string) $r['entity']) ?>
                <?= $r['entity_id'] ? '#' . (int) $r['entity_id'] : '' ?></td>
              <td class="text-xs" style="overflow-wrap:anywhere"><?= e((string) $r['detail']) ?></td>
              <td class="text-xs muted"><?= e((string) $r['ip']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php elseif ($tab === 'email'): ?>
  <?php
  $rows = db_all('SELECT * FROM email_log ORDER BY id DESC LIMIT 200');
  $fails = (int) db_val('SELECT COUNT(*) FROM email_log WHERE status = "failed"');
  ?>
  <?php if ($fails > 0): ?>
    <div class="alert alert-warn mb4"><?= icon('alert', 18) ?>
      <div><?= $fails ?> email<?= $fails === 1 ? ' has' : 's have' ?> failed.
        Check the Brevo API key and that your sending domain is verified.</div></div>
  <?php endif; ?>
  <div class="panel panel--flush">
    <h2>Last 200 emails</h2>
    <div class="scroll-x">
      <table class="admin-table" style="min-width:620px">
        <thead><tr><th>When</th><th>To</th><th>Subject</th><th>Template</th>
          <th>Status</th><th>Error</th></tr></thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td class="text-xs tight"><?= e(fmt_date((string) $r['created_at'], 'j M, g:ia')) ?></td>
              <td class="text-xs" style="overflow-wrap:anywhere"><?= e((string) $r['to_email']) ?></td>
              <td class="text-xs"><?= e(str_excerpt((string) $r['subject'], 46)) ?></td>
              <td class="text-xs muted"><?= e((string) $r['template']) ?></td>
              <td><span class="pill pill-<?= $r['status'] === 'sent' ? 'ok'
                    : ($r['status'] === 'failed' ? 'bad' : 'muted') ?>">
                <?= e((string) $r['status']) ?></span></td>
              <td class="text-xs" style="overflow-wrap:anywhere;color:var(--danger)">
                <?= e(str_excerpt((string) $r['error'], 60)) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php elseif ($tab === 'webhook'): ?>
  <?php $rows = db_all('SELECT id, event_id, event_type, processed_at, error, created_at
                        FROM payment_webhooks ORDER BY id DESC LIMIT 100'); ?>
  <div class="panel panel--flush">
    <h2>Razorpay webhook deliveries</h2>
    <?php if (!$rows): ?>
      <div class="admin-empty"><?= icon('lock', 40) ?>
        <p>Nothing received yet.</p>
        <p class="text-xs">Register <code>/webhooks/razorpay.php</code> in the Razorpay dashboard.</p>
      </div>
    <?php else: ?>
      <div class="scroll-x">
        <table class="admin-table" style="min-width:560px">
          <thead><tr><th>When</th><th>Event</th><th>Event ID</th><th>Processed</th><th>Error</th></tr></thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td class="text-xs tight"><?= e(fmt_date((string) $r['created_at'], 'j M, g:ia')) ?></td>
                <td><span class="pill pill-muted"><?= e((string) $r['event_type']) ?></span></td>
                <td class="text-xs muted" style="overflow-wrap:anywhere"><?= e((string) $r['event_id']) ?></td>
                <td><?= $r['processed_at']
                      ? '<span class="pill pill-ok">yes</span>'
                      : '<span class="pill pill-warn">no</span>' ?></td>
                <td class="text-xs" style="color:var(--danger)"><?= e((string) $r['error']) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

<?php else: ?>
  <?php $rows = db_all('SELECT m.*, v.sku, v.shade_name, p.name AS product_name, a.name AS admin_name
                        FROM stock_movements m
                        JOIN product_variants v ON v.id = m.variant_id
                        JOIN products p ON p.id = v.product_id
                        LEFT JOIN admin_users a ON a.id = m.admin_id
                        ORDER BY m.id DESC LIMIT 200'); ?>
  <div class="panel panel--flush">
    <h2>Last 200 stock movements</h2>
    <div class="scroll-x">
      <table class="admin-table" style="min-width:620px">
        <thead><tr><th>When</th><th class="num">Change</th><th>Product</th><th>Shade</th>
          <th>Reason</th><th>Note</th><th>By</th></tr></thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <tr>
              <td class="text-xs tight"><?= e(fmt_date((string) $r['created_at'], 'j M, g:ia')) ?></td>
              <td class="num"><strong style="color:<?= (int) $r['delta'] < 0
                    ? 'var(--danger)' : 'var(--success)' ?>">
                <?= (int) $r['delta'] > 0 ? '+' : '' ?><?= (int) $r['delta'] ?></strong></td>
              <td class="text-xs truncate" style="max-width:180px"><?= e((string) $r['product_name']) ?></td>
              <td class="text-xs"><?= e((string) $r['shade_name']) ?></td>
              <td><span class="pill pill-muted"><?= e((string) $r['reason']) ?></span></td>
              <td class="text-xs muted"><?= e((string) $r['note']) ?></td>
              <td class="text-xs muted"><?= e((string) ($r['admin_name'] ?: '—')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php require_once YN_ROOT . '/includes/admin-footer.php'; ?>
