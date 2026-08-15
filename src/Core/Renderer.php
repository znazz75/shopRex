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
 *
 * In plain terms: this class turns "a template file name + an array of
 * data" into a finished HTML string. It's the one place that knows how to
 * stitch together a page's header, body, and footer, and it's what makes
 * every controller's render() call work without controllers needing to know
 * anything about themes, layout files, or PHP's output-buffering functions.
 */
final class Renderer
{
    public function __construct(
        // Base folder that plain (non-themeable) view files and partials
        // live under (src/Views/storefront or src/Views/admin) - views are
        // located relative to this.
        private readonly string $viewsDir,
        // Used to resolve which actual file answers a themeable slot like
        // 'header.php' - see Core\ThemeManager's docblock.
        private readonly ThemeManager $theme,
    ) {
    }

    /** Exposes the underlying ThemeManager - used e.g. by the admin settings screen to list/switch installed theme packages. */
    public function theme(): ThemeManager
    {
        return $this->theme;
    }

    /**
     * Renders a full page: the active theme's header, then the given view
     * file's own markup, then the active theme's footer - this is what a
     * normal controller's $this->render(...) call ultimately calls.
     */
    public function render(string $view, array $data = []): string
    {
        // Output buffering captures everything the required files echo
        // (rather than sending it straight to the browser) so it can be
        // returned as a string and wrapped in a Response by the caller,
        // instead of being written out immediately.
        ob_start();
        // EXTR_SKIP turns each $data array key into a real local variable
        // in this function's scope (e.g. $data['product'] becomes
        // $product) without overwriting any variable that already exists
        // here (like $view or $this) - this is what lets a template file
        // reference e.g. $product directly instead of $data['product'].
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

    /** Builds the full filesystem path to a plain (non-themeable) view file from its dotless name, e.g. 'product/show' -> '.../product/show.php'. */
    private function viewPath(string $view): string
    {
        return $this->viewsDir . '/' . $view . '.php';
    }
}
