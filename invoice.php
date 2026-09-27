<?php
/**
 * Download an order invoice as a PDF.
 *
 * Access is the same rule as the confirmation page: the session that placed
 * the order, or the signed-in owner. An order number alone is not enough.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once YN_ROOT . '/includes/order.php';
require_once YN_ROOT . '/includes/invoice.php';

$orderNo = get_str('order', 20);
$order   = $orderNo !== '' ? order_by_no($orderNo) : null;

$owns = $order && (
    (isset($_SESSION['last_order_no']) && hash_equals((string) $_SESSION['last_order_no'], (string) $order['order_no']))
    || (customer_id() > 0 && (int) $order['customer_id'] === customer_id())
);

if (!$order || !$owns) {
    http_response_code(404);
    require YN_ROOT . '/404.php';
    exit;
}

$items = order_items((int) $order['id']);

try {
    $pdf = invoice_pdf_string($order, $items);
} catch (Throwable $e) {
    error_log('Invoice render failed for ' . $order['order_no'] . ': ' . $e->getMessage());
    $pdf = null;
}

if ($pdf === null) {
    flash('We could not generate that invoice. Please contact us and we will email it.', 'error');
    redirect('order-success/' . rawurlencode((string) $order['order_no']));
}

$filename = 'invoice-' . preg_replace('/[^A-Za-z0-9\-]/', '', (string) $order['order_no']) . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store');
echo $pdf;
