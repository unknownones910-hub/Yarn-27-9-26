<?php
/**
 * Support ticket inbox.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-bootstrap.php';
require_once YN_ROOT . '/includes/mailer.php';

$admin = admin_guard();

$id = get_int('id');

if (is_post()) {
    csrf_verify();
    $tid    = post_int('ticket_id');
    $action = post_str('action', 20);
    $ticket = $tid > 0 ? db_row('SELECT * FROM support_tickets WHERE id = ?', [$tid]) : null;

    if ($ticket) {
        if ($action === 'reply') {
            $body = post_str('body', 4000);
            if (mb_strlen($body) >= 2) {
                db_insert('INSERT INTO support_messages (ticket_id, sender, body) VALUES (?, "admin", ?)',
                          [$tid, $body]);
                db_exec('UPDATE support_tickets SET status = "awaiting_customer", updated_at = NOW()
                         WHERE id = ?', [$tid]);
                admin_log('ticket_reply', 'ticket', $tid);

                // Tell the customer there is a reply waiting.
                $cust = db_row('SELECT email, full_name FROM customers WHERE id = ?',
                               [(int) $ticket['customer_id']]);
                if ($cust) {
                    try {
                        send_mail((string) $cust['email'], (string) ($cust['full_name'] ?? ''),
                            'Re: ' . $ticket['subject'],
                            email_layout('We have replied',
                                '<p style="margin:0 0 16px;">' . nl2br(e($body)) . '</p>'
                                . email_button('View the conversation',
                                               abs_url('account/support.php?id=' . $tid))),
                            null, 'support_reply');
                    } catch (Throwable $e) {
                        error_log('Support reply email failed: ' . $e->getMessage());
                    }
                }
                flash('Reply sent.', 'success');
            }
        } elseif (in_array($action, ['resolved', 'closed', 'open'], true)) {
            db_exec('UPDATE support_tickets SET status = ? WHERE id = ?', [$action, $tid]);
            admin_log('ticket_' . $action, 'ticket', $tid);
            flash('Ticket marked ' . $action . '.', 'success');
        }
    }
    redirect('admin/support.php' . ($tid ? '?id=' . $tid : ''));
}

$ticket   = null;
$messages = [];
if ($id > 0) {
    $ticket = db_row(
        'SELECT t.*, c.email, c.full_name, o.order_no
         FROM support_tickets t
         LEFT JOIN customers c ON c.id = t.customer_id
         LEFT JOIN orders o ON o.id = t.order_id
         WHERE t.id = ? LIMIT 1', [$id]
    );
    if ($ticket) {
        $messages = db_all('SELECT * FROM support_messages WHERE ticket_id = ? ORDER BY created_at, id',
                           [$id]);
    }
}

$filter = get_str('status', 20) ?: 'open';
$where  = $filter === 'all' ? '1=1' : 't.status = ?';
$params = $filter === 'all' ? [] : [$filter];

$tickets = db_all(
    "SELECT t.*, c.email, (SELECT COUNT(*) FROM support_messages m WHERE m.ticket_id = t.id) msgs
     FROM support_tickets t LEFT JOIN customers c ON c.id = t.customer_id
     WHERE {$where} ORDER BY t.updated_at DESC LIMIT 100",
    $params
);

$pageTitle    = 'Support';
$adminSection = 'support';

require_once YN_ROOT . '/includes/admin-header.php';
?>

<div class="admin-head">
  <div class="min0"><h1>Support</h1><p>Reply within one working day — that is what the site promises.</p></div>
</div>

<?php if ($ticket): ?>
  <p class="mb4"><a class="text-sm" href="<?= e(url('admin/support.php')) ?>">
    <?= icon('arrow-left', 13) ?> All tickets</a></p>

  <div class="panel">
    <div class="row row-between mb3">
      <div class="min0">
        <h2 style="margin:0;border:0;padding:0"><?= e((string) $ticket['subject']) ?></h2>
        <p class="text-xs muted mb0">
          <?= e((string) ($ticket['email'] ?? 'unknown')) ?>
          <?php if ($ticket['order_no']): ?>
            · <a href="<?= e(url('admin/order-detail.php?id=' . (int) $ticket['order_id'])) ?>">
              <?= e((string) $ticket['order_no']) ?></a><?php endif; ?>
          · opened <?= e(fmt_date((string) $ticket['created_at'])) ?>
        </p>
      </div>
      <span class="pill pill-<?= $ticket['status'] === 'closed' ? 'muted' : 'info' ?>" style="flex:none">
        <?= e(str_replace('_', ' ', (string) $ticket['status'])) ?></span>
    </div>

    <div class="stack mt4">
      <?php foreach ($messages as $m): ?>
        <div style="padding:var(--s4);border-radius:var(--r-md);
                    background:<?= $m['sender'] === 'admin' ? 'var(--clay-tint)' : 'var(--sand)' ?>">
          <p class="text-xs muted mb2">
            <strong><?= $m['sender'] === 'admin' ? 'You' : 'Customer' ?></strong>
            · <?= e(fmt_datetime((string) $m['created_at'])) ?></p>
          <p class="text-sm mb0"><?= nl2br(e((string) $m['body'])) ?></p>
        </div>
      <?php endforeach; ?>
    </div>

    <form method="post" action="<?= e(url('admin/support.php')) ?>" class="mt5">
      <?= csrf_field() ?>
      <input type="hidden" name="ticket_id" value="<?= (int) $ticket['id'] ?>">
      <input type="hidden" name="action" value="reply">
      <div class="field">
        <label class="label" for="body">Your reply</label>
        <textarea class="textarea" id="body" name="body" rows="4" required></textarea>
        <span class="help">The customer gets this by email straight away.</span>
      </div>
      <div class="row">
        <button class="btn" type="submit">Send reply</button>
        <button class="btn btn-soft" type="submit" name="action" value="resolved" formnovalidate>
          Mark resolved</button>
        <button class="btn btn-soft" type="submit" name="action" value="closed" formnovalidate>
          Close</button>
      </div>
    </form>
  </div>

<?php else: ?>
  <div class="chips mb4">
    <?php foreach (['open' => 'Open', 'awaiting_customer' => 'Waiting on customer',
                    'resolved' => 'Resolved', 'closed' => 'Closed', 'all' => 'All'] as $k => $lbl): ?>
      <a class="chip <?= $filter === $k ? 'is-on' : '' ?>"
         href="<?= e(url('admin/support.php?status=' . $k)) ?>"><?= e($lbl) ?></a>
    <?php endforeach; ?>
  </div>

  <div class="panel panel--flush">
    <?php if (!$tickets): ?>
      <div class="admin-empty"><?= icon('chat', 40) ?><p>No tickets here.</p></div>
    <?php else: ?>
      <div class="scroll-x">
        <table class="admin-table">
          <thead><tr><th>Subject</th><th>Customer</th><th class="num">Messages</th>
            <th>Updated</th><th>Status</th><th></th></tr></thead>
          <tbody>
            <?php foreach ($tickets as $t): ?>
              <tr>
                <td><a href="<?= e(url('admin/support.php?id=' . (int) $t['id'])) ?>">
                  <strong><?= e((string) $t['subject']) ?></strong></a></td>
                <td class="text-xs"><?= e((string) ($t['email'] ?? '—')) ?></td>
                <td class="num"><?= (int) $t['msgs'] ?></td>
                <td class="text-xs muted"><?= e(time_ago((string) $t['updated_at'])) ?></td>
                <td><span class="pill pill-<?= $t['status'] === 'closed' ? 'muted' : 'info' ?>">
                  <?= e(str_replace('_', ' ', (string) $t['status'])) ?></span></td>
                <td class="tight"><a class="btn-link"
                  href="<?= e(url('admin/support.php?id=' . (int) $t['id'])) ?>">Open</a></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php require_once YN_ROOT . '/includes/admin-footer.php'; ?>
