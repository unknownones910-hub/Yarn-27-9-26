<?php
/**
 * Invoice / packing slip PDF for staff.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/admin-bootstrap.php';
require_once YN_ROOT . '/includes/invoice.php';

admin_guard();

$id    = get_int('id');
$doc   = get_str('doc', 10) === 'slip' ? 'slip' : 'invoice';
$order = $id > 0 ? order_by_id($id) : null;

if (!$order) {
    http_response_code(404);
    exit('Order not found.');
}

$items = order_items($id);

try {
    $pdf = $doc === 'slip'
        ? packing_slip_pdf_string($order, $items)
        : invoice_pdf_string($order, $items);
} catch (Throwable $e) {
    error_log('Admin PDF failed for order ' . $id . ': ' . $e->getMessage());
    $pdf = null;
}

if ($pdf === null) {
    flash('Could not generate that document. Is FPDF installed in /vendor?', 'error');
    redirect('admin/order-detail.php?id=' . $id);
}

$name = ($doc === 'slip' ? 'packing-slip-' : 'invoice-')
      . preg_replace('/[^A-Za-z0-9\-]/', '', (string) $order['order_no']) . '.pdf';

header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . $name . '"');
header('Content-Length: ' . strlen($pdf));
header('Cache-Control: private, no-store');
echo $pdf;
