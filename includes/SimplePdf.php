<?php
/**
 * Minimal, dependency-free PDF writer - just enough to lay out a simple
 * document like an invoice: text at an absolute position (core Helvetica /
 * Helvetica-Bold fonts via WinAnsiEncoding, which covers German umlauts/ß
 * and other Latin-1 text), thin lines, and filled rectangles, across one or
 * more A4 pages. This is not a general-purpose PDF library - no images, no
 * custom fonts, no exact text-width metrics (wrapText() estimates).
 *
 * Why this class still exists as-is: one of the "legacy classes kept
 * as-is" (see CLAUDE.md) - it was already a proper, single-purpose class
 * before the OOP rewrite, so it's `require_once`'d as-is from
 * src/container.php rather than ported into the ShopRex\ namespace.
 * includes/InvoiceGenerator.php is the only caller, using it to lay out
 * the invoice PDF page by page.
 *
 * Background for newcomers: a PDF file is plain text/bytes structured as a
 * series of numbered "objects" (fonts, pages, the content actually drawn
 * on each page, ...) that reference each other by object number, plus a
 * "xref" (cross-reference) table at the end that tells a PDF reader the
 * byte offset of every object so it can jump straight to any of them. Each
 * page's visible content is itself a tiny bytecode-like language of its
 * own (a "content stream") - short operators like `rg` (set fill color),
 * `Tf`/`Tj` (choose font / show text), `re` (rectangle), `m`/`l`/`S` (move
 * to / line to / stroke) - which is what text()/line()/rect() below build
 * up, and output() at the bottom assembles into the final object/xref
 * structure a PDF reader expects.
 */
class SimplePdf
{
    private const PAGE_WIDTH = 595.28;  // A4, in points (1/72 inch)
    private const PAGE_HEIGHT = 841.89;

    /** @var array<int, array<int, string>> content-stream operators per page - one inner array of raw PDF drawing commands (see class docblock) for each page added so far. */
    private array $pages = [];
    // Index into $pages of the page currently being drawn to - starts at
    // -1 so the very first addPage() call in the constructor brings it to 0.
    private int $currentPage = -1;

    /** Starts the document with one blank page ready to draw on. */
    public function __construct()
    {
        $this->addPage();
    }

    /** Appends a new blank page and makes it the current page every subsequent text()/line()/rect() call draws to - used when content overflows the current page (see InvoiceGenerator's page-break checks). */
    public function addPage(): void
    {
        $this->pages[] = [];
        $this->currentPage++;
    }

    /** A4 page width in points (1/72 inch) - callers use this to right-align columns etc. relative to the page edge. */
    public function pageWidth(): float
    {
        return self::PAGE_WIDTH;
    }

    /** A4 page height in points (1/72 inch) - callers use this to know where the top of a fresh page starts. */
    public function pageHeight(): float
    {
        return self::PAGE_HEIGHT;
    }

    /**
     * Draw $text with its baseline at ($x, $y), measured from the
     * bottom-left of the page (PDF's native coordinate system).
     */
    public function text(float $x, float $y, string $text, float $size = 10, bool $bold = false, array $rgb = [0, 0, 0]): void
    {
        // '/F1' and '/F2' are the two font resource names this class
        // always registers (see output() below) - regular and bold
        // Helvetica respectively.
        $font = $bold ? '/F2' : '/F1';
        [$r, $g, $b] = $rgb;
        // Raw PDF content-stream operators (see class docblock): `q`/`Q`
        // save/restore the graphics state so this text's color doesn't
        // leak into whatever's drawn next; `rg` sets the fill color;
        // `BT`/`ET` bracket a text-showing block; `Tf` selects font+size;
        // `Tm` sets the text position matrix (here, a plain translate to
        // $x,$y with no rotation/scaling); `Tj` actually shows the string.
        $this->pages[$this->currentPage][] = sprintf(
            "q %.3f %.3f %.3f rg BT %s %.2f Tf 1 0 0 1 %.2f %.2f Tm (%s) Tj ET Q\n",
            $r, $g, $b, $font, $size, $x, $y, $this->escapeText($text)
        );
    }

