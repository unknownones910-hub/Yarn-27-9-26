<?php
/**
 * Customer-initiated actions on their own order: exchange claim, reorder.
 *
 * Cancellation was removed deliberately — the shop does not accept it. The
 * branch is gone rather than hidden in the template, because a POST is a POST:
 * leaving the handler in place while removing the button would still let anyone
 * cancel by crafting the request.
 *
 * Every branch re-checks ownership AND eligibility server-side. A hidden
 * order_id in a form proves nothing.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/order.php';

$customer = require_login();
$cid      = (int) $customer['id'];

if (!is_post()) {
    redirect('account/orders.php');
}

csrf_verify();

$action  = post_str('action', 20);
$orderId = post_int('order_id');
$order   = $orderId > 0 ? order_by_id($orderId) : null;

if (!$order || (int) $order['customer_id'] !== $cid) {
    flash('We could not find that order.', 'error');
    redirect('account/orders.php');
}

$back = 'account/order-detail.php?id=' . $orderId;

switch ($action) {

    // -------------------------------------------------------- exchange --
    case 'return':
        $windowDays  = setting_int('return_window_days', 7);
        $deliveredAt = $order['delivered_at'] ? strtotime((string) $order['delivered_at']) : null;

        if ($order['status'] !== 'delivered' || $deliveredAt === null) {
            flash('A claim can only be raised once an order has been delivered.', 'warn');
            redirect($back);
        }
        if ((time() - $deliveredAt) > ($windowDays * 86400)) {
            flash('The ' . $windowDays . '-day claim window has closed. Please contact us — we will still help.', 'warn');
            redirect($back);
        }
        if (db_row('SELECT id FROM return_requests WHERE order_id = ? LIMIT 1', [$orderId])) {
            flash('A claim has already been raised for this order.', 'warn');
            redirect($back);
        }

        // Defect claims only. There is no "changed my mind" value to fall back
        // to any more, so an unrecognised reason is rejected rather than
        // quietly filed as something the shop does not accept.
        $allowedReasons = ['damaged', 'wrong_item', 'defective'];
        $reason = post_str('reason', 30);
        if (!in_array($reason, $allowedReasons, true)) {
            flash('Please choose what went wrong — we only exchange wrong, damaged or defective items.', 'warn');
            redirect($back);
        }

        // The unboxing video is the whole basis of the policy, so it is
        // required here and not only in the markup.
        $videoUrl = trim(post_str('video_url', 500));
        if ($videoUrl === '' || !preg_match('~^https?://~i', $videoUrl)
            || !filter_var($videoUrl, FILTER_VALIDATE_URL)) {
            flash('We need a link to your unboxing video before we can accept a claim. '
                . 'Upload it to Google Drive or YouTube (unlisted) and paste the link.', 'warn');
            redirect($back);
        }

        // Only accept item ids that actually belong to this order.
        $valid = db_col('SELECT id FROM order_items WHERE order_id = ?', [$orderId]);
        $sent  = array_map('intval', (array) ($_POST['items'] ?? []));
        $items = array_values(array_intersect($sent, array_map('intval', $valid)));

        if (!$items) {
            $items = array_map('intval', $valid);   // whole order
        }

        db_insert(
            'INSERT INTO return_requests
                 (order_id, customer_id, items_json, reason, comments, video_url, status)
             VALUES (?,?,?,?,?,?, "requested")',
            [$orderId, $cid, json_col($items), $reason, post_str('comments', 600) ?: null, $videoUrl]
        );

        try {
            require_once YN_ROOT . '/includes/order-emails.php';
            foreach (order_admin_recipients() as $to) {
                send_mail($to, 'Admin',
                    'Exchange claim · ' . $order['order_no'],
                    email_layout('Exchange claim',
                        '<p style="margin:0 0 16px;">Order <strong>' . e((string) $order['order_no'])
                        . '</strong> · ' . e((string) $order['email']) . '</p>'
                        . '<p style="margin:0 0 16px;">Reason: <strong>' . e($reason) . '</strong></p>'
                        . '<p style="margin:0 0 16px;">Unboxing video: <a href="' . e($videoUrl) . '">' . e($videoUrl) . '</a></p>'
                        . '<p style="margin:0;color:#5D554C;">' . e(post_str('comments', 600)) . '</p>'
                        . email_button('Open in admin', abs_url('admin/order-detail.php?id=' . $orderId))),
                    null, 'admin_return');
            }
        } catch (Throwable $e) {
            error_log('Return notification failed: ' . $e->getMessage());
        }

        flash('Claim raised. We will review your video and email you within one working day.', 'success');
        redirect($back);

    // --------------------------------------------------------- reorder --
    case 'reorder':
        $added   = 0;
        $skipped = 0;

        foreach (order_items($orderId) as $it) {
            $variantId = (int) $it['variant_id'];
            if ($variantId <= 0) {
                $skipped++;
                continue;
            }
            $res = cart_add($variantId, (int) $it['qty']);
            if ($res['ok']) {
                $added++;
            } else {
                $skipped++;
            }
        }

        if ($added === 0) {
            flash('None of those shades are available right now.', 'warn');
            redirect($back);
        }

        flash($skipped > 0
            ? $added . ' item' . ($added === 1 ? '' : 's') . ' added — ' . $skipped
              . ' could not be (out of stock or discontinued).'
            : 'Added to your cart.', $skipped > 0 ? 'warn' : 'success');
        redirect('cart.php');

    default:
        flash('Unknown action.', 'error');
        redirect($back);
}
