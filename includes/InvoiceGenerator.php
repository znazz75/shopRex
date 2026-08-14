<?php
/**
 * Builds a VAT-aware PDF invoice for an order, in the order's language.
 * Saved under uploads/invoices/ (never directly web-accessible - see
 * uploads/invoices/.htaccess - always served through invoice_download.php,
 * which checks the requester owns the order or is an admin).
 */
class InvoiceGenerator
{
    private const LABELS = [
        'en' => [
            'invoice' => 'Invoice', 'date' => 'Date', 'order_number' => 'Order number',
            'billing_address' => 'Billing address', 'item' => 'Item', 'qty' => 'Qty',
            'net_price' => 'Net Price', 'vat' => 'VAT', 'total' => 'Total',
            'subtotal' => 'Subtotal', 'shipping' => 'Shipping', 'grand_total' => 'Total',
            'vat_on' => 'VAT', 'test_notice' => 'TEST ORDER - NO REAL PAYMENT WAS PROCESSED',
            'thank_you' => 'Thank you for your order!',
        ],
        'de' => [
            'invoice' => 'Rechnung', 'date' => 'Datum', 'order_number' => 'Bestellnummer',
            'billing_address' => 'Rechnungsadresse', 'item' => 'Artikel', 'qty' => 'Menge',
            'net_price' => 'Nettopreis', 'vat' => 'MwSt.', 'total' => 'Gesamt',
            'subtotal' => 'Zwischensumme', 'shipping' => 'Versand', 'grand_total' => 'Gesamtsumme',
            'vat_on' => 'MwSt.', 'test_notice' => 'TESTBESTELLUNG - ES WURDE KEINE ECHTE ZAHLUNG VERARBEITET',
            'thank_you' => 'Vielen Dank für Ihre Bestellung!',
        ],
    ];