    /** Draws a straight line from ($x1,$y1) to ($x2,$y2) - used for table divider rules on the invoice. */
    public function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.5, array $rgb = [0.8, 0.8, 0.8]): void
    {
        [$r, $g, $b] = $rgb;
        // `RG` sets the *stroke* (line) color (as opposed to `rg` for
        // fill, used by text()/rect()); `w` sets line width; `m`/`l` move
        // to the start point / draw a line to the end point; `S` strokes
        // (actually paints) the path just defined.
        $this->pages[$this->currentPage][] = sprintf(
            "q %.3f %.3f %.3f RG %.2f w %.2f %.2f m %.2f %.2f l S Q\n",
            $r, $g, $b, $width, $x1, $y1, $x2, $y2
        );
    }

    /** Draws a solid filled rectangle with its bottom-left corner at ($x,$y) - not currently used by InvoiceGenerator but available for shaded backgrounds etc. */
    public function rect(float $x, float $y, float $w, float $h, array $rgb = [0.95, 0.95, 0.95]): void
    {
        [$r, $g, $b] = $rgb;
        // `re` defines a rectangle path at (x,y) sized w x h; `f` fills it
        // with the color set by `rg`.
        $this->pages[$this->currentPage][] = sprintf(
            "q %.3f %.3f %.3f rg %.2f %.2f %.2f %.2f re f Q\n",
            $r, $g, $b, $x, $y, $w, $h
        );
    }

    /**
     * Rough line-wrap for a block of text into an array of lines that
     * should each fit within $maxWidth at $size - based on an average
     * character-width estimate for Helvetica, not exact glyph metrics, but
     * close enough for invoice-style layout.
     */
    public function wrapText(string $text, float $maxWidth, float $size = 10): array
    {
        // Helvetica characters average roughly half as wide as they are
        // tall - not exact per-glyph metrics (this class doesn't embed
        // font metric data), but close enough to avoid visibly overflowing
        // a column on an invoice.
        $avgCharWidth = $size * 0.5;
        $maxChars = max(1, (int)floor($maxWidth / $avgCharWidth));

        // Split on whitespace (the \s+/u pattern is Unicode-aware) so
        // wrapping happens at word boundaries, never mid-word.
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            // Try adding the next word to the current line; if that would
            // push it past the character budget, close off the current
            // line as-is and start a new one with just this word.
            $candidate = $line === '' ? $word : $line . ' ' . $word;
            if (mb_strlen($candidate) > $maxChars && $line !== '') {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }
        if ($line !== '') {
            $lines[] = $line;
        }
        // Always return at least one (possibly empty) line, so callers
        // that index [0] (e.g. InvoiceGenerator's `$j === 0` check) never
        // hit an undefined-index error on an empty $text.
        return $lines ?: [''];
    }

    /** Converts $text to the WinAnsiEncoding bytes PDF text-showing operators expect, and escapes the three characters ( ) \ that would otherwise be misread as PDF string syntax. */
    private function escapeText(string $text): string
    {
        // WinAnsiEncoding (~= Windows-1252 / Latin-1) covers German
        // umlauts/ß and the euro sign without embedding a custom font.
        // iconv's //TRANSLIT suffix approximates any character that has no
        // exact Windows-1252 equivalent (e.g. a curly quote) instead of
        // failing outright.
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
        if ($converted === false) {
            // iconv can fail on some inputs/builds - fall back to PHP's
            // own (stricter, non-transliterating) encoding conversion
            // rather than leaving invalid bytes in the PDF stream.
            $converted = mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
        }
        // Backslash-escape PDF string syntax's special characters. Order
        // matters: backslash itself must be escaped first, otherwise the
        // backslashes just added in front of ( and ) would themselves get
        // doubled by this same replacement.
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $converted);
    }

    /**
     * Serializes every page into raw PDF bytes, ready to be written straight
     * to a .pdf file or streamed to the browser. Builds the full object
     * graph a minimal PDF needs (catalog, page tree, fonts, one page object
     * + one content-stream object per page added), then a byte-offset xref
     * table and trailer pointing back at it all, per the PDF file format
     * spec - see the class docblock for the general shape of a PDF file.
     */
    public function output(): string
    {
        // Object numbers 1 and 2 are the catalog and page tree (fixed
        // below); 3 and 4 are the two fonts. Every page needs two more
        // objects of its own (a /Page object + its /Contents stream), so
        // numbering continues from 5 upward, two per page.
        $fontRegularId = 3;
        $fontBoldId = 4;
        $pageObjIds = [];
        $contentObjIds = [];
        $nextId = 5;
        foreach ($this->pages as $i => $ops) {
            $pageObjIds[$i] = $nextId++;
            $contentObjIds[$i] = $nextId++;
        }

        // Build every PDF object as a string, keyed by its object number.
        $objects = [];
        // Object 1: the document catalog - the root of the whole document,
        // pointing at the page tree (object 2).
        $objects[1] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        // Object 2: the page tree - lists every page object as a "kid" in
        // page order, so a PDF reader knows how many pages there are and
        // in what sequence.
        $kids = implode(' ', array_map(fn($id) => "$id 0 R", $pageObjIds));
        $objects[2] = "2 0 obj\n<< /Type /Pages /Kids [$kids] /Count " . count($this->pages) . " >>\nendobj\n";
        // Objects 3/4: the two standard (no embedding needed) fonts every
        // page's /Resources dictionary below references as /F1 and /F2.
        $objects[3] = "3 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>\nendobj\n";
        $objects[4] = "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>\nendobj\n";

        // One /Page object (its size + which fonts/content it uses) and one
        // /Contents object (its actual drawing commands, concatenated from
        // every text()/line()/rect() call made on that page) per page.
        foreach ($this->pages as $i => $ops) {
            $pid = $pageObjIds[$i];
            $cid = $contentObjIds[$i];
            $objects[$pid] = "$pid 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 "
                . self::PAGE_WIDTH . ' ' . self::PAGE_HEIGHT
                . "] /Resources << /Font << /F1 $fontRegularId 0 R /F2 $fontBoldId 0 R >> >> /Contents $cid 0 R >>\nendobj\n";
            $stream = implode('', $ops);
            $objects[$cid] = "$cid 0 obj\n<< /Length " . strlen($stream) . " >>\nstream\n$stream\nendstream\nendobj\n";
        }

        // Objects must appear in the file in ascending numeric order for
        // the offsets computed below to correctly describe where each one
        // starts.
        ksort($objects);

        // Write the file header, then every object in order, remembering
        // each one's exact byte offset from the start of the file - the
        // xref table below needs these offsets so a PDF reader can jump
        // straight to any object without scanning the whole file.
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $body;
        }

        // The cross-reference (xref) table: one fixed-width 20-byte line
        // per object number giving its byte offset and generation number.
        // Entry 0 is always the special "free list head" placeholder PDF
        // requires. The trailer then points readers at the xref table's
        // own offset ($xrefStart) and the root catalog (object 1), which
        // is where a PDF reader actually starts parsing from.
        $xrefStart = strlen($pdf);
        $maxId = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($maxId + 1) . "\n0000000000 65535 f \n";
        for ($id = 1; $id <= $maxId; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
        }
        $pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root 1 0 R >>\nstartxref\n$xrefStart\n%%EOF";

        return $pdf;
    }
}
