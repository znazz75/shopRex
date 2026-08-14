<?php
/**
 * Minimal, dependency-free PDF writer - just enough to lay out a simple
 * document like an invoice: text at an absolute position (core Helvetica /
 * Helvetica-Bold fonts via WinAnsiEncoding, which covers German umlauts/ß
 * and other Latin-1 text), thin lines, and filled rectangles, across one or
 * more A4 pages. This is not a general-purpose PDF library - no images, no
 * custom fonts, no exact text-width metrics (wrapText() estimates).
 */
class SimplePdf
{
    private const PAGE_WIDTH = 595.28;  // A4, in points (1/72 inch)
    private const PAGE_HEIGHT = 841.89;

    /** @var array<int, array<int, string>> content-stream operators per page */
    private array $pages = [];
    private int $currentPage = -1;

    public function __construct()
    {
        $this->addPage();
    }

    public function addPage(): void
    {
        $this->pages[] = [];
        $this->currentPage++;
    }

    public function pageWidth(): float
    {
        return self::PAGE_WIDTH;
    }

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
        $font = $bold ? '/F2' : '/F1';
        [$r, $g, $b] = $rgb;
        $this->pages[$this->currentPage][] = sprintf(
            "q %.3f %.3f %.3f rg BT %s %.2f Tf 1 0 0 1 %.2f %.2f Tm (%s) Tj ET Q\n",
            $r, $g, $b, $font, $size, $x, $y, $this->escapeText($text)
        );
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.5, array $rgb = [0.8, 0.8, 0.8]): void
    {
        [$r, $g, $b] = $rgb;
        $this->pages[$this->currentPage][] = sprintf(
            "q %.3f %.3f %.3f RG %.2f w %.2f %.2f m %.2f %.2f l S Q\n",
            $r, $g, $b, $width, $x1, $y1, $x2, $y2
        );
    }

    public function rect(float $x, float $y, float $w, float $h, array $rgb = [0.95, 0.95, 0.95]): void
    {
        [$r, $g, $b] = $rgb;
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
        $avgCharWidth = $size * 0.5;
        $maxChars = max(1, (int)floor($maxWidth / $avgCharWidth));

        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $lines = [];
        $line = '';
        foreach ($words as $word) {
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
        return $lines ?: [''];
    }

    private function escapeText(string $text): string
    {
        // WinAnsiEncoding (~= Windows-1252 / Latin-1) covers German
        // umlauts/ß and the euro sign without embedding a custom font.
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
        if ($converted === false) {
            $converted = mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
        }
        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $converted);
    }

    /** Serialize every page into raw PDF bytes. */
    public function output(): string
    {
        $fontRegularId = 3;
        $fontBoldId = 4;
        $pageObjIds = [];
        $contentObjIds = [];
        $nextId = 5;
        foreach ($this->pages as $i => $ops) {
            $pageObjIds[$i] = $nextId++;
            $contentObjIds[$i] = $nextId++;
        }

        $objects = [];
        $objects[1] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
        $kids = implode(' ', array_map(fn($id) => "$id 0 R", $pageObjIds));
        $objects[2] = "2 0 obj\n<< /Type /Pages /Kids [$kids] /Count " . count($this->pages) . " >>\nendobj\n";
        $objects[3] = "3 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>\nendobj\n";
        $objects[4] = "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>\nendobj\n";

        foreach ($this->pages as $i => $ops) {
            $pid = $pageObjIds[$i];
            $cid = $contentObjIds[$i];
            $objects[$pid] = "$pid 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 "
                . self::PAGE_WIDTH . ' ' . self::PAGE_HEIGHT
                . "] /Resources << /Font << /F1 $fontRegularId 0 R /F2 $fontBoldId 0 R >> >> /Contents $cid 0 R >>\nendobj\n";
            $stream = implode('', $ops);
            $objects[$cid] = "$cid 0 obj\n<< /Length " . strlen($stream) . " >>\nstream\n$stream\nendstream\nendobj\n";
        }

        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $body;
        }

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
