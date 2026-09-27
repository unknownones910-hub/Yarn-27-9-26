<?php
/**
 * Order-related email bodies.
 *
 * Separate from mailer.php so that file stays a transport, not a template
 * library. Every function here is safe to call even when Brevo is not
 * configured — send_mail() logs and returns false.
 */
declare(strict_types=1);

require_once __DIR__ . '/mailer.php';

/** Items rendered as an email-safe table. */
function order_email_items_table(array $items): string
{
    $rows = '';
    foreach ($items as $it) {
        $rows .= '<tr>'
            . '<td style="padding:10px 0;border-bottom:1px solid #F5EEE4;font:14px/1.5 Arial,sans-serif;color:#2E2A26;">'
            . '<strong>' . e($it['product_name']) . '</strong><br>'
            . '<span style="color:#8A8078;font-size:13px;">' . e((string) $it['shade_name'])
            . ' &middot; Qty ' . (int) $it['qty'] . '</span></td>'
            . '<td align="right" style="padding:10px 0;border-bottom:1px solid #F5EEE4;'
            . 'font:14px/1.5 Arial,sans-serif;color:#2E2A26;white-space:nowrap;">'
            . e(money((float) $it['line_total'])) . '</td></tr>';
    }

    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">'
         . $rows . '</table>';
}

/** Totals block for an order row. */
function order_email_totals(array $o): string
{
    $line = static function (string $label, string $value, bool $bold = false, string $colour = '#5D554C'): string {
        $weight = $bold ? '700' : '400';
        $size   = $bold ? '16px' : '14px';
        return '<tr>'
             . '<td style="padding:5px 0;font:' . $weight . ' ' . $size . '/1.5 Arial,sans-serif;color:' . $colour . ';">' . $label . '</td>'
             . '<td align="right" style="padding:5px 0;font:' . $weight . ' ' . $size
             . '/1.5 Arial,sans-serif;color:' . ($bold ? '#2E2A26' : $colour) . ';white-space:nowrap;">' . $value . '</td>'
             . '</tr>';
    };

    $html = $line('Subtotal', e(money((float) $o['subtotal'])));

    if ((float) $o['discount_total'] > 0) {
        $html .= $line('Discount ' . e((string) $o['coupon_code']),
                       '&minus;' . e(money((float) $o['discount_total'])), false, '#3B7A50');
    }
    if ((float) $o['gift_wrap_total'] > 0) {
        $html .= $line('Gift wrap', e(money((float) $o['gift_wrap_total'])));
    }

    $html .= $line('Delivery', (float) $o['shipping_total'] > 0
        ? e(money((float) $o['shipping_total']))
        : '<span style="color:#3B7A50;font-weight:700;">FREE</span>');

    $html .= '<tr><td colspan="2" style="border-top:1px solid #EADFD0;height:8px;"></td></tr>';
    $html .= $line('Total', e(money((float) $o['grand_total'])), true);

    return '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" '
         . 'style="margin-top:8px;">' . $html . '</table>';
}

function order_email_address(array $o): string
{
    $bits = array_filter([
        $o['ship_line1'], $o['ship_line2'], $o['ship_landmark'],
        $o['ship_city'], $o['ship_state'] . ' ' . $o['ship_pincode'],
    ]);
    return '<p style="margin:0;font:14px/1.7 Arial,sans-serif;color:#5D554C;">'
         . '<strong style="color:#2E2A26;">' . e($o['ship_name']) . '</strong><br>'
         . e(implode(', ', array_map('trim', $bits))) . '<br>'
         . e($o['ship_phone']) . '</p>';
}

// -------------------------------------------------------- confirmation --

