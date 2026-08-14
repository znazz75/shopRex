<?php

namespace ShopRex\Services;

/**
 * Thin wrapper around the existing dependency-free SimplePdf writer
 * (already used by InvoiceGenerator - see src/container.php's docblock on
 * why that class stays as-is for now) - title + plain body text in, a
 * paginated PDF byte string out. Backs Admin -> Legal Documents' "generate
 * from typed text" option (Services\CheckoutService's InvoiceGenerator
 * call is the precedent this follows).
 */
final class PdfDocumentGenerator
{
    public function generate(string $title, string $bodyText): string
    {
        $pdf = new \SimplePdf();
        $margin = 50;
        $width = $pdf->pageWidth();
        $pageBottom = 60;
        $y = $pdf->pageHeight() - $margin;

        $pdf->text($margin, $y, $title, 16, true);
        $y -= 26;

        foreach (explode("\n", $bodyText) as $paragraph) {
            if (trim($paragraph) === '') {
                $y -= 12;
                continue;
            }
            foreach ($pdf->wrapText($paragraph, $width - 2 * $margin, 11) as $line) {
                if ($y < $pageBottom) {
                    $pdf->addPage();
                    $y = $pdf->pageHeight() - $margin;
                }
                $pdf->text($margin, $y, $line, 11);
                $y -= 15;
            }
        }

        return $pdf->output();
    }

    /** Renders and saves to $absolutePath, returning it unchanged for convenience. */
    public function generateToFile(string $title, string $bodyText, string $absolutePath): string
    {
        $dir = dirname($absolutePath);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        file_put_contents($absolutePath, $this->generate($title, $bodyText));
        return $absolutePath;
    }
}
