<?php

namespace ShopRex\Controllers\Storefront;

use ShopRex\Core\Container;
use ShopRex\Core\Controller;
use ShopRex\Core\Request;
use ShopRex\Core\Response;
use ShopRex\Models\Page;
use ShopRex\Services\I18n;
use ShopRex\Services\SettingsRepository;

/**
 * Renders an admin-authored CMS page (e.g. "About Us", "Terms of Service")
 * by its URL slug. CMS page content is rendered as trusted, unescaped
 * HTML by design - see CLAUDE.md's "Security posture" section: any admin
 * who can edit Pages (Super Admin or Manager) can inject markup/scripts
 * here, and that's an accepted, documented trust boundary, not a bug.
 * Direct port of page.php.
 */
final class PageController extends Controller
{
    /** Looks up and renders the page matching the URL's slug, in the visitor's current language (with a language fallback baked into Page::findForSlugAndLanguage() - see that method). Shows a 404 page if no matching slug exists in any language. */
    public function show(Request $request): Response
    {
        $slug = (string)$request->routeParam('slug', '');
        $lang = I18n::current();
        $settings = $this->container->make(SettingsRepository::class);

        $page = Page::findForSlugAndLanguage($slug, $lang, $settings);

        if (!$page) {
            $html = $this->view->render('page/not_found', ['pageTitle' => __('page.not_found_title')]);
            return Response::html($html, 404);
        }

        $html = $this->view->render('page/show', ['page' => $page, 'pageTitle' => $page->title]);
        return Response::html($html);
    }
}
