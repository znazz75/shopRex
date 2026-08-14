<?php

namespace ShopRex\Core;

use ShopRex\Core\Auth\AdminAuth;

/**
 * The "customizable router" - adding a new page anywhere in the app is one
 * line here (or in src/routes/web.php / src/routes/admin.php), no core
 * file needs touching:
 *
 *   $router->get('/brand/{slug}', [BrandController::class, 'show']);
 *   $router->post('/cart/add', [CartController::class, 'add']);
 *   $router->get('/admin/withdrawals', [WithdrawalAdminController::class, 'index'])
 *          ->capability('withdrawals');
 *
 * Routes are tried in registration order; the first pattern match wins
 * regardless of method (so a path match with the wrong method reports 405
 * rather than falling through to a 404 from a route that never matched the
 * path at all).
 */
final class Router
{
    /** @var Route[] */
    private array $routes = [];

    public function __construct(private readonly Container $container)
    {
    }

    public function get(string $pattern, array|\Closure $handler): Route
    {
        return $this->map(['GET'], $pattern, $handler);
    }

    public function post(string $pattern, array|\Closure $handler): Route
    {
        return $this->map(['POST'], $pattern, $handler);
    }

    public function match(array $methods, string $pattern, array|\Closure $handler): Route
    {
        return $this->map($methods, $pattern, $handler);
    }

    public function map(array $methods, string $pattern, array|\Closure $handler): Route
    {
        $route = new Route(array_map('strtoupper', $methods), $pattern, $handler);
        $this->routes[] = $route;
        return $route;
    }

    public function dispatch(Request $request): Response
    {
        $path = $request->path();
        $pathMatchedAnyMethod = false;

        foreach ($this->routes as $route) {
            $params = $route->match($path);
            if ($params === null) {
                continue;
            }
            $pathMatchedAnyMethod = true;

            if (!in_array($request->method(), $route->methods, true)) {
                continue;
            }

            if ($route->capability !== null && !AdminAuth::can($route->capability)) {
                return AdminAuth::denyResponse();
            }

            return $route->invoke($this->container, $request, $params);
        }

        return $pathMatchedAnyMethod ? Response::methodNotAllowed() : Response::notFound();
    }
}
