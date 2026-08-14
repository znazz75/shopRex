<?php

namespace ShopRex\Core;

use ShopRex\Core\Auth\CustomerAuth;

/**
 * Base for every storefront controller. Holds the Request and Container
 * (services are pulled from the container lazily/on demand by whichever
 * concrete controller needs them - Cart, Mailer, SettingsRepository, ...
 * rather than this base class guessing what every subclass needs).
 */
abstract class Controller
{
    protected readonly Renderer $view;
    protected readonly FlashBag $flash;
    protected readonly Csrf $csrf;

    public function __construct(protected readonly Request $request, protected readonly Container $container)
    {
        $this->view = $container->make(Renderer::class);
        $this->flash = $container->make(FlashBag::class);
        $this->csrf = $container->make(Csrf::class);
    }

    protected function render(string $template, array $data = []): Response
    {
        return Response::html($this->view->render($template, $data));
    }

    /** See Renderer::renderStandalone()'s docblock. */
    protected function renderStandalone(string $template, array $data = []): Response
    {
        return Response::html($this->view->renderStandalone($template, $data));
    }

    protected function redirect(string $path): Response
    {
        $target = str_starts_with($path, 'http://') || str_starts_with($path, 'https://')
            ? $path
            : rtrim(SITE_URL, '/') . '/' . ltrim($path, '/');
        return Response::redirect($target);
    }

    protected function flash(string $type, string $message): void
    {
        $this->flash->add($type, $message);
    }

    protected function requireCustomerLogin(): ?Response
    {
        if (CustomerAuth::check()) {
            return null;
        }
        $this->flash('error', __('auth.please_sign_in'));
        return $this->redirect('/login');
    }

    protected function requireCsrf(): ?Response
    {
        if ($this->csrf->verify($this->request->post('csrf_token'))) {
            return null;
        }
        return Response::html('Invalid or expired form submission (CSRF check failed). Please go back and try again.', 403);
    }
}
