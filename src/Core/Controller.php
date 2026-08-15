<?php

namespace ShopRex\Core;

use ShopRex\Core\Auth\CustomerAuth;

/**
 * Base for every storefront controller. Holds the Request and Container
 * (services are pulled from the container lazily/on demand by whichever
 * concrete controller needs them - Cart, Mailer, SettingsRepository, ...
 * rather than this base class guessing what every subclass needs).
 *
 * In plain terms: this is the parent class every storefront page controller
 * (ProductController, CartController, CheckoutController, ...) extends. It
 * bundles up the handful of things nearly every controller needs to do -
 * render a view, redirect somewhere, show a flash message, check the
 * visitor is logged in, verify a CSRF token - so subclasses just call
 * $this->render(...) / $this->redirect(...) instead of re-implementing
 * this plumbing in every single controller.
 */
abstract class Controller
{
    /** The view-rendering service (turns a template name + data array into HTML) - see Core\Renderer. */
    protected readonly Renderer $view;

    /** The one-shot "flash message" queue (e.g. "Item added to cart") - see Core\FlashBag. */
    protected readonly FlashBag $flash;

    /** The CSRF token helper for this request - see Core\Csrf. */
    protected readonly Csrf $csrf;

    public function __construct(protected readonly Request $request, protected readonly Container $container)
    {
        // Pulled from the container (rather than `new`'d directly) so every
        // controller shares the exact same Renderer/FlashBag/Csrf instances
        // as the rest of the request - important for FlashBag/Csrf since
        // they wrap the single shared Session.
        $this->view = $container->make(Renderer::class);
        $this->flash = $container->make(FlashBag::class);
        $this->csrf = $container->make(Csrf::class);
    }

    /** Renders a full page template (through the theme's header/footer layout) and wraps the resulting HTML in a 200 Response - the normal way a controller action produces its output. */
    protected function render(string $template, array $data = []): Response
    {
        return Response::html($this->view->render($template, $data));
    }

    /** See Renderer::renderStandalone()'s docblock. */
    protected function renderStandalone(string $template, array $data = []): Response
    {
        return Response::html($this->view->renderStandalone($template, $data));
    }

    /** Builds a redirect Response to a path within this site (or to an already-absolute http(s) URL, passed through unchanged) - the normal way a controller action ends after a POST that changed something. */
    protected function redirect(string $path): Response
    {
        // Absolute URLs (starting with http:// or https://) are used as-is
        // (e.g. redirecting off-site to a payment provider); anything else
        // is treated as a path relative to this site's own SITE_URL, with
        // slashes normalized so callers don't need to worry about leading/
        // trailing slash mismatches.
        $target = str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
            ? $path
            : rtrim(SITE_URL, '/') . '/' . ltrim($path, '/');
        return Response::redirect($target);
    }

    /** Queues a one-shot flash message (shown once on the next rendered page) - shortcut for $this->flash->add(...). */
    protected function flash(string $type, string $message): void
    {
        $this->flash->add($type, $message);
    }

    /**
     * Guard clause for storefront actions that require a logged-in
     * customer (e.g. viewing order history): returns null when the visitor
     * is already logged in (meaning "carry on"), or a ready-to-return
     * Response redirecting to /login with an explanatory flash message
     * otherwise. Callers write `if ($resp = $this->requireCustomerLogin()) return $resp;`.
     */
    protected function requireCustomerLogin(): ?Response
    {
        if (CustomerAuth::check()) {
            return null;
        }
        $this->flash('error', __('auth.please_sign_in'));
        return $this->redirect('/login');
    }

    /**
     * Guard clause for POST actions: verifies the submitted csrf_token
     * field against this session's token. Returns null (meaning "carry on")
     * if valid, or a 403 Response explaining the failure otherwise - same
     * calling convention as requireCustomerLogin() above. Every
     * state-changing storefront POST handler should call this first.
     */
    protected function requireCsrf(): ?Response
    {
        if ($this->csrf->verify($this->request->post('csrf_token'))) {
            return null;
        }
        return Response::html('Invalid or expired form submission (CSRF check failed). Please go back and try again.', 403);
    }
}
