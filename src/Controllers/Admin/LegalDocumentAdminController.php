<?php

namespace ShopRex\Controllers\Admin;

use ShopRex\Core\Container;
use ShopRex\Core\Request;
use ShopRex\Core\Response;
use ShopRex\Models\LegalDocument;
use ShopRex\Services\I18n;
use ShopRex\Services\PdfDocumentGenerator;

/**
 * New in v2.00 - manage the documents Controllers\Storefront\LegalDocumentController
 * serves at /legal/{type} (cancellation policy, warranty terms, ...), each
 * either an uploaded PDF or one rendered from typed text via
 * Services\PdfDocumentGenerator. No legacy procedural equivalent exists.
 */
final class LegalDocumentAdminController extends AdminCrudController
{
    /** Suggested out of the box - type is a free string, so this list is just a convenience, not a constraint. */
    private const SUGGESTED_TYPES = ['cancellation_policy', 'warranty_terms'];
    /** File extensions accepted for an uploaded document - just "pdf" today, kept as a list for symmetry with other upload-guard code and in case that ever needs to grow. */
    private const ALLOWED_EXTENSIONS = ['pdf'];
    /** Upper size limit for an uploaded document (10 MiB) - prevents an admin (accidentally or otherwise) from filling up disk space with an oversized upload. */
    private const MAX_FILE_BYTES = 10 * 1024 * 1024;

    /** Raw database handle for this controller's queries against `legal_documents`. */
    private readonly \PDO $pdo;
    /** Renders a typed title + body text into a PDF file on disk - the "generated" alternative to an uploaded PDF. */
    private readonly PdfDocumentGenerator $pdfGenerator;
    /** Absolute filesystem path (with trailing slash) where every legal document's PDF - uploaded or generated - is stored. */
    private readonly string $uploadDir;

    public function __construct(Request $request, Container $container)
    {
        parent::__construct($request, $container);
        $this->pdo = $container->make(\PDO::class);
        $this->pdfGenerator = $container->make(PdfDocumentGenerator::class);
        $this->uploadDir = dirname(__DIR__, 3) . '/uploads/legal_documents/';
    }

    /** GET /admin/legal-documents - lists every saved document and, if a type/lang is selected via ?type=&lang=, pre-fills the edit form with it (or a blank "generated" template if that type/language combo doesn't exist yet). */
    public function index(Request $request): Response
    {
        $lang = (string)$request->get('lang', I18n::current());
        $availableLangs = I18n::enabledLanguages();
        // A tampered/stale ?lang= that no longer maps to an enabled
        // language falls back to English rather than erroring out.
        if (!array_key_exists($lang, $availableLangs)) {
            $lang = 'en';
        }

        $documents = $this->pdo->query('SELECT * FROM legal_documents ORDER BY type, language')->fetchAll();

        $editType = $request->get('type');
        $current = null;
        if ($editType) {
            $stmt = $this->pdo->prepare('SELECT * FROM legal_documents WHERE type = ? AND language = ?');
            $stmt->execute([$editType, $lang]);
            // If no row exists yet for this type/language, hand the view a
            // blank "generated" template instead of null - lets the same
            // form markup handle both "editing an existing document" and
            // "creating one for a language that doesn't have it yet".
            $current = $stmt->fetch() ?: ['type' => $editType, 'language' => $lang, 'title' => '', 'source_mode' => 'generated', 'generated_text' => ''];
        }

        return $this->render('legal_documents/index', [
            'documents' => $documents, 'suggestedTypes' => self::SUGGESTED_TYPES,
            'lang' => $lang, 'availableLangs' => $availableLangs, 'editType' => $editType, 'current' => $current,
            'errors' => [], 'pageTitle' => __('admin.legal_documents'),
        ]);
    }

    /**
     * Creates or replaces the document for one (type, language) pair -
     * either from typed text turned into a PDF, or from an uploaded PDF
     * file, depending on which submit button the admin clicked. Because
     * type+language together identify the document (see the unique lookup
     * queries below), saving again for the same pair overwrites it rather
     * than creating a duplicate.
     */
    public function save(Request $request): Response
    {
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }

        $type = trim((string)$request->post('type', ''));
        $lang = (string)$request->post('language', 'en');
        $title = trim((string)$request->post('title', ''));
        $availableLangs = I18n::enabledLanguages();

        $errors = [];
        if ($type === '') {
            $errors[] = __('admin.legal_documents.type_required');
        }
        if ($title === '') {
            $errors[] = __('admin.legal_documents.title_required');
        }
        if (!array_key_exists($lang, $availableLangs)) {
            $lang = 'en';
        }

        $doc = new LegalDocument();
        $doc->type = $type;
        $doc->language = $lang;
        $doc->title = $title;

