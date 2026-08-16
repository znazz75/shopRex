<?php

namespace ShopRex\Services;

/**
 * Admin -> Finance -> Annual Report: a printable/downloadable PDF listing
 * every paid, non-test order in one calendar year, same hand-built
 * Services\SimplePdf approach InvoiceGenerator/PdfDocumentGenerator
 * already use (no new dependency).
 *
 * Unlike InvoiceGenerator, this is never saved to disk/a DB row - it's a
 * point-in-time report regenerated fresh on every request
 * (Controllers\Admin\FinanceAdminController::annualReport()), so it
 * doesn't need that class's idempotency/reuse machinery. It's also always
 * generated live inside an authenticated admin request, so - unlike
 * InvoiceGenerator/Mailer, which must lock their text to the ORDER's own
 * language regardless of who's viewing them later - using the site-wide
 * __() helper here is fine: it renders in whichever language the admin
 * generating the report is currently browsing in, which is exactly the
 * right one for an admin-only report.
 */
final class AnnualReportGenerator
{
    /**
     * @param array $orders Every paid, non-test order in $year - each row
     *        needs order_number, created_at, customer_email, subtotal,
     *        shipping_cost, tax_total, total (see FinanceAdminController::annualReport()'s query).
     */
    public function generate(int $year, array $orders): string
    {
        $pdf = new SimplePdf();
        $margin = 40;
        $width = $pdf->pageWidth();
        $pageBottom = 70;
        $y = $pdf->pageHeight() - $margin;

        $shopName = getSetting('shop_name', SITE_NAME);
        $pdf->text($margin, $y, $shopName, 16, true);
        $y -= 22;
        $pdf->text($margin, $y, __('admin.finance.annual_report.title', ['year' => $year]), 13, true);
        $y -= 16;
        $pdf->text($margin, $y, __('admin.finance.annual_report.generated_at', ['date' => formatLocalDate(date('Y-m-d H:i:s'), true)]), 9, false, [0.4, 0.4, 0.4]);
        $y -= 24;

        // Column x-positions measured backwards from the right margin,
        // same approach InvoiceGenerator's item table uses, so the table's
        // right edge always lines up regardless of page width.
        $colNumber = $margin;
        $colDate = $margin + 130;
        $colCustomer = $margin + 200;
        $colSubtotal = $width - $margin - 190;
        $colShipping = $width - $margin - 130;
        $colTax = $width - $margin - 70;
        $colTotal = $width - $margin - 0;

        $drawHeader = function () use ($pdf, &$y, $margin, $width, $colNumber, $colDate, $colCustomer, $colSubtotal, $colShipping, $colTax, $colTotal) {
            $pdf->text($colNumber, $y, __('admin.dashboard.order_number'), 9, true);
            $pdf->text($colDate, $y, __('common.date'), 9, true);
            $pdf->text($colCustomer, $y, __('admin.dashboard.customer'), 9, true);
            $pdf->text($colSubtotal - 40, $y, __('common.subtotal'), 9, true);
            $pdf->text($colShipping - 40, $y, __('common.shipping'), 9, true);
            $pdf->text($colTax - 40, $y, __('common.tax'), 9, true);
            $pdf->text($colTotal - 45, $y, __('common.total'), 9, true);
            $y -= 6;
            $pdf->line($margin, $y, $width - $margin, $y, 0.75, [0.3, 0.3, 0.3]);
            $y -= 14;
        };
        $drawHeader();

        $subtotalSum = 0.0;
        $shippingSum = 0.0;
        $taxSum = 0.0;
        $totalSum = 0.0;

        foreach ($orders as $order) {
            if ($y < $pageBottom + 30) {
                $pdf->addPage();
                $y = $pdf->pageHeight() - $margin;
                $drawHeader();
            }

            $pdf->text($colNumber, $y, $order['order_number'], 9);
            $pdf->text($colDate, $y, formatLocalDate($order['created_at']), 9);
            // Customer email is often too wide for its column - wrapText()'s
            // first line only, same "truncate rather than overlap the next
            // column" tradeoff a fixed-width table needs somewhere.
            $customerLines = $pdf->wrapText($order['customer_email'], $colSubtotal - $colCustomer - 10, 9);
            $pdf->text($colCustomer, $y, $customerLines[0] ?? '', 9);
            $pdf->text($colSubtotal - 40, $y, formatPrice((float)$order['subtotal']), 9);
            $pdf->text($colShipping - 40, $y, formatPrice((float)$order['shipping_cost']), 9);
            $pdf->text($colTax - 40, $y, formatPrice((float)$order['tax_total']), 9);
            $pdf->text($colTotal - 45, $y, formatPrice((float)$order['total']), 9);
            $y -= 14;

            $subtotalSum += (float)$order['subtotal'];
            $shippingSum += (float)$order['shipping_cost'];
            $taxSum += (float)$order['tax_total'];
            $totalSum += (float)$order['total'];
        }

        if ($y < $pageBottom + 40) {
            $pdf->addPage();
            $y = $pdf->pageHeight() - $margin;
        }
        $y -= 6;
        $pdf->line($margin, $y, $width - $margin, $y, 0.75, [0.3, 0.3, 0.3]);
        $y -= 16;
        $pdf->text($colNumber, $y, __('admin.finance.annual_report.order_count', ['count' => count($orders)]), 10, true);
        $pdf->text($colSubtotal - 40, $y, formatPrice($subtotalSum), 10, true);
        $pdf->text($colShipping - 40, $y, formatPrice($shippingSum), 10, true);
        $pdf->text($colTax - 40, $y, formatPrice($taxSum), 10, true);
        $pdf->text($colTotal - 45, $y, formatPrice($totalSum), 10, true);

        return $pdf->output();
    }
}
