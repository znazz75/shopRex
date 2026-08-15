<?php

namespace ShopRex\Core;

/**
 * Tiny composition root - index.php / admin/index.php each build a Router
 * (from src/routes/web.php or src/routes/admin.php) against the shared
 * Container from src/bootstrap.php, then hand both to App::run().
 *
 * In plain terms: this is the very last piece of glue code that turns "an
 * incoming HTTP request" into "an HTTP response sent back to the browser" -
 * everything before this (bootstrap.php, container.php, the route table)
 * is just setup; run() is where the actual request handling happens.
 */
final class App
{
    public function __construct(private readonly Container $container)
    {
    }

    /**
     * Builds the Request object for the current PHP request, asks the
     * Router to find and run the matching route, and sends the resulting
     * Response back to the browser. This is the single line index.php /
     * admin/index.php call to handle everything.
     */
    public function run(Router $router): void
    {
        // Request needs a Session instance to read/write session-backed
        // state (cart, language, csrf_token, ...), so it's pulled from the
        // container rather than constructed directly here.
        $request = new Request($this->container->make(Session::class));
        $response = $router->dispatch($request);
        $response->send();
    }
}
