<?php

namespace ShopRex\Models;

use ShopRex\Core\Model;

/**
 * A downloadable legal document (Admin -> Legal Documents), either an
 * uploaded PDF file or one rendered on the fly from admin-typed text via
 * Services\PdfDocumentGenerator. `type` is an open-ended string (not an
 * ENUM) so a new document type never needs a schema change.
 */
class LegalDocument extends Model
{
    protected static string $table = 'legal_documents';

    // Open-ended category string (e.g. "terms", "privacy-policy", "returns")
    // rather than a fixed ENUM - see class docblock for why.
    public string $type = '';
    public string $language = 'en';
    public string $title = '';
    // Either 'uploaded' (a real PDF file was uploaded) or 'generated' (built
    // from admin-typed text via PdfDocumentGenerator) - decides which of the
    // two path fields below is actually used, see resolvedPdfPath().
    public string $sourceMode = 'uploaded';
    // Filesystem path to the uploaded PDF, when sourceMode is 'uploaded'.
    public ?string $filePath = null;
    // The raw admin-typed text, when sourceMode is 'generated' - kept even
    // after the PDF is rendered so the admin can re-edit and regenerate it.
    public ?string $generatedText = null;
    // Filesystem path to the PDF rendered from $generatedText.
    public ?string $generatedPdfPath = null;
    public ?string $updatedAt = null;
    public ?string $createdAt = null;

    /** Looks up one legal document by type in the visitor's language, falling back to the default language and then to any language, same idea as Page::findForSlugAndLanguage() - so /legal/{type} always resolves to something if any version of that document exists. */
    public static function findForTypeAndLanguage(string $type, string $lang, \PDO $pdo, string $defaultLang = 'en'): ?self
    {
        $stmt = $pdo->prepare('SELECT * FROM legal_documents WHERE type = ? AND language = ?');
        $stmt->execute([$type, $lang]);
        $row = $stmt->fetch();

        if (!$row && $lang !== $defaultLang) {
            $stmt->execute([$type, $defaultLang]);
            $row = $stmt->fetch();
        }
        if (!$row) {
            $stmt = $pdo->prepare('SELECT * FROM legal_documents WHERE type = ? LIMIT 1');
            $stmt->execute([$type]);
            $row = $stmt->fetch();
        }

        return $row ? (new self())->fill($row) : null;
    }

    /** Absolute filesystem path to whichever PDF this document currently resolves to. */
    public function resolvedPdfPath(): ?string
    {
        return $this->sourceMode === 'generated' ? $this->generatedPdfPath : $this->filePath;
    }

    /**
     * One row per distinct `type` that has at least one document, for
     * building a storefront listing of what's available at /legal/{type} -
     * `type` is admin-defined free text (see the class docblock), so the
     * storefront can't hardcode which ones exist. Same current-language ->
     * default-language -> any fallback as findForTypeAndLanguage(), just
     * computed for every type at once instead of a single lookup.
     */
    public static function allForLanguage(\PDO $pdo, string $lang, string $defaultLang = 'en'): array
    {
        $rows = $pdo->query('SELECT type, language, title FROM legal_documents ORDER BY type, language')->fetchAll();

        // Groups the flat row list into [type => [language => row]] so each
        // distinct document type's available languages can be looked at together.
        $byType = [];
        foreach ($rows as $row) {
            $byType[$row['type']][$row['language']] = $row;
        }

        $result = [];
        foreach ($byType as $type => $byLang) {
            // Same fallback order as findForTypeAndLanguage(): visitor's
            // language, else the site default, else whatever language
            // happens to exist first (reset() grabs the first array value).
            $best = $byLang[$lang] ?? $byLang[$defaultLang] ?? reset($byLang);
            $result[] = ['type' => $type, 'title' => $best['title']];
        }
        return $result;
    }

    /** Inserts this document, or updates the existing row if one already exists for the same (type, language) pair - lets the admin save form just always "save", without needing to separately branch on create-vs-edit. */
    public function upsert(\PDO $pdo): void
    {
        // ON DUPLICATE KEY UPDATE relies on a unique constraint over
        // (type, language) in the schema - see sql/schema.sql.
        $stmt = $pdo->prepare(
            'INSERT INTO legal_documents (type, language, title, source_mode, file_path, generated_text, generated_pdf_path)
             VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE title = VALUES(title), source_mode = VALUES(source_mode),
                 file_path = VALUES(file_path), generated_text = VALUES(generated_text), generated_pdf_path = VALUES(generated_pdf_path)'
        );
        $stmt->execute([
            $this->type, $this->language, $this->title, $this->sourceMode,
            $this->filePath, $this->generatedText, $this->generatedPdfPath,
        ]);
    }
}
