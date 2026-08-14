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
    public function download(Request $request): Response
    {
        $type = (string)$request->routeParam('type', '');
        $pdo = $this->container->make(\PDO::class);
        $settings = $this->container->make(SettingsRepository::class);
        $defaultLang = $settings->get('default_language', 'en');

        $doc = LegalDocument::findForTypeAndLanguage($type, I18n::current(), $pdo, $defaultLang);
        if (!$doc) {
            return Response::html('Document not found.', 404);
        }

        $relativePath = $doc->resolvedPdfPath();
        $absolutePath = $relativePath ? dirname(__DIR__, 3) . '/uploads/legal_documents/' . basename($relativePath) : null;

        if (!$absolutePath || !is_file($absolutePath)) {
            return Response::html('Document not available.', 404);
        }

        $bytes = (string)file_get_contents($absolutePath);
        return Response::html($bytes)
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', 'inline; filename="' . basename($absolutePath) . '"')
            ->withHeader('Content-Length', (string)strlen($bytes))
            ->withHeader('X-Content-Type-Options', 'nosniff');
    }
}
