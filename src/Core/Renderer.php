<?php

namespace ShopRex\Core;

/**
 * The dedicated rendering class. Mirrors the exact shape of what every
 * storefront page does today:
 *
 *   require themeTemplatePath('header.php');
 *   ... page body ...
 *   require themeTemplatePath('footer.php');
 *
 * render() = header slot + a (non-themeable) view file + footer slot.
 * slot() exposes a single themeable piece on its own - today only 'home'
 * needs this (index.php shows header + home.php + footer for the
 * homepage, a category listing, AND search results, branching internally
 * on the data handed to it) - kept generic so a future feature can
 * register a new overridable slot without changing this class.
 * partial() is for reusable fragments (product card, pagination) that
 * don't go through the theme package at all.
 *
 * Templates keep using the same extract()-into-scope style they use today
 * (`<?= e($product['name']) ?>`) - see src/view-helpers.php for the small,
 * fixed set of global functions kept alive for that reason.
 */
final class Renderer
{
    public function __construct(
        private readonly string $viewsDir,
        private readonly ThemeManager $theme,
    ) {
    }

    public function theme(): ThemeManager
    {
        return $this->theme;
    }

    public function render(string $view, array $data = []): string
    {
        ob_start();
        extract($data, EXTR_SKIP);
        require $this->theme->resolve('header.php');
        require $this->viewPath($view);
        require $this->theme->resolve('footer.php');
        return (string)ob_get_clean();
    }

    /** Render a themeable slot on its own, with no surrounding header/footer. */
    public function slot(string $slot, array $data = []): string
    {
        ob_start();
        extract($data, EXTR_SKIP);
        require $this->theme->resolve($slot . '.php');
        return (string)ob_get_clean();
    }

    /** Render header + a themeable slot + footer in one call (index.php's common case). */
    public function renderSlot(string $slot, array $data = []): string
    {
        ob_start();
        extract($data, EXTR_SKIP);
        require $this->theme->resolve('header.php');
        require $this->theme->resolve($slot . '.php');
        require $this->theme->resolve('footer.php');
        return (string)ob_get_clean();
    }

    /** Reusable fragment with its own scope, not part of the theme package system. */
    public function partial(string $name, array $data = []): string
    {
        ob_start();
        extract($data, EXTR_SKIP);
        require $this->viewsDir . '/partials/' . $name . '.php';
        return (string)ob_get_clean();
    }

    /**
     * A view with no header/footer wrap at all - for the rare page that
     * renders its own complete <html> document instead of slotting into
     * the normal chrome (Admin -> Login: it must render before an admin
     * session exists at all, so admin/includes/header.php's sidebar - which
     * assumes a logged-in admin - can't wrap it).
     */
    public function renderStandalone(string $view, array $data = []): string
    {
        ob_start();
        extract($data, EXTR_SKIP);
        require $this->viewPath($view);
        return (string)ob_get_clean();
    }

    private function viewPath(string $view): string
    {
        return $this->viewsDir . '/' . $view . '.php';
    }
}
