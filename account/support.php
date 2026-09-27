<?php
/**
 * Support tickets.
 *
 * A thread per question, tied to an order where relevant, so nothing gets
 * lost between email and WhatsApp.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/account-nav.php';
require_once YN_ROOT . '/includes/order.php';

$customer = require_login();
$cid      = (int) $customer['id'];

$errors   = [];
$ticketId = get_int('id');

if (is_post()) {
    csrf_verify();
    $action = post_str('action', 20);

    // ---- new thread ---------------------------------------------------
    if ($action === 'create') {
        $subject = post_str('subject', 180);
        $body    = post_str('body', 4000);
        $orderId = post_int('order_id') ?: null;

        if ($orderId !== null) {
            $owns = db_val('SELECT id FROM orders WHERE id = ? AND customer_id = ?', [$orderId, $cid]);
            if (!$owns) {
                $orderId = null;
            }
        }

        if (mb_strlen($subject) < 3) {
            $errors['subject'] = 'Please give your question a short subject.';
        }
        if (mb_strlen($body) < 10) {
            $errors['body'] = 'Please tell us a bit more so we can actually help.';
        }
        if (!$errors && !rate_hit('ticket:' . $cid, 10, 3600)) {
            $errors['body'] = 'You have opened several tickets just now. Please give us a chance to reply.';
        }

        if (!$errors) {
            $newId = db_insert(
                'INSERT INTO support_tickets (customer_id, order_id, subject, status)
                 VALUES (?,?,?, "open")',
                [$cid, $orderId, $subject]
            );
            db_insert(
                'INSERT INTO support_messages (ticket_id, sender, body) VALUES (?, "customer", ?)',
                [$newId, $body]
            );

            try {
                require_once YN_ROOT . '/includes/order-emails.php';
                foreach (order_admin_recipients() as $to) {
                    send_mail($to, 'Admin', 'Support ticket #' . $newId . ' · ' . $subject,
                        email_layout('New support ticket',
                            '<p style="margin:0 0 16px;">From ' . e((string) $customer['email']) . '</p>'
                            . '<p style="margin:0 0 16px;"><strong>' . e($subject) . '</strong></p>'
                            . '<p style="margin:0;color:#5D554C;">' . nl2br(e($body)) . '</p>'),
                        null, 'admin_ticket');
                }
            } catch (Throwable $e) {
                error_log('Ticket notification failed: ' . $e->getMessage());
            }

            flash('Ticket opened. We reply within one working day.', 'success');
            redirect('account/support.php?id=' . $newId);
        }
    }

    // ---- reply on an existing thread ------------------------------------
    if ($action === 'reply') {
        $tid  = post_int('ticket_id');
        $body = post_str('body', 4000);

        $ticket = db_row('SELECT * FROM support_tickets WHERE id = ? AND customer_id = ? LIMIT 1',
                         [$tid, $cid]);

        if ($ticket && mb_strlen($body) >= 2) {
            db_insert('INSERT INTO support_messages (ticket_id, sender, body) VALUES (?, "customer", ?)',
                      [$tid, $body]);
            db_exec('UPDATE support_tickets SET status = "open", updated_at = NOW() WHERE id = ?', [$tid]);
            flash('Reply sent.', 'success');
        }
        redirect('account/support.php?id=' . $tid);
    }

    // ---- customer closes it --------------------------------------------
    if ($action === 'close') {
        $tid = post_int('ticket_id');
        db_exec('UPDATE support_tickets SET status = "closed" WHERE id = ? AND customer_id = ?',
                [$tid, $cid]);
        flash('Ticket closed. Reopen any time by replying.', 'success');
        redirect('account/support.php');
    }
}

$ticket   = null;
$messages = [];
if ($ticketId > 0) {
    $ticket = db_row('SELECT * FROM support_tickets WHERE id = ? AND customer_id = ? LIMIT 1',
                     [$ticketId, $cid]);
    if ($ticket) {
        $messages = db_all('SELECT * FROM support_messages WHERE ticket_id = ? ORDER BY created_at, id',
                           [$ticketId]);
    }
}

$tickets = db_all(
    'SELECT t.*, (SELECT COUNT(*) FROM support_messages m WHERE m.ticket_id = t.id) AS msgs
     FROM support_tickets t WHERE t.customer_id = ? ORDER BY t.updated_at DESC',
    [$cid]
);

$myOrders  = db_all('SELECT id, order_no, placed_at FROM orders WHERE customer_id = ?
                     ORDER BY placed_at DESC LIMIT 20', [$cid]);
$preOrder  = get_int('order');
$waLink    = whatsapp_link('Hi! I have a question about my order.');

$pageTitle = 'Support';
$noindex   = true;

require_once YN_ROOT . '/includes/header.php';
?>

<div class="container section-sm">
  <h1 class="mb5">Support</h1>

  <div class="account-layout">
    <?php account_nav('support'); ?>

    <div class="min0">

      <?php if ($ticket): ?>
        <p class="mb4"><a class="text-sm" href="<?= e(url('account/support.php')) ?>">
          <?= icon('arrow-left', 14) ?> All tickets</a></p>

        <div class="card mb5">
          <div class="row row-between mb3">
            <div class="min0">
              <h2 style="font-family:var(--font-sans);font-size:1.0625rem;margin:0">
                <?= e((string) $ticket['subject']) ?></h2>
              <p class="text-xs muted mb0">
                Opened <?= e(fmt_date((string) $ticket['created_at'])) ?>
                <?php if ($ticket['order_id']): ?>
                  · <a href="<?= e(url('account/order-detail.php?id=' . (int) $ticket['order_id'])) ?>">
                    view order</a>
                <?php endif; ?>
              </p>
            </div>
            <span class="pill <?= $ticket['status'] === 'closed' ? 'pill-muted' : 'pill-info' ?>"
                  style="flex:none"><?= e(ucfirst(str_replace('_', ' ', (string) $ticket['status']))) ?></span>
          </div>

          <div class="stack mt4">
            <?php foreach ($messages as $m): ?>
              <div style="padding:var(--s4);border-radius:var(--r-md);
                          background:<?= $m['sender'] === 'admin' ? 'var(--sand)' : 'var(--clay-tint)' ?>">
                <p class="text-xs muted mb2">
                  <strong><?= $m['sender'] === 'admin' ? e(setting('store_name')) : 'You' ?></strong>
                  · <?= e(fmt_datetime((string) $m['created_at'])) ?>
                </p>
                <p class="text-sm mb0"><?= nl2br(e((string) $m['body'])) ?></p>
              </div>
            <?php endforeach; ?>
          </div>

          <?php if ($ticket['status'] !== 'closed'): ?>
            <form method="post" action="<?= e(url('account/support.php')) ?>" class="mt5">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="reply">
              <input type="hidden" name="ticket_id" value="<?= (int) $ticket['id'] ?>">
              <div class="field">
                <label class="label" for="reply">Add a reply</label>
                <textarea class="textarea" id="reply" name="body" rows="3" required></textarea>
              </div>
              <div class="row">
                <button class="btn" type="submit">Send reply</button>
                <button class="btn btn-soft" type="submit" name="action" value="close"
                        formnovalidate>Close ticket</button>
              </div>
            </form>
          <?php else: ?>
            <p class="text-sm muted mt4 mb0">This ticket is closed. Open a new one if you need us again.</p>
          <?php endif; ?>
        </div>

      <?php else: ?>
        <div class="card mb5">
          <h2 style="font-family:var(--font-sans);font-size:1.0625rem">Ask us something</h2>
          <p class="text-sm soft">We reply within one working day, Monday to Saturday.</p>

          <form method="post" action="<?= e(url('account/support.php')) ?>" class="mt4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">

            <?php if ($myOrders): ?>
              <div class="field">
                <label class="label" for="order_id">Which order? <span class="opt">(optional)</span></label>
                <select class="select" id="order_id" name="order_id">
                  <option value="">Not about a specific order</option>
                  <?php foreach ($myOrders as $o): ?>
                    <option value="<?= (int) $o['id'] ?>" <?= $preOrder === (int) $o['id'] ? 'selected' : '' ?>>
                      <?= e((string) $o['order_no']) ?> · <?= e(fmt_date((string) $o['placed_at'])) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
            <?php endif; ?>

            <div class="field <?= isset($errors['subject']) ? 'has-error' : '' ?>">
              <label class="label" for="subject">Subject <span class="req">*</span></label>
              <input class="input" id="subject"<?= field_invalid($errors, 'subject') ?> name="subject" required maxlength="180"
                     placeholder="Wrong shade delivered" value="<?= e(post_str('subject', 180)) ?>">
              <?= field_error($errors, 'subject') ?>
            </div>

            <div class="field <?= isset($errors['body']) ? 'has-error' : '' ?>">
              <label class="label" for="body">How can we help? <span class="req">*</span></label>
              <textarea class="textarea" id="body"<?= field_invalid($errors, 'body') ?> name="body" required rows="4"
                        placeholder="Tell us what happened."><?= e(post_str('body', 4000)) ?></textarea>
              <?= field_error($errors, 'body') ?>
            </div>

            <button class="btn" type="submit">Open ticket</button>
          </form>

          <?php if ($waLink !== ''): ?>
            <p class="text-sm muted mt5 mb0">
              In a hurry? <a href="<?= eurl($waLink) ?>" target="_blank" rel="noopener">
                Message us on WhatsApp</a>.
            </p>
          <?php endif; ?>
        </div>

        <?php if ($tickets): ?>
          <h2 style="font-family:var(--font-sans);font-size:1rem" class="mb3">Your tickets</h2>
          <?php foreach ($tickets as $t): ?>
            <a class="review-line" style="text-decoration:none"
               href="<?= e(url('account/support.php?id=' . (int) $t['id'])) ?>">
              <div class="min0" style="flex:1">
                <p class="text-sm mb0"><strong><?= e((string) $t['subject']) ?></strong></p>
                <p class="text-xs muted mb0">
                  <?= (int) $t['msgs'] ?> message<?= (int) $t['msgs'] === 1 ? '' : 's' ?>
                  · updated <?= e(time_ago((string) $t['updated_at'])) ?>
                </p>
              </div>
              <span class="pill <?= $t['status'] === 'closed' ? 'pill-muted' : 'pill-info' ?>">
                <?= e(ucfirst(str_replace('_', ' ', (string) $t['status']))) ?></span>
            </a>
          <?php endforeach; ?>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php require_once YN_ROOT . '/includes/footer.php'; ?>