function order_send_confirmation(int $orderId): bool
{
    $o = order_by_id($orderId);
    if (!$o) {
        return false;
    }
    $items = order_items($orderId);

    $trackUrl = abs_url('track-order.php?order=' . rawurlencode((string) $o['order_no']));

    $body = '<p style="margin:0 0 16px;">Thank you — we have your order and payment.</p>'
          . '<p style="margin:0 0 8px;color:#5D554C;">Order number</p>'
          . '<p style="margin:0 0 24px;font:700 20px/1 Arial,sans-serif;color:#B4552F;">'
          . e((string) $o['order_no']) . '</p>'
          . '<p style="margin:0 0 8px;color:#5D554C;">We will pack and dispatch it within '
          . e(setting('dispatch_days', '1-2')) . ' business days, and email you a tracking link.</p>'
          . order_email_items_table($items)
          . order_email_totals($o)
          . '<h2 style="margin:28px 0 10px;font:600 17px/1.3 Georgia,serif;color:#2E2A26;">Delivering to</h2>'
          . order_email_address($o)
          . email_button('Track your order', $trackUrl)
          . '<p style="margin:0;color:#8A8078;font-size:14px;">Your invoice is attached as a PDF.</p>';

    $html = email_layout('Order confirmed', $body,
                         'Order ' . $o['order_no'] . ' confirmed — ' . money((float) $o['grand_total']));

    $text = "Thank you — we have your order and payment.\n\n"
          . 'Order: ' . $o['order_no'] . "\n"
          . 'Total: ' . money((float) $o['grand_total']) . "\n\n"
          . "Track: {$trackUrl}\n";

    // Attach the invoice when we can build one; never let that stop the email.
    $attachments = [];
    try {
        require_once __DIR__ . '/invoice.php';
        $pdf = invoice_pdf_string($o, $items);
        if ($pdf !== null) {
            $attachments[] = ['name' => 'invoice-' . $o['order_no'] . '.pdf', 'content' => $pdf];
        }
    } catch (Throwable $e) {
        error_log('Invoice generation failed for order ' . $orderId . ': ' . $e->getMessage());
    }

    return send_mail(
        (string) $o['email'],
        (string) $o['ship_name'],
        'Order confirmed · ' . $o['order_no'],
        $html,
        $text,
        'order_confirmation',
        $attachments
    );
}

// ------------------------------------------------------------ shipped --

function order_send_shipped(int $orderId): bool
{
    $o = order_by_id($orderId);
    if (!$o) {
        return false;
    }

    $tracking = !empty($o['tracking_url'])
        ? $o['tracking_url']
        : abs_url('track-order.php?order=' . rawurlencode((string) $o['order_no']));

    $courier = $o['courier'] ? e((string) $o['courier']) : 'our courier partner';
    $awb     = $o['awb'] ? '<p style="margin:0 0 16px;color:#5D554C;">Tracking number: <strong>'
                           . e((string) $o['awb']) . '</strong></p>' : '';

    $body = '<p style="margin:0 0 16px;">Your order <strong>' . e((string) $o['order_no'])
          . '</strong> is on its way with ' . $courier . '.</p>'
          . $awb
          . order_email_address($o)
          . email_button('Track your parcel', $tracking);

    return send_mail((string) $o['email'], (string) $o['ship_name'],
        'Your order has shipped · ' . $o['order_no'],
        email_layout('On its way', $body, 'Order ' . $o['order_no'] . ' has shipped'),
        'Your order ' . $o['order_no'] . " has shipped.\n" . $tracking,
        'order_shipped');
}

// ---------------------------------------------------------- cancelled --

function order_send_cancelled(int $orderId, string $reason = ''): bool
{
    $o = order_by_id($orderId);
    if (!$o) {
        return false;
    }

    $refundLine = $o['payment_status'] === 'paid'
        ? '<p style="margin:0 0 16px;">Your refund of <strong>' . e(money((float) $o['grand_total']))
          . '</strong> has been initiated and reaches your original payment method in 5–7 business days.</p>'
        : '<p style="margin:0 0 16px;">No payment was taken, so there is nothing to refund.</p>';

    $body = '<p style="margin:0 0 16px;">Your order <strong>' . e((string) $o['order_no'])
          . '</strong> has been cancelled.</p>'
          . ($reason !== '' ? '<p style="margin:0 0 16px;color:#5D554C;">Reason: ' . e($reason) . '</p>' : '')
          . $refundLine
          . email_button('Continue shopping', abs_url('shop.php'));

    return send_mail((string) $o['email'], (string) $o['ship_name'],
        'Order cancelled · ' . $o['order_no'],
        email_layout('Order cancelled', $body, 'Order ' . $o['order_no'] . ' cancelled'),
        'Your order ' . $o['order_no'] . " has been cancelled.\n",
        'order_cancelled');
}

