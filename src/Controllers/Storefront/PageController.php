<?php

namespace ShopRex\Controllers\Storefront;

use ShopRex\Core\Container;
use ShopRex\Core\Controller;
use ShopRex\Core\Request;
use ShopRex\Core\Response;
use ShopRex\Models\Page;
use ShopRex\Services\I18n;
use ShopRex\Services\SettingsRepository;

/** CMS page - direct port of page.php. */
final class PageController extends Controller
{
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
