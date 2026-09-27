<?php
/**
 * Invoice + packing slip (PDF, via FPDF).
 *
 * Deliberately a plain bill, not a GST tax invoice: the store is not GST
 * registered yet. When `gst_enabled` is turned on, the tax block below starts
 * printing HSN codes and the CGST/SGST or IGST split — the order rows already
 * carry the fields.
 *
 * FPDF is Latin-1 only, so every string goes through pdf_txt().
 */
declare(strict_types=1);

/** FPDF cannot render the ₹ glyph in its core fonts; use "Rs." there. */
function pdf_txt(string $s): string
{
    $s = str_replace(['₹', '—', '–', '·', '’', '‘', '“', '”', '•'],
                     ['Rs. ', '-', '-', '-', "'", "'", '"', '"', '*'], $s);
    $conv = @iconv('UTF-8', 'ISO-8859-1//TRANSLIT', $s);
    return $conv === false ? preg_replace('/[^\x20-\x7E]/', '', $s) : $conv;
}

function pdf_money(float $n): string
{
    return 'Rs. ' . number_format($n, 2);
}

function invoice_available(): bool
{
    return is_file(YN_ROOT . '/vendor/setasign/fpdf/fpdf.php');
}

/**
 * Build the invoice and return the raw PDF bytes, or null if FPDF is missing.
 */
