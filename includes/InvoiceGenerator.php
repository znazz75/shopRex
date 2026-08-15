<?php
/**
 * Builds a VAT-aware PDF invoice for an order, in the order's language.
 * Saved under uploads/invoices/ (never directly web-accessible - see
 * uploads/invoices/.htaccess - always served through invoice_download.php,
 * which checks the requester owns the order or is an admin).
 *
 * Why this class still exists as-is: one of the "legacy classes kept
 * as-is" (see CLAUDE.md) - it was already a proper, single-purpose class
 * before the OOP rewrite, so it's `require_once`'d as-is from
 * src/container.php rather than ported into the ShopRex\ namespace.
 * `Services\InvoiceService::generateForOrder()` is a thin new wrapper
 * around this exact method - callers in the new src/ code go through that
 * wrapper rather than calling InvoiceGenerator directly.
 *
 * Invoice label translations (see LABELS) cover all three of the shop's
 * languages (EN/DE/FR); an order placed in some other/disabled language
 * falls back to English invoice text via the in_array() check in
 * generateForOrder().
 */
class InvoiceGenerator
{
    // Every fixed label the PDF layout needs, per supported invoice
    // language - looked up as $t['key'] throughout generateForOrder()
    // below rather than going through the site-wide __()/i18n system,
    // since this text is baked into a downloadable file rather than
    // rendered live on a page.
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
        // Accented characters here (é, è, à, ç, ...) are all within
        // WinAnsiEncoding/Latin-1, same as German's umlauts - SimplePdf
        // renders them natively, no transliteration needed (see
        // SimplePdf's own docblock for the encoding this class relies on).
        'fr' => [
            'invoice' => 'Facture', 'date' => 'Date', 'order_number' => 'Numéro de commande',
            'billing_address' => 'Adresse de facturation', 'item' => 'Article', 'qty' => 'Qté',
            'net_price' => 'Prix net', 'vat' => 'TVA', 'total' => 'Total',
            'subtotal' => 'Sous-total', 'shipping' => 'Livraison', 'grand_total' => 'Total général',
            'vat_on' => 'TVA', 'test_notice' => "COMMANDE DE TEST - AUCUN PAIEMENT REEL N'A ETE TRAITE",
            // ^ accent-free by choice: this line is the one drawn as a
            // bold, enlarged all-caps warning banner, and dropping accents
            // on capitals in all-caps French text is a long-standing,
            // still-common typographic convention (unlike every other
            // label here, which is normal mixed-case text and keeps its
            // accents, e.g. 'Numéro de commande' below).
            'thank_you' => 'Merci pour votre commande !',
        ],
    ];

    /**
     * Generate the PDF, save it, and record it in the invoices table.
     * Safe to call more than once for the same order (e.g. a retry) -
     * replaces the previous file/row rather than accumulating duplicates.
     */
    public static function generateForOrder(array $order, array $items): array
    {
        // Fall back to English whenever the order's saved language isn't
        // one of the languages LABELS has translations for (covers en/de/fr
        // today - only reachable if a language gets disabled/removed after
        // the order was placed, or the language column is somehow blank).
        $language = in_array($order['language'] ?? 'en', array_keys(self::LABELS), true) ? $order['language'] : 'en';
        $t = self::LABELS[$language];

        // Deterministic invoice number derived purely from the order id -
        // this is what lets generateForOrder() be called again safely for
        // the same order (see docblock above): the number, and therefore
        // the file path and the UNIQUE db row, always come out identical.
        $invoiceNumber = sprintf('INV-%s-%06d', date('Y'), $order['id']);

        // Build the PDF top-to-bottom by hand: $y tracks the current
        // vertical "cursor" position and is decremented after every line
        // (SimplePdf's coordinate origin is the bottom-left of the page,
        // so moving down the page means decreasing y).
        $pdf = new SimplePdf();
        $margin = 50;
        $width = $pdf->pageWidth();
        $pageBottom = 70;
        $y = $pdf->pageHeight() - $margin;

        $shopName = getSetting('shop_name', SITE_NAME);
        $pdf->text($margin, $y, $shopName, 18, true);
        $y -= 10;

        // Stamp a red "TEST ORDER" warning near the top of test-account
        // invoices, so nobody mistakes a demo/trial order's PDF for a real
        // one (see CLAUDE.md's "Test accounts" section).
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
        // array_filter() drops any blank line (e.g. a customer with no
        // address_line2) so the address block never shows an empty gap.
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

        // Table columns - fixed x-positions measured backwards from the
        // right margin, so the table's right edge always lines up exactly
        // regardless of page width.
        $colItem = $margin;
        $colQty = $width - $margin - 260;
        $colPrice = $width - $margin - 205;
        $colTax = $width - $margin - 110;
        $colTotal = $width - $margin - 60;

        // A small reusable closure (captures $y by reference via `&$y` so
        // it can advance the shared cursor) rather than a separate method -
        // needed both here and again whenever the item table spills onto a
        // new page below.
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
            // Not enough vertical room left for another row - start a new
            // page and repeat the column headers there before continuing.
            if ($y < $pageBottom + 40) {
                $pdf->addPage();
                $y = $pdf->pageHeight() - $margin;
                $drawTableHeader();
            }

            // A long product name may need to wrap onto more than one line -
            // wrapText() splits it, and only the *first* wrapped line ($j
            // === 0) also gets the quantity/price/tax/total columns, so
            // those numbers don't repeat once per wrapped line.
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
            // A chosen option combination (e.g. "Size: M, Color: Red") is
            // printed as a smaller, greyed-out line under the product name.
            if (!empty($item['option_summary'])) {
                $pdf->text($colItem + 8, $y, $item['option_summary'], 8, false, [0.45, 0.45, 0.45]);
                $y -= 12;
            }

            // Accumulate this line's tax into the running per-rate total,
            // for the "VAT 19%: X / VAT 7%: Y" summary block below.
            $rate = (float)$item['tax_rate_percent'];
            if ($rate > 0) {
                $taxBreakdown[$rate] = ($taxBreakdown[$rate] ?? 0) + (float)$item['tax_amount'];
            }
            $y -= 4;
        }
        // Sort by rate ascending for a stable, predictable display order.
        ksort($taxBreakdown);

        $y -= 6;
        $pdf->line($margin, $y, $width - $margin, $y, 0.75, [0.3, 0.3, 0.3]);
        $y -= 18;

        // Make sure the whole totals block (subtotal + every tax line +
        // shipping + grand total) fits before starting it, rather than
        // letting it get split awkwardly across a page break partway through.
        if ($y < $pageBottom + 100) {
            $pdf->addPage();
            $y = $pdf->pageHeight() - $margin;
        }

        $pdf->text($colTax, $y, $t['subtotal'] . ':', 10);
        $pdf->text($colTotal, $y, formatPrice((float)$order['subtotal']), 10);
        $y -= 15;

        // One line per distinct tax rate present in the order (e.g. an
        // order mixing 19% and 7% VAT items shows both separately).
        foreach ($taxBreakdown as $rate => $amount) {
            $pdf->text($colTax, $y, $t['vat_on'] . ' ' . formatTaxRateNumber($rate) . '%:', 10);
            $pdf->text($colTotal, $y, formatPrice($amount), 10);
            $y -= 15;
        }

        $shippingLabel = !empty($order['shipping_method_name']) ? $t['shipping'] . ' (' . $order['shipping_method_name'] . '):' : $t['shipping'] . ':';
        $pdf->text($colTax, $y, $shippingLabel, 10);
        $pdf->text($colTotal, $y, formatPrice((float)$order['shipping_cost']), 10);
        $y -= 20;

        // Bold divider + grand total line, visually separated from the
        // subtotal/tax/shipping rows above it.
        $pdf->line($colTax - 10, $y + 10, $width - $margin, $y + 10, 0.75, [0.3, 0.3, 0.3]);
        $pdf->text($colTax, $y, $t['grand_total'] . ':', 12, true);
        $pdf->text($colTotal, $y, formatPrice((float)$order['total']), 12, true);
        $y -= 30;

        $pdf->text($margin, $y, $t['thank_you'], 10);

        // Make sure uploads/invoices/ exists before trying to write into it
        // (e.g. on a very first invoice ever generated) - @ suppresses the
        // warning if it already exists or a race with another request just
        // created it first.
        if (!is_dir(INVOICE_DIR)) {
            @mkdir(INVOICE_DIR, 0755, true);
        }
        $filePath = INVOICE_DIR . $invoiceNumber . '.pdf';
        // Serialise every page's drawing operations into raw PDF bytes
        // (see SimplePdf::output()) and write them straight to disk.
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
