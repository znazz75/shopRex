<?php

namespace ShopRex\Controllers\Storefront;

use ShopRex\Core\Container;
use ShopRex\Core\Controller;
use ShopRex\Core\Request;
use ShopRex\Core\Response;
use ShopRex\Models\LegalDocument;
use ShopRex\Services\I18n;
use ShopRex\Services\SettingsRepository;

/**
 * Customer-facing download for a legal document (Admin -> Legal Documents
 * manages the upload-or-generate source; that admin UI lands in Phase 7/8
 * alongside the rest of the admin panel build-out - see the architecture
 * plan). Resolves current language -> default language -> any, same
 * fallback chain as Models\Page::findForSlugAndLanguage().
 */
final class LegalDocumentController extends Controller
{
    /**
     * Streams a legal document's PDF (terms, privacy policy, etc.) for
     * public download at /legal/{type} - no login required, since these
     * are meant to be publicly readable disclosures, not customer-specific
     * documents.
     */
    public function download(Request $request): Response
    {
        $type = (string)$request->routeParam('type', '');
        $pdo = $this->container->make(\PDO::class);
        $settings = $this->container->make(SettingsRepository::class);
        $defaultLang = $settings->get('default_language', 'en');

        // Language fallback chain: current visitor language -> shop's
        // default language -> whatever exists at all - same pattern as
        // Models\Page::findForSlugAndLanguage(), so a document only
        // translated into some languages still resolves to something.
        $doc = LegalDocument::findForTypeAndLanguage($type, I18n::current(), $pdo, $defaultLang);
        if (!$doc) {
            return Response::html('Document not found.', 404);
        }

        // resolvedPdfPath() picks between an admin-uploaded PDF and one
        // generated from typed text (see Models\LegalDocument /
        // Services\PdfDocumentGenerator) - basename() strips any directory
        // components before rejoining with the fixed uploads directory, so
        // a path-traversal sequence in the stored value can't escape it.
        $relativePath = $doc->resolvedPdfPath();
        $absolutePath = $relativePath ? dirname(__DIR__, 3) . '/uploads/legal_documents/' . basename($relativePath) : null;

        if (!$absolutePath || !is_file($absolutePath)) {
            return Response::html('Document not available.', 404);
        }

        $bytes = (string)file_get_contents($absolutePath);
        return Response::html($bytes)
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'inline; filename="' . basename($absolutePath) . '"')
            // X-Content-Type-Options: nosniff stops the browser from
            // second-guessing the declared Content-Type and treating the
            // response as something else (e.g. HTML) based on sniffed content.
            ->withHeader('Content-Length', (string)strlen($bytes))
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