// --------------------------------------------------- payment problems --

function order_send_payment_failed(int $orderId): bool
{
    $o = order_by_id($orderId);
    if (!$o) {
        return false;
    }

    $retry = abs_url('pay.php?order=' . rawurlencode((string) $o['order_no']));

    $body = '<p style="margin:0 0 16px;">We could not complete the payment for order <strong>'
          . e((string) $o['order_no']) . '</strong>.</p>'
          . '<p style="margin:0 0 16px;color:#5D554C;">Nothing has been charged. Your items are still '
          . 'held — you can finish the payment using the button below.</p>'
          . order_email_totals($o)
          . email_button('Complete payment', $retry);

    return send_mail((string) $o['email'], (string) $o['ship_name'],
        'Payment not completed · ' . $o['order_no'],
        email_layout('Payment did not go through', $body, 'Finish paying for ' . $o['order_no']),
        "We could not complete your payment for order {$o['order_no']}.\nFinish here: {$retry}\n",
        'payment_failed');
}

// ---------------------------------------------------- admin notifiers --

function order_admin_recipients(): array
{
    $to = array_filter(array_map('trim', explode(',', setting('admin_notify_emails', ''))));
    if (!$to) {
        $to = array_filter([setting('store_email')]);
    }
    return $to;
}

function order_notify_admin_new(int $orderId): void
{
    $o = order_by_id($orderId);
    if (!$o) {
        return;
    }
    $items = order_items($orderId);

    $body = '<p style="margin:0 0 16px;">New paid order.</p>'
          . '<p style="margin:0 0 16px;"><strong>' . e((string) $o['order_no']) . '</strong> &middot; '
          . e(money((float) $o['grand_total'])) . ' &middot; ' . e((string) $o['email']) . '</p>'
          . order_email_items_table($items)
          . order_email_address($o)
          . email_button('Open in admin', abs_url('admin/order-detail.php?id=' . (int) $o['id']));

    foreach (order_admin_recipients() as $to) {
        send_mail($to, 'Admin', 'New order ' . $o['order_no'] . ' · ' . money((float) $o['grand_total']),
                  email_layout('New order', $body), null, 'admin_new_order');
    }
}

/**
 * Paid, but stock could not be deducted. This needs a human today, so it is
 * shouty on purpose.
 */
function order_notify_admin_shortfall(int $orderId, array $shortfall): void
{
    $o = order_by_id($orderId);
    if (!$o) {
        return;
    }

    $rows = '';
    foreach ($shortfall as $s) {
        $rows .= '<li style="margin-bottom:6px;"><strong>' . e($s['sku']) . '</strong> — '
               . e($s['name']) . ' (' . e((string) $s['shade']) . ') ×' . (int) $s['qty'] . '</li>';
    }

    $body = '<p style="margin:0 0 16px;color:#A82B22;"><strong>Payment was captured but stock could '
          . 'not be deducted.</strong> The customer has paid. Either source the stock or refund the '
          . 'affected lines.</p>'
          . '<p style="margin:0 0 16px;">Order <strong>' . e((string) $o['order_no']) . '</strong> &middot; '
          . e((string) $o['email']) . '</p>'
          . '<ul style="margin:0 0 16px;padding-left:20px;font:14px/1.6 Arial,sans-serif;color:#2E2A26;">'
          . $rows . '</ul>'
          . email_button('Open in admin', abs_url('admin/order-detail.php?id=' . (int) $o['id']));

    foreach (order_admin_recipients() as $to) {
        send_mail($to, 'Admin', '[ACTION NEEDED] Stock shortfall on ' . $o['order_no'],
                  email_layout('Stock shortfall', $body), null, 'admin_shortfall');
    }
}
