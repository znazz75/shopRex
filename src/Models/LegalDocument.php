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

    public string $type = '';
    public string $language = 'en';
    public string $title = '';
    public string $sourceMode = 'uploaded';
    public ?string $filePath = null;
    public ?string $generatedText = null;
    public ?string $generatedPdfPath = null;
    public ?string $updatedAt = null;
    public ?string $createdAt = null;

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

    public function upsert(\PDO $pdo): void
    {
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
