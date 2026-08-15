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
 *
 * In plain terms: this class is the app's "phone book" for URLs. It holds a
 * list of every known URL pattern (e.g. "/product/{slug}") together with
 * which controller method should handle it, and dispatch() is what looks at
 * the incoming request and decides which controller method to call.
 */
final class Router
{
    /** Every route registered so far, in the order they were added - checked in that same order when dispatching, so more specific patterns should generally be registered before more general ones. */
    /** @var Route[] */
    private array $routes = [];

    public function __construct(private readonly Container $container)
    {
    }

    /** Registers a route that only responds to GET requests (the common case for pages that just display something). */
    public function get(string $pattern, array|\Closure $handler): Route
    {
        return $this->map(['GET'], $pattern, $handler);
    }

    /** Registers a route that only responds to POST requests (the common case for form submissions/actions that change data). */
    public function post(string $pattern, array|\Closure $handler): Route
    {
        return $this->map(['POST'], $pattern, $handler);
    }

    /** Registers a route that responds to a specific custom set of HTTP methods - use this when a single URL needs to accept more than one method (e.g. both GET and POST). */
    public function match(array $methods, string $pattern, array|\Closure $handler): Route
    {
        return $this->map($methods, $pattern, $handler);
    }

    /** Shared implementation behind get()/post()/match(): builds a Route object, stores it, and returns it so the caller can chain extra configuration like ->capability(...). */
    public function map(array $methods, string $pattern, array|\Closure $handler): Route
    {
        // Normalize method names to uppercase so registering 'get' or 'GET'
        // behaves identically, and so the comparison against $request->method()
        // (which is always uppercase) below works regardless of how the
        // route was declared.
        $route = new Route(array_map('strtoupper', $methods), $pattern, $handler);
        $this->routes[] = $route;
        return $route;
    }

    /**
     * Finds the route matching the current request and runs it, returning
     * the Response it produces. This is called exactly once per request,
     * from Core\App::run().
     */
    public function dispatch(Request $request): Response
    {
        $path = $request->path();
        // Tracks whether *any* route's URL pattern matched, even if the
        // HTTP method didn't - lets us tell "no such page" (404) apart from
        // "that page exists but not for this method" (405) below.
        $pathMatchedAnyMethod = false;

        foreach ($this->routes as $route) {
            // Route::match() checks the URL pattern only (not the method) and
            // returns the extracted {placeholder} values, or null if the path
            // doesn't match this route at all.
            $params = $route->match($path);
            if ($params === null) {
                continue;
            }
            $pathMatchedAnyMethod = true;

            // The path matched, but this route doesn't accept the request's
            // HTTP method (e.g. a GET-only route hit with POST) - keep
            // looking in case another registered route matches both.
            if (!in_array($request->method(), $route->methods, true)) {
                continue;
            }

            // Capability-gated admin routes: before even invoking the
            // controller, check the logged-in admin's role is allowed to use
            // this route (see Core\Auth\AdminAuth::CAPABILITIES). This keeps
            // permission checks centralized in the router instead of being
            // re-implemented at the top of every admin controller method.
            if ($route->capability !== null && !AdminAuth::can($route->capability)) {
                return AdminAuth::denyResponse();
            }

            return $route->invoke($this->container, $request, $params);
        }

        // If some route's path matched but none accepted this method, that's
        // a 405; if nothing matched the path at all, it's a plain 404.
        return $pathMatchedAnyMethod ? Response::methodNotAllowed() : Response::notFound();
    }
}
