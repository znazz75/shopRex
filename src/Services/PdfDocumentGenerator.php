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
    /** Turns a plain-text title + body into a simple paginated PDF (bold title, then word-wrapped body paragraphs) and returns the raw PDF file bytes - used when an admin types a legal document's text directly instead of uploading a ready-made PDF. */
    public function generate(string $title, string $bodyText): string
    {
        $pdf = new SimplePdf();
        $margin = 50;
        $width = $pdf->pageWidth();
        // How close to the bottom of the page text is allowed to get before
        // a new page is started - leaves a bit of breathing room below the
        // last line rather than printing right to the page edge.
        $pageBottom = 60;
        // $y tracks the current vertical "cursor" position, counting DOWN
        // from the top of the page (PDF coordinates start at the bottom, so
        // this decreases as content is added).
        $y = $pdf->pageHeight() - $margin;

        $pdf->text($margin, $y, $title, 16, true);
        $y -= 26;

        // Body text is split on blank lines into paragraphs first...
        foreach (explode("\n", $bodyText) as $paragraph) {
            // ...and an empty line just adds vertical spacing (a paragraph
            // break) rather than being word-wrapped into nothing.
            if (trim($paragraph) === '') {
                $y -= 12;
                continue;
            }
            // wrapText() breaks one long paragraph into multiple lines that
            // each fit within the page's printable width.
            foreach ($pdf->wrapText($paragraph, $width - 2 * $margin, 11) as $line) {
                // Ran out of room on this page - start a fresh one and reset
                // the cursor back to the top, so text never overflows off
                // the bottom of a page.
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
        // Creates the destination folder if it doesn't exist yet (e.g. first
        // legal document ever generated); the @ suppresses a warning for the
        // harmless race where another request creates it first.
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        file_put_contents($absolutePath, $this->generate($title, $bodyText));
        return $absolutePath;
    }
}
