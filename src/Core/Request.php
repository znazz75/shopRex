<?php

namespace ShopRex\Core;

/**
 * Wraps $_GET/$_POST/$_SERVER/$_COOKIE/$_FILES for one request, plus the
 * route parameters the Router extracted from the URL path. Replaces the
 * ad hoc `$_POST['field'] ?? default` + manual trim()/(int) casts that
 * used to be repeated inline on every page.
 */
final class Request
{
    /** @var array<string, string> */
    private array $routeParams = [];

    public function __construct(private readonly Session $session)
    {
    }

    public function session(): Session
    {
        return $this->session;
    }

    public function method(): string
    {
        return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
    }

    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    /**
     * Path only, query string stripped, trailing slash normalized (except
     * for the root "/" itself), leading "/shopRex" sub-directory prefix
     * (or whatever base path the install lives under) stripped so route
     * patterns never need to know about it.
     *
     * The prefix to strip is derived from SITE_URL's own path component,
     * NOT dirname($_SERVER['SCRIPT_NAME']) - the latter looks right when
     * every request is handled by one script at the install root, but
     * admin/index.php lives one directory deeper than index.php while both
     * front controllers dispatch against route patterns that already
     * include "/admin" (e.g. "/admin/orders/{id}"). Under the .htaccess
     * rewrite, SCRIPT_NAME for an admin request is ".../admin/index.php",
     * so dirname() would strip "/shopRex/admin" instead of just
     * "/shopRex" and every admin route pattern would stop matching.
     * SITE_URL is the one thing both front controllers agree on.
     */
    public function path(): string
    {
        $uri = $_SERVER['REQUEST_URI'] ?? '/';
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';

        $basePath = defined('SITE_URL') ? (string)parse_url(SITE_URL, PHP_URL_PATH) : '';
        $basePath = rtrim($basePath, '/');
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }
        if ($path === '' ) {
            $path = '/';
        }
        if (strlen($path) > 1) {
            $path = rtrim($path, '/');
            if ($path === '') {
                $path = '/';
            }
        }
        return $path;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_GET[$key] ?? $default;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $default;
    }

    /** POST value if present, else GET - convenient for handlers reachable via either verb. */
    public function input(string $key, mixed $default = null): mixed
    {
        return $_POST[$key] ?? $_GET[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->method() === 'POST' ? $_POST : $_GET;
    }

    public function file(string $key): ?array
    {
        return $_FILES[$key] ?? null;
    }

    public function files(string $key): array
    {
        return $_FILES[$key] ?? [];
    }

    public function ip(): string
    {
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }

    public function referer(): ?string
    {
        return $_SERVER['HTTP_REFERER'] ?? null;
    }

    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        return $_SERVER[$key] ?? null;
    }

    public function withRouteParams(array $params): static
    {
        $this->routeParams = $params;
        return $this;
    }

    public function routeParam(string $key, mixed $default = null): mixed
    {
        return $this->routeParams[$key] ?? $default;
    }
}