function invoice_pdf_string(array $o, array $items): ?string
{
    if (!invoice_available()) {
        return null;
    }
    require_once YN_ROOT . '/vendor/setasign/fpdf/fpdf.php';

    $store   = setting('store_legal_name', setting('store_name'));
    $addr    = setting('store_address');
    $phone   = setting('store_phone');
    $email   = setting('store_email');
    $gstin   = setting('store_gstin');
    $gstOn   = setting_bool('gst_enabled', false) && $gstin !== '';

    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->AddPage();
    $pdf->SetMargins(15, 15, 15);

    // ---- header ------------------------------------------------------
    $pdf->SetFont('Helvetica', 'B', 18);
    $pdf->SetTextColor(46, 42, 38);
    $pdf->Cell(120, 9, pdf_txt($store), 0, 0);

    $pdf->SetFont('Helvetica', 'B', 14);
    $pdf->SetTextColor(180, 85, 47);
    $pdf->Cell(0, 9, $gstOn ? 'TAX INVOICE' : 'INVOICE', 0, 1, 'R');

    $pdf->SetFont('Helvetica', '', 9);
    $pdf->SetTextColor(110, 103, 94);
    $pdf->MultiCell(120, 4.5, pdf_txt($addr . "\n" . $phone . ' | ' . $email
        . ($gstOn ? "\nGSTIN: " . $gstin : '')), 0, 'L');

    $pdf->Ln(2);
    $pdf->SetDrawColor(234, 223, 208);
    $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
    $pdf->Ln(5);

    // ---- meta --------------------------------------------------------
    $top = $pdf->GetY();

    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->SetTextColor(46, 42, 38);
    $pdf->Cell(90, 5, 'BILL TO', 0, 1);
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->SetTextColor(70, 64, 58);

    $shipBits = array_filter([
        $o['ship_line1'], $o['ship_line2'], $o['ship_landmark'],
        $o['ship_city'] . ', ' . $o['ship_state'] . ' ' . $o['ship_pincode'],
        $o['ship_country'],
    ]);
    $pdf->MultiCell(90, 4.8, pdf_txt($o['ship_name'] . "\n" . implode("\n", array_map('trim', $shipBits))
        . "\n" . $o['ship_phone'] . "\n" . $o['email']), 0, 'L');

    $bottomLeft = $pdf->GetY();

    $pdf->SetXY(120, $top);
    $meta = [
        ['Invoice no.', (string) $o['order_no']],
        ['Order date',  fmt_date((string) $o['placed_at'], 'j M Y')],
        ['Payment',     $o['payment_status'] === 'paid' ? 'Paid' : 'Pending'],
    ];
    if (!empty($o['paid_at'])) {
        $meta[] = ['Paid on', fmt_date((string) $o['paid_at'], 'j M Y')];
    }
    foreach ($meta as [$k, $v]) {
        $pdf->SetX(120);
        $pdf->SetFont('Helvetica', '', 9);
        $pdf->SetTextColor(138, 128, 120);
        $pdf->Cell(28, 5.5, pdf_txt($k), 0, 0);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->SetTextColor(46, 42, 38);
        $pdf->Cell(0, 5.5, pdf_txt($v), 0, 1, 'R');
    }

    $pdf->SetY(max($bottomLeft, $pdf->GetY()) + 6);

    // ---- items -------------------------------------------------------
    $pdf->SetFillColor(245, 238, 228);
    $pdf->SetFont('Helvetica', 'B', 8.5);
    $pdf->SetTextColor(110, 103, 94);

    $w = $gstOn ? [78, 22, 14, 28, 32] : [96, 24, 16, 42];
    $headers = $gstOn
        ? ['ITEM', 'HSN', 'QTY', 'RATE', 'AMOUNT']
        : ['ITEM', 'RATE', 'QTY', 'AMOUNT'];

    foreach ($headers as $i => $h) {
        $align = $i === 0 ? 'L' : 'R';
        $pdf->Cell($w[$i], 8, pdf_txt($h), 0, ($i === count($headers) - 1) ? 1 : 0, $align, true);
    }

    $pdf->SetFont('Helvetica', '', 9.5);
    $pdf->SetTextColor(46, 42, 38);

    foreach ($items as $it) {
        $label = $it['product_name'];
        if (!empty($it['shade_name'])) {
            $label .= ' - ' . $it['shade_name'];
        }

        $y0 = $pdf->GetY();
        $pdf->MultiCell($w[0], 6, pdf_txt($label), 0, 'L');
        $y1 = $pdf->GetY();
        $rowH = max(6, $y1 - $y0);
        $pdf->SetXY(15 + $w[0], $y0);

        if ($gstOn) {
            $pdf->Cell($w[1], $rowH, pdf_txt((string) ($it['hsn_code'] ?? '')), 0, 0, 'R');
            $pdf->Cell($w[2], $rowH, (string) (int) $it['qty'], 0, 0, 'R');
            $pdf->Cell($w[3], $rowH, pdf_txt(pdf_money((float) $it['unit_price'])), 0, 0, 'R');
            $pdf->Cell($w[4], $rowH, pdf_txt(pdf_money((float) $it['line_total'])), 0, 1, 'R');
        } else {
            $pdf->Cell($w[1], $rowH, pdf_txt(pdf_money((float) $it['unit_price'])), 0, 0, 'R');
            $pdf->Cell($w[2], $rowH, (string) (int) $it['qty'], 0, 0, 'R');
            $pdf->Cell($w[3], $rowH, pdf_txt(pdf_money((float) $it['line_total'])), 0, 1, 'R');
        }

        $pdf->SetDrawColor(245, 238, 228);
        $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
        $pdf->Ln(1.5);
    }

    // ---- totals ------------------------------------------------------
    $pdf->Ln(3);
    $labelW = 130;

    $row = static function (FPDF $pdf, string $label, string $value, bool $bold = false) use ($labelW): void {
        $pdf->SetFont('Helvetica', $bold ? 'B' : '', $bold ? 11 : 9.5);
        $pdf->SetTextColor($bold ? 46 : 110, $bold ? 42 : 103, $bold ? 38 : 94);
        $pdf->Cell($labelW, $bold ? 8 : 6, pdf_txt($label), 0, 0, 'R');
        $pdf->SetTextColor(46, 42, 38);
        $pdf->Cell(0, $bold ? 8 : 6, pdf_txt($value), 0, 1, 'R');
    };

    $row($pdf, 'Subtotal', pdf_money((float) $o['subtotal']));
    if ((float) $o['discount_total'] > 0) {
        $row($pdf, 'Discount ' . ($o['coupon_code'] ?: ''), '-' . pdf_money((float) $o['discount_total']));
    }
    if ((float) $o['gift_wrap_total'] > 0) {
        $row($pdf, 'Gift wrap', pdf_money((float) $o['gift_wrap_total']));
    }
    $row($pdf, 'Delivery', (float) $o['shipping_total'] > 0
        ? pdf_money((float) $o['shipping_total']) : 'FREE');

    if ($gstOn && (float) $o['tax_total'] > 0) {
        $row($pdf, 'Tax', pdf_money((float) $o['tax_total']));
    }

    $pdf->SetDrawColor(234, 223, 208);
    $pdf->Line(120, $pdf->GetY() + 1, 195, $pdf->GetY() + 1);
    $pdf->Ln(2);
    $row($pdf, 'TOTAL', pdf_money((float) $o['grand_total']), true);

    if (!$gstOn) {
        $pdf->Ln(2);
        $pdf->SetFont('Helvetica', 'I', 8);
        $pdf->SetTextColor(138, 128, 120);
        $pdf->Cell(0, 4.5, pdf_txt('Prices are inclusive of all applicable taxes.'), 0, 1, 'R');
    }

    // ---- footer ------------------------------------------------------
    $pdf->SetY(-32);
    $pdf->SetDrawColor(234, 223, 208);
    $pdf->Line(15, $pdf->GetY(), 195, $pdf->GetY());
    $pdf->Ln(3);
    $pdf->SetFont('Helvetica', '', 8);
    $pdf->SetTextColor(138, 128, 120);
    $pdf->MultiCell(0, 4, pdf_txt(
        'Thank you for shopping with ' . $store . '. '
        . 'Wrong, damaged or defective items exchanged within ' . setting_int('return_window_days', 7)
        . ' days, with an unedited unboxing video. No cancellations or returns. '
        . 'Questions? ' . $email . ' | ' . $phone
    ), 0, 'C');

    return $pdf->Output('S');
}

