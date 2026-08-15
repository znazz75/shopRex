<?php

namespace ShopRex\Core;

/**
 * One registered route. Patterns use {name} for a required segment and
 * {name:regex} for a constrained one, e.g. "/product/{slug}" or
 * "/admin/orders/{id:\d+}". Compiled to a regex lazily, once, on first
 * match() call.
 *
 * In plain terms: this object represents a single "when the URL looks like
 * X, and the request uses method Y, call this controller method" rule.
 * Router just holds a list of these and asks each one in turn "does this
 * incoming path match you?" via match().
 */
final class Route
{
    /** The regex built from $pattern by compile() - null until the first match() call, so patterns that are registered but never actually hit during a request are never needlessly compiled. */
    private ?string $compiled = null;

    /** The placeholder names extracted from $pattern (e.g. ['slug'] for "/product/{slug}"), in the order they appear - used to build the named $params array returned by match(). */
    private array $paramNames = [];

    /** Admin capability required to access this route (see Core\Auth\AdminAuth::CAPABILITIES), or null if this route has no capability gate (e.g. any public storefront route). */
    public ?string $capability = null;

    /** Optional human-readable name for this route, set via name() - not currently used for URL generation, just a label. */
    public ?string $name = null;

    /**
     * @param string[] $methods
     * @param array|\Closure $handler [ControllerClass::class, 'method'] or a Closure
     */
    public function __construct(
        public readonly array $methods,
        public readonly string $pattern,
        public readonly array|\Closure $handler,
    ) {
    }

    /** Gate this route behind an admin capability - checked by the Router before invoke(). */
    public function capability(string $capability): self
    {
        $this->capability = $capability;
        return $this;
    }

    /** Attaches an optional label to this route; purely descriptive bookkeeping, doesn't affect matching or dispatch. */
    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /**
     * Checks whether $path matches this route's pattern, and if so, returns
     * the extracted {placeholder} values keyed by name (e.g.
     * ['slug' => 'classic-t-shirt']). Returns null on no match - callers
     * (Router::dispatch()) use that to keep trying the next registered route.
     * @return array<string,string>|null null if the path doesn't match this route at all.
     */
    public function match(string $path): ?array
    {
        // Build $this->compiled the first time it's needed, then reuse it -
        // see the $compiled property comment above.
        $this->compile();

        if (!preg_match($this->compiled, $path, $matches)) {
            return null;
        }

        $params = [];
        foreach ($this->paramNames as $paramName) {
            $params[$paramName] = $matches[$paramName] ?? '';
        }
        return $params;
    }

    /**
     * Turns this route's human-friendly pattern (e.g. "/product/{slug}" or
     * "/admin/orders/{id:\d+}") into an actual PCRE regex with named capture
     * groups, storing the result in $compiled. Only does real work once per
     * route (guarded by the null check), since compiling a regex on every
     * single request would be wasteful.
     */
    private function compile(): void
    {
        if ($this->compiled !== null) {
            return;
        }

        // Split into alternating literal / {placeholder} chunks so literal
        // chunks can be preg_quote()'d without also escaping the { } : of
        // a placeholder that's meant to become a named capture group.
        $this->paramNames = [];
        $parts = preg_split(
            '#(\{[a-zA-Z_][a-zA-Z0-9_]*(?::[^}]+)?\})#',
            $this->pattern,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY
        );

        $regex = '';
        foreach ($parts as $part) {
            // A chunk that looks like {name} or {name:regex} becomes a named
            // capture group; anything else is literal text from the pattern
            // and gets preg_quote()'d so characters like "." or "-" in a URL
            // aren't accidentally treated as regex syntax.
            if (preg_match('#^\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}$#', $part, $m)) {
                $this->paramNames[] = $m[1];
                // No :regex suffix means "match anything except a slash" -
                // i.e. exactly one URL path segment.
                $constraint = $m[2] ?? '[^/]+';
                $regex .= '(?P<' . $m[1] . '>' . $constraint . ')';
            } else {
                $regex .= preg_quote($part, '#');
            }
        }

        // Anchored with ^...$ so the whole path must match, not just a
        // substring of it.
        $this->compiled = '#^' . $regex . '$#';
    }

    /**
     * Actually calls this route's handler (a controller method, or a raw
     * Closure for tiny inline routes) and normalizes whatever it returns
     * into a Response object - so controller methods are free to just
     * `return 'some html';` or `return $someArray;` for a redirect helper
     * without every single one having to remember to wrap it.
     */
    public function invoke(Container $container, Request $request, array $params): Response
    {
        // Make the {placeholder} values extracted from the URL available on
        // the Request object too, so controllers can read them via
        // $request->param(...) instead of only through the $params argument.
        $request->withRouteParams($params);

        if ($this->handler instanceof \Closure) {
            $result = ($this->handler)($request, $params);
            return $result instanceof Response ? $result : Response::html((string)$result);
        }

        [$controllerClass, $method] = $this->handler;
        // Controllers are instantiated fresh per request (there's exactly
        // one Request per request anyway) - no reflection-based
        // autowiring, matching Container's "deliberately small" design;
        // a controller pulls whatever services it needs out of $container
        // itself, see Core\Controller.
        $controller = new $controllerClass($request, $container);
        $result = $controller->$method($request);
        return $result instanceof Response ? $result : Response::html((string)$result);
    }
}
