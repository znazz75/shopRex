<?php

namespace ShopRex\Core;

/**
 * One registered route. Patterns use {name} for a required segment and
 * {name:regex} for a constrained one, e.g. "/product/{slug}" or
 * "/admin/orders/{id:\d+}". Compiled to a regex lazily, once, on first
 * match() call.
 */
final class Route
{
    private ?string $compiled = null;
    private array $paramNames = [];
    public ?string $capability = null;
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

    public function name(string $name): self
    {
        $this->name = $name;
        return $this;
    }

    /** @return array<string,string>|null null if the path doesn't match this route at all. */
    public function match(string $path): ?array
    {
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
            if (preg_match('#^\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}$#', $part, $m)) {
                $this->paramNames[] = $m[1];
                $constraint = $m[2] ?? '[^/]+';
                $regex .= '(?P<' . $m[1] . '>' . $constraint . ')';
            } else {
                $regex .= preg_quote($part, '#');
            }
        }

        $this->compiled = '#^' . $regex . '$#';
    }

    public function invoke(Container $container, Request $request, array $params): Response
    {
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