/** Packing slip — same items, no prices. */
function packing_slip_pdf_string(array $o, array $items): ?string
{
    if (!invoice_available()) {
        return null;
    }
    require_once YN_ROOT . '/vendor/setasign/fpdf/fpdf.php';

    $pdf = new FPDF('P', 'mm', 'A4');
    $pdf->SetAutoPageBreak(true, 18);
    $pdf->AddPage();
    $pdf->SetMargins(15, 15, 15);

    $pdf->SetFont('Helvetica', 'B', 16);
    $pdf->Cell(0, 9, pdf_txt('PACKING SLIP - ' . $o['order_no']), 0, 1);
    $pdf->SetFont('Helvetica', '', 10);
    $pdf->SetTextColor(110, 103, 94);
    $pdf->Cell(0, 6, pdf_txt('Order date: ' . fmt_date((string) $o['placed_at'], 'j M Y')), 0, 1);
    $pdf->Ln(4);

    $pdf->SetFont('Helvetica', 'B', 10);
    $pdf->SetTextColor(46, 42, 38);
    $pdf->Cell(0, 6, 'SHIP TO', 0, 1);
    $pdf->SetFont('Helvetica', '', 11);
    $bits = array_filter([
        $o['ship_line1'], $o['ship_line2'], $o['ship_landmark'],
        $o['ship_city'] . ', ' . $o['ship_state'] . ' ' . $o['ship_pincode'],
    ]);
    $pdf->MultiCell(0, 5.5, pdf_txt($o['ship_name'] . "\n" . implode("\n", array_map('trim', $bits))
        . "\n" . $o['ship_phone']), 0, 'L');
    $pdf->Ln(6);

    $pdf->SetFillColor(245, 238, 228);
    $pdf->SetFont('Helvetica', 'B', 9);
    $pdf->Cell(30, 8, 'SKU', 0, 0, 'L', true);
    $pdf->Cell(120, 8, 'ITEM', 0, 0, 'L', true);
    $pdf->Cell(0, 8, 'QTY', 0, 1, 'R', true);

    $pdf->SetFont('Helvetica', '', 10);
    foreach ($items as $it) {
        $label = $it['product_name'] . (!empty($it['shade_name']) ? ' - ' . $it['shade_name'] : '');
        $pdf->Cell(30, 7, pdf_txt((string) $it['sku']), 0, 0);
        $pdf->Cell(120, 7, pdf_txt(mb_substr($label, 0, 52)), 0, 0);
        $pdf->SetFont('Helvetica', 'B', 11);
        $pdf->Cell(0, 7, (string) (int) $it['qty'], 0, 1, 'R');
        $pdf->SetFont('Helvetica', '', 10);
    }

    if (!empty($o['customer_note'])) {
        $pdf->Ln(6);
        $pdf->SetFont('Helvetica', 'B', 9);
        $pdf->Cell(0, 5, 'CUSTOMER NOTE', 0, 1);
        $pdf->SetFont('Helvetica', 'I', 10);
        $pdf->MultiCell(0, 5, pdf_txt((string) $o['customer_note']), 0, 'L');
    }

    return $pdf->Output('S');
}