    /**
     * Generate the PDF, save it, and record it in the invoices table.
     * Safe to call more than once for the same order (e.g. a retry) -
     * replaces the previous file/row rather than accumulating duplicates.
     */
    public static function generateForOrder(array $order, array $items): array
    {
        $language = in_array($order['language'] ?? 'en', array_keys(self::LABELS), true) ? $order['language'] : 'en';
        $t = self::LABELS[$language];

        $invoiceNumber = sprintf('INV-%s-%06d', date('Y'), $order['id']);

        $pdf = new SimplePdf();
        $margin = 50;
        $width = $pdf->pageWidth();
        $pageBottom = 70;
        $y = $pdf->pageHeight() - $margin;

        $shopName = getSetting('shop_name', SITE_NAME);
        $pdf->text($margin, $y, $shopName, 18, true);
        $y -= 10;

        if (!empty($order['is_test_order'])) {
            $pdf->text($margin, $y - 14, $t['test_notice'], 9, true, [0.7, 0, 0]);
            $y -= 28;
        } else {
            $y -= 18;
        }

        $pdf->text($margin, $y, $t['invoice'] . ' ' . $invoiceNumber, 13, true);
        $y -= 16;
        $pdf->text($margin, $y, $t['date'] . ': ' . formatLocalDate($order['created_at'] ?? date('Y-m-d H:i:s')), 10);
        $y -= 13;
        $pdf->text($margin, $y, $t['order_number'] . ': ' . $order['order_number'], 10);
        $y -= 28;

        $pdf->text($margin, $y, $t['billing_address'] . ':', 10, true);
        $y -= 14;
        foreach (array_filter([
            $order['shipping_name'] ?? '',
            $order['shipping_address1'] ?? '',
            $order['shipping_address2'] ?? '',
            trim(($order['shipping_postal_code'] ?? '') . ' ' . ($order['shipping_city'] ?? '')),
            $order['shipping_country'] ?? '',
        ]) as $line) {
            $pdf->text($margin, $y, $line, 10);
            $y -= 13;
        }
        $y -= 15;

        // Table columns
        $colItem = $margin;
        $colQty = $width - $margin - 260;
        $colPrice = $width - $margin - 205;
        $colTax = $width - $margin - 110;
        $colTotal = $width - $margin - 60;

        $drawTableHeader = function () use ($pdf, &$y, $margin, $width, $colItem, $colQty, $colPrice, $colTax, $colTotal, $t) {
            $pdf->text($colItem, $y, $t['item'], 10, true);
            $pdf->text($colQty, $y, $t['qty'], 10, true);
            $pdf->text($colPrice, $y, $t['net_price'], 10, true);
            $pdf->text($colTax, $y, $t['vat'], 10, true);
            $pdf->text($colTotal, $y, $t['total'], 10, true);
            $y -= 6;
            $pdf->line($margin, $y, $width - $margin, $y, 0.75, [0.3, 0.3, 0.3]);
            $y -= 14;
        };
        $drawTableHeader();

        $taxBreakdown = [];
        foreach ($items as $item) {
            if ($y < $pageBottom + 40) {
                $pdf->addPage();
                $y = $pdf->pageHeight() - $margin;
                $drawTableHeader();
            }

            foreach ($pdf->wrapText($item['product_name'], $colQty - $colItem - 10, 9) as $j => $lineText) {
                $pdf->text($colItem, $y, $lineText, 9);
                if ($j === 0) {
                    $pdf->text($colQty, $y, (string)$item['quantity'], 9);
                    $pdf->text($colPrice, $y, formatPrice((float)$item['unit_price']), 9);
                    $pdf->text($colTax, $y, formatTaxRateNumber((float)$item['tax_rate_percent']) . '%', 9);
                    $pdf->text($colTotal, $y, formatPrice((float)$item['total_price']), 9);
                }
                $y -= 12;
            }
            if (!empty($item['option_summary'])) {
                $pdf->text($colItem + 8, $y, $item['option_summary'], 8, false, [0.45, 0.45, 0.45]);
                $y -= 12;
            }

            $rate = (float)$item['tax_rate_percent'];
            if ($rate > 0) {
                $taxBreakdown[$rate] = ($taxBreakdown[$rate] ?? 0) + (float)$item['tax_amount'];
            }
            $y -= 4;
        }
        ksort($taxBreakdown);

        $y -= 6;
        $pdf->line($margin, $y, $width - $margin, $y, 0.75, [0.3, 0.3, 0.3]);
        $y -= 18;

        if ($y < $pageBottom + 100) {
            $pdf->addPage();
            $y = $pdf->pageHeight() - $margin;
        }

        $pdf->text($colTax, $y, $t['subtotal'] . ':', 10);
        $pdf->text($colTotal, $y, formatPrice((float)$order['subtotal']), 10);
        $y -= 15;

        foreach ($taxBreakdown as $rate => $amount) {
            $pdf->text($colTax, $y, $t['vat_on'] . ' ' . formatTaxRateNumber($rate) . '%:', 10);
            $pdf->text($colTotal, $y, formatPrice($amount), 10);
            $y -= 15;
        }

        $shippingLabel = !empty($order['shipping_method_name']) ? $t['shipping'] . ' (' . $order['shipping_method_name'] . '):' : $t['shipping'] . ':';
        $pdf->text($colTax, $y, $shippingLabel, 10);
        $pdf->text($colTotal, $y, formatPrice((float)$order['shipping_cost']), 10);
        $y -= 20;

        $pdf->line($colTax - 10, $y + 10, $width - $margin, $y + 10, 0.75, [0.3, 0.3, 0.3]);
        $pdf->text($colTax, $y, $t['grand_total'] . ':', 12, true);
        $pdf->text($colTotal, $y, formatPrice((float)$order['total']), 12, true);
        $y -= 30;

        $pdf->text($margin, $y, $t['thank_you'], 10);

        if (!is_dir(INVOICE_DIR)) {
            @mkdir(INVOICE_DIR, 0755, true);
        }
        $filePath = INVOICE_DIR . $invoiceNumber . '.pdf';
        file_put_contents($filePath, $pdf->output());

        // invoice_number is derived deterministically from the order id, so
        // calling this twice for the same order (e.g. a retry) hits the
        // UNIQUE constraint on invoice_number and cleanly updates the
        // existing row/file in place instead of erroring or duplicating.
        $stmt = db()->prepare(
            'INSERT INTO invoices (order_id, invoice_number, language, pdf_path) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE language = VALUES(language), pdf_path = VALUES(pdf_path)'
        );
        $stmt->execute([$order['id'], $invoiceNumber, $language, $filePath]);

        return ['invoice_number' => $invoiceNumber, 'pdf_path' => $filePath];
    }
}