        // Two mutually exclusive submit actions - which one fired is
        // determined by which submit button's name arrived in $_POST, not
        // by a hidden mode field (matches the upload-vs-generate toggle in
        // the form, where only one "half" is actually filled in).
        if ($request->post('generate_pdf') !== null) {
            $bodyText = (string)$request->post('generated_text', '');
            if (trim($bodyText) === '') {
                $errors[] = __('admin.legal_documents.text_required');
            }
            if (!$errors) {
                if (!is_dir($this->uploadDir)) {
                    @mkdir($this->uploadDir, 0755, true);
                }
                $filename = 'legal-' . preg_replace('/[^a-z0-9-]+/i', '-', $type) . '-' . $lang . '-' . time() . '.pdf';
                $this->pdfGenerator->generateToFile($title, $bodyText, $this->uploadDir . $filename);

                $doc->sourceMode = 'generated';
                $doc->generatedText = $bodyText;
                $doc->generatedPdfPath = $filename;
                $doc->filePath = null;
            }
        } else {
            $file = $request->file('document');
            // A file was actually chosen AND PHP reports it uploaded
            // cleanly - an empty file input or a failed upload both leave
            // this false, which the checks below treat as "no new file".
            $hasNewFile = !empty($file['name']) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;

            $existing = null;
            $existingStmt = $this->pdo->prepare('SELECT * FROM legal_documents WHERE type = ? AND language = ?');
            $existingStmt->execute([$type, $lang]);
            $existing = $existingStmt->fetch();

            // A file is required unless there's already an uploaded
            // document for this type/language to fall back on (editing
            // just the title without re-uploading is allowed).
            if (!$hasNewFile && (!$existing || $existing['source_mode'] !== 'uploaded')) {
                $errors[] = __('admin.legal_documents.file_required');
            }

            if (!$errors && $hasNewFile) {
                $ext = strtolower((string)pathinfo($file['name'], PATHINFO_EXTENSION));
                // Extension + a real content sniff, matching the posture
                // already used for image uploads (docs/SECURITY_AUDIT.md
                // finding #6) - a renamed non-PDF file fails this check.
                $isPdf = $ext === 'pdf' && str_starts_with((string)file_get_contents($file['tmp_name'], false, null, 0, 5), '%PDF-');
                if (!in_array($ext, self::ALLOWED_EXTENSIONS, true) || !$isPdf || $file['size'] > self::MAX_FILE_BYTES) {
                    $errors[] = __('admin.legal_documents.file_requirements');
                } else {
                    if (!is_dir($this->uploadDir)) {
                        @mkdir($this->uploadDir, 0755, true);
                    }
                    $filename = 'legal-' . preg_replace('/[^a-z0-9-]+/i', '-', $type) . '-' . $lang . '-' . time() . '.' . $ext;
                    if (move_uploaded_file($file['tmp_name'], $this->uploadDir . $filename)) {
                        $doc->sourceMode = 'uploaded';
                        $doc->filePath = $filename;
                        $doc->generatedText = null;
                        $doc->generatedPdfPath = null;
                    } else {
                        $errors[] = __('admin.legal_documents.save_upload_error');
                    }
                }
            } elseif (!$errors) {
                // No new file chosen but an uploaded document already exists
                // for this type/language - keep it, only the title changes.
                $doc->sourceMode = 'uploaded';
                $doc->filePath = $existing['file_path'];
                $doc->generatedText = null;
                $doc->generatedPdfPath = null;
            }
        }

        if ($errors) {
            $documents = $this->pdo->query('SELECT * FROM legal_documents ORDER BY type, language')->fetchAll();
            $current = ['type' => $type, 'language' => $lang, 'title' => $title, 'source_mode' => 'generated', 'generated_text' => (string)$request->post('generated_text', '')];
            return $this->render('legal_documents/index', [
                'documents' => $documents, 'suggestedTypes' => self::SUGGESTED_TYPES,
                'lang' => $lang, 'availableLangs' => $availableLangs, 'editType' => $type, 'current' => $current,
                'errors' => $errors, 'pageTitle' => __('admin.legal_documents'),
            ]);
        }

        // Old physical file (of whichever mode this type/language previously
        // used) is cleaned up only after the new one is safely in place.
        $oldStmt = $this->pdo->prepare('SELECT file_path, generated_pdf_path FROM legal_documents WHERE type = ? AND language = ?');
        $oldStmt->execute([$type, $lang]);
        if ($old = $oldStmt->fetch()) {
            foreach (array_filter([$old['file_path'], $old['generated_pdf_path']]) as $oldFile) {
                if ($oldFile !== ($doc->filePath ?? $doc->generatedPdfPath) && is_file($this->uploadDir . basename($oldFile))) {
                    @unlink($this->uploadDir . basename($oldFile));
                }
            }
        }

        $doc->upsert($this->pdo);

        $this->flash('success', __('admin.legal_documents.flash_saved'));
        return $this->redirect('/admin/legal-documents?type=' . urlencode($type) . '&lang=' . urlencode($lang));
    }

    /** Deletes one document row and its physical PDF file (whichever of uploaded/generated it has) from disk. */
    public function delete(Request $request): Response
    {
        if ($csrfFailure = $this->requireCsrf()) {
            return $csrfFailure;
        }
        $id = (int)$request->post('delete_id', 0);
        $stmt = $this->pdo->prepare('SELECT * FROM legal_documents WHERE id = ?');
        $stmt->execute([$id]);
        if ($doc = $stmt->fetch()) {
            // array_filter() drops whichever of the two path columns is
            // null/empty - a document only ever has one of the two set, so
            // this loop effectively deletes just the one real file.
            foreach (array_filter([$doc['file_path'], $doc['generated_pdf_path']]) as $file) {
                // basename() strips any directory component from the
                // stored filename before rejoining it to $this->uploadDir -
                // guards against a path-traversal value (e.g. "../../x")
                // ever having snuck into the column escaping the intended
                // upload directory.
                $path = $this->uploadDir . basename($file);
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            $this->pdo->prepare('DELETE FROM legal_documents WHERE id = ?')->execute([$id]);
            $this->flash('success', __('admin.legal_documents.flash_deleted'));
        }
        return $this->redirect('/admin/legal-documents');
    }
}
