<?php

namespace ShopRex\Core;

/**
 * Tiny composition root - index.php / admin/index.php each build a Router
 * (from src/routes/web.php or src/routes/admin.php) against the shared
 * Container from src/bootstrap.php, then hand both to App::run().
 */
final class App
{
    public function __construct(private readonly Container $container)
    {
    }

    public function run(Router $router): void
    {
        $request = new Request($this->container->make(Session::class));
        $response = $router->dispatch($request);
        $response->send();
    }
}
